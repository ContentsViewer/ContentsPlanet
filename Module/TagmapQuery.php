<?php

namespace TagmapQuery;

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");
require_once(MODULE_DIR . "/ContentDatabase.php");
require_once(MODULE_DIR . "/ContentDatabaseContext.php");
require_once(MODULE_DIR . "/SearchEngine.php");
require_once(MODULE_DIR . "/CacheStore.php");
require_once(MODULE_DIR . "/Logger.php");

/**
 * Tagmap query logic shared by the tag-viewer shell (Frontend/tag-viewer.php)
 * and the JSON API (Service/tagmap-service.php).
 *
 * Tag path semantics (unchanged from the former server-rendered tag-viewer):
 * a tag path like `A,B/C` selects contents by OR within a comma segment and
 * AND (refinement) across slash segments. Selected-path values are `true`
 * for explicit tag matches and a float score for name-only (suggested)
 * matches — see select() for why the operand order decides that.
 */

function maxDepth(): int
{
    return defined('TAGMAP_MAX_DEPTH') ? (int)TAGMAP_MAX_DEPTH : 5;
}

function maxWidth(): int
{
    return defined('TAGMAP_MAX_WIDTH') ? (int)TAGMAP_MAX_WIDTH : 5;
}

/**
 * Parses a tag path string into segments of tags.
 * `'/A,B/C'` (or `'A,B/C'`) -> `[['A', 'B'], ['C']]`; `''`/`'/'` -> `[]`.
 * Tags are trimmed; empty tags and empty segments are dropped.
 *
 * @return array<int, string[]>
 */
function parseTagPath(string $tagPath): array
{
    $tagPath = trim($tagPath, '/');
    if ($tagPath === '') {
        return [];
    }
    $parts = [];
    foreach (explode('/', $tagPath) as $segment) {
        $tags = array_values(array_filter(
            array_map('trim', explode(',', $segment)),
            fn(string $tag) => $tag !== ''
        ));
        if ($tags !== []) {
            $parts[] = $tags;
        }
    }
    return $parts;
}

/**
 * Canonical form of a tag path: per segment, unknown tags are dropped, exact
 * duplicates removed and the rest sorted ascending with the same collation
 * as the tag list display (natural order, case-insensitive); segments left
 * empty are dropped. Segments are not deduplicated across each other
 * (repeats legitimately narrow fuzzy-augmented selections).
 *
 * @param array<int, string[]> $parts
 * @return array{parts: array<int, string[]>, canonical: string, changed: bool}
 */
function canonicalize(array $parts, array $tag2path, string $originalTagPath): array
{
    $canonicalParts = [];
    foreach ($parts as $segment) {
        $tags = array_values(array_unique(array_filter(
            $segment,
            fn(string $tag) => array_key_exists($tag, $tag2path)
        )));
        usort($tags, 'strnatcasecmp');
        if ($tags !== []) {
            $canonicalParts[] = $tags;
        }
    }
    $canonical = canonicalTagPath($canonicalParts);
    return [
        'parts' => $canonicalParts,
        'canonical' => $canonical,
        'changed' => trim($originalTagPath, '/') !== $canonical,
    ];
}

/**
 * Inverse of parseTagPath, without percent-encoding: `[['A','B'],['C']]` ->
 * `'A,B/C'`; `[]` -> `''`. (URL building goes through CreateTagMapHREF.)
 *
 * @param array<int, string[]> $parts
 */
function canonicalTagPath(array $parts): string
{
    return implode('/', array_map(fn(array $segment) => implode(',', $segment), $parts));
}

/** @param array<int, string[]> $parts */
function withinCaps(array $parts): bool
{
    if (count($parts) > maxDepth()) {
        return false;
    }
    foreach ($parts as $segment) {
        if (count($segment) > maxWidth()) {
            return false;
        }
    }
    return true;
}

/**
 * Selection pipeline: refines the full-content set segment by segment.
 * Requires `$dbContext->LoadMetadata()` and `$dbContext->LoadIndex()`.
 *
 * @param array<int, string[]> $parts
 * @return array<int, array{selectors: string[], selected: array<string, bool|float>}>
 */
