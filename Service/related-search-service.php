<?php
require_once dirname(__FILE__) . '/../Module/Debug.php';
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . '/../Module/Authenticator.php';

ServiceUtils\RequirePostMethod();

ServiceUtils\RequireParams('contentPath');
$contentPath=$_POST['contentPath'];

ServiceUtils\ValidateAccessPrivilege($contentPath);
