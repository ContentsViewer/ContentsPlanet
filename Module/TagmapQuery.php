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
 * for explicit tag matches and a float score for fuzzy (suggested) matches.
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
        $selected = findTagSuggestedPaths($source, $part, $dbContext->index)
            + selectTaggedPaths($source, $part, $tag2path, $path2tag);
        $eachSelected[] = ['selectors' => $part, 'selected' => $selected];
        $source = $selected;
    }
    return $eachSelected;
}

/**
 * Co-occurrence data around the CURRENT selection.
 * coTags:   for each tag on any path of the current selection (excluding
 *           already-selected tags):
 *             count   = |current selection ∩ tag2path[tag]|  (AND drill-down)
 *             orCount = |parentSource ∩ (lastPart ∪ tag)|    (OR-add result)
 *           Tags with count 0 never appear, so a drill-down can no longer
 *           dead-end at zero contents. NOTE: count is the pure tag
 *           intersection — a conservative lower bound, since select() also
 *           admits fuzzy matches (score >= 0.75) after navigating.
 *           Root (empty selection): every tag, count = orCount = global.
 * chipTags: per-tag contribution counts for the last segment's tags
 *           (the removable breadcrumb chips; parent-based by design).
 *
 * @param array<int, array{selectors: string[], selected: array<string, bool|float>}> $eachSelected
 * @param array<int, string[]> $parts
 * @return array{coTags: array<string, array{count: int, orCount: int}>, chipTags: array<string, int>}
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

    $coTags = [];
    if (!is_null($current)) {
        foreach ($current as $path => $_) {
            foreach ($path2tag[$path] ?? [] as $tag => $__) {
                if (!isset($selectedTags[$tag])) {
                    $coTags[$tag]['count'] = ($coTags[$tag]['count'] ?? 0) + 1;
                }
            }
        }
        foreach ($coTags as $tag => $_) {
            $coTags[$tag]['orCount'] = count(selectTaggedPaths(
                $parentSource,
                array_merge($lastPart, [$tag]),
                $tag2path,
                $path2tag
            ));
        }
    } else {
        foreach ($tag2path as $tag => $paths) {
            if (!isset($selectedTags[$tag])) {
                $coTags[$tag] = ['count' => count($paths), 'orCount' => count($paths)];
            }
        }
    }
    // Deterministic order: natural-case key order, then stable sort by count
    // desc (PHP sorts are stable since 8.0) — keeps layouts reproducible.
    uksort($coTags, 'strnatcasecmp');
    uasort($coTags, fn(array $a, array $b) => $b['count'] <=> $a['count']);

    $chipTags = [];
    if ($parts !== []) {
        foreach ($lastPart as $tag) {
            $chipTags[$tag] = count(selectTaggedPaths($parentSource, [$tag], $tag2path, $path2tag));
        }
    }

    return ['coTags' => $coTags, 'chipTags' => $chipTags];
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
    if (
        !$tagMapIndex->load($tagmapIndexFileName)
        || !array_key_exists('contentsChangedTime', $dbContext->metadata->data)
        || (@filemtime($tagmapIndexFileName) < $dbContext->metadata->data['contentsChangedTime'])
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
 * @param array<string, bool|float> $selectedPaths
 * @return array{items: array<int, array<string, mixed>>, total: int, offset: int, limit: int, hasMore: bool}
 */
function contents(\ContentDatabaseContext $dbContext, array $selectedPaths, int $offset, int $limit): array
{
    require_once(MODULE_DIR . "/ContentsViewerUtils.php");

    $paths = array_keys($selectedPaths);
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
            'suggested' => $suggested,
            'score' => $suggested ? $selectedPaths[$path] : null,
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

/** Maximum number of coTags included in a response. */
const MAX_CO_TAGS = 200;

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

        $selectedTags = [];
        foreach ($parts as $part) {
            foreach ($part as $tag) {
                $selectedTags[$tag] = true;
            }
        }
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

    $totalCoTags = count($coTags);
    $coTagList = [];
    foreach (array_slice($coTags, 0, MAX_CO_TAGS, true) as $tag => $counts) {
        $coTagList[] = ['tag' => $tag, 'count' => $counts['count'], 'orCount' => $counts['orCount']];
    }
    $chipTagList = [];
    foreach ($chipTags as $tag => $count) {
        $chipTagList[] = ['tag' => $tag, 'count' => $count];
    }
    $suggestedList = [];
    foreach ($suggested as $tag => $desc) {
        $suggestedList[] = [
            'tag' => $tag,
            'count' => $desc['count'],
            'orCount' => $desc['orCount'],
            'score' => $desc['score'],
        ];
    }

    $contents = $parts === []
        ? ['items' => [], 'total' => 0, 'offset' => 0, 'limit' => $limit, 'hasMore' => false]
        : contents($dbContext, $selectedPaths, $offset, $limit);

    return [
        'tagPath' => $canonical === '' ? '' : '/' . $canonical,
        'layer' => $layerName,
        'segments' => $parts,
        'breadcrumb' => $breadcrumb,
        'coTags' => $coTagList,
        'totalCoTags' => $totalCoTags,
        'chipTags' => $chipTagList,
        'suggestedTags' => $suggestedList,
        'contents' => $contents,
        'stats' => [
            'totalContents' => count($selectedPaths),
            'generatedInMs' => round((microtime(true) - $started) * 1000, 1),
        ],
    ];
}

function cacheKey(string $rootDirectory, string $layerSuffix, string $canonicalTagPath, int $offset, int $limit): string
{
    // The tag-path part is hashed: percent-encoded multibyte tags would blow
    // up the cache file name length otherwise. The "api2" prefix isolates the
    // v2 response shape from stale v1 cache entries (which age out via TTL).
    return 'tagmap-api2-' . $rootDirectory . $layerSuffix . '-'
        . sha1($canonicalTagPath . '|' . $offset . '|' . $limit);
}

/**
 * Cached JSON response, shared by the shell and the API service (same key:
 * either one warms the cache for the other). Valid while nothing under the
 * root has changed (`updatedTime >= contentsChangedTime`), at most one day.
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
    int $offset,
    int $limit
): string {
    $contentsChangedTime = $dbContext->metadata->data['contentsChangedTime'] ?? 0;
    $key = cacheKey($rootDirectory, $layerSuffix, canonicalTagPath($parts), $offset, $limit);

    $cache = new \Cache();
    $cache->connect($key);
    $cache->lock(LOCK_SH);
    $cache->fetch();
    $cache->unlock();
    $cache->disconnect();
    if (
        isset($cache->data['json'], $cache->data['updatedTime'])
        && $cache->data['updatedTime'] >= $contentsChangedTime
    ) {
        return $cache->data['json'];
    }

    $response = buildResponse($dbContext, $rootDirectory, $layerName, $layerSuffix, $parts, $offset, $limit);
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
