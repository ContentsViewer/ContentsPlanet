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

if(isset($_GET['layer'])){
    $vars['layerName'] = $_GET['layer'];
}

$layerSuffix = ContentsDatabaseManager::GetLayerSuffix($vars['layerName']);
$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . $layerSuffix;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);
$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($vars['rootContentPath']);

if(
    ContentPathUtils::RealPath($vars['rootContentPath'] . Content::EXTENTION) === false && 
    ContentPathUtils::RealPath($metaFileName) === false
) {
    // Rootコンテンツ, メタファイルがないとき
    // 存在しないlayer名を見ている

    $vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}


ContentsDatabaseManager::LoadRelatedMetadata($vars['rootContentPath']);
$tag2path = ContentsDatabase::$metadata['tag2path'] ?? [];
$path2tag = ContentsDatabase::$metadata['path2tag'] ?? [];
ksort($tag2path);


// layerの再設定
$out = UpdateLayerNameAndResetLocalization($vars['rootContentPath'], $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];


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

$notFound = false;
foreach($tagPathParts as $i => $part){
    foreach($tagPathParts[$i] as $j => $tag){
        if(!array_key_exists($tag, $tag2path)) {
            // タグが存在しないとき, そのタグは消す
            $notFound = true;
            unset($tagPathParts[$i][$j]);
            continue;
        }
    }
    if(empty($tagPathParts[$i])) {
        unset($tagPathParts[$i]);
    }
}
if($notFound) {
    $relocatedURL = CreateTagMapHREF($tagPathParts, $vars['rootDirectory'], $vars['layerName']);
    header('Location: ' . $relocatedURL);
    exit();
}

// ここまでで, 各タグ名はtag2path内にあることが保証されている

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . 
    $_SERVER["HTTP_HOST"] . $vars['subURI'] . '?layer=' . $vars['layerName'];
$vars['htmlLang'] = $vars['layerName'];
$vars['pageTitle'] = '';
$vars['pageHeading']['title'] = '';
$vars['pageHeading']['parents'] = [];
$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]
$vars['contentSummary'] = '';
$vars['contentBody'] = '';
$vars['navigator'] = '';

// タイトルの設定
if(empty($tagPathParts)) {
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap');
    $vars['pageHeading']['title'] = $vars['pageTitle'];
}
else {
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
            'path' => CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName'])
        ];
    }
    $vars['pageHeading']['parents'][] = [
        'title' => Localization\Localize('tagmap', 'TagMap'), 
        'path' => CreateTagMapHREF([], $vars['rootDirectory'], $vars['layerName'])
    ];
}

