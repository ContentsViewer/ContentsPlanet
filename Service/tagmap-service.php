<?php

// Tag-map JSON API (v2 response shape):
//   coTags:        [{tag, count, orCount}]  — count = AND drill-down size
//                  (intersection with the current selection), orCount =
//                  size after OR-adding the tag to the last segment.
//   suggestedTags: [{tag, count, orCount, score}] — lexically similar tags.
//   contents/breadcrumb/chipTags/stats: unchanged from v1.

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");
require_once(MODULE_DIR . '/Logger.php');
require_once(MODULE_DIR . '/ServiceUtils.php');
require_once(MODULE_DIR . '/ErrorHandling.php');
require_once(MODULE_DIR . '/AccessGate.php');
require_once(MODULE_DIR . '/PathUtils.php');

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequirePostMethod();
ServiceUtils\RequireParams('contentPath', 'tagPath');

// Gate first, before any filesystem or database work: this endpoint serves
// the tag-map SPA and is a scraping target. Fail-open when unconfigured.
if (!AccessGate::verifyToken($_COOKIE[AccessGate::COOKIE_NAME] ?? '', $_SERVER['REMOTE_ADDR'] ?? '')) {
    http_response_code(428);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'challenge_required']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

try {
    $contentPath = PathUtils\canonicalize($_POST['contentPath']);
} catch (Exception $error) {
    ServiceUtils\SendErrorResponseAndExit('Invalid Parameter');
}
// Restore the conventional './'-relative form: canonicalize strips it, but
// GetTopDirectory and the content database expect it.
if (!str_starts_with($contentPath, './')) {
    $contentPath = './' . ltrim($contentPath, '/');
}

ServiceUtils\ValidateAccessPrivilege($contentPath);

require_once(MODULE_DIR . '/ContentDatabase.php');
require_once(MODULE_DIR . '/ContentDatabaseContext.php');
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/TagmapQuery.php');

use ContentDatabaseControls as DBControls;

$dbContext = new ContentDatabaseContext($contentPath);
if (ContentPathUtils::RealPath($dbContext->metaFileName) === false) {
    ServiceUtils\SendErrorResponseAndExit('Invalid Parameter');
}

$rootDirectory = substr(GetTopDirectory($contentPath), 1);
$layerName = DBControls\GetRelatedLayerName($contentPath);
if ($layerName === false) $layerName = DEFAULT_LAYER_NAME;
$layerSuffix = DBControls\GetLayerSuffix($layerName);

$offset = max(0, (int)($_POST['offset'] ?? 0));
$limit = min(20, max(1, (int)($_POST['limit'] ?? 20)));

$dbContext->LoadMetadata();
$tag2path = $dbContext->metadata->data['tag2path'] ?? [];

$tagPath = $_POST['tagPath'];
$parsed = TagmapQuery\parseTagPath($tagPath);
$canonicalized = TagmapQuery\canonicalize($parsed, $tag2path, $tagPath);
if ($canonicalized['changed']) {
    ServiceUtils\SendResponseAndExit([
        'error' => 'canonical_mismatch',
        'canonical' => $canonicalized['canonical'] === '' ? '' : '/' . $canonicalized['canonical'],
    ]);
}
if (!TagmapQuery\withinCaps($canonicalized['parts'])) {
    ServiceUtils\SendResponseAndExit([
        'error' => 'limit_exceeded',
        'max' => ['depth' => TagmapQuery\maxDepth(), 'width' => TagmapQuery\maxWidth()],
    ]);
}

echo TagmapQuery\responseJson(
    $dbContext,
    $rootDirectory,
    $layerName,
    $layerSuffix,
    $canonicalized['parts'],
    $offset,
    $limit
);
