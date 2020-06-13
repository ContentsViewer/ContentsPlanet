<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . '/../Module/Notifyer.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . '/../Module/CacheManager.php';
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('cmd', 'contentPath');
$cmd = $_POST['cmd'];
$feedbackCacheName = 'feedback-';

if($cmd == 'rate') {
    ServiceUtils\RequireParams('otp', 'contentPath', 'rating');
    $rating = $_POST['rating'];
    $contentPath = $_POST['contentPath'];
    if(!Authenticator::VerifyOTP($_POST['otp'])) {
        ServiceUtils\SendErrorResponseAndExit('Invalid access.');
    }
    ServiceUtils\ValidateAccessPrivilege($contentPath, false, $owner);
    $contentFilePath = Content::RealPath($contentPath);
    if($contentFilePath === false) {
        ServiceUtils\SendErrorResponseAndExit('Not exists content.');
    }
    if(!in_array($rating, [1, 2, 3, 4, 5])) {
        ServiceUtils\SendErrorResponseAndExit('Invalid parameter.');
    }

    $ts = time();

    $feedbackCacheName .= $owner;
    $feedbackCache = new Cache();
    $feedbackCache->Connect($feedbackCacheName); $feedbackCache->Lock(LOCK_EX); $feedbackCache->Fetch();
    $feedbackCache->data['expires'] = 12 * 30 * 24 * 60 * 60;
    $feedbacks = $feedbackCache->data['feedbacks'] ?? [];
    if(!array_key_exists($contentPath, $feedbacks)) {
        $feedbacks[$contentPath] = [];
    }
    $id = $ts; $sub = 1;
    while(array_key_exists($id, $feedbacks[$contentPath])) {
        $id = $ts . '.' . $sub;
        $sub++;
    }
    $feedbacks[$contentPath][$id] = [
        'ts'      => $ts,
        'type'    => 'rating',
        'rating'  => $rating
    ];
    $feedbackCache->data['feedbacks'] = $feedbacks;
    $feedbackCache->Apply(); $feedbackCache->Unlock(); $feedbackCache->Disconnect();
    
    if(!Authenticator::GetUserInfo($owner, 'notifyingList', $notifyingList)) {
        $notifyingList = [];
    }

    $hostURI = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"];
    $feedbackURI = $hostURI . ROOT_URI . '/Feedbacks';
    $contentURI = $hostURI . CreateContentHREF($contentPath);
    Notifyer::Notify([
        'subject' => 'Got Feedback. Content was Rated',
        'name'    => 'Feedback Service',
        'email'   => 'non',
        'content' => "
Page   : {$contentPath} <{$contentURI}>
Rating : {$rating}
--------
For more detail, please look at feedback-viewer <{$feedbackURI}>
    "
    ], $notifyingList);
    ServiceUtils\SendResponseAndExit(['isOK' => true]);
}

else if($cmd == 'message') {
    ServiceUtils\RequireParams('otp', 'contentPath', 'message');
    $contentPath = $_POST['contentPath'];
    $message = $_POST['message'];
    if(!Authenticator::VerifyOTP($_POST['otp'])) {
        ServiceUtils\SendErrorResponseAndExit('Invalid access.');
    }
    ServiceUtils\ValidateAccessPrivilege($contentPath, false, $owner);
    $contentFilePath = Content::RealPath($contentPath);
    if($contentFilePath === false) {
        ServiceUtils\SendErrorResponseAndExit('Not exists content.');
    }

    $ts = time();

    $feedbackCacheName .= $owner;
    $feedbackCache = new Cache();
    $feedbackCache->Connect($feedbackCacheName); $feedbackCache->Lock(LOCK_EX); $feedbackCache->Fetch();
    $feedbackCache->data['expires'] = 12 * 30 * 24 * 60 * 60;
    $feedbacks = $feedbackCache->data['feedbacks'] ?? [];
    if(!array_key_exists($contentPath, $feedbacks)) {
        $feedbacks[$contentPath] = [];
    }
    $id = $ts; $sub = 1;
    while(array_key_exists($id, $feedbacks[$contentPath])) {
        $id = $ts . '.' . $sub;
        $sub++;
    }
    $feedbacks[$contentPath][$id] = [
        'ts'      => $ts,
        'type'    => 'message',
        'message' => $message
    ];
    $feedbackCache->data['feedbacks'] = $feedbacks;
    $feedbackCache->Apply(); $feedbackCache->Unlock(); $feedbackCache->Disconnect();
    
    if(!Authenticator::GetUserInfo($owner, 'notifyingList', $notifyingList)) {
        $notifyingList = [];
    }

    $hostURI = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"];
    $feedbackURI = $hostURI . ROOT_URI . '/Feedbacks';
    $contentURI = $hostURI . CreateContentHREF($contentPath);
    Notifyer::Notify([
        'subject' => 'Got Feedback. Message from a site visitor.',
        'name'    => 'Feedback Service',
        'email'   => 'non',
        'content' => "
Page   : {$contentPath} <{$contentURI}>
Message: 
{$message}
--------
For more detail, please look at this page <{$feedbackURI}>
    "
    ], $notifyingList);
    ServiceUtils\SendResponseAndExit(['isOK' => true]);
}

else if($cmd == 'delete') {
    ServiceUtils\RequireLoginedSession();
    ServiceUtils\RequireParams('token', 'contentPath', 'id');
    $contentPath = $_POST['contentPath'];
    $id = $_POST['id'];
    ServiceUtils\ValidateCsrfToken();
    $owner = Authenticator::GetFileOwnerName($contentPath);
    if($owner === false) {
        ServiceUtils\SendErrorResponseAndExit('No owner.');
    }
    $loginedUser=\Authenticator::GetLoginedUsername();
    if($owner !== $loginedUser) {
        ServiceUtils\SendErrorResponseAndExit('Permission Denied.');
    }
    
    $feedbackCacheName .= $owner;
    $feedbackCache = new Cache();
    $feedbackCache->Connect($feedbackCacheName); $feedbackCache->Lock(LOCK_EX); $feedbackCache->Fetch();
    $feedbackCache->data['expires'] = 12 * 30 * 24 * 60 * 60;
    $feedbacks = $feedbackCache->data['feedbacks'] ?? [];
    if(
        array_key_exists($contentPath, $feedbacks) &&
        array_key_exists($id, $feedbacks[$contentPath])
    ) {
        unset($feedbacks[$contentPath][$id]);
    }
    $feedbackCache->data['feedbacks'] = $feedbacks;
    $feedbackCache->Apply(); $feedbackCache->Unlock(); $feedbackCache->Disconnect();
    ServiceUtils\SendResponseAndExit(['isOK' => true]);
}

ServiceUtils\SendErrorResponseAndExit('Unrecognized command.');