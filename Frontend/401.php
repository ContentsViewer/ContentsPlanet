<?php

require_once(FRONTEND_DIR . "/error-page-setup.php");

$vars['header'] = "HTTP/1.1 401 Unauthorized";
$vars['title'] = "Unauthorized...";
$vars['panelTitle'] = "401";
$vars['panelContentOnIdle'] = 
    "対象のコンテンツに対するアクセス権がありません.<br/>" . 
    "アクセス権を持つアカウントに再度ログインしてください.<br/>" .
    "<a href='" . ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]) ."'>" .
    "&gt;&gt;再ログイン&lt;&lt;</a>";
$vars['panelContentOnGameover'] = 
    "本来の目的にもどる↓" .
    "<a href='" . ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]) ."'>" .
    "再ログインしてコンテンツにアクセスする</a><br/>or";

require(FRONTEND_DIR . '/error-page.php');