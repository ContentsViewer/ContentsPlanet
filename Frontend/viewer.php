<?php
/**
 * 参照する変数
 *  $vars['pageTitle']
 *  $vars['rootContentPath'] = 'Master/Contents/Root'
 *  $vars['isPublic']
 *  $vars['pageHeading']['title']
 *  $vars['pageHeading']['parents'] = [ ['title' => '', 'path' => ''], ...]
 *  $vars['navigator']
 *  $vars['contentSummary']
 *  $vars['contentBody']
 *  $vars['childList'] = [ ['title' => '', 'summary' => '', 'url' => ''], ... ]
 *  $vars['pageBuildReport']['times'] = ['key' => ['displayName' => '', 'ms' => 0], ... ]
 *  $vars['pageBuildReport']['updates'] = ['key' => ['displayName' => '', 'updated' => false], ... ]
 *  $vars['warningMessages']
 * 
 * オプション
 *  $vars['contentPath']
 *  $vars['otpRequired'] = true
 *  $vars['htmlLang'] = ''
 *  $vars['canonialUrl'] = ''
 *  $vars['additionalHeadScript'] = ''
 *  $vars['addPlainTextLink'] = true
 *  $vars['fileDate'] = ['createdTime' => '', 'modifiedTime' => '']
 *  $vars['tagline'] = ['tags' => [], 'suggestedTags' => []]
 *  $vars['tagList'] = ['tag' => count:int, ...]
 *  $vars['addMoreTag'] = false
 *  $vars['recentContents']
 *  $vars['leftContent'] = ['title' => '', 'url' => '']
 *  $vars['rightContent'] = ['title' => '', 'url' => '']
 *  $vars['leftPageTabs'] = [['innerHTML' => '', 'selected' => bool], ...]
 *  $vars['rightPageTabs'] = [['innerHTML' => '', 'selected' => bool], ...]
 *  $vars['layerSelector'] = ['selectedLayer' => '', 'layers' => ['name' => '', 'hreflang' => '', 'url' => '', 'selected' => bool], ...]
 *  $vars['pageBottomHTML'] = ''
 *  $vars['mainFooterHTML'] = ''
 */

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/PathUtils.php");


use ContentsViewerUtils as CVUtils;
use PathUtils\Path;


$breadcrumbList = CVUtils\CreateBreadcrumbList(array_reverse($vars['pageHeading']['parents']));
$pluginRootURI = ROOT_URI . Path2URI($vars['contentsFolder'] . '/Plugin');
$rootDirectory = Path::from($vars['rootContentPath'])->canonicalize()->split()[1];

?>
<!DOCTYPE html>
<html lang="<?=isset($vars['htmlLang']) ? $vars['htmlLang'] : $vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <title><?=$vars['pageTitle']?></title>

  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon">

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>

  <!-- Code表記 -->
  <script>
    SyntaxHighlighter = {
      src: "<?=CLIENT_URI?>/syntaxhighlighter"
    }
  </script>
  <script src="<?=CLIENT_URI?>/OutlineText/load-syntaxhighlighter.js" async></script>

  <!-- 数式表記 -->
  <script src="<?=CLIENT_URI?>/OutlineText/load-mathjax.js" async></script>

  <?php if (isset($vars['canonialUrl'])):?>
    <link rel="canonical" href="<?=$vars['canonialUrl']?>">
  <?php endif;?>

  <?php if (isset($vars['layerSelector'])): ?>
    <?php foreach ($vars['layerSelector']['layers'] as $layer): ?>
      <link rel="alternate" hreflang="<?=$layer['hreflang']?>" href="<?=$layer['url']?>">
    <?php endforeach; ?>
  <?php endif;?>

  <meta name="content-path" content="<?=isset($vars['contentPath']) ? H($vars['contentPath']) : H($vars['rootContentPath'])?>">
  <meta name="token" content="<?=H(Authenticator::GenerateCsrfToken())?>">
  <meta name="service-uri" content="<?=H(SERVICE_URI)?>">

  <?php if (isset($vars['otpRequired']) && $vars['otpRequired']): ?>
  <meta name="otp" content="<?=H(Authenticator::GenerateOTP(30 * 60))?>">
  <?php endif;?>

  <script type="text/javascript" src="<?=CLIENT_URI?>/ContentsViewer/ContentsViewer.js" defer></script>
  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/style.css">
  <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/styles/base.css">
  <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/styles/print.css" media="print">
  <link rel="preload" href="<?=CLIENT_URI?>/ContentsViewer/styles/icon.css" as="style" onload="this.rel='stylesheet'">
  
  <?php if (isset($vars['additionalHeadScript'])): ?>
    <?=$vars['additionalHeadScript']?>
  <?php endif;?>

  <meta property="og:title" content="<?=$vars['pageTitle']?>">
  <meta property="og:description" content="<?=CVUtils\MakeOgpDescription($vars['contentSummary'])?>">
  <meta property="og:image" content="<?=(empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . CLIENT_URI . '/Common/ogp-image.png'?>">
  <meta name="twitter:card" content="summary">
  
  <link rel="preload" href="<?=$pluginRootURI . '/css'?>" as="style" onload="this.rel='stylesheet'">
  <script type="text/javascript" src="<?=$pluginRootURI . '/js'?>" defer></script>
  <!--HACK: The script must run after css link tag to prevent css transition on page load in chrome. This is chrome bug. -->
  <script>console.log("This message is needed to prevent css transition on page load in chrome.")</script>
