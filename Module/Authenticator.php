<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/PathUtils.php";
require_once dirname(__FILE__) . "/CacheManager.php";

class Authenticator
{
    const REALM = "Sacred area";

    private array $userTable;
    private string $rootUri;

    public function __construct(array $userTable, string $rootUri)
    {
        $this->userTable = $userTable;
        $this->rootUri = $rootUri;
    }

    // --- User table ---

    public function userExists(string $username): bool
    {
        return isset($this->userTable[$username]);
    }

    /**
     * ファイルパスからファイルを所有するユーザ名を返す．
     * 存在しない場合は，falseを返す．
     *
     * @param string $filePath Homeからのパス. ex)./Master/Contents
     * @return string|false
     */
    public function getFileOwnerName(string $filePath): string|false
    {
        try {
            $filePath = \PathUtils\canonicalize($filePath);
        } catch (\Exception $error) {
            return false;
        }

        foreach ($this->userTable as $username => $info) {
            try {
                $contentsFolder = \PathUtils\canonicalize($info['contentsFolder']);
            } catch (\Exception $error) {
                continue;
            }

            if (str_starts_with($filePath . '/', $contentsFolder . '/')) {
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
    public function getLoginedUsername(): string|false
    {
        return $_SESSION['username'] ?? false;
    }

    public function isValidUserTableAccess(string $username, string $key): bool
    {
        return $this->userExists($username) &&
            isset($this->userTable[$username][$key]);
    }

    /**
     * @param mixed $out 取得した値の出力先
     */
    public function getUserInfo(string $username, string $key, mixed &$out): bool
    {
        if ($this->isValidUserTableAccess($username, $key)) {
            $out = $this->userTable[$username][$key];
            return true;
        }

        return false;
    }

    public function isFileOwner(string $filePath, string $username): bool
    {
        if (!$this->getUserInfo($username, 'contentsFolder', $contentsFolder)) {
            return false;
        }
        try {
            $contentsFolder = \PathUtils\canonicalize($contentsFolder);
            $filePath = \PathUtils\canonicalize($filePath);
        } catch (\Exception $error) {
            return false;
        }

        return str_starts_with($filePath, $contentsFolder);
    }

    // --- URL helpers ---

    /**
     * ユーザ名を含めないURLを返します.
     */
    public function getLoginedUrl(string $returnTo = ''): string
    {
        $scheme = empty($_SERVER['HTTPS']) ? 'http://' : 'https://';
        if ($returnTo !== '') {
            return $scheme . $_SERVER['HTTP_HOST'] . $returnTo;
        }
        return $scheme . $_SERVER['HTTP_HOST'] . $this->rootUri . '/admin';
    }

    /**
     * @param string $returnTo ログイン完了後に遷移するページ先(URLエンコード不要)
     */
    public function getLoginUrl(string $returnTo = ''): string
    {
        $url = $this->rootUri . '/login';
        if ($returnTo !== '') {
            $url .= '?returnTo=' . urlencode($returnTo);
        }
        return $url;
    }

    // --- Session guards ---

    /**
     * この関数が実行された後は, ログアウト状態であることが保証される.
     * ログイン状態であるときは, デフォルトウェルカムページへ移動
     */
    public function requireUnloginedSession(string $returnTo = ''): void
    {
        @session_start();

        if (isset($_SESSION['username'])) {
            header('Location: ' . $this->getLoginedUrl($returnTo));
            exit;
        }
    }

    /**
     * この関数が実行された後は, ログイン状態であることが保証される.
     * ログイン状態でないとき, loginページに移動
     */
    public function requireLoginedSession(string $returnTo = ''): void
    {
        @session_start();

        if (!isset($_SESSION['username'])) {
            header('Location: ' . $this->getLoginUrl($returnTo));
            exit;
        }
    }

    /**
     * ログイン状態を開始する.
     * 認証に成功した時, これを呼ぶ.
     */
    public function startLoginedSession(string $username, string $returnTo = ''): void
    {
        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        header('Location: ' . $this->getLoginedUrl($returnTo));
        exit;
    }

    // --- CSRF ---

    /**
     * CSRFトークンの生成
     * session_id()をもとに生成
     */
    public function generateCsrfToken(): string
    {
        return hash('sha256', session_id());
    }

    /**
     * CSRFトークンの検証
     */
    public function validateCsrfToken(string $token): bool
    {
        return $token === $this->generateCsrfToken();
    }

    // --- Digest auth ---

    public function sendDigestAuthenticationHeader(): void
    {
        $nonce = $this->createNonce();
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Digest realm="' . self::REALM . '",qop="auth",nonce="' . $nonce . '"');
    }

    /**
     * ダイジェスト認証を行う.
     * 成功時はユーザ名, 失敗時は false を返す
     *
     * @param string $header PHP_AUTH_DIGESTの値
     * @return string|false
     */
    public function verifyDigest(string $header): string|false
    {
        $params = $this->httpDigestParse($header);
        $validResponse = $this->verifyDigestResponse($params);
        $validNonce = $this->verifyNonce($params['nonce']);
        return ($validResponse && $validNonce) ? $params['username'] : false;
    }

    // --- OTP ---

    /**
     * @param int $expires 有効期限（秒）
     */
    public function generateOtp(int $expires): string
    {
        $newOtp = bin2hex(random_bytes(32));

        $cache = new Cache();
        $cache->connect('otps');
        $cache->lock(LOCK_EX);
        $cache->fetch();
        $otps = $cache->data['otps'] ?? [];
        foreach ($otps as $otp => $exp) {
            if ($exp < time()) {
                unset($otps[$otp]);
            }
        }
        $otps[$newOtp] = time() + $expires;

        $cache->data['otps'] = $otps;
        $cache->apply();
        $cache->unlock();
        $cache->disconnect();

        return $newOtp;
    }

    public function verifyOtp(string $otp): bool
    {
        $cache = new Cache();
        $cache->connect('otps');
        $cache->lock(LOCK_SH);
        $cache->fetch();
        $otps = $cache->data['otps'] ?? [];
        $cache->unlock();
        $cache->disconnect();

        return isset($otps[$otp]);
    }

    // --- Utility ---

    /**
     * APR1-MD5 encryption method (windows compatible).
     * Apache .htpasswd互換のAPR1-MD5ハッシュ生成。
     * 現在未使用だが将来の利用に備えて保持。
     */
    public function cryptApr1Md5(string $plainpasswd): string
    {
        $salt = substr(str_shuffle("abcdefghijklmnopqrstuvwxyz0123456789"), 0, 8);
        $len = strlen($plainpasswd);
        $text = $plainpasswd . '$apr1$' . $salt;
        $bin = pack("H32", md5($plainpasswd . $salt . $plainpasswd));
        for ($i = $len; $i > 0; $i -= 16) {
            $text .= substr($bin, 0, min(16, $i));
        }
        for ($i = $len; $i > 0; $i >>= 1) {
            $text .= ($i & 1) ? chr(0) : $plainpasswd[0];
        }
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
        $tmp = strtr(
            strrev(substr(base64_encode($tmp), 2)),
            "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",
            "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
        );

        return "$" . "apr1" . "$" . $salt . "$" . $tmp;
    }

    // --- Private helpers ---

    private function createNonce(): string
    {
        $expires = time() - 30;
        $newNonce = md5(random_bytes(30));

        $cache = new Cache();
        $cache->connect('nonces');
        $cache->lock(LOCK_EX);
        $cache->fetch();

        $nonces = $cache->data['nonces'] ?? [];
        foreach ($nonces as $nonce => $ts) {
            if ($ts < $expires) {
                unset($nonces[$nonce]);
            }
        }
        $nonces[$newNonce] = time();

        $cache->data['nonces'] = $nonces;
        $cache->apply();
        $cache->unlock();
        $cache->disconnect();

        return $newNonce;
    }

    private function verifyNonce(string $nonce): bool
    {
        $verified = false;
        $expires = time() - 30;

        $cache = new Cache();
        $cache->connect('nonces');
        $cache->lock(LOCK_EX);
        $cache->fetch();
        $nonces = $cache->data['nonces'] ?? [];
        if (isset($nonces[$nonce])) {
            if ($nonces[$nonce] > $expires) {
                $verified = true;
            }
            unset($nonces[$nonce]);
            $cache->data['nonces'] = $nonces;
            $cache->apply();
        }
        $cache->unlock();
        $cache->disconnect();

        return $verified;
    }

    /**
     * http auth ヘッダをパースする関数
     *
     * @param string $header Authorizationヘッダ
     * @return array<string, string>
     */
    private function httpDigestParse(string $header): array
    {
        $keys = ['response', 'nonce', 'nc', 'cnonce', 'qop', 'uri', 'username'];
        $p = array_fill_keys($keys, '');
        $regex = '/(' . implode('|', $keys) . ')=(?:\'([^\']++)\'|"([^"]++)"|([^\s,]++))/';
        preg_match_all($regex, $header, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $p[$m[1]] = $m[3] ?: $m[4];
        }
        return $p;
    }

    /**
     * responseの妥当性検証
     *
     * @param array<string, string> $params パースされたPHP_AUTH_DIGEST
     */
    private function verifyDigestResponse(array $params): bool
    {
        $a1 = '';
        $this->getUserInfo($params['username'], 'digest', $a1);

        $expected = md5(implode(':', [
            $a1,
            $params['nonce'],
            $params['nc'],
            $params['cnonce'],
            $params['qop'],
            md5("{$_SERVER['REQUEST_METHOD']}:{$params['uri']}")
        ]));
        return hash_equals($expected, $params['response']);
    }
}

/**
 * Authenticatorの共有インスタンスを返す。
 */
function authenticator(): Authenticator
{
    static $instance = null;
    if ($instance === null) {
        $instance = new Authenticator(
            defined('USER_TABLE') ? USER_TABLE : [],
            defined('ROOT_URI') ? ROOT_URI : ''
        );
    }
    return $instance;
}
