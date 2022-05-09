<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/PluginLoader.php");


Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

$log = @file_get_contents(ROOT_DIR . '/OutputLog.txt');
if ($log === false) $log = '';

?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?= PluginLoader::getCommonHead() ?>

  <title>Log</title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-log.ico" type="image/vnd.microsoft.icon" />
  
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>
</head>

<body>
  <pre style="white-space: pre; font-family: Consolas,Liberation Mono,Courier,monospace; font-size: 12px;"><?= htmlspecialchars($log) ?></pre>
</body>

</html>