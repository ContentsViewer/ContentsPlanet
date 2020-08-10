<?php

require_once dirname(__FILE__) . "/../LinkageCMS.php";
require_once dirname(__FILE__) . '/../Module/Debug.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath');
$contentPath=$_POST['contentPath'];

ServiceUtils\ValidateAccessPrivilege($contentPath);

require_once dirname(__FILE__) . '/../Module/ContentsDatabaseManager.php';

// コンテンツの取得
// 存在しないコンテンツ確認
$currentContent = new Content();
if(!$currentContent->SetContent($contentPath)){
    ServiceUtils\SendErrorResponseAndExit('Not found.');
}

require_once dirname(__FILE__) . '/../Module/SearchEngine.php';
require_once dirname(__FILE__) . '/../Module/ContentsViewerUtils.php';
require_once dirname(__FILE__) . '/../Module/CacheManager.php';

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
$response=[
    'related' => []
];

$rootDirectory=substr(GetTopDirectory($contentPath), 1);
$layerName=ContentsDatabaseManager::GetRelatedLayerName($contentPath);
if($layerName === false) $layerName=DEFAULT_LAYER_NAME;
ContentsDatabaseManager::LoadRelatedMetadata($contentPath);
$tag2path = ContentsDatabase::$metadata['tag2path'] ?? [];

$parent = $currentContent->Parent();
$exclusionPathMap = [ $contentPath => true ];
// $parentPathMap = [];
// $childrenPathMap = [];
// $brotherPathMap = [];
// $childCount = $currentContent->ChildCount();
// for($i = 0; $i < $childCount; $i++){
//     if(($child = $currentContent->Child($i)) !== false){
//         $childrenPathMap[$child->path] = true;
//     }
// }
// if($parent !== false){
//     $parentPathMap[$parent->path] = true;
//     $childCount = $parent->ChildCount();
//     for($i = 0; $i < $childCount; $i++){
//         if(($child = $parent->Child($i)) !== false){
//             $brotherPathMap[$child->path] = true;
//         }
//     }
// }

/**
 * [
 *  ['tag' => '', 'suggestions' => []], 
 *  ...
 * ]
 */
$suggestedTagSuggestions = [];
$tagSuggestions = [];
$titleSuggestions = [];
$linkSuggestions = [];

// "<title> <parent.title> で検索
// ただし, parent は rootではない
$title = NotBlankText(
    [$currentContent->title, ContentsDatabaseManager::GetContentPathInfo($currentContent->path)['filename']]
);
$titleTagFullMatch = false;
foreach($tag2path as $tag => $paths){
    if($title === $tag) $titleTagFullMatch = true;
    if(strpos($title, $tag) !== false && !in_array($tag, $currentContent->tags, true)) {
        $suggestedTagSuggestions[] = ['tag' => $tag, 'suggestions' => []];
    }
}

$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);
SearchEngine\Index::Load($indexFilePath);

$titleQuery = $title;
// if($parent !== false){
//     $parentPathInfo = ContentsDatabaseManager::GetContentPathInfo($parent->path);
//     if($parentPathInfo['filename'] != ROOT_FILE_NAME){
//         $titleQuery = NotBlankText([$parent->title, $parentPathInfo['filename']]) . '-' . $titleQuery;
//     }
// }

// if(!$titleTagFullMatch || $titleQuery !== $title){
if(!$titleTagFullMatch){
    $titleSuggestions = SelectSuggestions(
        SearchEngine\Searcher::Search($titleQuery),
        $exclusionPathMap
    );
    $titleSuggestions = SelectAnotherDirectory($titleSuggestions, dirname($currentContent->path));
}

// <tag1> <tag2> <tag3> ..."で検索
foreach($currentContent->tags as $tag){
    if(!in_array($tag, array('noindex', 'noindex-latest'))){
        $suggestions = SelectSuggestions(
            SearchEngine\Searcher::Search($tag), $exclusionPathMap
        );
        $tagSuggestions[] = ['tag' => $tag, 'suggestions' => $suggestions];
    }
}

