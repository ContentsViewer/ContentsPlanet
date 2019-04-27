<?php

require_once dirname(__FILE__) . '/Debug.php';

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', getcwd() . '/Cache');
}

class CacheManager
{
    const EXTENTION = '.cache';
    const GC_PROBABILITY = 5;
    const LIFE_TIME = 604800; // 1 week: 604800

    public static function ReadCache($name)
    {
        if (rand(1, 100) < static::GC_PROBABILITY) {
            static::GC();
        }

        if (!static::CacheExists($name)) {
            return null;
        }

        $json = file_get_contents(static::GetCacheFilePath($name));
        if ($json === false) {
            return null;
        }

        return json_decode($json, true);
    }

    public static function WriteCache($name, $data)
    {
        $json = json_encode($data);
        if ($json === false) {
            return false;
        }

        if (file_put_contents(static::GetCacheFilePath($name), $json) === false) {
            return false;
        }

        return true;
    }

    public static function CacheExists($name)
    {
        return file_exists(static::GetCacheFilePath($name));
    }

    // タイムスタンプを返す.
    public static function GetCacheDate($name)
    {
        if (!static::CacheExists($name)) {
            return false;
        }

        return filemtime(static::GetCacheFilePath($name));
    }

    private static function GetCacheFilePath($name)
    {
        $name = urlencode($name);
        return CACHE_DIR . DIRECTORY_SEPARATOR . $name . static::EXTENTION;
    }

    public static function GC()
    {
        $expire = time()-static::LIFE_TIME;

        $list = scandir(CACHE_DIR . DIRECTORY_SEPARATOR);
        foreach ($list as $value) {
            $file = CACHE_DIR . DIRECTORY_SEPARATOR . $value;

            if (!is_file($file) || !static::IsCacheFile($file)) {
                continue;
            }

            $mod = filemtime($file);
            if ($mod < $expire) {
                //chmod($file, 0666);
                unlink($file);
            }
        }
    }

    public static function IsCacheFile($filename)
    {
        return substr($filename, strrpos($filename, '.')) === static::EXTENTION;
    }

}

// CacheManager::GC();
// echo CacheManager::IsCacheFile('test.cache');
// CacheManager::WriteCache('./my/teset.aa', ['content' => 'a/sasdasf']);
// var_dump(CacheManager::ReadCache('./my/teset.aa'));
