<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/Debug.php";


/**
 * 参照するグローバル変数:
 *  ROOT_URI
 */
class Authenticator
{
    const REALM = "Sacred area";
    const DUMMY_HASHED_PASSWORD = '$2y$10$abcdefghijklmnopqrstuv';

    public static function UserExists($username)
    {
        return array_key_exists($username, USER_TABLE);
    }

    /**
     * ファイルパスからファイルを所有するユーザ名を返す．
     * 存在しない場合は，falseを返す．
     * 
     * @param string $filePath Homeからのパス. ex)./Master/Contents
     * @return string|false
     */
    public static function GetFileOwnerName($filePath)
    {
        foreach (USER_TABLE as $username => $info) {
            if (strpos($filePath, $info['contentsFolder']) === 0) {
                return $username;
            }
        }

        return false;
    }

    /**
     * ログインしているユーザ名を返す.
     * 存在しないときは，falseを返す．
     * 
     * @return string|false
     */
    public static function GetLoginedUsername()
    {
        if (!isset($_SESSION['username'])) {
            return false;
        }

        return $_SESSION['username'];
    }

    public static function IsValidUserTableAccess($username, $key){
        return static::UserExists($username) &&
                array_key_exists($key, USER_TABLE[$username]);
    }

    public static function GetUserInfo($username, $key, &$out){
        if(static::IsValidUserTableAccess($username, $key)){
            $out = USER_TABLE[$username][$key];
            return true;
        }

        return false;
    }

    public static function IsFileOwner($filePath, $username)
    {
        if(!static::GetUserInfo($username, 'contentsFolder', $contentsFolder)){
            return false;
        }

        $filePath = static::NormalizePath($filePath);

        if (static::StartsWith($filePath, $contentsFolder)) {
            return true;
        }

        return false;
    }

    public static function NormalizePath(string $str)
    {
        $fn = explode("/", $str);
        $stack = [];

        $index = 0;
        foreach ($fn as $path) {
            if ($path === "..") {
                if (count($stack)) {
                    array_pop($stack);
                }

            }
            // 最初の'.'は無視しない.
            else if ($index != 0 && $path === ".") {
                // 無視
            } else if ($path === "") {
                // 無視
            } else {
                array_push($stack, $path);
            }

            $index++;
        }
        return implode("/", $stack);
    }

    public static function StartsWith($str, $search)
    {
        if (substr($str, 0, strlen($search)) === $search) {
            // Match
            return true;
        }

        return false;
    }

