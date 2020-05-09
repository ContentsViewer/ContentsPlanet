<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(FRONTEND_DIR . "/error-page-setup.php");


Debug::LogError("Internal Server Error(500):
  REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "
  Error Message: " . $vars['errorMessage']
);

$vars['header'] = "HTTP/1.1 500 Internal Server Error";
$vars['title'] = "Internal Server Error";
$vars['panelTitle'] = "500";
$vars['panelContentOnIdle'] = 
    Localization\Localize('500.panelContentOnIdle',
    "We are very sorry. Internal Server Error occured. <br/><br/>{0}<br/><br/>" .
    "<a href='{1}'>Goto TopPage</a>" .
    "<div class='note'>* This error will be reported for the best user experience.</div>",
    $vars['errorMessage'], CreateContentHREF($vars['rootContentPath']));

$vars['panelContentOnGameover'] = 
    Localization\Localize('500.panelContentOnGameover',
    "Back to the main objective. ↓" . 
    "<a href='{0}'>Goto TopPage</a><br/>or",
    CreateContentHREF($vars['rootContentPath']));

require(FRONTEND_DIR . '/error-page.php');