<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/Utils.php');

// === Please Set below variables ====================================

$username = "";
$password = "";

// ===================================================================


$hash = password_hash($password, PASSWORD_BCRYPT);
$digest = md5($username . ':' . Authenticator::REALM . ':' . $password);

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  <title><?=Localization\Localize('setup.setup', 'Setup')?></title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-setup.ico" type="image/vnd.microsoft.icon" />
  
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <style type="text/css">
    body{
        margin: 0 auto 0 auto;
        max-width: 898px;
        padding: 1em;
    }
    main{
      position: relative;
    }
  </style>
  
  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
</head>

<body>
  <main>
  <h1><?=Localization\Localize('setup.setup', 'Setup')?></h1>
  <hr>
  <div style='display: flex; flex-direction: row; justify-content: space-around;'>
    <div><code>$username</code>: <code><?=H($username)?></code></div>
    <div><code>$password</code>: <code>*****</code></div>
  </div>
  <hr>
  <dl>
    <dt><code>hashedPassword</code></dt>
    <dd><code style="word-wrap: break-word;"><?=H($hash)?></code></dd>

    <dt><code>digest</code></dt>
    <dd><code style="word-wrap: break-word;"><?=H($digest)?></code></dd>
  </dl>
  <hr>
  <div>
    * <?=Localization\Localize('setup.dontForgetDelete', 'Do not forget delete <code>$username</code> and <code>$password</code> after setting!')?>
  </div>
  </main>
</body>

</html>