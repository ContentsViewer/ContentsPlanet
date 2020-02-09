<?php
/**
 * 参照する変数
 *  $vars['pageTitle']
 *  $vars['rootContentPath']
 *  $vars['rootDirectory']
 *  $vars['isPublic']
 *  $vars['pageHeading']['title']
 *  $vars['pageHeading']['parents']
 *  $vars['navigator']
 *  $vars['contentSummary']
 *  $vars['contentBody']
 *  $vars['childList'] = [ ['title' => '', 'summary' => '', 'url' => ''], ... ]
 *  $vars['pageBuildReport']['times'] = ['key' => ['displayName' => '', 'ms' => 0], ... ]
 *  $vars['pageBuildReport']['updates'] = ['key' => ['displayName' => '', 'updated' => false], ... ]
 *  $vars['warningMessages']
 *  $vars['additionalHeadScripts']
 * 
 * オプション
 *  $vars['addPlainTextLink']
 *  $vars['addEditLink']
 *  $vars['openNewTabEditLink']
 *  $vars['fileDate'] = ['createdTime' => '', 'modifiedTime' => '']
 *  $vars['tagline']['tags']
 *  $vars['tagList']
 *  $vars['latestContents']
 *  $vars['leftContent'] = ['title' => '', 'url' => '']
 *  $vars['rightContent'] = ['title' => '', 'url' => '']
 * 
 */

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/ContentsViewerUtils.php");

// $vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <title><?=$vars['pageTitle']?></title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>

  <!-- Code表記 -->
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shCore.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shAutoloader.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/syntaxhighlighter/styles/shCoreDefault.css" />

  <!-- 数式表記 -->
  <script type="text/x-mathjax-config">
    MathJax.Hub.Config({
    tex2jax: { inlineMath: [['$','$'], ["\\(","\\)"]] },
    TeX: { equationNumbers: { autoNumber: "AMS" } }
  });
  </script>
  <script type="text/javascript"
    src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.5/MathJax.js?config=TeX-AMS_CHTML">
  </script>
  <meta http-equiv="X-UA-Compatible" CONTENT="IE=EmulateIE7" />

  <!-- OutlineText, ContentsViewer -->
  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />
  <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.css" />
  <script type="text/javascript" src="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.js"></script>

  <?php
  foreach($vars['additionalHeadScripts'] as $scriptName){
    readfile($scriptName);
  }
  ?>
</head>

