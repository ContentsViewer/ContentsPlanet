<?php

require_once(MODULE_DIR . '/ContentsDatabaseManager.php');

$contentPath = $vars['contentPath'];

if (isset($_GET['plainText'])) {
    echo '<!DOCTYPE html><html lang="ja"><head></head><body>';
    echo '<pre style="white-space: pre; font-family: Consolas,Liberation Mono,Courier,monospace; font-size: 12px;">';
    echo htmlspecialchars(file_get_contents(Content::RealPath($contentPath)));
    echo '</pre>';
    echo '</body></html>';
    exit();
}

require_once(MODULE_DIR . '/OutlineText.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/SearchEngine.php');


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['parse'] = ['displayName' => 'Parse Time', 'ms' => 0];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates']['navigator'] = ['displayName' => 'Nav', 'updated' => false];


$parentsMaxCount = 3;
$parents = [];
$children = [];
$leftContent = null;
$rightContent = null;


$stopwatch = new Stopwatch();

// コンテンツの取得
$currentContent = new Content();
$currentContent->SetContent($contentPath);

$vars['rootContentPath'] = ContentsDatabaseManager::GetRelatedRootFile($contentPath);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);


// テキストの読み込み
$stopwatch->Start();

$text = GetDecodedText($currentContent);
$currentContent->SetSummary($text['summary']);
$currentContent->SetBody($text['body']);

$vars['pageBuildReport']['times']['parse']['ms'] = $stopwatch->Elapsed() * 1000;

// ChildContentsの取得
$childrenPathList = $currentContent->ChildPathList();
$childrenPathListCount = count($childrenPathList);
for ($i = 0; $i < $childrenPathListCount; $i++) {
    $child = $currentContent->Child($i);
    if ($child !== false) {
        $children[] = $child;
    }
}

// Parentsの取得
$parent = $currentContent->Parent();

for ($i = 0; $i < $parentsMaxCount; $i++) {
    if ($parent === false) {
        break;
    }
    $parents[] = $parent;
    $parent = $parent->Parent();
}

// LeftContent, RightContentの取得
if (isset($parents[0])) {
    $parent = $parents[0];
    $brothers = $parent->ChildPathList();
    $myIndex = $currentContent->ChildIndex();

    if ($myIndex >= 0) {
        if ($myIndex > 0) {
            $leftContent = $parent->Child($myIndex - 1);
        }
        if ($myIndex < count($brothers) - 1) {
            $rightContent = $parent->Child($myIndex + 1);
        }
    }
}

$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($contentPath);
// メタデータの読み込み
ContentsDatabaseManager::LoadRelatedMetadata($contentPath);


// --- navigator作成 -------------------------------------------------
// naviの更新条件
// 
// 現在のコンテンツがコンテンツフォルダよりも新しいとき
// コンテンツ間関係が古い可能性あり．
//
// キャッシュがそもそもないとき
// キャッシュ作成のためにnavi作成
//
// キャッシュのnavi更新時間がコンテンツの更新時間の前のとき
// キャッシュが古いので更新
//
$contentsIsChanged = 
    (!array_key_exists('contentsChangedTime', ContentsDatabase::$metadata) ||
    $currentContent->UpdatedAtTimestamp() > ContentsDatabase::$metadata['contentsChangedTime']);

if($contentsIsChanged ||
    is_null($cache = CacheManager::ReadCache($currentContent->Path())) ||
    !array_key_exists('navigator', $cache) ||
    !array_key_exists('navigatorUpdateTime', $cache) ||
    ($cache['navigatorUpdateTime'] < ContentsDatabase::$metadata['contentsChangedTime'])
    ){
    
    $navigator = "<nav class='navi'><ul>";
    CreateNavHelper($parents, count($parents) - 1, $currentContent, $children, $navigator);
    $navigator .= '</ul></nav>';
    $cache['navigator'] = $navigator;
    
    // 読み込み時の時間を使う
    // 読み込んでからの変更を逃さないため
    $cache['navigatorUpdateTime'] = $currentContent->OpenedTime();

    CacheManager::WriteCache($currentContent->Path(), $cache);
    $vars['pageBuildReport']['updates']['navigator']['updated'] = true;
}

$navigator = $cache['navigator'];

// End navigator 作成 ------------------------------------------------


// メタデータの更新
// contentsChangedTime がここで更新される
ContentsDatabaseManager::RegistMetadata($currentContent);
ContentsDatabase::SaveMetadata($metaFileName);

// インデックスの読み込み
ContentsDatabaseManager::LoadRelatedIndex($contentPath);

// インデックスの更新
$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);
ContentsDatabaseManager::RegistIndex($currentContent);
SearchEngine\Indexer::ApplyIndex($indexFilePath);


// === ページ内容設定 =======================================================

// title作成
$title = "";
$title .= $currentContent->Title();
if (isset($parents[0])) {
    $title .= " | " . $parents[0]->Title();
}
$vars['pageTitle'] = $title;

// 追加ヘッダ
$vars['additionalHeadScripts'] = [];
if($currentContent->IsEndpoint()){
    $vars['additionalHeadScripts'][] = CLIENT_DIR . "/Common/AdSenseHead.html";
}

