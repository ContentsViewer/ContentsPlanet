<?php

require_once dirname(__FILE__) . "/Debug.php";

class Authenticator
{

    // contentsFolderの名前は必ずContents
    private static $userTable = [
        'master' => [
            'hashedPassword' => '',
            'digest' => '',
            'contentsFolder' => './Master/Contents',
            'fileFolder' => './Master/Files',
            'isPublic' => true,
        ],
    ];

    private static $realm = "Sacred area";
    private static $loginPage = "login.php";
    private static $loginedPage = "file-manager.php";

    public static function LoginPage()
    {return static::$loginPage;}
    public static function LoginedPage()
    {return static::$loginedPage;}
    public static function Realm()
    {return static::$realm;}

    public static function UserExists($username)
    {
        return array_key_exists($username, static::$userTable);
    }

    public static function GetOwnerNameFromContentPath($contentPath)
    {
        foreach (static::$userTable as $username => $info) {
            if (strpos($contentPath, $info['contentsFolder']) === 0) {
                return $username;
            }
        }

        return false;
    }

    public static function GetLoginedUsername()
    {
        if (!isset($_SESSION['username'])) {
            return false;
        }

        return $_SESSION['username'];
    }

    public static function GetIsPublic($username = null)
    {

        if (is_null($username)) {
            $username = $_SESSION['username'];
        }

        if (!static::UserExists($username)) {
            return true;
        }

        if (!array_key_exists('isPublic', static::$userTable[$username])) {
            return true;
        }

        return static::$userTable[$username]['isPublic'];
    }

    public static function GetHashedPassword($username = null)
    {
        if (is_null($username)) {
            $username = $_SESSION['username'];
        }

        if (!static::UserExists($username)) {
            return '$2y$10$abcdefghijklmnopqrstuv';
        }

        if (!array_key_exists('hashedPassword', static::$userTable[$username])) {
            return '$2y$10$abcdefghijklmnopqrstuv';
        }

        return static::$userTable[$username]['hashedPassword'];
    }

    public static function GetDigest($username = null)
    {
        if (is_null($username)) {
            $username = $_SESSION['username'];
        }

        if (!static::UserExists($username)) {
            return false;
        }

        if (!array_key_exists('digest', static::$userTable[$username])) {
            return false;
        }

        return static::$userTable[$username]['digest'];
    }

    public static function GetContentsFolder($username = null)
    {
        if (is_null($username)) {
            $username = $_SESSION['username'];
        }
        return static::$userTable[$username]['contentsFolder'];
    }

    public static function GetFileFolder($username = null)
    {
        if (is_null($username)) {
            $username = $_SESSION['username'];
        }
        return static::$userTable[$username]['fileFolder'];
    }

    public static function IsFileOwner($fileName, $username = null)
    {
        if (is_null($username)) {
            $username = $_SESSION['username'];
        }

        $fileFolder = static::$userTable[$username]['fileFolder'];
        $contentsFolder = static::$userTable[$username]['contentsFolder'];

        $fileName = static::NormalizePath($fileName);

        if (static::StartsWith($fileName, $fileFolder)) {
            return true;
        }

        if (static::StartsWith($fileName, $contentsFolder)) {
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

    // この関数が実行された後は, ログアウト状態であることが保証される.
    // ログイン状態であるときは, デフォルトウェルカムページへ移動
    public static function RequireUnloginedSession()
    {
        // セッション開始
        @session_start();

        // ログインしているとき
        if (isset($_SESSION['username'])) {

            header('Location: ./' . static::$loginedPage);
            exit;
        }

    }

    // この関数が実行された後は, ログイン状態であることが保証される.
    // ログイン状態でないとき, loginページに移動
    public static function RequireLoginedSession()
    {
        // セッション開始
        @session_start();

        // ログイン状態ではないときloginページに遷移
        if (!isset($_SESSION['username'])) {

            header('Location: ./' . static::$loginPage);

            exit;
        }

    }

    // ログイン状態を開始する.
    // 認証に成功した時, これを呼ぶ.
    public static function StartLoginedSession($username)
    {

        // セッションのIDの追跡を防ぐため, セッションIDの再割り当て
        session_regenerate_id(true);

        // ユーザ名を設定
        $_SESSION['username'] = $username;

        // ログイン後のページへ遷移
        header('Location: ./' . Authenticator::LoginedPage());

        exit;
    }

    // CSRFトークンの生成
    public static function GenerateCsrfToken()
    {
        // セッションIDからハッシュを生成
        return hash('sha256', session_id());
    }

    // CSRFトークンの検証
    public static function ValidateCsrfToken($token)
    {
        return $token === static::GenerateCsrfToken();
    }

    //
    // ", ' もエスケープする.
    //
    public static function H($var)
    {
        if (is_array($var)) {
            return array_map(static::H, $var);
        } else {
            return htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
        }
    }

    // http auth ヘッダをパースする関数
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
        $a1 = Authenticator::GetDigest($data['username']);
        $a2 = md5($_SERVER['REQUEST_METHOD'] . ':' . $data['uri']);
        return md5($a1 . ':' . $data['nonce'] . ':' . $data['nc'] . ':' . $data['cnonce'] . ':' . $data['qop'] . ':' . $a2);

    }
}
