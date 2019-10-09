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

header($vars['header']);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

    <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon" />
    
    <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />
    <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.css" />
    <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/Space-RUN/Space-RUN.css" />

    <script type="text/javascript" src="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.js"></script>

    <title><?=$vars['title']?></title>
</head>
<body>
    <?=CreateHeaderArea($vars['rootContentPath'], $vars['showRootChildren'])?>
    <div id="game-canvas-container">
        <canvas id="game-canvas"></canvas>
        <div id="game-panel">
            <h1 id="game-panel-title"></h1>
            <div id="game-panel-content"></div>
            <button id="game-button"></button>
        </div>
    </div>
    <script>
        var onBeginIdle = function(){
            panelTitle.textContent = "<?=$vars['panelTitle']?>";
            panelContent.innerHTML = "<?=$vars['panelContentOnIdle']?>";
        }
        var onBeginGameover = function(){
            panelContent.innerHTML = "<?=$vars['panelContentOnGameover']?>";
        }
    </script>
    <script src="<?=CLIENT_URI?>/Space-RUN/Space-RUN.js"></script>
</body>
</html>