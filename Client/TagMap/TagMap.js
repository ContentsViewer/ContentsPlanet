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
    // The world unit is one content radius (Layout.CONTENT_R), so there is
    // no per-level normalisation constant any more: a level's radius comes
    // from its own content count and lives in state.levelR.
    //
    // The zoom ceiling. A content never draws bigger than this, which is
    // what makes the zoom range finite: 56 px across clears the 44 px WCAG
    // 2.5.5 target and leaves room for a fixed-size title beside it. Zooming
    // further would only magnify a disc, since text does not scale with the
    // camera.
    var CONTENT_MAX_R_PX = 28
    var FIT_FILL = 0.94           // of the viewport, when a level is framed
    // How far past the fit the ROOT may be pulled back. The root has no
    // parent to frame, so this is a comfort figure rather than a derived one.
    var ROOT_PULLBACK = 2.6
    var ANCESTORS_DRAWN = 2       // deeper ancestors are culled, not composed
    var GHOST_RING_PAD = 1.06     // just outside the level, in level radii
    var GUIDE_RINGS = [0.25, 0.5, 0.75]
    var RING_HIT = 14             // screen px around an ancestor boundary

    // Outlines that get a fill. Every group is stroked, but filling 106
    // overlapping translucent shapes both costs fill rate and turns the level
    // into mud -- the overlaps read as darker regions that mean nothing. The
    // biggest few carry the sense of mass; the rest are outlines.
    var NEBULA_FILLS = 12
    var NEBULA_FILL_ALPHA = 0.10
    var NEBULA_LINE_ALPHA = 0.55
    // Screen radius below which a group's outline is not drawn at all.
    //
    // Not a performance measure -- the whole renderer costs about 0.6 ms a
    // frame. It is legibility: an outline is a CLAIM about which contents
    // belong together, and 40 of the root's 106 groups hold a single content,
    // so their outlines are 40 small circles crossing everything and saying
    // nothing you cannot already see from the mark inside them. Their
    // contents are still drawn; only the claim waits until it can be read.
    var NEBULA_MIN_SCREEN_R = 26
    // Content screen radius at which a mark stops being a dot in a batched
    // path and becomes an object with its own styling. It is also where the
    // title tier begins (W6): a 22 px disc is about the point at which a
    // label beside it stops swamping it.
    // Frames kept for the percentile readout.
    var PERF_WINDOW = 120
    var perf = { frames: 0, total: 0, worst: 0, recent: [] }
    // Past this many contents inside the child groups, showing them all in
    // place is noise rather than help (the former view used the same rule).
    var STAR_R = 7

    var LABEL_MIN_SCREEN_R = 16
    var LABEL_MAX_BOXES = 30      // labels per frame, collision-checked
    var MAX_DRAW_ERRORS = 90      // consecutive failing frames before giving up
    // Level-of-detail thresholds, in CSS px of CONTENT RADIUS. Absolute, so
    // they stop being ratios against a fit that changed every level.
    var STAR_MIN_PX = 1.5         // below this the outlines carry the density
    var RING_PX = 5               // where a dot gains an outline
    var LABEL_TITLE_PX = 11       // a 22 px disc has room for a name beside it
    var PARENT_PX = 17            // and for where the content lives
    var SUMMARY_PX = 24           // a card wide enough for two summary lines
    // Bodies asked for in one request. Bounded by what a viewport can show,
    // not by the manifest: each costs the server about 17 ms the first time
    // its file is read.
    var BODY_BATCH = 24
    // Hit target, whatever the mark's size. A 2 px dot still has to be
    // tappable, so the target is decoupled from the level of detail.
    var HIT_MIN_PX = 22

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
        nebula: null,   // {points, contours} -- the contents-first scene
        groupRemap: {}, // manifest group index -> slot index (see buildScene)
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
    // Content bodies, by manifest key. The map draws every content of a level
    // from the manifest, which carries identities and no bodies, so a mark
    // that grows big enough for a title has to ask for one. Kept across
    // navigations: a content keeps its key, so climbing back out costs
    // nothing. Never evicted -- the whole corpus of titles is a few KB.
    var bodies = {}
    var bodyFetches = {}       // key -> in flight
    var bodyMisses = {}        // key -> the server had nothing for it
    // key -> when it may be asked about again. A FAILED request is not a
    // miss: the content may well exist and the server may recover. But
    // without a wait, ensureBodies runs every frame and re-asks every frame:
    // one broken response produced 3565 requests and 3565 console warnings
    // before anyone looked. The backoff doubles, so a server that stays
    // broken is asked about once a minute rather than sixty times a second.
    var bodyRetryAt = {}
    var bodyBackoff = {}
    var BODY_RETRY_MS = 1500
    var BODY_RETRY_MAX_MS = 60000
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

    /**
     * Asks for the bodies of the marks that have grown big enough to show
     * one, nearest the middle first.
     *
     * Driven by the level of detail rather than by paging, so every content
     * is treated alike: a page would name whichever bodies arrived first and
     * leave the rest anonymous, and the reader would take the difference for
     * a fact about the contents.
     *
     * Summaries are a separate, opt-in request. Decoding a body to summarise
     * it costs about 8.6 ms on the server and nothing caches it, while a
     * title is about 1 ms -- and only the handful of marks at maximum zoom
     * have room for a summary at all.
     */
    function ensureBodies() {
        if (!state.nebula || contentPx() < LABEL_TITLE_PX) return
        var now = performance.now()
        var wantSummary = contentPx() >= SUMMARY_PX
        var wanted = []
        state.nebula.points.forEach(function (mark) {
            var known = bodies[mark.key]
            if (known && (!wantSummary || known.summary !== null)) return
            if (bodyMisses[mark.key] || bodyFetches[mark.key]) return
            if (bodyRetryAt[mark.key] && now < bodyRetryAt[mark.key]) return
            var screen = worldToScreen(mark.x, mark.y)
            if (screen.x < -60 || screen.x > cssW + 60
                || screen.y < -60 || screen.y > cssH + 60) return
            wanted.push({
                key: mark.key,
                distance: Math.hypot(screen.x - cssW / 2, screen.y - cssH / 2),
            })
        })
        if (wanted.length === 0) return

        // Nearest the middle first: that is where the reader is looking, and
        // a bounded request has to choose.
        wanted.sort(function (a, b) { return a.distance - b.distance })
        var keys = []
        for (var i = 0; i < wanted.length && keys.length < BODY_BATCH; i++) {
            if (keys.indexOf(wanted[i].key) < 0) keys.push(wanted[i].key)
        }
        keys.forEach(function (key) { bodyFetches[key] = true })

        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", "")
        form.append("scope", "keys")
        form.append("keys", keys.join(","))
        if (wantSummary) form.append("fields", "full")

        fetch(state.serviceUri + "/tagmap-service.php", { method: "POST", body: form })
            .then(function (response) { return response.json() })
            .then(function (body) {
                if (body.error) throw new Error(body.error)
                // Two items for one key means the 48-bit key collided. Drop
                // it: naming an article with another article's title is worse
                // than leaving it unnamed, and it would be undetectable.
                var byKey = {}
                ;(body.items || []).forEach(function (item) {
                    byKey[item.key] = byKey[item.key] === undefined ? item : null
                })
                keys.forEach(function (key) {
                    delete bodyFetches[key]
                    var item = byKey[key]
                    if (item) {
                        bodies[key] = item
                        delete bodyRetryAt[key]
                        delete bodyBackoff[key]
                        return
                    }
                    // The server answered and had nothing for this key, so
                    // asking again would get the same answer. A collision
                    // counts as a miss too: showing one article's title on
                    // another is worse than showing none.
                    bodyMisses[key] = true
                })
                ensureLoop()
            })
            .catch(function (error) {
                // A failure is not an answer, so these keys are not misses --
                // but they must wait before being asked about again, or the
                // per-frame sweep turns one bad response into thousands.
                var wait = 0
                keys.forEach(function (key) {
                    delete bodyFetches[key]
                    bodyBackoff[key] = Math.min(BODY_RETRY_MAX_MS,
                        (bodyBackoff[key] || BODY_RETRY_MS / 2) * 2)
                    bodyRetryAt[key] = performance.now() + bodyBackoff[key]
                    wait = Math.max(wait, bodyBackoff[key])
                })
                // One line per failure, not one per key: the point is that it
                // failed, and a flood buries whatever is above it.
                console.warn("TagMap: body lookup failed for " + keys.length
                    + " key(s); retrying in " + Math.round(wait / 1000) + "s", error)
            })
    }

    // ---- placement: the nested-circle model --------------------------------
    //
    // World coordinates are ABSOLUTE: one unit is one content radius, at
    // every depth. A circle's size therefore states a content count that
    // means the same thing everywhere on the map, and the camera scale is a
    // real quantity -- CSS px per content radius -- clamped at
    // CONTENT_MAX_R_PX so the zoom range is finite.
    //
    // Entering a group does not renormalise anything. The group's circle
    // BLOOMS into the level it becomes (it has to hold a mark per
    // (content, group) pair, so it needs about 2.2x the radius), and the
    // parent's field is dilated about that circle by the same factor, which
    // pushes every sibling radially outward without re-packing. See
    // Layout.composeBloom for why a re-pack is not an option.

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
        state.levelR = 0
        state.enteredHull = null
        state.labels = null
        // Held in the outgoing level's world coordinates, so it cannot
        // survive a scene rebuild.
        meltPath = null
        if (!data) return

        // A child is a GROUP of tags (the server merges tags that cover an
        // identical set of contents), keyed by the OR segment that selects
        // it. Sized by `count` at every depth -- at the root that equals the
        // global total, so there is no special case and no channel carrying
        // two different quantities.
        var derived = levelLayout(data)
        if (!derived) return
        var children = derived.children
        var layout = derived.layout
        state.groupRemap = derived.groupRemap
        state.layout = { slots: layout.anchors, byTag: layout.byTag }
        state.packExtent = layout.packExtent
        state.levelR = derived.levelR
        state.members = children.map(function (child) {
            return {
                tag: child.tag, count: child.entry.count,
                reach: child.entry.reach, total: child.entry.total,
            }
        })

        state.layout.slots.forEach(function (slot, index) {
            var entry = children[index].entry
            state.universe[slot.tag] = {
                tag: slot.tag,
                tags: entry.tags || [entry.tag],
                kind: "child",
                x: slot.x,
                y: slot.y,
                r: slot.r,
                count: entry.count,
                reach: entry.reach,
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
                x: Math.cos(angle) * GHOST_RING_PAD * state.levelR,
                y: Math.sin(angle) * GHOST_RING_PAD * state.levelR,
                r: Layout.territoryRadius(1),
                count: t.count,
                total: t.total,
            }
        })

        buildAncestors()
        buildNebula()
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
    /**
     * How many contents each pair of groups has in common, read straight off
     * the manifest. This is the structure the old layout threw away: it
     * ordered groups by count alone, so sharing partners could end up on
     * opposite sides of the field.
     */
    /**
     * A level's territories, from its payload. The ONLY place a level is
     * laid out.
     *
     * It has to be the only place, because a level is laid out twice: once as
     * the current level, and again as an ancestor when the reader is inside
     * one of its groups. buildAncestors used to redo the derivation and left
     * out orderBySharing, so the same parent had two different packings --
     * measured at 5.6 world units apart, which put the camera 118 px wrong
     * every time the reader left a level. A duplicated derivation is exactly
     * the shape of that bug.
     *
     * @return {children, layout, levelR, groupRemap} or null without `reach`
     */
    function levelLayout(data) {
        if (!data) return null
        var cached = sceneCache[data.tagPath]
        if (cached && cached.data === data) return cached.derived
        var children = (data.coTags || []).map(function (t) {
            return { tag: t.tag, entry: t }
        })
        if (children.length === 0) {
            return {
                children: children, groupRemap: {},
                layout: Layout.layoutAnchors([]),
                levelR: Layout.levelRadiusFor(0, (data.stats || {}).directContents || 0),
            }
        }
        // Sized by `reach` -- how many contents ENTERING the group yields --
        // never by `count`, this view's tally. They differ by up to 4x
        // (Arduino: 33 and 65), and a circle drawn from `count` is not the
        // size of the view behind it. Without `reach` there is no honest size
        // to draw, so the level stays empty rather than wrong.
        for (var i = 0; i < children.length; i++) {
            if (typeof children[i].entry.reach !== "number") {
                console.warn("TagMap: payload carries no coTags[].reach, which is the only "
                    + "honest size for a group; drawing nothing rather than sizing from count")
                return null
            }
        }

        // Who sits beside whom follows from who shares contents, so a content
        // in two groups lands between neighbours instead of halfway across
        // the field. Sizes are still the reaches; only the order changes.
        var serverOrder = children
        children = Layout.orderBySharing(children, sharingMatrix(data, children))
        // The manifest numbers groups in the SERVER's order, so reordering
        // them here means every membership index has to be translated. Miss
        // this and each content is assigned to the wrong group entirely --
        // silently, because the indices stay in range.
        var groupRemap = {}
        for (var oldIndex = 0; oldIndex < serverOrder.length; oldIndex++) {
            for (var newIndex = 0; newIndex < children.length; newIndex++) {
                if (children[newIndex].tag === serverOrder[oldIndex].tag) {
                    groupRemap[oldIndex] = newIndex
                    break
                }
            }
        }

        var layout = Layout.layoutAnchors(children.map(function (child) {
            return { tag: child.tag, reach: child.entry.reach }
        }))
        var derived = {
            children: children,
            groupRemap: groupRemap,
            layout: layout,
            levelR: Layout.levelRadiusFor(
                layout.packExtent, (data.stats || {}).directContents || 0),
        }
        sceneCache[data.tagPath] = { data: data, derived: derived }
        return derived
    }

    /**
     * The outlines of a level's groups, in that level's own coordinates.
     *
     * Needed for ancestors as well as for the current level: a group drawn as
     * a soft body and then, on being entered, as a hard circle changes SHAPE
     * discontinuously even when its position and radius do not. Every region
     * this renderer draws is a contour; nothing is an arc.
     *
     * @return {contours, marks, byTag} or null
     */
    function levelContours(data, derived) {
        if (!data || !derived || !Array.isArray(data.memberships)) return null
        var slot = sceneCache[data.tagPath]
        if (slot && slot.data === data && slot.shapes) return slot.shapes
        var anchors = derived.layout.anchors
        var rows = data.memberships.map(function (row) {
            return [row[0], row[1].map(function (g) {
                return g === -1 ? -1 : (derived.groupRemap[g] === undefined ? -1 : derived.groupRemap[g])
            })]
        })
        var marks = Layout.placeMarks(rows, anchors, { levelRadius: derived.levelR })

        var byGroup = []
        for (var g = 0; g < anchors.length; g++) byGroup.push([])
        marks.forEach(function (mark) {
            if (byGroup[mark.group]) byGroup[mark.group].push(mark)
        })

        var contours = []
        var byTag = {}
        for (var k = 0; k < anchors.length; k++) {
            var anchor = anchors[k]
            var members = byGroup[k]
            var field = members.length === 0
                ? [{ x: anchor.x, y: anchor.y, r: Layout.kernelRadius(anchor.r, 1) }]
                : (function () {
                    var radius = Layout.kernelForPoints(members, anchor.r)
                    return members.map(function (mark) {
                        return { x: mark.x, y: mark.y, r: radius }
                    })
                })()
            var outline = Layout.contour(field)
            var entry = {
                tag: anchor.tag, index: k, reach: anchor.reach,
                members: members.length, rings: outline.rings,
                area: outline.area, anchor: outline.anchor,
            }
            contours.push(entry)
            byTag[anchor.tag] = entry
        }
        // The level's own boundary: how far its contents reach, per
        // direction. A radial hull rather than a metaball, because a single
        // field over a whole level cannot be sampled finely enough to be
        // trusted -- measured on the root, a level-wide contour left 135 of
        // 411 marks OUTSIDE its own outline. See Layout.radialHull.
        var boundary = Layout.radialHull(marks, Layout.territoryRadius(1))
        var shapes = {
            contours: contours, marks: marks, byTag: byTag, boundary: boundary,
        }
        if (slot && slot.data === data) slot.shapes = shapes
        return shapes
    }

    /** Rings as one Path2D, in whatever coordinates they were given. */
    function ringsToPath(rings, scale, offsetX, offsetY) {
        var path = new Path2D()
        var k = scale === undefined ? 1 : scale
        var dx = offsetX || 0, dy = offsetY || 0
        rings.forEach(function (ring) {
            if (ring.length < 2) return
            path.moveTo(ring[0].x * k + dx, ring[0].y * k + dy)
            for (var i = 1; i < ring.length; i++) {
                path.lineTo(ring[i].x * k + dx, ring[i].y * k + dy)
            }
            path.closePath()
        })
        return path
    }

    function sharingMatrix(data, children) {
        var rows = data.memberships
        if (!Array.isArray(rows) || rows.length === 0) return null
        var sharing = {}
        for (var i = 0; i < rows.length; i++) {
            var groups = rows[i][1]
            if (!groups || groups.length < 2) continue
            for (var a = 0; a < groups.length; a++) {
                for (var b = a + 1; b < groups.length; b++) {
                    var ta = children[groups[a]] && children[groups[a]].tag
                    var tb = children[groups[b]] && children[groups[b]].tag
                    if (!ta || !tb) continue
                    if (!sharing[ta]) sharing[ta] = {}
                    if (!sharing[tb]) sharing[tb] = {}
                    sharing[ta][tb] = (sharing[ta][tb] || 0) + 1
                    sharing[tb][ta] = (sharing[tb][ta] || 0) + 1
                }
            }
        }
        return sharing
    }

    function buildNebula() {
        state.nebula = null
        var data = state.data
        if (!data || !Array.isArray(data.memberships)) return
        var derived = levelLayout(data)
        if (!derived) return
        var levelR = state.levelR
        if (!(levelR > 0)) return

        // The same marks and outlines an ANCESTOR of this level would be
        // drawn with -- one derivation, cached against the payload. When the
        // reader enters a group, the level they arrive at reuses the very
        // rings its parent was already drawing, so the boundary they clicked
        // is the same object rather than an equal one recomputed.
        var shapes = levelContours(data, derived)
        if (!shapes) return

        state.nebula = {
            // Each mark carries its content's `key`. That is the identity
            // duplication gave up in the geometry, and it is what lets a
            // content present at two levels slide between them instead of
            // being replaced.
            points: shapes.marks,
            contours: shapes.contours,
            anchors: derived.layout.anchors,
            levelR: levelR,
            // What is actually DRAWN. The territories fit inside INNER_FILL
            // of the level by construction, but each outline adds a kernel
            // halo, so this is measured rather than derived.
            extent: (function () {
                var extent = levelR
                shapes.contours.forEach(function (contour) {
                    contour.rings.forEach(function (ring) {
                        ring.forEach(function (vertex) {
                            var reach = Math.hypot(vertex.x, vertex.y)
                            if (reach > extent) extent = reach
                        })
                    })
                })
                return extent
            })(),
            packExtent: derived.layout.packExtent,
            truncated: !!data.manifestTruncated,
        }
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
        var steps = []
        var childLevelR = state.levelR
        for (var depth = state.segments.length; depth > 0; depth--) {
            var parentSegments = state.segments.slice(0, depth - 1)
            var parent = state.payloads[cacheKeyOf(parentSegments)]
            if (!parent) break
            var parentDerived = levelLayout(parent)
            if (!parentDerived) break
            var parentLayout = parentDerived.layout
            var parentSlots = { slots: parentLayout.anchors, byTag: parentLayout.byTag }
            // The parent's groups are keyed by their OR segment, and the
            // segment we came in through IS one of those keys, so a lookup by
            // the joined name finds it whether it was one tag or several.
            var slot = Layout.slotForSegment(parentSlots, [state.segments[depth - 1].join(",")])
                || Layout.slotForSegment(parentSlots, state.segments[depth - 1])
            if (!slot) break
            // The bloom: entering this circle grew it into the level below,
            // and dilated the parent's field about it by the same factor.
            var beta = slot.r > 0 ? childLevelR / slot.r : 0
            if (!(beta > 0)) break
            steps.push({ x: slot.x, y: slot.y, beta: beta })
            var parentLevelR = parentDerived.levelR
            chain.push({
                slot: slot, payload: parent, segments: parentSegments,
                slots: parentLayout.anchors, levelR: parentLevelR,
                chainTo: Layout.composeBloom(steps.slice()),
                // An ancestor's groups are drawn as the soft bodies they were,
                // not as circles: a shape that hardens into an arc the moment
                // you enter it is a discontinuity the camera cannot hide.
                shapes: levelContours(parent, parentDerived),
            })
            childLevelR = parentLevelR
        }
        state.bloomChain = steps
        // The outline of the circle we came in through, in OUR coordinates.
        // This is the level's boundary: what the reader clicked, kept as the
        // soft body it was rather than replaced by a circle.
        // The boundary the reader clicked, as a hull in OUR coordinates, so
        // it can be interpolated with this level's own hull angle by angle.
        // Both are star-shaped about the level centre, which is exactly what
        // a radial representation buys.
        state.enteredHull = null
        if (chain.length > 0 && chain[0].shapes) {
            var came = state.segments[state.segments.length - 1]
            var entered = chain[0].shapes.byTag[came.join(",")]
            if (entered && entered.rings.length > 0) {
                var into = chain[0].chainTo
                var mapped = entered.rings.map(function (ring) {
                    return ring.map(function (v) {
                        return { x: v.x * into.scale + into.x, y: v.y * into.scale + into.y }
                    })
                })
                state.enteredHull = Layout.radialHullOfRings(mapped, 0, 0)
            }
        }

        // The ancestors' other children, in the current level's space. These
        // are the siblings: after entering a child they stay exactly where
        // they were, dimmed, instead of scattering to unrelated positions.
        state.ancestors = []
        // One circle per TAG across the whole screen, and the circle nearest
        // to where you are wins. Matching on tags rather than on a group's
        // joined key matters because the keys need not line up: a segment the
        // reader composed themselves ("C#,Cpp") is not a key in the parent,
        // where C# and Cpp are separate groups, and a group that is merged at
        // one level can be split at another.
        var drawnTags = {}
        state.layout.slots.forEach(function (slot) {
            var node = state.universe[slot.tag]
            ;(node && node.tags ? node.tags : slot.tag.split(",")).forEach(function (tag) {
                drawnTags[tag] = true
            })
        })

        for (var i = 0; i < chain.length && i < ANCESTORS_DRAWN; i++) {
            var link = chain[i]
            var mapped = link.chainTo
            // The tags we entered through at this level are represented by
            // the boundary we are inside; drawing them again behind it shows
            // the reader the very thing they just stepped into.
            state.segments[state.segments.length - 1 - i].forEach(function (tag) {
                drawnTags[tag] = true
            })
            var visible = link.slots.filter(function (slot) {
                return !slot.tag.split(",").some(function (tag) { return drawnTags[tag] })
            })
            // Claim them so a further ancestor does not repeat them either.
            visible.forEach(function (slot) {
                slot.tag.split(",").forEach(function (tag) { drawnTags[tag] = true })
            })
            state.ancestors.push({
                depth: i + 1,
                segments: link.segments,
                // The BOUNDARY scales: it has to keep containing a field that
                // spread apart inside it.
                x: mapped.x,
                y: mapped.y,
                r: link.levelR * mapped.scale,
                // Where this ancestor's own space sits in ours, so its
                // outlines can be drawn without being rebuilt.
                map: mapped,
                shapes: link.shapes,
                boundaryHull: link.shapes ? link.shapes.boundary : null,
                children: visible.map(function (slot) {
                    var shape = link.shapes ? link.shapes.byTag[slot.tag] : null
                    return {
                        tag: slot.tag,
                        x: slot.x * mapped.scale + mapped.x,
                        y: slot.y * mapped.scale + mapped.y,
                        // The RADIUS does not. Only the circle that was
                        // entered grew, into the level you are now in; the
                        // rest were pushed apart, and a radius states a
                        // content count that must not change because the
                        // reader navigated.
                        r: slot.r,
                        rings: shape ? shape.rings : null,
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
        return { x: 0, y: 0, r: state.levelR }
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

    // ---- zoom-driven transitions ------------------------------------------
    //
    // Pushing against the zoom ceiling IS the gesture for going in, and
    // against the floor for coming out. That gives CONTENT_MAX_R_PX a job
    // instead of leaving it a dead wall, and it needs no new control: the
    // reader keeps zooming and the map keeps obliging.
    //
    // Tap and drag stay the primary path. On a wide display the whole zoom
    // range is under 2x, so there is not much room to zoom INTO a group
    // before the ceiling arrives -- which is exactly why the ceiling is the
    // trigger rather than an occupancy threshold that a big screen never
    // reaches.
    var ZOOM_PUSH_CAP = 0.35      // log units of push we bother to accumulate
    var ZOOM_PUSH_COMMIT = 0.22   // and how much commits the transition
    var ZOOM_DWELL_MS = 120       // held continuously, so a flick cannot commit
    var ZOOM_COOLDOWN_MS = 320
    var ZOOM_MAX_PER_BURST = 3
    // A wheel burst: macOS keeps sending decaying events for up to 1.5 s
    // after the fingers lift and does not flag them. Rather than trying to
    // detect inertia, the stream is segmented and one burst counts as one
    // gesture -- inertia is part of the same burst, so a burst ends once.
    var WHEEL_BURST_GAP_MS = 140
    // Last-resort backstop. A transition loop navigates for ever and the
    // reader cannot escape it, which is the worst failure this feature can
    // have, so it is disabled for the session rather than merely rate-limited.
    //
    // It should be unreachable: the dwell and the cooldown together mean a
    // transition costs at least 440 ms of sustained pushing, and the burst
    // cap stops at three, so six inside two seconds cannot happen. Measured
    // by hammering the wheel: the burst cap fires and the backstop never
    // does. It stays as insurance against a future change breaking the cap,
    // which is exactly the kind of change whose damage the reader cannot undo.
    var RUNAWAY_WINDOW_MS = 2000
    var RUNAWAY_MAX = 6

    var zoomPush = 0              // signed, in log-scale units, against a clamp
    var zoomDesired = 0           // what the reader asked for, clamps aside
    // Visible give past a clamp, in log units: about 15%. Enough to feel,
    // not enough to be mistaken for the zoom still working.
    var ZOOM_BAND = 0.14
    // How far past a clamp the reader may ASK to go. Beyond this the request
    // stops accumulating, so a long spin does not leave a huge spring to
    // unwind when they let go.
    var ZOOM_REACH = 1.6
    var zoomPending = null        // {kind, tag, since}
    var zoomBurst = null          // {entryPath, transitions, endsAt}
    var zoomCooldownUntil = 0
    var zoomHistory = []          // recent commit times, for the backstop
    var zoomDisabled = false
    var wheelBurstTimer = null
    var meltPath = null       // {key, path} for the pair currently held together
    // The bloom in progress: ancestor radii are multiplied by
    // factor^(1-t) so they start at the screen size they had and settle to
    // the absolute size their content count states. `t` is also the clock
    // for the mark morph below.
    var bloom = null
    // The outgoing level's marks, kept so a content that exists at both
    // levels can SLIDE rather than teleport. Measured without it: 57 of the
    // root's contents also live in /Arduino, and they jumped 10-38 world
    // units -- the camera was continuous and the contents were not. `map`
    // takes the outgoing world into the incoming one, the same similarity
    // the ancestor chain uses.
    var morph = null
    // Layouts and outlines, by tag path. A level is laid out twice -- once as
    // the current level, once as an ancestor when the reader is inside one of
    // its groups -- and the second time used to REDO the whole derivation:
    // the packing, the marks and a marching-squares pass per group. Keeping
    // them means entering reuses the very rings the parent was drawing, so
    // the boundary is the same object rather than an equal one, and the work
    // is paid once per payload rather than once per navigation.
    //
    // Safe to keep because a level's geometry is a pure function of its
    // payload: same payload in, same picture out, no clock and no randomness.
    var sceneCache = {}

    /** A content radius in CSS px. The level-of-detail indicator. */
    function contentPx() {
        return Layout.CONTENT_R * camera.scale
    }

    function zoomCeiling() {
        return CONTENT_MAX_R_PX / Layout.CONTENT_R
    }

    /**
     * How far back the reader may pull: far enough to SEE the level they
     * would leave to.
     *
     * The first version put the floor at a fixed fraction of the fit, which
     * measured 1.3x of pull-back at every level -- effectively pinning the
     * camera to the fit, so zooming out died almost immediately and read as
     * the control breaking. Pulling back is how you decide whether to leave,
     * so the floor is the scale at which the PARENT is framed, and pushing
     * past that is the leave gesture.
     *
     * At the root there is nothing to leave to, so the floor is generous
     * rather than derived: pulling back just shows empty sky, which is
     * harmless and stops the wheel feeling broken.
     */
    function zoomFloor() {
        var ancestor = state.ancestors && state.ancestors.length ? state.ancestors[0] : null
        var extent = ancestor
            ? Math.hypot(ancestor.x, ancestor.y) + ancestor.r
            : drawnExtent() * ROOT_PULLBACK
        if (!(extent > 0)) return fitScale()
        return Math.min((Math.min(cssW, cssH) * FIT_FILL) / (2 * extent), zoomCeiling())
    }

    /**
     * The scale actually drawn, given what the reader has asked for.
     *
     * Past a clamp the surplus is compressed rather than discarded, so the
     * wall is something you can feel pushing against. Without this the zoom
     * simply stops: no give, no cue, and then -- once the dwell elapses -- a
     * navigation the reader did not see coming. The band IS the affordance
     * for a gesture that has no control to look at.
     */
    function bandedScale(desired) {
        var ceiling = zoomCeiling(), floor = zoomFloor()
        if (desired > ceiling) {
            return ceiling * Math.exp(Math.min(ZOOM_BAND, Math.log(desired / ceiling) * 0.45))
        }
        if (desired < floor) {
            return floor / Math.exp(Math.min(ZOOM_BAND, Math.log(floor / desired) * 0.45))
        }
        return desired
    }

    /**
     * Applies a zoom step, and reads the intent off how far past the clamp
     * the reader is asking to go.
     *
     * `zoomDesired` is what they asked for and may exceed the clamps; the
     * camera shows the banded version of it. Deriving the push from the
     * surplus rather than accumulating it means letting go and pushing again
     * starts over, which is what a reader expects of a spring.
     */
    function applyZoom(factor, px, py) {
        var anchor = screenToWorld(px, py)
        var ceiling = zoomCeiling(), floor = zoomFloor()
        if (!(zoomDesired > 0)) zoomDesired = camera.scale
        zoomDesired = Math.min(ceiling * ZOOM_REACH,
            Math.max(floor / ZOOM_REACH, zoomDesired * factor))

        zoomPush = zoomDesired > ceiling ? Math.log(zoomDesired / ceiling)
            : (zoomDesired < floor ? -Math.log(floor / zoomDesired) : 0)
        zoomPush = Math.max(-ZOOM_PUSH_CAP, Math.min(ZOOM_PUSH_CAP, zoomPush))
        if (zoomPush === 0) zoomPending = null

        camera.scale = bandedScale(zoomDesired)
        camera.x = anchor.x - (px - cssW / 2) / camera.scale
        camera.y = anchor.y - (py - cssH / 2) / camera.scale
        considerZoomTransition(px, py)
    }

    /** Lets the band spring back once the reader stops pushing. */
    function releaseZoom() {
        if (zoomDesired > 0) {
            var settled = clampScale(zoomDesired)
            if (Math.abs(settled - camera.scale) > 1e-6) {
                cameraTarget = { x: camera.x, y: camera.y, scale: settled }
            }
        }
        zoomDesired = 0
        zoomPush = 0
        zoomPending = null
    }

    /**
     * Which group the reader is pushing into, by the OUTLINE rather than the
     * territory: the outline is the shape they can see.
     *
     * Returns null when two outlines both contain the point. Overlap is rare
     * now that a shared content is drawn in each of its groups, but it is not
     * impossible, and guessing between two would send the reader somewhere
     * they did not choose. Waiting for them to move the pointer costs a
     * moment; going to the wrong level costs their place.
     */
    function zoomCandidate(px, py) {
        if (!state.nebula) return null
        var world = screenToWorld(px, py)
        var found = null
        for (var i = 0; i < state.nebula.contours.length; i++) {
            var contour = state.nebula.contours[i]
            if (!Layout.pointInPolygon(contour.rings, world.x, world.y)) continue
            if (found) return null
            found = contour
        }
        return found
    }

    function considerZoomTransition(px, py) {
        if (zoomDisabled) return
        var now = performance.now()
        if (now < zoomCooldownUntil) return
        if (zoomBurst && zoomBurst.transitions >= ZOOM_MAX_PER_BURST) return

        var kind = zoomPush >= ZOOM_PUSH_COMMIT ? "in"
            : (zoomPush <= -ZOOM_PUSH_COMMIT ? "out" : null)
        if (!kind) { zoomPending = null; return }
        if (kind === "out" && state.segments.length === 0) return

        var tag = null
        if (kind === "in") {
            var candidate = zoomCandidate(px, py)
            if (!candidate) { zoomPending = null; return }
            tag = candidate.tag
        }

        // Held continuously: a single flick that happens to land past the
        // threshold must not navigate.
        if (!zoomPending || zoomPending.kind !== kind || zoomPending.tag !== tag) {
            zoomPending = { kind: kind, tag: tag, since: now }
            return
        }
        if (now - zoomPending.since < ZOOM_DWELL_MS) return

        commitZoomTransition(kind, tag, now)
    }

    function commitZoomTransition(kind, tag, now) {
        zoomHistory = zoomHistory.filter(function (at) { return now - at < RUNAWAY_WINDOW_MS })
        zoomHistory.push(now)
        if (zoomHistory.length > RUNAWAY_MAX) {
            zoomDisabled = true
            zoomPending = null
            showToast(localize(
                "ズームでの移動を停止しました（タップで移動できます）",
                "Zoom navigation stopped; tap to move instead"))
            return
        }

        zoomPush = 0
        zoomPending = null
        zoomCooldownUntil = now + ZOOM_COOLDOWN_MS
        if (zoomBurst) zoomBurst.transitions++

        // Within a burst the path is replaced, and the entry path is pushed
        // once when the burst ends -- so crossing three levels in one gesture
        // leaves one history entry, and a wobble that goes in and straight
        // back out leaves none.
        var replace = !!zoomBurst
        // `keepCamera`: the reader is still pushing, so the camera stays
        // where the re-anchor put it and their next wheel step continues from
        // there. Easing to the new level's fit instead fought the gesture --
        // it dragged the scale back while they were asking for more of it.
        if (kind === "in") {
            narrowDown(tag, { replace: replace, kind: "zoom-in", keepCamera: true })
        } else {
            leaveLevel({ replace: replace, kind: "zoom-out", keepCamera: true })
        }
    }

    /** Ends the current wheel burst, settling its history entry. */
    function endZoomBurst() {
        if (!zoomBurst) return
        var burst = zoomBurst
        zoomBurst = null
        zoomPush = 0
        zoomPending = null
        releaseZoom()
        if (burst.transitions === 0) return
        var finalPath = tagPathOf(state.segments)
        if (finalPath === burst.entryPath) {
            // In and straight back out: the reader is where they started, so
            // the journey does not belong in their history.
            history.replaceState(null, "", buildHref(state.segments))
            return
        }
        history.replaceState(null, "", burst.entryHref)
        history.pushState(null, "", buildHref(state.segments))
    }

    /** Forgets any pending zoom intent. Called when a gesture is abandoned. */
    function abortZoomIntent() {
        zoomPush = 0
        zoomPending = null
        if (zoomBurst) { zoomBurst.transitions = 0; zoomBurst = null }
    }

    /**
     * How much an ancestor's territory radius is inflated right now.
     *
     * 1 once the bloom has settled: a radius states a content count and must
     * not lie about it for longer than the transition takes.
     */
    function bloomFactor() {
        return bloom ? Math.pow(bloom.factor, 1 - bloom.t) : 1
    }

    /** What the level occupies on screen, outlines and halos included. */
    function drawnExtent() {
        if (state.nebula && state.nebula.extent > 0) return state.nebula.extent
        return state.levelR > 0 ? state.levelR : Layout.CONTENT_PITCH
    }

    /** The scale at which the level exactly fills the viewport. */
    function fitScale() {
        var extent = drawnExtent()
        return extent > 0 ? (Math.min(cssW, cssH) * FIT_FILL) / (2 * extent) : 1
    }

    /**
     * The camera scale is CSS px per content radius, clamped absolutely
     * rather than by a ratio against a fit that used to change every level.
     *
     * The ceiling is the point of the whole absolute scale: a content never
     * draws wider than CONTENT_MAX_R_PX, so there is a real end to zooming
     * in. Going past it could only magnify a disc, because the text beside it
     * is a fixed size and does not scale with the camera.
     */
    function clampScale(scale) {
        return Math.min(zoomCeiling(), Math.max(zoomFloor(), scale))
    }

    function hairline() {
        return 1 / camera.scale
    }

    /** Frames the current level. Only the camera moves. */
    function fitCurrentLevel() {
        cameraTarget = { x: 0, y: 0, scale: clampScale(fitScale()) }
        if (reducedMotion) {
            camera.x = cameraTarget.x
            camera.y = cameraTarget.y
            camera.scale = cameraTarget.scale
            cameraTarget = null
        }
    }

    /**
     * Keeps the picture still at the instant the level changes.
     *
     * Two things move that the camera has to follow, and I removed this
     * function once on the mistaken grounds that the absolute scale had made
     * it unnecessary. The absolute scale removed the RENORMALISATION, not the
     * need to follow:
     *
     *   1. the coordinate ORIGIN moves to the circle being entered, so every
     *      world coordinate shifts by -c;
     *   2. that circle BLOOMS into the level it becomes, radius r -> beta*r.
     *
     * Measured without this: the picture jumped 264 px and the boundary grew
     * 1.55x in one frame.
     *
     * The arithmetic is Layout.bloomCamera, where `node --test` can hold it:
     * deleting it from the renderer is invisible to the test suite, which is
     * how it went missing the first time.
     */
    function reanchorForBloom(centre, beta, direction) {
        var moved = Layout.bloomCamera(camera, centre, beta, direction)
        if (!moved) return false
        camera.x = moved.x
        camera.y = moved.y
        camera.scale = moved.scale
        // An ancestor's territory keeps its absolute radius, which states a
        // content count -- but the camera scale just changed by beta, so at
        // this instant those circles would jump by 1/beta. The factor starts
        // at beta (their old screen size) and eases to 1 (their honest size)
        // alongside the camera.
        bloom = { factor: beta, t: 0 }
        // The reader's pending zoom request belonged to the OLD level's
        // scale. Left standing, the very next wheel event recomputed the
        // camera from it and snapped the scale by 2.5x in one frame --
        // measured, and inside a single level, so it read as the map
        // breaking rather than as navigation. Cleared, the next event
        // re-seeds from the camera the re-anchor just placed.
        zoomDesired = 0
        zoomPush = 0
        zoomPending = null
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

    function stepCamera() {
        if (bloom) {
            bloom.t += (1 - bloom.t) * EASE
            if (bloom.t > 0.995) { bloom = null; morph = null }
        }
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
        // What the OUTGOING level drew for the level we are entering, or the
        // step out of it. Captured before the scene is rebuilt, because the
        // camera has to be re-anchored against it afterwards -- see
        // reanchorForBloom for why the absolute scale did not remove the need.
        // The marks on screen right now, by identity. Kept before the scene
        // is rebuilt, because rebuilding replaces every one of them.
        var outgoing = state.nebula ? state.nebula.points : null
        var step = null
        if (wasBuilt) {
            if (fromSegments.length + 1 === data.segments.length
                && isPrefixOf(fromSegments, data.segments)) {
                var entering = Layout.slotForSegment(state.layout,
                    [data.segments[data.segments.length - 1].join(",")])
                    || Layout.slotForSegment(state.layout,
                        data.segments[data.segments.length - 1])
                if (entering && entering.r > 0) {
                    step = { direction: "in", centre: entering, slotR: entering.r }
                }
            } else if (data.segments.length + 1 === fromSegments.length
                && isPrefixOf(data.segments, fromSegments)) {
                var out = state.bloomChain && state.bloomChain.length ? state.bloomChain[0] : null
                if (out) step = { direction: "out", centre: out, beta: out.beta }
            }
        }

        state.segments = data.segments
        state.data = data
        buildScene()
        if (!wasBuilt) { camera.x = 0; camera.y = 0 }

        // The instant after re-anchoring and before framing: the only moment
        // at which "nothing moved" is observable, since both happen in this
        // one synchronous block. Exposed so a test can assert it.
        // The bloom: the ratio between the level the circle became and the
        // circle it was. Known only now, because it needs the incoming
        // level's own radius.
        var bloomBeta = 0
        if (step) {
            bloomBeta = step.direction === "in"
                ? (step.slotR > 0 ? state.levelR / step.slotR : 0)
                : step.beta
            if (!reanchorForBloom(step.centre, bloomBeta, step.direction)) {
                camera.x = 0
                camera.y = 0
            }
            if (!(bloomBeta > 0)) step = null
        } else if (wasBuilt) {
            // A breadcrumb leap or a fresh load shares no frame of reference
            // with what was on screen, so there is nothing to be continuous
            // with; it simply frames the new level.
            camera.x = 0
            camera.y = 0
            bloom = null
        }

        // The outgoing marks, and the map that puts them in the new level's
        // coordinates. Nothing is recomputed for them: they are the same
        // instances the last frame drew.
        if (outgoing && step) {
            var mapBeta = step.direction === "in" ? bloomBeta : 1 / bloomBeta
            morph = {
                marks: outgoing,
                map: step.direction === "in"
                    ? { scale: mapBeta, x: -step.centre.x * mapBeta, y: -step.centre.y * mapBeta }
                    : { scale: mapBeta, x: step.centre.x, y: step.centre.y },
            }
        } else {
            morph = null
        }

        // Captured AFTER the re-anchor: the whole point of the snapshot is to
        // say whether the picture moved, and the re-anchor is part of the
        // same synchronous instant. Taken before it, it reported the camera
        // unchanged and the level jumped 67 px -- which is what the reader
        // sees, but it hid the cause.
        TM.lastTransition = {
            from: fromSegments,
            to: data.segments,
            // What moved the reader here, so the history policy and the
            // continuity claims can be checked separately per gesture.
            kind: options.kind || (options.fromHistory ? "history" : "tap"),
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
        // A zoom-driven transition leaves the camera where the re-anchor put
        // it: the reader is mid-gesture and still pushing, and framing the
        // new level would pull the scale back against them. Every other route
        // in -- a tap, a breadcrumb, history -- frames it.
        if (options.keepCamera) cameraTarget = null
        else fitCurrentLevel()
        // Ancestors are context, not a prerequisite: fetch them in the
        // background and let them pop in without moving anything.
        ensureAncestors()
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
    function narrowDown(key, options) {
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
        navigate(state.segments.concat([tags]), options)
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
    function leaveLevel(options) {
        if (state.segments.length === 0) return
        navigate(state.segments.slice(0, state.segments.length - 1), options)
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
            var began = performance.now()
            stepCamera()
            draw()
            positionPopup()
            ensureBodies()
            recordFrame(performance.now() - began)
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

    /**
     * Main-thread cost of a frame, so the budget is measured rather than
     * inferred from a frame rate.
     *
     * The rate on its own says nothing: a display running at 30 Hz gives
     * exactly 33.3 ms between frames however cheap the drawing is, and a
     * dropped frame and a slow one look identical from the outside. This
     * times the work itself.
     */
    function recordFrame(ms) {
        perf.frames++
        perf.total += ms
        if (ms > perf.worst) perf.worst = ms
        perf.recent.push(ms)
        if (perf.recent.length > PERF_WINDOW) perf.recent.shift()
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
        drawNebula(colors)
        drawContainer(colors)
        drawNebulaStars(colors)
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
            ctx.arc(0, 0, state.levelR * share, 0, TAU)
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
            // Its own boundary is a hull too. Left as an arc it was the one
            // hard circle still on screen, and the largest object in it.
            if (ancestor.boundaryHull && ancestor.map) {
                ctx.stroke(ringsToPath(
                    [Layout.hullRing(ancestor.boundaryHull, 0, 0)],
                    ancestor.map.scale * bloomFactor(), ancestor.map.x, ancestor.map.y))
            } else {
                ctx.beginPath()
                ctx.arc(ancestor.x, ancestor.y, ancestor.r, 0, TAU)
                ctx.stroke()
            }

            // Outlines, not fills, and only at a size that still reads as a
            // circle: an ancestor's children are magnified by 1/slot.r, so
            // filling them turns the context into the loudest thing on
            // screen and it competes with the level you are actually in.
            var readable = Math.hypot(cssW, cssH) * 0.55
            ctx.globalAlpha = 0.45
            ctx.strokeStyle = colors.muted
            ctx.lineWidth = hairline()
            var inflate = bloomFactor()
            var map = ancestor.map
            ancestor.children.forEach(function (child) {
                var worldR = child.r * inflate
                var childR = worldR * camera.scale
                if (childR < 3 || childR > readable) return
                var screen = worldToScreen(child.x, child.y)
                if (screen.x < -childR || screen.x > cssW + childR
                    || screen.y < -childR || screen.y > cssH + childR) return
                if (child.rings && map) {
                    // The soft body it was. Its rings are in the ancestor's
                    // own coordinates, so they go through the same map its
                    // centres did -- one similarity, no rebuild.
                    ctx.stroke(ringsToPath(child.rings, map.scale * inflate,
                        map.x, map.y))
                    return
                }
                ctx.beginPath()
                ctx.arc(child.x, child.y, worldR, 0, TAU)
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
    /**
     * Paths for the current nebula, built once per scene and cached on it.
     *
     * The design rests on contours being SCENE data rather than FRAME data:
     * the root's 106 outlines are ~12,000 vertices and take about 4 ms to
     * compute, which is fine once per navigation and impossible per frame.
     * Rebuilding the Path2D objects every frame would give most of that cost
     * back, so they are cached beside the rings they came from.
     */
    function nebulaPaths() {
        var nebula = state.nebula
        if (!nebula) return null
        if (nebula.paths) return nebula.paths

        var paths = nebula.contours.map(function (contour) {
            var path = new Path2D()
            var circle = null
            contour.rings.forEach(function (ring) {
                if (ring.length < 2) return
                path.moveTo(ring[0].x, ring[0].y)
                for (var i = 1; i < ring.length; i++) path.lineTo(ring[i].x, ring[i].y)
                path.closePath()
            })
            // A group whose outline is a single point's isoline IS a circle,
            // so draw it as one: 60-odd vertices per group, saved on the
            // long tail of one-content groups that most levels are made of.
            if (contour.members <= 1 && contour.rings.length === 1) {
                var anchor = nebula.anchors[contour.index]
                circle = { x: anchor.x, y: anchor.y,
                    r: Layout.territoryRadius(anchor.reach) }
            }
            return { path: path, circle: circle }
        })

        // Which ones get a fill: the biggest by CONTENT COUNT, not by
        // enclosed area. A group whose few contents are scattered across the
        // level has a large area and little mass, and filling it would say
        // the opposite of the truth.
        var ranked = nebula.contours
            .map(function (contour, index) { return { index: index, reach: contour.reach } })
            .sort(function (a, b) { return b.reach - a.reach })
        nebula.filled = {}
        ranked.slice(0, NEBULA_FILLS).forEach(function (entry) {
            nebula.filled[entry.index] = true
        })

        // How loudly each outline is drawn. Without this every group shouts
        // equally, and since most levels are a long tail of one-content
        // groups, the picture is dominated by its least informative lines --
        // measured on the root: 60 of 106 groups hold a single content, and
        // their outlines are what turns the centre into a hairball.
        var loudest = Math.max.apply(null, nebula.contours.map(function (c) { return c.reach }))
        nebula.prominence = nebula.contours.map(function (contour) {
            return Math.sqrt(contour.reach) / Math.sqrt(Math.max(1, loudest))
        })

        nebula.paths = paths
        return paths
    }

    /**
     * The nebulae: one soft outline per group, shaped by its own contents.
     *
     * Where two outlines overlap, a content sits in both groups -- that
     * crossing IS the picture of sharing, and it is what the old renderer
     * could only say by drawing the same content twice.
     */
    function drawNebula(colors) {
        var nebula = state.nebula
        var paths = nebulaPaths()
        if (!paths) return

        // What the reader is pushing into, if anything. Shown while the
        // intent builds rather than only once it commits: pushing against the
        // ceiling is a gesture with no control to look at, so the map has to
        // say which group it heard.
        var intent = zoomPending && zoomPending.kind === "in" ? zoomPending.tag : null
        var intentStrength = Math.min(1, Math.abs(zoomPush) / ZOOM_PUSH_COMMIT)

        // How readable each outline is at this zoom, as a fade rather than a
        // switch, so nothing pops in as you move.
        var legible = nebula.contours.map(function (contour) {
            var screenR = Layout.territoryRadius(contour.reach) * camera.scale
            return Layout.smoothstep(NEBULA_MIN_SCREEN_R * 0.7, NEBULA_MIN_SCREEN_R * 1.4, screenR)
        })

        ctx.save()
        ctx.strokeStyle = colors.accentRing
        ctx.fillStyle = colors.accent

        // Fills first, then every outline on top, so a small group's line is
        // never buried under a big group's wash.
        for (var f = 0; f < paths.length; f++) {
            if (!nebula.filled[f] || legible[f] <= 0.01) continue
            ctx.globalAlpha = NEBULA_FILL_ALPHA * legible[f]
            strokeOrArc(paths[f], false)
        }
        for (var i = 0; i < paths.length; i++) {
            if (legible[i] <= 0.01) continue
            var loud = nebula.prominence[i]
            ctx.globalAlpha = NEBULA_LINE_ALPHA * (0.30 + 0.70 * loud) * legible[i]
            ctx.lineWidth = hairline() * (0.9 + 1.5 * loud)
            strokeOrArc(paths[i], true)
        }

        // The group the push is aimed at, drawn last so it is on top and
        // regardless of whether its outline was legible enough to draw at
        // all: a one-content group is exactly the case where the reader most
        // needs to be told what they are about to enter.
        if (intent) {
            for (var m = 0; m < nebula.contours.length; m++) {
                if (nebula.contours[m].tag !== intent) continue
                ctx.globalAlpha = 0.35 + 0.55 * intentStrength
                ctx.strokeStyle = colors.focusRing
                ctx.lineWidth = hairline() * (1.5 + 2.5 * intentStrength)
                strokeOrArc(paths[m], true)
                break
            }
        }
        ctx.restore()
    }

    /** A contour, as its own circle where it is one, else as its path. */
    function strokeOrArc(entry, stroke) {
        if (entry.circle) {
            ctx.beginPath()
            ctx.arc(entry.circle.x, entry.circle.y, entry.circle.r, 0, Math.PI * 2)
            if (stroke) ctx.stroke()
            else ctx.fill()
            return
        }
        if (stroke) ctx.stroke(entry.path)
        else ctx.fill(entry.path)
    }

    /**
     * The contents, as stars: exactly one mark per content at every level.
     *
     * Drawn from the membership manifest, so they are there at the root too,
     * where no content bodies have been fetched at all -- that is the whole
     * reason the manifest carries identities without bodies.
     */
    function drawNebulaStars(colors) {
        var nebula = state.nebula
        if (!nebula || nebula.points.length === 0) return
        var screenR = contentPx()
        if (screenR < STAR_MIN_PX) return   // the outlines carry the density here

        var worldR = Layout.CONTENT_R
        // Mid-transition, a content that exists at both levels slides from
        // where it was to where it belongs; one that is only in the new level
        // fades in, and one only in the old fades out where it stood. Without
        // this the camera was continuous and the contents teleported.
        if (morph && bloom) {
            drawMorphingStars(colors, worldR)
            return
        }
        ctx.save()
        if (screenR < RING_PX) {
            // One path, one fill. 192 separate arc+fill calls cost more than
            // the whole rest of the frame, and at this size the individual
            // styling would not be visible anyway.
            //
            // Contrast rises with the mark, rather than the mark rising with
            // the contrast: the SIZE states the content's real extent and
            // must not be shrunk to quieten it. Fully saturated at this zoom
            // the marks read as the subject and the nebulae as background,
            // which is the metaphor upside down -- 192 contents cover only
            // about 6% of the viewport, so the weight was never the area.
            var batch = new Path2D()
            nebula.points.forEach(function (point) {
                batch.moveTo(point.x + worldR, point.y)
                batch.arc(point.x, point.y, worldR, 0, Math.PI * 2)
            })
            ctx.fillStyle = colors.inkSecondary
            ctx.globalAlpha = 0.25 + 0.45 * Layout.smoothstep(STAR_MIN_PX, RING_PX, screenR)
            ctx.fill(batch)
        } else {
            // The ring fades in rather than switching on, so nothing pops
            // while the camera moves. Only the ALPHA ramps: the disc stays
            // the size the content really is, because that size is the
            // statement, and text beside it is a fixed size that does not
            // scale with the camera either.
            var ringAlpha = Layout.smoothstep(RING_PX, RING_PX * 1.6, screenR)
            ctx.lineWidth = hairline()
            nebula.points.forEach(function (point) {
                ctx.beginPath()
                ctx.arc(point.x, point.y, worldR, 0, Math.PI * 2)
                ctx.globalAlpha = 1
                ctx.fillStyle = point.inBand ? colors.surface : colors.focus
                ctx.fill()
                ctx.globalAlpha = ringAlpha
                ctx.strokeStyle = point.inBand ? colors.muted : colors.focusRing
                ctx.stroke()
            })
        }
        ctx.restore()
    }

    /**
     * The marks during a level change: sliding, appearing or leaving.
     *
     * The identity that makes this possible is the manifest `key` -- the
     * reason the manifest carries identities without bodies. Matching on
     * position instead would pair up whichever marks happened to be near
     * each other, which is how a transition ends up looking like a shuffle.
     */
    function drawMorphingStars(colors, worldR) {
        var progress = Layout.smoothstep(0, 1, bloom.t)
        var map = morph.map
        var wasByKey = {}
        morph.marks.forEach(function (mark) {
            // A content can have several marks (one per group); the first is
            // enough to slide from, and the rest fade out.
            if (wasByKey[mark.key] === undefined) wasByKey[mark.key] = mark
        })

        var staying = new Path2D()
        var arriving = new Path2D()
        var leaving = new Path2D()
        var arrivingCount = 0, leavingCount = 0
        var nowKeys = {}

        state.nebula.points.forEach(function (mark) {
            nowKeys[mark.key] = true
            var was = wasByKey[mark.key]
            if (!was) {
                arriving.moveTo(mark.x + worldR, mark.y)
                arriving.arc(mark.x, mark.y, worldR, 0, TAU)
                arrivingCount++
                return
            }
            var fromX = was.x * map.scale + map.x
            var fromY = was.y * map.scale + map.y
            var x = fromX + (mark.x - fromX) * progress
            var y = fromY + (mark.y - fromY) * progress
            staying.moveTo(x + worldR, y)
            staying.arc(x, y, worldR, 0, TAU)
        })

        morph.marks.forEach(function (mark) {
            if (nowKeys[mark.key]) return
            var x = mark.x * map.scale + map.x
            var y = mark.y * map.scale + map.y
            leaving.moveTo(x + worldR, y)
            leaving.arc(x, y, worldR, 0, TAU)
            leavingCount++
        })

        ctx.save()
        ctx.fillStyle = colors.focus
        ctx.globalAlpha = 0.85
        ctx.fill(staying)
        if (arrivingCount > 0) {
            ctx.globalAlpha = 0.85 * progress
            ctx.fill(arriving)
        }
        if (leavingCount > 0) {
            ctx.globalAlpha = 0.6 * (1 - progress)
            ctx.fillStyle = colors.inkSecondary
            ctx.fill(leaving)
        }
        ctx.restore()
    }

    /**
     * Names the marks that have room for a name, in screen space.
     *
     * One vocabulary at every level, driven by the mark's size in CSS px:
     * title, then where the content lives, then a two-line summary. Each
     * fades in, and only the opacity moves -- 12 px text is 12 px text at
     * every zoom, which is what stopped the old thresholds feeling arbitrary.
     */
    function drawNebulaLabels(colors, place, ellipsize, wrapText) {
        var nebula = state.nebula
        if (!nebula) return
        var screenR = contentPx()
        if (screenR < LABEL_TITLE_PX) return

        var titleAlpha = Layout.smoothstep(LABEL_TITLE_PX, LABEL_TITLE_PX * 1.3, screenR)
        var parentAlpha = Layout.smoothstep(PARENT_PX, PARENT_PX * 1.25, screenR)
        var summaryAlpha = Layout.smoothstep(SUMMARY_PX, SUMMARY_PX * 1.15, screenR)

        // Nearest the middle first, so when the collision budget runs out it
        // is the edges that go unnamed.
        var candidates = []
        nebula.points.forEach(function (mark) {
            var body = bodies[mark.key]
            if (!body) return
            var screen = worldToScreen(mark.x, mark.y)
            if (screen.x < -80 || screen.x > cssW + 80
                || screen.y < -80 || screen.y > cssH + 80) return
            candidates.push({
                body: body, screen: screen,
                distance: Math.hypot(screen.x - cssW / 2, screen.y - cssH / 2),
            })
        })
        candidates.sort(function (a, b) { return a.distance - b.distance })

        // Counted, because "why is this content unnamed?" has four possible
        // answers -- below the tier, body not loaded, off screen, or beaten
        // to the space -- and only the last one is invisible from outside.
        var drawn = 0
        candidates.forEach(function (candidate) {
            var body = candidate.body
            ctx.font = "600 12px system-ui, sans-serif"
            var lines = [{
                text: ellipsize(body.title, 240, ctx),
                font: "600 12px system-ui, sans-serif",
                color: colors.ink,
                alpha: titleAlpha,
            }]
            if (parentAlpha > 0.01 && body.parentTitle) {
                ctx.font = "11px system-ui, sans-serif"
                lines.push({
                    text: ellipsize(body.parentTitle, 200, ctx),
                    font: "11px system-ui, sans-serif",
                    color: colors.muted,
                    alpha: parentAlpha,
                })
            }
            if (summaryAlpha > 0.01 && body.summary) {
                if (body._plain === undefined) body._plain = stripHtml(body.summary)
                if (body._plain) {
                    ctx.font = "11px system-ui, sans-serif"
                    wrapText(ctx, body._plain, 240, 2).forEach(function (text) {
                        lines.push({
                            text: text,
                            font: "11px system-ui, sans-serif",
                            color: colors.inkSecondary,
                            alpha: summaryAlpha,
                        })
                    })
                }
            }
            if (place(lines, candidate.screen.x, candidate.screen.y,
                { boxed: true, lineHeight: 15 })) drawn++
        })

        state.labels = {
            contentPx: Math.round(screenR * 100) / 100,
            titleAlpha: Math.round(titleAlpha * 100) / 100,
            parentAlpha: Math.round(parentAlpha * 100) / 100,
            summaryAlpha: Math.round(summaryAlpha * 100) / 100,
            marks: nebula.points.length,
            withBodyAndOnScreen: candidates.length,
            drawn: drawn,
            budget: LABEL_MAX_BOXES,
        }
    }

    function drawContainer(colors) {
        if (state.segments.length === 0) return
        var path = containerPath()
        if (!path) return
        ctx.save()
        ctx.globalAlpha = 0.07
        ctx.fillStyle = colors.focus
        ctx.fill(path)
        ctx.globalAlpha = 1
        ctx.strokeStyle = colors.focusRing
        ctx.lineWidth = hairline() * 2.5
        ctx.stroke(path)
        ctx.restore()
    }

    /**
     * The current level's boundary: how far its contents reach.
     *
     * Two things it has to be, and the first version was neither.
     *
     * It has to CONTAIN the contents. The outline the reader clicked
     * describes the PARENT's knowledge of that group -- its `count` marks
     * inside a territory of radius r -- while the level holds `reach` marks
     * spread over about 2.2r. Carrying that outline through left 51 of 85
     * marks outside their own boundary.
     *
     * And it must not become a circle at the moment of entering, which is a
     * shape discontinuity the camera cannot absorb.
     *
     * So: a radial hull of this level's own marks, blended angle by angle
     * with the hull of the outline that was clicked. At t=0 it is the shape
     * the reader touched; at t=1 it encloses everything here.
     */
    function containerPath() {
        var shapes = sceneShapes()
        if (!shapes || !shapes.boundary) return null
        var radii = shapes.boundary
        if (state.enteredHull && bloom) {
            radii = Layout.blendHulls(state.enteredHull, shapes.boundary,
                Layout.smoothstep(0, 1, bloom.t))
        }
        return ringsToPath([Layout.hullRing(radii, 0, 0)])
    }

    /** This level's cached marks and outlines. */
    function sceneShapes() {
        if (!state.data) return null
        return levelContours(state.data, levelLayout(state.data))
    }

    /**
     * Direct contents, as dots on the inside of the boundary. A content that
     * matched by name similarity rather than by a tag is drawn hollow: it is
     * not tagged with what was selected, and saying so is the distinction the
     * server-rendered view used to make with a "Suggested" badge.
     */
    function drawLabels(colors) {
        var boxes = []
        var container = containerRegion()
        var contentRadiusPx = contentPx()

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
                // Per-line alpha, so a level-of-detail tier can fade in
                // beneath one that is already fully there.
                ctx.globalAlpha = line.alpha === undefined ? 1 : line.alpha
                ctx.fillText(line.text, box.x + padding, textY)
                textY += lineHeight
            })
            ctx.globalAlpha = 1
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

        // 3. The contents, from the manifest rather than from a page, so
        // every content of the level is treated alike -- a page would name
        // whichever bodies arrived first and leave the rest anonymous.
        drawNebulaLabels(colors, place, ellipsize, wrapText)

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

    /**
     * The two groups melting into one, while the reader holds them together.
     *
     * Fusing A and B used to be a switch: you released, the URL became
     * `A,B`, and a new picture appeared. What the reader had no way to see
     * was what they were about to get. Here the union is drawn WHILE they
     * hold it, as one soft body over both groups' marks -- which is the same
     * shape the fused level will draw, because it is the same field over the
     * same marks.
     *
     * The melt is the kernel: enlarging it until the two fields reach across
     * the gap is exactly what "these are one body now" means, and it is the
     * one parameter the density field already understands. Nothing else is
     * animated, and no marks move.
     */
    function drawFusionMelt(colors, sourceTag, targetTag) {
        if (!state.nebula) return
        var source = null, target = null
        state.nebula.contours.forEach(function (contour) {
            if (contour.tag === sourceTag) source = contour
            if (contour.tag === targetTag) target = contour
        })
        if (!source || !target) return

        // Contours are scene data, not frame data: a melt path costs a
        // marching-squares pass, so it is built when the pair changes and
        // reused while the reader holds it there.
        // Length-prefixed, because a tag name can contain any character a
        // separator might use -- including the comma that joins an OR group.
        var cacheKey = sourceTag.length + ":" + sourceTag + targetTag
        if (!meltPath || meltPath.key !== cacheKey) {
            meltPath = { key: cacheKey, path: buildMeltPath(source, target) }
        }
        if (!meltPath.path) return

        ctx.globalAlpha = 0.14
        ctx.fillStyle = colors.focus
        ctx.fill(meltPath.path)
        ctx.globalAlpha = 0.8
        ctx.strokeStyle = colors.focusRing
        ctx.lineWidth = hairline() * 2
        ctx.stroke(meltPath.path)
        ctx.globalAlpha = 1
    }

    /**
     * The single body two groups make, as one Path2D.
     *
     * The kernel that closes the gap is derived from the CLOSEST CROSS-GROUP
     * pair, not from the distance between the territories: what has to merge
     * is the marks. Measured across group sizes, one body appears at
     * 0.78-0.88 of that distance -- which is the pairwise merge constant of
     * the Wyvill kernel, 0.8625 -- so 0.95 clears it with margin at every
     * size rather than being tuned to one.
     */
    function buildMeltPath(source, target) {
        var marks = state.nebula.points.filter(function (mark) {
            return mark.group === source.index || mark.group === target.index
        })
        if (marks.length === 0) return null

        var mine = marks.filter(function (m) { return m.group === source.index })
        var theirs = marks.filter(function (m) { return m.group === target.index })
        var crossGap = Infinity
        for (var i = 0; i < mine.length; i++) {
            for (var j = 0; j < theirs.length; j++) {
                var d = Math.hypot(mine[i].x - theirs[j].x, mine[i].y - theirs[j].y)
                if (d < crossGap) crossGap = d
            }
        }
        if (!Number.isFinite(crossGap)) return null

        var anchors = state.nebula.anchors
        var natural = Layout.kernelForPoints(marks,
            Math.max(anchors[source.index].r, anchors[target.index].r))
        var bridging = Math.max(natural, crossGap * 0.95)
        var outline = Layout.contour(marks.map(function (mark) {
            return { x: mark.x, y: mark.y, r: bridging }
        }))
        if (outline.rings.length === 0) return null

        var path = new Path2D()
        outline.rings.forEach(function (ring) {
            if (ring.length < 2) return
            path.moveTo(ring[0].x, ring[0].y)
            for (var i = 1; i < ring.length; i++) path.lineTo(ring[i].x, ring[i].y)
            path.closePath()
        })
        return path
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
            if (drag.target.kind === "cloud") drawFusionMelt(colors, drag.tag, drag.target.tag)
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
     * The content mark under the pointer.
     *
     * The target is at least HIT_MIN_PX whatever the mark's drawn size, so a
     * 2 px dot is still tappable: the level of detail decides how much a mark
     * SAYS, never whether it can be reached.
     *
     * Nearest wins, because marks never overlap (the sunflower keeps them
     * over a pitch apart and the territories are disjoint), so "nearest" and
     * "the one you meant" are the same answer.
     */
    function pickStar(px, py) {
        if (!state.nebula || contentPx() < STAR_MIN_PX) return null
        var world = screenToWorld(px, py)
        var reach = Math.max(Layout.CONTENT_R, HIT_MIN_PX / camera.scale)
        var best = null, bestDistance = Infinity
        state.nebula.points.forEach(function (mark) {
            var distance = Math.hypot(world.x - mark.x, world.y - mark.y)
            if (distance > reach || distance >= bestDistance) return
            bestDistance = distance
            best = mark
        })
        if (!best) return null
        // The body may not have arrived: the manifest carries identities, and
        // the level of detail only asks for the marks big enough to show a
        // title. A tap is a request for THIS one, so it is fetched now and
        // the card opens when it lands.
        return { key: best.key, item: bodies[best.key] || null, mark: best }
    }

    /**
     * Fetches one content's body, for a tap on a mark whose title has not
     * been asked for yet.
     */
    function fetchOneBody(key) {
        if (bodyFetches[key] || bodyMisses[key]) return Promise.resolve(null)
        // A tap is an explicit request, so it ignores the backoff -- but it
        // still records one, so holding the pointer down cannot loop.
        bodyFetches[key] = true
        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", "")
        form.append("scope", "keys")
        form.append("keys", key)
        form.append("fields", "full")
        return fetch(state.serviceUri + "/tagmap-service.php", { method: "POST", body: form })
            .then(function (response) { return response.json() })
            .then(function (body) {
                delete bodyFetches[key]
                var items = (body.items || []).filter(function (item) { return item.key === key })
                // Two items for one key means the 48-bit key collided; show
                // nothing rather than possibly the wrong article.
                if (items.length !== 1) { bodyMisses[key] = true; return null }
                bodies[key] = items[0]
                return items[0]
            })
            .catch(function (error) {
                delete bodyFetches[key]
                bodyBackoff[key] = Math.min(BODY_RETRY_MAX_MS,
                    (bodyBackoff[key] || BODY_RETRY_MS / 2) * 2)
                bodyRetryAt[key] = performance.now() + bodyBackoff[key]
                console.warn("TagMap: body lookup failed", error)
                return null
            })
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
                var wanted = gesture.startScale * Math.hypot(a.x - b.x, a.y - b.y) / gesture.startDist
                var scale = clampScale(wanted)
                // A pinch pushes against the clamps like a wheel does, so it
                // feeds the same intent accumulator.
                var ceiling = zoomCeiling(), floor = zoomFloor()
                if (wanted > ceiling) zoomPush = Math.min(ZOOM_PUSH_CAP, Math.log(wanted / ceiling))
                else if (wanted < floor) zoomPush = Math.max(-ZOOM_PUSH_CAP, -Math.log(floor / wanted))
                else { zoomPush = 0; zoomPending = null }
                var mid = { x: (a.x + b.x) / 2 - canvasLeft(), y: (a.y + b.y) / 2 - canvasTop() }
                camera.scale = scale
                camera.x = gesture.anchor.x - (mid.x - cssW / 2) / scale
                camera.y = gesture.anchor.y - (mid.y - cssH / 2) / scale
                considerZoomTransition(mid.x, mid.y)
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
                if (Object.keys(pointers).length < 2) {
                    gesture = null
                    // Fingers lifted: the band springs back to the clamp.
                    releaseZoom()
                }
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
            meltPath = null
            releaseZoom()
            // A pinch that crossed the threshold and was then cancelled must
            // not navigate: the reader did not finish the gesture, and the
            // system cancelling it is not their decision.
            abortZoomIntent()
        }
        canvas.addEventListener("pointercancel", abortGesture)
        canvas.addEventListener("lostpointercapture", abortGesture)
        window.addEventListener("blur", function () { abortGesture(null) })

        canvas.addEventListener("wheel", function (event) {
            event.preventDefault()
            cameraTarget = null
            // ctrlKey on a wheel event is a trackpad pinch, not a scroll.
            // Same intent, so the same path -- just a different sensitivity.
            var factor = Math.exp(-event.deltaY * (event.ctrlKey ? 0.008 : 0.0012))
            var px = event.clientX - canvasLeft()
            var py = event.clientY - canvasTop()

            var now = performance.now()
            if (!zoomBurst || now > zoomBurst.endsAt) {
                endZoomBurst()
                zoomBurst = {
                    entryPath: tagPathOf(state.segments),
                    entryHref: buildHref(state.segments),
                    transitions: 0,
                    endsAt: 0,
                }
            }
            zoomBurst.endsAt = now + WHEEL_BURST_GAP_MS
            clearTimeout(wheelBurstTimer)
            wheelBurstTimer = setTimeout(endZoomBurst, WHEEL_BURST_GAP_MS + 20)

            applyZoom(factor, px, py)
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
        if (star) {
            if (star.item) openInfoCard(star)
            else fetchOneBody(star.key).then(function (item) {
                if (item) openInfoCard({ key: star.key, item: item, mark: star.mark })
            })
            return
        }
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
        if (!Array.isArray(data.memberships)) return false
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
            current: container ? toScreen({ tag: "(current)", x: 0, y: 0, r: state.levelR }) : null,
            children: state.layout.slots.map(function (slot) {
                return toScreen(state.universe[slot.tag])
            }),
            ancestors: state.ancestors.map(function (ancestor) {
                return {
                    depth: ancestor.depth,
                    tagPath: tagPathOf(ancestor.segments),
                    boundary: toScreen({ tag: "(boundary)", x: ancestor.x, y: ancestor.y, r: ancestor.r }),
                    // World as well as screen: the containment and no-overlap
                    // claims about an ancestor's field are camera-independent,
                    // and the camera is mid-ease at a transition.
                    wx: ancestor.x,
                    wy: ancestor.y,
                    wr: ancestor.r,
                    children: ancestor.children.map(function (child) {
                        return Object.assign(
                            { wx: child.x, wy: child.y, wr: child.r }, toScreen(child))
                    }),
                }
            }),
            groups: state.layout.slots.map(function (slot) {
                var node = state.universe[slot.tag]
                return { tag: slot.tag, tags: node ? node.tags : null, count: node ? node.count : null }
            }),
            nebula: state.nebula ? {
                // World coordinates as well as screen ones: the continuity
                // assertions compare across a navigation, and at that instant
                // the camera is mid-ease, so screen coordinates cannot say
                // whether anything actually moved.
                points: state.nebula.points.map(function (point) {
                    var screen = worldToScreen(point.x, point.y)
                    return {
                        key: point.key,
                        group: point.group,
                        inBand: point.inBand,
                        wx: point.x,
                        wy: point.y,
                        x: Math.round(screen.x * 100) / 100,
                        y: Math.round(screen.y * 100) / 100,
                    }
                }),
                contours: state.nebula.contours.map(function (c) {
                    return {
                        tag: c.tag, reach: c.reach, members: c.members,
                        rings: c.rings.length, area: c.area,
                    }
                }),
                anchors: state.nebula.anchors.map(function (a) {
                    return { tag: a.tag, reach: a.reach, wx: a.x, wy: a.y, wr: a.r }
                }),
                levelR: state.nebula.levelR,
                packExtent: state.nebula.packExtent,
                extent: state.nebula.extent,
                // CSS px per content radius, so "a content is N px" is
                // directly assertable rather than re-derived from the camera.
                contentPx: contentPx(),
                truncated: state.nebula.truncated,
            } : null,
            // The zoom, as a set of numbers: "you cannot zoom past a
            // content" and "pushing the ceiling is the enter gesture" both
            // become assertions rather than intentions.
            zoom: {
                k: camera.scale,
                contentPx: contentPx(),
                fit: fitScale(),
                floor: zoomFloor(),
                ceiling: zoomCeiling(),
                atCeiling: camera.scale >= zoomCeiling() - 1e-9,
                atFloor: camera.scale <= zoomFloor() + 1e-9,
                push: Math.round(zoomPush * 1000) / 1000,
                pending: zoomPending ? { kind: zoomPending.kind, tag: zoomPending.tag } : null,
                burst: zoomBurst ? zoomBurst.transitions : null,
                disabled: zoomDisabled,
            },
            // Why a content is or is not named, as numbers.
            labels: state.labels || null,
            stats: state.data ? state.data.stats : null,
            pending: Object.keys(ancestorFetches).concat(Object.keys(bodyFetches)),
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
        // Zooming, as the reader does it. Without these the dwell, the
        // cooldown and the runaway backstop cannot be tested -- and an
        // untestable transition loop is the worst failure this feature has.
        wheel: function (deltaY, px, py) {
            var factor = Math.exp(-deltaY * 0.0012)
            var now = performance.now()
            if (!zoomBurst || now > zoomBurst.endsAt) {
                endZoomBurst()
                zoomBurst = {
                    entryPath: tagPathOf(state.segments),
                    entryHref: buildHref(state.segments),
                    transitions: 0, endsAt: 0,
                }
            }
            zoomBurst.endsAt = now + WHEEL_BURST_GAP_MS
            cameraTarget = null
            applyZoom(factor, px === undefined ? cssW / 2 : px, py === undefined ? cssH / 2 : py)
        },
        endWheelBurst: endZoomBurst,
        releaseZoom: releaseZoom,
        zoomTo: function (scale) {
            cameraTarget = null
            camera.scale = clampScale(scale)
            zoomPush = 0
            zoomPending = null
        },
        hitTest: function (px, py) {
            var star = pickStar(px, py)
            // The url is only known once the body has arrived; the key is
            // always known, because that is what the manifest carries.
            if (star) {
                return {
                    kind: "content",
                    key: star.key,
                    url: star.item ? star.item.url : null,
                    title: star.item ? star.item.title : null,
                }
            }
            var cloud = pickCloud(px, py)
            if (cloud) return { kind: cloud.kind, tag: cloud.tag }
            if (pickContainer(px, py)) return { kind: "interior" }
            var ancestor = pickAncestor(px, py)
            if (ancestor) return { kind: "ancestor", tagPath: tagPathOf(ancestor.segments) }
            return { kind: "empty" }
        },
    }

    /** Resolves once the camera has settled, for deterministic assertions. */
    TM.settle = function (timeoutMs) {
        var deadline = Date.now() + (timeoutMs || 5000)
        return new Promise(function (resolve) {
            var quiet = 0
            // setTimeout, not requestAnimationFrame: a hidden tab runs no
            // frames, so a frame-driven wait would never resolve there --
            // and never reach the document.hidden test either. The deadline
            // is the backstop for a camera that never settles.
            var check = function () {
                quiet = cameraTarget === null ? quiet + 1 : 0
                if (quiet >= 2 || document.hidden || Date.now() > deadline) {
                    resolve(TM.snapshot())
                    return
                }
                setTimeout(check, 16)
            }
            check()
        })
    }

    /**
     * What a frame costs on the main thread, in milliseconds.
     *
     * Report this, not a frame rate: on a 30 Hz display every frame is 33.3
     * ms apart no matter how cheap the drawing is, so the rate cannot tell a
     * heavy renderer from a slow screen. `p95` against a 16.7 ms budget is
     * the number that decides whether the drawing fits in a frame.
     */
    TM.perf = function (reset) {
        var recent = perf.recent.slice().sort(function (a, b) { return a - b })
        var at = function (share) {
            return recent.length === 0 ? 0
                : Math.round(recent[Math.min(recent.length - 1,
                    Math.floor(recent.length * share))] * 100) / 100
        }
        var report = {
            frames: perf.frames,
            mean: perf.frames === 0 ? 0 : Math.round(perf.total / perf.frames * 100) / 100,
            median: at(0.5),
            p95: at(0.95),
            worst: Math.round(perf.worst * 100) / 100,
            budget: 16.7,
            window: recent.length,
            contours: state.nebula ? state.nebula.contours.length : 0,
            points: state.nebula ? state.nebula.points.length : 0,
        }
        if (reset) perf = { frames: 0, total: 0, worst: 0, recent: [] }
        return report
    }

    window.TagMap = TM

    // Last: applyData() reads TM.snapshot, so the hooks must exist first.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init)
    } else {
        init()
    }
})()