function select(\ContentDatabaseContext $dbContext, array $parts): array
{
    $tag2path = $dbContext->metadata->data['tag2path'] ?? [];
    $path2tag = $dbContext->metadata->data['path2tag'] ?? [];

    $eachSelected = [];
    $source = null;
    foreach ($parts as $part) {
        // Tagged first: PHP's array `+` keeps the LEFT operand's keys, so
        // whichever side comes first decides the value of a path found by
        // both. The content index includes each content's tags, so searching
        // a tag name fuzzy-matches nearly every content already carrying it
        // — with the operands the other way round (as the former
        // server-rendered view had them) the float score won and virtually
        // every content was classified as a name match. Explicit membership
        // must win, so `true` means tagged and a float means name-only.
        $selected = selectTaggedPaths($source, $part, $tag2path, $path2tag)
            + findTagSuggestedPaths($source, $part, $dbContext->index);
        $eachSelected[] = ['selectors' => $part, 'selected' => $selected];
        $source = $selected;
    }
    return $eachSelected;
}

/**
 * Collapses tags that cover exactly the same contents into one entry.
 *
 * Two tags that always appear together over this selection carry no
 * distinguishing information: shown separately they are several circles the
 * reader cannot tell apart, and the same content is counted once per tag so
 * the numbers stop adding up. Merged, the group is a single OR segment
 * (`A,B`) that selects precisely the set the reader saw.
 *
 * This is the former server-rendered view's rule (`array_keys($groups,
 * $paths)` in its createTagGroupsElement), restated as data rather than
 * markup so the map can use it too.
 *
 * @param array<string, array<string, mixed>> $tagPaths tag => set of paths
 * @return array<string, array{tags: string[], count: int}> keyed by 'A,B'
 */
function mergeIdenticalTags(array $tagPaths): array
{
    // Group by the identity of the path set. Sorting the paths makes the key
    // independent of the order they were encountered in.
    $byShape = [];
    foreach ($tagPaths as $tag => $paths) {
        $shape = array_keys($paths);
        sort($shape);
        $byShape[md5(implode("\0", $shape))][] = $tag;
    }

    $merged = [];
    foreach ($byShape as $tags) {
        usort($tags, 'strnatcasecmp');
        // The key doubles as the OR segment, so it must already be in the
        // canonical order the URL layer expects (same collation).
        $merged[implode(',', $tags)] = [
            'tags' => $tags,
            'count' => count($tagPaths[$tags[0]]),
        ];
    }
    return $merged;
}

/**
 * Co-occurrence data around the CURRENT selection.
 * coTags:   the child groups. Tags on any path of the current selection
 *           (excluding already-selected ones), with tags covering an
 *           IDENTICAL set of contents merged into one group (see
 *           mergeIdenticalTags); a group is keyed by, and navigable as, the
 *           OR segment `A,B`.
 *             tags    = the tags in the group
 *             count   = |current selection ∩ tag2path[tag]|, identical for
 *                       every tag in the group
 *             orCount = |parentSource ∩ (lastPart ∪ tags)|  (OR-add result)
 *           Groups with count 0 never appear, so a drill-down can no longer
 *           dead-end at zero contents.
 *
 *           `count` is a statement about the CURRENT set ("N of the contents
 *           here carry this tag"), not a prediction of the next view: it is a
 *           pure tag intersection, while select() also admits fuzzy matches
 *           (score >= 0.75) once you navigate, so the next total is >= count.
 *           Present it in the present tense; `stats.explicitContents` lets a
 *           client explain the gap. An exact per-tag prediction would need a
 *           fuzzy index search per coTag (up to 200 per response) — too slow.
 *
 *           Root (empty selection): every tag, count = orCount = global.
 * chipTags: per-tag contribution counts for the last segment's tags
 *           (the removable breadcrumb chips; parent-based by design).
 * orBase:   |parentSource ∩ lastPart| — the fuzzy-blind size that orCount is
 *           measured from, so `orCount - orBase` is a non-negative delta.
 *           Comparing orCount against stats.totalContents instead would
 *           suggest that OR-adding a tag shrinks the set, because that total
 *           includes fuzzy matches and orCount does not. Null at the root.
 * directCount:
 *           contents of the current selection carrying no unselected tag, so
 *           they belong under the selection itself rather than to any child
 *           tag. This is the legacy createTagGroups() 'non' group, computed
 *           over the WHOLE selection — a client cannot derive it from a page
 *           of items, nor from coTags (which is capped at MAX_CO_TAGS).
 *
 * @param array<int, array{selectors: string[], selected: array<string, bool|float>}> $eachSelected
 * @param array<int, string[]> $parts
 * @return array{coTags: array<string, array{tags: string[], count: int, orCount: int}>, chipTags: array<string, int>, orBase: int|null, directCount: int}
 */
