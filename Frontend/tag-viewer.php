<?php

require_once(MODULE_DIR . "/ContentDatabase.php");
require_once(MODULE_DIR . "/ContentDatabaseContext.php");
require_once(MODULE_DIR . "/ContentDatabaseControls.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Stopwatch.php");
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/SearchEngine.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

if (isset($_GET['layer'])) {
    $vars['layerName'] = $_GET['layer'];
}

$layerSuffix = DBControls\GetLayerSuffix($vars['layerName']);
$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . $layerSuffix;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);
$dbContext = new ContentDatabaseContext($vars['rootContentPath']);

if (
    !DBControls\IsValidLayerName($vars['layerName'])
    || ContentPathUtils::RealPath($dbContext->metaFileName) === false
) {
    // 無効なレイヤー名, メタファイルがないとき
    // 存在しないlayer名を見ている

    $vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

$dbContext->LoadMetadata();
$tag2path = $dbContext->metadata->data['tag2path'] ?? [];
$path2tag = $dbContext->metadata->data['path2tag'] ?? [];
ksort($tag2path);


// layerの再設定
$out = CVUtils\UpdateLayerNameAndResetLocalization($vars['rootContentPath'], $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];


// パスの仕様
// TagMap/TagA/TagB,TagC/TagD
//   TagA -> (TagB, TagC) -> TagD


$tagPath = substr($vars['subURI'], strpos($vars['subURI'], '/TagMap') + 7);

// '/TagMap/A' -> /A -> ['', A]
// '/TagMap' -> '' -> ['']
$tagPathParts = array_slice(explode('/', $tagPath), 1);
foreach ($tagPathParts as $i => $part) {
    $part = explode(',', $part);
    foreach ($part as $j => $tag) {
        $part[$j] = trim($tag);
    }
    $tagPathParts[$i] = $part;
}

$notFound = false;
foreach ($tagPathParts as $i => $part) {
    foreach ($tagPathParts[$i] as $j => $tag) {
        if (!array_key_exists($tag, $tag2path)) {
            // タグが存在しないとき, そのタグは消す
            $notFound = true;
            unset($tagPathParts[$i][$j]);
            continue;
        }
    }
    if (empty($tagPathParts[$i])) {
        unset($tagPathParts[$i]);
    }
}
if ($notFound) {
    $relocatedURL = CVUtils\CreateTagMapHREF($tagPathParts, $vars['rootDirectory'], $vars['layerName']);
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
$vars['rootChildContents'] = $dbContext->GetRootChildContens();

// タイトルの設定
if (empty($tagPathParts)) {
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap');
    $vars['pageHeading']['title'] = $vars['pageTitle'];
} else {
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap') . ': ';
    $i = count($tagPathParts) - 1;
    for ($c = 0; $i >= 0 && $c < 2; $i--, $c++) {
        $vars['pageTitle'] .= implode(', ', $tagPathParts[$i]) . ' | ';
    }
    $vars['pageTitle'] = substr($vars['pageTitle'], 0, -3);

    $vars['pageHeading']['title'] = '' . implode(', ', end($tagPathParts));

    $workTagPathParts = $tagPathParts;
    $i = count($tagPathParts) - 2;
    for (; $i >= 0; $i--) {
        unset($workTagPathParts[$i + 1]);
        $vars['pageHeading']['parents'][] = [
            'title' => implode(', ', $tagPathParts[$i]),
            'path' => CVUtils\CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName'])
        ];
    }
    $vars['pageHeading']['parents'][] = [
        'title' => Localization\Localize('tagmap', 'TagMap'),
        'path' => CVUtils\CreateTagMapHREF([], $vars['rootDirectory'], $vars['layerName'])
    ];
}

// タグが指定されていないとき
if (empty($tagPathParts)) {
    $tags = [];
    foreach ($tag2path as $tag => $paths) {
        $tags[$tag] = count($paths);
    }

    // タグマップを表示して, 終了する.
    $vars['contentSummary'] = '';

    $vars['navigator'] = CreateNavi([], $tag2path, $path2tag, $vars['rootDirectory'], $vars['layerName']);

    $majorTags = DBControls\GetMajorTags($tag2path);

    $body = '';
    $body .= '<div style="margin: 1em;"></div>'
        . CreateTagCardsElement($majorTags, [], $vars['rootDirectory'], $vars['layerName']);
    $body .= '<div style="margin-top: 1em; margin-bottom: 1em;">'
        . CVUtils\CreateTagListElement($tags, $vars['rootDirectory'], $vars['layerName'])
        . '</div>';
    $vars['contentBody'] = $body;

    // ビルド時間計測 終了
    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

// ここから先は, 何らかのタグが指定されている
//  * $tagPathParts の要素数は 0 より大きい


$dbContext->LoadIndex();

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
foreach ($tagPathParts as $part) {
    $selected = array_merge(
        FindTagSuggestedPaths($source, $part, $dbContext->index),
        SelectTaggedPaths($source, $part, $tag2path, $path2tag)
    );
    $eachSelectedTaggedPaths[] = ['selectors' => $part, 'selected' => $selected];
    $source = $selected;
}

// --- 同階層のタググループへの追加と削除
$selectedTags = [];
foreach ($tagPathParts as $part) {
    foreach ($part as $tag) {
        $selectedTags[$tag] = true;
    }
}
$source = null;
if (count($eachSelectedTaggedPaths) > 1) {
    $source = $eachSelectedTaggedPaths[count($eachSelectedTaggedPaths) - 2]['selected'];
}

$includedTags = array_diff_key($tag2path, $selectedTags);
foreach ($includedTags as $tag => $_) {
    $paths =  SelectTaggedPaths(
        $source,
        [$tag],
        $tag2path,
        $path2tag
    );
    if (!empty($paths)) {
        $includedTags[$tag] = $paths;
    } else {
        unset($includedTags[$tag]);
    }
}

$excludedTags = [];
foreach (end($tagPathParts) as $tag) {
    $excludedTags[$tag] = true;
}
foreach ($excludedTags as $tag => $_) {
    $excludedTags[$tag] = SelectTaggedPaths(
        $source,
        [$tag],
        $tag2path,
        $path2tag
    );
}

// --- 類似しているタグ候補の提示 -------------------------------------------------

// .tagmap.index の更新
$tagmapIndexFileName = CONTENTS_HOME_DIR . $vars['rootDirectory'] . '/.index.tagmap' . $layerSuffix;
$tagMapIndex = new SearchEngine\Index();
if (
    !$tagMapIndex->Load($tagmapIndexFileName)
    || !array_key_exists('contentsChangedTime', $dbContext->metadata->data)
    || (filemtime($tagmapIndexFileName) < $dbContext->metadata->data['contentsChangedTime'])
) {
    // tagmap index の更新

    $tagMapIndex->data = []; // indexの初期化
    foreach ($tag2path as $tag => $_) {
        SearchEngine\Indexer::Index($tagMapIndex, $tag, $tag);
    }
    $tagMapIndex->Apply($tagmapIndexFileName);
}

$suggestions = [];
foreach (end($tagPathParts) as $tag) {
    $suggestions = array_merge($suggestions, SearchEngine\Searcher::Search($tagMapIndex, $tag));
}
foreach ($suggestions as $i => $suggested) {
    if ($suggested['score'] < 0.5 || array_key_exists($suggested['id'], $selectedTags)) {
        unset($suggestions[$i]);
    }
}
SortSuggestions($suggestions);


$suggestedTags = [];
foreach ($suggestions as $suggested) {
    $paths = SelectTaggedPaths(
        $source,
        [$suggested['id']],
        $tag2path,
        $path2tag
    );
    if (count($paths) > 0) {
        $suggestedTags[$suggested['id']] = $paths;
    }
}
// End 類似しているタグ候補の提示 ---

// --- ヒットしたコンテンツの設定 -------------------------------------------------


$selectedPaths = end($eachSelectedTaggedPaths)['selected'];

/**
 * [
 *  'path' => content, ...
 * ]
 */
$hitContents = [];

$hitTagGroups = CreateTagGroups($selectedPaths, $path2tag, $selectedTags);

// --- 子タググループ内のコンテンツ数を取得 ---
foreach ($hitTagGroups['tags'] as $tag => $paths) {
    foreach ($paths as $path => $_) {
        $hitContents[$path] = [];
    }
}
// 子タググループ内のコンテンツ数が10以内の時のみ展開する 
$expandTagGroups = count($hitContents) <= 10;
// End 子タググループ内のコンテンツ数を取得 ---

$notFounds = [];

$setContent = function ($path) use (&$hitContents, &$notFounds, &$selectedPaths, &$dbContext) {
    $content = $dbContext->database->get($path);
    if (!$content) {
        $notFounds[] = $path;
        return;
    }
    $hitContents[$path] = ['content' => $content];
    $value = $selectedPaths[$path];
    if (is_bool($value)) {
        // ユーザがタグ付けしたコンテンツ
        $hitContents[$path]['suggested'] = false;
    } else {
        // 提案されたコンテンツ
        $hitContents[$path]['suggested'] = true;
        $hitContents[$path]['score'] = $value;
    }
};

// タグ直下のコンテンツを読み込む
foreach ($hitTagGroups['non'] as $path => $_) {
    $setContent($path);
}

// 子タググループも展開する場合, 展開されるコンテンツも読み込む
if ($expandTagGroups) {
    foreach ($hitContents as $path => $desc) {
        if (isset($desc['content'])) {
            // コンテンツが読み込まれている要素が出た場合, 
            // それ以降の要素のコンテンツはすでに読み込まれている. 
            // 子タググループ内のコンテンツが先に並んでいるため. 
            break;
        }
        // Debug::Log($path);
        $setContent($path);
    }
}

// 見つからないコンテンツをメタデータとインデックスから削除
$dbContext->DeleteContentsFromIndex($notFounds);
$dbContext->ApplyIndex();

$dbContext->DeleteContentsFromMetadata($notFounds);
$dbContext->SaveMetadata();


$countHitContents = count($hitContents);


// --- summary の設定 ---------------------------------------------------
$breadcrumb = '';
foreach ($tagPathParts as $part) {
    $breadcrumb .= '<em>' . implode(', ', $part) . '</em> / ';
}
$breadcrumb = substr($breadcrumb, 0, -3);

$summary = '';

// --- タグコントロールエリア --- 
$summary .= '<div style="margin-top: 1em; margin-bottom: 1em; border: 1px solid #dadce0; border-radius: 6px; padding: 12px 16px;">';
if (!empty($vars['pageHeading']['parents'])) {
    $summary .= '<div style="margin-bottom: 0.5em;">';
    $parents = array_reverse(array_slice($vars['pageHeading']['parents'], 0, -1));
    foreach ($parents as $parent) {
        $summary .= '<a href="' . $parent['path'] . '">' . $parent['title'] . '</a>';
        $summary .= ' &gt; ';
    }
    $summary .= '</div>';
}
$summary .= '<ul class="tag-list removable">';
$tags = $tagPathParts[count($tagPathParts) - 1];
foreach ($tags as $i => $tag) {
    $workTagPathParts = $tagPathParts;
    $workTags = $tags;
    array_splice($workTags, $i, 1);
    $workTagPathParts[count($workTagPathParts) - 1] = $workTags;
    $summary .=  '<li><a href="'
        . CVUtils\CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName'])
        . '">' . $tag . '<span>' . count($excludedTags[$tag]) . '</span></a></li>';
}
$summary .= '</ul>';

$summary .= '<div style="text-align:center;">+</div>';
if (!empty($suggestedTags)) {
    $summary .= '<div>' . Localization\Localize('didYouMean', 'Did you mean: ');
    $summary .= '<ul class="tag-list">';
    foreach ($suggestedTags as $tag => $pathList) {
        $workTagPathParts = $tagPathParts;
        $workTagPathParts[count($workTagPathParts) - 1][] = $tag;
        // Debug::Log($workTagPathParts);
        $summary .=  '<li><a href="'
            . CVUtils\CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName'])
            . '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
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
    $summary .=  '<li><a href="'
        . CVUtils\CreateTagMapHREF($workTagPathParts, $vars['rootDirectory'], $vars['layerName'])
        . '">' . $tag . '<span>' . count($pathList) . '</span></a></li>';
}
$summary .=  '</ul>';
$summary .= '</details>';
$summary .= '</div>';
// End タグコントロールエリア ---

$summary .= '<p>';
if ($countHitContents <= 0) {
    $summary .= Localization\Localize(
        'tag-viewer.notFoundContents',
        'Not Found any Contents in "{0}".',
        $breadcrumb
    );
}
$summary .= '</p>';

$vars['contentSummary'] = $summary;
// End summary の設定 ---

$body = '';
if ($countHitContents > 0) {
    $body .= '<div style="margin: 1em;"></div>';

    // タグ直下のコンテンツを表示
    if (!empty($hitTagGroups['non'])) {
        $body .= '<div class="card-wrapper">';
        $body .= CreateContentCardsElement($hitTagGroups['non'], $hitContents);
        $body .= '</div><div class="splitter"></div>';
    }

    if ($expandTagGroups) {
        $body .= CreateTagGroupsElement($hitTagGroups, $hitContents, $tagPathParts, $vars['rootDirectory'], $vars['layerName']);
    } else {
        $tags = [];
        foreach ($hitTagGroups['tags'] as $tag => $paths) {
            $tags[$tag] = count($paths);
        }
        $body .= CreateTagCardsElement($tags, $tagPathParts, $vars['rootDirectory'], $vars['layerName']);
    }
}

$vars['contentBody'] = $body;


// navigator 設定
$vars['navigator'] = CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $vars['rootDirectory'], $vars['layerName']);;


// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

if ($stopwatch->Elapsed() > 1.5) {
    Debug::LogWarning(
        "Performance Note:\n" .
            "  Page: tag-viewer\n" .
            "  Process Time: " . $stopwatch->Elapsed() * 1000 . " ms\n" .
            "--- Tag Path ---\n" .
            print_r($tagPathParts, true) .
            "----------------"
    );
}

require(FRONTEND_DIR . '/viewer.php');


/**
 * ['pathA' => any, 'pathB' => any, ...]
 * 
 * @param array $source 
 *  ['pathA' => any, 'pathB' => any, ...]
 */
function SelectTaggedPaths($source, $selectorTags, $tag2path, $path2tag)
{
    $selectedPaths = [];
    foreach ($selectorTags as $tag) {
        $selectedPaths = array_merge($selectedPaths, $tag2path[$tag]);
    }

    if (is_null($source)) {
        return $selectedPaths;
    }

    return array_intersect_key($source, $selectedPaths);
}


function FindTagSuggestedPaths($source, $selectorTags, $index)
{
    $suggestions = [];
    foreach ($selectorTags as $tag) {
        $suggestions = array_merge(
            $suggestions,
            SearchEngine\Searcher::Search($index, $tag)
        );
        // Debug::Log($suggestions);
    }

    foreach ($suggestions as $i => $suggested) {
        if ($suggested['score'] < 0.75) {
            unset($suggestions[$i]);
        }
    }

    SortSuggestions($suggestions);

    $selectedPaths = [];
    foreach ($suggestions as $suggested) {
        $selectedPaths[$suggested['id']] = $suggested['score'];
    }

    if (is_null($source)) {
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
function GetUnionTags($paths, $path2tag)
{
    $union = [];
    foreach ($paths as $path => $_) {
        if (array_key_exists($path, $path2tag)) {
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
function CreateNavi($eachSelectedTaggedPaths, $tag2path, $path2tag, $rootDirectory, $layerName)
{
    $navi = '<nav class="navi"><ul>';

    $tagStack = array_reverse(array_keys($tag2path));
    $currentTaggedPathsIndex = -1;
    $countTaggedPaths = count($eachSelectedTaggedPaths);
    if ($countTaggedPaths > 0) {
        $currentTaggedPathsIndex = 0;
    }
    $currentPathParts = [];
    $alreadyCrawlIntoChildren = false;
    $alreadyCrawlIntoChildrenStack = [];

    while (!is_null($poppedTag = array_pop($tagStack))) {
        if ($poppedTag === true) {
            $navi .= '</ul>';
            $currentTaggedPathsIndex--;
            array_pop($currentPathParts);
            $alreadyCrawlIntoChildren = array_pop($alreadyCrawlIntoChildrenStack);
            continue;
        }

        if ((0 <= $currentTaggedPathsIndex) && ($currentTaggedPathsIndex < $countTaggedPaths)) {
            $currentTaggedPaths = $eachSelectedTaggedPaths[$currentTaggedPathsIndex];
            if (in_array($poppedTag, $currentTaggedPaths['selectors'], true)) {
                if (!$alreadyCrawlIntoChildren) {
                    // 子タグのループを終えたときに, すでにループ済みであることを示すフラグ
                    $alreadyCrawlIntoChildrenStack[] = true;
                    $currentPathParts[] = $currentTaggedPaths['selectors'];
                    $navi .= '<li><a href="'
                        . CVUtils\CreateTagMapHREF($currentPathParts, $rootDirectory, $layerName)
                        . '" class="selected">' . implode(', ', $currentTaggedPaths['selectors'])
                        . '</a><ul>';
                    $unionTags = GetUnionTags($currentTaggedPaths['selected'], $path2tag);
                    for ($i = 0; $i <= $currentTaggedPathsIndex; $i++) {
                        foreach ($eachSelectedTaggedPaths[$i]['selectors'] as $tag) {
                            unset($unionTags[$tag]);
                        }
                    }
                    $tagStack[] = true; // 子タグが終わったときの目印
                    $tagStack = array_merge($tagStack, array_reverse(array_keys($unionTags)));
                    $currentTaggedPathsIndex++;
                    $alreadyCrawlIntoChildren = false; // 子タグのループ内では, まだその子タグループはしていない
                    continue;
                } else {
                    // skip
                    continue;
                }
            }
        }
        $navi .= '<li><a href="'
            . CVUtils\CreateTagMapHREF(array_merge($currentPathParts, [[$poppedTag]]), $rootDirectory, $layerName)
            . '">' . $poppedTag . '</a>';
    }

    $navi .= '</ul></nav>';
    return $navi;
}


function SortSuggestions(&$suggestions)
{
    uasort($suggestions, function ($a, $b) {
        if ($a['score'] == $b['score']) {
            return 0;
        }
        return ($a['score'] < $b['score']) ? 1 : -1;
    });
}


function CreateTagGroupsElement($tagGroups, $contentMap, $tagPathParts, $rootDirectory, $layerName)
{
    $html = '';

    $groups = $tagGroups['tags'];
    $compact = [];

    foreach ($groups as $tag => $paths) {
        $keys = array_keys($groups, $paths);
        $compact[implode(', ', $keys)] = ['tagPathParts' => $keys, 'paths' => $paths];
    }

    foreach ($compact as $name => $desc) {
        $html .= '<div class="card-wrapper">';
        $tagHref = CVUtils\CreateTagMapHREF(array_merge($tagPathParts, [$desc['tagPathParts']]), $rootDirectory, $layerName);
        $html .= CVUtils\CreateTagCard($name, $tagHref);
        $html .= CreateContentCardsElement($desc['paths'], $contentMap);
        $html .= '</div><div class="splitter"></div>';
    }
    return $html;
}


function CreateTagCardsElement($tags, $tagPathParts, $rootDirectory, $layerName)
{
    $html = '';
    if (!empty($tags)) {
        $html .= '<div class="card-wrapper">';
        foreach ($tags as $tag => $count) {
            $tagHref = CVUtils\CreateTagMapHREF(array_merge($tagPathParts, [[$tag]]), $rootDirectory, $layerName);
            $html .= CVUtils\CreateTagCard("$tag ($count)", $tagHref, true, true);
        }
        $html .= '</div><div class="splitter"></div>';
    }
    return $html;
}


function CreateContentCardsElement($paths, $contentMap)
{
    $html = '';
    foreach ($paths as $path => $_) {
        $content = $contentMap[$path]['content'];
        $parent = $content->Parent();
        $text = CVUtils\GetDecodedText($content);
        $href = CVUtils\CreateContentHREF($content->path);
        $title = '';

        $title .= NotBlankText([$content->title, basename($content->path)])
            . ($parent === false ? '' : ' | ' . NotBlankText([$parent->title, basename($parent->path)]));

        $footer = '';
        if ($contentMap[$path]['suggested']) {
            $footer = '<div class="magic-icon icon" style="display: inline-block; padding-right: 0.25em;"></div>' . Localization\Localize('tag-viewer.suggested', 'Suggested');
        }
        $html .= CVUtils\CreateContentCard($title, $text['summary'], $href, $footer);
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
 *      'path' => Any, 'path' => Any, ...
 *  ]
 */
function CreateTagGroups($contentMap, $path2tag, $selectedTags)
{
    $tagGroups = ['non' => [], 'tags' => []];

    if (!empty($contentMap)) {
        $unionTags = GetUnionTags($contentMap, $path2tag);
        $unionTags = array_diff_key($unionTags, $selectedTags);

        foreach ($contentMap as $path => $_) {
            $tagGroups['non'][$path] = true;
        }

        foreach ($unionTags as $tag => $_) {
            foreach ($contentMap as $path => $__) {
                if (array_key_exists($tag, $path2tag[$path] ?? [])) {
                    $tagGroups['tags'][$tag][$path] = true;
                    if (array_key_exists($path, $tagGroups['non'])) {
                        unset($tagGroups['non'][$path]);
                    }
                }
            }
        }
    }
    return $tagGroups;
}
