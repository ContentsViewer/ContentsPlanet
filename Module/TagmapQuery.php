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
 * The population a selection puts on the map: path => membership, where
 * `true` means the content carries a selected tag and a float is a name
 * similarity score.
 *
 * THIS IS THE ONE DEFINITION of "what is in this selection", and it exists
 * because there used to be two. membershipManifest() names these paths and
 * contentsByKeys() resolves the client's keys back to bodies; when the
 * second walked `path2tag` instead (tagged contents only) it could not
 * resolve the name matches the first had already put on the map, so those
 * marks were drawn and could never be named. Measured on /Arduino: 65 keys
 * in the manifest, 57 resolvable, and all 8 that were not were name matches.
 * Both callers now read the same array, which makes that class of
 * disagreement unrepresentable rather than merely fixed.
 *
 * At the root there is no selection, so every tagged content is in and every
 * membership is `true`. `path2tag`'s values are TAG MAPS, not memberships --
 * handing them straight through would make contentItem() read each one as a
 * name match (it tests `!is_bool($membership)`) and report the whole corpus
 * as `suggested`. Normalising here, once, is what keeps that trap out of the
 * call sites.
 *
 * @param array<int, array{selectors: string[], selected: array<string, bool|float>}> $eachSelected
 * @param array<string, mixed> $path2tag
 * @return array<string, bool|float>
 */
function universeOf(array $eachSelected, array $path2tag): array
{
    return $eachSelected === []
        ? array_map(fn() => true, $path2tag)
        : end($eachSelected)['selected'];
}

/**
 * The same population, for a caller that holds only the selection.
 *
 * Requires LoadMetadata(). Loads the index only when there IS a selection to
 * resolve: name matching happens inside select(), so the root -- which
 * selects nothing -- needs no index at all (measured: `totalContents ==
 * explicitContents == 192` there, i.e. not one name match to find).
 *
 * @param array<int, string[]> $parts canonical
 * @return array<string, bool|float>
 */
function selectionUniverse(\ContentDatabaseContext $dbContext, array $parts): array
{
    $path2tag = $dbContext->metadata->data['path2tag'] ?? [];
    if ($parts === []) {
        return universeOf([], $path2tag);
    }
    $dbContext->LoadIndex();
    return universeOf(select($dbContext, $parts), $path2tag);
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

    // Which groups each content belongs to. The client needs this to place
    // every content exactly once: with counts alone it cannot tell that the
    // same content is being counted by three groups, so it would draw three
    // marks for one thing. Built from the sets already computed above, so no
    // content file is read and nothing is recounted.
    $pathGroups = [];
    foreach ($coTags as $key => $group) {
        foreach ($tagPaths[$group['tags'][0]] as $path => $_) {
            $pathGroups[$path][] = $key;
        }
    }

    return [
        'coTags' => $coTags,
        'chipTags' => $chipTags,
        'orBase' => $orBase,
        'directCount' => $directCount,
        'pathGroups' => $pathGroups,
    ];
}

/**
 * A short, stable identity for a content, so the client can tell that a mark
 * at one level and a mark at another are the same thing and tween between
 * them. The path itself would do, but it more than doubles the manifest
 * (measured: 8.8 KB versus 4.1 KB at the root); 48 bits keeps collisions
 * around one in ten million at ten thousand contents, and a collision costs
 * a tweened mark, not correctness.
 */
function contentKey(string $path): string
{
    return substr(md5($path), 0, 12);
}

/** Maximum manifest rows. Beyond this the client falls back to anonymous marks. */
const MAX_MANIFEST = 4000;

/**
 * The membership manifest: one row per content, naming the groups it belongs
 * to by their index in `coTags`.
 *
 * Rows are `[key, [groupIndex, ...]]`. An empty list means the content sits
 * directly under the selection. A `-1` means it belongs to a group that fell
 * outside MAX_CO_TAGS — kept distinct so those contents are never mistaken
 * for direct ones, which would inflate `directContents` all over again.
 *
 * @param array<string, mixed> $universe the paths in scope, in payload order
 * @param array<string, string[]> $pathGroups path => group keys
 * @param string[] $shownKeys group keys in response order (already capped)
 * @return array{rows: array, truncated: bool}
 */
