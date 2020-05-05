<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(FRONTEND_DIR . "/error-page-setup.php");

Debug::LogWarning("Bad Request Detected(400):
  Message: " . $vars['errorMessage'] . "
  REQUEST_URI: " . $_SERVER['REQUEST_URI']);

$vars['header'] = "HTTP/1.1 400 Bad Request";
$vars['title'] = "Bad Request !";
$vars['panelTitle'] = "400";
$vars['panelContentOnIdle'] = 
    Localization\Localize('400.panelContentOnIdle', 
    "Bad Request Detected.<br/><br/>" .
    "{0}<br/><br/>" .
    "<a href='{1}'>Goto TopPage</a>" .
    "<div class='note'>* This error will be reported for the best user experience.</div>",
    $vars['errorMessage'], CreateContentHREF($vars['rootContentPath']));

$vars['panelContentOnGameover'] = 
    Localization\Localize('400.panelContentOnGameover', 
    "Back to the main objectives. ↓" . 
    "<a href='{0}'>Goto TopPage</a><br/>or",
    CreateContentHREF($vars['rootContentPath']));

require(FRONTEND_DIR . '/error-page.php');