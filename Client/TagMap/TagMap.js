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
     * drag a sibling INTO the boundary to absorb it into this segment, drag
     * out past the boundary to leave. Drag empty space to pan, wheel/pinch
     * to zoom -- zoom is mostly level-of-detail, but pushing past a clamp or
     * filling the viewport with one group does navigate; see
     * considerZoomTransition.
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
    // The zoom ceiling lives in the layout module now (Layout.zoomCeiling),
    // because it is derived: it is the scale at which a content's CARD holds
    // a full excerpt. The old ceiling of 28 px was the right rule for a DISC
    // -- text does not scale with the camera, so magnifying a circle buys
    // nothing -- and the wrong rule for a card, whose whole point is text.
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
    var RING_PX = 5               // where a dot stops being drawn in a batch
    // How solid a mark is. It rises with the mark rather than the mark
    // rising with it: the SIZE states the content's real extent and must not
    // be shrunk to quieten it. At the far end 411 marks would otherwise read
    // as the subject and the nebulae as background, which is the metaphor
    // upside down. Above RING_PX it is simply 1.
    var STAR_ALPHA_MIN = 0.35
    var STAR_ALPHA_MAX = 1.0
    // The background speck field. Sized so an ink dot survives on paper --
    // see buildStarLayer -- and kept clear of the marks by weight rather
    // than by size, since the two overlap in size at the far end anyway (a
    // mark is about 4 px there).
    var STAR_COUNT = 200
    var STAR_MIN_R = 0.6
    var STAR_MAX_R = 2.2
    // How far ahead of the card's own thresholds a body is fetched. What a
    // card can hold is Layout.cardFor()'s answer, not a separate list of px
    // -- but asking exactly when the text would appear shows a blank card
    // for one round trip, so the request runs one zoom step early. A wheel
    // step is 1.302, measured; 1.4 covers it with room over.
    var BODY_LEAD = 1.4
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
    // Which content the detail card is showing: {key, item}. The card used
    // to keep no state at all -- "is it open" was read off a DOM class -- so
    // there was nothing to draw a connector from. positionPopup already
    // re-anchors the tag popup to its node every frame; this is the same
    // idea for the lines.
    var infoTarget = null
    var drag = null         // {tag, x, y, target}
    var dragHint = null     // screen-space label for the current drop target
    var running = false
    // Ticks once per frame; the nebula path cache keys on it.
    var frameSerial = 0
    var drawErrors = 0      // consecutive failing frames; see frame()
    // Read live, not once. Until now this gated one thing -- the ease that
    // frames a level -- and every motion in the canvas was transient, so a
    // stale answer cost at most one eased camera move. The decorations below
    // never stop, so a reader who turns the preference on mid-session has to
    // be obeyed mid-session.
    var motionQuery = window.matchMedia
        ? window.matchMedia("(prefers-reduced-motion: reduce)") : null
    var reducedMotion = motionQuery ? motionQuery.matches : false
    if (motionQuery && motionQuery.addEventListener) {
        motionQuery.addEventListener("change", function (event) {
            reducedMotion = event.matches
        })
    }

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
    //
    // Most of a body is selection-independent -- a title, a summary and a URL
    // are the same wherever the content is drawn -- but `suggested` and
    // `score` are NOT: they say "in the selection you asked about, this
    // matched by name rather than by a tag". So each cached item records the
    // selection it was fetched under, as `forTagPath`, and anything that
    // makes a claim about membership checks it first. Without that the badge
    // on a detail card would keep asserting a verdict from a level the
    // reader has left.
    var bodies = {}
    var bodyFetches = {}       // key -> in flight
    // key -> its 48-bit manifest key collided with another content's, so it
    // can never be resolved and must never be asked about again. ONLY that:
    // "the server returned nothing" used to land here too, which made a
    // transient disagreement permanent -- the class of bug that left cards
    // blank with no way to recover. Nothing-at-all now goes to the backoff
    // below, where a retry is possible.
    var bodyMisses = {}
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
    // One body lookup at a time. The sweep runs every frame and used to fire
    // again the instant an answer landed, so a single navigation split into
    // seven requests (measured entering /Unity, 23 marks). Each one pays PHP
    // startup, metadata and index loading before it reads a single body, so
    // the split multiplies a fixed cost the reader gains nothing from.
    var bodySweepInFlight = false
    var navGeneration = 0   // see navigate(): guards against stale responses
    var loadingTimer = null
    // The in-flight gate renewal, held as the promise and not as a boolean: all
    // three service callers can get 428 in the same frame, and a boolean would
    // only tell the second and third arrivals that someone else was working --
    // it gives them nothing to await. Holding the promise makes them join it.
    var gateRenewal = null
    var gateFailedAt = 0
    // A failed renewal is not retried for this long. The proof is ~65,536
    // SHA-256 digests (16 bits), so an unattended tab facing a permanently
    // broken gate would otherwise burn that every time the body sweep's own
    // backoff came due -- silently, for as long as the tab stayed open.
    var GATE_COOLDOWN_MS = 30000
    // Not a cap on how long the reader waits -- a cap on how long the slot
    // above can stay occupied. AccessGate.solve() has no abort, so without
    // this a proof that never finishes would block every later renewal.
    // Generous on purpose: the readers this feature exists for are on mobile,
    // and the same devices are the slowest at the proof. Raise it if
    // ACCESS_GATE_POW_BITS ever goes above 16.
    var GATE_POW_TIMEOUT_MS = 60000

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
            accent: v("--tagmap-tag", "#3498db"),
            accentRing: v("--tagmap-tag-ring", "#006399"),
            focus: v("--tagmap-focus", "#d6991f"),
            focusRing: v("--tagmap-focus-ring", "#a87500"),
            reading: v("--tagmap-reading", "#e12885"),
            // A content's two colours. Kept apart from `surface`, which the
            // DOM chrome also wears: tinting that would tint the breadcrumb
            // and the info card too.
            contentDisc: v("--tagmap-content-disc", "#bec8d1"),
            contentPaper: v("--tagmap-content-paper", "#ffffff"),
            contentEdge: v("--tagmap-content-edge", "#808080"),
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
     * Mints a fresh gate token in place, without a page load.
     *
     * The token is bound to the address it was issued to (IPv4 /32, IPv6 /64),
     * so it dies the moment a reader moves between Wi-Fi and a mobile network,
     * and every service call then answers 428. This used to be
     * location.reload(), which cost two further document loads -- the reload
     * carries the stale cookie, so the .htaccess prefilter, which only tests
     * that a cookie EXISTS, passes it through to the inline challenge, which
     * reloads again -- and discarded everything the URL does not carry: the
     * camera, the open card, every fetched title, the ancestor context, and
     * any pages past the first.
     *
     * None of that was necessary. ContentsViewer.js has re-minted in place
     * since the gate landed; only this file believed a page load was the one
     * way in.
     *
     * NOT ensureToken(): AccessGate.hasToken() is a substring test on
     * document.cookie, so it sees presence and never validity. An
     * IP-invalidated token is present, so ensureToken() would answer {ok:true}
     * about the very cookie that just failed. Only acquireToken() re-mints.
     *
     * Resolves true when a usable cookie now exists, false otherwise. It never
     * rejects: the caller's only question is whether it may re-send, and a
     * boolean is the whole answer.
     *
     * No onProgress: solve() offers one, but there is nowhere on this map to
     * put an attempt counter that would not be more alarming than the 2px
     * line setLoading() already raises.
     *
     * @param force skip the failure cooldown (the reader asked, in person)
     */
    function renewGate(force) {
        if (gateRenewal) return gateRenewal
        if (!force && gateFailedAt && performance.now() - gateFailedAt < GATE_COOLDOWN_MS) {
            return Promise.resolve(false)
        }
        // Unreachable in practice: access-gate.js and this file are both
        // deferred and it is listed first, and no 428 can arrive before
        // init(). Guarded anyway, because a missing global would throw inside
        // a promise chain and surface as an invisible unhandled rejection
        // rather than as the toast the reader needs.
        if (!window.AccessGate) {
            console.warn("TagMap: gate renewal needed but AccessGate is not loaded")
            return Promise.resolve(false)
        }

        // setLoading is a class toggle, not a refcount, so a foreground
        // request still in flight loses its line one RTT early when this
        // settles. Refcounting it means auditing six call sites and fixing
        // navigate()'s cache-hit path, which calls setLoading(false) with no
        // matching true -- more risk than the 50ms of comfort is worth.
        setLoading(true)
        var timer = null
        var renewal = Promise.race([
            window.AccessGate.acquireToken(state.serviceUri).then(function (result) {
                if (!result.ok) console.warn("TagMap: gate renewal failed (" + result.reason + ")")
                return result.ok
            }, function (error) {
                console.warn("TagMap: gate renewal threw", error)
                return false
            }),
            // A race, not a cancellation: solve() has no abort path. The
            // orphan keeps hashing and writes the cookie whenever it lands,
            // which only helps -- some later request simply works.
            new Promise(function (resolve) {
                timer = setTimeout(function () { resolve(false) }, GATE_POW_TIMEOUT_MS)
            }),
        ]).then(function (ok) {
            // Identity, not truthiness -- the same discipline as
            // navGeneration. Only the current renewal may clear the slot, so
            // an orphan finishing a minute late cannot wipe a live one.
            if (gateRenewal === renewal) {
                gateRenewal = null
                gateFailedAt = ok ? 0 : performance.now()
            }
            clearTimeout(timer)
            setLoading(false)
            return ok
        })
        gateRenewal = renewal
        return renewal
    }

    /**
     * The reader asking is the strongest signal there is, and it outranks the
     * cooldown, which exists only to stop unattended retries. It lives here so
     * the cooldown has one owner and the retry buttons do not reach into it.
     */
    function clearGateCooldown() {
        gateFailedAt = 0
    }

    /**
     * Posts to the tag-map service and resolves the parsed body.
     *
     * Owns the transport and nothing else. What a body MEANS differs by
     * caller -- canonical_mismatch is not an error to fetchState but any
     * body.error is one to the body lookups -- so interpretation stays where
     * it already is.
     *
     * The gate policy lives here because it has to. It used to be a predicate,
     * gateExpired(), and before that the predicate lived only in fetchState(),
     * which left the body lookups retrying on their backoff forever: marks
     * stayed anonymous, one console warning a minute, and fetchOneBody()
     * reported the empty response as "in the manifest but not in the
     * population" -- blaming the data for what the gate had done. Recovery now
     * re-SENDS the request, and a predicate cannot do that; it does not know
     * the request. So what is shared is the call itself.
     *
     * A 428 body is `{"error":"challenge_required"}`, which carries no items.
     * It is never parsed here, so it can never be read as an answer about any
     * key.
     *
     * One re-send, ever. A second 428 means the token was already wrong when
     * it was minted -- the address moved again during the proof -- which
     * re-minting cannot fix, so it surfaces as the server's own error string.
     *
     * @param signal optional. The re-send reuses it, so a reader who navigated
     *   during the proof gets AbortError from an already-aborted controller,
     *   which navigate() and loadMore() already handle. Nothing here has to
     *   know about navigation.
     */
    function askService(form, signal) {
        function send(retried) {
            // The same FormData can be sent twice: fetch extracts a fresh body
            // from it on every send. (A Request or a stream body could not.)
            return fetch(state.serviceUri + "/tagmap-service.php", {
                method: "POST",
                body: form,
                signal: signal,
            }).then(function (response) {
                if (response.status === 428) {
                    if (retried) throw new Error("challenge_required")
                    return renewGate().then(function (ok) {
                        if (!ok) throw new Error("challenge_required")
                        return send(true)
                    })
                }
                // Without this a 500 fell through to response.json() and
                // reached the caller as a SyntaxError, so the sweep logged a
                // parser error under "body lookup failed" and navigate() put
                // "Unexpected token '<'" in a toast -- blaming the client's
                // parser for the server's outage.
                if (!response.ok) throw new Error("http_" + response.status)
                return response.json()
            })
        }
        return send(false)
    }

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

        // Two one-shot retries, independent of each other: askService may
        // re-send once for the gate, and this may recurse once for a canonical
        // path. The ceiling for one call is therefore four service requests
        // and one seed -- 428, renew, canonical_mismatch, 428, renew. The
        // recursion carries isRetry, so there is no third round.
        return askService(form, signal).then(function (body) {
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
     * Summaries come with the title, in one request. Asking for titles first
     * and summaries later, once a mark grew big enough to show one, read the
     * same content file TWICE to save a single decode (~3-4 ms) -- and a
     * whole file read costs more than that on any filesystem, so the staging
     * was a loss everywhere rather than a trade.
     */
    function ensureBodies() {
        if (!state.nebula) return
        if (bodySweepInFlight) return   // see bodySweepInFlight
        var soon = Layout.cardFor(contentPx() * BODY_LEAD)
        if (!soon.showTitle) return   // nothing would have room to show it
        var now = performance.now()
        var shape = markShape()
        var wanted = []
        // The list the card pass draws, not state.nebula.points: during a
        // bloom the two differ, and a mark that is drawn but not in the
        // fetch list is a mark that can appear blank. Marks on their way OUT
        // are skipped -- they belong to the selection being left, so their
        // keys have no referent in the one being asked about.
        marksThisFrame().forEach(function (mark) {
            if (mark.leaving) return
            if (bodies[mark.key]) return
            if (bodyMisses[mark.key] || bodyFetches[mark.key]) return
            if (bodyRetryAt[mark.key] && now < bodyRetryAt[mark.key]) return
            var screen = worldToScreen(mark.x, mark.y)
            if (!nearViewport(screen, shape.w, shape.h)) return
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

        // The selection these keys came from. A manifest key is 48 bits of a
        // path hash and carries no level, so it only has a referent inside
        // the selection that minted it -- the server resolves it against
        // that selection's population, which is the same list the manifest
        // was built from. Sending "" asked the server to resolve them
        // against the tagged corpus instead, where a content selected by
        // NAME similarity does not appear: those marks were drawn and could
        // never be named (8 of /Arduino's 65).
        var askedFor = tagPathOf(state.segments)
        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", askedFor)
        form.append("scope", "keys")
        form.append("keys", keys.join(","))
        form.append("fields", "full")   // see the note above ensureBodies

        // Held across a gate renewal too, which is exactly what it is for: the
        // proof takes seconds, and the mutex keeps the per-frame sweep quiet
        // for all of them at no extra cost. Every exit clears it -- the .then
        // below and the .catch at the end -- so a 428 can no longer strand it.
        bodySweepInFlight = true
        askService(form)
            .then(function (body) {
                bodySweepInFlight = false
                if (body.error) throw new Error(body.error)
                // This answer is kept even if the reader has moved on. The
                // keys and the tagPath left in the SAME request, so the
                // server resolved exactly these keys in exactly the
                // selection they came from -- the answer cannot be about a
                // different level, and a title does not expire when the
                // camera does. Discarding it here (which an earlier draft
                // did, by analogy with navGeneration) only threw away
                // correct titles and asked for them again.
                //
                // What a late answer CAN carry is a stale membership
                // verdict, so each item is stamped with the selection it was
                // resolved in; see `bodies`.
                var byKey = {}
                ;(body.items || []).forEach(function (item) {
                    byKey[item.key] = byKey[item.key] === undefined ? item : null
                })
                var absent = 0
                keys.forEach(function (key) {
                    delete bodyFetches[key]
                    var item = byKey[key]
                    if (item) {
                        item.forTagPath = askedFor
                        bodies[key] = item
                        delete bodyRetryAt[key]
                        delete bodyBackoff[key]
                        return
                    }
                    if (byKey[key] === null) {
                        // Two items for one key: the 48-bit key collided.
                        // Permanent, and correctly so -- the same two paths
                        // will collide next time, and naming an article with
                        // another article's title is worse than leaving it
                        // unnamed.
                        bodyMisses[key] = true
                        return
                    }
                    // Nothing at all. This should not be reachable: the key
                    // came from the manifest of this very selection, and the
                    // server resolves it against that selection's own
                    // population. If it happens, contents changed under us
                    // or something is broken -- both worth asking about
                    // again, so it goes to the backoff rather than being
                    // silenced forever.
                    bodyBackoff[key] = Math.min(BODY_RETRY_MAX_MS,
                        (bodyBackoff[key] || BODY_RETRY_MS / 2) * 2)
                    bodyRetryAt[key] = performance.now() + bodyBackoff[key]
                    absent++
                })
                if (absent > 0) {
                    // Worth a line: the manifest and the population are
                    // supposed to be the same list, so a gap is a real
                    // inconsistency and not a normal outcome.
                    console.warn("TagMap: " + absent + " of " + keys.length
                        + " key(s) are in the manifest of " + (askedFor || "(root)")
                        + " but not in its population")
                }
                ensureLoop()
            })
            .catch(function (error) {
                bodySweepInFlight = false
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
    // Layout.zoomCeiling() so the zoom range is finite.
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
            // The same contours keyed by tag. Already built by sceneShapes;
            // kept so the label pass can reach a group's own anchor without
            // scanning the list every frame.
            contourByTag: shapes.byTag,
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
            var depth = i + 1
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
                depth: depth,
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
                        // Where this sibling would take you, and how far back
                        // up it lives. Computed here so the hit test, the
                        // popup and the drag all read one answer instead of
                        // re-deriving it from the ancestor three times.
                        segments: link.segments.concat([[slot.tag]]),
                        depth: depth,
                        // What its circle's size already states: the count
                        // you get by entering it. The popup shows this, so
                        // the number and the size cannot disagree.
                        reach: slot.reach,
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
    // against the floor for coming out. It needs no new control: the reader
    // keeps zooming and the map keeps obliging.
    //
    // The ceiling alone is not enough now that it sits at the card scale --
    // about 93 px per content radius, which is 6.8x past the fit on
    // /Arduino, far too much wheel-work to be the way in. So OCCUPANCY is
    // the other trigger: a group that fills most of the viewport is one the
    // reader is plainly heading into. Measured, that is reachable -- a
    // 14-content group needs k >= 28, a 33-content group k >= 18.
    //
    // Tap and drag stay the primary path regardless.
    var ZOOM_PUSH_CAP = 0.35      // log units of push we bother to accumulate
    var ZOOM_PUSH_COMMIT = 0.22   // and how much commits the transition
    var ZOOM_DWELL_MS = 120       // held continuously, so a flick cannot commit
    var ZOOM_COOLDOWN_MS = 320
    var ZOOM_MAX_PER_BURST = 3
    // A group filling this share of the viewport commits going in; the
    // current level shrinking to this share commits coming out. The gap is a
    // factor of 1.8 in scale, and the two tests are on DIFFERENT objects (a
    // child versus this level), so they cannot chase each other.
    var ZOOM_ENTER_OCCUPANCY = 0.62
    var ZOOM_LEAVE_OCCUPANCY = 0.34
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
    var marksFrame = null     // {t, marks} the interpolated list, once per frame
    // Screen rects of the cards drawn this frame, so the group names can
    // avoid them. Rebuilt every frame by drawNebulaCards.
    var cardRects = []
    // Screen rects of the NAMES drawn this frame, with what each one names.
    // A group's name is the one part of it that is certainly visible, never
    // overlaps anything (place() collision-checks it) and is never under a
    // card, so it is the tap target the group can rely on. Rebuilt every
    // frame by drawLabels, the same way cardRects is.
    var labelHits = []
    // Why a name did not get drawn, counted per frame.
    //
    // place() is a first-come arbiter with four ways to say no, and every one
    // of them was silent: it returns false and NO caller looks at the return.
    // Content marks have had `named`/`anonymous` since the card tier landed,
    // and that pair is what made "drawn but nameless" a fixable bug rather
    // than a matter of opinion. Group names had nothing at all, so the same
    // failure was invisible on the other half of the picture.
    //
    // `wanted` counts the groups that reached the arbiter plus the ones the
    // radius gate turned away, so `placed / wanted` is the number to move.
    var nameStats = null
    // Corner radius of a fully-grown card, in CSS px. The mark interpolates
    // from a circle (corner = half its side) to this.
    //
    // Fixed px rather than a share of the card, for the same reason the type
    // is: a card grows 89x63 -> 198x140 across the reading zooms, and a
    // radius that grew with it would turn the big one into a lozenge. So the
    // rounding is constant and only its SHARE of the card shrinks, from 9.5%
    // to 4.5%.
    //
    // The value is a choice, not a derivation -- the site's own content card
    // (.card-item) is 2px and the map's DOM chrome is 5-12px, so this sits
    // between them by decision.
    var CARD_CORNER_PX = 8
    // The connector from the detail card to the marks it is about.
    var LINK_WIDTH_PX = 1.5
    // The edge of the content being read. Every other mark gets 1.
    var READING_EDGE_PX = 2
    // One trip of the pulse along a connector, and one dash-length step of
    // the ring around the mark it points at. Slow on purpose: this is a
    // place marker, not an alert, and anything quick enough to catch the eye
    // twice is quick enough to stop the reader finishing a sentence.
    var LINK_PULSE_MS = 2600
    var READING_DASH_MS = 1400
    // Half the pulse's length, as a share of the line. The rest of the line
    // stays drawn, dimmed: the connector's job is to say WHERE, and a line
    // that is only visible where the pulse happens to be stops doing it.
    var LINK_PULSE_LEN = 0.16
    var LINK_DIM_ALPHA = 0.4
    // Long dashes with short gaps: the edge still reads as an edge, and the
    // rotation is what you notice rather than the dashes themselves.
    var READING_DASH = [14, 5]
    // The outline of the group a zoom is aimed at, flowing the way the
    // reader is pushing.
    var INTENT_DASH = [10, 6]
    var INTENT_FLOW_MS = 1800
    // The drift of a speck-sized mark: how far, how slowly, and the object
    // returned when there is none, so the callers allocate nothing per mark
    // per frame in the common case.
    // The drift field: how fast it turns over, how far it swings, and how
    // broad it is.
    //
    // FLOAT_WAVE is in WORLD units, where marks are CONTENT_PITCH (2.6)
    // apart, so it is really "how many marks to a wave" -- here about two
    // and a third.
    //
    // It was 15, nearly six marks to a wave, and at any zoom that puts a
    // dozen marks on screen that is most of the picture inside one wave: it
    // read as the whole field sliding rather than flexing. Measured at
    // contentPx 45 while walking it down, with the outlines warped by the
    // same field:
    //
    //   wave   neighbours differ   outline vs true isoline   marks escaping
    //     15         4.62 px               14.5 px              0 / 568
    //      9         6.18 px               15.4 px              0 / 568
    //      6         7.85 px               17.0 px              0 / 568
    //      4         9.08 px               22.3 px              0 / 568
    //
    // The cost of going finer is fidelity: a shorter wave has a steeper
    // gradient, and the warped outline is only the true isoline of the
    // warped marks to within that gradient over one kernel radius. At 6 the
    // error is 17 px against a 180.8 px kernel margin -- 9% -- and no mark
    // left its own outline at any wavelength tried.
    var FLOAT_MS = 11000
    var FLOAT_WAVE = 6
    // The finer octave, as a multiple of the base frequency. Over one
    // CONTENT_PITCH it advances 3.4 * 2.6 / 15 = 0.59 of a radian, so it is
    // what makes two neighbours differ; the base is what keeps a whole
    // region drifting together. Together they close a touching pair by about
    // a third of the amplitude -- 4 px of the 16 px between two titles at
    // FLOAT_MAX_PX.
    var FLOAT_OCTAVE = 3.4
    var FLOAT_K = 1.1
    var FLOAT_MAX_PX = 11
    var ZERO_DRIFT = { x: 0, y: 0 }
    // Endpoints drawn this frame, for tests. One entry per instance of the
    // content the detail card is showing, so a test counts lines instead of
    // a reader counting them in a screenshot.
    var linkEnds = []
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

    /**
     * Where a looping decoration is in its cycle, 0..1.
     *
     * The one clock in the draw path, and it drives style only -- a dash
     * offset, a gradient stop. No geometry reads it, which is the line the
     * determinism tests actually draw: they stub Math.random and Date.now
     * around the LAYOUT module, and what they protect is "same payload, same
     * picture". A moving dash does not move a vertex.
     *
     * Wall-clock rather than a frame count, so the speed is the same on a
     * 30 Hz panel and a 120 Hz one. Frozen at 0 under reduced motion, which
     * makes every caller below static without any of them testing for it.
     */
    function motionPhase(periodMs) {
        if (reducedMotion) return 0
        return (performance.now() % periodMs) / periodMs
    }

    /** A content radius in CSS px. The level-of-detail indicator. */
    function contentPx() {
        return Layout.CONTENT_R * camera.scale
    }

    function zoomCeiling() {
        return Layout.zoomCeiling()
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
        // The pending intent is considerZoomTransition's alone. Clearing it
        // here whenever the push was zero killed the OCCUPANCY intent on
        // every event -- push is zero for the whole range between the floor
        // and the ceiling, which is exactly where occupancy does its work --
        // so the dwell never accumulated and going in still needed the
        // ceiling. Measured: the intent was re-seeded every 47 ms and only
        // committed once the push finally crossed, at k = 107 of a 93 ceiling.

        camera.scale = bandedScale(zoomDesired)
        camera.x = anchor.x - (px - cssW / 2) / camera.scale
        camera.y = anchor.y - (py - cssH / 2) / camera.scale
        considerZoomTransition(px, py, factor > 1 ? 1 : (factor < 1 ? -1 : 0))
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

    /**
     * What share of the smaller viewport dimension a circle of world radius
     * `r` covers. 1 means it just spans the screen.
     */
    function occupancy(r) {
        var side = Math.min(cssW, cssH)
        return side > 0 ? (2 * r * camera.scale) / side : 0
    }

    function considerZoomTransition(px, py, direction) {
        if (zoomDisabled) return
        var now = performance.now()
        if (now < zoomCooldownUntil) return
        if (zoomBurst && zoomBurst.transitions >= ZOOM_MAX_PER_BURST) return

        // Which group the pointer is in, needed by both triggers going in.
        var candidate = zoomCandidate(px, py)

        // Two ways to commit, and either is enough.
        //
        // The push is the reader shoving past a clamp. It no longer suffices
        // on its own: the ceiling now sits at the card scale, 6.8x past the
        // fit on /Arduino, which is far too much wheel-work to be the way in.
        //
        // So occupancy is the other one. A group that fills most of the
        // viewport is one the reader is plainly heading into, and that is
        // reachable -- measured, a 14-content group crosses 0.62 at k = 28,
        // a 33-content group at k = 18, both inside the fit-to-ceiling range.
        // It requires the reader to still be zooming that way, because a big
        // group is big whichever direction they came from.
        var kind = null
        if (zoomPush >= ZOOM_PUSH_COMMIT) kind = "in"
        else if (zoomPush <= -ZOOM_PUSH_COMMIT) kind = "out"
        else if (direction > 0 && candidate
            && occupancy(groupRadius(candidate.tag)) >= ZOOM_ENTER_OCCUPANCY) kind = "in"
        else if (direction < 0
            && occupancy(drawnExtent()) <= ZOOM_LEAVE_OCCUPANCY) kind = "out"
        if (!kind) { zoomPending = null; return }
        if (kind === "out" && state.segments.length === 0) return

        var tag = null
        if (kind === "in") {
            // Null when two outlines both hold the point: see zoomCandidate.
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

    /**
     * A group's radius in world units, as the reader sees it: the outline it
     * is drawn with, falling back to the territory the layout gave it.
     */
    function groupRadius(tag) {
        var node = state.universe ? state.universe[tag] : null
        return node && node.r > 0 ? node.r : 0
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
     * The ceiling is the point of the whole absolute scale: it is the scale
     * at which a content's card holds a full excerpt, so there is a real end
     * to zooming in and its justification is legibility rather than taste.
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
        // The per-frame mark list is keyed by bloom.t, so a new bloom that
        // starts at the same t the last one ended on would be served the
        // PREVIOUS level's marks for its first frame.
        marksFrame = null
        // The reader's pending zoom request belonged to the OLD level's
        // scale. Left standing, the very next wheel event recomputed the
        // camera from it and snapped the scale by 2.5x in one frame --
        // measured, and inside a single level, so it read as the map
        // breaking rather than as navigation. Cleared, the next event
        // re-seeds from the camera the re-anchor just placed.
        zoomDesired = 0
        zoomPush = 0
        zoomPending = null
        // A pinch carries a frame of its own, and it is the same shape as a
        // camera: gesture.anchor is a world point, gesture.startScale a
        // scale. The pointermove handler recomputes the camera from both on
        // every event, so leaving them in the old frame does not merely add
        // an error -- it writes the old frame straight back and ERASES the
        // re-anchor above. Measured entering /Arduino: the wheel's scale
        // stepped 13.43 -> 9.87 across the transition (by 1/beta) and its
        // camera moved 14.5 world units, while the pinch went 12.80 -> 14.11
        // and moved 0.35, as though no level had changed.
        //
        // Being a camera's shape, it moves by the camera's own function. Note
        // startDist is a distance on the SCREEN, so it is frame-independent
        // and must not be touched; the direction test downstream compares
        // finger distances and is unaffected for the same reason.
        if (gesture && gesture.mode === "pinch") {
            var frame = Layout.bloomCamera(
                { x: gesture.anchor.x, y: gesture.anchor.y, scale: gesture.startScale },
                centre, beta, direction)
            if (frame) {
                gesture.anchor = { x: frame.x, y: frame.y }
                gesture.startScale = frame.scale
            }
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

        // The popup is anchored to a tag OF THIS LEVEL, so it cannot survive
        // one: its subject is gone by definition. The card is anchored to a
        // content, and a content can be at both levels -- see
        // syncInfoCardToLevel, which decides per content rather than per
        // navigation.
        closePopup()
        syncInfoCardToLevel()
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
            if (error.message === "challenge_required") {
                // The gate could not be renewed in place. Say what happened
                // rather than showing the wire's word for it, and let the
                // reader decide: their asking outranks the failure cooldown,
                // so clear it before re-entering.
                showToast(localize("接続が変わったため確認が必要です", "Your connection changed -- verification failed"), function () {
                    clearGateCooldown()
                    navigate(segments, options)
                })
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
            // Paging had no retry at all, so a failure here was terminal until
            // the reader navigated away and back. It has one now, because a
            // failed gate renewal is exactly the kind of failure that a second
            // attempt fixes.
            showToast(localize("読み込みに失敗しました", "Failed to load"), function () {
                if (error.message === "challenge_required") clearGateCooldown()
                loadMore()
            })
        })
    }

    // ---- star layer -------------------------------------------------------

    /**
     * The parallax speck field: a depth cue, drawn under everything at a
     * quarter of the camera's motion.
     *
     * Present in BOTH themes. It used to be dark-only, on the reasoning that
     * a paper chart has no glowing specks -- true of glowing ones, but the
     * paper answer is ink rather than light, and having the depth cue in one
     * theme and not the other made the two themes different designs.
     *
     * The background must never be the loudest thing on screen. Measured
     * before this was enforced, the dark field's strongest speck reached
     * 3.89:1 against its ground while the faintest content mark managed
     * 1.60:1 -- the context out-shouting the subject, the same inversion the
     * marks themselves were once guilty of.
     *
     * A fixed cap could not fix that without making the field invisible: the
     * marks fade with distance (starAlpha) while a fixed field does not, so
     * a value safe at the far end -- where the marks reach only 1.49:1 --
     * was imperceptible at every zoom a reader actually uses, where they
     * reach 3.63:1. So the FIELD fades with the marks instead (see draw()),
     * the ratio holds everywhere, and the ink can be strong enough to see.
     *
     * Both inks carry the same alpha, so neither theme has more background
     * than the other.
     *
     * Seeded, so the same field appears for every reader on every visit.
     */
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
        // Radius and count, not alpha, are what made the field invisible on
        // paper. A 0.4 px ink dot antialiases away to nothing, and raising
        // the alpha could not buy back area -- the loudest speck only got to
        // 1.68:1. Night got away with it because a glowing speck on a nearly
        // black ground starts at 3.89:1, so it could afford to be tiny.
        for (var i = 0; i < STAR_COUNT; i++) {
            octx.globalAlpha = 0.12 + random() * 0.5
            octx.beginPath()
            octx.arc(random() * off.width, random() * off.height,
                STAR_MIN_R + random() * (STAR_MAX_R - STAR_MIN_R), 0, TAU)
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
            frameSerial++
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
            // The field carries the same weight the marks do, so it is
            // subordinate to them at EVERY zoom rather than only at the one
            // the cap was chosen for. Fixed, it had to be set faint enough
            // for the far end -- where the marks fade to 1.49:1 -- which
            // left it invisible everywhere else. Tracking them, it can be
            // strong enough to see (loudest speck 2.08:1 against the marks'
            // 3.63:1) and still fades away with them.
            ctx.globalAlpha = starAlpha(contentPx())
            for (var x = ox - starLayer.width; x < cssW; x += starLayer.width) {
                for (var y = oy - starLayer.height; y < cssH; y += starLayer.height) {
                    ctx.drawImage(starLayer, x, y)
                }
            }
            ctx.globalAlpha = 1
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

        // The marks, once they are big enough to hold text. Screen space,
        // because the type is a fixed size and would otherwise scale with
        // the camera. No collision test: a card's diagonal is CONTENT_PITCH
        // and placeMarks keeps marks at least that far apart, so two cards
        // cannot reach each other.
        drawNebulaCards(colors)

        // The content being read, and the lines tying it to every place it
        // sits -- both AFTER the marks.
        //
        // The lines used to run before them, so that they would "pass UNDER
        // them and never cross the text they are pointing at". The first half
        // of that was the cost, not the aim: it bought nothing from the mark
        // being pointed AT -- the line already stops at that mark's edge, and
        // still does -- while burying the line under every unrelated mark it
        // crossed on the way. A connector that cannot be followed is not one,
        // and being followable clear across the screen is the whole reason
        // --tagmap-reading has a hue of its own.
        //
        // The card's own text stays safe either way: it is DOM, above the
        // canvas at z-index 20, so no draw order here can reach it.
        drawReadingMarks(colors)
        drawInfoLinks(colors)

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
     * Is an ancestor's context drawn at all this frame?
     *
     * Only while the level still reads as a circle. Past this, its boundary
     * is off-screen and its children are each larger than the viewport -- a
     * wall of arcs that says nothing and competes with the level you are
     * actually in. Zoom out and it returns.
     */
    function ancestorDrawn(ancestor) {
        return ancestor.r * camera.scale <= Math.hypot(cssW, cssH) * 1.4
    }

    /**
     * One of an ancestor's children as it is DRAWN this frame, or null.
     *
     * markShape()'s rule applied to the group layer. Read by drawAncestors,
     * by the sibling names and by pickSibling, so what is visible is what is
     * touchable -- the three used to decide it separately and two of them had
     * already drifted:
     *
     *  - pickSibling applied NONE of these tests. Measured over 36
     *    zoom-and-position combinations, it answered "sibling" 4,854 times
     *    and 1,659 of those named a child the frame had not drawn. Those
     *    1,659 are every single answer it gave at contentPx 12 and 26, where
     *    ancestorDrawn culls the ancestor whole and its 96 children are
     *    nowhere on screen: dragging blank canvas grabbed one of them
     *    instead of panning. Now 0, while the 3,195 answers at contentPx 6,
     *    where the children ARE drawn, are unchanged.
     *  - the sibling names copied two of the four tests by hand and dropped
     *    the other two.
     *
     * `rings.scale` folds bloomFactor() in for the same reason: the draw
     * multiplied by map.scale * bloomFactor() while the pick divided by
     * map.scale alone, so mid-transition the polygon being tested was not
     * the polygon being stroked. One number now, so they cannot differ --
     * a guarantee by construction, since a bloom is too brief to sample.
     *
     * The viewport test earns nothing for the hit test -- you can only press
     * where you are looking, and child.rings sits inside the child.r circle
     * this rejects on. It is here because drawAncestors needs it, and
     * because a reader should not have to work out which of four tests
     * matters to which of three callers. That is the whole point.
     */
    function ancestorChildShape(ancestor, child) {
        if (!ancestorDrawn(ancestor)) return null
        var inflate = bloomFactor()
        var worldR = child.r * inflate
        var childR = worldR * camera.scale
        // Outlines, not fills, and only at a size that still reads as a
        // circle: an ancestor's children are magnified by 1/slot.r, so
        // filling them turns the context into the loudest thing on screen
        // and it competes with the level you are actually in.
        if (childR < 3 || childR > Math.hypot(cssW, cssH) * 0.55) return null
        var screen = worldToScreen(child.x, child.y)
        if (screen.x < -childR || screen.x > cssW + childR
            || screen.y < -childR || screen.y > cssH + childR) return null
        var map = ancestor.map
        return {
            worldR: worldR,
            childR: childR,
            screen: screen,
            // Where child.rings lands in world space, or null when it has no
            // soft body and the circle is all there is.
            rings: child.rings && map && map.scale > 0
                ? { scale: map.scale * inflate, x: map.x, y: map.y }
                : null,
        }
    }

    /**
     * The levels you came through: each drawn as a boundary with its other
     * children still in place. That IS the trail -- the way back is a shape
     * you can see and zoom out into, not a line of dots -- and the siblings
     * around you are literally the ones you left behind.
     */
    function drawAncestors(colors) {
        state.ancestors.forEach(function (ancestor) {
            if (!ancestorDrawn(ancestor)) return

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

            ctx.globalAlpha = 0.45
            ctx.strokeStyle = colors.muted
            ctx.lineWidth = hairline()
            ancestor.children.forEach(function (child) {
                // Which children are drawn, and where, is ancestorChildShape's
                // to say -- not restated here, so the hit test and the names
                // cannot fall behind an edit made in this loop.
                var shape = ancestorChildShape(ancestor, child)
                if (!shape) return
                if (shape.rings) {
                    // The soft body it was. Its rings are in the ancestor's
                    // own coordinates, so they go through the same map its
                    // centres did -- one similarity, no rebuild.
                    ctx.stroke(ringsToPath(child.rings, shape.rings.scale,
                        shape.rings.x, shape.rings.y))
                    return
                }
                ctx.beginPath()
                ctx.arc(child.x, child.y, shape.worldR, 0, TAU)
                ctx.stroke()
            })
            ctx.restore()
        })
    }

    /**
     * Paths for the current nebula.
     *
     * The EXTRACTION stays scene data: the root's 106 outlines are ~12,000
     * vertices and cost about 4 ms of marching squares, which is fine once
     * per navigation and impossible per frame. Nothing here redoes that.
     *
     * The Path2D objects were scene data too, and no longer are, because the
     * outlines now drift with their contents. Building them from vertices
     * that already exist is a different cost from finding those vertices:
     * measured on the root, 0.90 ms a frame against a 16.7 ms budget, and
     * less at any zoom where the legibility cull takes outlines out of the
     * set. When nothing is moving they are still built once and kept.
     *
     * The legibility cull is the ONLY one. Neither this pass nor drawNebula
     * has a viewport test: every legible outline is handed to the rasteriser
     * and clipped there. This said "the legibility cull and the viewport",
     * which reads as a second condition someone could go and find.
     */
    function nebulaPaths() {
        var nebula = state.nebula
        if (!nebula) return null
        if (nebula.paths && (reducedMotion || nebula.pathsAt === fieldClock())) return nebula.paths

        var paths = nebula.contours.map(function (contour) {
            var path = new Path2D()
            var circle = null
            contour.rings.forEach(function (ring) {
                if (ring.length < 2) return
                // Each vertex reads the field at its OWN position, which is
                // what deforms the shape. Moving the whole outline by one
                // sample would translate a rigid body instead.
                var d = fieldAt(ring[0].x, ring[0].y)
                path.moveTo(ring[0].x + d.x / camera.scale, ring[0].y + d.y / camera.scale)
                for (var i = 1; i < ring.length; i++) {
                    d = fieldAt(ring[i].x, ring[i].y)
                    path.lineTo(ring[i].x + d.x / camera.scale, ring[i].y + d.y / camera.scale)
                }
                path.closePath()
            })
            // A group whose outline is a single point's isoline IS a circle,
            // so draw it as one: 60-odd vertices per group, saved on the
            // long tail of one-content groups that most levels are made of.
            // It has no vertices to warp, so it travels with the one mark it
            // is drawn around -- which for a circle is the whole truth.
            if (contour.members <= 1 && contour.rings.length === 1) {
                var anchor = nebula.anchors[contour.index]
                var ad = fieldAt(anchor.x, anchor.y)
                circle = { x: anchor.x + ad.x / camera.scale,
                    y: anchor.y + ad.y / camera.scale,
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
        nebula.pathsAt = fieldClock()
        return paths
    }

    /**
     * Which frame the field is on, so a scene's paths are rebuilt once per
     * frame rather than once per pass -- drawNebula asks for them twice, for
     * the fills and then for the outlines.
     */
    function fieldClock() {
        return frameSerial
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
                // The outline flows while the push is on it. The weight
                // already answers "how hard"; the flow answers "at what",
                // which is the question a reader mid-gesture is asking, and
                // a moving edge separates this outline from the still ones
                // around it faster than a thicker one does.
                //
                // Divided by camera.scale because this runs inside the world
                // transform, the same correction drawDrag makes so its dash
                // stays a constant size on screen at any zoom.
                ctx.setLineDash([INTENT_DASH[0] / camera.scale, INTENT_DASH[1] / camera.scale])
                ctx.lineDashOffset =
                    -motionPhase(INTENT_FLOW_MS) * (INTENT_DASH[0] + INTENT_DASH[1]) / camera.scale
                strokeOrArc(paths[m], true)
                ctx.setLineDash([])
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
     * Where every mark is drawn THIS frame, under its content's key.
     *
     * One list, read by the dots, the cards and the hit test alike, so they
     * cannot disagree about where a content is. They used to: the draw used
     * the interpolated positions during a level change while the hit test
     * read the settled ones, so mid-transition a tap landed on the wrong
     * content.
     *
     * During a level change a content present at both levels slides from
     * where it was to where it belongs; one only in the new level fades in,
     * one only in the old fades out where it stood.
     */
    function marksThisFrame() {
        if (!state.nebula) return []
        if (!(morph && bloom)) return state.nebula.points
        if (marksFrame && marksFrame.t === bloom.t) return marksFrame.marks

        var progress = Layout.smoothstep(0, 1, bloom.t)
        var map = morph.map
        var wasByKey = {}
        morph.marks.forEach(function (mark) {
            if (wasByKey[mark.key] === undefined) wasByKey[mark.key] = mark
        })

        var out = []
        var nowKeys = {}
        state.nebula.points.forEach(function (mark) {
            nowKeys[mark.key] = true
            var was = wasByKey[mark.key]
            if (!was) {
                out.push({ key: mark.key, x: mark.x, y: mark.y,
                    inBand: mark.inBand, group: mark.group, alpha: progress })
                return
            }
            var fromX = was.x * map.scale + map.x
            var fromY = was.y * map.scale + map.y
            out.push({
                key: mark.key,
                x: fromX + (mark.x - fromX) * progress,
                y: fromY + (mark.y - fromY) * progress,
                inBand: mark.inBand, group: mark.group, alpha: 1,
            })
        })
        morph.marks.forEach(function (mark) {
            if (nowKeys[mark.key]) return
            out.push({
                key: mark.key,
                x: mark.x * map.scale + map.x,
                y: mark.y * map.scale + map.y,
                inBand: mark.inBand, group: -1, alpha: 1 - progress, leaving: true,
            })
        })
        marksFrame = { t: bloom.t, marks: out }
        return out
    }

    /**
     * The marks, while they are still too small to be anything but dots.
     *
     * One path, one fill. Four hundred separate arc calls cost more than the
     * whole rest of the frame, and at this size individual styling would not
     * be visible anyway. Above RING_PX the marks become cards, drawn in
     * screen space by drawNebulaCards -- text is a fixed size and cannot be
     * drawn inside the world transform.
     */
    /**
     * How solid a content mark is at this size. Both passes read this, so
     * the batched dots and the discs that replace them agree at RING_PX by
     * construction rather than by two matching literals.
     */
    function starAlpha(screenR) {
        return STAR_ALPHA_MIN + (STAR_ALPHA_MAX - STAR_ALPHA_MIN)
            * Layout.smoothstep(STAR_MIN_PX, RING_PX, screenR)
    }

    /**
     * A slow drift for a mark drawn as a speck, in CSS px.
     *
     * The only decoration here that says nothing. Everything else that moves
     * is an instrument -- the pulse says which way the card points, the ring
     * says what is being read, the flowing outline says what a push is aimed
     * at -- and this is the texture of the field itself, so that a map with
     * nothing selected is not a still photograph.
     *
     * How far it may drift, and why it is not confined to the specks.
     *
     * Marks are at least CONTENT_PITCH apart and a card's diagonal is
     * exactly CONTENT_PITCH, so two cards can at worst touch at a corner --
     * that is what lets a title be drawn inside its mark with no collision
     * test at all. Drifting by A leaves them 2A closer, which sounds like it
     * spends that guarantee, and the first version of this confined the
     * drift to below RING_PX for exactly that reason.
     *
     * It was the wrong reading. The guarantee that matters is about TITLES,
     * and a title is inset CARD_PAD (8 px) from its card's edge, so two
     * titles are 16 px apart when their cards touch. They cannot meet until
     * A reaches 8 px. At FLOAT_PX the drift spends a third of that margin
     * and the cards themselves never visibly overlap.
     *
     * Measured cost of the first version: at the root's fit scale, contentPx
     * is about 4.5, where the fade to RING_PX left an amplitude of 0.2 px.
     * The drift was placed where it was safe rather than where the reader
     * was, and it was invisible in the view the map opens on.
     *
     * Read by every pass that turns a mark into a position -- through
     * markScreen() -- and by pickStar, because markShape()'s rule is that
     * what is visible is what is touchable.
     */
    function markFloat(mark) {
        return fieldAt(mark.x, mark.y)
    }

    var fieldPhase = { serial: -1, a: 0, b: 0, c: 0, d: 0 }

    /**
     * The field's four phases, advanced once a FRAME rather than once per
     * sample.
     *
     * They are constant within a frame, and the field is now read by every
     * mark AND every contour vertex -- 12,370 of them on the root. Computing
     * them inline cost four performance.now() calls per sample, about fifty
     * thousand a frame, and took the frame from 3.7 ms to 13.9 ms against a
     * 16.7 ms budget. The arithmetic was never the expense; asking the clock
     * was.
     */
    function fieldPhases() {
        if (fieldPhase.serial !== frameSerial) {
            fieldPhase.serial = frameSerial
            fieldPhase.a = motionPhase(FLOAT_MS) * TAU
            fieldPhase.b = motionPhase(FLOAT_MS * 1.618) * TAU
            fieldPhase.c = motionPhase(FLOAT_MS * 0.773) * TAU
            fieldPhase.d = motionPhase(FLOAT_MS * 1.317) * TAU
        }
        return fieldPhase
    }

    /**
     * The drift field at a world point, in CSS px.
     *
     * One function, sampled by everything that moves: the marks, the vertices
     * of the outlines around them, and the anchors their names sit on. That
     * is what makes the outline follow its contents rather than merely move
     * near them -- an isoline of a warped field is very nearly the warp of
     * the isoline, and the error is the field's gradient over one kernel
     * radius. Measured on the root at contentPx 45: the warped outline sits
     * within 14.5 px of the true isoline of the warped marks, against a
     * 180.8 px kernel margin, and no mark left its own outline in 830 checks.
     */
    function fieldAt(x, y) {
        if (reducedMotion) return ZERO_DRIFT
        // A FIELD, phased by where a mark is rather than by which mark it is.
        // Neighbours therefore drift almost together, and what changes
        // between two of them is the field's gradient over the distance
        // separating them -- at FLOAT_WAVE that is about a sixth of the
        // amplitude across one CONTENT_PITCH, so their separation barely
        // moves however far the field swings.
        //
        // Independent per-mark phases were tried first and are what forced
        // the amplitude down: two neighbours in opposite phase close by 2A,
        // so A had to stay well under the 8 px where titles would meet. A
        // coherent field lifts that ceiling almost entirely, and it is also
        // the difference between a field breathing and four hundred separate
        // things jittering.
        // Each term takes ONE phase, with a coefficient of exactly 1.
        //
        // motionPhase is a sawtooth: it steps from 1 back to 0. sin and cos
        // are TAU-periodic, so a phase used as `sin(p + k)` crosses that step
        // without a seam -- but `sin(0.6p + k)` does not, because 0.6*TAU is
        // not a whole turn. Scaling a wrapped phase was how the first version
        // of this was written, and it tore twice a cycle. Four periods,
        // mutually incommensurate, give the same unrepeating drift with no
        // term that can tear.
        var ph = fieldPhases()
        var a = ph.a, b = ph.b, c = ph.c, d = ph.d
        var u = x / FLOAT_WAVE, v = y / FLOAT_WAVE
        // A second, finer octave. The coarse one alone made neighbours move
        // as one: over a CONTENT_PITCH gap its phase advances by only
        // 2.6/15 of a radian, so two marks side by side differed by 17% of
        // the amplitude and the field read as a few large slabs sliding.
        //
        // Shortening the coarse wavelength instead would have bought detail
        // by spending the coherence the amplitude rests on. An octave adds
        // the detail beside it: FLOAT_OCTAVE times the frequency at a third
        // the weight, which roughly doubles what separates two neighbours
        // while leaving the broad drift they share intact.
        var p = u * FLOAT_OCTAVE, q = v * FLOAT_OCTAVE
        var amp = floatAmplitude()
        return {
            x: (Math.sin(a + u + v * 0.7) + Math.sin(c + v * 1.3)) * 0.375 * amp
                + Math.sin(b + p - q * 0.6) * 0.25 * amp,
            y: (Math.cos(b + v - u * 0.8) + Math.cos(d + u * 1.1)) * 0.375 * amp
                + Math.cos(c + q + p * 0.9) * 0.25 * amp,
        }
    }

    /**
     * How far the field swings, in CSS px.
     *
     * Not a constant, and not proportional either. A fixed number of pixels
     * is 83% of a speck and 1.3% of a full-sized card, so the drift read as
     * strong when zoomed out and vanished when zoomed in -- which was a side
     * effect of picking the simplest safe value, not a decision. Strict
     * proportion is the other extreme: matching the speck's share would want
     * about 28 px at contentPx 45.
     *
     * The square root is the usual compromise between "the same movement"
     * and "the same relative movement", and it keeps the share within one
     * order of magnitude across the whole zoom range (26% of a speck, 8% of
     * a card at 45, 5% at the ceiling) instead of two.
     */
    function floatAmplitude() {
        return Math.min(FLOAT_MAX_PX, FLOAT_K * Math.sqrt(contentPx()))
    }

    /**
     * Where a mark is DRAWN, in CSS px: its layout position plus its drift.
     *
     * One function, so the dot, the card, the reading ring, the connector
     * and the hit test cannot disagree about where a content is -- the same
     * discipline markShape() and marksThisFrame() keep, and for the same
     * reason: they have disagreed before, and it read as the map breaking.
     */
    function markScreen(mark) {
        var screen = worldToScreen(mark.x, mark.y)
        var drift = markFloat(mark)
        screen.x += drift.x
        screen.y += drift.y
        return screen
    }

    function drawNebulaStars(colors) {
        var screenR = contentPx()
        if (screenR < STAR_MIN_PX) return   // the outlines carry the density
        if (screenR >= RING_PX) return      // the per-mark pass takes over

        var marks = marksThisFrame()
        if (marks.length === 0) return
        var worldR = Layout.CONTENT_R
        var batch = new Path2D()
        marks.forEach(function (mark) {
            // markFloat answers in CSS px; this path is built in world units,
            // so the drift is divided by the scale to stay the same size on
            // screen at any zoom -- the correction drawDrag makes for its
            // dash, for the same reason.
            var drift = markFloat(mark)
            var x = mark.x + drift.x / camera.scale
            var y = mark.y + drift.y / camera.scale
            batch.moveTo(x + worldR, y)
            batch.arc(x, y, worldR, 0, TAU)
        })
        ctx.save()
        ctx.globalAlpha = starAlpha(screenR)
        // The same two colours a big mark wears, so a content's colour does
        // not depend on how far away the camera is. A mark still LOOKS
        // darker when it is tiny, but that is geometry rather than a second
        // palette: a 1 px edge is 100% of a 1.5 px dot's area, 40% of a 5 px
        // one and 10% of a 20 px one.
        ctx.fillStyle = colors.contentDisc
        ctx.fill(batch)
        ctx.strokeStyle = colors.contentEdge
        ctx.lineWidth = hairline()
        ctx.stroke(batch)
        ctx.restore()
    }

    /**
     * The content being read, stroked again in its own colour.
     *
     * --tagmap-reading is defined as "the content you are READING -- the
     * detail card and the lines tying it to every place that content
     * appears", but only the lines ever used it: every mark was stroked
     * contentEdge, #808080 in both themes, whether or not it was the one the
     * card is about. The reader could see WHERE the thing they were reading
     * sits and not see WHICH thing it was.
     *
     * A separate pass rather than a branch inside the two mark passes, for
     * the same reason drawNebula re-strokes the group a push is aimed at
     * after its own loop: the dot pass batches every mark into ONE Path2D
     * and fills it once, so per-mark styling there is not a branch, it is a
     * split batch. Screen space and markShape(), so a single pass covers
     * both regimes -- the shape interpolates disc to card, and a mark too
     * small to hold a title is exactly when finding it matters most.
     */
    function drawReadingMarks(colors) {
        if (!infoTarget || !state.nebula) return
        var shape = markShape()
        var w = shape.w, h = shape.h
        var marks = marksThisFrame()
        ctx.save()
        ctx.strokeStyle = colors.reading
        // Heavier than the 1 px every other mark gets: at a hairline the hue
        // alone carries it, and the hue is the one thing a reader with a
        // colour deficiency may not have.
        ctx.lineWidth = READING_EDGE_PX
        // A dashed edge that turns, slowly: a lock on the thing being read,
        // the same way the connector's pulse is a lock on the way to it. The
        // dash pattern is fixed and only the offset moves, so the ring keeps
        // its weight -- nothing here pulses, because a mark that throbs
        // beside body text is an alert, and this is a place marker.
        // Wrapped on the DASH cycle, not on the perimeter: the pattern
        // repeats every dash+gap, so an offset that returns to 0 after
        // exactly one of those is seamless, while one that runs a whole
        // perimeter tears unless the perimeter happens to be a multiple of
        // it -- which for a rounded rectangle that changes size with zoom it
        // never is. The ring still turns; it just cannot say how far round
        // it has been, which nothing needs it to.
        var dashCycle = READING_DASH[0] + READING_DASH[1]
        ctx.setLineDash(READING_DASH)
        ctx.lineDashOffset = -motionPhase(READING_DASH_MS) * dashCycle
        for (var i = 0; i < marks.length; i++) {
            // A mark on its way out is not what you are reading, the same
            // rule pickStar and drawInfoLinks apply.
            if (marks[i].key !== infoTarget.key || marks[i].leaving) continue
            var screen = markScreen(marks[i])
            if (!nearViewport(screen, w, h)) continue
            ctx.globalAlpha = marks[i].alpha === undefined ? 1 : marks[i].alpha
            roundedRect(ctx, screen.x - w / 2, screen.y - h / 2, w, h, shape.corner)
            ctx.stroke()
        }
        ctx.setLineDash([])
        ctx.restore()
    }

    /**
     * The detail card, tied to every mark of the content it is showing.
     *
     * A content is drawn once per group it belongs to, so one content can be
     * in three places at once -- that duplication is what keeps the group
     * outlines from crossing, and the layout module says three times over
     * that identity lives in the `key` "so the instances of one content can
     * be tied together when the reader asks rather than always". This is the
     * reader asking.
     *
     * Positions come from marksThisFrame(), the same list the dots, the
     * cards and the hit test read, so a line cannot point somewhere a mark
     * is not. Instances the camera is not showing are not dropped: the line
     * stops at the viewport edge with a wedge, which is the only way the
     * reader learns the content is also over there.
     *
     * Both ends stop on an EDGE -- the mark's drawn shape at one end, the
     * detail card's rectangle at the other -- so the line touches what it
     * connects instead of sliding underneath it. An instance the camera is
     * not showing gets its line run off the viewport edge, which says
     * "further that way" without adding a glyph to say it.
     *
     * Screen space, because one end of every line is a DOM rectangle.
     */
    function drawInfoLinks(colors) {
        linkEnds.length = 0
        if (!infoTarget || !state.nebula) return
        var card = elements.infoCard
        if (!card || !card.classList.contains("visible")) return
        var box = card.getBoundingClientRect()
        if (!(box.width > 0)) return
        var anchor = { x: box.left + box.width / 2, y: box.top + box.height / 2 }
        var cardRect = { x: box.left, y: box.top, w: box.width, h: box.height }
        var viewport = { x: 0, y: 0, w: cssW, h: cssH }

        var marks = marksThisFrame().filter(function (mark) {
            return mark.key === infoTarget.key && !mark.leaving
        })
        if (marks.length === 0) return

        // The mark's own drawn shape, so a line can stop at its edge the way
        // it stops at the card's. This clip used to be load-bearing for a
        // second reason -- the cards were drawn after this pass, so anything
        // reaching a grown mark's centre ended up under it. That is no longer
        // true: the pass now runs after the cards. The clip stays for the
        // reason it was named after, which is the one that never depended on
        // draw order: a line that ran to the centre would cross the title of
        // the very mark it is pointing at.
        var shape = markShape()

        ctx.save()
        ctx.strokeStyle = colors.reading
        ctx.fillStyle = colors.reading
        ctx.lineWidth = LINK_WIDTH_PX
        ctx.lineJoin = "round"
        for (var i = 0; i < marks.length; i++) {
            var screen = markScreen(marks[i])
            // Off screen: stop at the edge and say which way it lies.
            var clamped = Layout.clampToRect(anchor.x, anchor.y, screen.x, screen.y, viewport)
            var far = clamped || Layout.clipToRect(anchor.x, anchor.y, screen.x, screen.y, {
                x: screen.x - shape.w / 2, y: screen.y - shape.h / 2,
                w: shape.w, h: shape.h,
            }) || screen

            // The near end stops ON the card, not under it.
            //
            // This clip was here once, removed, and is back, and the reason
            // is worth keeping because it is the same reason both times: the
            // card USED to be opaque, so the browser covered the tail and
            // clipping bought nothing while costing lines -- an instance
            // behind the card had its whole segment inside the rectangle and
            // got dropped (measured: 3 lines for 4 instances at k=26).
            //
            // The card is translucent now, so the tail would be visible: a
            // fan of lines converging on a point under the text. And the
            // cost that made clipping a bad trade has gone with it, because
            // an instance behind the card is now visible THROUGH it -- the
            // line was standing in for a mark the reader could not see, and
            // the mark speaks for itself.
            var near = Layout.clipToRect(far.x, far.y, anchor.x, anchor.y, cardRect)
            if (near) {
                // A brightening that travels from the card TOWARD the
                // content, so the line says which end is the question and
                // which is the answer. A uniform dash would be cheaper and
                // says nothing about direction.
                //
                // Two strokes rather than a gradient: a gradient wants its
                // stops as colour strings with alpha, and the palette hands
                // out whatever the stylesheet says -- parsing it here would
                // put a colour parser in the draw path to save one stroke of
                // a handful of lines.
                ctx.globalAlpha = LINK_DIM_ALPHA
                ctx.beginPath()
                ctx.moveTo(far.x, far.y)
                ctx.lineTo(near.x, near.y)
                ctx.stroke()

                // at(0) is the card edge, at(1) is the mark. A phase that
                // counts UP therefore walks the head from the card out to
                // the content: the card asks, and the line goes and points.
                var head = motionPhase(LINK_PULSE_MS)
                var at = function (t) {
                    return { x: near.x + (far.x - near.x) * t, y: near.y + (far.y - near.y) * t }
                }

                // The band WRAPS rather than restarting. Its tip leaves at
                // the mark while its tail is still short of it, and the part
                // that has left re-enters at the card in the same instant --
                // so it is drawn as two pieces whenever it straddles an end,
                // and the amount of bright line never changes.
                //
                // An earlier version faded the pulse out at both ends so the
                // restart could not be seen. That hid the seam instead of
                // removing it, and cost the line its steady brightness for
                // most of every trip.
                var a0 = head - LINK_PULSE_LEN, b0 = head + LINK_PULSE_LEN
                var spans = a0 < 0 ? [[a0 + 1, 1], [0, b0]]
                    : b0 > 1 ? [[a0, 1], [0, b0 - 1]]
                    : [[a0, b0]]
                ctx.globalAlpha = 1
                for (var sp = 0; sp < spans.length; sp++) {
                    var from = at(spans[sp][0]), to = at(spans[sp][1])
                    ctx.beginPath()
                    ctx.moveTo(from.x, from.y)
                    ctx.lineTo(to.x, to.y)
                    ctx.stroke()
                }
            }
            linkEnds.push({
                x: Math.round(far.x), y: Math.round(far.y),
                offScreen: !!clamped, behindCard: !near, group: marks[i].group,
            })
        }
        ctx.restore()
    }

    /**
     * A content, as the card it grows into.
     *
     * The title and summary go INSIDE the mark, not beside it. Beside it they
     * collide, and the collision test was both expensive and arbitrary:
     * measured on /Arduino, 70-77 marks had a title to show and only 7-12 got
     * the space, chosen by nothing but distance from the screen centre.
     * Inside, collision is impossible and every content that can be named is.
     *
     * The shape interpolates from the disc it was: at Layout.cardFor().round
     * = 1 the width, height and corner radius all agree on a circle, so the
     * handover from the batched dots at RING_PX is invisible.
     */
    /**
     * The shape a mark has on screen right now, in CSS px.
     *
     * A disc at round = 1 -- width, height and corner radius all agree on a
     * circle -- and the card at round = 0, interpolated between. Read by the
     * draw AND by the hit test, so what is visible is what is touchable.
     */
    /**
     * Is a mark near enough to the viewport that its card gets drawn?
     *
     * The card pass and ensureBodies() share this, so "drawn" and "asked
     * about" are the same set. They used to differ: cards were drawn out to
     * a card's width past the edge while bodies were only asked for within
     * 60 px, so the marks in the gap were drawn and never named. Measured 2
     * of the 24 on screen in the ordinary /Arduino view, blank until the
     * camera moved -- the same symptom as the population bug and a wholly
     * separate cause. This is markShape()'s rule applied to a second pair of
     * passes: one predicate, so they cannot drift apart.
     */
    /**
     * Does a circle put any ink on screen? Used to decide what COUNTS as
     * wanting a name, not what gets one.
     *
     * The nearest point of the viewport to the centre, then one distance
     * test -- a group whose centre is far outside can still cross the
     * viewport with its rim, and that group is visible and unnamed.
     */
    function circleOnScreen(x, y, r) {
        var nx = Math.max(0, Math.min(x, cssW))
        var ny = Math.max(0, Math.min(y, cssH))
        return (x - nx) * (x - nx) + (y - ny) * (y - ny) <= r * r
    }

    function nearViewport(screen, w, h) {
        return Layout.nearRect(screen.x, screen.y, w, h,
            { x: 0, y: 0, w: cssW, h: cssH })
    }

    function markShape() {
        var screenR = contentPx()
        var card = Layout.cardFor(screenR)
        var disc = 2 * Layout.CONTENT_R * camera.scale
        var grown = 1 - card.round
        var w = disc + (card.w - disc) * grown
        var h = disc + (card.h - disc) * grown
        return {
            card: card,
            screenR: screenR,
            grown: grown,
            w: w,
            h: h,
            corner: (Math.min(w, h) / 2) * card.round + CARD_CORNER_PX * grown,
        }
    }

    /**
     * Is (px, py) inside a rounded rectangle of w x h centred on (cx, cy)?
     *
     * Exact for both ends of the morph: at corner = w/2 = h/2 this is a
     * circle test, at corner = 0 a rectangle test.
     */
    function insideRounded(px, py, cx, cy, w, h, corner) {
        var dx = Math.abs(px - cx), dy = Math.abs(py - cy)
        var hx = w / 2, hy = h / 2
        if (dx > hx || dy > hy) return false
        var r = Math.min(corner, hx, hy)
        var inx = hx - r, iny = hy - r
        if (dx <= inx || dy <= iny) return true
        return (dx - inx) * (dx - inx) + (dy - iny) * (dy - iny) <= r * r
    }

    function drawNebulaCards(colors) {
        cardRects.length = 0
        var screenR = contentPx()
        var marks = screenR < RING_PX ? [] : marksThisFrame()
        if (marks.length === 0) {
            // Said plainly rather than left stale: a reader of the snapshot
            // must not see the last card tier's counts while dots are drawn.
            state.labels = { contentPx: Math.round(screenR * 100) / 100, tier: "dots" }
            return
        }

        var shape = markShape()
        var card = shape.card
        var grown = shape.grown
        var w = shape.w, h = shape.h, corner = shape.corner
        // Above RING_PX this is 1; it is here so the two passes multiply by
        // the same number and agree exactly at the boundary between them.
        var markAlpha = starAlpha(screenR)
        var named = 0, onScreen = 0, withBody = 0

        ctx.save()
        ctx.textBaseline = "middle"
        ctx.lineWidth = 1
        for (var i = 0; i < marks.length; i++) {
            var mark = marks[i]
            var screen = markScreen(mark)
            if (!nearViewport(screen, w, h)) continue
            onScreen++
            var body = bodies[mark.key]
            if (body) withBody++

            var x = screen.x - w / 2, y = screen.y - h / 2
            var alpha = mark.alpha === undefined ? 1 : mark.alpha
            ctx.globalAlpha = alpha
            roundedRect(ctx, x, y, w, h, corner)

            // Every content is drawn alike, in two colours that split the
            // two jobs a mark has to do at once:
            //
            //   the EDGE says "here is a mark"  -- 3.2:1 against the map
            //   the FILL says "text goes here"  -- 7.94:1 for 11 px type
            //
            // One flat colour cannot do both. Measured: a fill light enough
            // to read type on tops out at 1.39:1 against the ground, and the
            // lightest fill that reaches 3:1 on its own is about #808080,
            // on which the 12 px line falls to 2.07:1. Letting the edge
            // carry the shape frees the fill to be light, which is also the
            // shape the site's own content cards already have.
            //
            // Only the FILL changes with size, and only where it has to: a
            // circle holds no text so it can be a grey chip, a card has to
            // be something type reads on. Tied to `round` rather than to the
            // size, so the crossing is already done wherever text actually
            // appears -- #ccd4db by the first title, plain paper before the
            // excerpt. The edge never changes, and neither does the disc
            // colour, so zooming out does not repaint the contents.
            ctx.globalAlpha = markAlpha * alpha
            ctx.fillStyle = colors.contentPaper
            ctx.fill()
            if (card.round > 0) {
                ctx.globalAlpha = markAlpha * alpha * card.round
                ctx.fillStyle = colors.contentDisc
                ctx.fill()
            }
            ctx.globalAlpha = markAlpha * alpha
            ctx.strokeStyle = colors.contentEdge
            ctx.lineWidth = 1
            ctx.stroke()

            // Only a card that holds text blocks a group name. A disc does
            // not: the group names sat beside discs happily before, and
            // blocking on them would starve the names the level needs.
            if (card.showTitle) cardRects.push({ x: x, y: y, w: w, h: h })

            if (card.showTitle && body) {
                if (drawCardText(colors, card, body, x, y, w, h)) named++
            }
        }
        ctx.restore()
        ctx.globalAlpha = 1

        // Why a content is or is not named, as numbers. With the collision
        // test gone, "named" should equal "on screen and its body has
        // arrived" -- anything less is a defect, not a budget.
        //
        // But that equality is NOT the measure of whether the map can name
        // its contents, and reading it as one hid a real bug for a while: its
        // denominator is "bodies that arrived", so a mark whose body can
        // never arrive is not counted as a failure -- it is not counted at
        // all. `anonymous` uses the denominator that cannot be gamed: every
        // mark this pass DREW. Settled and above the title tier it must be
        // 0. It may be positive for a bloom's length, when marks on their
        // way out are still drawn and were deliberately not asked about; a
        // figure that STAYS positive means either the manifest and the
        // population disagree, or something is drawn that is never asked
        // about. Both look like a blank card, and both used to happen.
        state.labels = {
            contentPx: Math.round(screenR * 100) / 100,
            cardPx: Math.round(w) + "x" + Math.round(h),
            round: Math.round(card.round * 100) / 100,
            lines: card.lines,
            marks: marks.length,
            onScreen: onScreen,
            withBodyAndOnScreen: withBody,
            named: named,
            anonymous: onScreen - withBody,
        }
    }

    /**
     * The type inside a card: title, then where it lives, then the excerpt.
     *
     * Dropped from the bottom as the room runs out, so the most identifying
     * line survives longest. Nothing is cached -- measured, wrapping 24
     * summaries costs 0.19 ms a frame against a 16.7 ms budget, and at the
     * card scale only about two dozen marks are on screen at all.
     */
    function drawCardText(colors, card, body, x, y, w, h) {
        var left = x + Layout.CARD_PAD
        var width = card.usableW
        if (width < 24) return false
        var lineY = y + Layout.CARD_PAD + Layout.CARD_LINE_PX / 2
        var bottom = y + h - Layout.CARD_PAD
        var drew = false

        ctx.font = "600 " + Layout.CARD_TITLE_PX + "px system-ui, sans-serif"
        ctx.fillStyle = colors.ink
        var titleLines = card.titleLines > 1
            ? wrapText(ctx, body.title, width, card.titleLines)
            : [ellipsize(body.title, width, ctx)]
        for (var t = 0; t < titleLines.length; t++) {
            if (lineY + Layout.CARD_LINE_PX / 2 > bottom) break
            ctx.fillText(titleLines[t], left, lineY)
            lineY += Layout.CARD_LINE_PX
            drew = true
        }

        if (card.showParent && body.parentTitle) {
            ctx.font = Layout.CARD_BODY_PX + "px system-ui, sans-serif"
            ctx.fillStyle = colors.muted
            if (lineY + Layout.CARD_LINE_PX / 2 <= bottom) {
                ctx.fillText(ellipsize(body.parentTitle, width, ctx), left, lineY)
                lineY += Layout.CARD_LINE_PX
            }
        }

        if (card.summaryLines > 0 && body.summary) {
            if (body._plain === undefined) body._plain = stripHtml(body.summary)
            if (body._plain) {
                ctx.font = Layout.CARD_BODY_PX + "px system-ui, sans-serif"
                ctx.fillStyle = colors.inkSecondary
                var lines = wrapText(ctx, body._plain, width, card.summaryLines)
                for (var i = 0; i < lines.length; i++) {
                    if (lineY + Layout.CARD_LINE_PX / 2 > bottom) break
                    ctx.fillText(lines[i], left, lineY)
                    lineY += Layout.CARD_LINE_PX
                }
            }
        }
        return drew
    }

    /**
     * The level you are in: a faint wash over its interior, and its outline.
     *
     * The wash is not decoration. Zoomed in, the boundary line is off screen
     * and the wash is the ONLY thing saying you are inside this level rather
     * than out among the ancestors -- removing it was tried and took that
     * away.
     *
     * It does cost the map some colour, and the amount is known: the wash
     * sits UNDER the tag wash, and the two together decide what the inside
     * of a nebula looks like. Amber under blue partly cancels (chroma 6 with
     * the site amber, 3 with the old #eda100) where an unwashed nebula would
     * read at 16. That is the price of the signal, paid knowingly, and it is
     * why the wash is 7% rather than more.
     */
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
    /**
     * The boundary's radii for this frame, mid-morph included. Split out of
     * containerPath so the hit test can read the same numbers the stroke
     * does, instead of a circle that overshoots them by up to 40%.
     */
    function containerRadii() {
        var shapes = sceneShapes()
        if (!shapes || !shapes.boundary) return null
        if (state.enteredHull && bloom) {
            return Layout.blendHulls(state.enteredHull, shapes.boundary,
                Layout.smoothstep(0, 1, bloom.t))
        }
        return shapes.boundary
    }

    function containerPath() {
        var radii = containerRadii()
        return radii ? ringsToPath([Layout.hullRing(radii, 0, 0)]) : null
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
    var ORIGIN_ONLY = [[0, 0]]

    /**
     * Where a label may sit: its anchor, then two rings around it.
     *
     * Eight directions rather than four, so a name hemmed in on two sides
     * still has somewhere to go, and the near ring first so it stays as
     * close to the thing it names as it can.
     */
    function nudgeLadder(step) {
        var out = [[0, 0]]
        for (var ring = 1; ring <= 2; ring++) {
            var r = step * ring
            for (var i = 0; i < 8; i++) {
                var angle = i * Math.PI / 4
                out.push([Math.cos(angle) * r, Math.sin(angle) * r])
            }
        }
        return out
    }

    function drawLabels(colors) {
        var boxes = []
        // LABEL_MAX_BOXES says "labels per frame" and used to count `boxes`,
        // which holds obstacles too. The chrome seeds about 3 and the cards
        // seed one per titled mark -- measured, roughly 24 in a viewport --
        // so from contentPx 26 (where cardFor().showTitle turns on) some 27
        // of the 30 were spent before a single name was attempted. Measured
        // at contentPx 40: 3 groups visible, 0 named, 106 refusals all from
        // this one test.
        //
        // The obstacles still block placement; they just do not spend the
        // allowance. The cost the allowance bounds is the collision scan,
        // and that is already bounded by the viewport -- only the cards on
        // screen are ever in the list.
        var placed = 0
        labelHits.length = 0
        nameStats = { wanted: 0, placed: 0, tooSmall: 0, budget: 0, offScreen: 0, collided: 0 }
        var container = containerRegion()
        var contentRadiusPx = contentPx()

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.textAlign = "left"
        ctx.textBaseline = "middle"

        // The floating chrome owns its rectangles: seeding them means a label
        // can never land under the header or the breadcrumb.
        chromeRects().forEach(function (rect) { boxes.push(rect) })
        // So do the cards. This IS a collision test, but a bounded one: at
        // most LABEL_MAX_BOXES names against the couple of dozen cards a
        // viewport holds, not every content against every other. The test
        // the plan rejected was the all-pairs one, and that is exactly what
        // moving the text inside the mark removed.
        cardRects.forEach(function (rect) { boxes.push(rect) })

        function place(lines, screenX, screenY, options) {
            options = options || {}
            if (placed >= LABEL_MAX_BOXES) { nameStats.budget++; return false }
            // Off-screen labels used to consume the budget and starve the
            // visible ones, so content titles never appeared at all.
            //
            // `clamp` is the exception, and only the group names pass it: a
            // group you have zoomed INTO fills the screen while its anchor
            // sits outside, so the one name the reader most needs is the one
            // this test threw away. Measured at contentPx 60: 3 groups
            // visible, 0 named, every refusal from here. Pulled to the edge
            // instead, the way an off-screen connector end is clamped rather
            // than dropped. The caller decides what "visible" means -- it
            // only clamps for a circle that actually crosses the viewport,
            // so a group genuinely off screen is still refused here.
            if (!options.clamp
                && (screenX < -80 || screenX > cssW + 80 || screenY < -60 || screenY > cssH + 60)) {
                nameStats.offScreen++
                return false
            }
            var padding = options.boxed ? 6 : 3
            var lineHeight = options.lineHeight || 16
            var width = 0
            lines.forEach(function (line) {
                ctx.font = line.font
                width = Math.max(width, ctx.measureText(line.text).width)
            })
            var w = width + padding * 2
            var h = lines.length * lineHeight + padding * 2
            // Now that the label's size is known, the clamp can keep the
            // whole of it on screen rather than just its anchor point.
            if (options.clamp) {
                screenX = Math.max(w / 2 + 6, Math.min(screenX, cssW - w / 2 - 6))
                screenY = Math.max(h / 2 + 6, Math.min(screenY, cssH - h / 2 - 6))
            }
            var nudges = options.nudges || ORIGIN_ONLY
            var box = null
            for (var n = 0; n < nudges.length && !box; n++) {
                var tryX = screenX + nudges[n][0], tryY = screenY + nudges[n][1]
                var candidate = {
                    x: options.center ? tryX - w / 2 : tryX + 10,
                    y: tryY - h / 2,
                    w: w,
                    h: h,
                }
                var free = true
                for (var i = 0; i < boxes.length && free; i++) {
                    var other = boxes[i]
                    free = !(candidate.x < other.x + other.w
                        && candidate.x + candidate.w > other.x
                        && candidate.y < other.y + other.h
                        && candidate.y + candidate.h > other.y)
                }
                if (free) box = candidate
            }
            if (!box) { nameStats.collided++; return false }
            boxes.push(box)
            placed++
            // Recorded for the hit test, so what the reader can read is what
            // they can press. Only labels that name a pickable thing pass a
            // `hit`; the level's own caption and the drag hint do not.
            if (options.hit) {
                labelHits.push({
                    x: box.x, y: box.y, w: box.w, h: box.h,
                    kind: options.hit.kind, tag: options.hit.tag,
                    node: options.hit.node,
                })
            }

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
            if (!node) return
            // The group's OWN anchor: the point where its field is
            // strongest, which Layout.fieldArgmax guarantees is inside its
            // contour. It was computed, stored and tested from the start and
            // nothing read it -- the name sat on the packed slot centre
            // instead, which for a lopsided territory is not even inside the
            // shape being named.
            var contour = state.nebula && state.nebula.contourByTag
                ? state.nebula.contourByTag[entry.tag] : null
            var at = (contour && contour.anchor) || node
            // The same field the outline reads, so a name travels with the
            // shape it names instead of hanging beside it.
            var screen = worldToScreen(at.x, at.y)
            var nd = fieldAt(at.x, at.y)
            screen.x += nd.x
            screen.y += nd.y
            var visible = circleOnScreen(
                worldToScreen(node.x, node.y).x, worldToScreen(node.x, node.y).y,
                node.r * camera.scale)
            // Only groups the reader can actually SEE count as wanting a
            // name. Counting all of them instead made the rate meaningless:
            // zoomed in, most of the root's 106 circles are genuinely off
            // screen and not naming them is correct, so the denominator fell
            // as fast as the numerator and the number said nothing. Measured
            // that way, contentPx 60 reported 0 of 106 -- which reads as a
            // total failure and is in fact the right answer.
            if (visible) nameStats.wanted++
            // Below this the circle is too small for a name to be about
            // anything the reader can see: at contentPx 5 it turns away the
            // 40 single-content groups of the root, whose outlines are not
            // drawn either (NEBULA_MIN_SCREEN_R is higher still). Measured
            // from contentPx 8 upward it never fires.
            if (node.r * camera.scale < LABEL_MIN_SCREEN_R) { nameStats.tooSmall++; return }
            if (place([
                { text: node.tag, font: "600 14px system-ui, sans-serif", color: colors.ink },
                // "of these, N" -- a fact about the current set, matching the
                // quantity this circle's size encodes here.
                {
                    text: localize("このうち " + entry.count + "件", entry.count + " of these"),
                    font: "12px system-ui, sans-serif",
                    color: colors.inkSecondary,
                },
            ], screen.x, screen.y, {
                center: true,
                lineHeight: 16,
                nudges: nudgeLadder(Layout.CARD_H * contentRadiusPx * 0.75),
                // Only a group that actually crosses the viewport earns the
                // edge treatment. One that is wholly elsewhere is refused by
                // the ordinary off-screen test, so zooming in does not line
                // the borders with the names of things you cannot see.
                clamp: visible,
                hit: { kind: "cloud", tag: node.tag, node: node },
            })) nameStats.placed++
        })

        // 2b. Name suggestions. The label IS the whole mark -- no outline
        // is drawn for them at all -- so it has to carry the explanation:
        // these came in by a similar NAME and may share no content with the
        // selection whatsoever.
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
            place(lines, screen.x, screen.y - node.r * camera.scale - 4, {
                center: true, boxed: true, lineHeight: 14,
                hit: { kind: "cloud", tag: node.tag, node: node },
            })
        })

        // 4. The siblings you left behind, so the way out stays named.
        //
        // Which of them are drawn is ancestorChildShape's to say. This used
        // to restate two of its four conditions as literals here and drop
        // the other two, so a child too small to stroke could still be
        // named -- the copy is what a shared function exists to prevent.
        var others = []
        state.ancestors.forEach(function (ancestor) {
            ancestor.children.forEach(function (child) {
                var shape = ancestorChildShape(ancestor, child)
                if (shape) others.push({ node: child, shape: shape })
            })
        })
        others.sort(function (a, b) { return b.node.r - a.node.r })
        others.forEach(function (entry) {
            var node = entry.node
            // A name needs more room than an outline does: this is about
            // whether the circle is big enough to be worth naming, not about
            // whether it is drawn, which is already settled above.
            if (entry.shape.childR < LABEL_MIN_SCREEN_R) return
            place([
                { text: node.tag, font: "600 13px system-ui, sans-serif", color: colors.muted },
            ], entry.shape.screen.x, entry.shape.screen.y, {
                center: true,
                lineHeight: 15,
                nudges: nudgeLadder(Layout.CARD_H * contentRadiusPx * 0.75),
                hit: { kind: "sibling", tag: node.tag, node: node },
            })
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
        // The detail card is the reading surface now, so a name may not sit
        // under it. Only when it is open: hidden, getBoundingClientRect
        // reports zeroes and `add` skips it anyway.
        add(elements.infoCard)
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
        var i = 0
        for (; i < text.length && lines.length < maxLines; i++) {
            var candidate = line + text[i]
            if (context.measureText(candidate).width > maxWidth && line !== "") {
                lines.push(line)
                line = text[i]
            } else {
                line = candidate
            }
        }
        if (lines.length < maxLines && line !== "") {
            lines.push(line)
            return lines
        }
        // Ran out of lines with text still to come. Say so on the last one:
        // an excerpt that just stops reads as a complete summary that
        // happens to end mid-sentence.
        if (lines.length > 0 && (line !== "" || i < text.length)) {
            lines[lines.length - 1] = ellipsize(
                lines[lines.length - 1] + line, maxWidth, context)
        }
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
        // A sibling is not in state.universe -- it lives in state.ancestors
        // -- so the tether has to read it from the drag itself.
        var source = drag.sibling || state.universe[drag.tag]
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
            var mark = (drag.target.kind === "out" || drag.target.kind === "absorb")
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
                    : (drag.target.kind === "absorb"
                        // Widens THIS segment -- the frame it was dropped
                        // in. Matches the popup's join button.
                        ? localize("一緒に見る", "View together")
                        // A NEW level holding both. Matches the hint strip.
                        : localize("一緒に入る", "Enter together")),
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
     * Tested against the shape the mark is DRAWN as -- the same rounded
     * rectangle markShape() gives the renderer -- so a card's corners are
     * live and a disc's are not.
     *
     * Scaled up whole if the mark is smaller than HIT_MIN_PX, so a 2 px dot
     * is still tappable: the level of detail decides how much a mark SAYS,
     * never whether it can be reached.
     *
     * Read from marksThisFrame(), not from the settled layout. Mid-transition
     * those differ, and taking the settled one meant a tap during a level
     * change landed on whichever content used to be there.
     *
     * Nearest centre wins. The shapes never overlap (the sunflower keeps the
     * marks over a pitch apart and a card's diagonal is exactly that pitch),
     * so only the enlarged minimum targets can, and there "nearest" is the
     * one you meant.
     */
    function pickStar(px, py) {
        if (!state.nebula || contentPx() < STAR_MIN_PX) return null
        var shape = markShape()
        var grow = Math.max(1, HIT_MIN_PX / Math.min(shape.w, shape.h))
        var w = shape.w * grow, h = shape.h * grow, corner = shape.corner * grow
        var best = null, bestDistance = Infinity
        marksThisFrame().forEach(function (mark) {
            // A mark on its way out is not a target: it is where a content
            // WAS, and the reader is looking at where it is now.
            if (mark.leaving) return
            // The same position every draw pass uses, so what is visible
            // stays what is touchable.
            var screen = markScreen(mark)
            var distance = Math.hypot(px - screen.x, py - screen.y)
            if (distance >= bestDistance) return
            if (!insideRounded(px, py, screen.x, screen.y, w, h, corner)) return
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
        var askedFor = tagPathOf(state.segments)
        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", askedFor)
        form.append("scope", "keys")
        form.append("keys", key)
        form.append("fields", "full")
        return askService(form)
            .then(function (body) {
                delete bodyFetches[key]
                // An error is not an empty answer. Without this the branch
                // below would call a rejected request an inconsistency
                // between the manifest and the population.
                if (body.error) throw new Error(body.error)
                // This used to be dropped whenever the reader navigated
                // mid-flight, on the grounds that the tapped mark was gone
                // and the card would describe something no longer drawn. The
                // premise was wrong: a content keeps its key at every level,
                // which is why marksThisFrame() can match one across a
                // transition at all. What decides is whether the CONTENT is
                // still on the map -- the same question syncInfoCardToLevel
                // asks, so a tap and a level change cannot disagree about
                // whether a card is about something visible.
                //
                // The tagPath the answer was resolved in still matters, but
                // only for the membership badge, and forTagPath below carries
                // that. Title, summary and URL are the same wherever the
                // content is drawn.
                var here = state.nebula ? state.nebula.points : []
                var present = false
                for (var p = 0; p < here.length; p++) {
                    if (here[p].key === key) { present = true; break }
                }
                if (!present) return null
                var items = (body.items || []).filter(function (item) { return item.key === key })
                // Two items for one key means the 48-bit key collided; show
                // nothing rather than possibly the wrong article. That is
                // permanent. Zero items is not: see ensureBodies.
                if (items.length > 1) { bodyMisses[key] = true; return null }
                if (items.length === 0) {
                    bodyBackoff[key] = Math.min(BODY_RETRY_MAX_MS,
                        (bodyBackoff[key] || BODY_RETRY_MS / 2) * 2)
                    bodyRetryAt[key] = performance.now() + bodyBackoff[key]
                    console.warn("TagMap: key " + key + " is in the manifest of "
                        + (askedFor || "(root)") + " but not in its population")
                    return null
                }
                items[0].forTagPath = askedFor
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

    /**
     * The groups a pick may return: this level's groups, minus what the
     * boundary already represents.
     *
     * Not filtered by what was drawn, unlike pickSibling, and it does not
     * need to be. The disc this reaches a group through is at most
     * HIT_MIN_PX / 2 at its centre, so a group whose centre is off screen is
     * already out of reach -- you can only press where you are looking,
     * which is why adding a viewport test here was tried and changed
     * nothing. Compare pickSibling, whose polygon DOES reach across the
     * viewport and so has to be told.
     *
     * Measured over 36 zoom-and-position combinations: 2,104 answers, of
     * which 387 named a group whose CENTRE is off screen -- all of them
     * reached through the group's name, which drawLabels deliberately clamps
     * to the viewport edge for a group whose rim is still visible -- and 0
     * named a group with no part of its circle on screen.
     *
     * The one gap left is a group inside the viewport whose outline the
     * legibility cull skipped, and it is only ever a PRESS that reaches it.
     * Measured on the root at its fit scale, where 68 of the 106 groups on
     * screen are below NEBULA_MIN_SCREEN_R: a tap on their centres answered
     * "content" 55 times and "name" 13 times and named the group itself
     * zero times, because handleTap runs pickStar and pickLabel ahead of
     * pickCloudCentre. pointerdown does not -- it asks pickCloud first --
     * so all 68 are grabbable as drag sources. That is the intended
     * asymmetry: a drag is a GROUP gesture (contents are not drag sources),
     * and the contents inside the group are drawn, so it is not a press on
     * nothing. Zoom in one step and the gap is 0 of 57.
     *
     * Selected tags are drawn by drawContainer(), not as clouds, so they
     * must not be pickable either: an invisible disc at the focus centre
     * blocked panning inside your own selection and allowed A/A.
     */
    function pickableClouds(excludeTag) {
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
        return nodes
    }

    /**
     * PRESSING and DROPPING want opposite things, so they get separate
     * tests.
     *
     * Pressing must not reach past what is drawn -- a press on empty paper
     * has to pan. Dropping wants to be easy to land on, and reaching too far
     * costs nothing there because drawDrag rings the target and dragHint
     * names the action BEFORE the finger lifts, so the reader can correct.
     *
     * This is the generous one, and it is the circle the single old pickCloud
     * used for both jobs: `territoryRadius(reach)`, which is up to 2.1x the
     * radius of the outline actually drawn.
     */
    function pickDropTarget(px, py, excludeTag) {
        var world = screenToWorld(px, py)
        var nodes = pickableClouds(excludeTag)
        for (var i = 0; i < nodes.length; i++) {
            if (Math.hypot(world.x - nodes[i].x, world.y - nodes[i].y) <= nodes[i].r) return nodes[i]
        }
        return null
    }

    /**
     * A name drawn this frame, and what it names. The strictest possible
     * parity: the reader pressed the very pixels of a word.
     */
    function pickLabel(px, py, kind) {
        for (var i = 0; i < labelHits.length; i++) {
            var box = labelHits[i]
            if (kind && box.kind !== kind) continue
            if (px >= box.x && px <= box.x + box.w
                && py >= box.y && py <= box.y + box.h) return box
        }
        return null
    }

    /**
     * A small disc at a group's centre, as the fallback press target.
     *
     * Never larger than the group's own territory, so it cannot reach past
     * the group the way the old circle did. It yields to a content mark
     * where they overlap, and they overlap often: marks sit CONTENT_PITCH
     * apart, so below k = 22/2.6 = 8.46 their own HIT_MIN_PX targets tile the
     * group's interior with no gaps at all. That is why the centre alone is
     * not enough and the name is tried first.
     */
    function pickCloudCentre(px, py, excludeTag) {
        var world = screenToWorld(px, py)
        var reach = HIT_MIN_PX / 2 / camera.scale
        var nodes = pickableClouds(excludeTag)
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i]
            if (Math.hypot(world.x - node.x, world.y - node.y) <= Math.min(reach, node.r)) {
                return node
            }
        }
        return null
    }

    /** What a press on a group lands on: its name, or its centre. */
    function pickCloud(px, py) {
        var label = pickLabel(px, py, "cloud")
        if (label && state.universe[label.tag]) return state.universe[label.tag]
        return pickCloudCentre(px, py)
    }

    /**
     * Inside the selection, as the DROP side sees it: the circle, which is
     * what drawDrag highlights and is deliberately generous. Reaching past
     * the drawn hull is harmless here -- see pickDropTarget. Null at the
     * root, where there is nothing to narrow.
     */
    function pickContainer(px, py) {
        var container = containerRegion()
        if (!container) return null
        var world = screenToWorld(px, py)
        return Math.hypot(world.x - container.x, world.y - container.y) <= container.r
            ? container : null
    }

    /**
     * Inside the selection, as the PRESS side sees it: the hull that is
     * actually stroked. The circle is `levelR` while the hull dips to
     * HULL_FLOOR = 0.60 of its own peak, so pressing the empty paper in a
     * sparse direction used to page the contents.
     */
    function pickContainerDrawn(px, py) {
        if (state.segments.length === 0) return null
        var radii = containerRadii()
        if (!radii) return null
        var world = screenToWorld(px, py)
        return Layout.pointInPolygon([Layout.hullRing(radii, 0, 0)], world.x, world.y)
            ? containerRegion() : null
    }

    /**
     * One of an ancestor's remaining children -- a sibling of where you are.
     *
     * Tested as it is DRAWN: its name, then its own soft body, then a small
     * disc at its centre. ancestorChildShape decides both whether it is
     * drawn and where, so this cannot test a child the frame did not stroke.
     * It used to test every child of every ancestor unconditionally, which
     * is how a press on blank canvas grabbed a sibling that was not on
     * screen -- see ancestorChildShape for what that measured.
     *
     * Its circle `child.r` is the territory, up to 2.1x the body's radius,
     * which is why pressing well clear of a sibling used to grab it.
     */
    function pickSibling(px, py) {
        var named = pickLabel(px, py, "sibling")
        if (named && named.node) return named.node
        var world = screenToWorld(px, py)
        var reach = HIT_MIN_PX / 2 / camera.scale
        var best = null
        state.ancestors.forEach(function (ancestor) {
            ancestor.children.forEach(function (child) {
                var shape = ancestorChildShape(ancestor, child)
                if (!shape) return
                var inside = false
                if (shape.rings) {
                    // The rings live in the ancestor's own space; the draw
                    // scales them out, so the pick scales the point in --
                    // through the SAME number, so a bloom cannot leave the
                    // tested polygon behind the stroked one.
                    inside = Layout.pointInPolygon(child.rings,
                        (world.x - shape.rings.x) / shape.rings.scale,
                        (world.y - shape.rings.y) / shape.rings.scale)
                }
                if (!inside) {
                    inside = Math.hypot(world.x - child.x, world.y - child.y)
                        <= Math.min(reach, shape.worldR)
                }
                // Smallest first, so a small sibling beside a big one is
                // reachable.
                if (inside && (!best || child.r < best.r)) best = child
            })
        })
        return best
    }

    /**
     * The way out: a sibling to move to, or an ancestor's boundary to step
     * back through.
     */
    function pickAncestor(px, py) {
        var sibling = pickSibling(px, py)
        if (sibling) return { r: sibling.r, segments: sibling.segments, sibling: sibling }
        // Otherwise: the nearest ancestor boundary we are standing on. The
        // boundary is stroked as a HULL, so the band follows the hull rather
        // than the circle it used to be -- the two differ by up to 40%.
        var world = screenToWorld(px, py)
        for (var i = 0; i < state.ancestors.length; i++) {
            var ancestor = state.ancestors[i]
            var edge = ancestor.r
            if (ancestor.boundaryHull && ancestor.map) {
                var local = Layout.hullRadiusAt(ancestor.boundaryHull,
                    world.x - ancestor.map.x, world.y - ancestor.map.y)
                if (local !== null) edge = local * ancestor.map.scale
            }
            var here = Math.hypot(world.x - ancestor.x, world.y - ancestor.y)
            if (Math.abs(here - edge) * camera.scale <= RING_HIT) {
                return { r: ancestor.r, segments: ancestor.segments }
            }
        }
        return null
    }

    // ---- popup / info card ------------------------------------------------

    function openPopup(node) {
        closeInfoCard()
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
        // A SIBLING -- one of an ancestor's other children -- is not a child
        // of this level, so it is not in coTags and tagEntry() has nothing
        // for it. buildAncestors culls the two populations apart on purpose,
        // so this is by construction rather than by accident.
        var sibling = node.segments ? node : null
        // What entering it gives, which is what its circle's size already
        // states. For a child, coTags' `count` is the same promise measured
        // against the current set.
        var andCount = sibling
            ? (typeof sibling.reach === "number" ? sibling.reach : null)
            : (entry ? entry.count : null)

        var narrow = document.createElement("button")
        narrow.className = "tagmap-popup-action"
        // "Of the contents here, N carry this tag" -- a fact about the current
        // set. It is a lower bound on the next view, which also admits
        // similar-name matches; see the note under the header.
        narrow.textContent = "▶ " + localize("この中に入る", "Enter")
            + (andCount === null ? "" : " — " + localize("このうち ", "") + andCount + localize("件", ""))
        if (sibling) {
            // Sideways, not deeper: this is the level the sibling already
            // stands for, so its own depth cap applies, not ours.
            narrow.onclick = function () { closePopup(); navigate(sibling.segments) }
        } else if (atMaxDepth || andCount === 0 || isSelected) {
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

        // "View together" widens the segment you are IN, so it is only
        // offered where the union is between peers. A depth-1 sibling is a
        // peer of the current segment: at `/A/B/C`, sibling D of C makes
        // `/A/B/C,D`. A deeper one is not -- sibling E of B belongs beside
        // B, i.e. `/A/B,E/C`, which is not this segment -- so it is not
        // offered here. Dragging E into B's own frame is the way to say
        // that, and the frame names the segment unambiguously.
        var canJoin = sibling ? sibling.depth === 1 : true
        if (!atRoot && !isSelected && canJoin) {
            // A delta against orBase, not against the header total: both are
            // computed without similar-name matches, so the delta cannot go
            // negative. Comparing orCount with the header (which includes
            // them) made OR-adding a tag look like it shrank the set.
            var orBase = state.data && state.data.stats ? state.data.stats.orBase : null
            // Null for a sibling: `orCount` is measured per current level and
            // a sibling is absent from that payload by construction, so there
            // is no honest number to show. Substituting its own count would
            // promise a union it has not measured -- the same trap the header
            // note above warns about -- so the button goes without one.
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

    /**
     * Builds the card's contents from an item, read against the CURRENT
     * selection.
     *
     * Separate from openInfoCard because the card now outlives a level change
     * (see syncInfoCardToLevel). The DOM used to be built once and never
     * rebuilt, which was harmless only because every navigation closed the
     * card: the badge below froze the verdict it was opened with, and a card
     * that survives a level change would have gone on asserting it about a
     * level the reader had left. Everything here that reads state.segments
     * has to be re-derivable, and calling this again is the whole repair.
     */
    function renderInfoCard(item) {
        var card = elements.infoCard
        card.textContent = ""

        var title = document.createElement("div")
        title.className = "tagmap-card-title"
        title.textContent = item.title + (item.parentTitle ? " | " + item.parentTitle : "")
        card.appendChild(title)

        // Do not let a name match pass for a tag match -- and do not let a
        // verdict from another level pass for one about this level either.
        // `suggested` is only meaningful in the selection it was resolved
        // in, and bodies are deliberately cached across navigations, so a
        // body carried in from elsewhere says nothing here. Silence is the
        // honest answer: the badge exists to add a caveat, and an absent
        // caveat claims nothing, while a wrong one misstates authorship.
        if (item.suggested && item.forTagPath === tagPathOf(state.segments)) {
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
    }

    function openInfoCard(star) {
        // One overlay at a time: the popup is anchored to a tag and this to a
        // content, and two answers to one tap is one too many.
        closePopup()
        // The item is kept, not just the key: it is what the card is rebuilt
        // from when the level changes under it. It used to be written here
        // and never read again.
        infoTarget = { key: star.key, item: star.item }
        renderInfoCard(star.item)
        elements.infoCard.classList.add("visible")
    }

    /**
     * Carries the card across a level change when its content is still on the
     * map, and closes it when it is not.
     *
     * A content keeps its key at every level. That is not an assumption: it
     * is the fact marksThisFrame() matches an outgoing mark to an incoming
     * one by raw key equality on, which is what lets a content present at two
     * levels SLIDE instead of being replaced. applyData built that morph and
     * then, thirty-odd lines later, closed the card unconditionally and
     * without a word -- the same synchronous block asserting both that the
     * content survived and that the card about it did not.
     *
     * The test is about the CONTENT, not the route. A card describes a
     * content, so it stays open exactly as long as that content is something
     * the reader can still see; a breadcrumb leap and a zoom get the same
     * answer because the question is the same. drawInfoLinks already tolerates
     * the gap in between -- with no instance on screen this frame it drops
     * the lines and leaves the card alone.
     *
     * Rebuilt rather than left standing, because `suggested` is only
     * meaningful in the selection it was resolved in and the card is built
     * imperatively once. Re-rendering re-reads it, so the badge goes silent
     * on a carried-over body instead of misstating authorship.
     */
    function syncInfoCardToLevel() {
        if (!infoTarget) return
        var points = state.nebula ? state.nebula.points : []
        for (var i = 0; i < points.length; i++) {
            if (points[i].key !== infoTarget.key) continue
            if (infoTarget.item) renderInfoCard(infoTarget.item)
            return
        }
        closeInfoCard()
    }

    function closeInfoCard() {
        infoTarget = null
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
            // Capture is an optimisation -- it keeps the drag alive when the
            // pointer leaves the canvas -- and it throws if the id is not
            // active any more. Unguarded, that exception aborted the rest of
            // pointerdown, so the gesture never started at all: the whole
            // interaction was lost to a nicety.
            try {
                canvas.setPointerCapture(event.pointerId)
            } catch (error) {
                // A pointer that is already gone still gets its gesture.
            }
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
                // One gesture, one burst -- the wheel's contract, reached
                // without the wheel's guesswork: a wheel burst has to be
                // inferred from a 140 ms gap because macOS keeps sending
                // decaying events after the fingers lift, whereas a pinch
                // says when it begins and when it ends.
                //
                // Without a burst, `replace = !!zoomBurst` was always false
                // on touch, so every crossing pushed its own history entry --
                // going in and straight back out left two where the wheel
                // leaves none -- and ZOOM_MAX_PER_BURST never capped a chain.
                // The timer is cleared because a wheel burst still winding
                // down would otherwise fire and close this one mid-gesture.
                clearTimeout(wheelBurstTimer)
                endZoomBurst()
                zoomBurst = {
                    entryPath: tagPathOf(state.segments),
                    entryHref: buildHref(state.segments),
                    transitions: 0,
                    // The wheel uses this to segment a stream it cannot
                    // otherwise cut. Nothing has to guess where this gesture
                    // ends, and leaving it at 0 means a wheel arriving during
                    // a pinch is correctly treated as a different gesture.
                    endsAt: 0,
                }
                cameraTarget = null
                return
            }
            if (ids.length !== 1) return

            var px = event.clientX - canvasLeft()
            var py = event.clientY - canvasTop()
            // A sibling is a drag source too, so it can be pulled into the
            // selection. The current level's own children win, as everywhere
            // else: the level you are in beats the context behind it.
            var cloud = pickCloud(px, py)
            var sibling = cloud ? null : pickSibling(px, py)
            var held = cloud || sibling
            gesture = {
                mode: held ? "node" : "pan",
                tag: held ? held.tag : null,
                sibling: sibling || null,
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
                else zoomPush = 0   // the intent is not ours to clear; see applyZoom
                var mid = { x: (a.x + b.x) / 2 - canvasLeft(), y: (a.y + b.y) / 2 - canvasTop() }
                camera.scale = scale
                camera.x = gesture.anchor.x - (mid.x - cssW / 2) / scale
                camera.y = gesture.anchor.y - (mid.y - cssH / 2) / scale
                considerZoomTransition(mid.x, mid.y,
                    wanted > gesture.startScale ? 1 : (wanted < gesture.startScale ? -1 : 0))
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

            // What a drop means depends on what is being dragged.
            //
            //   a child of this level  onto another child = enter both
            //                          past the boundary = leave
            //   a SIBLING              inside the boundary = absorb it into
            //                          this segment, which is what the frame
            //                          you dropped it in says
            //
            // So: inward absorbs, outward leaves.
            if (distance > DRAG_START_PX) {
                gesture.moved = true
                var px = event.clientX - canvasLeft()
                var py = event.clientY - canvasTop()
                var target = null
                if (gesture.sibling) {
                    // Only the current selection's frame absorbs. An
                    // ancestor's frame would mean something else again, and
                    // is left out until it is asked for.
                    if (pickContainer(px, py)) target = { kind: "absorb" }
                } else {
                    // A sibling wins over the interior: children are packed
                    // well inside it, so testing the interior first made the
                    // ones near the centre impossible to drop onto.
                    var cloud = pickDropTarget(px, py, gesture.tag)
                    if (cloud) {
                        target = { kind: "cloud", tag: cloud.tag }
                    } else if (!pickContainer(px, py)) {
                        target = { kind: "out" }
                    }
                }
                drag = {
                    tag: gesture.tag, x: px, y: py, target: target,
                    sibling: gesture.sibling || null,
                }
            }
        })

        function endPointer(event) {
            var pointer = pointers[event.pointerId]
            delete pointers[event.pointerId]
            if (!gesture) { drag = null; return }

            if (gesture.mode === "pinch") {
                if (Object.keys(pointers).length < 2) {
                    gesture = null
                    // Fingers lifted: the gesture is over, so its burst is
                    // too. endZoomBurst() calls releaseZoom() -- the band
                    // springing back to the clamp -- and additionally settles
                    // the history the crossings replaced along the way.
                    endZoomBurst()
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
                // The frame it was dropped in names the segment that takes
                // it, so widenLevel needs no argument beyond the tag.
                else if (target && target.kind === "absorb") widenLevel(sourceTag)
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
        // An open POPUP still swallows the next tap: it is a menu, and a menu
        // has to be dismissed before anything behind it answers.
        if (popupTarget) {
            closePopup()
            return
        }
        // A name outranks a mark: pressing the word "OS" cannot plausibly
        // mean the article whose card happens to sit under it.
        var named = pickLabel(px, py)
        if (named && named.kind === "cloud" && state.universe[named.tag]) {
            openPopup(state.universe[named.tag])
            return
        }
        if (named && named.kind === "sibling" && named.node) {
            openPopup(named.node)
            return
        }
        var star = pickStar(px, py)
        if (star) {
            // A tap NEVER leaves the map. It used to, once the mark was big
            // enough to show an excerpt, on the reasoning that the card was
            // then saying what a detail card would. But that put an
            // unconfirmed, unundoable navigation under an ordinary tap on
            // the reading surface, and a 198x140 card holds five lines of a
            // summary, not the article. Leaving is the detail card's "open"
            // link and nothing else.
            //
            // Tapping a DIFFERENT content replaces what the card shows
            // rather than closing it, so reading through a level costs one
            // tap per content instead of two.
            if (infoTarget && infoTarget.key === star.key) {
                closeInfoCard()
                return
            }
            if (star.item) openInfoCard(star)
            else fetchOneBody(star.key).then(function (item) {
                if (item) openInfoCard({ key: star.key, item: item, mark: star.mark })
            })
            return
        }
        // Nothing content-like under the finger: an open card takes the tap
        // as "done".
        if (infoTarget) { closeInfoCard(); return }
        // The centre disc, AFTER the marks: where a mark sits on it the
        // mark is the more specific object, and the name above still works.
        var cloud = pickCloudCentre(px, py)
        if (cloud) { openPopup(cloud); return }
        // Inside the boundary but on none of them: page the contents.
        if (pickContainerDrawn(px, py)) {
            if (state.data && state.data.contents.hasMore) loadMore()
            return
        }
        // Outside it: the context behind, which is the way back out.
        var ancestor = pickAncestor(px, py)
        if (!ancestor) return
        // A sibling ASKS. Jumping straight to it threw away the segment the
        // reader was in -- `/Arduino/OS` became `/Arduino/Stack` with no
        // confirmation -- while the thing they usually want, seeing the two
        // together, had no gesture at all.
        if (ancestor.sibling) { openPopup(ancestor.sibling); return }
        navigate(ancestor.segments)
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
                // heldMs answers "why has this not committed yet": the dwell
                // is the only reason a pending transition waits.
                pending: zoomPending ? {
                    kind: zoomPending.kind,
                    tag: zoomPending.tag,
                    heldMs: Math.round(performance.now() - zoomPending.since),
                } : null,
                burst: zoomBurst ? zoomBurst.transitions : null,
                disabled: zoomDisabled,
            },
            // Why a content is or is not named, as numbers.
            labels: state.labels || null,
            // The group-name half of the same question `labels.anonymous`
            // asks about contents: of the groups that wanted a name this
            // frame, how many got one, and what turned the rest away.
            names: nameStats,
            // The connector endpoints drawn this frame: one per instance of
            // the content the detail card is showing. A test counts lines
            // here rather than a reader counting them in a screenshot.
            links: infoTarget ? { key: infoTarget.key, drawn: linkEnds.slice() } : null,
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
        // handleTap's own order, so a test asserts what a finger would do.
        hitTest: function (px, py) {
            var named = pickLabel(px, py)
            if (named && named.kind === "cloud" && state.universe[named.tag]) {
                return { kind: "name", of: state.universe[named.tag].kind, tag: named.tag }
            }
            if (named && named.kind === "sibling" && named.node) {
                return { kind: "name", of: "sibling", tag: named.tag }
            }
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
            var cloud = pickCloudCentre(px, py)
            if (cloud) return { kind: cloud.kind, tag: cloud.tag }
            if (pickContainerDrawn(px, py)) return { kind: "interior" }
            var ancestor = pickAncestor(px, py)
            if (ancestor) {
                return ancestor.sibling
                    ? { kind: "sibling", tag: ancestor.sibling.tag,
                        depth: ancestor.sibling.depth,
                        tagPath: tagPathOf(ancestor.segments) }
                    : { kind: "ancestor", tagPath: tagPathOf(ancestor.segments) }
            }
            return { kind: "empty" }
        },
        /** What a PRESS would start: a node gesture, or a pan. */
        pressTest: function (px, py) {
            var cloud = pickCloud(px, py)
            if (cloud) return { mode: "node", kind: cloud.kind, tag: cloud.tag }
            var sibling = pickSibling(px, py)
            if (sibling) return { mode: "node", kind: "sibling", tag: sibling.tag }
            return { mode: "pan" }
        },
        /** What a DROP there would do, for the given source tag. */
        dropTest: function (px, py, sourceTag, asSibling) {
            if (asSibling) return pickContainer(px, py) ? { kind: "absorb" } : { kind: null }
            var cloud = pickDropTarget(px, py, sourceTag)
            if (cloud) return { kind: "cloud", tag: cloud.tag }
            return pickContainer(px, py) ? { kind: null } : { kind: "out" }
        },
    }

    /**
     * The gate recovery's state, so a test can assert it instead of a person
     * inferring it from the network panel.
     *
     * `renewing` is what proves single-flight: three callers can get 428 in
     * one frame, and the only externally visible difference between one proof
     * and three is that the seed is requested once. `sweeping` is the
     * regression that matters most -- a 428 used to leave the body sweep's
     * mutex set forever, which was invisible because the page was going away.
     */
    TM.gate = function () {
        return {
            renewing: !!gateRenewal,
            failedAt: gateFailedAt,
            sweeping: bodySweepInFlight,
        }
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
