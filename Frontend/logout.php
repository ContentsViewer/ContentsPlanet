<?php

require_once(MODULE_DIR . '/Authenticator.php');

$returnTo = '';
if (isset($_GET['returnTo'])) {
    $returnTo = $_GET['returnTo'];
}

Authenticator::RequireLoginedSession($returnTo);

// CSRFトークンを検証
if ( !Authenticator::ValidateCsrfToken(filter_input(INPUT_GET, 'token')) ) {
    $vars['errorMessage'] = 'トークンが無効です';
    require(FRONTEND_DIR . '/400.php');
    exit();
}

// セッション用Cookieの破棄
setcookie(session_name(), '', 1);

// セッションファイルの破棄
session_destroy();

// ログアウト完了後に/login.phpに遷移
header ('Location: ' . Authenticator::GetLoginURL($returnTo));
