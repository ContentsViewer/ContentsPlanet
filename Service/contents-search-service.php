<?php
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/SearchEngine.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";


if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    exit;
}

if(!isset($_POST['contentPath']) || !isset($_POST['query'])){
    SendErrorResponseAndExit('Few parameters.');
}
$contentPath = $_POST['contentPath'];
$query = $_POST['query'];


$owner = Authenticator::GetFileOwnerName($contentPath);
if($owner === false){
    SendErrorResponseAndExit('No owner.');
}

$isPublic = false;

if(!Authenticator::GetUserInfo($owner, 'isPublic', $isPublic)){
    SendErrorResponseAndExit('Internal error.');
}

if(!$isPublic){
    // セッション開始
    @session_start();

    if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
        SendErrorResponseAndExit('Invalid token.');
    }

    $loginedUser = Authenticator::GetLoginedUsername();
    if ($loginedUser !== $owner) {
        SendErrorResponseAndExit('Permission denied.');
    }
}

$response = [];
$indexFilePath = ContentsDatabaseManager::GetRelatedIndexFileName($contentPath);
SearchEngine\Seacher::LoadIndex($indexFilePath);
SearchEngine\Indexer::LoadIndex($indexFilePath);
$preSuggestions = SearchEngine\Seacher::Search($query);

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
        'summary' => $text['summary'],
        'url' => CreateContentHREF($content->path),
    ];
    $suggestionCount++;
}
$response['suggestions'] = $suggestions;
SearchEngine\Indexer::ApplyIndex($indexFilePath);

// SendErrorResponseAndExit('Permission denied.');
SendResponseAndExit($response);

function SendErrorResponseAndExit($error){
    SendResponseAndExit(['error' => $error]);
}

function SendResponseAndExit($response){
    echo json_encode($response);
    exit;
}