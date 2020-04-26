<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Authenticator.php');


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['parse'] = ['displayName' => 'Parse Time', 'ms' => 0];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);

if(basename($vars['subURI']) == '.note'){
    // '.note'のみで, そのあとにコンテンツ名が無い場合
    $vars['errorMessage'] = 'URLが不正です.';
    require(FRONTEND_DIR . '/400.php');
    exit();
}

$relatedContentPath = dirname($vars['contentPath']) . '/' . basename($vars['contentPath'], '.note');

$note = new Content();
$noteExists = $note->SetContent($vars['contentPath']);

$vars['pageTabs'] = [];

$content = new Content();
if($content->SetContent($relatedContentPath)){

    $vars['pageTitle'] = 'ノート: ' . NotBlankText([$content->title, basename($content->path)]);

    // page-tabの追加
    $vars['pageTabs'][] = ['selected' => false, 'innerHTML' => '<a href="' . CreateContentHREF($content->path) . '">コンテンツ</a>'];

    if(($navigator = GetNavigator($content->path)) !== false){
        $vars['navigator'] = $navigator;
    }
    else{
        $vars['navigator'] = '<nav class="navi"><ul><li>一時的に利用できません</li></ul></nav>';
    }

    $vars['contentSummary'] = '<p>「コンテンツ: <a href="' . CreateContentHREF($content->path) . '">' .  
        NotBlankText([$content->title, basename($content->path)]) .'</a>」に関するノート</p>';
}
else{
    // コンテンツがない場合

    if(!$noteExists){
        // Noteファイルもないとき

        require(FRONTEND_DIR . '/404.php');
        exit();
    }


    $vars['pageTitle'] = 'ノート: ' . basename($relatedContentPath);

    if(($navigator = GetNavigator($vars['rootContentPath'])) !== false){
        $vars['navigator'] = $navigator;
    }
    else{
        $vars['navigator'] = '<nav class="navi"><ul><li>一時的に利用できません</li></ul></nav>';
    }
    
    $vars['contentSummary'] = '<p>ノートは存在しますが, 関連付けられているコンテンツ「' .  basename($relatedContentPath) .'.content」が見つかりません.</p>';
    $vars['contentSummary'] .= '<ul><li>「' .  basename($relatedContentPath) . '.content」を<a href="' . ROOT_URI . dirname($vars['subURI']) .'">同階層</a>から探す.</li>' .
        '<li>「' .  basename($relatedContentPath) . '.content」を<a href="javascript:void(0);" onclick="OnClickSearchButton(\'' . H(Path2URI($relatedContentPath)) . '\')">検索する</a>.</li></ul>';
}

$vars['pageHeading']['parents'] = [];
$vars['pageHeading']['title'] = $vars['pageTitle'];

$vars['pageTabs'][] = ['selected' => true, 'innerHTML' => '<a href="' . ROOT_URI .$vars['subURI'] . '">ノート</a>'];
$vars['pageTabs'][] = ['selected' => false, 'innerHTML' => '<a href="' . ROOT_URI . dirname($vars['subURI']) . '">ディレクトリ</a>'];


$vars['childList'] = [];

// ここまでで, コンテンツがあるが, Noteファイルがない
// or コンテンツがあり, Noteファイルもある
// or コンテンツがなく, Noteファイルがある


$body = '';

// Note:
//  同じディレクトリ内で, 同名のサブディレクトリとファイルは存在できない.
if($noteExists){
    $parsingStopwatch = new Stopwatch();
    $parsingStopwatch->Start();

    $text = GetDecodedText($note);

    $parsingStopwatch->Stop();
    
    $vars['pageBuildReport']['times']['parse']['ms'] = $parsingStopwatch->Elapsed() * 1000;

    $vars['fileDate'] = ['createdTime' => $note->createdTime, 'modifiedTime' => $note->modifiedTime];
    $vars['contentSummary'] .= $text['summary'];
    $body .= $text['body'];
}
else{
    $body = '<p>ノートファイルが存在しません. </p>';
    // $body .= '<p>同階層に「' .  basename($contentPath) . '.note」を作成してください. </p>';
}

$vars['contentBody'] = $body;

// plainText リンクの追加
$vars['addPlainTextLink'] = true;

// edit リンクの追加
$vars['addEditLink'] = true;
$vars['openNewTabEditLink'] = $enableRemoteEdit;

// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

require(FRONTEND_DIR . '/viewer.php');

function GetNavigator($contentPath){
    $cache = new Cache;
    $cache->Connect($contentPath);
    $cache->Lock(LOCK_SH);
    $cache->Fetch();
    $cache->Unlock();
    $cache->Disconnect();

    if(!is_null($cache->data) && array_key_exists('navigator', $cache->data)){
        return $cache->data['navigator'];
    }
    return false;
}