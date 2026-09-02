<?php

// Tag-map JSON API. Response shape (see TagmapQuery\RESPONSE_SCHEMA).
//
// Request: contentPath, tagPath, offset, limit, scope
//   scope=direct   (default) contents carrying no unselected tag
//   scope=children contents living inside the child groups
//   scope=all      the whole selection
//
//   coTags:        the child GROUPS: [{tag, tags[], count, orCount, total}].
//                  Tags covering an identical set of contents are merged into
//                  one group; `tag` is its name and OR segment ("A,B"), and
//                  `tags` is what to navigate to. count = how many contents
//                  of the CURRENT selection carry it — a present-tense fact,
//                  not a prediction: the next view also admits name matches,
//                  so its total is >= count. orCount = size after OR-adding
//                  the group to the last segment. total = the group's global
//                  reach (the union of its tags).
//                  NOTE: the counts do NOT sum to the selection size. A
//                  content carrying two groups is counted in both; use
//                  stats.childContents for the distinct figure.
//   suggestedTags: [{tag, count, orCount, total, score}] — similar tag names.
//                  count may legitimately be 0 (a similar NAME sharing no
//                  content); do not substitute another field for it.
//   chipTags:      [{tag, count, total}] — last segment only, parent-based.
//   contents:      one page; `scope` echoes which population it is drawn
//                  from. items[]: {title, parentTitle, url, summary,
//                  suggested, score, tags}. `suggested` = matched by name
//                  similarity rather than by a tag, and must be marked.
//   stats:         totalContents (name matches included), explicitContents
//                  (tagged only — the difference explains why a drill-down
//                  can grow), directContents (under the selection itself),
//                  childContents (distinct contents inside the child groups;
//                  a client shows them in place when this is small instead of
//                  making the reader enter a group to find one item), orBase
//                  (the name-blind size orCount is measured from, so
//                  orCount - orBase is a non-negative delta; null at the
//                  root), generatedInMs.
//
// No coordinates: layout is a presentation concern and never leaves the
// client. A client must label every number with the field it came from and
// must not fall back to a different field when one is missing.

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
// Which contents to list. Defaults to the map's own population.
$scope = TagmapQuery\normalizeScope($_POST['scope'] ?? null);

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
    $scope,
    $offset,
    $limit
);
