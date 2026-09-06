<?php

/**
 * Full-bleed page template for the TagMap SPA (nested-circle exploration).
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
 * rootContentPath, rootChildContents, isPublic, tagmapInitialStateJson,
 * warningMessages.
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

  <title><?= H($vars['pageTitle']) ?></title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon">

  <?php if (isset($vars['canonialUrl'])) : ?>
    <link rel="canonical" href="<?= H($vars['canonialUrl']) ?>">
  <?php endif; ?>

  <meta name="content-path" content="<?= H($vars['rootContentPath']) ?>">
  <meta name="token" content="<?= H(authenticator()->generateCsrfToken()) ?>">
  <meta name="service-uri" content="<?= H(SERVICE_URI) ?>">

  <?php // The client mirrors these to disable actions the server would reject.
        // Published so raising TAGMAP_MAX_* cannot leave the two out of step. ?>
  <meta name="tagmap-max-depth" content="<?= H((string)TagmapQuery\maxDepth()) ?>">
  <meta name="tagmap-max-width" content="<?= H((string)TagmapQuery\maxWidth()) ?>">

  <meta property="og:title" content="<?= H($vars['pageTitle']) ?>">
  <meta property="og:image" content="<?= (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . CLIENT_URI . '/Common/ogp-image.png' ?>">
  <meta name="twitter:card" content="summary">

  <?php if (isset($vars['metaRobots'])) : ?>
    <meta name="robots" content="<?= H($vars['metaRobots']) ?>">
  <?php endif; ?>

  <?php
    // Cache-busting version query, applied to every asset this page owns:
    // without it a browser's heuristic HTTP cache can keep serving a stale
    // script after a deploy, which breaks the SPA in hard-to-diagnose ways.
    // A mismatched pair is the worst case -- ContentsViewer.js and
    // ThemeChanger.js are read by TagMap.js (the 428 retry path and
    // onChangeThemeCallbacks), so they must be versioned too.
    $v = function (string $relativePath): string {
        $time = filemtime(CLIENT_DIR . $relativePath);
        return $time === false ? '' : '?v=' . $time;
    };
  ?>
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css<?= $v('/Common/css/base.css') ?>">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/base.css<?= $v('/ContentsViewer/styles/base.css') ?>">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/ContentsViewer/styles/icon.css<?= $v('/ContentsViewer/styles/icon.css') ?>" media="print" onload="this.media='all'; this.onload=null;">
  <link rel="stylesheet" href="<?= CLIENT_URI ?>/TagMap/tagmap.css<?= $v('/TagMap/tagmap.css') ?>">

  <!-- Synchronous on purpose: sets the theme attribute before first paint. -->
  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js<?= $v('/ThemeChanger/ThemeChanger.js') ?>"></script>

  <script src="<?= CLIENT_URI ?>/AccessGate/access-gate.js<?= $v('/AccessGate/access-gate.js') ?>" defer></script>
  <script src="<?= CLIENT_URI ?>/ContentsViewer/ContentsViewer.js<?= $v('/ContentsViewer/ContentsViewer.js') ?>" defer></script>
  <?php // Pure layout geometry, before TagMap.js which consumes it. Kept as
        // its own file so `node --test` can check the invariants that cannot
        // be eyeballed (determinism, non-overlap, depth-5 precision). ?>
  <script src="<?= CLIENT_URI ?>/TagMap/tagmap-layout.js<?= $v('/TagMap/tagmap-layout.js') ?>" defer></script>
  <script src="<?= CLIENT_URI ?>/TagMap/TagMap.js<?= $v('/TagMap/TagMap.js') ?>" defer></script>
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