function coOccurrence(array $eachSelected, array $parts, array $tag2path, array $path2tag): array
{
    $selectedTags = [];
    foreach ($parts as $part) {
        foreach ($part as $tag) {
            $selectedTags[$tag] = true;
        }
    }

    $current = $eachSelected === [] ? null : end($eachSelected)['selected'];
    $parentSource = count($eachSelected) > 1 ? $eachSelected[count($eachSelected) - 2]['selected'] : null;
    $lastPart = $parts === [] ? [] : end($parts);

    // Per candidate tag, WHICH paths of the current selection carry it. The
    // sets (not just their sizes) are what lets identical tags be merged
    // below, and the same pass yields directCount for free.
    $tagPaths = [];
    $directCount = 0;
    if (!is_null($current)) {
        foreach ($current as $path => $_) {
            $hasUnselectedTag = false;
            foreach ($path2tag[$path] ?? [] as $tag => $__) {
                if (!isset($selectedTags[$tag])) {
                    $tagPaths[$tag][$path] = true;
                    $hasUnselectedTag = true;
                }
            }
            if (!$hasUnselectedTag) {
                $directCount++;
            }
        }
    } else {
        foreach ($tag2path as $tag => $paths) {
            if (!isset($selectedTags[$tag])) {
                $tagPaths[$tag] = $paths;
            }
        }
    }

    $coTags = mergeIdenticalTags($tagPaths);
    foreach ($coTags as $key => $group) {
        $coTags[$key]['orCount'] = count(selectTaggedPaths(
            $parentSource,
            array_merge($lastPart, $group['tags']),
            $tag2path,
            $path2tag
        ));
    }
    // Deterministic order: natural-case key order, then stable sort by count
    // desc (PHP sorts are stable since 8.0) — keeps layouts reproducible.
    uksort($coTags, 'strnatcasecmp');
    uasort($coTags, fn(array $a, array $b) => $b['count'] <=> $a['count']);

    $chipTags = [];
    $orBase = null;
    if ($parts !== []) {
        foreach ($lastPart as $tag) {
            $chipTags[$tag] = count(selectTaggedPaths($parentSource, [$tag], $tag2path, $path2tag));
        }
        $orBase = count(selectTaggedPaths($parentSource, $lastPart, $tag2path, $path2tag));
    }

    return [
        'coTags' => $coTags,
        'chipTags' => $chipTags,
        'orBase' => $orBase,
        'directCount' => $directCount,
    ];
}

/**
 * Lexically similar tag suggestions for the last segment (tag names
 * themselves can be near-duplicates, e.g. WSL / WSL2), from the
 * `.index.tagmap` fuzzy index. Rebuilds that index only when stale against
 * `contentsChangedTime` (the single write permitted on this read path).
 * Per suggested tag:
 *   count   = |current selection ∩ tag2path[tag]|  (AND size; may be 0)
 *   orCount = |source ∩ (lastPart ∪ tag)|          (OR-add result size)
 *
 * @param string[] $lastPart
 * @param array<string, true> $selectedTags
 * @param array<string, bool|float>|null $source parent selection (null = all)
 * @param array<string, bool|float>|null $current current selection
 * @return array<string, array{count: int, orCount: int, score: float}>
 */
