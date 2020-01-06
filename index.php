<?php

require_once(dirname(__FILE__) . '/CollabCMS.php');


require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');

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
$htaccess = "
<IfModule mod_rewrite.c>
RewriteEngine On
";

if(REDIRECT_HTTPS_ENABLED){
    $htaccess .= "
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
";
}

$htaccess .= "
RewriteCond %{REQUEST_URI} !(^" . CLIENT_URI . "/)
RewriteCond %{REQUEST_URI} !(^" . SERVICE_URI . "/)
RewriteRule ^(.*)$ index.php

RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]
</IfModule>
";
file_put_contents(ROOT_DIR . '/.htaccess', $htaccess);

$vars = [];

// $_SERVER['REQUEST_URI'] = '/CollabCMS/Master/../../Debugger/Contents/Root';

$normalizedURI = NormalizePath($_SERVER['REQUEST_URI']);
if($normalizedURI === false){
    $vars['errorMessage'] = 'URLが不正です.';
    require(FRONTEND_DIR . '/400.php');
    exit();
}

if(ROOT_URI !== '' && strpos($normalizedURI, ROOT_URI) !== 0){
    $vars['errorMessage'] = 'URLが不正です.';
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

if($vars['subURI'] == GetTopDirectory($vars['subURI']) . '/TagList'){
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
    $filePath = CONTENTS_HOME_DIR . URI2Path($vars['subURI']);
    $filePath = Content::RelativePath($filePath);
    if(strrpos($filePath, '/') !== strlen($filePath) - 1){
        $filePath = $filePath . '/';
    }
    $basename = basename($filePath);

    $content = new Content();
    if($content->SetContent($filePath . ROOT_FILE_NAME)){
        header('Location: ' . ROOT_URI . Path2URI($filePath . ROOT_FILE_NAME), true, 301);
        exit();
    }
    else if($content->SetContent($filePath . $basename)){
        header('Location: ' . ROOT_URI . Path2URI($filePath . $basename), true, 301);
        exit();
    }
}

// contentPathの取得
$vars['contentPath'] = '.' . URI2Path($vars['subURI']);


// 存在しないコンテンツ確認
$content = new Content();
if(!$content->SetContent($vars['contentPath'])){
    require(FRONTEND_DIR . '/404.php');
    exit();
}

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


require(FRONTEND_DIR . '/contents-viewer.php');