<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(FRONTEND_DIR . "/error-page-setup.php");


Debug::LogError("Not Found page Accessed(404):\n  REQUEST_URI: " . $_SERVER['REQUEST_URI']);

$vars['header'] = "HTTP/1.1 404 Not Found";
$vars['title'] = "Not Found...";
$vars['panelTitle'] = "404";
$vars['panelContentOnIdle'] = 
    "存在しない or 移動した コンテンツにアクセスした可能性があります.<br/>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageから探す</a>" .
    "<div class='note'>* 品質向上のためこの問題は管理者に報告されます.</div>";

$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" . 
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageから探す</a><br/>or";

require(FRONTEND_DIR . '/error-page.php');