<body>
  <input type="hidden" id="contentPath" value="<?=H($vars['rootContentPath'])?>">
  <input type="hidden" id="token" value="<?=H(Authenticator::GenerateCsrfToken())?>">
  <input type="hidden" id="serviceUri" value="<?=H(SERVICE_URI)?>">

  <?=CreateHeaderArea($vars['rootContentPath'], true, !$vars['isPublic']);?>

  <div class='menu-open-button-wrapper'>
    <input type="checkbox" href="#" class="menu-open" name="menu-open" id="menu-open"
      onchange="OnChangeMenuOpen(this)" />
    <label class="menu-open-button" for="menu-open" role="button">
      <span class="lines line-1"></span>
      <span class="lines line-2"></span>
      <span class="lines line-3"></span>
    </label>
  </div>

  <div id="left-column-responsive">
    <?=$vars['navigator']?>
  </div>

  <div id='left-column'>
    <?=$vars['navigator']?>
  </div>

  <div id='right-column'>
    目次
    <nav class='navi'></nav>
    <?php if (isset($vars['addPlainTextLink']) && $vars['addPlainTextLink']): ?>
    <a href="?plainText" class="show-sourcecode">このページのソースコードを表示</a>
    <?php endif;?>
  </div>

  <div id='center-column'>
    <main id="main">
      <article>
        <?=CreatePageHeading($vars['pageHeading']['title'], $vars['pageHeading']['parents'])?>

        <?php if (isset($vars['fileDate'])): ?>
        <div id="file-date">
          <?php if (is_int($vars['fileDate']['createdTime'])): ?>
            <img src='<?=CLIENT_URI?>/Common/CreatedAtStampA.png' alt='公開日'>: <time><?=date("Y-m-d", $vars['fileDate']['createdTime'])?></time>
          <?php endif;?>
          <?php if (is_int($vars['fileDate']['modifiedTime'])): ?>
            <img src='<?=CLIENT_URI?>/Common/UpdatedAtStampA.png' alt='更新日'>: <time><?=date("Y-m-d", $vars['fileDate']['modifiedTime'])?></time>
          <?php endif;?>
        </div>
        <?php endif;?>

        <?php if (isset($vars['tagline'])): ?>
        <ul class="tagline">
          <?php foreach ($vars['tagline']['tags'] as $tag): ?>
          <li><a href='<?=CreateTagDetailHREF($tag, $vars['rootDirectory'])?>'><?=$tag?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endif;?>

        <div id="content-summary" class="summary">
          <?=$vars['contentSummary']?>
          <?php if (isset($vars['latestContents'])): ?>
          <?=CreateNewBox($vars['latestContents'])?>
          <?php endif;?>
          <?php if (isset($vars['tagList'])): ?>
          <h3>タグ一覧</h3>
          <?=CreateTagListElement($vars['tagList'], $vars['rootDirectory'])?>
          <?php endif;?>
        </div>

        <div id="doc-outline-embeded" class="accbox">
          <input type="checkbox" id="toggle-doc-outline" class="cssacc" role="button" autocomplete="off" />
          <label for="toggle-doc-outline">目次</label>
        </div>

        <div id="content-body"><?=$vars['contentBody']?></div>

        <div id="child-list">
          <ul class="child-list">
            <?php foreach ($vars['childList'] as $child): ?>
            <li>
              <div>
                <div class='child-title'>
                  <a href='<?=$child['url']?>'><?=$child['title']?></a>
                </div>
                <div class='child-summary'>
                  <?=$child['summary']?>
                </div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="left-right-content-link-container clear-fix">
          <?php if (isset($vars['leftContent'])): ?>
          <a class="left-content-link" href="<?=$vars['leftContent']['url']?>">
            <svg viewBox="0 0 48 48">
              <path d="M30.83 32.67l-9.17-9.17 9.17-9.17L28 11.5l-12 12 12 12z"></path>
            </svg>
            <?=mb_strimwidth($vars['leftContent']['title'], 0, 40, "...")?>
          </a>
          <?php endif;?>
          <?php if (isset($vars['rightContent'])): ?>
          <a class="right-content-link" href="<?=$vars['rightContent']['url']?>">
            <?=mb_strimwidth($vars['rightContent']['title'], 0, 40, "...")?>
            <svg viewBox="0 0 48 48">
              <path d="M17.17 32.92l9.17-9.17-9.17-9.17L20 11.75l12 12-12 12z"></path>
            </svg>
          </a>
          <?php endif;?>
        </div>

        <div id='main-footer-responsive'>
          <?php if (isset($vars['addPlainTextLink']) && $vars['addPlainTextLink']): ?>
          <a href="?plainText">このページのソースコードを表示</a>
          <?php endif;?>
        </div>

        <div id='printfooter'>
          「<?=(empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];?>」から取得
        </div>
      </article>
    </main>
    <footer id='footer'>
      <ul id='footer-info'>
        <li id='footer-info-editlink'>
          <a href='<?=ROOT_URI?>/Login' target='FileManager'>Manage</a>
          <?php if (isset($vars['addEditLink']) && $vars['addEditLink']): ?>
          <a href='?cmd=edit'
            <?=(isset($vars['openNewTabEditLink']) && $vars['openNewTabEditLink']) ? "target='_blank'" : ""?>>Edit</a>
          <?php endif;?>
        </li>
        <li id='footer-info-cms'>
          Powered by <?=COPYRIGHT?>
        </li>
        <li id='footer-info-build-report'>
          <?php foreach ($vars['pageBuildReport']['times'] as $key => $info): ?>
          <?=$info['displayName']?>: <?=sprintf("%.2f[ms]", $info['ms'])?>;
          <?php endforeach; ?>
          <?php if (count($vars['pageBuildReport']['updates']) > 0): ?>
          <?php
            $eaches = [];
            foreach ($vars['pageBuildReport']['updates'] as $key => $info){
              $eaches[] = $info['displayName'] . '=' . ($info['updated'] ? 'Y' : 'N');
            }
          ?>
          Update: <?=implode(', ', $eaches)?>;
          <?php endif;?>
        </li>
      </ul>
    </footer>
  </div>

  <div id='sitemask' onclick='OnClickSitemask()' role='button' aria-label='閉じる'></div>
  <?=CreateSearchOverlay()?>

  <?php if (count($vars['warningMessages']) > 0): ?>
  <div id="warning-message-box">
    <ul>
      <?php foreach ($vars['warningMessages'] as $message): ?>
      <li><?=$message?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif;?>

  <!-- SyntaxHighlighter 有効化 -->
  <script>
  SyntaxHighlighter.autoloader(
    'applescript            <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushAppleScript.js',
    'actionscript3 as3      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushAS3.js',
    'bash shell             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushBash.js',
    'coldfusion cf          <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushColdFusion.js',
    'cpp c                  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCpp.js',
    'c# c-sharp csharp      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCSharp.js',
    'css                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCss.js',
    'delphi pascal          <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushDelphi.js',
    'diff patch pas         <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushDiff.js',
    'erl erlang             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushErlang.js',
    'groovy                 <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushGroovy.js',
    'java                   <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJava.js',
    'jfx javafx             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJavaFX.js',
    'js jscript javascript  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJScript.js',
    'perl pl                <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPerl.js',
    'php                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPhp.js',
    'text plain             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPlain.js',
    'py python              <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPython.js',
    'ruby rails ror rb      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushRuby.js',
    'sass scss              <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushSass.js',
    'scala                  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushScala.js',
    'sql                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushSql.js',
    'xml xhtml xslt html    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushXml.js'
  );
  SyntaxHighlighter.defaults['gutter'] = false;
  SyntaxHighlighter.all();
  </script>
</body>

</html>