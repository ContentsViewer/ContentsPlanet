<?php

require_once dirname(__FILE__) . "/Module/Authenticator.php";

$returnTo = '';
if (isset($_GET['returnTo'])) {
    $returnTo = $_GET['returnTo'];
}

Authenticator::RequireLoginedSession($returnTo);

// CSRFトークンを検証
if ( !Authenticator::ValidateCsrfToken(filter_input(INPUT_GET, 'token')) ) {
    // 400 Bad Request
    header ( 'Content-type: text/plain; charset=UTF-8', true, 400 );
    exit('トークンが無効です');
}

// セッション用Cookieの破棄
setcookie(session_name(), '', 1);

// セッションファイルの破棄
session_destroy();

// ログアウト完了後に/login.phpに遷移
header ('Location: ' . Authenticator::GetLoginURL($returnTo));
