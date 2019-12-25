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

        if(rand(1, 100) < CacheManager::GC_PROBABILITY){
            CacheManager::GC();
        }

        //  'w' を使うと, ロックを取得する前にファイルを切り詰めてしまいます
        if (!$this->fp = fopen(CacheManager::GetCacheFilePath($name), 'c+b')){
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
    const GC_PROBABILITY = 5;
    const LIFE_TIME = 604800; // 1 week: 604800


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

        return filemtime(static::GetCacheFilePath($name));
    }

    public static function GetCacheFilePath($name){
        $name = urlencode($name);
        return CACHE_DIR . DIRECTORY_SEPARATOR . $name . self::EXTENTION;
    }

    public static function GC(){
        $expire = time()-self::LIFE_TIME;

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

    public static function IsCacheFile($filename){
        return substr($filename, strrpos($filename, '.')) === self::EXTENTION;
    }

}

// CacheManager::GC();
// echo CacheManager::IsCacheFile('test.cache');
// CacheManager::WriteCache('./my/teset.aa', ['content' => 'a/sasdasf']);
// var_dump(CacheManager::ReadCache('./my/teset.aa'));