// タグが指定されていないとき
if(empty($tagPathParts)) {
    // タグマップを表示して, 終了する.
    $vars['contentSummary'] = '<div style="margin-top: 1em; margin-botton: 1em;">' .
        CreateTagListElement($tag2path,  $vars['rootDirectory'], $vars['layerName']) .
        '</div>';
    $vars['navigator'] = CreateNavi([], $tag2path, $path2tag, $vars['rootDirectory'], $vars['layerName']);
    
    $majorTags = GetMajorTags($tag2path);
    $vars['contentBody'] = CreateTagCardsElement($majorTags, $vars['rootDirectory'], $vars['layerName']);

    // ビルド時間計測 終了
    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

// ここから先は, 何らかのタグが指定されている
//  * $tagPathParts の要素数は 0 より大きい

SearchEngine\Index::Load(ContentsDatabaseManager::GetRelatedIndexFileName($vars['rootContentPath']));

/**
 * [
 *  [
 *      'selectors' => ['tagA', 'tagB', ...], 
 *      'selected' => ['pathA' => any, 'pathB' => any, ...]
 *  ], ...
 * ]
 */
$eachSelectedTaggedPaths = []; 
$source = null;
foreach($tagPathParts as $part){
    $selected = array_merge(
        FindTagSuggestedPaths($source, $part),
        SelectTaggedPaths($source, $part, $tag2path, $path2tag)
    );
    $eachSelectedTaggedPaths[] = ['selectors' => $part, 'selected' => $selected];
    $source = $selected;
}

// --- 同階層のタググループへの追加と削除
$selectedTags = [];
foreach($tagPathParts as $part){
    foreach($part as $tag){
        $selectedTags[$tag] = true;
    }
}
$source = null;
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

// --- 類似しているタグ候補の提示 -------------------------------------------------

// .tagmap.index の更新
$indexFileName = CONTENTS_HOME_DIR . $vars['rootDirectory'] . '/.index.tagmap' . $layerSuffix;
if(
    !SearchEngine\Index::Load($indexFileName) || 
    !array_key_exists('contentsChangedTime', ContentsDatabase::$metadata) ||
    (filemtime($indexFileName) < ContentsDatabase::$metadata['contentsChangedTime'])
){
    // tagmap index の更新

    SearchEngine\Index::$data = []; // indexの初期化
    foreach($tag2path as $tag => $_){
        SearchEngine\Indexer::RegistIndex($tag, $tag);
    }
    // Debug::Log("update");
    SearchEngine\Index::Apply($indexFileName);
}

$suggestions = [];
foreach(end($tagPathParts) as $tag){
    $suggestions = array_merge($suggestions, SearchEngine\Searcher::Search($tag));
}
foreach($suggestions as $i => $suggested){
    if($suggested['score'] < 0.5 || array_key_exists($suggested['id'], $selectedTags)){
        unset($suggestions[$i]);
    }
}
SortSuggestions($suggestions);


$suggestedTags = [];
foreach($suggestions as $suggested){
    $paths = SelectTaggedPaths(
        $source, 
        [$suggested['id']], 
        $tag2path, $path2tag
    );
    if(count($paths) > 0){
        $suggestedTags[$suggested['id']] = $paths;
    }
}
// End 類似しているタグ候補の提示 ---

// --- ヒットしたコンテンツの設定 -------------------------------------------------
/**
 * [
 *  'path' => content, ...
 * ]
 */
$hitContents = [];
$suggestedContents = [];
if(!empty(end($eachSelectedTaggedPaths)['selected'])) {
    // まず, 分ける
    foreach(end($eachSelectedTaggedPaths)['selected'] as $path => $value){
        if(is_bool($value)){
            $hitContents[] = $path;
        }
        else{
            $suggestedContents[] = ['path' => $path, 'score' => $value];
        }
    }

    if(!empty($hitContents)) {
        $out = ContentsDatabaseManager::GetSortedContentsByUpdatedTime($hitContents);

        ContentsDatabase::LoadMetadata($metaFileName);
        foreach($out['notFounds'] as $path){
            ContentsDatabase::UnregistLatest($path);
            ContentsDatabase::UnregistTag($path);
        }
        ContentsDatabase::SaveMetadata($metaFileName);

        $hitContents = [];
        foreach($out['sorted'] as $content) {
            $hitContents[$content->path] = $content;
        }
    }

    if(!empty($suggestedContents)) {
        SortSuggestions($suggestedContents);
        $out = [];
        foreach($suggestedContents as $suggested){
            $content = new Content;
            if($content->SetContent($suggested['path'])){
                $out[] = $content;
            }
        }

        $suggestedContents = [];
        foreach($out as $content) {
            $suggestedContents[$content->path] = $content;
        }
    }
}
$hitTagGroup = CreateTagGroup($hitContents, $path2tag, $selectedTags);
$suggestedTagGroup = CreateTagGroup($suggestedContents, $path2tag, $selectedTags);

// End ヒットしたコンテンツの設定 ---

// --- summary の設定 ---------------------------------------------------
$breadcrumb = '';
foreach($tagPathParts as $part){
    $breadcrumb .= '<em>' . implode(', ', $part) . '</em> / ';
}
$breadcrumb = substr($breadcrumb, 0, -3);

$summary = '<p>';
$countHitContents = count($hitContents);
$countSuggestedContents = count($suggestedContents);
if($countHitContents > 0 && $countSuggestedContents > 0){
    // $summary .= Localization\Localize(
    //     'tag-viewer.foundNContentsSuggestedNContents', 
    //     '<em>Found {1} Contents</em>, and <em>{2} Contents Suggested</em> in "{0}".', 
    //     $breadcrumb, $countHitContents, $countSuggestedContents
    // );
}
elseif($countHitContents <= 0 && $countSuggestedContents <= 0){
    $summary .= Localization\Localize(
        'tag-viewer.notFoundContents', 
        'Not Found any Contents in "{0}".', $breadcrumb
    );
}
elseif($countHitContents > 0){
    // $summary .= Localization\Localize(
    //     'tag-viewer.foundNContents', 
    //     '<em>Found {1} Contents</em> in "{0}".', 
    //     $breadcrumb, $countHitContents
    // );
}
else{
    // $summary .= Localization\Localize(
    //     'tag-viewer.suggestedNContents', 
    //     '<em>{1} Contents Suggested</em> in "{0}".', 
    //     $breadcrumb, $countSuggestedContents
    // );
}
$summary .= '</p>';

$summary .= '<div style="margin-top: 1em; margin-bottom: 1em; border: 1px solid #dadce0; border-radius: 6px; padding: 12px 16px;">';
if(!empty($vars['pageHeading']['parents'])) {
    $summary .= '<div style="margin-bottom: 0.5em;">';
    $parents = array_reverse(array_slice($vars['pageHeading']['parents'], 0, -1));
    foreach($parents as $parent){
        $summary .= '<a href="' . $parent['path'] . '">' . $parent['title'] . '</a>';
        $summary .= ' &gt; ';
    }
    $summary .= '</div>';
}
$summary .= '<ul class="tag-list removable">';
$tags = $tagPathParts[count($tagPathParts) - 1];
foreach($tags as $i => $tag){
    $workTagPathParts = $tagPathParts;
    $workTags = $tags;
    array_splice($workTags, $i, 1);
    $workTagPathParts[count($workTagPathParts) - 1] = $workTags;
    $summary .=  '<li><a href="' . 
        CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName']) .
        '">' . $tag . '<span>' . count($excludedTags[$tag]) . '</span></a></li>';
}
$summary .= '</ul>';

$summary .= '<div style="text-align:center;">+</div>';
if(!empty($suggestedTags)) {
    $summary .= '<div>' . Localization\Localize('didYouMean', 'Did you mean: ');
    $summary .= '<ul class="tag-list">';
    foreach ($suggestedTags as $tag => $pathList) {
        $workTagPathParts = $tagPathParts;
        $workTagPathParts[count($workTagPathParts) - 1][] = $tag;
        // Debug::Log($workTagPathParts);
        $summary .=  '<li><a href="' . 
            CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName']) .
            '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
    }
    $summary .=  '</ul>';
    $summary .= '</div>';
}

$summary .= '<details><summary>' . Localization\Localize('others', 'Others') . '</summary>';
$summary .= '<ul class="tag-list">';
foreach ($includedTags as $tag => $pathList) {
    $workTagPathParts = $tagPathParts;
    $workTagPathParts[count($workTagPathParts) - 1][] = $tag;
    // Debug::Log($workTagPathParts);
    $summary .=  '<li><a href="' . 
        CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName']) .
        '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
}
$summary .=  '</ul>';
$summary .= '</details>';

$summary .= '</div>';

$vars['contentSummary'] = $summary;
// End summary の設定 ---

$body = '';
if($countHitContents > 0){
    $body .= '<div style="height: 7px"></div>';
    $body .= CreateTagGroupElement($hitTagGroup, $hitContents, $tagPathParts, $vars['rootDirectory'], $vars['layerName']);
}

if($countSuggestedContents > 0){
    $body .= '<div><h3>' . Localization\Localize('tag-viewer.suggestedContents', 'Suggested Contents') . '</h3>';
    $body .= CreateTagGroupElement($suggestedTagGroup, $suggestedContents, $tagPathParts, $vars['rootDirectory'], $vars['layerName']);
    $body .= '</div>';
}
$vars['contentBody'] = $body;


// navigator 設定
$vars['navigator'] = CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $vars['rootDirectory'], $vars['layerName']);;


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

    if(is_null($source)){
        return $selectedPaths;
    }

    return array_intersect_key($source, $selectedPaths);
}


