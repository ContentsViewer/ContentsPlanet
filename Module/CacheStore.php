<?php

require_once dirname(__FILE__) . '/Logger.php';

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', getcwd() . '/Cache');
}

/** File-based cache handle for a single cache entry. */
class Cache
{
    public $data;

    private $fp;

    /**
     * Usage flow:
     * connect -> lock(*1) -> fetch -> {read/write data} -> apply -> unlock(*1) -> disconnect
     *
     * *1: Lock/unlock can be omitted when not needed.
     *
     * @return Cache
     */
    public function connect($name)
    {
        $this->disconnect();

        $filename = CacheStore::filePath($name);

        // Update file modification time on connect.
        // NOTE: Should we check the Error? Maybe No.
        //  If touch fails when the file exists, following fopen will fail.
        //  If the file exists, this error affects cache clearing but is not destructive.
        touch($filename);

        //  'w' would truncate the file before the lock is obtained
        if (!$this->fp = @fopen($filename, 'c+b')) {
            $this->fp = null;
            throw new RuntimeException();
        }

        return $this;
    }

    public function disconnect()
    {
        if (is_null($this->fp)) return $this;

        flock($this->fp, LOCK_UN);
        fclose($this->fp);
        $this->fp = null;

        CacheStore::gc();

        return $this;
    }

    /**
     * @param int $operation LOCK_SH|LOCK_EX
     */
    public function lock(int $operation)
    {
        if (is_null($this->fp)) return $this;

        if (!flock($this->fp, $operation)) {
            throw new RuntimeException();
        }
        return $this;
    }

    public function unlock()
    {
        if (is_null($this->fp)) return $this;

        flock($this->fp, LOCK_UN);
        return $this;
    }

    public function apply()
    {
        if (is_null($this->fp)) return $this;

        $serialized = serialize($this->data);

        rewind($this->fp);
        ftruncate($this->fp, 0);
        fwrite($this->fp, $serialized);
        fflush($this->fp);

        return $this;
    }

    public function fetch()
    {
        if (is_null($this->fp)) return $this;

        rewind($this->fp);
        $raw = stream_get_contents($this->fp);
        if ($raw === false) {
            throw new RuntimeException();
        }

        $data = unserialize($raw, ['allowed_classes' => false]);
        $this->data = $data !== false ? $data : [];
        return $this;
    }

    function __destruct()
    {
        $this->disconnect();
    }
}

/** Static utilities for managing the cache directory (GC, path resolution, existence checks). */
class CacheStore
{
    const EXTENSION = '.cache';
    const DEFAULT_LIFE_TIME = 604800; // 1 week
    const GC_PROBABILITY = 5;
    const GC_MAX_FILE_CRAWL = 10;
    const GC_MAX_COUNT_PER_REQUEST = 1;

    private static int $gcCount = 0;

    public static function exists(string $name): bool
    {
        return file_exists(self::filePath($name));
    }

    /** Returns the file modification timestamp, or false if the cache does not exist. */
    public static function getDate(string $name): int|false
    {
        if (!self::exists($name)) {
            return false;
        }

        return @filemtime(self::filePath($name));
    }

    public static function filePath(string $name): string
    {
        $name = urlencode($name);
        return CACHE_DIR . DIRECTORY_SEPARATOR . $name . self::EXTENSION;
    }

    public static function gc(bool $force = false): void
    {
        ++self::$gcCount;

        if (!$force) {
            // Assure gc probability is constant per one request.
            if (self::$gcCount > self::GC_MAX_COUNT_PER_REQUEST) {
                return;
            }
            if (rand(1, 100) > self::GC_PROBABILITY) {
                return;
            }
        }

        $files = scandir(CACHE_DIR . DIRECTORY_SEPARATOR);
        if (!shuffle($files)) return;
        $counter = 0;
        foreach ($files as $file) {
            if ($counter >= self::GC_MAX_FILE_CRAWL) break;

            $file = CACHE_DIR . DIRECTORY_SEPARATOR . $file;
            if (
                !is_file($file)                               ||
                !self::isCacheFile($file)                     ||
                ($modifiedTime = @filemtime($file)) === false ||
                ($fp = @fopen($file, 'r')) === false
            ) {
                continue;
            }
            if (
                !flock($fp, LOCK_SH)                         ||
                ($raw = stream_get_contents($fp)) === false
            ) {
                fclose($fp);
                continue;
            }
            fclose($fp);
            $data = unserialize($raw, ['allowed_classes' => false]);
            $expires = $data['expires'] ?? self::DEFAULT_LIFE_TIME;
            if ($expires !== false && ($modifiedTime + $expires < time())) {
                @unlink($file);
            }
            ++$counter;
        }
    }

    private static function isCacheFile(string $filename): bool
    {
        return substr($filename, strrpos($filename, '.')) === self::EXTENSION;
    }
}
