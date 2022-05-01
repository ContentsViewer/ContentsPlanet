<?php

require_once(MODULE_DIR . '/Authenticator.php');
Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

$log = @file_get_contents(ROOT_DIR . '/OutputLog.txt');
if ($log === false) $log = '';

?>
<!DOCTYPE html>
<html>

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html"); ?>
  <title>Log</title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-log.ico" type="image/vnd.microsoft.icon" />
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>
</head>

<body>
  <pre style="white-space: pre; font-family: Consolas,Liberation Mono,Courier,monospace; font-size: 12px;"><?= htmlspecialchars($log) ?></pre>
</body>

</html>