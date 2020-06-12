<?php

require_once(MODULE_DIR . '/Authenticator.php');

Authenticator::RequireLoginedSession();

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  
  <title>Feedback Viewer</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-filemanager.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/FeedbackViewer/FeedbackViewer.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/FeedbackViewer/FeedbackViewer.css" />

</head>

<body>
    
  <main>
    <h1>Feedback Viewer</h1>
  </main>
</body>

</html>