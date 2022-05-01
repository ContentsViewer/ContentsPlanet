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
            $_SERVER["HTTP_HOST"] . ROOT_URI . "/login?start";
    
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

  html {
    height: 100%;
  }
  body {
    height: 100%;
    margin: 0;
    padding: 0;
  }

  .main {
    height: 100%;
    display: flex;
    flex-flow: column;
    justify-content: center;
    align-items: center;
  }

  .main .background {
    position: absolute;
    display: flex;
  }

  .main .body {
    display: flex;
    flex-flow: column;
    justify-content: center;
    align-items: center;
    max-height: 300px;
    height: 100%;
    padding: 1rem;
  }

  .main .body .spacer {
    flex-grow: 1;
  }

  .main h1 {
    position: relative;
    margin-top: 0;
    margin-bottom: .75rem;
  }
  .main ul {
    list-style: none;
    text-align: center;
    margin: 0;
    padding: 0;
  }

  .spinner {
    width: 40px;
    height: 40px;
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

  .main .login {
    display: inline-block;
    padding: .375rem .75rem;
  }

  @media screen {
    html[theme="dark"] .spinner {
      background-color: #cccccc;
    }
  }
  </style>
</head>

<body>
  <div class='main'>
    <div class='background'><div class="spinner"></div></div>
    <div class='body'>
      <h1>Hello!</h1>
      <ul>
        <?php foreach ($messages as $message): ?>
          <li><?=$message?></li>
        <?php endforeach; ?>
      </ul>
      <div class='spacer'></div>
      <a class='login' href="<?=$url?>">&gt; <?=Localization\Localize('login', 'Log in')?> &lt;</a>
    </div>
  </div>
</body>

</html>
<?php
    exit;
}