function suggestedTags(
    \ContentDatabaseContext $dbContext,
    string $rootDirectory,
    string $layerSuffix,
    array $lastPart,
    array $selectedTags,
    ?array $source,
    ?array $current,
    array $tag2path,
    array $path2tag
): array {
    $tagmapIndexFileName = CONTENTS_HOME_DIR . $rootDirectory . '/.index.tagmap' . $layerSuffix;
    $tagMapIndex = new \SearchEngine\Index();
    // When contentsChangedTime is unknown, keep the index we have: rebuilding
    // it on every request is a far worse failure than slightly stale tag-name
    // suggestions. (The response cache resolves the same ambiguity the other
    // way, because there a rebuild costs one request, not one per request.)
    $indexTime = @filemtime($tagmapIndexFileName);
    if (
        !$tagMapIndex->load($tagmapIndexFileName)
        || $indexTime === false
        || $indexTime < ($dbContext->metadata->data['contentsChangedTime'] ?? 0)
    ) {
        $tagMapIndex = new \SearchEngine\Index();
        foreach ($tag2path as $tag => $_) {
            $tagMapIndex->register($tag, $tag);
        }
        $tagMapIndex->apply($tagmapIndexFileName);
    }

    $suggestions = [];
    foreach ($lastPart as $tag) {
        $suggestions = array_merge($suggestions, $tagMapIndex->search($tag));
    }
    foreach ($suggestions as $i => $suggested) {
        if ($suggested['score'] < 0.5 || array_key_exists($suggested['id'], $selectedTags)) {
            unset($suggestions[$i]);
        }
    }
    sortSuggestions($suggestions);

    $result = [];
    foreach ($suggestions as $suggested) {
        $orPaths = selectTaggedPaths(
            $source,
            array_merge($lastPart, [$suggested['id']]),
            $tag2path,
            $path2tag
        );
        if (count($orPaths) > 0) {
            $andPaths = is_null($current)
                ? ($tag2path[$suggested['id']] ?? [])
                : array_intersect_key($current, $tag2path[$suggested['id']] ?? []);
            $result[$suggested['id']] = [
                'count' => count($andPaths),
                'orCount' => count($orPaths),
                'score' => $suggested['score'],
            ];
        }
    }
    return $result;
}

/**
 * One page of the matching contents, in deterministic order: explicit
 * (user-tagged) matches first, then suggested matches by score descending,
 * then by path. Content files are read only for the sliced page.
 * Missing contents are skipped (never pruned from metadata on this path).
 *
 * `$scope` picks the population, because the map draws the two apart:
 *   SCOPE_DIRECT   contents carrying no unselected tag — the legacy
 *                  createTagGroups() 'non' group. These belong to the
 *                  selection itself and are drawn in its free band.
 *   SCOPE_CHILDREN contents that DO carry an unselected tag, i.e. the ones
 *                  living inside the child groups. Requested only when there
 *                  are few of them, so the map can show them in place
 *                  instead of making the reader enter a child to find one
 *                  item (the legacy view's `$expandTagGroups` rule).
 *   SCOPE_ALL      the whole selection, for a plain list.
 *
 * Deciding membership here rather than on the client also fixes two things
 * the client could not: the explicit-before-fuzzy ordering biased a
 * page-local estimate low, and a tag beyond MAX_CO_TAGS is missing from
 * coTags, which made its contents look direct when they are not.
 *
 * @param array<string, bool|float> $selectedPaths
 * @param array<string, true> $selectedTags
 * @return array{items: array<int, array<string, mixed>>, total: int, offset: int, limit: int, hasMore: bool}
 */
