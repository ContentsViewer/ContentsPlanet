<?php

require_once(MODULE_DIR . "/ContentDatabase.php");
require_once(MODULE_DIR . "/ContentDatabaseContext.php");
require_once(MODULE_DIR . "/ContentDatabaseControls.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Stopwatch.php");
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/TagmapQuery.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;

/**
 * Tag-map shell: serves the mount point and the inline initial state for the
 * TagMap SPA (Client/TagMap/TagMap.js). All query logic lives in
 * Module/TagmapQuery.php, shared with Service/tagmap-service.php; further
 * navigation happens client-side against that service.
 *
 * The former server-rendered implementation is gone; its one load-bearing
 * idea, the "non" group of contents belonging to the selection itself rather
 * than to any child tag, now lives in TagmapQuery as stats.directContents
 * and the `direct` request flag.
 */

$vars['warningMessages'] = [];


// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

if (isset($_GET['layer'])) {
    $vars['layerName'] = $_GET['layer'];
}

$layerSuffix = DBControls\GetLayerSuffix($vars['layerName']);
$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . $layerSuffix;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);
$dbContext = new ContentDatabaseContext($vars['rootContentPath']);

if (
    !DBControls\IsValidLayerName($vars['layerName'])
    || ContentPathUtils::RealPath($dbContext->metaFileName) === false
) {
    // 無効なレイヤー名, メタファイルがないとき
    // 存在しないlayer名を見ている

    $vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

$dbContext->LoadMetadata();
$tag2path = $dbContext->metadata->data['tag2path'] ?? [];


// layerの再設定
$out = CVUtils\UpdateLayerNameAndResetLocalization($vars['rootContentPath'], $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];


// パスの仕様
// TagMap/TagA/TagB,TagC/TagD
//   TagA -> (TagB, TagC) -> TagD

// Canonicalize the tag path: unknown tags dropped, per-segment dedupe and
// natural-order sort. Non-canonical URLs (including the former unknown-tag
// case) get a permanent redirect to the canonical one, collapsing all
// orderings of a combination into a single URL.
$rawTagPath = implode('/', array_slice(explode('/', $vars['subURI']), 3));
$canonicalized = TagmapQuery\canonicalize(
    TagmapQuery\parseTagPath($rawTagPath),
    $tag2path,
    $rawTagPath
);
$tagPathParts = $canonicalized['parts'];

if ($canonicalized['changed']) {
    $relocatedURL = CVUtils\CreateTagMapHREF($tagPathParts, $vars['rootDirectory'], $vars['layerName']);
    header('Location: ' . $relocatedURL, true, 301);
    exit();
}

// Over-cap requests get a bare 404: the crawlable URL space is finite by
// design, and rendering the full error page here would defeat that purpose.
if (!TagmapQuery\withinCaps($tagPathParts)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit();
}

// ここまでで, 各タグ名はtag2path内にあることが保証されている

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") .
    $_SERVER["HTTP_HOST"] . $vars['subURI'] . '?layer=' . $vars['layerName'];
$vars['htmlLang'] = $vars['layerName'];
$vars['rootChildContents'] = $dbContext->GetRootChildContens();

// タイトルの設定
if (empty($tagPathParts)) {
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap');
} else {
    $vars['pageTitle'] = Localization\Localize('tagmap', 'TagMap') . ': ';
    $i = count($tagPathParts) - 1;
    for ($c = 0; $i >= 0 && $c < 2; $i--, $c++) {
        $vars['pageTitle'] .= implode(', ', $tagPathParts[$i]) . ' | ';
    }
    $vars['pageTitle'] = substr($vars['pageTitle'], 0, -3);
}

// Initial state: the exact API response for this URL (page 0), inlined so
// the SPA renders without a first roundtrip. Shares the cache with
// Service/tagmap-service.php — either one warms it for the other.
// Every '<' is JSON-escaped (to the 6-char sequence backslash-u003C) so a
// literal '</script>' inside data cannot break out of the inline element.
// Buffered: this runs before the doctype, and index.php's error handler
// echoes its diagnostics. Anything printed here would land in front of
// <!doctype html> and put the document into quirks mode, which breaks a
// template built on height:100% / position:fixed. Log it instead.
ob_start();
$initialStateJson = TagmapQuery\responseJson(
    $dbContext,
    $vars['rootDirectory'],
    $vars['layerName'],
    $layerSuffix,
    $tagPathParts,
    TagmapQuery\SCOPE_DIRECT,   // the population the map draws
    0,
    20
);
$strayOutput = ob_get_clean();
if ($strayOutput !== '') {
    logger()->error("tag-viewer: output before doctype was discarded:\n" . $strayOutput);
}
$vars['tagmapInitialStateJson'] = str_replace('<', chr(0x5C) . 'u003C', $initialStateJson);


// ビルド時間計測 終了
// tagmap-page.php renders no footer, so there is no pageBuildReport to fill
// in; the slow-page warning below is what this measurement is for.
$stopwatch->Stop();

if ($stopwatch->Elapsed() > 1.5) {
    logger()->warning(
        "Performance Note:\n" .
            "  Page: tag-viewer\n" .
            "  Process Time: " . $stopwatch->Elapsed() * 1000 . " ms\n" .
            "--- Tag Path ---\n" .
            print_r($tagPathParts, true) .
            "----------------"
    );
}

$vars['metaRobots'] = 'noindex, follow';
require(FRONTEND_DIR . '/tagmap-page.php');
