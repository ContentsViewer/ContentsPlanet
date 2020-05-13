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


// ContentsDatabaseManager::LoadRelatedMetadata($vars['rootContentPath']);
// $tag2path = array_key_exists('tag2path', ContentsDatabase::$metadata) ? ContentsDatabase::$metadata['tag2path'] : [];
// $path2tag = array_key_exists('path2tag', ContentsDatabase::$metadata) ? ContentsDatabase::$metadata['path2tag'] : [];
// ksort($tag2path);


// layerの再設定
$out = UpdateLayerNameAndResetLocalization($vars['contentPath'], $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];


$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);
SearchEngine\Index::Load($indexFilePath);


$parent = $currentContent->Parent();
$childPathList = [];
if($parent !== false){
    $childCount = $parent->ChildCount();
    for($i = 0; $i < $childCount; $i++){
        if(($child = $parent->Child($i)) !== false){
            $childPathList[] = $child->path;
        }
    }
}

// === 関連コンテンツの検索 =================================================
$titleSuggestions = [];

/**
 * [
 *  ['tag' => '', 'suggestions' => []], 
 *  ...
 * ]
 */
$tagSuggestions = [];

$countSuggestions = 0;

// "<title> <parent.title> で検索
// ただし, parent は rootではない
$titleQuery = NotBlankText(
    [$currentContent->title, ContentsDatabaseManager::GetContentPathInfo($currentContent->path)['filename']]
);
if($parent !== false){
    $parentPathInfo = ContentsDatabaseManager::GetContentPathInfo($parent->path);
    if($parentPathInfo['filename'] != ROOT_FILE_NAME){
        $titleQuery .= ' ' . NotBlankText([$parent->title, $parentPathInfo['filename']]);
    }
}
$titleSuggestions = SelectSuggestions(
    SearchEngine\Searcher::Search($titleQuery), $currentContent->path, $childPathList, 0.5
);
$countSuggestions += count($titleSuggestions);

// <tag1> <tag2> <tag3> ..."で検索
foreach($currentContent->tags as $tag){
    if(!in_array($tag, array('noindex', 'noindex-latest'))){
        $suggestions = SelectSuggestions(
            SearchEngine\Searcher::Search($tag), $currentContent->path, $childPathList
        );
        $countSuggestions += count($suggestions);
        $tagSuggestions[] = ['tag' => $tag, 'suggestions' => $suggestions];
    }
}


/*
$terms = explode(' ', $query);
$termCount = count($terms);
for($i = $termCount - 1; $i >= 0; $i--){
    $terms[$i] = trim($terms[$i]);
    if(empty($terms[$i])){
        array_splice($terms, $i, 1);
    }
}
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
*/

// End 関連コンテンツの検索 =================================================

// === ページ内容設定 =======================================================

$vars['pageTitle'] = Localization\Localize('related', 'Related') . ': ' . NotBlankText([$currentContent->title, basename($currentContent->path)]);
$vars['pageHeading']['parents'] = [];
$vars['pageHeading']['title'] = $vars['pageTitle'];
$vars['childList'] = [];
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

$summary = '';
if($countSuggestions > 0){
    $summary = '<p>' . 
        Localization\Localize(
            'related-viewer.foundRelatedContents', 
            'Found <em>{0} Related Contents</em> in another direcotry.', $countSuggestions
        ) . '</p>';
}
else{
    $summary = '<p>' .
        Localization\Localize(
            'related-viewer.notFoundRelatedContents', 
            'Not Found Related Contents in another directory.'
        ) . '</p>';
}
$vars['contentSummary'] = $summary;

$body = '';
if(count($titleSuggestions) > 0){
    $body .= '<h2>"' . $titleQuery . '"</h2><div class="section">';
    $body .= CreateSuggestedContentList($titleSuggestions);
    $body .= '</div>';
}

foreach($tagSuggestions as $each){
    if(count($each['suggestions'])){
        $body .= '<h2>"' . $each['tag'] . '"</h2><div class="section">';
        $body .= '<ul class="tagline" style="text-align: right;"><li><a href="' . 
            CreateTagMapHREF([[$each['tag']]], $vars['rootDirectory'], $vars['layerName']) .
            '">' . $each['tag'] . '</a></li></ul>';
        $body .= CreateSuggestedContentList($each['suggestions']);
        $body .= '</div>';
    }
}

// if(count($suggestions) > 0){
//     // $body .= '<h3>「' . trim($query) . '」に関連する</h3>';
//     $body .= CreateSuggestedContentList($suggestions);
// }

$vars['contentBody'] = $body;

$vars['htmlLang'] = $vars['layerName'];
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
    foreach($suggestions as $i => $suggested){
        if(in_array($suggested['id'], $childPathList)){
            unset($suggestions[$i]);
            continue;
        }

        $steps = CountSteps($suggested['id'], $currentContentPath);
        if($steps !== false && $steps < 4){
            unset($suggestions[$i]);
            continue;
        }
    }
    return $suggestions;
}

function SelectSuggestions($suggestions, $currentContentPath, $childPathList, $scoreThres = 0.8){
    foreach($suggestions as $i => $suggested){
        if($suggested['score'] < $scoreThres){
            unset($suggestions[$i]);
        }
    }
    $suggestions = SelectDifferentDirectoryContents($suggestions, $currentContentPath, $childPathList);
    $suggestions = array_slice($suggestions, 0, 30);
    return $suggestions;
}

function CreateSuggestedContentList($suggestions){
    $html = '<ul class="child-list">';

    $content = new Content();
    foreach ($suggestions as $suggested) {
        if($content->SetContent($suggested['id'])){
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