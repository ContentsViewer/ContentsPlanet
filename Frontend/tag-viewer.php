<?php

require_once(MODULE_DIR . "/ContentsDatabaseManager.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Stopwatch.php");
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/SearchEngine.php');


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$layerSuffix = ContentsDatabaseManager::GetLayerSuffix($vars['layerName']);

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . $layerSuffix;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

ContentsDatabaseManager::LoadRelatedMetadata($vars['rootContentPath']);
$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($vars['rootContentPath']);

$tag2path = array_key_exists('tag2path', ContentsDatabase::$metadata) ? ContentsDatabase::$metadata['tag2path'] : [];
$path2tag = array_key_exists('path2tag', ContentsDatabase::$metadata) ? ContentsDatabase::$metadata['path2tag'] : [];
ksort($tag2path);

// .tagmap.index 
$indexFileName = CONTENTS_HOME_DIR . $vars['rootDirectory'] . '/.index.tagmap' . $layerSuffix;
if(
    !SearchEngine\Indexer::LoadIndex($indexFileName) || 
    !array_key_exists('contentsChangedTime', ContentsDatabase::$metadata) ||
    (filemtime($indexFileName) < ContentsDatabase::$metadata['contentsChangedTime'])){
    // tagmap index の更新

    SearchEngine\Indexer::$index = []; // indexの初期化
    foreach($tag2path as $tag => $_){
        SearchEngine\Indexer::RegistIndex($tag, $tag);
    }
    // Debug::Log("update");
    SearchEngine\Indexer::ApplyIndex($indexFileName);
}


// パスの仕様
// TagMap/TagA/TagB,TagC/TagD
//   TagA -> (TagB, TagC) -> TagD


$tagPath = substr($vars['subURI'], strpos($vars['subURI'], '/TagMap') + 7);
// '/TagMap/A' -> /A -> ['', A]
// '/TagMap' -> '' -> ['']
$tagPathParts = array_slice(explode('/', $tagPath), 1);
foreach($tagPathParts as $i => $part){
    $part = explode(',', $part);
    foreach($part as $j => $tag){
        $part[$j] = trim($tag);
    }
    $tagPathParts[$i] = $part;
}


$relocatedURL = CreateTagMapHREF([[]], $vars['rootDirectory']);
$notFound = false;
foreach($tagPathParts as $part){
    foreach($part as $j => $tag){
        if(!array_key_exists($tag, $tag2path)){
            // タグが存在しないとき
            $notFound = true;
            unset($part[$j]);
            continue;
        }
    }
    $partString = implode(',', $part);
    $relocatedURL .= ($partString=='' ? '' : '/' . $partString);
    if($notFound){
        header('Location: ' . $relocatedURL);
        exit();
    }
}

// ここまでで, 各タグ名はtag2path内にあることが保証されている

$vars['pageTitle'] = '';
$vars['pageHeading']['title'] = '';
$vars['pageHeading']['parents'] = [];
$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]
$vars['contentSummary'] = '';
$vars['contentBody'] = '';
$vars['navigator'] = '';

