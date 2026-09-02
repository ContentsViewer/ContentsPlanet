<?php

/**
 * Full-bleed page template for the TagMap SPA (planetary exploration UI).
 *
 * A slim sibling of viewer.php: it keeps only the shared site header, the
 * search overlay and the theme system; there are no columns, tabs or footer —
 * everything below the header is the TagMap canvas.
 *
 * ContentsViewer.js compatibility (audited; no JS changes needed):
 *  - Hard init dependencies are satisfied: #header (CreateHeaderArea) and
 *    the search overlay elements (CreateSearchOverlay).
 *  - Absent layout elements (#left-column, #right-column, #content-body,
 *    #doc-outline-embeded, #related-results, ...) are null-guarded in its
 *    setup functions.
 *  - Unguarded handlers (onChangeMenuOpen, onClickSitemask, layer selector)
 *    are unreachable: this template renders no hamburger checkbox, no
 *    layer selector, and #sitemask without an onclick attribute.
 *
 * Required $vars: pageTitle, htmlLang, language, canonialUrl, metaRobots,
 * rootContentPath, rootChildContents, isPublic, contentsFolder,
 * tagmapInitialStateJson, warningMessages.
 */

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/PathUtils.php");
require_once(MODULE_DIR . "/PluginLoader.php");

use ContentsViewerUtils as CVUtils;
use PathUtils\Path;

$rootDirectory = explode('/', Path::from($vars['rootContentPath'])->canonicalize()->split()[1])[0];

?>
<!DOCTYPE html>
<html lang="<?= isset($vars['htmlLang']) ? $vars['htmlLang'] : $vars['language'] ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <?= PluginLoader::getCommonHead() ?>

  <title><?= $vars['pageTitle'] ?></title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon">

  <?php if (isset($vars['canonialUrl'])) : ?>
    <link rel="canonical" href="<?= $vars['canonialUrl'] ?>">
  <?php endif; ?>

  <meta name="content-path" content="<?= H($vars['rootContentPath']) ?>">
  <meta name="token" content="<?= H(authenticator()->generateCsrfToken()) ?>">
  <meta name="service-uri" content="<?= H(SERVICE_URI) ?>">

  <meta property="og:title" content="<?= $vars['pageTitle'] ?>">
  <meta property="og:image" content="<?= (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . CLIENT_URI . '/Common/ogp-image.png' ?>">
  <meta name="twitter:card" content="summary">

  <?php if (isset($vars['metaRobots'])) : ?>
    <meta name="robots" content="<?= H($vars['metaRobots']) ?>">
  <?php endif; ?>

  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/base.css">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/icon.css" media="print" onload="this.media='all'; this.onload=null;">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/TagMap/tagmap.css">

  <!-- Synchronous on purpose: sets the theme attribute before first paint. -->
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>

  <script src="<?= CLIENT_URI ?>/AccessGate/access-gate.js" defer></script>
  <script src="<?= CLIENT_URI ?>/ContentsViewer/ContentsViewer.js" defer></script>
  <script src="<?= CLIENT_URI ?>/TagMap/TagMap.js" defer></script>
</head>

<body class="tagmap-page">
  <?= CVUtils\CreateHeaderArea($vars['rootContentPath'], $rootDirectory, $vars['rootChildContents'], !$vars['isPublic']); ?>

  <div id="tagmap-app"></div>
  <script type="application/json" id="tagmap-initial-state"><?= $vars['tagmapInitialStateJson'] ?></script>
  <noscript>
    <p class="tagmap-noscript"><?= Localization\Localize('tag-viewer.requiresJs', 'JavaScript is required to view the tag map.') ?></p>
  </noscript>

  <?php // No onclick: ContentsViewer.onClickSitemask() would dereference the
        // absent menu elements; the mask can never become visible here. ?>
  <div id='sitemask'></div>
  <?= CVUtils\CreateSearchOverlay() ?>

  <?php if (count($vars['warningMessages']) > 0) : ?>
    <div id="warning-message-box">
      <button onclick='ContentsViewer.closeWarningMessageBox()'>
        <div class='icon times-icon'></div>
      </button>
      <ul>
        <?php foreach ($vars['warningMessages'] as $message) : ?>
          <li><?= $message ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</body>

</html>
