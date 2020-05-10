<?php

require_once(MODULE_DIR . '/Authenticator.php');

$returnTo = '';
if (isset($_GET['returnTo'])) {
    $returnTo = $_GET['returnTo'];
}

Authenticator::RequireUnloginedSession($returnTo);

$messages = [];

if(!isset($_GET['start'])){
    RenderLoginPageAndExit($messages, $vars['language']);
}

if (
    !isset($_SERVER['PHP_AUTH_DIGEST']) ||
    !($username = Authenticator::VerifyDigest($_SERVER['PHP_AUTH_DIGEST'])) ||
    !Authenticator::UserExists($username)
) {
    Authenticator::SendDigestAuthenticationHeader();

    $messages[] = Localization\Localize('authenticationFailed', 'Authentication failed.');
    RenderLoginPageAndExit($messages, $vars['language']);
}


Authenticator::StartLoginedSession($username, $returnTo);


function RenderLoginPageAndExit($messages, $language){
    $url = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . "" . 
            $_SERVER["HTTP_HOST"] . ROOT_URI . "/Login?start";
    
    if(isset($_GET['returnTo'])){
        $url .= '&returnTo=' . urlencode($_GET['returnTo']);
    }
?>
<!DOCTYPE html>
<html lang="<?=$language?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html"); ?>
  <title><?=Localization\Localize('login', 'Log in')?></title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-login.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <style type="text/css">
  body {
    text-align: center;
  }

  ul {
    list-style: none;
    padding: 0;
  }

  .spinner {
    width: 40px;
    height: 40px;
    margin: 100px auto;
    background-color: #333;
    border-radius: 100%;
    animation: sk-scaleout 1.0s infinite ease-in-out;
  }

  @keyframes sk-scaleout {
    0% {
      transform: scale(0);
    }

    100% {
      transform: scale(1.0);
      opacity: 0;
    }
  }

  @media screen {
    html[theme="dark"] .spinner {
      background-color: #cccccc;
    }
  }
  </style>
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
  <div class="spinner"></div>
  <a href="<?=$url?>">&gt; <?=Localization\Localize('login', 'Log in')?> &lt;</a>
</body>

</html>
<?php
    exit;
}