function membershipManifest(array $universe, array $pathGroups, array $shownKeys): array
{
    $indexOf = array_flip($shownKeys);
    $rows = [];
    $truncated = false;
    foreach ($universe as $path => $_) {
        if (count($rows) >= MAX_MANIFEST) {
            $truncated = true;
            break;
        }
        $groups = [];
        $hidden = false;
        foreach ($pathGroups[$path] ?? [] as $key) {
            if (isset($indexOf[$key])) {
                $groups[] = $indexOf[$key];
            } else {
                $hidden = true;
            }
        }
        sort($groups);
        if ($hidden) {
            $groups[] = -1;
        }
        $rows[] = [contentKey($path), $groups];
    }
    return ['rows' => $rows, 'truncated' => $truncated];
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
        $item = contentItem($dbContext, $path, $selectedPaths[$path]);
        if ($item !== null) $items[] = $item;
    }

    return [
        'items' => $items,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'hasMore' => $offset + $limit < $total,
    ];
}

/**
 * One content, as the client sees it.
 *
 * @param $membership true when the content carries a selected tag, or a float
 *   score when it matched by name similarity instead. Null when the caller has
 *   no selection to judge against (a key lookup), which reports neither.
 */
function contentItem(
    \ContentDatabaseContext $dbContext,
    string $path,
    $membership,
    bool $withSummary = true
): ?array {
    require_once(MODULE_DIR . "/ContentsViewerUtils.php");

    $content = $dbContext->database->get($path);
    if (!$content) {
        logger()->warning("TagmapQuery: content not found: {$path}");
        return null;
    }
    $path2tag = $dbContext->metadata->data['path2tag'] ?? [];
    $parent = $content->parent();
    // Decoding the body to summarise it costs 8.6 ms per content and nothing
    // caches it, so it is skipped unless the caller says it needs it. A
    // title is about 1 ms; the difference decides whether naming sixty marks
    // takes one second or six.
    $summary = $withSummary ? \ContentsViewerUtils\GetDecodedText($content)['summary'] : null;
    $suggested = $membership !== null && !is_bool($membership);
    return [
        'title' => \NotBlankText([$content->title, basename($content->path)]),
        'parentTitle' => $parent === false ? null : \NotBlankText([$parent->title, basename($parent->path)]),
        'key' => contentKey($path),
        'url' => \ContentsViewerUtils\CreateContentHREF($content->path),
        'summary' => $summary,
        // `suggested` means this content matched by name similarity, not by a
        // tag. The UI must mark it: it is not tagged with what the user
        // selected. (path2tag mixes authored with machine-inferred tags and
        // cannot tell them apart, so `tags` claims no authorship -- it is
        // only the membership the index recorded.)
        'suggested' => $suggested,
        'score' => $suggested ? $membership : null,
        'tags' => array_keys($path2tag[$path] ?? []),
    ];
}

/**
 * Maximum keys resolved in one lookup.
 *
 * Each costs about 17 ms the first time its file is read, so a request is
 * bounded by what a viewport can actually show rather than by the manifest.
 */
const MAX_KEYS = 200;

/**
 * The contents behind a set of manifest keys.
 *
 * The map draws every content of a level from the membership manifest, which
 * carries identities and no bodies, so a mark that grows large enough to
 * deserve a title has nothing to show. This resolves exactly the marks that
 * reached that size -- not a page, which would name whichever contents
 * happened to be fetched first and leave the rest anonymous.
 *
 * Keys are resolved INSIDE the population that minted them ($universe, from
 * selectionUniverse()). A manifest key is only meaningful in the selection
 * that produced it: resolved against `path2tag` instead, a name match -- in
 * the selection but carrying no tag -- was unresolvable, so the map drew a
 * mark it could never name (measured: 8 of /Arduino's 65). The population
 * also carries each path's membership, which is what lets an item report
 * `suggested` truthfully; passing null here reported every content as
 * tagged.
 *
 * A key is 48 bits of an md5, so two paths CAN collide. Both are returned;
 * each item carries its own key, so a client that receives two items for one
 * key must drop that key rather than pick one. Showing the wrong article's
 * title is worse than showing none. This is also why the scan below runs to
 * the end instead of stopping once it has one path per requested key: an
 * early exit could return the first half of a collision, which a client
 * reads as a clean resolution and shows the wrong title. The scan got
 * cheaper anyway -- a selection is smaller than the tagged corpus (65
 * against 192 on /Arduino).
 *
 * @param array<string, bool|float> $universe path => membership
 */
