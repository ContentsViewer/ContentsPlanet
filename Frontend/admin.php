<?php

require_once(MODULE_DIR . '/Authenticator.php');

Authenticator::RequireLoginedSession();

header('Content-Type: text/html; charset=UTF-8');

require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;

$username = Authenticator::GetLoginedUsername();
Authenticator::GetUserInfo($username, 'contentsFolder', $contentsFolder);
Authenticator::GetUserInfo($username, 'enableRemoteEdit', $enableRemoteEdit);

$layerSuffix = DBControls\GetLayerSuffix($vars['layerName']);
$rootContentPath = $contentsFolder . '/' . ROOT_FILE_NAME . $layerSuffix;

$title = \Localization\Localize('admin.welcome', 'Welcome "{0}"!', htmlspecialchars($username));

$vars['rootContentPath'] = $rootContentPath;
$vars['contentsFolder'] = $contentsFolder;
$vars['pageTitle'] = "Admin: ${title}";
$vars['isPublic'] = false;
$vars['warningMessages'] = [];

$vars['pageHeading']['title'] = $title;
$vars['pageHeading']['parents'] = [];

$vars['navigator'] = '';
$vars['pageBuildReport']['times'] = [];
$vars['pageBuildReport']['updates'] = [];

$head = '';
$head .= "
<style>
.admin-menu-list {
  display: flex;
  flex-direction: row-reverse;
}
.tip-box {
  margin-top: 1em;
  margin-bottom: 1em;
  border: 1px solid hsl(220deg 9% 50% / 30%);
  border-radius: 6px;
  padding: 12px 16px;
}
</style>
";

$vars['additionalHeadScript'] = $head;

$rootURI = ROOT_URI;
$token = H(Authenticator::GenerateCsrfToken());
$logout = Localization\Localize('logout', 'Log out');

$summary = '';
$summary .= "<div class='admin-menu-list'><a href='${rootURI}/logout?token=${token}'>${logout}</a></div>";

$tip = CVUtils\GetTip($rootContentPath);
$summary .= "<div class='tip-box'>${tip}</div>";

$vars['contentSummary'] = $summary;


$body = '';
$vars['contentBody'] = $body;


$vars['childList'] = [
  [
    'title' => 'Feedback Viewer',
    'summary' => '',
    'url' => ROOT_URI . '/feedbacks'
  ],
  [
    'title' => 'Log Viewer',
    'summary' => '',
    'url' => ROOT_URI . '/logs'
  ]
];

require(FRONTEND_DIR . '/viewer.php');
exit();
