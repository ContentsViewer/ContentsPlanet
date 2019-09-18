<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(FRONTEND_DIR . "/error-page-setup.php");


$vars['header'] = "HTTP/1.1 400 Bad Request";
$vars['title'] = "Internal Server Error";
$vars['panelTitle'] = "400";
$vars['panelContentOnIdle'] = 
    "不正なリクエストを確認しました.<br/><br/>" .
    $vars['errorMessage'] . "<br/><br/>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a>";

$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" . 
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a><br/>or";

require(FRONTEND_DIR . '/error-page.php');