<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(MODULE_DIR . "/Utils.php");
require_once(MODULE_DIR . "/ContentsDatabaseManager.php");

require_once(FRONTEND_DIR . "/error-page-setup.php");

Debug::LogError("Not Found page Accessed(404):
  REQUEST_URI: " . $_SERVER['REQUEST_URI']);

$query = ContentsDatabaseManager::ReduceURI($vars['subURI']);

$vars['header'] = "HTTP/1.1 404 Not Found";
$vars['title'] = "Not Found...";
$vars['panelTitle'] = "404";
$vars['panelContentOnIdle'] = 
    Localization\Localize('404.panelContentOnIdle',
    "You may access the not-existed or moved content.<div style='display: flex; align-items: center;'>" .
    "<a style='flex-basis: 30%;' href='javascript:void(0);' onclick='OnClickSearchButton(&#39;{0}&#39;)'>Search</a>" .
    "<a style='flex-basis: 30%;' href='{1}'>Find from The Top</a>" .
    "<a style='flex-basis: 30%;' href='{2}'>Find from The Same Directory</a></div>" .
    "<div class='note'>* This error will be reported for the best user experience.</div>", 
    H($query), CreateContentHREF($vars['rootContentPath']), ROOT_URI . dirname($vars['subURI']));

$vars['panelContentOnGameover'] = 
    Localization\Localize('404.panelContentOnGameover',
    "Back to the main objectives. ↓" . 
    "<div style='display: flex; align-items: center;'>" .
    "<a style='flex-basis: 30%;' href='javascript:void(0);' onclick='OnClickSearchButton(&#39;{0}&#39;)'>Search</a>" .
    "<a style='flex-basis: 30%;' href='{1}'>Find from The Top</a>" .
    "<a style='flex-basis: 30%;' href='{2}'>Find from The Same Directory</a></div> or",
    H($query), CreateContentHREF($vars['rootContentPath']), ROOT_URI . dirname($vars['subURI']));

require(FRONTEND_DIR . '/error-page.php');