foreach ($suggestedTagSuggestions as $i => $each) {
    $suggestedTagSuggestions[$i]['suggestions'] = SelectSuggestions(
        SearchEngine\Searcher::Search($each['tag']), $exclusionPathMap
    );
}

$contentCache = new Cache;
$contentCache->Connect($currentContent->path);
$contentCache->Lock(LOCK_SH); $contentCache->Fetch(); $contentCache->Unlock();
$contentCache->Disconnect();
if(array_key_exists('contentLinks', $contentCache->data)) {
    $contentLinks = $contentCache->data['contentLinks'];
    foreach($contentLinks as $path => $_) {
        $linkSuggestions[] = ['id' => $path];
    }
}

// === Set Response ==================================================
$notFounds = [];

if(!empty($contents = CreateSuggestedContents($linkSuggestions, $notFounds))) {
    $response['related'][] = [
        'keyword' => 'Links',
        'detailURL' => false,
        'type' => 'link',
        'contents' => $contents
    ];
}

if(!empty($contents = CreateSuggestedContents($titleSuggestions, $notFounds))) {
    $response['related'][] = [
        'keyword' => $titleQuery,
        'detailURL' => false,
        'type' => 'page',
        'contents' => $contents
    ];
}

foreach($tagSuggestions as $each){
    if(!empty($contents = CreateSuggestedContents($each['suggestions'], $notFounds))) {
        $response['related'][] = [
            'keyword' => $each['tag'],
            'detailURL' => CreateTagMapHREF([[$each['tag']]], $rootDirectory, $layerName),
            'type' => 'tag',
            'contents' => $contents
        ];
    }
}
foreach($suggestedTagSuggestions as $each){
    if(!empty($contents = CreateSuggestedContents($each['suggestions'], $notFounds))) {
        $response['related'][] = [
            'keyword' => $each['tag'],
            'detailURL' => CreateTagMapHREF([[$each['tag']]], $rootDirectory, $layerName),
            'type' => 'tag',
            'contents' => $contents
        ];
    }
}

if(ContentsDatabaseManager::UnregistContentsFromIndex($notFounds)) {
    SearchEngine\Index::Apply($indexFilePath);
}

ServiceUtils\SendResponseAndExit($response);

// === Functions =====================================================

function SelectSuggestions($suggestions, $exclusionPathMap, $scoreThres = 0.8){
    foreach($suggestions as $i => $suggested){
        if(
            $suggested['score'] < $scoreThres ||
            array_key_exists($suggested['id'], $exclusionPathMap)
        ){
            unset($suggestions[$i]);
            continue;
        }
    }
    $suggestions = array_slice($suggestions, 0, 30);
    return $suggestions;
}

function SelectAnotherDirectory($suggestions, $currentDir) {
    foreach($suggestions as $i => $suggested) {
        $path = $suggested['id'];
        if(strpos($path, $currentDir . '/') === 0 ) {
            unset($suggestions[$i]);
            continue;
        }
    }
    return $suggestions;
}

function CreateSuggestedContents($suggestions, &$notFounds){
    $contents = [];
    $content = new Content();
    foreach ($suggestions as $suggested) {
        if($content->SetContent($suggested['id'])){
            $parent = $content->Parent();
            $text = GetDecodedText($content);
            $contentToSet = [
                'title' => $content->title,
                'parentTitle' => false,
                'parentURL' => false,
                'summary' => $text['summary'],
                'url' => CreateContentHREF($content->path)
            ];
            if($parent != false) {
                $contentToSet['parentTitle'] = $parent->title;
                $contentToSet['parentURL'] = CreateContentHREF($parent->path);
            }
            $contents[] = $contentToSet;
        }
        else {
            $notFounds[] = $suggested['id'];
        }
    }
    return $contents;
}