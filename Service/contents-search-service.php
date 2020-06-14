<?php
require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/SearchEngine.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath', 'query');
$contentPath = $_POST['contentPath'];
$query = $_POST['query'];

ServiceUtils\ValidateAccessPrivilege($contentPath);

$response = ['suggestions' => []];
$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);
if(!SearchEngine\Index::Load($indexFilePath)){
    // indexファイルが無いとき, このまま処理を続けない.
    // POSTで送られる"contentPath"は, 存在しないコンテンツパスでも送れるので,
    // 下でApplyIndexしたときに, 余計なファイル作成される
    ServiceUtils\SendResponseAndExit($response);
}

$preSuggestions = SearchEngine\Searcher::Search($query);
// \Debug::Log($preSuggestions);

$maxSuggestionCount = 15;
$suggestionCount = 0;

$suggestions = [];
foreach($preSuggestions as $suggestion){
    
    if($suggestion['score'] < 0.5) break;
    if($suggestionCount >= $maxSuggestionCount) break;

    $content = new Content();
    if(!$content->SetContent($suggestion['id'])){
        // 存在しないコンテンツは index から取り除く
        SearchEngine\Indexer::UnregistIndex($suggestion['id']);
        // \Debug::Log($suggestion['id']);
        continue;
    }

    $parent = $content->Parent();
    
    $text = GetDecodedText($content);

    $suggestions[] = [
        'score' => $suggestion['score'],
        'title' => $content->title,
        'parentTitle' => $parent === false ? false : $parent->title,
        'parentUrl' => $parent === false ? false : CreateContentHREF($parent->path),
        'summary' => $text['summary'],
        'url' => CreateContentHREF($content->path),
    ];
    $suggestionCount++;
}
$response['suggestions'] = $suggestions;
SearchEngine\Index::Apply($indexFilePath);

ServiceUtils\SendResponseAndExit($response);
