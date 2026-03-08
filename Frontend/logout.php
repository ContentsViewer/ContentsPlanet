<?php

require_once(MODULE_DIR . '/Authenticator.php');

$returnTo = '';
if (isset($_GET['returnTo'])) {
    $returnTo = $_GET['returnTo'];
}

authenticator()->requireLoginedSession($returnTo);

// CSRFトークンを検証
if ( !authenticator()->validateCsrfToken((string) filter_input(INPUT_GET, 'token')) ) {
    $vars['errorMessage'] = Localization\Localize('invalidToken', 'Invalid Token.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

// セッション用Cookieの破棄
setcookie(session_name(), '', 1);

// セッションファイルの破棄
session_destroy();

// ログアウト完了後に/login.phpに遷移
header ('Location: ' . authenticator()->getLoginUrl($returnTo));
