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

header($vars['header']);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <title><?=$vars['title']?></title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />
  <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.css" />
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/Space-RUN/Space-RUN.css" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.js"></script>

</head>

<body>
  <input type="hidden" id="contentPath" value="<?=H($vars['rootContentPath'])?>">
  <input type="hidden" id="token" value="<?=H(Authenticator::GenerateCsrfToken())?>">
  <input type="hidden" id="serviceUri" value="<?=H(SERVICE_URI)?>">

  <?=CreateHeaderArea($vars['rootContentPath'], $vars['showRootChildren'], $vars['showPrivateIcon'])?>
  <div id="game-canvas-container">
    <canvas id="game-canvas"></canvas>
    <div id="game-panel">
      <h1 id="game-panel-title"></h1>
      <div id="game-panel-content"></div>
      <button id="game-button"></button>
    </div>
  </div>
  <script src="<?=CLIENT_URI?>/Space-RUN/Space-RUN.js"></script>
  <script>
  config.onBeginIdle = function() {
    panelTitle.textContent = "<?=$vars['panelTitle']?>";
    panelContent.innerHTML = "<?=$vars['panelContentOnIdle']?>";
  }
  config.onBeginGameover = function() {
    panelContent.innerHTML = "<?=$vars['panelContentOnGameover']?>";
  }
  function onChangeTheme(){
    if(ThemeChanger.getCurrentTheme() === "dark"){
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
    else{
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
  </script>
  <?=CreateSearchOverlay()?>
</body>

</html>