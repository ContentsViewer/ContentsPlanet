<?php

require_once(dirname(__FILE__) . '/CollabCMS.php');


require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/Localization.php');


// 古いURLのリダイレクト
if (isset($_GET['content'])) {
    // ./Master/Contents/Root
    $contentPath = $_GET['content'];
    $contentPath = Path2URI($contentPath);
    // echo $contentPath;
    header('Location: ' . ROOT_URI . $contentPath, true, 301);
    exit();
}

// .htaccessの確認
$htaccess = 
    "\n<IfModule mod_rewrite.c>\n" .
    "RewriteEngine On\n";

if(REDIRECT_HTTPS_ENABLED){
    $htaccess .= 
        "\nRewriteCond %{HTTPS} off\n" .
        "RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n";
}

$htaccess .= 
    "\nRewriteCond %{REQUEST_URI} !(^" . CLIENT_URI . "/)\n" . 
    "RewriteCond %{REQUEST_URI} !(^" . SERVICE_URI . "/)\n" .
    "RewriteRule ^(.*)$ index.php\n" .
    "\nRewriteCond %{HTTP:Authorization} ^(.*)\n" .
    "RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]\n" .
    "</IfModule>\n";

// NOTE fopen オプション w ではなく c にする理由
//  wの時は, ファイルポインタをファイルの先頭に置き, ファイルサイズをゼロにします.
//  つまり, openしたときにファイルが切り詰められる. ファイルの中身が消される.
//  cオプションは, 切り詰められない.
$htaccessFp = fopen(ROOT_DIR . '/.htaccess', 'c+');
if(flock($htaccessFp, LOCK_SH)){
    $htaccessFileContents = stream_get_contents($htaccessFp);
    flock($htaccessFp, LOCK_UN);
    fclose($htaccessFp);

    if(preg_match("/(^|\n)# BEGIN CollabCMS *\n(.*)\n# END CollabCMS */s", $htaccessFileContents, $matches, PREG_OFFSET_CAPTURE)){
        $htaccessFileContents = substr_replace($htaccessFileContents, $htaccess, $matches[2][1], strlen($matches[2][0]));
        file_put_contents(ROOT_DIR . '/.htaccess', $htaccessFileContents, LOCK_EX);
    }
    else{
        file_put_contents(ROOT_DIR . '/.htaccess', 
            $htaccessFileContents .
            "\n# BEGIN CollabCMS\n" .
            $htaccess . 
            "\n# END CollabCMS\n", 
            LOCK_EX);
    }
}

$vars = [];

// layer, language
// 言語パックになくても, layerがある場合
//  例えば, test_ch.content (中国語)があって, 言語パックがない.
//  その時, 言語パックをenにするならば, layer名とlocale名が一致しなくなる.
//
// layer(language)の確認, localization の設定
$vars['layerName'] = DEFAULT_LAYER_NAME;
if(isset($_COOKIE['layer'])){
    $vars['layerName'] = $_COOKIE['layer'];
}

$vars['language'] = 'en';
if(isset($_GET['hl'])){
    $vars['language'] = $_GET['hl'];
}
else if(isset($_COOKIE['language'])){
    $vars['language'] = $_COOKIE['language'];
}
if(!Localization\SetLocale($vars['language'])){
    $vars['language'] = 'en';
    Localization\SetLocale($vars['language']);
}
setcookie('language', $vars['language'], time()+(60*60*24*30*6), '/'); // 有効時間 6カ月

// $_SERVER['REQUEST_URI'] = '/CollabCMS/Master/../../Debugger/Contents/Root';

