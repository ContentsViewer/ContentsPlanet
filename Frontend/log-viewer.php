<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/PluginLoader.php");


authenticator()->requireLoginedSession($_SERVER["REQUEST_URI"]);

$current = @file_get_contents(ROOT_DIR . '/OutputLog.txt');
$rotated = @file_get_contents(ROOT_DIR . '/OutputLog.1.txt');
if ($current === false) $current = '';
if ($rotated === false) $rotated = '';
$log = $current . ($rotated !== '' ? "\n--- Rotated Log ---\n" . $rotated : '');

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