function contents(
    \ContentDatabaseContext $dbContext,
    array $selectedPaths,
    array $selectedTags,
    string $scope,
    int $offset,
    int $limit
): array {
    require_once(MODULE_DIR . "/ContentsViewerUtils.php");

    $path2tag = $dbContext->metadata->data['path2tag'] ?? [];

    $paths = array_keys($selectedPaths);
    if ($scope !== SCOPE_ALL) {
        $wantDirect = $scope === SCOPE_DIRECT;
        $paths = array_values(array_filter(
            $paths,
            function (string $path) use ($path2tag, $selectedTags, $wantDirect) {
                $isDirect = array_diff_key($path2tag[$path] ?? [], $selectedTags) === [];
                return $isDirect === $wantDirect;
            }
        ));
    }
    usort($paths, function ($a, $b) use ($selectedPaths) {
        $explicitA = is_bool($selectedPaths[$a]);
        $explicitB = is_bool($selectedPaths[$b]);
        if ($explicitA !== $explicitB) {
            return $explicitA ? -1 : 1;
        }
        if (!$explicitA && $selectedPaths[$a] != $selectedPaths[$b]) {
            return ($selectedPaths[$a] < $selectedPaths[$b]) ? 1 : -1;
        }
        return strcmp($a, $b);
    });

    $total = count($paths);
    $page = array_slice($paths, $offset, $limit);

    $items = [];
    foreach ($page as $path) {
        $content = $dbContext->database->get($path);
        if (!$content) {
            logger()->warning("TagmapQuery: content not found: {$path}");
            continue;
        }
        $parent = $content->parent();
        $text = \ContentsViewerUtils\GetDecodedText($content);
        $suggested = !is_bool($selectedPaths[$path]);
        $items[] = [
            'title' => \NotBlankText([$content->title, basename($content->path)]),
            'parentTitle' => $parent === false ? null : \NotBlankText([$parent->title, basename($parent->path)]),
            'url' => \ContentsViewerUtils\CreateContentHREF($content->path),
            'summary' => $text['summary'],
            // `suggested` means this content matched by name similarity, not
            // by a tag. The UI must mark it: it is not tagged with what the
            // user selected. (path2tag mixes authored with machine-inferred
            // tags and cannot tell them apart, so `tags` claims no
            // authorship — it is only the membership the index recorded.)
            'suggested' => $suggested,
            'score' => $suggested ? $selectedPaths[$path] : null,
            'tags' => array_keys($path2tag[$path] ?? []),
        ];
    }

    return [
        'items' => $items,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'hasMore' => $offset + $limit < $total,
    ];
}

/** Maximum number of coTag groups included in a response. */
const MAX_CO_TAGS = 200;

/** Which contents of the selection a response should list. */
const SCOPE_DIRECT = 'direct';      // carrying no unselected tag
const SCOPE_CHILDREN = 'children';  // living inside the child groups
const SCOPE_ALL = 'all';

/** @return string one of the SCOPE_* constants */
function normalizeScope(?string $scope): string
{
    return in_array($scope, [SCOPE_DIRECT, SCOPE_CHILDREN, SCOPE_ALL], true)
        ? $scope
        : SCOPE_DIRECT;
}

/**
 * Assembles the full response payload used both as the API response and as
 * the shell's inline initial state.
 * Precondition: `$dbContext->LoadMetadata()` has been called and `$parts`
 * is canonical and within caps.
 *
 * @param array<int, string[]> $parts
 * @return array<string, mixed>
 */
