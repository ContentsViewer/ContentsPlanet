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
    "大変申し訳ございません. 内部エラーにあいました.<br/><br/>" .
    $vars['errorMessage'] . "<br/><br/>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a>" .
    "<div class='note'>* 品質向上のためこの問題は管理者に報告されます.</div>";

$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" . 
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageに行く</a><br/>or";

require(FRONTEND_DIR . '/error-page.php');