function FindTagSuggestedPaths($source, $selectorTags){
    $suggestions = [];
    foreach($selectorTags as $tag){
        $suggestions = array_merge($suggestions, array_slice(SearchEngine\Searcher::Search($tag), 0, 30));
    }

    foreach($suggestions as $i => $suggested){
        if($suggested['score'] < 0.8){
            unset($suggestions[$i]);
        }
    }

    SortSuggestions($suggestions);
    $suggestions = array_slice($suggestions, 0, 30);

    $selectedPaths = [];
    foreach($suggestions as $suggested){
        $selectedPaths[$suggested['id']] = $suggested['score'];
    }
    
    if(is_null($source)){
        return $selectedPaths;
    }
    return array_intersect_key($selectedPaths, $source);
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
        if(array_key_exists($path, $path2tag)) {
            $union = array_merge($union, $path2tag[$path]);
        }
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
function CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $rootDirectory, $layerName){
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
                        CreateTagMapHREF($currentPathParts, $rootDirectory, $layerName) .
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
            CreateTagMapHREF(array_merge($currentPathParts, [[$poppedTag]]), $rootDirectory, $layerName) .
            '">' . $poppedTag .
            '</a></li>';
    }

    $navi .= '</ul></nav>';
    return $navi;
}

function SortSuggestions(&$suggestions){
    uasort($suggestions, function($a, $b) {
        if ($a['score'] == $b['score']) {
            return 0;
        }
        return ($a['score'] < $b['score']) ? 1 : -1;
    });
}

