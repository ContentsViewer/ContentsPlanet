<?php

require_once dirname(__FILE__) . '/Debug.php';

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', getcwd() . '/Cache');
}

/**
 * キャッシュファイルとユーザ間を取り持つ
 */
class Cache {
    public $data;

    private $fp;

    /**
     * ここからの主な処理の流れ
     * Connect -> Lock(\*1) -> Fetch -> {dataへの読み書き} -> Apply -> Unlock(\*1) -> Disconnect
     * 
     * *1: 
     *  Lockを気にしないときは, 省略可
     * 
     * @return bool 
     */
    public function Connect($name){
        $this->Disconnect();

        $filename = CacheManager::GetCacheFilePath($name);

        // connect 時, ファイルの更新時間を更新
        touch($filename);

        //  'w' を使うと, ロックを取得する前にファイルを切り詰めてしまいます
        if (!$this->fp = @fopen($filename, 'c+b')){
            $this->fp = null;
            return false;
        }

        return true;
    }

    public function Disconnect(){
        if(is_null($this->fp)) return;

        flock($this->fp, LOCK_UN);
        fclose($this->fp);
        $this->fp = null;
        
        // 最後に, キャッシュのガベージコレクションを行う
        if(rand(1, 100) < CacheManager::GC_PROBABILITY){
            CacheManager::GC();
        }
    }

    /**
     * @param LOCK_SH|LOCK_EX $operation
     */
    public function Lock($operation){
        if(is_null($this->fp)) return;

        flock($this->fp, $operation);
    }

    public function Unlock(){
        if(is_null($this->fp)) return;

        flock($this->fp, LOCK_UN);
    }

    public function Apply(){
        if(is_null($this->fp)) return false;
        
        $json = json_encode($this->data);
        if($json === false) return false;

        rewind($this->fp);
        ftruncate($this->fp, 0);
        fwrite($this->fp, $json);
        fflush($this->fp);

        return true;
    }

    public function Fetch(){
        if(is_null($this->fp)) return false;
        
        rewind($this->fp);
        $json = stream_get_contents($this->fp);
        if($json === false) return false;

        $this->data = json_decode($json, true);
        return true;
    }

    function __destruct(){
        $this->Disconnect();
    }
}

/**
 * キャッシュファイル全体の情報を担当
 */
class CacheManager {
    const EXTENTION = '.cache';
    const DEFAULT_LIFE_TIME = 604800; // 1 week: 604800
    const GC_PROBABILITY = 5;
    const GC_MAX_FILE_CRAWL = 10;

    public static function CacheExists($name){
        return file_exists(static::GetCacheFilePath($name));
    }

    /**
     * タイムスタンプを返す.
     */
    public static function GetCacheDate($name){
        if (!static::CacheExists($name)) {
            return false;
        }

        return @filemtime(static::GetCacheFilePath($name));
    }

    public static function GetCacheFilePath($name){
        $name = urlencode($name);
        return CACHE_DIR . DIRECTORY_SEPARATOR . $name . self::EXTENTION;
    }

    public static function GC(){
        $files = scandir(CACHE_DIR . DIRECTORY_SEPARATOR);
        if (!shuffle($files)) return;
        $counter = 0;
        foreach ($files as $file) {
            if ($counter >= self::GC_MAX_FILE_CRAWL) continue;

            $file = CACHE_DIR . DIRECTORY_SEPARATOR . $file;
            if(
                !is_file($file)                               ||
                !static::IsCacheFile($file)                   ||
                ($modifiedTime = @filemtime($file)) === false ||
                ($fp = @fopen($file, 'r')) === false
            ) {
                continue;
            }
            if(
                !flock($fp, LOCK_SH)                         ||
                ($json = stream_get_contents($fp)) === false
            ) {
                fclose($fp); continue;
            }
            fclose($fp);
            $data = json_decode($json, true);
            $expires = $data['expires'] ?? self::DEFAULT_LIFE_TIME;
            if($expires !== false && ($modifiedTime + $expires < time())) {
                @unlink($file);
            }
            $counter++;
        }
    }

    public static function IsCacheFile($filename){
        return substr($filename, strrpos($filename, '.')) === self::EXTENTION;
    }
}
