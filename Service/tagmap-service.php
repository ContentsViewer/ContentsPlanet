<?php

// Tag-map JSON API. Response shape (see TagmapQuery\RESPONSE_SCHEMA).
//
// Request: contentPath, tagPath, offset, limit, scope
//   scope=direct   (default) contents carrying no unselected tag
//   scope=children contents living inside the child groups
//   scope=all      the whole selection
//   scope=keys     NOT a view: a lookup. With `keys` (a comma-separated list
//                  of manifest keys) it returns {scope, items[], requested,
//                  withSummary, truncated} and nothing else -- no coTags, no
//                  stats, no selection. The map draws every content of a
//                  level from the membership manifest, which carries
//                  identities and no bodies, so this is how a mark that grew
//                  large enough to deserve a title gets one. Pass
//                  `fields=full` for summaries: decoding a body costs ~8.6 ms
//                  and nothing caches it, so it is opt-in and meant for the
//                  handful of marks at maximum zoom.
//                  A key is 48 bits, so two contents CAN collide. Both items
//                  are returned; a client receiving two for one key must drop
//                  it rather than pick, since the wrong article's title is
//                  worse than none.
//
//   coTags:        the child GROUPS: [{tag, tags[], count, orCount, total,
//                  reach}]. Tags covering an identical set of contents are
//                  merged into one group; `tag` is its name and OR segment
//                  ("A,B"), and `tags` is what to navigate to.
//                  count = how many contents of the CURRENT selection carry
//                  it — a present-tense fact about this view.
//                  reach = how many the NEXT view has, i.e. entering this
//                  group yields exactly `reach` contents, name matches
//                  included. reach >= count, and on the reference corpus
//                  they agree for 89% of groups but differ by up to 4x.
//                  Size a group by reach, not by count: a circle drawn from
//                  count is not the size of the view behind it.
//                  orCount = size after OR-adding the group to the last
//                  segment. total = the group's global reach (the union of
//                  its tags), selection-independent.
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

// scope=keys is a lookup, not a view: it resolves manifest keys to titles for
// marks the client is already drawing, so it needs no selection, no
// co-occurrence and no cache entry -- and it must not be given the shape of a
// view, or a client could mistake it for one.
if ($scope === TagmapQuery\SCOPE_KEYS) {
    $keys = preg_split('/[,\s]+/', (string)($_POST['keys'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    // Summaries are opt-in: decoding a body to summarise it is the expensive
    // part (8.6 ms each, uncached) and only the handful of marks at maximum
    // zoom have room to show one.
    $withSummary = ($_POST['fields'] ?? '') === 'full';
    ServiceUtils\SendResponseAndExit(
        ['scope' => TagmapQuery\SCOPE_KEYS]
        + TagmapQuery\contentsByKeys($dbContext, $keys ?: [], $withSummary)
    );
}

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
