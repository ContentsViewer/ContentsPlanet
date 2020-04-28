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

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);


$parent = $currentContent->Parent();

$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);

SearchEngine\Searcher::LoadIndex($indexFilePath);

// === 関連コンテンツの検索 =================================================

// $suggestions = [];

// "<title> <parent.title>" で検索
// ただし, parent は rootではない
$titleQuery = NotBlankText([$currentContent->title, basename($currentContent->path)]) . 
    (($parent === false || $parent->IsRoot()) ? '' : ' ' . NotBlankText([$parent->title, basename($parent->path)]) );

$titleSuggestions = SearchEngine\Searcher::Search($titleQuery);

// score 0.5 未満 は除外 
foreach($titleSuggestions as $i => $suggestion){
    if($suggestion['score'] < 0.5){
        unset($titleSuggestions[$i]);
    }
}
// Debug::Log($workSuggestions);
// $suggestions = array_merge($suggestions, $workSuggestions);

// "<tag1> <tag2> <tag3> ..." で検索
$tagQuery = '';
foreach($currentContent->tags as $tag){
    if(!in_array($tag, array('noindex', 'noindex-latest', '編集中', 'editing'))){
        $tagQuery .= $tag . ' ';
    }
}
$tagSuggestions = SearchEngine\Searcher::Search($tagQuery);

// 全タグをAND検索したとき, score 0.3 未満のものは除外
foreach($tagSuggestions as $i => $suggestion){
    if($suggestion['score'] < 0.3){
        unset($tagSuggestions[$i]);
    }
}
// $suggestions = array_merge($suggestions, $workSuggestions);

// // 重複を除外
// $uniqueKeys = [];
// foreach($suggestions as $i => $suggestion){
//     if(array_key_exists($suggestion['id'], $uniqueKeys)){
//         unset($suggestions[$i]);
//         continue;
//     }
//     $uniqueKeys[$suggestion['id']] = true;    
// }

$titleSuggestions = SelectDifferentDirectoryContents($titleSuggestions, $currentContent->path);
$tagSuggestions = SelectDifferentDirectoryContents($tagSuggestions, $currentContent->path);

// End 関連コンテンツの検索 =================================================

// === ページ内容設定 =======================================================

$vars['pageTitle'] = '関連: ' . NotBlankText([$currentContent->title, basename($currentContent->path)]);

$vars['pageHeading']['parents'] = [];
$vars['pageHeading']['title'] = $vars['pageTitle'];

$vars['navigator'] = '';
if(($navigator = GetNavigator($currentContent->path)) !== false){
    $vars['navigator'] = $navigator;
}
else{
    $vars['navigator'] = '<nav class="navi"><ul><li>一時的に利用できません</li></ul></nav>';
}

// page-tabの追加
$vars['pageTabs'] = [
    ['selected' => false, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path) . '">コンテンツ</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path) . '.note">ノート</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CreateDirectoryHREF(dirname($contentPath)) .'">ディレクトリ</a>'],
    ['selected' => true, 'innerHTML' => '<a href="' . CreateContentHREF($currentContent->path) .'?related">関連</a>']];


$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]

$body = '';

if(count($titleSuggestions) > 0){
    $body .= '<h3>タイトル「' . trim($titleQuery) . '」に関連する</h3>';
    $body .= CreateSuggestedContentList($titleSuggestions);
}

if(count($tagSuggestions) > 0){
    $body .= '<h3>タグ「' . trim($tagQuery) . '」に関連する</h3>';
    $body .= CreateSuggestedContentList($tagSuggestions);
}


if(count($titleSuggestions) + count($tagSuggestions) > 0){
    $vars['contentSummary'] = '<p>「コンテンツ: <a href="' . CreateContentHREF($currentContent->path) . '">' .  
    NotBlankText([$currentContent->title, basename($currentContent->path)]) .
    '</a>」と関連するコンテンツが, 別階層で<em>' . (count($titleSuggestions) + count($tagSuggestions)) .'件</em>見つかりました.</p>';
}
else{
    $vars['contentSummary'] = '<p>「コンテンツ: <a href="' . CreateContentHREF($currentContent->path) . '">' .  
    NotBlankText([$currentContent->title, basename($currentContent->path)]) .
    '</a>」と関連するコンテンツが, 別階層で見つかりませんでした.</p>';
}

$vars['contentBody'] = $body;

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

function SelectDifferentDirectoryContents($suggestions, $currentContentPath){
    foreach($suggestions as $i => $suggestion){
        $steps = CountSteps($suggestion['id'], $currentContentPath);
        if($steps !== false && $steps < 4){
            unset($suggestions[$i]);
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