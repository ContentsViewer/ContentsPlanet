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

ServiceUtils\ValidateAccessPrivilege($contentPath);

$response = ['suggestions' => []];

$indexFilePath = DBControls\GetRelatedIndexFileName($contentPath);
$index = new SearchEngine\Index();
if (!$index->Load($indexFilePath)) {
    // indexファイルが無いとき, このまま処理を続けない.
    // POSTで送られる"contentPath"は, 存在しないコンテンツパスでも送れるので,
    // 下でApplyIndexしたときに, 余計なファイル作成される
    ServiceUtils\SendResponseAndExit($response);
}

$contentDB = new ContentDatabase();

$preSuggestions = SearchEngine\Searcher::Search($index, $query);
// \Debug::Log($preSuggestions);

$maxSuggestionCount = 15;
$suggestionCount = 0;

$suggestions = [];
$notFounds = [];
foreach ($preSuggestions as $suggestion) {

    if ($suggestion['score'] < 0.5) break;
    if ($suggestionCount >= $maxSuggestionCount) break;

    $content = $contentDB->get($suggestion['id']);
    if (!$content) {
        // 存在しないコンテンツは index から取り除く
        $notFounds[] = $suggestion['id'];
        continue;
    }

    $parent = $content->Parent();

    $text = CVUtils\GetDecodedText($content);

    $suggestions[] = [
        'score' => $suggestion['score'],
        'title' => $content->title,
        'parentTitle' => $parent === false ? false : $parent->title,
        'parentUrl' => $parent === false ? false : CVUtils\CreateContentHREF($parent->path),
        'summary' => $text['summary'],
        'url' => CVUtils\CreateContentHREF($content->path),
    ];
    $suggestionCount++;
}
$response['suggestions'] = $suggestions;

if (DBControls\DeleteContentsFromIndex($index, $notFounds)) {
    $index->Apply($indexFilePath);
}

ServiceUtils\SendResponseAndExit($response);
