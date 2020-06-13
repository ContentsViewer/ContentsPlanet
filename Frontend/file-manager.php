<?php

require_once(MODULE_DIR . '/Authenticator.php');

Authenticator::RequireLoginedSession();

header('Content-Type: text/html; charset=UTF-8');

require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');

$username = Authenticator::GetLoginedUsername();
Authenticator::GetUserInfo($username, 'contentsFolder', $contentsFolder);
Authenticator::GetUserInfo($username, 'enableRemoteEdit', $enableRemoteEdit);

$layerSuffix = ContentsDatabaseManager::GetLayerSuffix($vars['layerName']);
$rootContentPath = $contentsFolder . '/' . ROOT_FILE_NAME . $layerSuffix;

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <title>File Manager</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-filemanager.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/FileManager/FileManager.css" />
  <style type="text/css">
    body{
      margin: 0 auto 0 auto;
      max-width: 898px;
      padding: 1em;
    }
    main{
      position: relative;
    }

    .file-wrap {
      margin: 0;
    }

    .tips {
      margin: 2em auto;
      padding: 1em;
      width: 90%;
      color: #666;
      background-color: #f7f7f7;
      border: 1px solid #ccc;
    }

    #logout {
      position: absolute;
      right: 0;
      top: 0;
    }

    #loading-box {
      position: fixed;
      right: 5px;
      bottom: 5px;
      z-index: 99;
      filter: brightness(200%);
    }

    .spinner {
      display: inline-block;
      height: 40px;
      width: 40px;
      position: relative;
    }

    .cube1, .cube2 {
      background-color: rgba(0, 102, 255, 0.5);
      animation: poping-plane 1.8s infinite alternate ease-in-out;
      width: 80%;
      height: 80%;
      position: absolute;
    }

    .cube2 {
      animation-delay: -0.9s;
    }

    @keyframes poping-plane {
      0% {
        transform: translateX(0%) translateY(0%) scale(1.2) rotate(0deg);
      }

      50% {
        transform: translateX(70%) translateY(70%) scale(0.0) rotate(180deg);
      }

      100% {
        transform: translateX(0%) translateY(0%) scale(1.2) rotate(360deg);
      }
    }

    #remaining {
      font-size: 0.7em;
      opacity: 0.5;
      text-shadow: #FFF 0 0 2px;
    }

    .button {
      cursor: pointer;
      font-size: 0.8em;
      border: 1px solid #15aeec;
      background-color: #49c0f0;
      background-image: -webkit-linear-gradient(top, #49c0f0, #2cafe3);
      background-image: linear-gradient(to bottom, #49c0f0, #2cafe3);
      border-radius: 4px;
      color: #fff;
      padding: 10px;
      margin: 10px;
      -webkit-transition: none;
      transition: none;
      text-shadow: 0 1px 1px rgba(0, 0, 0, .3);
    }

    .button:not(.uninteractable):hover {
      border: 1px solid #1090c3;
      background-color: #1ab0ec;
      background-image: -webkit-linear-gradient(top, #1ab0ec, #1a92c2);
      background-image: linear-gradient(to bottom, #1ab0ec, #1a92c2);
    }

    .button:active,
    .uninteractable {
      background: #1a92c2;
      box-shadow: inset 0 3px 5px rgba(0, 0, 0, .2);
      color: #1679a1;
      text-shadow: 0 1px 1px rgba(255, 255, 255, .5);
    }

    .uninteractable {
      cursor: not-allowed;
    }

    .log {
      overflow-y: auto;
      overflow-x: auto;
      width: 100%;
      border: 1px solid #ccc;
      min-height: 100px;
      max-height: 500px;
    }

    @media screen {
      html[theme="dark"] .tips {
        background-color: transparent;
      }
    }
  </style>
  
  <meta name="content-path" content="<?=H($rootContentPath)?>" />
  <meta name="token" content="<?=H(Authenticator::GenerateCsrfToken())?>" />
</head>

<body>
  <main>
    <h1>File Manager</h1>
    <div id='logout'>
      <a href="<?=ROOT_URI?>/Logout?token=<?=H(Authenticator::GenerateCsrfToken())?>"><?=Localization\Localize('logout', 'Log out')?></a>
    </div>
    <p><?=Localization\Localize('file-manager.welcome', 'Welcome {0}!', H($username))?></p>
    <ul>
      <li><?=Localization\Localize('file-manager.frontpage', '<a href="{0}" target="_blank">Here</a> is The FrontPage.', ROOT_URI . Path2URI($rootContentPath))?></li>
    </ul>
    <div class='tips'><?=GetTip($layerSuffix)?></div>

    <h2>Contents</h2>
    <div id='content-tree' class='file-wrap'></div>
    <hr>

    <h2>Log</h2>
    <pre class='log'><?php
      $log = @file_get_contents(ROOT_DIR. '/OutputLog.txt');
      if($log !== false){
        echo H($log);
      }
  ?></pre>
  </main>

  <div id='loading-box'>
    <span id='remaining'></span>
    <div class='spinner'>
      <div class='cube1'></div>
      <div class='cube2'></div>
    </div>
  </div>

  <script src="<?=CLIENT_URI?>/FileManager/FileManager.js" type="text/javascript" charset="utf-8"></script>

  <script>
  
  var token = document.getElementsByName("token").item(0).content;
  var contentPath = document.getElementsByName("content-path").item(0).content;
  var contentManager = new FileManager(document.getElementById('content-tree'),
    '<?=$contentsFolder?>',
    token,
    OpenFile, Path2URI, <?=var_export($enableRemoteEdit)?> , CopyPathText,
    SendRequestCallbackFunction,
    ReceiveResponseCallbackFunction
  );

  // ./Master/Contents/Root -> /CollabCMS/Master/Root
  // /Master/Contents/Root -> /CollabCMS/Master/Root
  function Path2URI(path) {
    path = path.replace(/^\./, "");
    path = path.replace(/^(\/[^\/]*)(\/Contents)(\/.*)?/, "$1$3");
    return '<?=ROOT_URI?>' + path;
  }

  function CopyPathText(fileElement) {
    var path = fileElement.path;
    path = path.replace(/^\./, "");
    path = path.replace(/^(\/[^\/]*)(\/Contents)(\/.*)?/, "$1$3");
    if (FileManager.GetExtention(path) == '.content') {
      path = FileManager.RemoveExtention(path);
    }

    return 'ROOT_URI' + path;
  }

  function OpenFile(path) {
    if (FileManager.GetExtention(path) == '.content') {
      path = FileManager.RemoveExtention(path);
    }

    window.open(Path2URI(path));
  }

  var requestCount = 0;

  function SendRequestCallbackFunction(request) {
    requestCount++;
  }

  function ReceiveResponseCallbackFunction(request) {
    requestCount--;
  }

  var timerId = setTimeout(Update, 1000);

  function Update() {
    var loadingBox = document.getElementById('loading-box');
    var remaining = document.getElementById('remaining');

    if (requestCount > 0) {
      loadingBox.style.visibility = '';
      remaining.textContent = requestCount;
      timerId = setTimeout(Update, 1000);
    } else {
      loadingBox.style.visibility = 'hidden';
      timerId = setTimeout(Update, 500);
    }
  }
  </script>
</body>

</html>