$normalizedURI = NormalizePath($_SERVER['REQUEST_URI']);
if($normalizedURI === false){
    $vars['errorMessage'] = Localization\Localize('invalidURL', 'Invalid URL.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

if(ROOT_URI !== '' && strpos($normalizedURI, ROOT_URI) !== 0){
    $vars['errorMessage'] = Localization\Localize('invalidURL', 'Invalid URL.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

$_SERVER['REQUEST_URI'] = $normalizedURI;

// サブURIの取得
// ex) /Master/Root
$vars['subURI'] = substr($_SERVER['REQUEST_URI'], strlen(ROOT_URI));
$length = strpos($vars['subURI'], '?');
if($length === false) $vars['subURI'] = substr($vars['subURI'], 0);
else $vars['subURI'] = substr($vars['subURI'], 0, $length);

$vars['subURI'] = urldecode($vars['subURI']);

// 特定のパス確認
if($vars['subURI'] == '/FileManager'){
    require(FRONTEND_DIR . '/file-manager.php');
    exit();
}
else if($vars['subURI'] == '/Login'){
    require(FRONTEND_DIR . '/login.php');
    exit();
}
else if($vars['subURI'] == '/Logout'){
    require(FRONTEND_DIR . '/logout.php');
    exit();
}
else if($vars['subURI'] == '/Setup'){
    require(FRONTEND_DIR . '/setup.php');
    exit(); 
}
else if($vars['subURI'] == '/' || $vars['subURI'] == ''){
    $vars['subURI'] = DEFAULT_SUB_URI;
    header('Location: ' . ROOT_URI . DEFAULT_SUB_URI, true, 301);
    exit();
}

// 権限情報の確認
$vars['owner'] = Authenticator::GetFileOwnerName('.' . URI2Path($vars['subURI']));
if($vars['owner'] !== false){
    $vars['isPublic'] = false;
    $vars['isAuthorized'] = true;
    Authenticator::GetUserInfo($vars['owner'], 'isPublic', $vars['isPublic']);
    if(!$vars['isPublic']){
        // セッション開始
        @session_start();
        $loginedUser = Authenticator::GetLoginedUsername();
        
        if ($loginedUser !== $vars['owner']) {
            $vars['isAuthorized'] = false;
        }
    }
}

if($vars['owner'] === false){
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

if(!$vars['isPublic'] && !$vars['isAuthorized']){
    // 非公開かつ認証されていないとき
    // 403(Forbidden)は, 認証を受けていないクライアントに存在を知られるので使用しない方がいいかも.
    // 多くのWebアプリケーション(PukiWiki, GitLab, Wordpressなど)は, 
    // 302(Found)からログインページへリダイレクトしている.
    require(FRONTEND_DIR . '/403.php');
    // header('Location: ' . ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]));
    exit();
}

if(($vars['subURI'] == GetTopDirectory($vars['subURI']) . '/TagMap') || 
    strpos($vars['subURI'], GetTopDirectory($vars['subURI']) . '/TagMap/') === 0){
    require(FRONTEND_DIR . '/tag-viewer.php');
    exit();
}

// ファイルかどうか
if(is_file(CONTENTS_HOME_DIR . URI2Path($vars['subURI']))){
    $vars['filePath'] = CONTENTS_HOME_DIR . URI2Path($vars['subURI']);
    require(FRONTEND_DIR . '/file-server.php');
    exit();
}

// ディレクトリかどうか
if(is_dir(CONTENTS_HOME_DIR . URI2Path($vars['subURI']))){
    $directoryPath = URI2Path($vars['subURI']);
    if(strrpos($directoryPath, '/') === strlen($directoryPath) - 1){
        $directoryPath = substr($directoryPath, 0, -1);
    }

    $vars['directoryPath'] = $directoryPath;
    require(FRONTEND_DIR . '/directory-viewer.php');
    exit();
}

// contentPathの取得
$vars['contentPath'] = '.' . URI2Path($vars['subURI']);

// コマンドの確認
if (isset($_GET['cmd'])) {
    if($_GET['cmd'] == 'edit'){
        require(FRONTEND_DIR . '/content-editor.php');
        exit();
    }
    else if($_GET['cmd'] == 'preview'){
        require(FRONTEND_DIR . '/preview.php');
        exit();
    }
}

// plainText モードの確認
if (isset($_GET['plainText'])) {
    $vars['filePath'] = Content::RealPath($vars['contentPath']);
    if($vars['filePath'] === false){
        require(FRONTEND_DIR . '/404.php');
        exit();
    }

    require(FRONTEND_DIR . '/plaintext.php');
    exit();
}

// ノートページのとき
if(GetExtention($vars['subURI']) == '.note'){
    require(FRONTEND_DIR . '/note-viewer.php');
    exit();
}

require(FRONTEND_DIR . '/contents-viewer.php');