    /**
     * 
     * ユーザ名を含めないURLを返します.
     * http://username@domain-name/LOGINED_PAGE
     */
    public static function GetLoginedURL($returnTo=''){
        if(is_string($returnTo) && $returnTo !== ''){
            // returnToが設定されているとき
            return (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . $returnTo;
        }
        return (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . ROOT_URI . '/FileManager';
    }

    /**
     * @param str $returnTo ログイン完了後に遷移するページ先(URLエンコード不要)
     */
    public static function GetLoginURL($returnTo=''){
        $url = ROOT_URI . '/Login';
        if(is_string($returnTo) && $returnTo !== ''){
            $url .= '?returnTo=' . urlencode($returnTo);
        }
        return $url;
    }

    /**
     * 
     * この関数が実行された後は, ログアウト状態であることが保証される.
     * ログイン状態であるときは, デフォルトウェルカムページへ移動
     * 
     */ 
    public static function RequireUnloginedSession($returnTo='')
    {
        // セッション開始
        @session_start();

        // ログインしているとき
        if (isset($_SESSION['username'])) {
            header('Location: ' . self::GetLoginedURL($returnTo));
            exit;
        }

    }

    /**
     * 
     * この関数が実行された後は, ログイン状態であることが保証される.
     * ログイン状態でないとき, loginページに移動
     * 
     */
    public static function RequireLoginedSession($returnTo='')
    {
        // セッション開始
        @session_start();

        // ログイン状態ではないときloginページに遷移
        if (!isset($_SESSION['username'])) {
            header('Location: ' . self::GetLoginURL($returnTo));
            exit;
        }
    }

    /**
     * ログイン状態を開始する.
     * 認証に成功した時, これを呼ぶ.
     */
    public static function StartLoginedSession($username, $returnTo='')
    {

        // セッションのIDの追跡を防ぐため, セッションIDの再割り当て
        session_regenerate_id(true);

        // ユーザ名を設定
        $_SESSION['username'] = $username;

        // ログイン後のページへ遷移
        header('Location: ' . self::GetLoginedURL($returnTo));

        exit;
    }


    /**
     * CSRFトークンの生成
     * session_id()をもとに生成
     * sessionを始めていなくてもsession_id()は空文字を返す
     */
    public static function GenerateCsrfToken()
    {
        // セッションIDからハッシュを生成
        return hash('sha256', session_id());
    }

    /**
     * CSRFトークンの検証
     */
    public static function ValidateCsrfToken($token)
    {
        return $token === static::GenerateCsrfToken();
    }


    /**
     * http auth ヘッダをパースする関数
     */
    public static function HttpDigestParse($txt)
    {
        // データが失われている場合への対応
        $neededParts = array('nonce' => 1, 'nc' => 1, 'cnonce' => 1, 'qop' => 1, 'username' => 1, 'uri' => 1, 'response' => 1);

        $data = array();
        $keys = implode('|', array_keys($neededParts));

        preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', $txt, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $data[$m[1]] = $m[3] ? $m[3] : $m[4];
            unset($neededParts[$m[1]]);
        }

        return $neededParts ? false : $data;

        // // 利用するパラメータ
        // $keys = ['response', 'nonce', 'nc', 'cnonce', 'qop', 'uri', 'username'];

        // // あらかじめ空欄で埋めておく
        // $data = array_fill_keys($keys, '');

        // // 正規表現を生成してパラメータをパース
        // $regex = '/(' . implode('|', $keys) . ')=(?:\'([^\']++)\'|"([^"]++)"|([^\s,]++))/';

        // preg_match_all($regex, $txt, $matches, PREG_SET_ORDER);
        // foreach ($matches as $m) {
        //     // 見つかったところは空欄を上書き
        //     $data[$m[1]] = $m[3] ?: $m[4];
        // }
        // //Debug::Log($data['username']);
        // //var_dump($data);

        // return $data;
    }

    public static function ValidDigestResponse($data)
    {
        // 有効なレスポンスを生成する
        static::GetUserInfo($data['username'], 'digest', $a1);
        $a2 = md5($_SERVER['REQUEST_METHOD'] . ':' . $data['uri']);
        
        return md5($a1 . ':' . $data['nonce'] . ':' . $data['nc'] . ':' . $data['cnonce'] . ':' . $data['qop'] . ':' . $a2);
    }
        
    // APR1-MD5 encryption method (windows compatible)
    public static function CryptApr1Md5($plainpasswd)
    {
        $salt = substr(str_shuffle("abcdefghijklmnopqrstuvwxyz0123456789"), 0, 8);
        $len = strlen($plainpasswd);
        $text = $plainpasswd . '$apr1$' . $salt;
        $bin = pack("H32", md5($plainpasswd . $salt . $plainpasswd));
        for ($i = $len; $i > 0; $i -= 16) {$text .= substr($bin, 0, min(16, $i));}
        for ($i = $len; $i > 0; $i >>= 1) {$text .= ($i & 1) ? chr(0) : $plainpasswd{0};}
        $bin = pack("H32", md5($text));
        $tmp = '';
        for ($i = 0; $i < 1000; $i++) {
            $new = ($i & 1) ? $plainpasswd : $bin;
            if ($i % 3) {
                $new .= $salt;
            }

            if ($i % 7) {
                $new .= $plainpasswd;
            }

            $new .= ($i & 1) ? $bin : $plainpasswd;
            $bin = pack("H32", md5($new));
        }
        for ($i = 0; $i < 5; $i++) {
            $k = $i + 6;
            $j = $i + 12;
            if ($j == 16) {
                $j = 5;
            }

            $tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
        }
        $tmp = chr(0) . chr(0) . $bin[11] . $tmp;
        $tmp = strtr(strrev(substr(base64_encode($tmp), 2)),
            "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",
            "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz");

        return "$" . "apr1" . "$" . $salt . "$" . $tmp;
    }
}
