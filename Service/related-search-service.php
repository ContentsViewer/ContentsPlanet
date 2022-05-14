<?php

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");
require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/ServiceUtils.php');
require_once(MODULE_DIR . '/ErrorHandling.php');
require_once(MODULE_DIR . '/Stopwatch.php');

set_error_handler('ErrorHandling\PlainErrorHandler');

$sw = new Stopwatch();
$sw->Start();

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath');
$contentPath = $_POST['contentPath'];

ServiceUtils\ValidateAccessPrivilege($contentPath);

require_once dirname(__FILE__) . '/../Module/ContentDatabase.php';
require_once dirname(__FILE__) . '/../Module/ContentDatabaseContext.php';

$dbContext = new ContentDatabaseContext($contentPath);

// コンテンツの取得
// 存在しないコンテンツ確認
$currentContent = $dbContext->database->get($contentPath);
if (!$currentContent) {
    ServiceUtils\SendErrorResponseAndExit('Not found.');
}

require_once dirname(__FILE__) . '/../Module/SearchEngine.php';
require_once dirname(__FILE__) . '/../Module/ContentsViewerUtils.php';
require_once dirname(__FILE__) . '/../Module/ContentDatabaseControls.php';
require_once dirname(__FILE__) . '/../Module/CacheManager.php';

use ContentsViewerUtils as CVUtils;
use ContentDatabaseControls as DBControls;


/**
 * 'related' => [
 *      [
 *          'keyword' => '',
 *          'type' => '',
 *          'detailURL' => '',
 *          'contents' => [
 *              [
 *                  'title'=>'',
 *                  'parentTitle'=>'',
 *                  'parentURL'=>'',
 *                  'summary'=>'',
 *                  'url'=>'',
 *              ]
 *          ]
 *      ],
 *      ...
 * ]
 */
$response = [
    'related' => []
];

$rootDirectory = substr(GetTopDirectory($contentPath), 1);
$layerName = DBControls\GetRelatedLayerName($contentPath);
if ($layerName === false) $layerName = DEFAULT_LAYER_NAME;

$dbContext->LoadMetadata();
$tag2path = $dbContext->metadata->data['tag2path'] ?? [];

$parent = $currentContent->Parent();
$exclusionPathMap = [$contentPath => true];


/**
 * [
 *  'tagA, tagB' => [
 *      'tagPathParts' => ['tagA', 'tagB'],
 *      'paths' => ['pathA' => true, 'pathB' => true, ...],
 *      'suggestions' => [
 *          ['id' => 'pathA'],
 *          ['id' => 'pathB'],
 *          ...
 *      ]
 *  ],
 *  ...
 * ]
 */
$tagSuggestions = [];
$titleSuggestions = [];
$linkSuggestions = [];

// "<title> <parent.title> で検索
// ただし, parent は rootではない
$title = NotBlankText(
    [$currentContent->title, DBControls\GetContentPathInfo($currentContent->path)['filename']]
);

$fullMatchTag = null;
$suggestedTags = DBControls\GetSuggestedTags($currentContent, $tag2path, false, $fullMatchTag);
$suggestedTags = array_unique(array_merge($suggestedTags, $currentContent->tags));


$dbContext->LoadIndex();

$titleQuery = $title;

if (is_null($fullMatchTag)) {
    $titleSuggestions = SelectSuggestions(
        SearchEngine\Searcher::Search($dbContext->index, $titleQuery),
        $exclusionPathMap
    );
    $titleSuggestions = SelectAnotherDirectory($titleSuggestions, dirname($currentContent->path));
}

// タグ検索
$tagGroups = [];
foreach ($suggestedTags as $tag) {
    if (in_array($tag, array('noindex', 'noindex-recent'))) {
        continue;
    }

    $suggestions = SelectSuggestions(
        SearchEngine\Searcher::Search($dbContext->index, $tag),
        $exclusionPathMap
    );
    $tagGroups[$tag] = array_fill_keys(array_column($suggestions, 'id'), true);
}

