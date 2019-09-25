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
    "不正なリクエストを確認しました.<br/><br/>" .
    $vars['errorMessage'] . "<br/><br/>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a>" .
    "<div class='note'>* 品質向上のためこの問題は管理者に報告されます.</div>";

$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" . 
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a><br/>or";

require(FRONTEND_DIR . '/error-page.php');