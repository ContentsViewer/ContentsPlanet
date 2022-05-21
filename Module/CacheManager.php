<?php

require_once dirname(__FILE__) . '/Debug.php';

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', getcwd() . '/Cache');
}

/**
 * キャッシュファイルとユーザ間を取り持つ
 */
class Cache
{
    public $data;

    private $fp;

    /**
     * ここからの主な処理の流れ
     * Connect -> Lock(\*1) -> Fetch -> {dataへの読み書き} -> Apply -> Unlock(\*1) -> Disconnect
     * 
     * *1: 
     *  Lockを気にしないときは, 省略可
     * 
     * @return Cache
     */
    public function connect($name)
    {
        $this->disconnect();

        $filename = CacheManager::getCacheFilePath($name);

        // connect 時, ファイルの更新時間を更新
        // NOTE: Shoud we check the Error? Maybe No.
        //  If touch fails when the file exists, following fopen will fail.
        //  If the file exists, this error affects cache clearing but is not destructive.
        touch($filename);

        //  'w' を使うと, ロックを取得する前にファイルを切り詰めてしまいます
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

        // 最後に, キャッシュのガベージコレクションを行う
        if (rand(1, 100) < CacheManager::GC_PROBABILITY) {
            CacheManager::gc();
        }
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

        $json = json_encode($this->data);
        if ($json === false) {
            throw new RuntimeException();
        }

        rewind($this->fp);
        ftruncate($this->fp, 0);
        fwrite($this->fp, $json);
        fflush($this->fp);

        return $this;
    }

    public function fetch()
    {
        if (is_null($this->fp)) return $this;

        rewind($this->fp);
        $json = stream_get_contents($this->fp);
        if ($json === false) {
            throw new RuntimeException();
        }

        $this->data = json_decode($json, true);
        return $this;
    }

    function __destruct()
    {
        $this->disconnect();
    }
}

/**
 * キャッシュファイル全体の情報を担当
 */
class CacheManager
{
    const EXTENSION = '.cache';
    const DEFAULT_LIFE_TIME = 604800; // 1 week: 604800
    const GC_PROBABILITY = 5;
    const GC_MAX_FILE_CRAWL = 10;

    public static function cacheExists($name)
    {
        return file_exists(static::getCacheFilePath($name));
    }

    /**
     * タイムスタンプを返す.
     */
    public static function getCacheDate($name)
    {
        if (!static::cacheExists($name)) {
            return false;
        }

        return @filemtime(static::getCacheFilePath($name));
    }

    public static function getCacheFilePath($name)
    {
        $name = urlencode($name);
        return CACHE_DIR . DIRECTORY_SEPARATOR . $name . self::EXTENSION;
    }

    public static function gc()
    {
        $files = scandir(CACHE_DIR . DIRECTORY_SEPARATOR);
        if (!shuffle($files)) return;
        $counter = 0;
        foreach ($files as $file) {
            if ($counter >= self::GC_MAX_FILE_CRAWL) continue;

            $file = CACHE_DIR . DIRECTORY_SEPARATOR . $file;
            if (
                !is_file($file)                               ||
                !static::isCacheFile($file)                   ||
                ($modifiedTime = @filemtime($file)) === false ||
                ($fp = @fopen($file, 'r')) === false
            ) {
                continue;
            }
            if (
                !flock($fp, LOCK_SH)                         ||
                ($json = stream_get_contents($fp)) === false
            ) {
                fclose($fp);
                continue;
            }
            fclose($fp);
            $data = json_decode($json, true);
            $expires = $data['expires'] ?? self::DEFAULT_LIFE_TIME;
            if ($expires !== false && ($modifiedTime + $expires < time())) {
                @unlink($file);
            }
            $counter++;
        }
    }

    public static function isCacheFile($filename)
    {
        return substr($filename, strrpos($filename, '.')) === self::EXTENSION;
    }
}