foreach ($tagGroups as $tag => $paths) {
    $keys = array_keys($tagGroups, $paths);
    $tagSuggestions[implode(', ', $keys)] = ['tagPathParts' => $keys, 'paths' => $paths];
}

foreach ($tagSuggestions as $key => $desc) {
    $tagSuggestions[$key]['suggestions'] = array_map(function ($path) {
        return ['id' => $path];
    }, array_keys($desc['paths']));
}


$contentCache = new Cache;
$contentCache->Connect($currentContent->path);
$contentCache->Lock(LOCK_SH);
$contentCache->Fetch();
$contentCache->Unlock();
$contentCache->Disconnect();

if (isset($contentCache->data['contentLinks'])) {
    $contentLinks = $contentCache->data['contentLinks'];
    foreach ($contentLinks as $path => $_) {
        $linkSuggestions[] = ['id' => $path];
    }
}

// === Set Response ==================================================
$notFounds = [];

if (!empty($contents = CreateSuggestedContents($dbContext->database, $linkSuggestions, $notFounds))) {
    $response['related'][] = [
        'keyword' => 'Links',
        'detailURL' => false,
        'type' => 'link',
        'contents' => $contents
    ];
}

if (!empty($contents = CreateSuggestedContents($dbContext->database, $titleSuggestions, $notFounds))) {
    $response['related'][] = [
        'keyword' => $titleQuery,
        'detailURL' => false,
        'type' => 'page',
        'contents' => $contents
    ];
}

foreach ($tagSuggestions as $key => $desc) {
    if (!empty($contents = CreateSuggestedContents($dbContext->database, $desc['suggestions'], $notFounds))) {
        $response['related'][] = [
            'keyword' => $key,
            'detailURL' => CVUtils\CreateTagMapHREF([$desc['tagPathParts']], $rootDirectory, $layerName),
            'type' => 'tag',
            'contents' => $contents
        ];
    }
}

if ($dbContext->DeleteContentsFromIndex($notFounds)) {
    $dbContext->ApplyIndex();
}

$sw->Stop();
if ($sw->Elapsed() > 1.5) {
    Debug::LogWarning(
        "Performance Note:\n" .
            "  Service Name: related-search-service\n" .
            "  Content Path: {$currentContent->path}\n" .
            "  Process Time: " . $sw->Elapsed() * 1000 . " ms\n"
    );
}

ServiceUtils\SendResponseAndExit($response);


// === Functions =====================================================

function SelectSuggestions($suggestions, $exclusionPathMap, $scoreThres = 0.8)
{
    foreach ($suggestions as $i => $suggested) {
        if (
            $suggested['score'] < $scoreThres ||
            array_key_exists($suggested['id'], $exclusionPathMap)
        ) {
            unset($suggestions[$i]);
            continue;
        }
    }
    $suggestions = array_slice($suggestions, 0, 10);
    return $suggestions;
}

function SelectAnotherDirectory($suggestions, $currentDir)
{
    foreach ($suggestions as $i => $suggested) {
        $path = $suggested['id'];
        if (strpos($path, $currentDir . '/') === 0) {
            unset($suggestions[$i]);
            continue;
        }
    }
    return $suggestions;
}

function CreateSuggestedContents(ContentDatabase $database, $suggestions, &$notFounds)
{
    $contents = [];
    foreach ($suggestions as $suggested) {
        $content = $database->get($suggested['id']);
        if (!$content) {
            $notFounds[] = $suggested['id'];
            continue;
        }

        $parent = $content->Parent();
        $text = CVUtils\GetDecodedText($content);
        $contentToSet = [
            'title' => $content->title,
            'parentTitle' => false,
            'parentURL' => false,
            'summary' => $text['summary'],
            'url' => CVUtils\CreateContentHREF($content->path)
        ];
        if ($parent != false) {
            $contentToSet['parentTitle'] = $parent->title;
            $contentToSet['parentURL'] = CVUtils\CreateContentHREF($parent->path);
        }
        $contents[] = $contentToSet;
    }
    return $contents;
}
