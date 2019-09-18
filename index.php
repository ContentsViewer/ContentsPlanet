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
file_put_contents(ROOT_DIR . '/.htaccess', "
<IfModule mod_rewrite.c>
RewriteEngine On

RewriteCond %{REQUEST_URI} !(^" . CLIENT_URI . "/)
RewriteCond %{REQUEST_URI} !(^" . SERVICE_URI . "/)
RewriteRule ^(.*)$ index.php

</IfModule>
");

$vars = [];

// セッション開始
@session_start();
$vars['loginedUser'] = Authenticator::GetLoginedUsername();

// if(strpos($_SERVER['REQUEST_URI'], ROOT_URI) !== 0){
//     require(FRONTEND_DIR . '/500.php');
//     exit();
// }

// サブURIの取得
// ex) /Master/Root

$vars['subURI'] = substr($_SERVER['REQUEST_URI'], strlen(ROOT_URI));
$length = strpos($vars['subURI'], '?');
if($length === false) $vars['subURI'] = substr($vars['subURI'], 0);
else $vars['subURI'] = substr($vars['subURI'], 0, $length);


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
else if($vars['subURI'] == '/'){
    $vars['subURI'] = DEFAULT_SUB_URI;
}

// 権限情報の確認
$vars['owner'] = Authenticator::GetFileOwnerName('.' . URI2Path($vars['subURI']));
if($vars['owner'] !== false){
    $vars['isPublic'] = false;
    $vars['isAuthorized'] = true;
    Authenticator::GetUserInfo($vars['owner'], 'isPublic', $vars['isPublic']);
    if(!$vars['isPublic']){
        if ($vars['loginedUser'] !== $vars['owner']) {
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
    require(FRONTEND_DIR . '/401.php');
    exit();
}

if($vars['subURI'] == GetTopDirectory($vars['subURI']) . '/TagList'){
    require(FRONTEND_DIR . '/tag-list.php');
    exit();
}


// ファイルかどうか
if(is_file(CONTENTS_HOME_DIR . URI2Path($vars['subURI']))){
    $vars['filePath'] = CONTENTS_HOME_DIR . URI2Path($vars['subURI']);
    require(FRONTEND_DIR . '/file-server.php');
    exit();
}

// ディレクトリかどうか

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


require(FRONTEND_DIR . '/viewer.php');