</head>

<body>
  <?=CVUtils\CreateHeaderArea($vars['rootContentPath'], $rootDirectory, true, !$vars['isPublic']);?>

  <div class='menu-open-button-wrapper'>
    <input type="checkbox" href="#" class="menu-open" name="menu-open" id="menu-open"
      onchange="ContentsViewer.onChangeMenuOpen(this)">
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
    <?=Localization\Localize('outline', 'Outline')?>
    <nav class='navi'><div style='margin-left: 1em'><?=Localization\Localize('noOutline', 'There is no Outline.')?></div></nav>
    <?php if (isset($vars['addPlainTextLink']) && $vars['addPlainTextLink']): ?>
    <a href="?plainText" class="show-sourcecode"><?=Localization\Localize('viewTheSourceCodeOfThisPage', 'View the Source Code of this page')?></a>
    <?php endif;?>
  </div>
  
  <?php if (isset($vars['layerSelector'])): ?>
  <div id='layer-selector'>
    <button onclick="ContentsViewer.onClickLayerSelector(this, event)"><?=$vars['layerSelector']['selectedLayer']?></button>
    <ul>
      <?php foreach ($vars['layerSelector']['layers'] as $layer): ?>
      <li <?=$layer['selected'] ? 'selected' : ''?>>
        <a href="<?=$layer['url']?>"><?=$layer['name']?></a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif;?>

  <div id='center-column'>
    <?php if (isset($vars['leftPageTabs']) || isset($vars['rightPageTabs'])): ?>
    <div id='page-tabs'>
      <?php if (isset($vars['leftPageTabs'])): ?>
      <div class='vector-tabs left'>
        <ul>
          <?php foreach ($vars['leftPageTabs'] as $tab): ?>
          <li <?=$tab['selected'] ? "class='selected'" : ''?>><?=$tab['innerHTML']?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif;?>
      <?php if (isset($vars['rightPageTabs'])): ?>
      <div class='vector-tabs right'>
        <ul>
          <?php foreach ($vars['rightPageTabs'] as $tab): ?>
          <li <?=$tab['selected'] ? "class='selected'" : ''?>><?=$tab['innerHTML']?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif;?>
    </div>
    <?php endif;?>
    <main id="main">
      <article>
        <div id="page-heading">
          <?=$breadcrumbList?>
          <h1 id="first-heading"><?=$vars['pageHeading']['title']?></h1>
        </div>
        <?php if (isset($vars['fileDate'])): ?>
        <div class="file-date muted">
          <?php if (is_int($vars['fileDate']['createdTime'])): ?>
            <div>
              <span><?=Localization\Localize('publishedDate', 'Published')?>:</span>
              <time><?=date("Y-m-d", $vars['fileDate']['createdTime'])?></time>
            </div>
          <?php endif;?>
          <?php if (is_int($vars['fileDate']['modifiedTime'])): ?>
            <div>
              <span><?=Localization\Localize('modifiedDate', 'Modified')?>:</span>
              <time><?=date("Y-m-d", $vars['fileDate']['modifiedTime'])?></time>
            </div>
          <?php endif;?>
        </div>
        <?php endif;?>

        <?php if (isset($vars['tagline'])): ?>
        <ul class="tagline">
          <?php if (isset($vars['tagline']['tags'])): ?>
          <?php foreach ($vars['tagline']['tags'] as $tag): ?>
          <li><a href='<?=CVUtils\CreateTagMapHREF([[$tag]], $rootDirectory, $vars['layerName'])?>'><?=$tag?></a></li>
          <?php endforeach; ?>
          <?php endif;?>
          <?php if (isset($vars['tagline']['suggestedTags'])): ?>
          <?php foreach ($vars['tagline']['suggestedTags'] as $tag): ?>
          <li class="outline"><a href='<?=CVUtils\CreateTagMapHREF([[$tag]], $rootDirectory, $vars['layerName'])?>'><?=$tag?></a></li>
          <?php endforeach; ?>
          <?php endif;?>
        </ul>
        <?php endif;?>

        <div id="content-summary" class="summary">
          <?=$vars['contentSummary']?>
          <?php if (isset($vars['recentContents']) && !empty($vars['recentContents'])): ?>
          <?=CVUtils\CreateRecentList($vars['recentContents'])?>
          <?php endif;?>
          <?php if (isset($vars['tagList']) && !empty($vars['tagList'])): ?>
          <h3><?=Localization\Localize('tagmap', 'TagMap')?></h3>
          <?=CVUtils\CreateTagListElement($vars['tagList'], $rootDirectory, $vars['layerName'], [], isset($vars['addMoreTag']) && $vars['addMoreTag'])?>
          <?php endif;?>
        </div>

        <div id="doc-outline-embeded" class="accbox">
          <input type="checkbox" id="toggle-doc-outline" class="cssacc" autocomplete="off">
          <div class="nav-title"><?=Localization\Localize('outline', 'Outline')?></div>
          <div class="nav-wrapper accshow"></div>
          <label for="toggle-doc-outline" role="button" title="<?=Localization\Localize('expandCollapseContents', 'Expand/collapse contents')?>"><div class="icon"></div></label>
        </div>
        
        <div id="content-body"><?=$vars['contentBody']?></div>

        <div id="child-list">
          <ul class="child-list">
            <?php foreach ($vars['childList'] as $child): ?>
            <li><div>
              <div class='child-title'>
                <a href='<?=$child['url']?>'><?=$child['title']?></a>
              </div>
              <div class='child-summary'><?=$child['summary']?></div>
            </div></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div id='printfooter'>
          <?php if (isset($vars['canonialUrl'])):?>
            <?=Localization\Localize('retrievedFrom', 'Retrieved from "{0}"', $vars['canonialUrl'])?>
          <?php else:?>
            <?=Localization\Localize('retrievedFrom', 'Retrieved from "{0}"', (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"])?>
          <?php endif;?>
        </div>
      </article>
      <div class="content-link-container clear-fix">
        <?php if (isset($vars['leftContent'])): ?>
        <a class="left content-link" href="<?=$vars['leftContent']['url']?>">
          <svg viewBox="0 0 48 48">
            <path d="M30.83 32.67l-9.17-9.17 9.17-9.17L28 11.5l-12 12 12 12z"></path>
          </svg>
          <span><?=$vars['leftContent']['title']?></span>
        </a>
        <?php endif;?>
        <?php if (isset($vars['rightContent'])): ?>
        <a class="right content-link" href="<?=$vars['rightContent']['url']?>">
          <span><?=$vars['rightContent']['title']?></span>
          <svg viewBox="0 0 48 48">
            <path d="M17.17 32.92l9.17-9.17-9.17-9.17L20 11.75l12 12-12 12z"></path>
          </svg>
        </a>
        <?php endif;?>
      </div>
      <div id='main-footer'>
        <div id='main-footer-responsive'>
          <?php if (isset($vars['addPlainTextLink']) && $vars['addPlainTextLink']): ?>
          <a href="?plainText"><?=Localization\Localize('viewTheSourceCodeOfThisPage', 'View the Source Code of this page')?></a>
          <?php endif;?>
        </div>
        <div style='float: right'><?=$breadcrumbList?><span><?=$vars['pageHeading']['title']?></span></div>
        <div style='clear: right'></div>
        <?=$vars['mainFooterHTML'] ?? ''?>
      </div>
    </main>
    <?php if (isset($vars['pageBottomHTML'])):?>
    <div id='page-bottom'><?=$vars['pageBottomHTML']?></div>
    <?php endif;?>
    <footer id='footer'>
      <ul id='footer-info'>
        <li id='footer-info-editlink'>
          <a href='<?=ROOT_URI?>/login' target='FileManager'>Manage</a>
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

  <div id='sitemask' onclick='ContentsViewer.onClickSitemask()' role='button' aria-label='<?=Localization\Localize('close', 'Close')?>'></div>
  <?=CVUtils\CreateSearchOverlay()?>

  <?php if (count($vars['warningMessages']) > 0): ?>
  <div id="warning-message-box">
    <button onclick='ContentsViewer.closeWarningMessageBox()'><div class='icon times-icon'></div></button>
    <ul>
      <?php foreach ($vars['warningMessages'] as $message): ?>
      <li><?=$message?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif;?>
</body>

</html>