function buildResponse(
    \ContentDatabaseContext $dbContext,
    string $rootDirectory,
    string $layerName,
    string $layerSuffix,
    array $parts,
    string $scope,
    int $offset,
    int $limit
): array {
    $started = microtime(true);

    $tag2path = $dbContext->metadata->data['tag2path'] ?? [];
    $path2tag = $dbContext->metadata->data['path2tag'] ?? [];

    $canonical = canonicalTagPath($parts);

    $breadcrumb = [['title' => 'TagMap', 'tagPath' => '']];
    $cumulative = [];
    foreach ($parts as $segment) {
        $cumulative[] = $segment;
        $breadcrumb[] = [
            'title' => implode(', ', $segment),
            'tagPath' => canonicalTagPath($cumulative),
        ];
    }

    $selectedTags = [];
    foreach ($parts as $part) {
        foreach ($part as $tag) {
            $selectedTags[$tag] = true;
        }
    }

    if ($parts === []) {
        $co = coOccurrence([], [], $tag2path, $path2tag);
        $coTags = $co['coTags'];
        $chipTags = [];
        $suggested = [];
        $selectedPaths = [];
    } else {
        $dbContext->LoadIndex();
        $eachSelected = select($dbContext, $parts);
        $co = coOccurrence($eachSelected, $parts, $tag2path, $path2tag);
        $coTags = $co['coTags'];
        $chipTags = $co['chipTags'];

        $source = count($eachSelected) > 1 ? $eachSelected[count($eachSelected) - 2]['selected'] : null;
        $selectedPaths = end($eachSelected)['selected'];
        $suggested = suggestedTags(
            $dbContext,
            $rootDirectory,
            $layerSuffix,
            end($parts),
            $selectedTags,
            $source,
            $selectedPaths,
            $tag2path,
            $path2tag
        );
    }

    // `total` is the tag's global content count, independent of the selection.
    // Whichever of count/total a client encodes as size, its label must name
    // that same number — mixing the two makes circles incomparable.
    $totalOf = fn(string $tag): int => count($tag2path[$tag] ?? []);

    $totalCoTags = count($coTags);
    $coTagList = [];
    foreach (array_slice($coTags, 0, MAX_CO_TAGS, true) as $tag => $counts) {
        // `tag` is the group's name AND its OR segment; `tags` is what to
        // navigate to. `total` is the group's global reach (the union of its
        // members), so it stays comparable with a single-tag group.
        $coTagList[] = [
            'tag' => $tag,
            'tags' => $counts['tags'],
            'count' => $counts['count'],
            'orCount' => $counts['orCount'],
            'total' => count(selectTaggedPaths(null, $counts['tags'], $tag2path, $path2tag)),
        ];
    }
    $chipTagList = [];
    foreach ($chipTags as $tag => $count) {
        $chipTagList[] = ['tag' => $tag, 'count' => $count, 'total' => $totalOf($tag)];
    }
    $suggestedList = [];
    foreach ($suggested as $tag => $desc) {
        $suggestedList[] = [
            'tag' => $tag,
            'count' => $desc['count'],
            'orCount' => $desc['orCount'],
            'score' => $desc['score'],
            'total' => $totalOf($tag),
        ];
    }
    $contents = $parts === []
        ? ['items' => [], 'total' => 0, 'offset' => 0, 'limit' => $limit, 'hasMore' => false]
        : contents($dbContext, $selectedPaths, $selectedTags, $scope, $offset, $limit);

    return [
        'tagPath' => $canonical === '' ? '' : '/' . $canonical,
        'layer' => $layerName,
        'segments' => $parts,
        'scope' => $scope,
        'breadcrumb' => $breadcrumb,
        'coTags' => $coTagList,
        'totalCoTags' => $totalCoTags,
        'chipTags' => $chipTagList,
        'suggestedTags' => $suggestedList,
        'contents' => $contents,
        'stats' => [
            // Every number a client can honestly print about this selection.
            // At the root there is no selection, so the size of the tagged
            // corpus is the only meaningful total (it used to report 0).
            'totalContents' => $parts === [] ? count($path2tag) : count($selectedPaths),
            'explicitContents' => $parts === []
                ? count($path2tag)
                : count(array_filter($selectedPaths, 'is_bool')),
            'directContents' => $co['directCount'],
            // Distinct contents living inside the child groups. A client uses
            // it to decide whether to show them in place instead of making
            // the reader enter a child to find one item; it is NOT the sum of
            // the groups' counts, which double-counts anything carrying two
            // of them.
            'childContents' => $parts === []
                ? 0
                : count($selectedPaths) - $co['directCount'],
            'orBase' => $co['orBase'],
            'generatedInMs' => round((microtime(true) - $started) * 1000, 1),
        ],
    ];
}

/**
 * Response schema version. Bump whenever the response shape OR the meaning
 * of any value in it changes: it is part of the cache key, so old entries
 * become unreachable (and age out via TTL) instead of being served to a
 * client that expects the new fields. A meaning-only change matters just as
 * much — without a bump, a deploy keeps serving numbers computed the old way
 * for up to the full TTL.
 */
const RESPONSE_SCHEMA = 7;

function cacheKey(
    string $rootDirectory,
    string $layerSuffix,
    string $canonicalTagPath,
    string $scope,
    int $offset,
    int $limit
): string {
    // The tag-path part is hashed: percent-encoded multibyte tags would blow
    // up the cache file name length otherwise. Root and layer stay readable
    // for debugging; RESPONSE_SCHEMA isolates older response shapes.
    return 'tagmap-api' . RESPONSE_SCHEMA . '-' . $rootDirectory . $layerSuffix . '-'
        . sha1($canonicalTagPath . '|' . $scope . '|' . $offset . '|' . $limit);
}

