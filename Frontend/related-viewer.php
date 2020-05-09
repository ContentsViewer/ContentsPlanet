<?php

require_once(MODULE_DIR . '/ContentsDatabaseManager.php');


$contentPath = $vars['contentPath'];

// コンテンツの取得
// 存在しないコンテンツ確認
$currentContent = new Content();
if(!$currentContent->SetContent($contentPath)){
    require(FRONTEND_DIR . '/404.php');
    exit();
}


require_once(MODULE_DIR . '/SearchEngine.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/Stopwatch.php');

$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['rootContentPath'] = ContentsDatabaseManager::GetRelatedRootFile($vars['contentPath']);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);


$parent = $currentContent->Parent();

$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);

SearchEngine\Searcher::LoadIndex($indexFilePath);

// === 関連コンテンツの検索 =================================================

// $suggestions = [];

// "<title> <parent.title> <tag1> <tag2> <tag3> ..."で検索
// ただし, parent は rootではない
$query = NotBlankText([$currentContent->title, ContentsDatabaseManager::GetContentPathInfo($currentContent->path)['filename']]);
if($parent !== false){
    $parentPathInfo = ContentsDatabaseManager::GetContentPathInfo($parent->path);
    if($parentPathInfo['filename'] != ROOT_FILE_NAME){
        $query .= ' ' . NotBlankText([$parent->title, $parentPathInfo['filename']]);
    }
} 

foreach($currentContent->tags as $tag){
    if(!in_array($tag, array('noindex', 'noindex-latest', Localization\Localize('editing', 'editing')))){
        $query .= ' ' . $tag;
    }
}

$suggestions = SearchEngine\Searcher::Search($query);

$terms = explode(' ', $query);
$termCount = count($terms);
for($i = $termCount - 1; $i >= 0; $i--){
    $terms[$i] = trim($terms[$i]);
    if(empty($terms[$i])){
        array_splice($terms, $i, 1);
    }
}
// Debug::Log(count($terms));
// Debug::Log(0.7 / count($terms));
// Debug::Log(count($terms));
// Debug::Log(0.5 / (1+log10(count($terms))));
// Debug::Log($suggestions);

// フィルタ例:
//   0.3以上のもの:
//     問題点:
//       termが増えるごとに, 限定されていき, ヒットしずらくなる
//
//   各termごと, 平均して7割以上類似するもの:
//     0.7 / count($terms)
//     問題点:
//       termが増えるごとに, scoreが0に近づき, ヒットしやすくなる
//
//    考えは, 上のままでtermsの数が増えるごとにスコアの下降を抑える
//      0.5 / (1+log(count($terms)))
//
foreach($suggestions as $i => $suggestion){
    if($suggestion['score'] < (0.5 / (1+log(count($terms))))){
        unset($suggestions[$i]);
    }
}

$childPathList = [];
if($parent !== false){
    $childCount = $parent->ChildCount();
    for($i = 0; $i < $childCount; $i++){
        if(($child = $parent->Child($i)) !== false){
            $childPathList[] = $child->path;
        }
    }
}
// Debug::Log($childPathList);
// $titleSuggestions = SelectDifferentDirectoryContents($titleSuggestions, $currentContent->path, $childPathList);
$suggestions = SelectDifferentDirectoryContents($suggestions, $currentContent->path, $childPathList);
$suggestions = array_slice($suggestions, 0, 30); // 最大30件

// End 関連コンテンツの検索 =================================================

// === ページ内容設定 =======================================================

$vars['pageTitle'] = Localization\Localize('related', 'Related') . ': ' . NotBlankText([$currentContent->title, basename($currentContent->path)]);

$vars['pageHeading']['parents'] = [];
$vars['pageHeading']['title'] = $vars['pageTitle'];

$vars['navigator'] = '';
if(($navigator = GetNavigator($currentContent->path)) !== false){
    $vars['navigator'] = $navigator;
}
else{
    $vars['navigator'] = '<nav class="navi"><ul><li>' . Localization\Localize('temporarilyUnavailable', 'Temporarily Unavailable') . '</li></ul></nav>';
}

// page-tabの追加
$vars['pageTabs'] = [
    ['selected' => false, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path) . '">' . Localization\Localize('content', 'Content') . '</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path . '.note') . '">' . Localization\Localize('note', 'Note') . '</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CreateDirectoryHREF(dirname($contentPath), $vars['language']) .'">' . Localization\Localize('directory', 'Directory') . '</a>'],
    ['selected' => true, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path) .'?related">' . Localization\Localize('related', 'Related') . '</a>']
];


$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]

$body = '';

if(count($suggestions) > 0){
    // $body .= '<h3>「' . trim($query) . '」に関連する</h3>';
    $body .= CreateSuggestedContentList($suggestions);
}


if(count($suggestions) > 0){
    $vars['contentSummary'] = '<p>' . 
        Localization\Localize('related-viewer.foundRelatedContents', 
        'Found <em>{1} Contents</em> related with <em>"{0}"</em> in another direcotry.', trim($query), count($suggestions)) .
        '</p>';
}
else{
    $vars['contentSummary'] = '<p>' .
        Localization\Localize('related-viewer.notFoundRelatedContents', 
        'Not Found Contents related with <em>"{0}"</em> in another directory.', trim($query)) .
        '</p>';
}

$vars['contentBody'] = $body;

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . 
    $_SERVER["HTTP_HOST"] . CreateContentHREF($vars['contentPath']) . '?related';

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

function CountSteps($pathFrom, $pathTo){
    // Debug::Log($pathFrom);
    // Debug::Log($pathTo);

    $steps = 0;
    $current = $pathFrom;
    //
    // ex) 
    //  例えば, 以下のセットが判別できるか確認すること
    //  ./Master/Contents/Writing/Writing
    //  ./Master/Contents/Writing/WritingMethod/WritingMethod
    //
    //  ./Master/Contents/Writing/Writing
    //  ./Master/Contents/Writing/Writing
    //
    //  ./Master/Contents/Writing/Writing
    //  ./Master/Contents/Writing/Writing/Method/WritingMethod
    while(strpos($pathTo, $current . '/') !== 0 && $pathTo != $current){
        $current = dirname($current);
        $steps++;
        
        if($current == '.'){
            // 共通したディレクトリにいない
            return false;
        }
    }
    $root = $current;
    $current = $pathTo;
    while($root != $current){
        $current = dirname($current);
        $steps++;
    }

    // Debug::Log($steps);
    return $steps;
}

function SelectDifferentDirectoryContents($suggestions, $currentContentPath, $childPathList){
    foreach($suggestions as $i => $suggestion){
        if(in_array($suggestion['id'], $childPathList)){
            unset($suggestions[$i]);
            continue;
        }

        $steps = CountSteps($suggestion['id'], $currentContentPath);
        if($steps !== false && $steps < 4){
            unset($suggestions[$i]);
            continue;
        }
    }
    return $suggestions;
}

function CreateSuggestedContentList($suggestions){
    $html = '<ul class="child-list">';

    $content = new Content();
    foreach ($suggestions as $suggestion) {
        if($content->SetContent($suggestion['id'])){
            $parent = $content->Parent();
            $html .= '<li><div><div class="child-title">' .
                '<a href="'. CreateContentHREF($content->path) . '">' . 
                NotBlankText([$content->title, basename($content->path)]) . 
                ($parent === false ? '' : ' | ' . NotBlankText([$parent->title, basename($parent->path)])) . '</a>' .
                '</div><div class="child-summary">' . GetDecodedText($content)['summary'] . '</div></div></li>';
        }
    }
    $html .= '</ul>';
    return $html;
}