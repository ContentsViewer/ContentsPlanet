<?php

require_once(dirname(__FILE__) . '/ContentsPlanet.php');

require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentDatabase.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/Localization.php');
require_once(MODULE_DIR . '/ErrorHandling.php');
require_once(MODULE_DIR . '/PathUtils.php');

set_error_handler('ErrorHandling\StyledErrorHandler');

// --- Setup htaccess file ---
$htaccessDesc =
    "\n<IfModule mod_rewrite.c>\n" .
    "RewriteEngine On\n" .
    (REDIRECT_HTTPS_ENABLED ?
        "\nRewriteCond %{HTTPS} off\n" .
        "RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n"
        : '') .
    "\nRewriteCond %{REQUEST_URI} !(^" . CLIENT_URI . "/)\n" .
    "RewriteCond %{REQUEST_URI} !(^" . SERVICE_URI . "/)\n" .
    "RewriteRule ^(.*)$ index.php\n" .
    "\nRewriteCond %{HTTP:Authorization} ^(.*)\n" .
    "RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]\n" .
    "</IfModule>\n";

// NOTE: fopen オプション w ではなく c にする理由
//  wの時は, ファイルポインタをファイルの先頭に置き, ファイルサイズをゼロにします.
//  つまり, openしたときにファイルが切り詰められる. ファイルの中身が消される.
//  cオプションは, 切り詰められない.
$fp = fopen(ROOT_DIR . '/.htaccess', 'c+');
if (flock($fp, LOCK_SH)) {
    $htaccess = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $makeSegment = function ($desc, $hash) {
        return
            "\n# BEGIN ContentsPlanet\n" .
            "# hash: {$hash}\n" .
            $desc .
            "\n# END ContentsPlanet\n";
    };

    $hash = hash('fnv132', $htaccessDesc);

    if (preg_match(
        "/(^|\n)# BEGIN ContentsPlanet\n# hash: (.*?)\n(.*)\n# END ContentsPlanet/s",
        $htaccess,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        if ($matches[2][0] != $hash) {
            // Need to update.
            $htaccess = substr_replace(
                $htaccess,
                $makeSegment($htaccessDesc, $hash),
                $matches[0][1],
                strlen($matches[0][0])
            );
            file_put_contents(ROOT_DIR . '/.htaccess', $htaccess, LOCK_EX);
        }
    } else {
        file_put_contents(
            ROOT_DIR . '/.htaccess',
            $htaccess . $makeSegment($htaccessDesc, $hash),
            LOCK_EX
        );
    }
}
// End setup htaccess file ---

$vars = [];

// layer, language
// 言語パックになくても, layerがある場合
//  例えば, test_zh.content (中国語)があって, 言語パックがない.
//  その時, 言語パックをenにするならば, layer名とlocale名が一致しなくなる.
//
// layer(language)の確認, localization の設定
$vars['layerName'] = DEFAULT_LAYER_NAME;
if (isset($_COOKIE['layer'])) {
    $vars['layerName'] = $_COOKIE['layer'];
}

$vars['language'] = 'en';
if (isset($_GET['hl'])) {
    $vars['language'] = $_GET['hl'];
} else if (isset($_COOKIE['language'])) {
    $vars['language'] = $_COOKIE['language'];
}
if (!Localization\SetLocale($vars['language'])) {
    $vars['language'] = 'en';
    Localization\SetLocale($vars['language']);
}
// 有効時間 6カ月
setcookieSecure('language', $vars['language'], time() + (60 * 60 * 24 * 30 * 6), '/');

// $_SERVER['REQUEST_URI'] = '/ContentsPlanet/Master/../../Debugger/Contents/Root';
// $_SERVER['REQUEST_URI'] = '/ContentsPlanet/Master/../../../Test';

try {
    $normalizedURI = PathUtils\canonicalize($_SERVER['REQUEST_URI']);
} catch (Exception $error) {
    $vars['errorMessage'] = Localization\Localize('invalidURL', 'Invalid URL.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

if (ROOT_URI !== '' && strpos($normalizedURI, ROOT_URI) !== 0) {
    $vars['errorMessage'] = Localization\Localize('invalidURL', 'Invalid URL.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

$_SERVER['REQUEST_URI'] = $normalizedURI;

// サブURIの取得
// ex) /Master/Root
$vars['subURI'] = substr($_SERVER['REQUEST_URI'], strlen(ROOT_URI));
$length = strpos($vars['subURI'], '?');
if ($length === false) $vars['subURI'] = substr($vars['subURI'], 0);
else $vars['subURI'] = substr($vars['subURI'], 0, $length);

$vars['subURI'] = urldecode($vars['subURI']);

// 特定のパス確認
if ($vars['subURI'] == '/admin') {
    require(FRONTEND_DIR . '/admin.php');
    exit();
} else if ($vars['subURI'] == '/login') {
    require(FRONTEND_DIR . '/login.php');
    exit();
} else if ($vars['subURI'] == '/logout') {
    require(FRONTEND_DIR . '/logout.php');
    exit();
} else if ($vars['subURI'] == '/setup') {
    require(FRONTEND_DIR . '/setup.php');
    exit();
} else if ($vars['subURI'] == '/feedbacks') {
    require(FRONTEND_DIR . '/feedback-viewer.php');
    exit();
} else if ($vars['subURI'] == '/logs') {
    require(FRONTEND_DIR . '/log-viewer.php');
    exit();
} else if ($vars['subURI'] == '/' || $vars['subURI'] == '') {
    $vars['subURI'] = DEFAULT_SUB_URI;
    header('Location: ' . ROOT_URI . DEFAULT_SUB_URI, true, 301);
    exit();
}

// 権限情報の確認
$vars['owner'] = Authenticator::GetFileOwnerName('.' . URI2Path($vars['subURI']));
if ($vars['owner'] !== false) {
    $vars['isPublic'] = false;
    $vars['isAuthorized'] = true;
    Authenticator::GetUserInfo($vars['owner'], 'isPublic', $vars['isPublic']);
    if (!$vars['isPublic']) {
        // セッション開始
        @session_start();
        $loginedUser = Authenticator::GetLoginedUsername();

        if ($loginedUser !== $vars['owner']) {
            $vars['isAuthorized'] = false;
        }
    }
}

if ($vars['owner'] === false) {
    // ownerを持たないパスは存在しない
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$vars['contentsFolder'] = DEFAULT_CONTENTS_FOLDER;
Authenticator::GetUserInfo($vars['owner'], 'contentsFolder', $vars['contentsFolder']);

// ここまでで設定されている変数
//  subURI
//  owner
//  isPublic
//  isAuthorized
//  contentsFolder

if (!$vars['isPublic'] && !$vars['isAuthorized']) {
    // 非公開かつ認証されていないとき
    // 403(Forbidden)は, 認証を受けていないクライアントに存在を知られるので使用しない方がいいかも.
    // 多くのWebアプリケーション(PukiWiki, GitLab, Wordpressなど)は, 
    // 302(Found)からログインページへリダイレクトしている.
    require(FRONTEND_DIR . '/403.php');
    // header('Location: ' . ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]));
    exit();
}

// NOTE: Can we use the colon in URLs.
//  Colons are allowed in the URI path. 
//  But you need to be careful when writing relative 
//  URI paths with a colon since it is not allowed when used like this:
//
//  * https://stackoverflow.com/questions/1737575/are-colons-allowed-in-urls


// Split path into each segments.
// ex)
//  '/Master/:tagmap/A'
//      => ['', 'Master', ':tagmap', 'A']
$segments = explode('/', $vars['subURI']);

// URLs except resources in content folders begin with `:`.

// Redirect old tagmap url.
// TODO: Someday this process will be removed.
if (isset($segments[2]) && $segments[2] === 'TagMap') {
    $segments[2] = ':tagmap';
    header('Location: ' . ROOT_URI . implode('/', $segments) . '?' . $_SERVER['QUERY_STRING'], true, 301);
    exit();
}

if (isset($segments[2]) && $segments[2] === ':tagmap') {
    require(FRONTEND_DIR . '/tag-viewer.php');
    exit();
}

if (isset($segments[2]) && $segments[2] === ':scripts') {
    require(FRONTEND_DIR . '/script-server.php');
    exit();
}

// ファイルかどうか
if (is_file(CONTENTS_HOME_DIR . URI2Path($vars['subURI']))) {
    $vars['filePath'] = CONTENTS_HOME_DIR . URI2Path($vars['subURI']);
    require(FRONTEND_DIR . '/file-server.php');
    exit();
}

// ディレクトリかどうか
if (is_dir(CONTENTS_HOME_DIR . URI2Path($vars['subURI']))) {
    $directoryPath = URI2Path($vars['subURI']);
    if (strrpos($directoryPath, '/') === strlen($directoryPath) - 1) {
        $directoryPath = substr($directoryPath, 0, -1);
    }

    $vars['directoryPath'] = '.' . $directoryPath;
    require(FRONTEND_DIR . '/directory-viewer.php');
    exit();
}

// contentPathの取得
$vars['contentPath'] = '.' . URI2Path($vars['subURI']);

// コマンドの確認
if (isset($_GET['cmd'])) {
    if ($_GET['cmd'] == 'edit') {
        require(FRONTEND_DIR . '/content-editor.php');
        exit();
    } else if ($_GET['cmd'] == 'preview') {
        require(FRONTEND_DIR . '/preview.php');
        exit();
    } else if ($_GET['cmd'] == 'history') {
        require(FRONTEND_DIR . '/history-viewer.php');
        exit();
    }
}

// plainText モードの確認
if (isset($_GET['plainText'])) {
    $vars['filePath'] = ContentPathUtils::RealPath($vars['contentPath'] . Content::EXTENSION);
    if ($vars['filePath'] === false) {
        require(FRONTEND_DIR . '/404.php');
        exit();
    }

    require(FRONTEND_DIR . '/plaintext.php');
    exit();
}

// ノートページのとき
if (pathinfo($vars['subURI'], PATHINFO_EXTENSION) == 'note') {
    require(FRONTEND_DIR . '/note-viewer.php');
    exit();
}

require(FRONTEND_DIR . '/contents-viewer.php');
