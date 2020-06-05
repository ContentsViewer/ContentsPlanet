<?php
require_once dirname(__FILE__) . '/../Module/Debug.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';

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

/**
 * 'related' => [
 *      [
 *          'keyword' => '',
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
$parentPathMap = [];
$childrenPathMap = [];
$exclusionPathMap = [ $contentPath => true ];
$brotherPathMap = [];
$childCount = $currentContent->ChildCount();
for($i = 0; $i < $childCount; $i++){
    if(($child = $currentContent->Child($i)) !== false){
        $childrenPathMap[$child->path] = true;
    }
}
if($parent !== false){
    $parentPathMap[$parent->path] = true;
    $childCount = $parent->ChildCount();
    for($i = 0; $i < $childCount; $i++){
        if(($child = $parent->Child($i)) !== false){
            $brotherPathMap[$child->path] = true;
        }
    }
}

/**
 * [
 *  ['tag' => '', 'suggestions' => []], 
 *  ...
 * ]
 */
$suggestedTagSuggestions = [];
$tagSuggestions = [];
$titleSuggestions = [];

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

SearchEngine\Index::Load(ContentsDatabaseManager::GetRelatedIndexFileName($contentPath));

$titleQuery = $title;
if($parent !== false){
    $parentPathInfo = ContentsDatabaseManager::GetContentPathInfo($parent->path);
    if($parentPathInfo['filename'] != ROOT_FILE_NAME){
        $titleQuery = NotBlankText([$parent->title, $parentPathInfo['filename']]) . '-' . $titleQuery;
    }
}

if(!$titleTagFullMatch || $titleQuery !== $title){
    $titleSuggestions = SelectSuggestions(
        SearchEngine\Searcher::Search($titleQuery),
        array_merge($exclusionPathMap, $parentPathMap, $brotherPathMap, $childrenPathMap),
        0.5
    );
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

// === Set Response ==================================================
if(count($titleSuggestions) > 0) {
    $response['related'][] = [
        'keyword' => $titleQuery,
        'detailURL' => false,
        'contents' => CreateSuggestedContents($titleSuggestions)
    ];
}
foreach($tagSuggestions as $each){
    if(count($each['suggestions']) > 0) {
        $response['related'][] = [
            'keyword' => $each['tag'],
            'detailURL' => CreateTagMapHREF([[$each['tag']]], $rootDirectory, $layerName),
            'contents' => CreateSuggestedContents($each['suggestions'])
        ];
    }
}
foreach($suggestedTagSuggestions as $each){
    if(count($each['suggestions']) > 0) {
        $response['related'][] = [
            'keyword' => $each['tag'],
            'detailURL' => CreateTagMapHREF([[$each['tag']]], $rootDirectory, $layerName),
            'contents' => CreateSuggestedContents($each['suggestions'])
        ];
    }
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

function CreateSuggestedContents($suggestions){
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
    }
    return $contents;
}