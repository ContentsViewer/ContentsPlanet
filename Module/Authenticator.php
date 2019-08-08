<?php

require_once dirname(__FILE__) . "/Debug.php";

class Authenticator
{
    // ===============================================================
    //  CONFIG
    //   contentsFolderの名前は必ずContents
    // ===============================================================
    const USER_TABLE = [
        'master' => [
            'hashedPassword' => '$2y$10$F4p8eQuuhvB5WMFZsJM4ouQLWXsnCesb3HUiPpGKPrUWNk2mbyNiq',
            'digest' => 'acdb000a8fe73ee48aaf4f80442d6182',
            'contentsFolder' => './Master/Contents',
            'isPublic' => true,
            'enableGitEdit' => false,
            'gitRemoteRootUrl' => '',
        ],
        'debugger' => [
            'hashedPassword' => '$2y$10$7QcYIo5gnALcmY3pM3uIMOrHWrXU5jeny.Z/Ib4Ea5sDzuMQuql46',
            'digest' => 'f2f0a813e88ab67cfa661f08922530e9',
            'contentsFolder' => './Debugger/Contents',
            'isPublic' => false,
            'enableGitEdit' => false,
            'gitRemoteRootUrl' => '',
        ],
        'dronepole' => [
            'hashedPassword' => '$2y$10$.yhSA6GNcRnqcPZJMICaVOolGSYnaHZxQVuai4gtMVyRhOji2SO3e',
            'digest' => 'b49aa68046706044fe92c734359768eb',
            'contentsFolder' => './DronePole/Contents',
            'isPublic' => true,
            'enableGitEdit' => false,
            'gitRemoteRootUrl' => '',
        ],
    ];

    // END CONFIG
    // ===============================================================

    const REALM = "Sacred area";
    const LOGIN_PAGE = "login.php";
    const LOGINED_PAGE = "file-manager.php";
    const DUMMY_HASHED_PASSWORD = '$2y$10$abcdefghijklmnopqrstuv';

    public static function UserExists($username)
    {
        return array_key_exists($username, self::USER_TABLE);
    }

    /**
     * ファイルパスからファイルを所有するユーザ名を返す．
     * 存在しない場合は，falseを返す．
     * 
     * @return string|false
     */
    public static function GetFileOwnerName($filePath)
    {
        foreach (self::USER_TABLE as $username => $info) {
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
                array_key_exists($key, self::USER_TABLE[$username]);
    }

    public static function GetUserInfo($username, $key, &$out){
        if(static::IsValidUserTableAccess($username, $key)){
            $out = self::USER_TABLE[$username][$key];
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
     * この関数が実行された後は, ログアウト状態であることが保証される.
     * ログイン状態であるときは, デフォルトウェルカムページへ移動
     * 
     */ 
    public static function RequireUnloginedSession()
    {
        // セッション開始
        @session_start();

        // ログインしているとき
        if (isset($_SESSION['username'])) {

            header('Location: ./' . self::LOGINED_PAGE);
            exit;
        }

    }

    /**
     * 
     * この関数が実行された後は, ログイン状態であることが保証される.
     * ログイン状態でないとき, loginページに移動
     * 
     */
    public static function RequireLoginedSession()
    {
        // セッション開始
        @session_start();

        // ログイン状態ではないときloginページに遷移
        if (!isset($_SESSION['username'])) {

            header('Location: ./' . self::LOGIN_PAGE);

            exit;
        }

    }

    /**
     * ログイン状態を開始する.
     * 認証に成功した時, これを呼ぶ.
     */
    public static function StartLoginedSession($username)
    {

        // セッションのIDの追跡を防ぐため, セッションIDの再割り当て
        session_regenerate_id(true);

        // ユーザ名を設定
        $_SESSION['username'] = $username;

        // ログイン後のページへ遷移
        header('Location: ./' . self::LOGINED_PAGE);

        exit;
    }

    /**
     * CSRFトークンの生成
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
     * ", ' もエスケープする.
     */
    public static function H($var)
    {
        if (is_array($var)) {
            return array_map(static::H, $var);
        } else {
            return htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
        }
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
}
