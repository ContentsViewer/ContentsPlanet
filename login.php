<?php

require_once dirname(__FILE__) . "/Module/Authenticator.php";


Authenticator::RequireUnloginedSession();

$messages = [];

if(!isset($_GET['StartLogin'])){
    
    //echo $url;
    RenderLoginPageAndExit($messages);
}

if (
    !isset($_SERVER['PHP_AUTH_DIGEST']) ||
    !($data = Authenticator::HttpDigestParse($_SERVER['PHP_AUTH_DIGEST'])) ||
    !Authenticator::UserExists($data['username']) ||
    $data['response'] != Authenticator::ValidDigestResponse($data)

    ) {
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Digest realm="'. Authenticator::Realm() .
           '",qop="auth",nonce="'.uniqid().'",opaque="'.md5(Authenticator::Realm()).'"');
    
    $messages[] = "認証に失敗しました.";
    RenderLoginPageAndExit($messages);
    
}




Authenticator::StartLoginedSession($data['username']);

function RenderLoginPageAndExit($messages){
    $url = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . "dummy@" . 
            $_SERVER["HTTP_HOST"] . $_SERVER['SCRIPT_NAME'] . "?StartLogin";
            
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>ログイン</title>
</head>
<body>
    <h1>Hello!</h1>
    <ul>
    <?php
        foreach($messages as $message){
            echo "<li>$message</li>";
        }

    ?>
    </ul>
    <a href="<?=$url?>">&gt; ログインを開始する &lt;</a>
</body>
</html>
    <?php
    exit;
}


?>