function CreateTagGroupElement($tagGroup, $contentMap, $tagPathParts, $rootDirectory, $layerName) {
    $html = '';
    if(!empty($tagGroup['non'])) {
        $html .= '<div class="card-wrapper">';
        $html .= CreateContentListElement($tagGroup['non'], $contentMap);
        $html .= '</div><div class="splitter"></div>';
    }
    foreach($tagGroup['tags'] as $tag => $paths) {
        $html .= '<div class="card-wrapper">';
        $tagHref = CreateTagMapHREF(array_merge($tagPathParts, [[$tag]]), $rootDirectory, $layerName);
        $html .= CreateTagCard($tag, $tagHref);
        $html .= CreateContentListElement($paths, $contentMap);
        $html .= '</div><div class="splitter"></div>';
    }
    return $html;
}

function CreateTagCardsElement($tags, $rootDirectory, $layerName) {
    $html = '';
    if(!empty($tags)) {
        $html .= '<div class="card-wrapper">';
        foreach($tags as $tag => $_) {
            $tagHref = CreateTagMapHREF([[$tag]], $rootDirectory, $layerName);
            $html .= CreateTagCard($tag, $tagHref);
        }
        $html .= '</div><div class="splitter"></div>';
    }
    return $html;
}

function CreateContentListElement($paths, $contentMap){
    $html = '';
    foreach ($paths as $path => $_) {
        $content = $contentMap[$path];
        $parent = $content->Parent();
        $text = GetDecodedText($content);
        $href = CreateContentHREF($content->path);
        $title = NotBlankText([$content->title, basename($content->path)]) .
            ($parent === false ? '' : ' | ' . NotBlankText([$parent->title, basename($parent->path)]));
        $html .= CreateContentCard($title, $text['summary'], $href);
    }
    return $html;
}

/**
 * 
 * [
 *  'non' => ['path'=>true, 'path'=>true, ...],
 *  'tags' => [
 *      'tag' => ['path' => true],
 *      ...
 *  ]      
 * ]
 * 
 * @param array $contentMap
 *  [
 *      'path' => Content, 'path' => Content, ...
 *  ]
 */
function CreateTagGroup($contentMap, $path2tag, $selectedTags) {
    $tagGroup = ['non' => [], 'tags' => []];

    if(!empty($contentMap)) {
        $unionTags = GetUnionTags($contentMap, $path2tag);
        $unionTags = array_diff_key($unionTags, $selectedTags);
        
        foreach($contentMap as $path => $_) {
            $tagGroup['non'][$path] = true;
        }

        foreach($unionTags as $tag => $_) {
            foreach($contentMap as $path => $__) {
                if(array_key_exists($tag, $path2tag[$path] ?? [])) {
                    $tagGroup['tags'][$tag][$path] = true;
                    if(array_key_exists($path, $tagGroup['non'])) {
                        unset($tagGroup['non'][$path]);
                    }
                }
            }
        }
    }
    return $tagGroup;
}

/**
 */
function GetMajorTags($tag2path) {
    $tags = []; $nt = 0; $ts = [];
    foreach($tag2path as $tag => $paths) {
        $count = count($paths);
        $tags[$tag] = $count;
        $ts[$count] = 0;
        $nt++;
    }
    if($nt < 2) return [];
    ksort($ts); $first = key($ts); unset($ts[$first]);

    foreach($ts as $thres => $s) {
        $u0 = 0;
        $u1 = 0; $v1 = 0 ; $n1 = 0;
        $u2 = 0; $v2 = 0 ; $n2 = 0;

        foreach ($tags as $tag => $count) {
            if($count < $thres) { $n1++; $u1 += $count; } // class 1
            else                { $n2++; $u2 += $count; } // class 2
            $u0 += $count;
        }
        $u0 /= ($n1 + $n2);
        $u1 /= $n1; $u2 /= $n2;
        
        foreach ($tags as $tag => $count) {
            if($count < $thres) { $v1 += ($count - $n1) * ($count - $n1); } // class 1
            else                { $v2 += ($count - $n2) * ($count - $n2); } // class 2
        }
        $v1 /= $n1; $v2 /= $n2;

        $vw = ($n1 * $v1 + $n2 * $v2) / ($n1 + $n2);
        $vb = ($n1 * ($u1 - $u0) * ($u1 - $u0) + $n2 * ($u2 - $u0) * ($u2 - $u0)) / ($n1 + $n2);
        $ts[$thres] = $vb / $vw;
    }

    asort($ts); $thres = array_key_last($ts);

    $majorTags = [];
    foreach ($tags as $tag => $count) {
        if($count >= $thres) { $majorTags[$tag] = $count; }
    }

    arsort($majorTags);
    return $majorTags;
}