// タイトルの設定
if(count($tagPathParts) <= 0){
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap');
    $vars['pageHeading']['title'] = $vars['pageTitle'];
}
else{
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap') . ': ';
    $i = count($tagPathParts) - 1;
    for($c = 0; $i >= 0 && $c < 2; $i--, $c++){
        $vars['pageTitle'] .= implode(', ', $tagPathParts[$i]) . ' | ';
    }
    $vars['pageTitle'] = substr($vars['pageTitle'], 0, -3);
    
    $vars['pageHeading']['title'] = '' . implode(', ', end($tagPathParts));
    
    $workTagPathParts = $tagPathParts;
    $i = count($tagPathParts) - 2;
    for(; $i >= 0; $i--){
        unset($workTagPathParts[$i + 1]);
        $vars['pageHeading']['parents'][] = [
            'title' => implode(', ', $tagPathParts[$i]), 
            'path' => CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'])];
    }
    $vars['pageHeading']['parents'][] = [
        'title' => Localization\Localize('tagmap', 'TagMap'), 
        'path' => CreateTagMapHREF([[]], $vars['rootDirectory'])];
    // Debug::Log($tagPathParts);
    // Debug::Log($workTagPathParts);
}

// タグが指定されていないとき
if(count($tagPathParts) <= 0){
    // タグマップを表示して, 終了する.
    $vars['contentSummary'] = '<div style="margin-top: 1em; margin-botton: 1em;">' .
        CreateTagListElement($tag2path,  $vars['rootDirectory']) .
        '</div>';
    $vars['navigator'] = CreateNavi([], $tag2path, $path2tag, $vars['rootDirectory']);
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

// ここから先は, 何らかのタグが指定されている
//  * $tagPathParts の要素数は 0 より大きい

/**
 * [
 *  [
 *      'selectors' => ['tagA', 'tagB', ...], 
 *      'selected' => ['pathA' => any, 'pathB' => any, ...]
 *  ], ...
 * ]
 */
$eachSelectedTaggedPaths = []; 
$source = $path2tag;
foreach($tagPathParts as $part){
    $selected = SelectTaggedPaths($source, $part, $tag2path, $path2tag);
    $eachSelectedTaggedPaths[] = ['selectors' => $part, 'selected' => $selected];
    $source = $selected;
}

// --- ヒットしたコンテンツの設定
$hitContents = [];
if(count(end($eachSelectedTaggedPaths)['selected']) > 0){
    $out = ContentsDatabaseManager::GetSortedContentsByUpdatedTime(array_keys(end($eachSelectedTaggedPaths)['selected']));

    ContentsDatabase::LoadMetadata($metaFileName);
    foreach($out['notFounds'] as $path){
        ContentsDatabase::UnregistLatest($path);
        ContentsDatabase::UnregistTag($path);
    }
    ContentsDatabase::SaveMetadata($metaFileName);

    $hitContents = $out['sorted'];
}

// --- 子タグの設定
$childTags = GetUnionTags(end($eachSelectedTaggedPaths)['selected'], $path2tag);

foreach($tagPathParts as $part){
    foreach($part as $tag){
        unset($childTags[$tag]);
    }
}

foreach($childTags as $tag => $_){
    $childTags[$tag] = SelectTaggedPaths(end($eachSelectedTaggedPaths)['selected'], [$tag], $tag2path, $path2tag);
}

// --- 同階層のタググループへの追加と削除
$selectedTags = [];
foreach($tagPathParts as $part){
    foreach($part as $tag){
        $selectedTags[$tag] = true;
    }
}
$source = $path2tag;
if(count($eachSelectedTaggedPaths) > 1){
    $source = $eachSelectedTaggedPaths[count($eachSelectedTaggedPaths) - 2]['selected'];
}

$includedTags = array_diff_key($tag2path, $selectedTags);
foreach($includedTags as $tag => $_){
    $paths =  SelectTaggedPaths(
        $source, 
        [$tag], 
        $tag2path, $path2tag
    );
    if(count($paths) > 0){
        $includedTags[$tag] = $paths;
    }
    else{
        unset($includedTags[$tag]);
    }
}

$excludedTags = [];
foreach(end($tagPathParts) as $tag){
    $excludedTags[$tag] = true;
}
foreach($excludedTags as $tag => $_){
    $excludedTags[$tag] = SelectTaggedPaths(
        $source,
        [$tag],
        $tag2path, $path2tag
    );
}

// --- 類似しているタグ候補の提示
SearchEngine\Searcher::LoadIndex($indexFileName);
$suggestion = [];
foreach(end($tagPathParts) as $tag){
    $suggestion = array_merge($suggestion, SearchEngine\Searcher::Search($tag));
}
foreach($suggestion as $i => $suggested){
    if($suggested['score'] < 0.5 || array_key_exists($suggested['id'], $selectedTags)){
        unset($suggestion[$i]);
    }
}
uasort($suggestion, function($a, $b) {
    if ($a['score'] == $b['score']) {
        return 0;
    }
    return ($a['score'] < $b['score']) ? 1 : -1;
});

$suggestedTags = [];
foreach($suggestion as $suggested){
    $paths = SelectTaggedPaths(
        $source, 
        [$suggested['id']], 
        $tag2path, $path2tag
    );
    if(count($paths) > 0){
        $suggestedTags[$suggested['id']] = $paths;
    }
}

// Debug::Log($suggestedTags);

// --- summary の設定 
$breadcrumb = '';
foreach($tagPathParts as $part){
    $breadcrumb .= '<em>' . implode(', ', $part) . '</em> / ';
}
$breadcrumb = substr($breadcrumb, 0, -3);

$summary = '<p>';
if(count($hitContents) > 0){
    $summary .= Localization\Localize('tag-viewer.foundNContents', 
    'Found <em>{1} Contents</em> in "{0}".', $breadcrumb, count($hitContents));
}
else{
    $summary .= Localization\Localize('tag-viewer.notFoundContents', 
    'Not Found any Contents in "{1}".', $breadcrumb);
}
$summary .= '</p>';
$vars['contentSummary'] = $summary;


// --- body の設定
$body = '';

$body .= '<div style="margin-top: 1em; margin-bottom: 1em; border: 1px solid #dadce0; border-radius: 6px; padding: 12px 16px;">';
if(count($vars['pageHeading']['parents']) >= 1){
    $body .= '<div style="margin-bottom: 0.5em;">';
    $parents = array_reverse(array_slice($vars['pageHeading']['parents'], 0, -1));
    foreach($parents as $parent){
        $body .= '<a href="' . $parent['path'] . '">' . $parent['title'] . '</a>';
        $body .= ' &gt; ';
    }
    $body .= '</div>';
}
$body .= '<ul class="tag-list removable">';
$tags = $tagPathParts[count($tagPathParts) - 1];
foreach($tags as $i => $tag){
    $workTagPathParts = $tagPathParts;
    $workTags = $tags;
    array_splice($workTags, $i, 1);
    $workTagPathParts[count($workTagPathParts) - 1] = $workTags;
    $body .=  '<li><a href="' . 
        CreateTagMapHREF($workTagPathParts, $vars['rootDirectory']) .
        '">' . $tag . '<span>' . count($excludedTags[$tag]) . '</span></a></li>';
}
$body .= '</ul>';

$body .= '<div style="text-align:center;">+</div>';
if(count($suggestedTags) > 0){
    $body .= '<div>' . Localization\Localize('didYouMean', 'Did you mean: ');
    $body .= '<ul class="tag-list">';
    foreach ($suggestedTags as $tag => $pathList) {
        $workTagPathParts = $tagPathParts;
        $workTagPathParts[count($workTagPathParts) - 1][] = $tag;
        // Debug::Log($workTagPathParts);
        $body .=  '<li><a href="' . 
            CreateTagMapHREF($workTagPathParts, $vars['rootDirectory']) .
            '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
    }
    $body .=  '</ul>';
    $body .= '</div>';
}

$body .= '<details><summary>' . Localization\Localize('others', 'Others') . '</summary>';
$body .= '<ul class="tag-list">';
foreach ($includedTags as $tag => $pathList) {
    $workTagPathParts = $tagPathParts;
    $workTagPathParts[count($workTagPathParts) - 1][] = $tag;
    // Debug::Log($workTagPathParts);
    $body .=  '<li><a href="' . 
        CreateTagMapHREF($workTagPathParts, $vars['rootDirectory']) .
        '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
}
$body .=  '</ul>';
$body .= '</details>';

$body .= '</div>';

if(count($childTags) > 0){
    $body .= '<div><h3>&gt; ' . Localization\Localize('tag-viewer.narrowDown', 'Narrow Down') . '</h3><div style="margin-left: 16px;">';
    $body .= CreateTagListElement($childTags, $vars['rootDirectory'], $tagPathParts);
    $body .= '</div></div>';
}

$vars['contentSummary'] .= $body;


// --- child list の設定
foreach($hitContents as $content){
    $parent = $content->Parent();
    $vars['childList'][] = [
        'title' => NotBlankText([$content->title, basename($content->path)]) . 
            ($parent === false ? '' : ' | ' . NotBlankText([$parent->title, basename($parent->path)])), 
        'summary' => GetDecodedText($content)['summary'], 
        'url' => CreateContentHREF($content->path)
    ];
}


// navigator 設定
$vars['navigator'] = CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $vars['rootDirectory']);;


// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

require(FRONTEND_DIR . '/viewer.php');

/**
 * ['pathA' => any, 'pathB' => any, ...]
 * 
 * @param array $source 
 *  ['pathA' => any, 'pathB' => any, ...]
 */
function SelectTaggedPaths($source, $selectorTags, $tag2path, $path2tag){
    $selectedPaths = [];
    foreach($selectorTags as $tag){
        $selectedPaths = array_merge($selectedPaths, $tag2path[$tag]);
    }
    return array_intersect_key($source, $selectedPaths);
}

/**
 * ['tagA' => any, 'tagB' => any, ...]
 * 
 * @param array $paths
 *  ['pathA' => any, 'pathB' => any, ...]
 */
function GetUnionTags($paths, $path2tag){
    $union = [];
    foreach($paths as $path => $_){
        $union = array_merge($union, $path2tag[$path]);
    }
    return $union;
}
/**
 * @param array $eachSelectedTaggedPaths
 *  [
 *      [
 *          'selectors' => ['tagA', 'tagB', ...], 
 *          'selected' => ['pathA' => any, 'pathB' => any, ...]
 *      ], ...
 *  ]
 */
function CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $rootDirectory){
    $navi = '<nav class="navi"><ul>';

    $tagStack = array_reverse(array_keys($tag2path));
    $currentTaggedPathsIndex = -1;
    $countTaggedPaths = count($eachSelectedTaggedPaths);
    if($countTaggedPaths > 0){
        $currentTaggedPathsIndex = 0;
    }
    $currentPathParts = [];
    $alreadyCrawlIntoChildren = false;
    $alreadyCrawlIntoChildrenStack = [];

    while(!is_null($poppedTag = array_pop($tagStack))){
        if($poppedTag === true){
            $navi .= '</ul>';
            $currentTaggedPathsIndex--;
            array_pop($currentPathParts);
            $alreadyCrawlIntoChildren = array_pop($alreadyCrawlIntoChildrenStack);
            continue;
        }

        if((0 <= $currentTaggedPathsIndex) && ($currentTaggedPathsIndex < $countTaggedPaths)){
            $currentTaggedPaths = $eachSelectedTaggedPaths[$currentTaggedPathsIndex];
            if(in_array($poppedTag, $currentTaggedPaths['selectors'], true)){
                if(!$alreadyCrawlIntoChildren){
                    // 子タグのループを終えたときに, すでにループ済みであることを示すフラグ
                    $alreadyCrawlIntoChildrenStack[] = true;
                    $currentPathParts[] = $currentTaggedPaths['selectors'];
                    $navi .= '<li><a href="' . 
                        CreateTagMapHREF($currentPathParts, $rootDirectory) .
                        '" class="selected">' . implode(', ', $currentTaggedPaths['selectors']) .
                        '</a></li><ul>';
                    $unionTags = GetUnionTags($currentTaggedPaths['selected'], $path2tag);
                    for($i = 0; $i <= $currentTaggedPathsIndex; $i++){
                        foreach($eachSelectedTaggedPaths[$i]['selectors'] as $tag){
                            unset($unionTags[$tag]);
                        }
                    }
                    $tagStack[] = true; // 子タグが終わったときの目印
                    $tagStack = array_merge($tagStack, array_reverse(array_keys($unionTags)));
                    $currentTaggedPathsIndex++;
                    $alreadyCrawlIntoChildren = false; // 子タグのループ内では, まだその子タグループはしていない
                    continue;
                }
                else{
                    // skip
                    continue;
                }
            }
        }
        $navi .= '<li><a href="' . 
            CreateTagMapHREF(array_merge($currentPathParts, [[$poppedTag]]), $rootDirectory) .
            '">' . $poppedTag .
            '</a></li>';
    }

    $navi .= '</ul></nav>';
    return $navi;
}