// pageHeading の作成
$vars['pageHeading']['title'] = $currentContent->Title();
$parentTitlePathList = [];
foreach($parents as $parent){
    if($parent === false) break;
    $parentTitlePathList[] = ['title' => $parent->Title(), 'path' => CreateContentHREF($parent->Path())];
}
$vars['pageHeading']['parents'] = $parentTitlePathList;

// Left, Right Content の設定
if (!is_null($leftContent) && $leftContent !== false) {
    $vars['leftContent'] = ['title' => $leftContent->Title(), 'url' => CreateContentHREF($leftContent->Path())];
}

if (!is_null($rightContent) && $rightContent !== false) {
    $vars['rightContent'] = ['title' => $rightContent->Title(), 'url' => CreateContentHREF($rightContent->Path())];
}

// navigator の設定
$vars['navigator'] = $navigator;

// file date の設定
$vars['fileDate'] = ['createdAt' => $currentContent->CreatedAt(), 'updatedAt' => $currentContent->UpdatedAt()];

// tagline の設定
$vars['tagline']['tags'] = $currentContent->Tags();

// content summary の設定
$vars['contentSummary'] = $currentContent->Summary();

// tagList と 最新のコンテンツ 設定
if ($currentContent->IsRoot()){
    $vars['tagList'] = ContentsDatabase::$metadata['tag2path'];
    $out = ContentsDatabaseManager::GetSortedContentsByUpdatedTime(array_keys(ContentsDatabase::$metadata['latest']));
    
    ContentsDatabase::LoadMetadata($metaFileName);
    foreach($out['notFounds'] as $path){
        ContentsDatabase::UnregistLatest($path);
        ContentsDatabase::UnregistTag($path);
    }
    ContentsDatabase::SaveMetadata($metaFileName);

    $vars['latestContents'] = $out['sorted'];
}

// content body の設定
$vars['contentBody'] = $currentContent->Body();

// child list の設定
$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]

foreach ($children as $child) {
    $vars['childList'][] = [
        'title' => $child->Title(), 
        'summary' => GetDecodedText($child)['summary'], 
        'url' => CreateContentHREF($child->Path())
    ];
}

// plainText リンクの追加
$vars['addPlainTextLink'] = true;

// edit リンクの追加
$vars['addEditLink'] = true;
$vars['openNewTabEditLink'] = $enableRemoteEdit;

// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

// 警告表示設定

// $vars['warningMessages'][] = "Hello world";
$vars['warningMessages'] = array_merge($vars['warningMessages'], GetMessages($currentContent->Path()));

if ($vars['pageBuildReport']['times']['build']['ms'] > 1000) {
    Debug::LogWarning("
    Performance Note:
        Page Title: {$currentContent->Title()}
        Page Path: {$currentContent->Path()}
        --- Build Report ---
" . print_r($vars['pageBuildReport'], true) . "
        --------------------"
    );

    $vars['warningMessages'][] = "申し訳ございません m(. _ . )m<br> ページの生成に時間がかかったようです.<br>品質向上のためこの問題は管理者に報告されます.";
}


require(FRONTEND_DIR . '/viewer.php');

function CreateNavHelper($parents, $parentsIndex, $currentContent, $children, &$navigator)
{
    if ($parentsIndex < 0) {
        // echo '1+';
        $navigator .= '<li><a class = "selected" href="' . CreateContentHREF($currentContent->Path()) . '">' . $currentContent->Title() . '</a></li>';

        $navigator .= "<ul>";
        foreach ($children as $c) {

            $navigator .= '<li><a href="' . CreateContentHREF($c->Path()) . '">' . $c->Title() . '</a></li>';
        }

        $navigator .= "</ul>";

        return;
    }

    $childrenCount = $parents[$parentsIndex]->ChildCount();

    $navigator .= '<li><a class = "selected" href="' . CreateContentHREF($parents[$parentsIndex]->Path()) . '">' . $parents[$parentsIndex]->Title() . '</a></li>';

    $navigator .= "<ul>";
    if ($parentsIndex == 0) {
        // echo '2+';
        $currentContentIndex = $currentContent->ChildIndex();
        for ($i = 0; $i < $childrenCount; $i++) {

            $child = $parents[$parentsIndex]->Child($i);
            if ($child === false) {
                continue;
            }

            if ($i == $currentContentIndex) {
                $navigator .= '<li><a class = "selected" href="' . CreateContentHREF($child->Path()) . '">' . $child->Title() . '</a></li>';

                $navigator .= "<ul>";
                foreach ($children as $c) {
                    $navigator .= '<li><a href="' . CreateContentHREF($c->Path()) . '">' . $c->Title() . '</a></li>';
                }
                $navigator .= "</ul>";
            } else {
                $navigator .= '<li><a href="' . CreateContentHREF($child->Path()) . '">' . $child->Title() . '</a></li>';
            }
        }
    } else {
        // echo '3+';
        $nextParentIndex = $parents[$parentsIndex - 1]->ChildIndex();
        for ($i = 0; $i < $childrenCount; $i++) {
            if ($i == $nextParentIndex) {
                CreateNavHelper($parents, $parentsIndex - 1, $currentContent, $children, $navigator);
            } else {
                $child = $parents[$parentsIndex]->Child($i);
                if ($child === false) {
                    continue;
                }
                $navigator .= '<li><a href="' . CreateContentHREF($child->Path()) . '">' . $child->Title() . '</a></li>';
            }
        }
    }
    $navigator .= "</ul>";
    return;
}
