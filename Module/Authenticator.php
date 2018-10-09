<?php

class Authenticator {

    // contentsFolderの名前は必ずContents
    private static $userTable = [
        'master' => [
            'hashedPassword' => '',
            'contentsFolder' => './Master/Contents',
            'fileFolder' => './Master/Files']
    ];

    private static $loginPage = "login.php";
    private static $loginedPage = "file-manager.php";

    public static function LoginPage(){return static::$loginPage;}
    public static function LoginedPage(){return static::$loginedPage;}

    public static function UserExists($username){
        return array_key_exists($username, static::$userTable);
    }

    public static function GetHashedPassword($username = null){
        if($username == null){
            $username = $_SESSION['username'];
        }
        
        if(!static::UserExists($username)){
            return '$2y$10$abcdefghijklmnopqrstuv';
        }

        if(!array_key_exists('hashedPassword', static::$userTable[$username])){
            return '$2y$10$abcdefghijklmnopqrstuv';
        }

        return static::$userTable[$username]['hashedPassword'];
    }

    public static function GetContentsFolder($username = null){
        if($username == null){
            $username = $_SESSION['username'];
        }
        return static::$userTable[$username]['contentsFolder'];
    }

    public static function GetFileFolder($username = null){
        if($username == null){
            $username = $_SESSION['username'];
        }
        return static::$userTable[$username]['fileFolder'];
    }

    public static function IsFileOwner($fileName, $username = null){
        if($username == null){
            $username = $_SESSION['username'];
        }

        $fileFolder = static::$userTable[$username]['fileFolder'];
        $contentsFolder = static::$userTable[$username]['contentsFolder'];

        $fileName = static::NormalizePath($fileName);

        if(static::StartsWith($fileName, $fileFolder)){
            return true;
        }

        if(static::StartsWith($fileName, $contentsFolder)){
            return true;
        }

        return false;

    }

    public static function NormalizePath (string $str) {
        $fn = explode("/", $str);
        $stack = [];

        $index = 0;
        foreach ($fn as $path) {
            if ($path === "..") {
                if (count($stack))
                    array_pop($stack);
            }
            // 最初の'.'は無視しない.
            else if ($index != 0 && $path === ".") {
                // 無視
            }
            else if ($path === "") {
                // 無視
            }
            else {
                array_push($stack, $path);
            }

            $index++;
        }
        return implode("/", $stack);
    }

    public static function StartsWith($str, $search){
        if (substr($str,  0, strlen($search)) === $search) {
            // Match
            return true;
        }

        return false;
    }
    
    // この関数が実行された後は, ログアウト状態であることが保証される.
    // ログイン状態であるときは, 
    public static function RequireUnloginedSession(){
        // セッション開始
        @session_start();

        // ログインしているとき
        if(isset($_SESSION['username'])){

            header('Location: ./' . static::$loginedPage);
            exit;
        }

    }


    // この関数が実行された後は, ログイン状態であることが保証される.
    // ログイン状態でないとき, login.phpに移動
    public static function RequireLoginedSession(){
        // セッション開始
        @session_start();

        // ログイン状態ではないときloginページに遷移
        if(!isset($_SESSION['username'])){

            header('Location: ./' . static::$loginPage);

            exit;
        }
        
    }


    // CSRFトークンの生成
    public static function GenerateCsrfToken(){
        // セッションIDからハッシュを生成
        return hash('sha256', session_id());
    }

    // CSRFトークンの検証
    public static function ValidateCsrfToken($token){
        return $token === static::GenerateCsrfToken();
    }


    // 
    // ", ' もエスケープする.
    //
    public static function H($var){
        if(is_array($var)){
            return array_map(static::H, $var);
        }
        else{
            return htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
        }
    }


}

?>