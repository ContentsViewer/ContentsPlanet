;(function () {
    "use strict"

    /**
     * TagMap: nested circles you travel into.
     *
     * The selection path IS the nesting. `/Library/C#` means the root field
     * contains Library's circle, which contains C#'s circle, and a child is
     * always a slot inside its parent (see tagmap-layout.js for the geometry
     * and its tests). So the circle you click is the circle you enter, and
     * entering it moves only the CAMERA: the re-anchor is an algebraic
     * identity, which is why the entered circle stays exactly where it was
     * and the siblings you leave behind do not move at all.
     *
     * Coordinates are a presentation concern and exist only here. The server
     * returns data -- tags with counts, one page of contents -- and knows
     * nothing about positions, so the same path draws the same picture for
     * everyone. State lives as:
     *   URL             the selection (canonical, shareable)
     *   memory          payload cache (current level and its ancestors), camera
     *   history.state   nothing (popstate reads the URL and the memory cache)
     *
     * Reading it:
     *   amber boundary  the level you are in
     *   circles inside  its child tags, sized by the count on their label
     *   dots in the band  contents sitting directly here, in none of the
     *                   children; hollow means matched by tag-name similarity
     *   dashed circle   a similar tag NAME, which may share no content
     *   faint outlines  the levels you came through and their other children
     *
     * Every number names the payload field it came from, and none is
     * substituted when a field is missing: a tag with no `count` is not a tag
     * whose count is its global total.
     *
     * Interaction: tap a child to enter it, drag one child onto another to
     * enter both together (a NEW level, not a wider version of this one),
     * drag out past the boundary to leave. Drag empty space to pan,
     * wheel/pinch to zoom; zoom is level-of-detail only and never navigates.
     * NOTE: never use location.hash (ContentsViewer.js owns hashchange).
     */

    var TM = {}

    // ---- config -----------------------------------------------------------

    // Mirrors of TAGMAP_MAX_DEPTH / TAGMAP_MAX_WIDTH, read from the page in
    // init() so the two cannot drift; these are only the fallback defaults.
    var MAX_DEPTH = 5
    var MAX_WIDTH = 5
    var PAGE_SIZE = 20
    var TAU = Math.PI * 2

    // World units per current-level radius. Any value works (the camera
    // fits to it); this one keeps hairlines and label sizes in a range the
    // canvas is comfortable with.
    var LEVEL_R = 400
    var ANCESTORS_DRAWN = 2       // deeper ancestors are culled, not composed
    var GHOST_RING = 0.9          // where name suggestions sit, in the band
    var GHOST_R = 0.055
    var GUIDE_RINGS = [0.25, 0.5, 0.75]
    var RING_HIT = 14             // screen px around an ancestor boundary
    // Past this many contents inside the child groups, showing them all in
    // place is noise rather than help (the former view used the same rule).
    var EXPAND_LIMIT = 10
    var STAR_R = 7

    var SCALE_MIN = 0.12
    var SCALE_MAX = 5
    var LABEL_MIN_SCREEN_R = 16
    var LABEL_MAX_BOXES = 30      // labels per frame, collision-checked
    var MAX_DRAW_ERRORS = 90      // consecutive failing frames before giving up
    var STAR_MIN_SCALE = 0.6      // below this, contents are not drawn at all
    var SUMMARY_ZOOM = 1.4        // zoom past the default fit to see summaries

    var TAP_MOVE_PX = 8
    var TAP_MS = 400
    var DRAG_START_PX = 10
    var EASE = 0.14
    var CONTAINER_FILL = 0.72     // share of the viewport the current level fills

    var Layout = window.TagMapLayout

    // ---- state (all of it in memory) --------------------------------------

    var state = {
        serviceUri: null,
        contentPath: null,
        csrfToken: null,
        layer: "ja",
        segments: [],
        data: null,
        payloads: {},   // tagPath -> response   memory cache, incl. ancestors
        // Derived from payloads by buildScene(); never mutated elsewhere, so
        // the same payloads always give the same picture.
        layout: { slots: [], byTag: {} },   // children of the current level
        universe: {},   // tag -> {tag, kind, x, y, r}  in current-level space
        members: [],    // [{tag, count, total}] the current level's children
        slotChain: [],  // slot each level occupies inside its parent
        levels: [],     // transforms: current level first, then ancestors
        ancestors: [],  // [{depth, x, y, r, children: [...]}]
        band: [],       // direct-content dot positions, index-stable
        childItems: [], // contents inside the child groups (scope=children)
        insideChildren: {},  // group key -> [{item, x, y}] drawn in place
        stars: [],      // [{item, ...}] paired with band positions
        trail: [],      // [{tagPath, label, x, y}]
    }

    var camera = { x: 0, y: 0, scale: 1 }
    var cameraTarget = null
    var popupTarget = null
    var drag = null         // {tag, x, y, target}
    var dragHint = null     // screen-space label for the current drop target
    var running = false
    var drawErrors = 0      // consecutive failing frames; see frame()
    var reducedMotion = window.matchMedia
        && window.matchMedia("(prefers-reduced-motion: reduce)").matches

    var elements = {}
    var ctx = null
    var dpr = 1
    var cssW = 0
    var cssH = 0
    var starLayer = null
    var controllers = { nav: null, page: null }
    var ancestorFetches = {}   // cacheKey -> in flight, so we ask only once
    var insideFetches = {}     // tagPath -> in flight
    var navGeneration = 0   // see navigate(): guards against stale responses
    var loadingTimer = null

    // ---- helpers ----------------------------------------------------------

    function natCaseCompare(a, b) {
        var ax = String(a).toLowerCase().match(/(\d+|\D+)/g) || []
        var bx = String(b).toLowerCase().match(/(\d+|\D+)/g) || []
        for (var i = 0; i < Math.max(ax.length, bx.length); i++) {
            if (ax[i] === undefined) return -1
            if (bx[i] === undefined) return 1
            var an = parseInt(ax[i], 10)
            var bn = parseInt(bx[i], 10)
            if (!isNaN(an) && !isNaN(bn)) {
                if (an !== bn) return an - bn
            } else if (ax[i] !== bx[i]) {
                return ax[i] < bx[i] ? -1 : 1
            }
        }
        return 0
    }

    function parseTagPath(tagPath) {
        var segments = []
        tagPath.split("/").forEach(function (part) {
            var tags = part.split(",").map(function (t) { return t.trim() }).filter(Boolean)
            if (tags.length > 0) segments.push(tags)
        })
        return segments
    }

    function normalizeSegments(segments) {
        var result = []
        segments.forEach(function (segment) {
            var tags = []
            segment.forEach(function (tag) {
                tag = tag.trim()
                if (tag !== "" && tags.indexOf(tag) === -1) tags.push(tag)
            })
            tags.sort(natCaseCompare)
            if (tags.length > 0) result.push(tags)
        })
        return result
    }

    function tagPathOf(segments) {
        return segments.map(function (s) { return s.join(",") }).join("/")
    }

    function basePath() {
        var path = location.pathname
        var index = path.toLowerCase().indexOf("/:tagmap")
        return index >= 0 ? path.slice(0, index) : path
    }

    function buildHref(segments) {
        var tagPart = segments.map(function (s) {
            return s.map(encodeURIComponent).join(",")
        }).join("/")
        return basePath() + "/:tagmap" + (tagPart ? "/" + tagPart : "")
            + "?layer=" + encodeURIComponent(state.layer)
    }

    function currentUrlSegments() {
        var index = location.pathname.toLowerCase().indexOf("/:tagmap")
        var raw = index >= 0
            ? decodeURIComponent(location.pathname.slice(index + "/:tagmap".length))
            : ""
        return parseTagPath(raw)
    }

    function segmentsLabel(segments) {
        if (segments.length === 0) return "TagMap"
        return segments.map(function (s) { return s.join(", ") }).join(" / ")
    }

    function selectedTags() {
        var tags = []
        state.segments.forEach(function (segment) {
            segment.forEach(function (tag) { if (tags.indexOf(tag) === -1) tags.push(tag) })
        })
        return tags
    }

    /**
     * The payload entry for a tag, or null when the current response says
     * nothing about it. Callers must render nothing rather than substitute a
     * different field: a tag with no `count` is not a tag with count = total.
     */
    function tagEntry(tag) {
        if (!state.data) return null
        var lists = [state.data.coTags, state.data.suggestedTags, state.data.chipTags]
        for (var i = 0; i < lists.length; i++) {
            var list = lists[i] || []
            for (var j = 0; j < list.length; j++) {
                if (list[j].tag === tag) return list[j]
            }
        }
        return null
    }

    function localize(ja, en) {
        var lang = document.documentElement.lang || "ja"
        return lang.indexOf("ja") === 0 ? ja : en
    }

    function readMeta(name) {
        var element = document.getElementsByName(name).item(0)
        return element ? element.content : null
    }

    // Only the star field needs randomness now; layout hashing lives in
    // tagmap-layout.js, where it is covered by tests.
    function prng(seed) {
        var a = seed >>> 0
        return function () {
            a |= 0; a = (a + 0x6d2b79f5) | 0
            var t = Math.imul(a ^ (a >>> 15), 1 | a)
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296
        }
    }

    // ---- theme ------------------------------------------------------------

    var paletteCache = null

    function palette() {
        if (paletteCache) return paletteCache
        var style = getComputedStyle(document.documentElement)
        function v(name, fallback) {
            var value = style.getPropertyValue(name).trim()
            return value !== "" ? value : fallback
        }
        paletteCache = {
            bg: v("--tagmap-bg", "#fcfcfb"),
            ink: v("--tagmap-ink", "#0b0b0b"),
            inkSecondary: v("--tagmap-ink-secondary", "#52514e"),
            muted: v("--tagmap-muted", "#898781"),
            gridline: v("--tagmap-gridline", "#e1e0d9"),
            surface: v("--tagmap-node-surface", "#ffffff"),
            accent: v("--tagmap-planet-3", "#2a78d6"),
            accentRing: v("--tagmap-planet-ring", "#1c5cab"),
            focus: v("--tagmap-sun", "#eda100"),
            focusRing: v("--tagmap-sun-ring", "#c98500"),
            star: v("--tagmap-star", ""),
        }
        return paletteCache
    }

    function onThemeChanged() {
        // ThemeChanger runs its callbacks before it sets the theme attribute.
        requestAnimationFrame(function () {
            paletteCache = null
            buildStarLayer()
        })
    }

    // ---- api --------------------------------------------------------------

    /**
     * @param channel "nav" for a selection change, "page" for paging. They get
     *   separate controllers: sharing one meant either cancelled the other, so
     *   paging silently killed an in-flight navigation and vice versa.
     */
    function fetchState(segments, offset, isRetry, channel, scope) {
        channel = channel || "nav"
        if (controllers[channel]) controllers[channel].abort()
        controllers[channel] = new AbortController()
        var signal = controllers[channel].signal

        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", tagPathOf(segments))
        form.append("offset", offset || 0)
        form.append("limit", PAGE_SIZE)
        if (scope) form.append("scope", scope)
        // No CSRF token: this endpoint is a public READ, guarded by AccessGate
        // and ValidateAccessPrivilege, and it never validated the token we
        // used to send. Sending an ignored field only implies a check exists.

        return fetch(state.serviceUri + "/tagmap-service.php", {
            method: "POST",
            body: form,
            signal: signal,
        }).then(function (response) {
            if (response.status === 428) {
                // The gate token expired: reload into the challenge flow.
                location.reload()
                return new Promise(function () {})
            }
            return response.json()
        }).then(function (body) {
            if (body.error === "canonical_mismatch" && !isRetry) {
                return fetchState(parseTagPath(body.canonical), offset, true, channel, scope)
            }
            if (body.error) throw new Error(body.error)
            return body
        })
    }

    // ---- placement: the nested-circle model --------------------------------
    //
    // World coordinates ARE the current level's space, scaled so the current
    // circle has radius LEVEL_R. Everything drawn is therefore a slot inside
    // that circle or an ancestor scaled up around it, and "the circle you
    // click is the circle you enter" holds by construction. Positions are
    // exact -- no easing of nodes, because nothing moves: entering a child
    // moves the CAMERA, and the algebra of that move (Layout.reanchorCamera)
    // keeps every circle pixel-identical at the instant of the transition.

    /**
     * Rebuilds the scene from the payloads on hand. Pure: same payloads in,
     * same scene out, so a revisit reproduces the picture exactly and a
     * shared URL shows the same thing to everyone.
     */
    function buildScene() {
        var data = state.data
        state.universe = {}
        state.members = []
        state.layout = { slots: [], byTag: {} }
        state.slotChain = []
        state.levels = [{ depth: 0, ax: 0, ay: 0, ak: 1 }]
        if (!data) return

        // A child is a GROUP of tags (the server merges tags that cover an
        // identical set of contents), keyed by the OR segment that selects
        // it. Sized by `count` at every depth -- at the root that equals the
        // global total, so there is no special case and no channel carrying
        // two different quantities.
        var children = (data.coTags || []).map(function (t) {
            return { tag: t.tag, weight: t.count, entry: t }
        })
        state.layout = Layout.layoutChildren(children)
        state.members = children.map(function (child) {
            return { tag: child.tag, count: child.entry.count, total: child.entry.total }
        })

        state.layout.slots.forEach(function (slot, index) {
            var entry = children[index].entry
            state.universe[slot.tag] = {
                tag: slot.tag,
                tags: entry.tags || [entry.tag],
                kind: "child",
                x: slot.x * LEVEL_R,
                y: slot.y * LEVEL_R,
                r: slot.r * LEVEL_R,
                count: entry.count,
                total: entry.total,
                slot: slot,
            }
        })

        // Name suggestions are not children of this selection, so they sit in
        // the free band rather than inside. Uniform size on purpose: their
        // number is a global total while a child's is an in-selection count,
        // and encoding two different quantities in one channel would invite a
        // false comparison.
        ;(data.suggestedTags || []).forEach(function (t, i) {
            if (state.universe[t.tag]) return
            var angle = -Math.PI / 2 + (i % 2 === 0 ? 1 : -1) * Math.ceil((i + 1) / 2) * 0.16
            state.universe[t.tag] = {
                tag: t.tag,
                kind: "suggestion",
                x: Math.cos(angle) * GHOST_RING * LEVEL_R,
                y: Math.sin(angle) * GHOST_RING * LEVEL_R,
                r: GHOST_R * LEVEL_R,
                count: t.count,
                total: t.total,
            }
        })

        state.band = Layout.layoutBand((data.contents || {}).items || [])
        placeInsideChildren()
        buildAncestors()
    }

    /**
     * Puts the child groups' own contents inside their circles, when there
     * are few enough of them to be worth showing in place.
     *
     * The former server-rendered view did this: below a threshold it expanded
     * every group and listed its contents, above it showed only counts. The
     * reason still holds -- making someone enter a circle to discover it
     * holds one item is a wasted move, and with the contents visible the
     * reader can also SEE that a content belonging to two groups appears in
     * both, which no count can convey.
     *
     * Needs the `children` scope, so it fills in when that arrives; until
     * then the circles simply show their counts.
     */
    function placeInsideChildren() {
        state.insideChildren = {}
        var items = state.childItems
        if (!items || items.length === 0) return

        var selected = selectedTags()
        var byChild = {}
        items.forEach(function (item) {
            var unselected = (item.tags || []).filter(function (tag) {
                return selected.indexOf(tag) < 0
            })
            // A content lands in every group it belongs to -- that IS the
            // overlap, shown rather than summarised.
            state.layout.slots.forEach(function (slot) {
                var node = state.universe[slot.tag]
                if (!node) return
                var belongs = node.tags.some(function (tag) {
                    return unselected.indexOf(tag) >= 0
                })
                if (belongs) (byChild[slot.tag] = byChild[slot.tag] || []).push(item)
            })
        })

        Object.keys(byChild).forEach(function (key) {
            var node = state.universe[key]
            var group = byChild[key]
            var positions = Layout.layoutInside(group.length)
            state.insideChildren[key] = group.map(function (item, index) {
                return {
                    item: item,
                    x: node.x + positions[index].x * node.r,
                    y: node.y + positions[index].y * node.r,
                }
            })
        })
    }

    /**
     * Fetches the contents living inside the child groups, but only when
     * there are few of them. The threshold is the point past which showing
     * them all is noise rather than help.
     */
    function ensureInsideChildren() {
        var stats = (state.data || {}).stats || {}
        var forPath = state.data ? state.data.tagPath : null
        if (typeof stats.childContents !== "number"
            || stats.childContents === 0
            || stats.childContents > EXPAND_LIMIT) {
            return
        }
        if (insideFetches[forPath]) return
        insideFetches[forPath] = true
        fetchState(state.segments, 0, false, "inside", "children").then(function (data) {
            if (!state.data || state.data.tagPath !== forPath) return
            state.childItems = data.contents.items || []
            placeInsideChildren()
        }).catch(function () {
            // Optional detail: without it the circles still show their counts.
        }).then(function () {
            delete insideFetches[forPath]
        })
    }

    /**
     * The chain of enclosing levels, from whichever prefix payloads we have.
     *
     * Each ancestor is placed so the level below sits exactly at the slot it
     * occupies inside it -- the algebraic inverse of entering -- so an
     * ancestor arriving late pops in additively and nothing already drawn
     * moves. Ancestors we have no payload for are simply absent; the current
     * level never depends on them.
     */
    function buildAncestors() {
        var chain = []
        for (var depth = state.segments.length; depth > 0; depth--) {
            var parentSegments = state.segments.slice(0, depth - 1)
            var parent = state.payloads[cacheKeyOf(parentSegments)]
            if (!parent) break
            var parentLayout = Layout.layoutChildren((parent.coTags || []).map(function (t) {
                return { tag: t.tag, weight: t.count }
            }))
            // The parent's groups are keyed by their OR segment, and the
            // segment we came in through IS one of those keys, so a lookup by
            // the joined name finds it whether it was one tag or several.
            var slot = Layout.slotForSegment(parentLayout, [state.segments[depth - 1].join(",")])
                || Layout.slotForSegment(parentLayout, state.segments[depth - 1])
            if (!slot) break
            chain.push({ slot: slot, payload: parent, layout: parentLayout, segments: parentSegments })
        }

        state.slotChain = chain.map(function (link) { return link.slot })
        state.levels = Layout.composeChain(state.slotChain, ANCESTORS_DRAWN)

        // The ancestors' other children, in the current level's space. These
        // are the siblings: after entering a child they stay exactly where
        // they were, dimmed, instead of scattering to unrelated positions.
        state.ancestors = []
        for (var i = 0; i < chain.length && i + 1 < state.levels.length; i++) {
            var level = state.levels[i + 1]
            var link = chain[i]
            // The group we came in through, by its OR-segment key.
            var entered = {}
            entered[state.segments[state.segments.length - 1 - i].join(",")] = true
            state.ancestors.push({
                depth: i + 1,
                level: level,
                segments: link.segments,
                x: level.ax * LEVEL_R,
                y: level.ay * LEVEL_R,
                r: level.ak * LEVEL_R,
                children: link.layout.slots
                    .filter(function (slot) {
                        if (entered[slot.tag]) return false
                        // One circle per tag name per screen. A tag that is
                        // also a child HERE would otherwise appear twice, and
                        // the two circles encode different quantities -- the
                        // outer one the tag's size in the parent, the inner
                        // one its size in this selection. Same channel, two
                        // meanings, and the outer is usually the bigger of
                        // the two, so the eye goes to the wrong one. The
                        // circle that belongs to where you are wins.
                        return !state.universe[slot.tag]
                    })
                    .map(function (slot) {
                        return {
                            tag: slot.tag,
                            x: (slot.x * level.ak + level.ax) * LEVEL_R,
                            y: (slot.y * level.ak + level.ay) * LEVEL_R,
                            r: slot.r * level.ak * LEVEL_R,
                        }
                    }),
            })
        }
    }

    /**
     * The current level, as one circle. A selection is a single set however
     * it was built -- `A,B` is a union and `A/B` an intersection, but either
     * way what you are looking at is one group of contents -- so it gets one
     * boundary. Null at the root, which is a level without a boundary.
     */
    function containerRegion() {
        if (state.segments.length === 0) return null
        return { x: 0, y: 0, r: LEVEL_R }
    }

    /**
     * Contents drawn as dots sit *directly* in the selection: every tag they
     * carry is already selected, so they fall into none of the child clouds.
     * A content that belongs to a child is that child's business — its count
     * is on the child's label, and its dot appears once you go in. Same split
     * the server-rendered tag view made ("non" group versus the tag groups).
     *
     * The server decides membership (`direct=1`); the client must not infer
     * it from `coTags`, which is capped, nor from a page of items, which is
     * ordered explicit-first and so biases a page-local estimate low.
     *
     * Angles come from the index alone, so layoutBand(10) is an exact prefix
     * of layoutBand(30): appending a page cannot move an existing dot.
     */
    function computeStars(data) {
        return ((data.contents || {}).items || []).map(function (item, index) {
            return { item: item, index: index }
        })
    }

    /**
     * Direct contents ring the inside of the boundary, just outside where the
     * children are packed, so they read as "in here, but in none of these".
     */
    function starPosition(star) {
        var dot = state.band[star.index]
        if (!dot) return { x: 0, y: 0 }
        return { x: dot.x * LEVEL_R, y: dot.y * LEVEL_R }
    }

    // ---- camera -----------------------------------------------------------

    function worldToScreen(wx, wy) {
        return {
            x: cssW / 2 + (wx - camera.x) * camera.scale,
            y: cssH / 2 + (wy - camera.y) * camera.scale,
        }
    }

    function screenToWorld(px, py) {
        return {
            x: (px - cssW / 2) / camera.scale + camera.x,
            y: (py - cssH / 2) / camera.scale + camera.y,
        }
    }

    function clampScale(scale) {
        return Math.min(SCALE_MAX, Math.max(SCALE_MIN, scale))
    }

    function hairline() {
        return 1 / camera.scale
    }

    /** Frames the current level. Only the camera moves. */
    function fitCurrentLevel() {
        // The root has no boundary, so frame the packed field it contains.
        var fill = state.segments.length > 0 ? CONTAINER_FILL : 0.94
        var extent = state.segments.length > 0 ? LEVEL_R : LEVEL_R * Layout.INNER_FILL
        cameraTarget = {
            x: 0,
            y: 0,
            scale: clampScale((Math.min(cssW, cssH) * fill) / (2 * extent)),
        }
        if (reducedMotion) {
            camera.x = cameraTarget.x
            camera.y = cameraTarget.y
            camera.scale = cameraTarget.scale
            cameraTarget = null
        }
    }

    /**
     * Re-anchors the camera across a level change so the transition is
     * visually continuous.
     *
     * Screen mapping is f(p) = C + (p - c) * k, and entering a slot rewrites
     * the space as p' = (p - s) / s.r. Setting c' = (c - s)/s.r and
     * k' = k * s.r makes f'(p') = f(p) for EVERY point, so at the instant of
     * the change the circle being entered stays exactly where it was, every
     * sibling stays exactly where it was (they are now drawn through the
     * ancestor transform, which is this map's inverse) and the ancestor
     * boundary stays put. Nothing can teleport and nothing can scatter --
     * it is an identity, not a tuning parameter. The camera then eases to
     * fitCurrentLevel(), growing the entered circle to fill the view while
     * the ancestors expand past the edges.
     *
     * `slot` is in unit space; world space is that scaled by LEVEL_R.
     */
    function reanchorCamera(slot, direction) {
        if (!slot || !(slot.r > 1e-9)) return false
        var sx = slot.x * LEVEL_R, sy = slot.y * LEVEL_R
        if (direction === "out") {
            camera.x = camera.x * slot.r + sx
            camera.y = camera.y * slot.r + sy
            camera.scale = camera.scale / slot.r
        } else {
            camera.x = (camera.x - sx) / slot.r
            camera.y = (camera.y - sy) / slot.r
            camera.scale = camera.scale * slot.r
        }
        return true
    }

    /** True when `outer` is a prefix of `inner`, segment for segment. */
    function isPrefixOf(outer, inner) {
        if (outer.length > inner.length) return false
        for (var i = 0; i < outer.length; i++) {
            if (outer[i].join(",") !== inner[i].join(",")) return false
        }
        return true
    }

    /**
     * Keeps the picture continuous when the selection changes by exactly one
     * level. Any other jump (a breadcrumb leap, a fresh load) has no shared
     * frame of reference, so it just fits.
     */
    function reanchorForNavigation(fromSegments, toSegments) {
        if (fromSegments.length + 1 === toSegments.length && isPrefixOf(fromSegments, toSegments)) {
            var entering = Layout.slotForSegment(state.layout, toSegments[toSegments.length - 1])
            if (reanchorCamera(entering, "in")) return
        } else if (toSegments.length + 1 === fromSegments.length && isPrefixOf(toSegments, fromSegments)) {
            if (reanchorCamera(state.slotChain[0], "out")) return
        }
        camera.x = 0
        camera.y = 0
    }

    function stepCamera() {
        if (!cameraTarget) return
        camera.x += (cameraTarget.x - camera.x) * EASE
        camera.y += (cameraTarget.y - camera.y) * EASE
        camera.scale *= Math.pow(cameraTarget.scale / camera.scale, EASE)
        var near = Math.abs(cameraTarget.x - camera.x) < 1
            && Math.abs(cameraTarget.y - camera.y) < 1
            && Math.abs(cameraTarget.scale - camera.scale) < 0.002
        if (near) {
            camera.x = cameraTarget.x
            camera.y = cameraTarget.y
            camera.scale = cameraTarget.scale
            cameraTarget = null
        }
    }

    // ---- navigation -------------------------------------------------------

    function applyData(data, options) {
        options = options || {}
        var fromSegments = state.segments
        var wasBuilt = !!state.data

        state.payloads[data.tagPath] = data
        // Captured here, not by the caller: the camera keeps easing while a
        // request is in flight, so a "before" taken any earlier is measured
        // against a different camera and the comparison is meaningless.
        var transitionBefore = wasBuilt ? TM.snapshot() : null
        // The camera must be re-anchored BEFORE the scene is rebuilt: it
        // needs the slot the incoming level occupies in the outgoing one.
        if (wasBuilt) reanchorForNavigation(fromSegments, data.segments)

        state.segments = data.segments
        state.data = data
        // Belongs to the level we are leaving, not the one we are entering.
        state.childItems = []
        buildScene()
        state.stars = computeStars(data)
        if (!wasBuilt) { camera.x = 0; camera.y = 0 }

        // The instant after re-anchoring and before framing: the only moment
        // at which "nothing moved" is observable, since both happen in this
        // one synchronous block. Exposed so a test can assert it.
        TM.lastTransition = {
            from: fromSegments,
            to: data.segments,
            before: transitionBefore,
            at: TM.snapshot(),
        }

        var label = segmentsLabel(state.segments)

        // history.state stays empty: the URL is the identity and memory holds
        // the view. popstate reads the URL and the payload cache.
        if (!options.fromHistory) {
            if (options.replace) history.replaceState(null, "", buildHref(state.segments))
            else history.pushState(null, "", buildHref(state.segments))
        }
        document.title = data.tagPath === "" ? "TagMap" : "TagMap: " + label

        closePopup()
        closeInfoCard()
        renderBreadcrumb()
        rebuildSrList()
        updateNote()
        fitCurrentLevel()
        // Ancestors are context, not a prerequisite: fetch them in the
        // background and let them pop in without moving anything.
        ensureAncestors()
        ensureInsideChildren()
    }

    function cacheKeyOf(segments) {
        var tagPath = tagPathOf(segments)
        return tagPath === "" ? "" : "/" + tagPath
    }

    /**
     * Fetches the payloads of the enclosing levels, so their boundaries and
     * their other children (the siblings) can be drawn. Never blocks the
     * current level and never touches the camera.
     */
    function ensureAncestors() {
        for (var depth = state.segments.length - 1; depth >= 0; depth--) {
            var prefix = state.segments.slice(0, depth)
            var key = cacheKeyOf(prefix)
            if (state.payloads[key] || ancestorFetches[key]) continue
            ancestorFetches[key] = true
            ;(function (segments, cacheKey) {
                fetchState(segments, 0, false, "ancestor-" + cacheKey).then(function (data) {
                    state.payloads[data.tagPath] = data
                    buildAncestors()
                }).catch(function () {
                    // Context is optional; a failure just leaves it absent.
                }).then(function () {
                    delete ancestorFetches[cacheKey]
                })
            })(prefix, key)
        }
    }

    function navigate(segments, options) {
        options = options || {}
        segments = normalizeSegments(segments)
        var tagPath = tagPathOf(segments)
        var cacheKey = tagPath === "" ? "" : "/" + tagPath
        // Every navigation claims a generation, and a response only applies if
        // it still holds the newest one. Without this, a slow request for an
        // abandoned selection would land later and drag the view back to it,
        // pushing its own history entry on the way.
        var generation = ++navGeneration
        var cached = state.payloads[cacheKey]
        if (cached) {
            // Also drop anything in flight: a cache hit is a navigation too.
            if (controllers.nav) controllers.nav.abort()
            setLoading(false)
            applyData(cached, options)
            return
        }
        setLoading(true)
        fetchState(segments, 0, false).then(function (data) {
            setLoading(false)
            if (generation !== navGeneration) return
            applyData(data, options)
        }).catch(function (error) {
            if (generation !== navGeneration) return
            // Clear the progress line first: an abort is still the end of this
            // request, and returning before it left the bar up forever.
            setLoading(false)
            if (error.name === "AbortError") return
            if (error.message === "limit_exceeded") {
                // Retrying cannot help: the server will reject it again.
                showToast(localize("これ以上たどれません", "Cannot go deeper"))
                return
            }
            showToast(localize("読み込みに失敗しました", "Failed to load") + " (" + error.message + ")", function () {
                navigate(segments, options)
            })
        })
    }

    /**
     * Enters a child group. `key` is its OR segment ("A" or "A,B"), which is
     * what the group's circle is keyed by, so a merged group enters as the
     * union it was drawn as.
     */
    function narrowDown(key) {
        var node = state.universe[key]
        var tags = node && node.tags ? node.tags : String(key).split(",")
        var selected = selectedTags()
        tags = tags.filter(function (tag) { return selected.indexOf(tag) < 0 })
        if (tags.length === 0) return
        if (state.segments.length >= MAX_DEPTH) {
            showToast(localize("これ以上絞り込めません", "Cannot narrow down further"))
            return
        }
        if (tags.length > MAX_WIDTH) {
            showToast(localize("一度に選べるタグ数を超えています", "Too many tags at once"))
            return
        }
        navigate(state.segments.concat([tags]))
    }

    /**
     * Enters the union of the given tags, as a NEW level.
     *
     * `enterTogether(['A','B'])` at `/Library` gives `/Library/A,B` -- the
     * contents of Library that carry A or B. Widening the last segment
     * instead (`/Library,A,B`) is a SUPERSET of what you were looking at, so
     * the gesture labelled "view together" would silently un-narrow the view
     * and destroy the parent relationship the user just gestured about. Only
     * at the root did the two forms coincide, which is why the old behaviour
     * looked right there and wrong everywhere else.
     */
    function enterTogether(keys) {
        var selected = selectedTags()
        var segment = []
        keys.forEach(function (key) {
            var node = state.universe[key]
            var tags = node && node.tags ? node.tags : String(key).split(",")
            tags.forEach(function (tag) {
                if (segment.indexOf(tag) === -1 && selected.indexOf(tag) < 0) segment.push(tag)
            })
        })
        if (segment.length === 0) return
        if (segment.length > MAX_WIDTH) {
            showToast(localize("一度に選べるタグ数を超えています", "Too many tags at once"))
            return
        }
        if (state.segments.length >= MAX_DEPTH) {
            showToast(localize("これ以上絞り込めません", "Cannot narrow down further"))
            return
        }
        navigate(state.segments.concat([segment]))
    }

    /** Steps out to the enclosing level. */
    function leaveLevel() {
        if (state.segments.length === 0) return
        navigate(state.segments.slice(0, state.segments.length - 1))
    }

    /** Adds a tag to the CURRENT level's segment, widening this same level. */
    function widenLevel(tag) {
        if (state.segments.length === 0) return enterTogether([tag])
        var last = state.segments[state.segments.length - 1].slice()
        if (last.indexOf(tag) >= 0) return
        last.push(tag)
        if (last.length > MAX_WIDTH) {
            showToast(localize("このセグメントは満杯です", "This segment is full"))
            return
        }
        navigate(state.segments.slice(0, state.segments.length - 1).concat([last]))
    }

    function removeTag(segmentIndex, tag) {
        var segments = state.segments.map(function (s) { return s.slice() })
        segments[segmentIndex] = segments[segmentIndex].filter(function (t) { return t !== tag })
        navigate(segments)
    }

    function removeSegment(segmentIndex) {
        var segments = state.segments.map(function (s) { return s.slice() })
        segments.splice(segmentIndex, 1)
        navigate(segments)
    }

    function loadMore() {
        var contents = state.data.contents
        var forPath = state.data.tagPath
        setLoading(true)
        fetchState(state.segments, contents.offset + contents.limit, false, "page").then(function (data) {
            setLoading(false)
            // The selection may have changed while this was in flight; a page
            // of the old selection must not be appended to the new one.
            if (!state.data || state.data.tagPath !== forPath) return
            state.data.contents.items = state.data.contents.items.concat(data.contents.items)
            state.data.contents.offset = data.contents.offset
            state.data.contents.hasMore = data.contents.hasMore
            state.payloads[state.data.tagPath] = state.data
            // Purely additive: layoutBand is index-derived, so the positions
            // already on screen are a prefix of the new ones and no existing
            // dot moves.
            state.band = Layout.layoutBand(state.data.contents.items)
            state.stars = computeStars(state.data)
            rebuildSrList()
        }).catch(function (error) {
            setLoading(false)
            if (error.name === "AbortError") return
            showToast(localize("読み込みに失敗しました", "Failed to load"))
        })
    }

    // ---- star layer (dark theme only) --------------------------------------

    function buildStarLayer() {
        starLayer = null
        var colors = palette()
        if (!colors.star || cssW === 0) return
        var off = document.createElement("canvas")
        off.width = Math.ceil(cssW * 1.4)
        off.height = Math.ceil(cssH * 1.4)
        var octx = off.getContext("2d")
        var random = prng(0xC0FFEE)
        octx.fillStyle = colors.star
        for (var i = 0; i < 130; i++) {
            octx.globalAlpha = 0.12 + random() * 0.5
            octx.beginPath()
            octx.arc(random() * off.width, random() * off.height, 0.4 + random() * 1.1, 0, TAU)
            octx.fill()
        }
        starLayer = off
    }

    // ---- render -----------------------------------------------------------

    function ensureLoop() {
        if (running || document.hidden) return
        running = true
        drawErrors = 0      // a deliberate (re)start earns another chance
        requestAnimationFrame(frame)
    }

    function frame() {
        if (document.hidden) { running = false; return }
        try {
            stepCamera()
            draw()
            positionPopup()
            drawErrors = 0
        } catch (error) {
            // Keep the loop alive: one bad frame must not freeze the map for
            // good. Give up only if it keeps failing, so a persistent error
            // neither floods the console nor burns a core silently.
            drawErrors++
            if (drawErrors === 1 || drawErrors === MAX_DRAW_ERRORS) {
                console.error("TagMap draw error:", error)
            }
            if (drawErrors >= MAX_DRAW_ERRORS) {
                running = false
                showToast(localize("表示を停止しました", "Rendering stopped"), ensureLoop)
                return
            }
        }
        requestAnimationFrame(frame)
    }

    function draw() {
        if (!ctx) return
        var colors = palette()

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.fillStyle = colors.bg
        ctx.fillRect(0, 0, cssW, cssH)

        if (starLayer) {
            var ox = (-camera.x * camera.scale * 0.25) % starLayer.width
            var oy = (-camera.y * camera.scale * 0.25) % starLayer.height
            for (var x = ox - starLayer.width; x < cssW; x += starLayer.width) {
                for (var y = oy - starLayer.height; y < cssH; y += starLayer.height) {
                    ctx.drawImage(starLayer, x, y)
                }
            }
        }

        ctx.save()
        ctx.translate(cssW / 2, cssH / 2)
        ctx.scale(camera.scale, camera.scale)
        ctx.translate(-camera.x, -camera.y)

        drawGrid(colors)
        // Ancestors first, underneath: they are the context you came through.
        drawAncestors(colors)
        drawClouds(colors)
        drawContainer(colors)
        drawStars(colors)
        drawDrag(colors)

        ctx.restore()
        ctx.globalAlpha = 1

        // Text last, in screen space, collision-checked as one set.
        drawLabels(colors)
    }

    /**
     * Concentric guides inside the current level, rather than a cartesian
     * grid: distance from the centre is what means something here, and a
     * level-relative ring cannot develop a scale at which it disappears.
     */
    function drawGrid(colors) {
        if (state.segments.length === 0) return
        ctx.save()
        ctx.strokeStyle = colors.gridline
        ctx.lineWidth = hairline()
        ctx.globalAlpha = 0.7
        GUIDE_RINGS.forEach(function (share) {
            ctx.beginPath()
            ctx.arc(0, 0, LEVEL_R * share, 0, TAU)
            ctx.stroke()
        })
        ctx.restore()
    }

    /**
     * The levels you came through: each drawn as a boundary with its other
     * children still in place. That IS the trail -- the way back is a shape
     * you can see and zoom out into, not a line of dots -- and the siblings
     * around you are literally the ones you left behind.
     */
    function drawAncestors(colors) {
        var diagonal = Math.hypot(cssW, cssH)
        state.ancestors.forEach(function (ancestor) {
            var screenR = ancestor.r * camera.scale
            // Only while the level still reads as a circle. Past this, its
            // boundary is off-screen and its children are each larger than
            // the viewport -- a wall of arcs that says nothing and competes
            // with the level you are actually in. Zoom out and it returns.
            if (screenR > diagonal * 1.4) return

            ctx.save()
            ctx.globalAlpha = 0.5
            ctx.strokeStyle = colors.focusRing
            ctx.lineWidth = hairline() * 1.2
            ctx.beginPath()
            ctx.arc(ancestor.x, ancestor.y, ancestor.r, 0, TAU)
            ctx.stroke()

            // Outlines, not fills, and only at a size that still reads as a
            // circle: an ancestor's children are magnified by 1/slot.r, so
            // filling them turns the context into the loudest thing on
            // screen and it competes with the level you are actually in.
            var readable = Math.hypot(cssW, cssH) * 0.55
            ctx.globalAlpha = 0.45
            ctx.strokeStyle = colors.muted
            ctx.lineWidth = hairline()
            ancestor.children.forEach(function (child) {
                var childR = child.r * camera.scale
                if (childR < 3 || childR > readable) return
                var screen = worldToScreen(child.x, child.y)
                if (screen.x < -childR || screen.x > cssW + childR
                    || screen.y < -childR || screen.y > cssH + childR) return
                ctx.beginPath()
                ctx.arc(child.x, child.y, child.r, 0, TAU)
                ctx.stroke()
            })
            ctx.restore()
        })
    }

    /**
     * The current level's children, plus the name suggestions sitting in the
     * free band. Two states, deliberately far apart: a child of this
     * selection is filled with a ring, a name suggestion is dashed. Nothing
     * else is drawn here -- a tag that is not part of this level has no
     * position in it, which is why an unrelated circle can no longer overlap
     * a child and steal its taps.
     */
    function drawClouds(colors) {
        var nodes = []
        for (var tag in state.universe) nodes.push(state.universe[tag])
        nodes.sort(function (a, b) { return b.r - a.r })

        nodes.forEach(function (node) {
            var screen = worldToScreen(node.x, node.y)
            var screenR = node.r * camera.scale
            if (screen.x < -screenR || screen.x > cssW + screenR
                || screen.y < -screenR || screen.y > cssH + screenR) return

            var isSuggested = node.kind === "suggestion"

            ctx.save()
            ctx.translate(node.x, node.y)

            if (!isSuggested) {
                ctx.globalAlpha = 0.3
                ctx.fillStyle = colors.accent
                ctx.beginPath()
                ctx.arc(0, 0, node.r, 0, TAU)
                ctx.fill()
                ctx.globalAlpha = 1
                ctx.strokeStyle = colors.accentRing
                ctx.lineWidth = hairline() * 1.4
            } else {
                ctx.globalAlpha = 0.7
                ctx.strokeStyle = colors.accentRing
                ctx.lineWidth = hairline()
                ctx.setLineDash([7 / camera.scale, 5 / camera.scale])
            }
            ctx.beginPath()
            ctx.arc(0, 0, node.r, 0, TAU)
            ctx.stroke()
            ctx.setLineDash([])
            ctx.restore()
        })
        ctx.globalAlpha = 1

        drawInsideChildren(colors)
    }

    /**
     * The contents of a child group, drawn inside it. Same dot vocabulary as
     * the free band -- filled means tagged, hollow means matched by name --
     * so a dot means the same thing wherever it sits.
     */
    function drawInsideChildren(colors) {
        var r = Math.max(3, 4 / camera.scale)
        Object.keys(state.insideChildren).forEach(function (key) {
            var node = state.universe[key]
            if (!node || node.r * camera.scale < 18) return
            state.insideChildren[key].forEach(function (dot) {
                var screen = worldToScreen(dot.x, dot.y)
                if (screen.x < -20 || screen.x > cssW + 20
                    || screen.y < -20 || screen.y > cssH + 20) return
                ctx.save()
                ctx.strokeStyle = colors.focusRing
                ctx.lineWidth = hairline()
                ctx.beginPath()
                ctx.arc(dot.x, dot.y, r, 0, TAU)
                if (dot.item.suggested) {
                    ctx.fillStyle = colors.surface
                    ctx.fill()
                    ctx.setLineDash([2.5 / camera.scale, 2 / camera.scale])
                } else {
                    ctx.fillStyle = colors.focus
                    ctx.fill()
                }
                ctx.stroke()
                ctx.restore()
            })
        })
    }

    /**
     * The selection: one boundary holding everything gathered inside it,
     * whatever combination of tags produced it.
     */
    function drawContainer(colors) {
        var container = containerRegion()
        if (!container) return
        ctx.save()
        ctx.globalAlpha = 0.07
        ctx.fillStyle = colors.focus
        ctx.beginPath()
        ctx.arc(container.x, container.y, container.r, 0, TAU)
        ctx.fill()
        ctx.globalAlpha = 1
        ctx.strokeStyle = colors.focusRing
        ctx.lineWidth = hairline() * 2.5
        ctx.beginPath()
        ctx.arc(container.x, container.y, container.r, 0, TAU)
        ctx.stroke()
        ctx.restore()
    }

    /**
     * Direct contents, as dots on the inside of the boundary. A content that
     * matched by name similarity rather than by a tag is drawn hollow: it is
     * not tagged with what was selected, and saying so is the distinction the
     * server-rendered view used to make with a "Suggested" badge.
     */
    function drawStars(colors) {
        if (camera.scale < STAR_MIN_SCALE) return
        var r = Math.max(STAR_R, 5 / camera.scale)

        state.stars.forEach(function (star) {
            var position = starPosition(star)
            var screen = worldToScreen(position.x, position.y)
            if (screen.x < -60 || screen.x > cssW + 60 || screen.y < -60 || screen.y > cssH + 60) return

            ctx.save()
            ctx.translate(position.x, position.y)
            ctx.strokeStyle = colors.focusRing
            ctx.lineWidth = hairline()
            ctx.beginPath()
            ctx.arc(0, 0, r, 0, TAU)
            if (star.item.suggested) {
                ctx.fillStyle = colors.surface
                ctx.fill()
                ctx.setLineDash([3 / camera.scale, 2.5 / camera.scale])
            } else {
                ctx.fillStyle = colors.focus
                ctx.fill()
            }
            ctx.stroke()
            ctx.restore()
        })
    }

    /**
     * How far in we are, relative to the default framing. 1 = the level fits
     * as fitCurrentLevel() left it; above that, detail earns its space.
     */
    function zoomFactor() {
        var fit = (Math.min(cssW, cssH) * CONTAINER_FILL) / (2 * LEVEL_R)
        return camera.scale / fit
    }

    /**
     * All text in one screen-space pass, so nothing ever lands on top of
     * anything else: whoever asks first wins the space, in priority order —
     * the selection, then the clouds inside it by size, then contents, then
     * the surrounding map. Text that does not fit is simply not drawn, and
     * reappears as soon as zooming in makes room for it. This is the only
     * place in the renderer that draws text.
     */
    function drawLabels(colors) {
        var boxes = []
        var container = containerRegion()
        var factor = zoomFactor()

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.textAlign = "left"
        ctx.textBaseline = "middle"

        // The floating chrome owns its rectangles: seeding them means a label
        // can never land under the header or the breadcrumb.
        chromeRects().forEach(function (rect) { boxes.push(rect) })

        function place(lines, screenX, screenY, options) {
            options = options || {}
            if (boxes.length >= LABEL_MAX_BOXES) return false
            // Off-screen labels used to consume the budget and starve the
            // visible ones, so content titles never appeared at all.
            if (screenX < -80 || screenX > cssW + 80 || screenY < -60 || screenY > cssH + 60) {
                return false
            }
            var padding = options.boxed ? 6 : 3
            var lineHeight = options.lineHeight || 16
            var width = 0
            lines.forEach(function (line) {
                ctx.font = line.font
                width = Math.max(width, ctx.measureText(line.text).width)
            })
            var box = {
                x: options.center ? screenX - width / 2 - padding : screenX + 10,
                y: screenY - (lines.length * lineHeight) / 2 - padding,
                w: width + padding * 2,
                h: lines.length * lineHeight + padding * 2,
            }
            for (var i = 0; i < boxes.length; i++) {
                var other = boxes[i]
                if (box.x < other.x + other.w && box.x + box.w > other.x
                    && box.y < other.y + other.h && box.y + box.h > other.y) return false
            }
            boxes.push(box)

            if (options.boxed) {
                ctx.globalAlpha = 0.94
                ctx.fillStyle = colors.surface
                ctx.strokeStyle = colors.gridline
                ctx.lineWidth = 1
                roundedRect(ctx, box.x, box.y, box.w, box.h, 5)
                ctx.fill()
                ctx.stroke()
                ctx.globalAlpha = 1
            }
            var textY = box.y + padding + lineHeight / 2
            lines.forEach(function (line) {
                ctx.font = line.font
                ctx.fillStyle = line.color
                ctx.fillText(line.text, box.x + padding, textY)
                textY += lineHeight
            })
            return true
        }

        // 1. The selection: what it is and how much it holds. Every number
        // here names a stats field; none is derived from the loaded page.
        if (container && state.data) {
            var top = worldToScreen(container.x, container.y - container.r)
            var stats = state.data.stats || {}
            var caption = stats.totalContents + localize("件", " items")
            if (typeof stats.directContents === "number" && stats.directContents > 0) {
                caption += localize(
                    "・直下 " + stats.directContents + "件",
                    " · " + stats.directContents + " directly here"
                )
            }
            // The groups' counts do not add up to the total, because a
            // content carrying two of them is in both. Say the distinct
            // figure and say that they overlap, rather than leaving the
            // reader to try the arithmetic and fail.
            if (typeof stats.childContents === "number" && stats.childContents > 0) {
                caption += localize(
                    "・子タグの中 " + stats.childContents + "件",
                    " · " + stats.childContents + " in child tags"
                )
                if (state.members.length > 0) {
                    caption += localize(
                        "（" + state.members.length + "グループ・重複あり）",
                        " (" + state.members.length + " groups, overlapping)"
                    )
                }
            }
            var captionLines = [
                { text: segmentsLabel(state.segments), font: "700 20px system-ui, sans-serif", color: colors.ink },
                { text: caption, font: "600 13px system-ui, sans-serif", color: colors.inkSecondary },
            ]
            // Why a drill-down can come back with more than the tag's own
            // count promised: part of this set matched by name, not by tag.
            if (typeof stats.explicitContents === "number"
                && stats.explicitContents < stats.totalContents) {
                var fuzzy = stats.totalContents - stats.explicitContents
                captionLines.push({
                    text: localize(
                        "うち " + fuzzy + "件は類似名タグから",
                        fuzzy + " matched by similar tag name"
                    ),
                    font: "11px system-ui, sans-serif",
                    color: colors.muted,
                })
            }
            place(captionLines, top.x, top.y - 32, { center: true, lineHeight: 22 })
        }

        // 2. The clouds gathered inside, biggest first.
        state.members.slice().sort(function (a, b) {
            var na = state.universe[a.tag], nb = state.universe[b.tag]
            return (nb ? nb.r : 0) - (na ? na.r : 0)
        }).forEach(function (entry) {
            var node = state.universe[entry.tag]
            if (!node || node.r * camera.scale < LABEL_MIN_SCREEN_R) return
            var screen = worldToScreen(node.x, node.y)
            place([
                { text: node.tag, font: "600 14px system-ui, sans-serif", color: colors.ink },
                // "of these, N" -- a fact about the current set, matching the
                // quantity this circle's size encodes here.
                {
                    text: localize("このうち " + entry.count + "件", entry.count + " of these"),
                    font: "12px system-ui, sans-serif",
                    color: colors.inkSecondary,
                },
            ], screen.x, screen.y, { center: true, lineHeight: 16 })
        })

        // 2a. Contents shown inside a child group, once zoomed enough that
        // their circle has room for a name.
        if (factor >= 1) {
            Object.keys(state.insideChildren).forEach(function (key) {
                var node = state.universe[key]
                if (!node || node.r * camera.scale < 40) return
                state.insideChildren[key].forEach(function (dot) {
                    var screen = worldToScreen(dot.x, dot.y)
                    ctx.font = "11px system-ui, sans-serif"
                    place([{
                        text: ellipsize(dot.item.title, 160, ctx),
                        font: "11px system-ui, sans-serif",
                        color: colors.ink,
                    }], screen.x, screen.y, { boxed: true, lineHeight: 13 })
                })
            })
        }

        // 2b. Name suggestions. They MUST be named: a dashed circle with no
        // label is a shape the reader cannot interpret, and these are the
        // ones that need explaining most -- a similar NAME, which may share
        // no content with the selection at all.
        var suggestionNodes = []
        for (var suggestedTag in state.universe) {
            if (state.universe[suggestedTag].kind === "suggestion") {
                suggestionNodes.push(state.universe[suggestedTag])
            }
        }
        suggestionNodes.forEach(function (node) {
            var screen = worldToScreen(node.x, node.y)
            var lines = [
                {
                    text: localize("もしかして", "Similar name"),
                    font: "10px system-ui, sans-serif",
                    color: colors.muted,
                },
                { text: node.tag, font: "600 13px system-ui, sans-serif", color: colors.ink },
            ]
            // `count` is the truth about sharing, and it is often zero. Say
            // which case this is rather than let the shape imply membership.
            lines.push({
                text: node.count > 0
                    ? localize("このうち " + node.count + "件", node.count + " of these")
                    : localize("共通なし", "nothing in common"),
                font: "11px system-ui, sans-serif",
                color: colors.muted,
            })
            place(lines, screen.x, screen.y - node.r * camera.scale - 4,
                { center: true, boxed: true, lineHeight: 14 })
        })

        // 3. Contents: the title, plus the summary once zoomed past the fit.
        if (camera.scale >= STAR_MIN_SCALE) {
            state.stars.forEach(function (star) {
                var position = starPosition(star)
                var screen = worldToScreen(position.x, position.y)
                if (screen.x < -60 || screen.x > cssW + 60 || screen.y < -60 || screen.y > cssH + 60) return
                ctx.font = "600 12px system-ui, sans-serif"
                var lines = [{
                    text: ellipsize(star.item.title, 240, ctx),
                    font: "600 12px system-ui, sans-serif",
                    color: colors.ink,
                }]
                if (factor >= SUMMARY_ZOOM) {
                    if (star.item._plain === undefined) {
                        star.item._plain = star.item.summary ? stripHtml(star.item.summary) : ""
                    }
                    if (star.item._plain) {
                        ctx.font = "11px system-ui, sans-serif"
                        wrapText(ctx, star.item._plain, 240, 2).forEach(function (text) {
                            lines.push({ text: text, font: "11px system-ui, sans-serif", color: colors.inkSecondary })
                        })
                    }
                }
                place(lines, screen.x, screen.y, { boxed: true, lineHeight: 15 })
            })
        }

        // 4. The siblings you left behind, so the way out stays named.
        var diagonal = Math.hypot(cssW, cssH)
        var others = []
        state.ancestors.forEach(function (ancestor) {
            // Same cull as drawAncestors: name only what is drawn.
            if (ancestor.r * camera.scale > diagonal * 1.4) return
            ancestor.children.forEach(function (child) { others.push(child) })
        })
        others.sort(function (a, b) { return b.r - a.r })
        var readableR = diagonal * 0.55
        others.forEach(function (node) {
            var screenR = node.r * camera.scale
            // Named only while the circle it names is actually legible.
            if (screenR < LABEL_MIN_SCREEN_R || screenR > readableR) return
            var screen = worldToScreen(node.x, node.y)
            place([
                { text: node.tag, font: "600 13px system-ui, sans-serif", color: colors.muted },
            ], screen.x, screen.y, { center: true, lineHeight: 15 })
        })

        // 5. The drop-target hint, last so it always gets its space: it is
        // feedback on what your finger is doing right now.
        if (dragHint) {
            boxes.length = 0
            place([{ text: dragHint.text, font: "700 14px system-ui, sans-serif", color: colors.ink }],
                dragHint.x, dragHint.y - 28, { center: true, boxed: true, lineHeight: 18 })
            dragHint = null
        }

        ctx.textBaseline = "alphabetic"
    }

    /**
     * Screen rectangles the floating DOM chrome occupies. Measured per frame
     * from the live elements, so a breadcrumb that wraps to two lines is
     * accounted for without anything to keep in sync.
     */
    function chromeRects() {
        var rects = []
        var add = function (element) {
            if (!element) return
            var rect = element.getBoundingClientRect()
            if (rect.width > 0 && rect.height > 0) {
                rects.push({ x: rect.left, y: rect.top, w: rect.width, h: rect.height })
            }
        }
        add(document.getElementById("header"))
        add(elements.breadcrumb)
        return rects
    }

    function stripHtml(html) {
        var element = document.createElement("div")
        element.innerHTML = html
        return (element.textContent || "").replace(/\s+/g, " ").trim()
    }

    function wrapText(context, text, maxWidth, maxLines) {
        var lines = []
        var line = ""
        for (var i = 0; i < text.length && lines.length < maxLines; i++) {
            var candidate = line + text[i]
            if (context.measureText(candidate).width > maxWidth && line !== "") {
                lines.push(line)
                line = text[i]
            } else {
                line = candidate
            }
        }
        if (lines.length < maxLines && line !== "") lines.push(line)
        return lines
    }

    function drawDrag(colors) {
        if (!drag) return
        var source = state.universe[drag.tag]
        if (!source) return
        var world = screenToWorld(drag.x, drag.y)

        ctx.save()
        ctx.strokeStyle = colors.accentRing
        ctx.lineWidth = hairline() * 1.5
        ctx.setLineDash([6 / camera.scale, 4 / camera.scale])
        ctx.beginPath()
        ctx.moveTo(source.x, source.y)
        ctx.lineTo(world.x, world.y)
        ctx.stroke()
        ctx.setLineDash([])

        ctx.globalAlpha = 0.5
        ctx.strokeStyle = colors.accentRing
        ctx.beginPath()
        ctx.arc(world.x, world.y, source.r * 0.35, 0, TAU)
        ctx.stroke()
        ctx.globalAlpha = 1

        if (drag.target) {
            var mark = drag.target.kind === "out"
                ? containerRegion()
                : state.universe[drag.target.tag]
            if (mark) {
                ctx.strokeStyle = colors.focusRing
                ctx.lineWidth = hairline() * 3
                ctx.beginPath()
                ctx.arc(mark.x, mark.y, mark.r * 1.04, 0, TAU)
                ctx.stroke()
            }
        }
        ctx.restore()

        // The hint is screen text, so it belongs to the label pass, not here:
        // drawn inside the world transform its size fought the zoom.
        if (drag.target) {
            dragHint = {
                text: drag.target.kind === "out"
                    ? localize("ここから出る", "Leave this level")
                    : localize("一緒に見る", "View together"),
                x: drag.x,
                y: drag.y,
            }
        }
    }

    function roundedRect(context, x, y, w, h, r) {
        context.beginPath()
        context.moveTo(x + r, y)
        context.arcTo(x + w, y, x + w, y + h, r)
        context.arcTo(x + w, y + h, x, y + h, r)
        context.arcTo(x, y + h, x, y, r)
        context.arcTo(x, y, x + w, y, r)
        context.closePath()
    }

    function ellipsize(text, maxWidth, context) {
        if (context.measureText(text).width <= maxWidth) return text
        var result = text
        while (result.length > 1 && context.measureText(result + "…").width > maxWidth) {
            result = result.slice(0, -1)
        }
        return result + "…"
    }

    // ---- hit testing ------------------------------------------------------

    /**
     * A content dot: the free band's, or one shown inside a child group.
     * Inside-a-group dots come first because they sit on top of that group's
     * circle, which is what the eye reads them as belonging to.
     */
    function pickStar(px, py) {
        if (camera.scale < STAR_MIN_SCALE) return null
        var world = screenToWorld(px, py)
        var insideHit = null
        var insideR = Math.max(4, 14 / camera.scale)
        Object.keys(state.insideChildren).forEach(function (key) {
            state.insideChildren[key].forEach(function (dot) {
                if (insideHit) return
                if (Math.hypot(world.x - dot.x, world.y - dot.y) <= insideR) {
                    insideHit = { item: dot.item, index: -1 }
                }
            })
        })
        if (insideHit) return insideHit

        var hitR = Math.max(STAR_R, 18 / camera.scale)
        for (var i = 0; i < state.stars.length; i++) {
            var position = starPosition(state.stars[i])
            if (Math.hypot(world.x - position.x, world.y - position.y) <= hitR) return state.stars[i]
        }
        return null
    }

    function pickCloud(px, py, excludeTag) {
        var world = screenToWorld(px, py)
        // Selected tags are drawn by drawContainer(), not as clouds, so they
        // must not be pickable either: an invisible disc at the focus centre
        // blocked panning inside your own selection and allowed A/A.
        var selected = selectedTags().reduce(function (set, tag) {
            set[tag] = true
            return set
        }, {})
        var nodes = []
        for (var tag in state.universe) {
            if (tag === excludeTag || selected[tag]) continue
            nodes.push(state.universe[tag])
        }
        // Smallest first: a small cloud sitting inside a big one is reachable.
        nodes.sort(function (a, b) { return a.r - b.r })
        for (var i = 0; i < nodes.length; i++) {
            if (Math.hypot(world.x - nodes[i].x, world.y - nodes[i].y) <= nodes[i].r) return nodes[i]
        }
        return null
    }

    // The drop target for "narrow down": the inner area of the selection.
    // The whole interior, which is exactly what drawDrag highlights. Null at
    // the root, where there is nothing to narrow.
    function pickContainer(px, py) {
        var container = containerRegion()
        if (!container) return null
        var world = screenToWorld(px, py)
        return Math.hypot(world.x - container.x, world.y - container.y) <= container.r
            ? container : null
    }

    /**
     * An ancestor's boundary or one of its remaining children: the way out.
     * Tapping a sibling jumps straight to it, which is what it looks like.
     */
    function pickAncestor(px, py) {
        var world = screenToWorld(px, py)
        var hit = null
        state.ancestors.forEach(function (ancestor) {
            ancestor.children.forEach(function (child) {
                if (Math.hypot(world.x - child.x, world.y - child.y) <= child.r) {
                    if (!hit || child.r < hit.r) {
                        hit = { r: child.r, segments: ancestor.segments.concat([[child.tag]]) }
                    }
                }
            })
        })
        if (hit) return hit
        // Otherwise: the nearest ancestor boundary we are standing inside.
        for (var i = 0; i < state.ancestors.length; i++) {
            var ancestor = state.ancestors[i]
            var ringDistance = Math.abs(
                Math.hypot(world.x - ancestor.x, world.y - ancestor.y) - ancestor.r
            )
            if (ringDistance * camera.scale <= RING_HIT) {
                return { r: ancestor.r, segments: ancestor.segments }
            }
        }
        return null
    }

    // ---- popup / info card ------------------------------------------------

    function openPopup(node) {
        popupTarget = node
        var popup = elements.popup
        popup.textContent = ""

        var title = document.createElement("div")
        title.className = "tagmap-popup-title"
        title.textContent = node.tag
        popup.appendChild(title)

        // Read the tag's own payload entry. No fallback to a different field:
        // substituting the global `total` for a missing `count` promised
        // content that a drill-down could not deliver.
        var entry = tagEntry(node.tag)
        var atRoot = state.segments.length === 0
        var atMaxDepth = state.segments.length >= MAX_DEPTH
        var last = state.segments[state.segments.length - 1]
        var atMaxWidth = last ? last.length >= MAX_WIDTH : false
        var isSelected = selectedTags().indexOf(node.tag) >= 0
        var andCount = entry ? entry.count : null

        var narrow = document.createElement("button")
        narrow.className = "tagmap-popup-action"
        // "Of the contents here, N carry this tag" -- a fact about the current
        // set. It is a lower bound on the next view, which also admits
        // similar-name matches; see the note under the header.
        narrow.textContent = "▶ " + localize("この中に入る", "Enter")
            + (andCount === null ? "" : " — " + localize("このうち ", "") + andCount + localize("件", ""))
        if (atMaxDepth || andCount === 0 || isSelected) {
            narrow.disabled = true
            narrow.title = atMaxDepth
                ? localize("これ以上絞り込めません", "Cannot narrow down further")
                : (isSelected
                    ? localize("すでに選択されています", "Already selected")
                    : localize("共通のコンテンツがありません", "No shared contents"))
        } else {
            narrow.onclick = function () { closePopup(); narrowDown(node.tag) }
        }
        popup.appendChild(narrow)

        if (!atRoot && !isSelected) {
            // A delta against orBase, not against the header total: both are
            // computed without similar-name matches, so the delta cannot go
            // negative. Comparing orCount with the header (which includes
            // them) made OR-adding a tag look like it shrank the set.
            var orBase = state.data && state.data.stats ? state.data.stats.orBase : null
            var delta = (entry && typeof entry.orCount === "number" && typeof orBase === "number")
                ? entry.orCount - orBase
                : null
            var join = document.createElement("button")
            join.className = "tagmap-popup-action"
            join.textContent = "＋ " + localize("一緒に見る", "View together")
                + (delta === null ? "" : " — ＋" + delta + localize("件", ""))
            if (atMaxWidth) {
                join.disabled = true
                join.title = localize("このセグメントは満杯です", "This segment is full")
            } else {
                // Widens THIS level, matching the number shown (orCount is
                // measured against the parent, i.e. this same segment).
                join.onclick = function () { closePopup(); widenLevel(node.tag) }
            }
            popup.appendChild(join)
        }

        popup.classList.add("visible")
        positionPopup()
    }

    function positionPopup() {
        if (!popupTarget) return
        var popup = elements.popup
        var screen = worldToScreen(popupTarget.x, popupTarget.y)
        var nodeR = popupTarget.r * camera.scale
        var width = popup.offsetWidth || 190
        var height = popup.offsetHeight || 90
        var below = screen.y + Math.min(nodeR, 120) + 10
        var x = Math.min(Math.max(8, screen.x - width / 2), Math.max(8, cssW - width - 8))
        var y = below + height > cssH - 8
            ? Math.max(8, screen.y - Math.min(nodeR, 120) - height - 10)
            : below
        popup.style.transform = "translate(" + Math.round(x) + "px," + Math.round(y) + "px)"
    }

    function closePopup() {
        popupTarget = null
        if (elements.popup) elements.popup.classList.remove("visible")
    }

    function openInfoCard(star) {
        var item = star.item
        var card = elements.infoCard
        card.textContent = ""

        var title = document.createElement("div")
        title.className = "tagmap-card-title"
        title.textContent = item.title + (item.parentTitle ? " | " + item.parentTitle : "")
        card.appendChild(title)

        // Do not let a name match pass for a tag match.
        if (item.suggested) {
            var badge = document.createElement("div")
            badge.className = "tagmap-badge"
            badge.textContent = localize(
                "類似名タグからの候補",
                "matched by similar tag name"
            )
            card.appendChild(badge)
        }

        if (item.summary) {
            var summary = document.createElement("div")
            summary.className = "tagmap-card-summary"
            summary.innerHTML = item.summary // server-rendered summary HTML
            card.appendChild(summary)
        }

        var actions = document.createElement("div")
        actions.className = "tagmap-card-actions"
        var open = document.createElement("a")
        open.href = item.url
        open.textContent = localize("開く", "Open")
        actions.appendChild(open)
        var close = document.createElement("button")
        close.textContent = localize("閉じる", "Close")
        close.onclick = closeInfoCard
        actions.appendChild(close)
        card.appendChild(actions)

        card.classList.add("visible")
    }

    function closeInfoCard() {
        if (elements.infoCard) elements.infoCard.classList.remove("visible")
    }

    // ---- gestures ---------------------------------------------------------

    var pointers = {}
    var gesture = null

    function canvasLeft() { return elements.canvasRect ? elements.canvasRect.left : 0 }
    function canvasTop() { return elements.canvasRect ? elements.canvasRect.top : 0 }

    function setupGestures() {
        var canvas = elements.canvas

        canvas.addEventListener("pointerdown", function (event) {
            canvas.setPointerCapture(event.pointerId)
            pointers[event.pointerId] = { x: event.clientX, y: event.clientY }
            var ids = Object.keys(pointers)
            if (ids.length === 2) {
                drag = null
                var a = pointers[ids[0]], b = pointers[ids[1]]
                gesture = {
                    mode: "pinch",
                    startDist: Math.hypot(a.x - b.x, a.y - b.y),
                    startScale: camera.scale,
                    anchor: screenToWorld((a.x + b.x) / 2 - canvasLeft(), (a.y + b.y) / 2 - canvasTop()),
                }
                cameraTarget = null
                return
            }
            if (ids.length !== 1) return

            var px = event.clientX - canvasLeft()
            var py = event.clientY - canvasTop()
            var cloud = pickCloud(px, py)
            gesture = {
                mode: cloud ? "node" : "pan",
                tag: cloud ? cloud.tag : null,
                startX: event.clientX, startY: event.clientY,
                startedAt: performance.now(),
                camX: camera.x, camY: camera.y,
                moved: false,
            }
        })

        canvas.addEventListener("pointermove", function (event) {
            var pointer = pointers[event.pointerId]
            if (!pointer || !gesture) return
            pointer.x = event.clientX
            pointer.y = event.clientY

            if (gesture.mode === "pinch") {
                var ids = Object.keys(pointers)
                if (ids.length < 2) return
                var a = pointers[ids[0]], b = pointers[ids[1]]
                var scale = clampScale(gesture.startScale * Math.hypot(a.x - b.x, a.y - b.y) / gesture.startDist)
                var mid = { x: (a.x + b.x) / 2 - canvasLeft(), y: (a.y + b.y) / 2 - canvasTop() }
                camera.scale = scale
                camera.x = gesture.anchor.x - (mid.x - cssW / 2) / scale
                camera.y = gesture.anchor.y - (mid.y - cssH / 2) / scale
                return
            }

            var dx = event.clientX - gesture.startX
            var dy = event.clientY - gesture.startY
            var distance = Math.hypot(dx, dy)

            if (gesture.mode === "pan") {
                if (distance > TAP_MOVE_PX) gesture.moved = true
                cameraTarget = null
                camera.x = gesture.camX - dx / camera.scale
                camera.y = gesture.camY - dy / camera.scale
                return
            }

            // Dragging a child: onto a sibling = enter both together, out
            // past the boundary = leave this level.
            if (distance > DRAG_START_PX) {
                gesture.moved = true
                var px = event.clientX - canvasLeft()
                var py = event.clientY - canvasTop()
                // A sibling wins over the interior: children are packed well
                // inside it, so testing the interior first made the ones near
                // the centre impossible to drop onto.
                var target = null
                var cloud = pickCloud(px, py, gesture.tag)
                if (cloud) {
                    target = { kind: "cloud", tag: cloud.tag }
                } else if (!pickContainer(px, py)) {
                    target = { kind: "out" }
                }
                drag = { tag: gesture.tag, x: px, y: py, target: target }
            }
        })

        function endPointer(event) {
            var pointer = pointers[event.pointerId]
            delete pointers[event.pointerId]
            if (!gesture) { drag = null; return }

            if (gesture.mode === "pinch") {
                if (Object.keys(pointers).length < 2) gesture = null
                return
            }

            var quick = performance.now() - gesture.startedAt < TAP_MS
            if (gesture.mode === "node" && drag) {
                var target = drag.target
                var sourceTag = drag.tag
                drag = null
                gesture = null
                // A new level holding both, NOT a wider version of this one:
                // widening would select a superset of what is on screen.
                if (target && target.kind === "cloud") enterTogether([sourceTag, target.tag])
                else if (target && target.kind === "out") leaveLevel()
                return
            }
            if (!gesture.moved && quick && pointer) {
                handleTap(event.clientX - canvasLeft(), event.clientY - canvasTop())
            }
            drag = null
            gesture = null
        }
        canvas.addEventListener("pointerup", endPointer)

        /**
         * A cancelled gesture must NOT commit. An Android long-press menu, an
         * OS edge swipe or a lost capture all arrive here, and running the
         * drop would perform a navigation the user explicitly aborted.
         */
        function abortGesture(event) {
            if (event && event.pointerId !== undefined) delete pointers[event.pointerId]
            else pointers = {}
            drag = null
            dragHint = null
            gesture = null
        }
        canvas.addEventListener("pointercancel", abortGesture)
        canvas.addEventListener("lostpointercapture", abortGesture)
        window.addEventListener("blur", function () { abortGesture(null) })

        canvas.addEventListener("wheel", function (event) {
            event.preventDefault()
            cameraTarget = null
            var factor = Math.exp(-event.deltaY * 0.0012)
            var px = event.clientX - canvasLeft()
            var py = event.clientY - canvasTop()
            var anchor = screenToWorld(px, py)
            camera.scale = clampScale(camera.scale * factor)
            camera.x = anchor.x - (px - cssW / 2) / camera.scale
            camera.y = anchor.y - (py - cssH / 2) / camera.scale
        }, { passive: false })

        // iOS Safari: block the page pinch while over the canvas only.
        canvas.addEventListener("gesturestart", function (event) { event.preventDefault() })
    }

    /**
     * One ordered list, so nothing can be drawn in a place where something
     * else answers for it. Smallest-first inside each layer, and the current
     * level always beats the context behind it.
     */
    function handleTap(px, py) {
        if (popupTarget || (elements.infoCard && elements.infoCard.classList.contains("visible"))) {
            closePopup()
            closeInfoCard()
            return
        }
        var star = pickStar(px, py)
        if (star) { openInfoCard(star); return }
        var cloud = pickCloud(px, py)
        if (cloud) { openPopup(cloud); return }
        // Inside the boundary but on none of them: page the contents.
        if (pickContainer(px, py)) {
            if (state.data && state.data.contents.hasMore) loadMore()
            return
        }
        // Outside it: the context behind, which is the way back out.
        var ancestor = pickAncestor(px, py)
        if (ancestor) navigate(ancestor.segments)
    }

    // ---- chrome -----------------------------------------------------------

    /**
     * Fetching never blocks or dims anything: the map stays interactive and
     * only a thin line at the top edge says that something is on its way.
     */
    function setLoading(loading) {
        clearTimeout(loadingTimer)
        if (loading) {
            loadingTimer = setTimeout(function () {
                elements.progress.classList.add("visible")
            }, 200)
        } else {
            elements.progress.classList.remove("visible")
        }
    }

    function showToast(message, onRetry) {
        var toast = elements.toast
        toast.textContent = ""
        var text = document.createElement("span")
        text.textContent = message
        toast.appendChild(text)
        if (onRetry) {
            var retry = document.createElement("button")
            retry.textContent = localize("再試行", "Retry")
            retry.onclick = function () { toast.classList.remove("visible"); onRetry() }
            toast.appendChild(retry)
        }
        toast.classList.add("visible")
        clearTimeout(toast._timer)
        toast._timer = setTimeout(function () { toast.classList.remove("visible") }, 6000)
    }

    function renderBreadcrumb() {
        var container = elements.breadcrumb
        container.textContent = ""

        var rootChip = document.createElement("button")
        rootChip.className = "tagmap-chip tagmap-chip-root" + (state.segments.length === 0 ? " current" : "")
        rootChip.textContent = "TagMap"
        rootChip.onclick = function () { navigate([]) }
        container.appendChild(rootChip)

        var chipCounts = {}
        if (state.data) {
            (state.data.chipTags || []).forEach(function (c) { chipCounts[c.tag] = c.count })
        }

        state.segments.forEach(function (segment, segmentIndex) {
            var isLast = segmentIndex === state.segments.length - 1
            var box = document.createElement("span")
            box.className = "tagmap-segment"

            segment.forEach(function (tag) {
                var chip = document.createElement("span")
                chip.className = "tagmap-chip"
                var label = document.createElement("span")
                label.textContent = tag + (isLast && chipCounts[tag] !== undefined ? " " + chipCounts[tag] : "")
                chip.appendChild(label)
                var remove = document.createElement("button")
                remove.className = "tagmap-chip-remove"
                remove.setAttribute("aria-label", "remove " + tag)
                remove.textContent = "×"
                remove.onclick = function () { removeTag(segmentIndex, tag) }
                chip.appendChild(remove)
                box.appendChild(chip)
            })

            var removeSegmentButton = document.createElement("button")
            removeSegmentButton.className = "tagmap-segment-remove"
            removeSegmentButton.setAttribute("aria-label", "remove segment")
            removeSegmentButton.textContent = "×"
            removeSegmentButton.onclick = function () { removeSegment(segmentIndex) }
            box.appendChild(removeSegmentButton)

            container.appendChild(box)
            if (!isLast) {
                var separator = document.createElement("span")
                separator.className = "tagmap-separator"
                separator.textContent = "/"
                container.appendChild(separator)
            }
        })
    }

    // Hidden mirror of the scene: screen readers, and a deterministic E2E hook.
    function rebuildSrList() {
        var list = elements.srList
        list.textContent = ""
        if (!state.data) return
        // Same numbers, same wording as the canvas: `count` in the present
        // tense, OR as a delta from stats.orBase.
        var orBase = state.data.stats ? state.data.stats.orBase : null
        function addTag(entry) {
            var row = document.createElement("div")
            row.setAttribute("role", "listitem")
            if (entry.count > 0) {
                var narrow = document.createElement("button")
                narrow.textContent = entry.tag + " " + localize("で絞り込む", "narrow down")
                    + " (" + localize("このうち ", "") + entry.count + ")"
                narrow.setAttribute("data-tagmap-action", "and")
                narrow.setAttribute("data-tag", entry.tag)
                narrow.onclick = function () { narrowDown(entry.tag) }
                row.appendChild(narrow)
            }
            if (typeof entry.orCount === "number" && typeof orBase === "number") {
                var join = document.createElement("button")
                join.textContent = entry.tag + " " + localize("を一緒に見る", "view together")
                    + " (+" + (entry.orCount - orBase) + ")"
                join.setAttribute("data-tagmap-action", "or")
                join.setAttribute("data-tag", entry.tag)
                join.onclick = function () { widenLevel(entry.tag) }
                row.appendChild(join)
            }
            if (row.childNodes.length > 0) list.appendChild(row)
        }
        (state.data.coTags || []).forEach(addTag)
        ;(state.data.suggestedTags || []).forEach(addTag)
        ;(state.data.contents.items || []).forEach(function (item) {
            var row = document.createElement("div")
            row.setAttribute("role", "listitem")
            var link = document.createElement("a")
            link.href = item.url
            link.textContent = item.title
                + (item.suggested ? localize("（類似名タグから）", " (similar tag name)") : "")
            row.appendChild(link)
            list.appendChild(row)
        })
    }

    function updateNote() {
        if (!state.data) return
        var parts = []
        if (state.data.totalCoTags > (state.data.coTags || []).length) {
            parts.push(localize("上位", "Top ") + state.data.coTags.length + " / " + state.data.totalCoTags)
        }
        parts.push(localize(
            "タップ＝入る／隣へドラッグ＝一緒に入る／外へドラッグ＝出る",
            "Tap to enter · drag onto a neighbour to enter both · drag out to leave"
        ))
        elements.note.textContent = parts.join("  ·  ")
    }

    function buildChrome() {
        var app = elements.app
        app.textContent = ""

        elements.canvas = document.createElement("canvas")
        elements.canvas.className = "tagmap-canvas"
        elements.canvas.setAttribute("role", "img")
        elements.canvas.setAttribute("aria-label", localize("タグマップ", "Tag map"))
        app.appendChild(elements.canvas)
        ctx = elements.canvas.getContext("2d")

        elements.breadcrumb = document.createElement("div")
        elements.breadcrumb.className = "tagmap-breadcrumb"
        app.appendChild(elements.breadcrumb)

        elements.popup = document.createElement("div")
        elements.popup.className = "tagmap-popup"
        app.appendChild(elements.popup)

        elements.infoCard = document.createElement("div")
        elements.infoCard.className = "tagmap-info-card"
        app.appendChild(elements.infoCard)

        elements.note = document.createElement("div")
        elements.note.className = "tagmap-field-note"
        app.appendChild(elements.note)

        elements.progress = document.createElement("div")
        elements.progress.className = "tagmap-progress"
        app.appendChild(elements.progress)

        elements.toast = document.createElement("div")
        elements.toast.className = "tagmap-toast"
        app.appendChild(elements.toast)

        elements.srList = document.createElement("div")
        elements.srList.className = "tagmap-sr-only"
        elements.srList.setAttribute("role", "list")
        app.appendChild(elements.srList)

        setupGestures()
        resizeCanvas()
    }

    function resizeCanvas() {
        var rect = elements.canvas.getBoundingClientRect()
        elements.canvasRect = rect
        cssW = rect.width
        cssH = rect.height
        dpr = Math.min(2, window.devicePixelRatio || 1)
        elements.canvas.width = Math.round(cssW * dpr)
        elements.canvas.height = Math.round(cssH * dpr)
        buildStarLayer()
    }

    // ---- bootstrap --------------------------------------------------------

    /**
     * Rejects an inline initial state written by an older server (a stale
     * cached page whose shape this build cannot read). Probes the newest
     * fields, not merely the presence of an array.
     */
    function isCurrentShape(data) {
        if (!data || !data.coTags || !data.stats || !data.contents) return false
        if (!Array.isArray(data.segments)) return false
        if (data.stats.directContents === undefined) return false
        if (data.stats.childContents === undefined) return false
        if (data.coTags.length > 0 && !Array.isArray(data.coTags[0].tags)) return false
        if (data.coTags.length > 0 && data.coTags[0].total === undefined) return false
        return true
    }

    function init() {
        elements.app = document.getElementById("tagmap-app")
        if (!elements.app) return

        state.serviceUri = readMeta("service-uri")
        state.contentPath = readMeta("content-path")
        state.csrfToken = readMeta("token")

        // Take the caps from the server rather than trusting the constants:
        // otherwise raising TAGMAP_MAX_* leaves the client refusing paths the
        // server would accept, and lowering it offers ones it will reject.
        var depthCap = parseInt(readMeta("tagmap-max-depth"), 10)
        var widthCap = parseInt(readMeta("tagmap-max-width"), 10)
        if (depthCap > 0) MAX_DEPTH = depthCap
        if (widthCap > 0) MAX_WIDTH = widthCap

        var layerMatch = location.search.match(/[?&]layer=([^&]+)/)
        if (layerMatch) state.layer = decodeURIComponent(layerMatch[1])

        var initial = null
        var inline = document.getElementById("tagmap-initial-state")
        if (inline) {
            try { initial = JSON.parse(inline.textContent) } catch (error) { /* ignore */ }
        }

        buildChrome()

        if (initial && isCurrentShape(initial)) {
            state.layer = initial.layer || state.layer
            applyData(initial, { replace: true })
        } else {
            navigate(currentUrlSegments(), { replace: true })
        }

        window.addEventListener("popstate", function () {
            // history.state is intentionally empty: the URL is the identity,
            // and the payload usually comes from the in-memory cache.
            navigate(currentUrlSegments(), { fromHistory: true })
        })

        var resizeTimer = null
        window.addEventListener("resize", function () {
            clearTimeout(resizeTimer)
            resizeTimer = setTimeout(resizeCanvas, 200)
        })

        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) ensureLoop()
        })

        window.addEventListener("keydown", function (event) {
            if (event.key === "Escape") { closePopup(); closeInfoCard() }
        })

        if (window.ThemeChanger && ThemeChanger.onChangeThemeCallbacks) {
            ThemeChanger.onChangeThemeCallbacks.push(onThemeChanged)
        }

        ensureLoop()
    }

    TM.state = state
    TM.camera = camera

    /**
     * Screen-space snapshot: turns every visual claim into a number, so a
     * test can assert "the entered circle stayed put and the siblings did not
     * scatter" instead of a person squinting at a screenshot. Radii and
     * positions come through the same worldToScreen the renderer uses.
     */
    TM.snapshot = function () {
        var toScreen = function (item) {
            var screen = worldToScreen(item.x, item.y)
            return {
                tag: item.tag,
                x: Math.round(screen.x * 100) / 100,
                y: Math.round(screen.y * 100) / 100,
                r: Math.round(item.r * camera.scale * 100) / 100,
            }
        }
        var container = containerRegion()
        return {
            tagPath: state.data ? state.data.tagPath : null,
            segments: state.segments,
            settled: cameraTarget === null,
            camera: { x: camera.x, y: camera.y, scale: camera.scale },
            viewport: { w: cssW, h: cssH },
            current: container ? toScreen({ tag: "(current)", x: 0, y: 0, r: LEVEL_R }) : null,
            children: state.layout.slots.map(function (slot) {
                return toScreen(state.universe[slot.tag])
            }),
            ancestors: state.ancestors.map(function (ancestor) {
                return {
                    depth: ancestor.depth,
                    tagPath: tagPathOf(ancestor.segments),
                    boundary: toScreen({ tag: "(boundary)", x: ancestor.x, y: ancestor.y, r: ancestor.r }),
                    children: ancestor.children.map(toScreen),
                }
            }),
            dots: state.stars.map(function (star) {
                var position = starPosition(star)
                var screen = worldToScreen(position.x, position.y)
                return {
                    url: star.item.url,
                    suggested: !!star.item.suggested,
                    x: Math.round(screen.x * 100) / 100,
                    y: Math.round(screen.y * 100) / 100,
                }
            }),
            insideChildren: Object.keys(state.insideChildren).map(function (key) {
                return {
                    tag: key,
                    dots: state.insideChildren[key].map(function (dot) {
                        var screen = worldToScreen(dot.x, dot.y)
                        return {
                            url: dot.item.url,
                            suggested: !!dot.item.suggested,
                            x: Math.round(screen.x * 100) / 100,
                            y: Math.round(screen.y * 100) / 100,
                        }
                    }),
                }
            }),
            groups: state.layout.slots.map(function (slot) {
                var node = state.universe[slot.tag]
                return { tag: slot.tag, tags: node ? node.tags : null, count: node ? node.count : null }
            }),
            stats: state.data ? state.data.stats : null,
            pending: Object.keys(ancestorFetches).concat(Object.keys(insideFetches)),
        }
    }

    /** The very functions the gestures call, so tests exercise real paths. */
    TM.act = {
        enter: narrowDown,
        enterTogether: enterTogether,
        widen: widenLevel,
        leave: leaveLevel,
        navigate: function (tagPath) { navigate(parseTagPath(tagPath)) },
        loadMore: loadMore,
        hitTest: function (px, py) {
            var star = pickStar(px, py)
            if (star) return { kind: "content", url: star.item.url }
            var cloud = pickCloud(px, py)
            if (cloud) return { kind: cloud.kind, tag: cloud.tag }
            if (pickContainer(px, py)) return { kind: "interior" }
            var ancestor = pickAncestor(px, py)
            if (ancestor) return { kind: "ancestor", tagPath: tagPathOf(ancestor.segments) }
            return { kind: "empty" }
        },
    }

    /** Resolves once the camera has settled, for deterministic assertions. */
    TM.settle = function () {
        return new Promise(function (resolve) {
            var quiet = 0
            var check = function () {
                quiet = cameraTarget === null ? quiet + 1 : 0
                if (quiet >= 2 || document.hidden) resolve(TM.snapshot())
                else requestAnimationFrame(check)
            }
            requestAnimationFrame(check)
        })
    }

    window.TagMap = TM

    // Last: applyData() reads TM.snapshot, so the hooks must exist first.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init)
    } else {
        init()
    }
})()