function contentsByKeys(
    \ContentDatabaseContext $dbContext,
    array $keys,
    array $universe,
    bool $withSummary = false
): array {
    $wanted = [];
    foreach (array_slice(array_values(array_unique($keys)), 0, MAX_KEYS) as $key) {
        $wanted[$key] = true;
    }

    $items = [];
    foreach ($universe as $path => $membership) {
        if (!isset($wanted[contentKey($path)])) continue;
        $item = contentItem($dbContext, $path, $membership, $withSummary);
        if ($item !== null) $items[] = $item;
    }
    return [
        'items' => $items,
        'requested' => count($wanted),
        'withSummary' => $withSummary,
        'truncated' => count(array_unique($keys)) > MAX_KEYS,
    ];
}

/** Maximum number of coTag groups included in a response. */
const MAX_CO_TAGS = 200;

/** Which contents of the selection a response should list. */
const SCOPE_DIRECT = 'direct';      // carrying no unselected tag
const SCOPE_CHILDREN = 'children';  // living inside the child groups
const SCOPE_ALL = 'all';
// Not a population but a lookup: resolve these manifest keys to their bodies.
// It answers a different question, so it returns a different, lean shape.
const SCOPE_KEYS = 'keys';

/** @return string one of the SCOPE_* constants */
function normalizeScope(?string $scope): string
{
    return in_array($scope, [SCOPE_DIRECT, SCOPE_CHILDREN, SCOPE_ALL, SCOPE_KEYS], true)
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
        // The index is loaded even here: `reach` below needs the name matches
        // a child group would admit, and at the root nothing else consults it.
        $dbContext->LoadIndex();
        $co = coOccurrence([], [], $tag2path, $path2tag);
        $coTags = $co['coTags'];
        $chipTags = [];
        $suggested = [];
        $selectedPaths = universeOf([], $path2tag);
    } else {
        $dbContext->LoadIndex();
        $eachSelected = select($dbContext, $parts);
        $co = coOccurrence($eachSelected, $parts, $tag2path, $path2tag);
        $coTags = $co['coTags'];
        $chipTags = $co['chipTags'];

        $source = count($eachSelected) > 1 ? $eachSelected[count($eachSelected) - 2]['selected'] : null;
        $selectedPaths = universeOf($eachSelected, $path2tag);
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

    // What entering a group actually yields. `count` is a present-tense fact
    // about the CURRENT selection and is smaller, because the next view also
    // admits name matches: measured on the reference corpus 89% of groups
    // agree, but Arduino goes 33 -> 65 and Unity 4 -> 17. A client that sizes
    // a group by `count` therefore draws a circle the next view does not fit.
    // The source is the current selection, exactly as select() chains it, so
    // this is the child view's totalContents rather than an estimate of it.
    // Null at the root, NOT the root universe: a null source means "the whole
    // corpus", while the root universe is the TAGGED corpus (192 of 217
    // contents). Passing it would restrict findTagSuggestedPaths to tagged
    // contents and understate every group's reach.
    $reachSource = $parts === [] ? null : $selectedPaths;
    $reachOf = function (array $tags) use ($reachSource, $tag2path, $path2tag, $dbContext): int {
        return count(
            selectTaggedPaths($reachSource, $tags, $tag2path, $path2tag)
            + findTagSuggestedPaths($reachSource, $tags, $dbContext->index)
        );
    };

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
            'reach' => $reachOf($counts['tags']),
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

    // The manifest is memberships only -- no titles, no summaries, no URLs.
    // It is what lets the client draw each content once, in the right place,
    // at every level including the root (which carries no content list at
    // all). Measured at 4 KB for 192 contents; the bodies of the same 192
    // would be two orders of magnitude more.
    $manifest = membershipManifest(
        $selectedPaths,
        $co['pathGroups'],
        array_map(fn(array $entry) => $entry['tag'], $coTagList)
    );

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
        'memberships' => $manifest['rows'],
        'manifestTruncated' => $manifest['truncated'],
        'contents' => $contents,
        'stats' => [
            // Every number a client can honestly print about this selection.
            // At the root there is no selection, so the size of the tagged
            // corpus is the only meaningful total (it used to report 0).
            // The root's universe is a real population now (every tagged
            // content, membership `true`), so both figures read it directly
            // instead of special-casing the root.
            'totalContents' => count($selectedPaths),
            'explicitContents' => count(array_filter($selectedPaths, 'is_bool')),
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
const RESPONSE_SCHEMA = 10;

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
