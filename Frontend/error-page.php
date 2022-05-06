<?php

/**
 * 参照する変数
 *  $vars['header']
 *  $vars['title']
 *  $vars['panelTitle']
 *  $vars['panelContentOnIdle']
 *  $vars['panelContentOnGameover']
 */

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/PathUtils.php");

use ContentsViewerUtils as CVUtils;
use PathUtils\Path;

$rootDirectory = Path::from($vars['rootContentPath'])->canonicalize()->split()[1];


header($vars['header']);
?>
<!DOCTYPE html>
<html lang="<?= $vars['language'] ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html"); ?>

  <title><?= $vars['title'] ?></title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon">
  
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/OutlineText/style.css">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/base.css">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/print.css" media="print">
  <link rel="preload" href="<?=CLIENT_URI?>/ContentsViewer/styles/icon.css" as="style" onload="this.rel='stylesheet'">
  <link rel="preload" href="<?= CLIENT_URI ?>/Space-RUN/Space-RUN.css" as="style" onload="this.rel='stylesheet'">

  <meta name="content-path" content="<?= H($vars['rootContentPath']) ?>">
  <meta name="token" content="<?= H(Authenticator::GenerateCsrfToken()) ?>">
  <meta name="service-uri" content="<?= H(SERVICE_URI) ?>">

  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ContentsViewer/ContentsViewer.js" defer></script>

  <script>
    function onGameLoaded() {
      config.onBeginIdle = function() {
        panelTitle.textContent = "<?= $vars['panelTitle'] ?>";
        panelContent.innerHTML = "<?= $vars['panelContentOnIdle'] ?>";
      }
      config.onBeginGameover = function() {
        panelContent.innerHTML = "<?= $vars['panelContentOnGameover'] ?>";
      }

      function onChangeTheme() {
        if (ThemeChanger.getCurrentTheme() === "dark") {
          config.wallStrokeColor.r = 255;
          config.wallStrokeColor.g = 255;
          config.wallStrokeColor.b = 255;
          config.normalObstacleColor.r = 255;
          config.normalObstacleColor.g = 255;
          config.normalObstacleColor.b = 255;
          config.scoreTextColor.r = 255;
          config.scoreTextColor.g = 255;
          config.scoreTextColor.b = 255;
          config.bulletColor.r = 255;
          config.bulletColor.g = 255;
          config.bulletColor.b = 255;
        } 
        else {
          config.wallStrokeColor.r = 0;
          config.wallStrokeColor.g = 0;
          config.wallStrokeColor.b = 0;
          config.normalObstacleColor.r = 0;
          config.normalObstacleColor.g = 0;
          config.normalObstacleColor.b = 0;
          config.scoreTextColor.r = 0;
          config.scoreTextColor.g = 0;
          config.scoreTextColor.b = 0;
          config.bulletColor.r = 0;
          config.bulletColor.g = 0;
          config.bulletColor.b = 0;
        }
      }
      ThemeChanger.onChangeThemeCallbacks.push(onChangeTheme);
      onChangeTheme();
      startGame();
    }
  </script>
  <script src="<?= CLIENT_URI ?>/Space-RUN/Space-RUN.js" defer onload="onGameLoaded()"></script>

  <style>
    #game-panel-content a {
      display: block;
      text-align: center;
      margin-left: auto;
      margin-right: auto;
      text-decoration: underline;
      font-size: 120%;
    }

    #game-panel-content .note {
      margin-top: 1em;
      font-size: 60%;
    }

    #game-button {
      display: none;
    }
  </style>
</head>

<body>
  <?= CVUtils\CreateHeaderArea($vars['rootContentPath'], $rootDirectory, $vars['showRootChildren'], $vars['showPrivateIcon']) ?>
  <div id="game-canvas-container">
    <canvas id="game-canvas"></canvas>
    <div id="game-panel">
      <h1 id="game-panel-title">
        <?= $vars['panelTitle'] ?>
      </h1>
      <div id="game-panel-content">
        <?= $vars['panelContentOnIdle'] ?>
      </div>
      <button id="game-button"></button>
    </div>
  </div>
  <?= CVUtils\CreateSearchOverlay() ?>
</body>

</html>