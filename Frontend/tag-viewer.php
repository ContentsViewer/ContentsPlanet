<?php

require_once(MODULE_DIR . "/ContentsDatabaseManager.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Stopwatch.php");
require_once(MODULE_DIR . '/Authenticator.php');


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

ContentsDatabaseManager::LoadRelatedMetadata($vars['rootContentPath']);

$tagMap = ContentsDatabase::$metadata['tag2path'];

$tagName = '';
$detailMode = false;
if (isset($_GET['name'])) {
    $tagName = urldecode($_GET['name']);

    if (array_key_exists($tagName, $tagMap)) {
        $detailMode = true;
    }
}

$sortedContents = [];
if($detailMode){
    $sortedContents = GetSortedContentsByUpdatedTime($tagMap[$tagName]);
}

$navigator = CreateTagNavigator($tagMap, $tagName, $vars['rootDirectory']);


// === ページ内容設定 =======================================================

// title作成
$vars['pageTitle'] = ($detailMode ? $tagName . ' | ' : '') . 'タグ一覧';

// 追加ヘッダ
$vars['additionalHeadScripts'] = [];

// navigator 設定
$vars['navigator'] = $navigator;

// pageHeading の作成
if($detailMode){
    $vars['pageHeading']['title'] = $tagName;
    $vars['pageHeading']['parents'] = [['title' => 'タグ一覧', 'path' => CreateTagDetailHREF('', $vars['rootDirectory'])]];
}
else{
    $vars['pageHeading']['title'] ='タグ一覧';
    $vars['pageHeading']['parents'] = [];
}

$vars['contentSummary'] = '';
if(!$detailMode){
    $vars['latestContents'] = ContentsDatabase::$metadata['latestContents'];
    $vars['tagList'] = ContentsDatabase::$metadata['tag2path'];
}


$vars['contentBody'] = '';

// child list の設定
$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]

foreach($sortedContents as $content){
    $parent = $content->Parent();
    $vars['childList'][] = [
        'title' => $content->Title() . ($parent === false ? '' : ' | ' . $parent->Title()), 
        'summary' => GetDecodedText($content)['summary'], 
        'url' => CreateContentHREF($content->Path())
    ];
}

// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

require(FRONTEND_DIR . '/viewer.php');
