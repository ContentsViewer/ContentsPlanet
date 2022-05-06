<?php
/**
 * 参照する変数
 *  $vars['filePath']
 */

if(!is_file($vars['filePath'])){
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$file = fopen($vars['filePath'], 'r');
flock($file, LOCK_SH);
$text = stream_get_contents($file);
fclose($file);

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon" />
  
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
</head>
<body>
  <pre style="white-space: pre; font-family: Consolas,Liberation Mono,Courier,monospace; font-size: 12px;"><?=htmlspecialchars($text)?></pre>
</body>
</html>