/**
 * Cached JSON response, shared by the shell and the API service (same key:
 * either one warms the cache for the other). Valid while nothing under the
 * root has changed (`updatedTime > contentsChangedTime`), at most one day.
 *
 * NOTE: contentsChangedTime only advances during a crawl, and this path just
 * loads metadata. Editing a content nobody opens therefore stays invisible
 * here until the entry expires. The one-day TTL is the real bound.
 *
 * Precondition: `$dbContext->LoadMetadata()` has been called.
 *
 * @param array<int, string[]> $parts canonical, within caps
 */
function responseJson(
    \ContentDatabaseContext $dbContext,
    string $rootDirectory,
    string $layerName,
    string $layerSuffix,
    array $parts,
    string $scope,
    int $offset,
    int $limit
): string {
    // A missing contentsChangedTime means "we cannot tell whether contents
    // changed", which must not be read as "nothing changed" -- that served a
    // day-old response unconditionally. Without the key, skip the cache read.
    $changeTimeKnown = array_key_exists('contentsChangedTime', $dbContext->metadata->data);
    $contentsChangedTime = $changeTimeKnown ? $dbContext->metadata->data['contentsChangedTime'] : 0;
    $key = cacheKey($rootDirectory, $layerSuffix, canonicalTagPath($parts), $scope, $offset, $limit);

    $cache = new \Cache();
    // Probe before connecting: connect() opens with 'c+b' and touches, so an
    // unconditional read would create and stamp a file just to miss on it.
    if ($changeTimeKnown && \CacheStore::exists($key)) {
        $cache->connect($key);
        $cache->lock(LOCK_SH);
        $cache->fetch();
        $cache->unlock();
        $cache->disconnect();
        // Strictly newer: with >=, a content modified in the same second as
        // the write would satisfy the condition and stay hidden for a day.
        if (
            isset($cache->data['json'], $cache->data['updatedTime'])
            && $cache->data['updatedTime'] > $contentsChangedTime
        ) {
            return $cache->data['json'];
        }
    }

    $response = buildResponse(
        $dbContext,
        $rootDirectory,
        $layerName,
        $layerSuffix,
        $parts,
        $scope,
        $offset,
        $limit
    );
    $json = json_encode($response, JSON_UNESCAPED_UNICODE);

    $cache->connect($key);
    $cache->lock(LOCK_EX);
    $cache->fetch();
    $cache->data = ['json' => $json, 'updatedTime' => time(), 'expires' => 86400];
    $cache->apply();
    $cache->unlock();
    $cache->disconnect();

    return $json;
}

// --- Selection primitives (moved unchanged from Frontend/tag-viewer.php) ---

/**
 * ['pathA' => any, 'pathB' => any, ...]
 *
 * @param array|null $source
 *  ['pathA' => any, 'pathB' => any, ...]
 * @param string[] $selectorTags
 * @param array $tag2path
 * @param array $path2tag
 * @return array
 */
function selectTaggedPaths($source, $selectorTags, $tag2path, $path2tag)
{
    $selectedPaths = [];
    foreach ($selectorTags as $tag) {
        if (isset($tag2path[$tag])) {
            $selectedPaths += $tag2path[$tag];
        }
    }

    if (is_null($source)) {
        return $selectedPaths;
    }

    return array_intersect_key($source, $selectedPaths);
}

/**
 * @param array|null $source
 * @param string[] $selectorTags
 * @param \SearchEngine\Index $index
 * @return array
 */
function findTagSuggestedPaths($source, $selectorTags, $index)
{
    $suggestions = [];
    foreach ($selectorTags as $tag) {
        $suggestions = array_merge(
            $suggestions,
            $index->search($tag)
        );
    }

    foreach ($suggestions as $i => $suggested) {
        if ($suggested['score'] < 0.75) {
            unset($suggestions[$i]);
        }
    }

    sortSuggestions($suggestions);

    $selectedPaths = [];
    foreach ($suggestions as $suggested) {
        $selectedPaths[$suggested['id']] = $suggested['score'];
    }

    if (is_null($source)) {
        return $selectedPaths;
    }
    return array_intersect_key($selectedPaths, $source);
}

function sortSuggestions(&$suggestions)
{
    uasort($suggestions, function ($a, $b) {
        if ($a['score'] == $b['score']) {
            return 0;
        }
        return ($a['score'] < $b['score']) ? 1 : -1;
    });
}
