<?php

require_once(FRONTEND_DIR . "/error-page-setup.php");

$vars['header'] = "HTTP/1.1 403 Forbidden";
$vars['title'] = "Forbidden...";
$vars['panelTitle'] = "403";
$vars['panelContentOnIdle'] = 
    Localization\Localize('403.panelContentOnIdle',
    "You do not have access rights for this content.<br/>" . 
    "Please login with another account having the correct access rights and try again.<br/>" .
    "<a href='{0}'>&gt;&gt;Re-login&lt;&lt;</a>",
    ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]));
    
$vars['panelContentOnGameover'] = 
    Localization\Localize('403.panelContentOnGameover',
    "Back to the main objective. ↓" .
    "<a href='{0}'>Re-login to access the content</a><br/>or",
    ROOT_URI . "/Logout?token=" . H(Authenticator::GenerateCsrfToken()) . "&returnTo=" . urlencode($_SERVER["REQUEST_URI"]));

require(FRONTEND_DIR . '/error-page.php');