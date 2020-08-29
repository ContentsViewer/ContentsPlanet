<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . '/../Module/Notifyer.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . '/../Module/Utils.php';
require_once dirname(__FILE__) . "/../Module/Authenticator.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath', 'subject', 'name', 'email', 'message', 'returnTo', 'otp');

if(!Authenticator::VerifyOTP($_POST['otp'])) {
    ServiceUtils\SendErrorResponseAndExit('Invalid access.');
}
ServiceUtils\ValidateAccessPrivilege($_POST['contentPath'], false, $owner);
if(!Authenticator::GetUserInfo($owner, 'notifyingList', $notifyingList)) {
    ServiceUtils\SendErrorResponseAndExit('No destinations.');
}

$message = [
    'subject' => $_POST['subject'],
    'name'    => $_POST['name'],
    'email'   => $_POST['email'],
    'content' => $_POST['message']
];
Notifyer::Notify($message, $notifyingList);

header('Location: ' . H($_POST['returnTo']));