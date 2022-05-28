<?php
require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/SearchEngine.php";
require_once dirname(__FILE__) . "/../Module/ContentDatabaseControls.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

use ContentsViewerUtils as CVUtils;
use ContentDatabaseControls as DBControls;


ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath', 'query');
$contentPath = $_POST['contentPath'];
$query = $_POST['query'];
// $contentPath = './Master/../../Debugger/Root';
try {
    $contentPath = \PathUtils\canonicalize($contentPath);
} catch (Exception $error) {
    \ServiceUtils\SendErrorResponseAndExit('Invalid Parameter');
}

ServiceUtils\ValidateAccessPrivilege($contentPath);

$response = [
    'suggestions' => [],
    'suggestedTopics' => [],
    'nextTopics' => []
];

$indexFilePath = DBControls\GetRelatedIndexFileName($contentPath);
$index = new SearchEngine\Index();
if (!$index->Load($indexFilePath)) {
    // indexファイルが無いとき, このまま処理を続けない.
    // POSTで送られる"contentPath"は, 存在しないコンテンツパスでも送れるので,
    // 下でApplyIndexしたときに, 余計なファイル作成される
    ServiceUtils\SendResponseAndExit($response);
}

$metaFileName = DBControls\GetRelatedMetaFileName($contentPath);
$metadata = new \ContentDatabaseMetadata();
$metadata->LoadMetadata($metaFileName);
$tag2path = $metadata->data['tag2path'] ?? [];
$path2tag = $metadata->data['path2tag'] ?? [];

if (empty($query)) {
    $majorTags = DBControls\GetMajorTags($tag2path);
    // $majorTags = array_slice($majorTags, 0, 10);
    $response['nextTopics'] = array_keys($majorTags);
    ServiceUtils\SendResponseAndExit($response);
}

$contentDB = new ContentDatabase();

$preSuggestions = filterSuggestions(SearchEngine\Searcher::Search($index, $query), 0.5);
// \Debug::Log($preSuggestions);
$counter = 0;
$suggestions = [];
$notFounds = [];
foreach ($preSuggestions as $suggestion) {
    if ($counter >= 15) break;

    $content = $contentDB->get($suggestion['id']);
    if (!$content) {
        // 存在しないコンテンツは index から取り除く
        $notFounds[] = $suggestion['id'];
        continue;
    }

    $parent = $content->parent();
    $text = CVUtils\GetDecodedText($content);

    $suggestions[] = [
        'score' => $suggestion['score'],
        'title' => $content->title,
        'parentTitle' => $parent === false ? false : $parent->title,
        'parentUrl' => $parent === false ? false : CVUtils\CreateContentHREF($parent->path),
        'summary' => $text['summary'],
        'url' => CVUtils\CreateContentHREF($content->path),
    ];
    ++$counter;
}
$response['suggestions'] = $suggestions;

$queryParts = array_map('mb_strtolower', explode(' ', $query));
$lastQueryPart = end($queryParts);
if (!empty($lastQueryPart)) {
    $layerName = DBControls\GetRelatedLayerName($contentPath);
    $layerSuffix = DBControls\GetLayerSuffix($layerName);
    $userDirectory = DBControls\GetUserDirectory($contentPath);
    $tagmapIndexFileName = CONTENTS_HOME_DIR . '/' . $userDirectory . '/.index.tagmap' . $layerSuffix;
    $tagMapIndex = new SearchEngine\Index();

    if ($tagMapIndex->Load($tagmapIndexFileName)) {
        $suggestedTopics = filterSuggestions(
            array_slice(\SearchEngine\Searcher::Search($tagMapIndex, $lastQueryPart), 0, 10),
            0.6
        );
        \Debug::Log($suggestedTopics);
        // if only one item and it is same as lastQueryPart, it will be removed.
        if (
            count($suggestedTopics) == 1
            && (mb_strtolower($suggestedTopics[0]['id']) == $lastQueryPart)
        ) {
            $suggestedTopics = [];
        }

        // $suggestedTopics = array_filter($suggestedTopics, function ($v) use ($lastQueryPart) {
        //     return mb_strtolower($v['id']) !== $lastQueryPart;
        // });
        // $suggestedTopics = array_values($suggestedTopics);
        $response['suggestedTopics'] = $suggestedTopics;
    }
} else {
}

$topics = getUnionTopics(array_map(function ($v) {
    return $v['id'];
}, filterSuggestions($preSuggestions, 0.8)), $path2tag, $queryParts);
$response['nextTopics'] = $topics;

if (DBControls\DeleteContentsFromIndex($index, $notFounds)) {
    $index->Apply($indexFilePath);
}

ServiceUtils\SendResponseAndExit($response);


function getUnionTopics($paths, $path2tag, $exclusions)
{
    $union = [];
    foreach ($paths as $path) {
        $tags = $path2tag[$path] ?? [];
        foreach ($tags as $tag => $_) {
            if (in_array(mb_strtolower($tag), $exclusions)) continue;

            if (isset($union[$tag])) {
                ++$union[$tag];
            } else {
                $union[$tag] = 1;
            }
        }
    }
    arsort($union);

    return array_keys($union);
}

function filterSuggestions($suggestions, $score)
{
    $filtered = [];
    foreach ($suggestions as $s) {
        if ($s['score'] < $score) break;
        $filtered[] = $s;
    }
    return $filtered;
}
