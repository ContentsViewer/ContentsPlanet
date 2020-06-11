<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . '/../Module/Notifyer.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . '/../Module/Utils.php';
require_once dirname(__FILE__) . "/../Module/Authenticator.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('destination', 'subject', 'name', 'email', 'message', 'returnTo', 'otp');
$destination = $_POST['destination'];

if(!Authenticator::VerifyOTP($_POST['otp'])) {
    ServiceUtils\SendErrorResponseAndExit('Invalid access.');
}
if(!Authenticator::UserExists($destination)) {
    ServiceUtils\SendErrorResponseAndExit('No owner.');
}
if(!Authenticator::GetUserInfo($destination, 'isPublic', $isPublic)) {
    ServiceUtils\SendErrorResponseAndExit('Internal error.');
}
if(!Authenticator::GetUserInfo($destination, 'notifyingList', $notifyingList)) {
    ServiceUtils\SendErrorResponseAndExit('No destinations.');
}
if(!$isPublic) {
    // セッション開始
    @session_start();
    
    $loginedUser=\Authenticator::GetLoginedUsername();
    if($loginedUser !== $destination) {
        SendErrorResponseAndExit('Permission denied.');
    }
}

$message = [
    'subject' => $_POST['subject'],
    'name'    => $_POST['name'],
    'email'   => $_POST['email'],
    'content' => $_POST['message']
];
Notifyer::Notify($message, $notifyingList);

header('Location: ' . H($_POST['returnTo']));