<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Debug.php");
require_once(FRONTEND_DIR . "/error-page-setup.php");


Debug::LogError("Not Found page Accessed(404):
  REQUEST_URI: " . $_SERVER['REQUEST_URI']);

$vars['header'] = "HTTP/1.1 404 Not Found";
$vars['title'] = "Not Found...";
$vars['panelTitle'] = "404";
$vars['panelContentOnIdle'] = 
    "存在しない or 移動した コンテンツにアクセスした可能性があります.<div style='display: flex'>" .
    "<a href='javascript:void(0);' onclick='OnClickSearchButton(&#39;" . H($vars['subURI']) . "&#39;)'>検索する</a>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageから探す</a></div>" .
    "<div class='note'>* 品質向上のためこの問題は管理者に報告されます.</div>";

$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" . 
    "<div style='display: flex'>" .
    "<a href='javascript:void(0);' onclick='OnClickSearchButton(&#39;" . H($vars['subURI']) . "&#39;)'>検索する</a>" .
    "<a href='" . CreateContentHREF($vars['rootContentPath']) . "'>TopPageから探す</a></div> or";

require(FRONTEND_DIR . '/error-page.php');