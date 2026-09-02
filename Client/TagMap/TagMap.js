;(function () {
    "use strict"

    /**
     * TagMap v2: planetary-exploration canvas for the tag map.
     *
     * The current selection is a "sun" at the center; co-occurring tags float
     * around it as planets (radius from AND drill-down count), the matching
     * contents orbit close as moons, lexically similar tags appear as dashed
     * "ghost planets" on an outer arc, and previously visited levels recede
     * into the background as tappable rings (the journey, spatialized).
     *
     * - Tap a planet -> mini popup: 「▶ この星へ飛ぶ (AND)」 / 「＋ 一緒に見る (OR)」.
     * - Drag = pan, wheel/pinch = zoom; zooming far out returns one level.
     * - Soft navigation via the History API; URL keeps the /:tagmap/A,B/C
     *   segment format (comma = OR, slash = AND). The journey trace lives in
     *   memory and is mirrored into history.state (no storage APIs).
     * - Data: Service/tagmap-service.php (v2 shape: coTags[{tag,count,orCount}],
     *   suggestedTags[{tag,count,orCount,score}]); first render uses the
     *   inline #tagmap-initial-state JSON emitted by Frontend/tag-viewer.php.
     * - Custom 2D canvas + requestAnimationFrame (no d3). NOTE: never use
     *   location.hash here (ContentsViewer.js has a global hashchange
     *   handler); hidden DOM list mirrors the scene for screen readers and
     *   deterministic E2E.
     */

    var TM = {}

    // ---- config -----------------------------------------------------------

    var MAX_DEPTH = 5
    var MAX_WIDTH = 5
    var PAGE_SIZE = 20
    var TAP_MOVE_PX = 8
    var TAP_MS = 400
    var GOLDEN_ANGLE = 2.39996
    var PLANET_R_MIN = 18
    var PLANET_R_MAX = 64
    var PLANET_PAD = 14
    var MOON_R = 12
    var GHOST_MAX = 8
    var LABEL_MAX = 60
    var LABEL_MIN_SCREEN_R = 14
    var RING_GAP = 220
    var RING_MAX = 4
    var SCALE_MIN = 0.35
    var SCALE_MAX = 6
    var ZOOM_BACK_SCALE = 0.5
    var SYSTEM_ROTATION = 0.004  // rad/s, whole system => geometry stays collision-free
    var TRANSITION_MS = 400

    // ---- state ------------------------------------------------------------

    var state = {
        serviceUri: null,
        contentPath: null,
        csrfToken: null,
        layer: "ja",
        segments: [],
        data: null,
        trace: [],
        loading: false,
    }

    var camera = { x: 0, y: 0, scale: 1 }
    var scene = null            // laid-out world (see layoutScene)
    var popupTarget = null      // {kind:'planet'|'ghost', node}
    var transition = null       // {startedAt, fromAlpha..} drill animation
    var zoomBackLatched = false
    var dirty = true
    var running = false
    var reducedMotion = window.matchMedia
        && window.matchMedia("(prefers-reduced-motion: reduce)").matches

    var elements = {}
    var ctx = null
    var dpr = 1
    var cssW = 0
    var cssH = 0
    var starfields = []         // [{canvas, parallax}]
    var abortController = null
    var loadingTimer = null

    // ---- small helpers ----------------------------------------------------

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

    function segmentsLabel(segments) {
        if (segments.length === 0) return "TagMap"
        return segments.map(function (s) { return s.join(", ") }).join(" / ")
    }

    function localize(ja, en) {
        var lang = document.documentElement.lang || "ja"
        return lang.indexOf("ja") === 0 ? ja : en
    }

    function readMeta(name) {
        var element = document.getElementsByName(name).item(0)
        return element ? element.content : null
    }

    // FNV-1a: stable per-tag "personality" seed for jitter/bob phases.
    function fnv1a(text) {
        var hash = 0x811c9dc5
        for (var i = 0; i < text.length; i++) {
            hash ^= text.charCodeAt(i)
            hash = (hash * 0x01000193) >>> 0
        }
        return hash
    }

    // Tiny deterministic PRNG (mulberry32) for the starfield.
    function prng(seed) {
        var a = seed >>> 0
        return function () {
            a |= 0; a = (a + 0x6d2b79f5) | 0
            var t = Math.imul(a ^ (a >>> 15), 1 | a)
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296
        }
    }

    function now() {
        return performance.now() / 1000
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
            bg: v("--tagmap-bg", "#f6f6f4"),
            star: v("--tagmap-star", "rgba(120,120,140,0.5)"),
            sun: v("--tagmap-sun", "#f0b429"),
            planet: v("--tagmap-planet", "rgba(52,152,219,0.35)"),
            planetStroke: v("--tagmap-planet-stroke", "rgba(41,128,185,0.8)"),
            moon: v("--tagmap-moon", "rgba(46,204,113,0.7)"),
            ghost: v("--tagmap-ghost", "rgba(155,89,182,0.5)"),
            ring: v("--tagmap-ring", "rgba(128,128,128,0.35)"),
            text: v("--tagmap-text", "#202122"),
        }
        return paletteCache
    }

    function onThemeChanged() {
        // ThemeChanger fires callbacks BEFORE it sets the `theme` attribute,
        // so re-read the palette one frame later.
        requestAnimationFrame(function () {
            paletteCache = null
            buildStarfields()
            markDirty()
        })
    }

    // ---- api --------------------------------------------------------------

    function fetchState(segments, offset, isRetry) {
        if (abortController) abortController.abort()
        abortController = new AbortController()

        var form = new FormData()
        form.append("contentPath", state.contentPath)
        form.append("tagPath", tagPathOf(segments))
        form.append("offset", offset || 0)
        form.append("limit", PAGE_SIZE)
        if (state.csrfToken) form.append("token", state.csrfToken)

        return fetch(state.serviceUri + "/tagmap-service.php", {
            method: "POST",
            body: form,
            signal: abortController.signal,
        }).then(function (response) {
            if (response.status === 428) {
                // Token expired or missing: re-enter the challenge flow.
                // The trace survives in history.state.
                location.reload()
                return new Promise(function () {}) // never resolves
            }
            return response.json()
        }).then(function (body) {
            if (body.error === "canonical_mismatch" && !isRetry) {
                return fetchState(parseTagPath(body.canonical), offset, true)
            }
            if (body.error) throw new Error(body.error)
            return body
        })
    }

    // ---- navigation -------------------------------------------------------

    function applyData(data, options) {
        state.segments = data.segments
        state.data = data
        if (!options.noTrace) {
            state.trace.push({
                segments: data.segments,
                label: segmentsLabel(data.segments),
                ts: Date.now(),
            })
        }
        var historyState = { segments: state.segments, trace: state.trace, data: data }
        if (options.replace) {
            history.replaceState(historyState, "", buildHref(state.segments))
        } else {
            history.pushState(historyState, "", buildHref(state.segments))
        }
        document.title = data.tagPath === "" ? "TagMap"
            : "TagMap: " + segmentsLabel(state.segments)
        closePopup()
        closeInfoCard()
        scene = layoutScene(data, state.trace)
        camera = { x: 0, y: 0, scale: 1 }
        zoomBackLatched = false
        renderBreadcrumb()
        rebuildSrList()
        updateNote()
        markDirty()
    }

    function navigate(segments, options) {
        options = options || {}
        segments = normalizeSegments(segments)
        setLoading(true)
        fetchState(segments, 0, false).then(function (data) {
            setLoading(false)
            if (transition) transition.pendingData = { data: data, options: options }
            else applyData(data, options)
        }).catch(function (error) {
            if (error.name === "AbortError") return
            setLoading(false)
            transition = null
            markDirty()
            showToast(localize("読み込みに失敗しました", "Failed to load") + " (" + error.message + ")", function () {
                navigate(segments, options)
            })
        })
    }

    function drillDown(node) {
        if (state.segments.length >= MAX_DEPTH) {
            showToast(localize("最大深度です", "Maximum depth reached"))
            return
        }
        startDrillTransition(node)
        navigate(state.segments.concat([[node.tag]]))
    }

    function orAdd(tag) {
        if (state.segments.length === 0) {
            navigate([[tag]])
            return
        }
        var last = state.segments[state.segments.length - 1]
        if (last.length >= MAX_WIDTH) {
            showToast(localize("このセグメントは満杯です", "This segment is full"))
            return
        }
        navigate(state.segments.slice(0, -1).concat([last.concat([tag])]))
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

    function goBackOneLevel() {
        // Rings hold entries BEFORE the current one; innermost = most recent.
        if (!scene || scene.rings.length === 0) return false
        navigate(scene.rings[0].segments)
        return true
    }

    function loadMore() {
        var contents = state.data.contents
        fetchState(state.segments, contents.offset + contents.limit, false).then(function (data) {
            state.data.contents.items = state.data.contents.items.concat(data.contents.items)
            state.data.contents.offset = data.contents.offset
            state.data.contents.hasMore = data.contents.hasMore
            history.replaceState(
                { segments: state.segments, trace: state.trace, data: state.data },
                "",
                buildHref(state.segments)
            )
            scene = layoutScene(state.data, state.trace)
            rebuildSrList()
            markDirty()
        }).catch(function (error) {
            if (error.name === "AbortError") return
            showToast(localize("読み込みに失敗しました", "Failed to load"))
        })
    }

    // ---- layout -----------------------------------------------------------

    /**
     * Deterministic world layout for one system. World origin = sun center.
     * Planets: golden-angle spiral with per-tag seeded jitter, pushed outward
     * until collision-free; the whole system rotates rigidly (SYSTEM_ROTATION)
     * so relative geometry — and therefore collision-freeness — is preserved.
     */
    function layoutScene(data, trace) {
        var totalContents = data.stats ? data.stats.totalContents : 0
        var sunR = Math.min(110, Math.max(60, 40 + 8 * Math.sqrt(totalContents)))

        var maxCount = 1
        data.coTags.forEach(function (t) { maxCount = Math.max(maxCount, t.count) })

        var planets = []
        data.coTags.forEach(function (t, i) {
            var seed = fnv1a(t.tag)
            var r = PLANET_R_MIN + (PLANET_R_MAX - PLANET_R_MIN) * Math.sqrt(t.count / maxCount)
            var angle = i * GOLDEN_ANGLE + ((seed % 1000) / 1000 - 0.5) * 0.3
            var dist = sunR + 140 + 40 * Math.sqrt(i)
            // Push outward until collision-free against already-placed planets.
            for (var guard = 0; guard < 400; guard++) {
                var x = Math.cos(angle) * dist
                var y = Math.sin(angle) * dist
                var collides = false
                for (var j = 0; j < planets.length; j++) {
                    var p = planets[j]
                    var dx = x - Math.cos(p.angle) * p.dist
                    var dy = y - Math.sin(p.angle) * p.dist
                    if (Math.hypot(dx, dy) < r + p.r + PLANET_PAD) { collides = true; break }
                }
                if (!collides) break
                dist += 12
            }
            planets.push({
                tag: t.tag, count: t.count, orCount: t.orCount,
                r: r, angle: angle, dist: dist, seed: seed, ghost: false,
            })
        })

        var maxDist = sunR + 140
        planets.forEach(function (p) { maxDist = Math.max(maxDist, p.dist + p.r) })

        // Ghost planets: lexically similar tags on a dedicated upper arc.
        var ghosts = []
        var coTagSet = {}
        data.coTags.forEach(function (t) { coTagSet[t.tag] = true })
        var suggested = (data.suggestedTags || [])
            .filter(function (t) { return !coTagSet[t.tag] })
            .slice(0, GHOST_MAX)
        var ghostDist = maxDist + 90
        suggested.forEach(function (t, i) {
            var spread = Math.min(1.2, 0.35 * Math.max(1, suggested.length - 1))
            var angle = -Math.PI / 2
                + (suggested.length > 1 ? (i / (suggested.length - 1) - 0.5) * spread : 0)
            ghosts.push({
                tag: t.tag, count: t.count, orCount: t.orCount, score: t.score,
                r: 22, angle: angle, dist: ghostDist, seed: fnv1a(t.tag), ghost: true,
            })
        })
        if (ghosts.length > 0) maxDist = ghostDist + 30

        // Moons: current contents on an inner orbit.
        var moons = []
        var items = data.contents.items
        var moonCount = items.length + (data.contents.hasMore ? 1 : 0)
        items.forEach(function (item, i) {
            moons.push({
                item: item, more: false,
                angle: (i / Math.max(1, moonCount)) * Math.PI * 2,
                dist: sunR + 70, r: MOON_R,
            })
        })
        if (data.contents.hasMore) {
            moons.push({
                item: null, more: true,
                remaining: data.contents.total - items.length,
                angle: ((moonCount - 1) / Math.max(1, moonCount)) * Math.PI * 2,
                dist: sunR + 70, r: MOON_R + 2,
            })
        }

        // Outer rings: up to RING_MAX previous trace entries (deduped by
        // tagPath, most recent occurrence wins), innermost = most recent.
        var rings = []
        var seen = {}
        var currentPath = tagPathOf(data.segments)
        for (var k = trace.length - 2; k >= 0 && rings.length < RING_MAX; k--) {
            var entry = trace[k]
            var path = tagPathOf(entry.segments)
            if (path === currentPath || seen[path]) continue
            seen[path] = true
            rings.push({
                segments: entry.segments, label: entry.label,
                radius: maxDist * 1.35 + rings.length * RING_GAP,
                seed: fnv1a(entry.label),
            })
        }

        return {
            sunR: sunR, planets: planets, ghosts: ghosts, moons: moons,
            rings: rings, fieldRadius: maxDist,
            sunLabel: data.segments.length === 0
                ? "TagMap"
                : data.segments[data.segments.length - 1].join(", "),
            totalContents: totalContents,
        }
    }

    // Per-frame polar position (rigid system rotation + tiny seeded bob).
    function nodePosition(node, t) {
        var angle = node.angle + (reducedMotion ? 0 : SYSTEM_ROTATION * t)
        var bobPhase = (node.seed % 628) / 100
        var dist = node.dist + (reducedMotion ? 0 : Math.sin(t * 0.7 + bobPhase) * 2)
        return { x: Math.cos(angle) * dist, y: Math.sin(angle) * dist }
    }

    // ---- camera / transforms ---------------------------------------------

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

    // ---- starfield --------------------------------------------------------

    function buildStarfields() {
        starfields = []
        if (cssW === 0 || cssH === 0) return
        var colors = palette()
        var layers = [
            { parallax: 0.1, count: 90, rMax: 1.2 },
            { parallax: 0.25, count: 60, rMax: 1.6 },
            { parallax: 0.5, count: 35, rMax: 2.2 },
        ]
        layers.forEach(function (layer, i) {
            var off = document.createElement("canvas")
            // Oversized so parallax panning never runs off the tile edge.
            off.width = Math.ceil(cssW * 1.5)
            off.height = Math.ceil(cssH * 1.5)
            var octx = off.getContext("2d")
            var random = prng(0xC0FFEE + i * 7919)
            octx.fillStyle = colors.star
            for (var s = 0; s < layer.count; s++) {
                var x = random() * off.width
                var y = random() * off.height
                var r = 0.4 + random() * layer.rMax
                octx.globalAlpha = 0.3 + random() * 0.7
                octx.beginPath()
                octx.arc(x, y, r, 0, Math.PI * 2)
                octx.fill()
            }
            starfields.push({ canvas: off, parallax: layer.parallax })
        })
    }

    // ---- render -----------------------------------------------------------

    function markDirty() {
        dirty = true
        ensureLoop()
    }

    function isAnimating() {
        return transition !== null || (!reducedMotion && scene !== null)
    }

    function ensureLoop() {
        if (running || document.hidden) return
        running = true
        requestAnimationFrame(frame)
    }

    function frame() {
        if (document.hidden) { running = false; return }
        try {
            if (dirty || isAnimating()) {
                dirty = false
                draw(now())
                positionPopup()
            }
        } catch (error) {
            // Never let a draw error kill the loop silently: log it, drop
            // the `running` latch so markDirty() can restart the loop.
            console.error("TagMap draw error:", error)
            running = false
            return
        }
        if (dirty || isAnimating()) {
            requestAnimationFrame(frame)
        } else {
            running = false
        }
    }

    function draw(t) {
        if (!ctx || !scene) return
        var colors = palette()

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.fillStyle = colors.bg
        ctx.fillRect(0, 0, cssW, cssH)

        // Starfield: parallax against the camera, wrapped by tile offset.
        starfields.forEach(function (layer) {
            var ox = (-camera.x * camera.scale * layer.parallax) % layer.canvas.width
            var oy = (-camera.y * camera.scale * layer.parallax) % layer.canvas.height
            var drift = reducedMotion ? 0 : (t * 2 * layer.parallax) % layer.canvas.width
            var startX = ((ox + drift) % layer.canvas.width) - layer.canvas.width
            var startY = (oy % layer.canvas.height) - layer.canvas.height
            for (var x = startX; x < cssW; x += layer.canvas.width) {
                for (var y = startY; y < cssH; y += layer.canvas.height) {
                    ctx.drawImage(layer.canvas, x, y)
                }
            }
        })

        var alpha = 1
        var zoomBoost = 1
        if (transition) {
            var progress = Math.min(1, (performance.now() - transition.startedAt) / TRANSITION_MS)
            var eased = easeInOutCubic(progress)
            if (transition.phase === "out") {
                alpha = 1 - eased
                // Fly toward the tapped planet.
                camera.x = transition.from.x + (transition.target.x - transition.from.x) * eased
                camera.y = transition.from.y + (transition.target.y - transition.from.y) * eased
                camera.scale = transition.from.scale * (1 + eased)
                if (progress >= 1) {
                    if (transition.pendingData) {
                        var pending = transition.pendingData
                        transition = { phase: "in", startedAt: performance.now() }
                        applyData(pending.data, pending.options)
                    }
                    // else: keep holding at the end state until the fetch lands.
                }
            } else { // "in"
                alpha = eased
                zoomBoost = 0.8 + 0.2 * eased
                if (progress >= 1) transition = null
            }
        }

        ctx.save()
        ctx.translate(cssW / 2, cssH / 2)
        ctx.scale(camera.scale * zoomBoost, camera.scale * zoomBoost)
        ctx.translate(-camera.x, -camera.y)
        ctx.globalAlpha = alpha

        drawRings(t, colors)
        drawPlanets(t, colors)
        drawSun(colors)
        drawMoons(t, colors)

        ctx.restore()
        ctx.globalAlpha = 1
    }

    function drawRings(t, colors) {
        // Rings sit "behind": parallax factor via a slightly reduced offset.
        scene.rings.forEach(function (ring, k) {
            ctx.save()
            ctx.translate(camera.x * 0.15, camera.y * 0.15) // parallax 0.85
            ctx.strokeStyle = colors.ring
            ctx.globalAlpha *= Math.max(0.15, 0.5 - k * 0.1)
            ctx.lineWidth = 2 / camera.scale
            ctx.beginPath()
            ctx.arc(0, 0, ring.radius, 0, Math.PI * 2)
            ctx.stroke()

            // Seeded silhouette dots along the ring.
            var random = prng(ring.seed)
            ctx.fillStyle = colors.ring
            for (var d = 0; d < 12; d++) {
                var angle = random() * Math.PI * 2
                var r = 2 + random() * 3
                ctx.beginPath()
                ctx.arc(Math.cos(angle) * ring.radius, Math.sin(angle) * ring.radius, r, 0, Math.PI * 2)
                ctx.fill()
            }

            // Label on the upper arc.
            var fontSize = Math.max(12, 14 / camera.scale)
            ctx.fillStyle = colors.text
            ctx.globalAlpha *= 1.6
            ctx.font = fontSize + "px system-ui, sans-serif"
            ctx.textAlign = "center"
            ctx.fillText(ring.label, 0, -ring.radius - 10 / camera.scale)
            ctx.restore()
        })
    }

    function drawPlanets(t, colors) {
        var labeled = 0
        var atMaxDepth = state.segments.length >= MAX_DEPTH

        scene.planets.concat(scene.ghosts).forEach(function (node) {
            var pos = nodePosition(node, t)
            var screen = worldToScreen(pos.x, pos.y)
            var screenR = node.r * camera.scale
            if (screen.x < -screenR || screen.x > cssW + screenR
                || screen.y < -screenR || screen.y > cssH + screenR) return // cull

            ctx.save()
            ctx.translate(pos.x, pos.y)
            if (node.ghost) {
                var shimmer = reducedMotion ? 0.55
                    : 0.4 + 0.25 * Math.sin(t * 1.3 + (node.seed % 100))
                ctx.globalAlpha *= shimmer
                ctx.setLineDash([6, 5])
                ctx.fillStyle = colors.ghost
                ctx.strokeStyle = colors.ghost
            } else {
                if (atMaxDepth) ctx.globalAlpha *= 0.55
                ctx.fillStyle = colors.planet
                ctx.strokeStyle = colors.planetStroke
            }
            ctx.lineWidth = 1.5
            ctx.beginPath()
            ctx.arc(0, 0, node.r, 0, Math.PI * 2)
            ctx.fill()
            ctx.stroke()
            ctx.setLineDash([])

            // Labels: only for reasonably large on-screen planets, capped.
            if (screenR > LABEL_MIN_SCREEN_R && labeled < LABEL_MAX) {
                labeled++
                ctx.fillStyle = colors.text
                ctx.textAlign = "center"
                var nameSize = Math.max(9, node.r / 3.2)
                ctx.font = nameSize + "px system-ui, sans-serif"
                ctx.fillText(ellipsize(node.tag, node.r * 2.2, ctx), 0, -2)
                ctx.font = Math.max(8, node.r / 4) + "px system-ui, sans-serif"
                ctx.globalAlpha *= 0.65
                ctx.fillText(String(node.count), 0, nameSize)
            }
            ctx.restore()
        })

        // Ghost arc label.
        if (scene.ghosts.length > 0) {
            var first = nodePosition(scene.ghosts[0], t)
            ctx.save()
            ctx.fillStyle = colors.text
            ctx.globalAlpha *= 0.5
            ctx.font = "12px system-ui, sans-serif"
            ctx.textAlign = "center"
            ctx.fillText(localize("もしかして", "Did you mean"), 0, first.y - 40)
            ctx.restore()
        }
    }

    function drawSun(colors) {
        ctx.save()
        ctx.fillStyle = colors.sun
        ctx.beginPath()
        ctx.arc(0, 0, scene.sunR, 0, Math.PI * 2)
        ctx.fill()

        ctx.fillStyle = colors.text
        ctx.textAlign = "center"
        var size = Math.max(12, scene.sunR / 4)
        ctx.font = "bold " + size + "px system-ui, sans-serif"
        ctx.fillText(ellipsize(scene.sunLabel, scene.sunR * 1.8, ctx), 0, 0)
        if (state.segments.length > 0) {
            ctx.font = Math.max(10, scene.sunR / 6) + "px system-ui, sans-serif"
            ctx.globalAlpha = 0.75
            ctx.fillText(scene.totalContents + localize("件", " items"), 0, size + 4)
        }
        if (state.segments.length > 0 && scene.planets.length === 0 && scene.ghosts.length === 0) {
            ctx.globalAlpha = 0.6
            ctx.font = "12px system-ui, sans-serif"
            ctx.fillText(
                localize("これ以上の分岐はありません", "No further branches"),
                0, scene.sunR + 24
            )
        }
        ctx.restore()
    }

    function drawMoons(t, colors) {
        scene.moons.forEach(function (moon) {
            var angle = moon.angle + (reducedMotion ? 0 : t * 0.05)
            var x = Math.cos(angle) * moon.dist
            var y = Math.sin(angle) * moon.dist
            ctx.save()
            ctx.translate(x, y)
            ctx.fillStyle = colors.moon
            ctx.beginPath()
            ctx.arc(0, 0, moon.r, 0, Math.PI * 2)
            ctx.fill()
            if (moon.more) {
                ctx.fillStyle = colors.text
                ctx.textAlign = "center"
                ctx.font = "bold 10px system-ui, sans-serif"
                ctx.fillText("+" + moon.remaining, 0, 3)
            }
            ctx.restore()
        })
    }

    function ellipsize(text, maxWidth, context) {
        if (context.measureText(text).width <= maxWidth) return text
        var result = text
        while (result.length > 1 && context.measureText(result + "…").width > maxWidth) {
            result = result.slice(0, -1)
        }
        return result + "…"
    }

    function easeInOutCubic(x) {
        return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2
    }

    // ---- transition -------------------------------------------------------

    function startDrillTransition(node) {
        if (reducedMotion) return
        var pos = nodePosition(node, now())
        transition = {
            phase: "out",
            startedAt: performance.now(),
            from: { x: camera.x, y: camera.y, scale: camera.scale },
            target: { x: pos.x, y: pos.y },
            pendingData: null,
        }
        markDirty()
    }

    // ---- hit testing ------------------------------------------------------

    function pick(px, py) {
        var world = screenToWorld(px, py)
        var t = now()
        var i, node, pos, hitR

        // Moons first (they sit innermost and are small).
        for (i = 0; i < scene.moons.length; i++) {
            var moon = scene.moons[i]
            var angle = moon.angle + (reducedMotion ? 0 : t * 0.05)
            pos = { x: Math.cos(angle) * moon.dist, y: Math.sin(angle) * moon.dist }
            hitR = Math.max(moon.r, 20 / camera.scale)
            if (Math.hypot(world.x - pos.x, world.y - pos.y) <= hitR) {
                return { kind: "moon", node: moon }
            }
        }
        // Planets and ghosts.
        var nodes = scene.planets.concat(scene.ghosts)
        for (i = 0; i < nodes.length; i++) {
            node = nodes[i]
            pos = nodePosition(node, t)
            hitR = Math.max(node.r, 20 / camera.scale)
            if (Math.hypot(world.x - pos.x, world.y - pos.y) <= hitR) {
                return { kind: node.ghost ? "ghost" : "planet", node: node }
            }
        }
        // Sun (no-op target, blocks ring hits underneath).
        if (Math.hypot(world.x, world.y) <= scene.sunR) return { kind: "sun" }
        // Rings (band around the circle; account for their parallax offset).
        for (i = 0; i < scene.rings.length; i++) {
            var ring = scene.rings[i]
            var rx = world.x - camera.x * 0.15
            var ry = world.y - camera.y * 0.15
            var distance = Math.hypot(rx, ry)
            if (Math.abs(distance - ring.radius) <= Math.max(24, 24 / camera.scale)) {
                return { kind: "ring", node: ring }
            }
        }
        return null
    }

    // ---- popup / info card ------------------------------------------------

    function openPopup(kind, node) {
        popupTarget = { kind: kind, node: node }
        var popup = elements.popup
        popup.textContent = ""

        var title = document.createElement("div")
        title.className = "tagmap-popup-title"
        title.textContent = node.tag
        popup.appendChild(title)

        var atRoot = state.segments.length === 0
        var atMaxDepth = state.segments.length >= MAX_DEPTH
        var last = state.segments[state.segments.length - 1]
        var atMaxWidth = last ? last.length >= MAX_WIDTH : false

        var fly = document.createElement("button")
        fly.className = "tagmap-popup-action"
        fly.textContent = "▶ " + localize("この星へ飛ぶ", "Fly to this star")
            + " — " + node.count + localize("件", "")
        if (atMaxDepth || node.count === 0) {
            fly.disabled = true
            fly.title = atMaxDepth
                ? localize("最大深度です", "Maximum depth reached")
                : localize("共通のコンテンツがありません", "No shared contents")
        } else {
            fly.onclick = function () { closePopup(); drillDown(node) }
        }
        popup.appendChild(fly)

        if (!atRoot) {
            var join = document.createElement("button")
            join.className = "tagmap-popup-action"
            join.textContent = "＋ " + localize("一緒に見る", "View together")
                + " — " + node.orCount + localize("件", "")
            if (atMaxWidth) {
                join.disabled = true
                join.title = localize("このセグメントは満杯です", "This segment is full")
            } else {
                join.onclick = function () { closePopup(); orAdd(node.tag) }
            }
            popup.appendChild(join)
        }

        popup.classList.add("visible")
        positionPopup()
        markDirty()
    }

    function positionPopup() {
        if (!popupTarget) return
        var popup = elements.popup
        var pos = nodePosition(popupTarget.node, now())
        var screen = worldToScreen(pos.x, pos.y)
        var below = screen.y + popupTarget.node.r * camera.scale + 10
        var width = popup.offsetWidth || 180
        var height = popup.offsetHeight || 90
        var x = Math.min(Math.max(8, screen.x - width / 2), cssW - width - 8)
        var y = below + height > cssH - 8
            ? screen.y - popupTarget.node.r * camera.scale - height - 10
            : below
        popup.style.transform = "translate(" + Math.round(x) + "px," + Math.round(y) + "px)"
    }

    function closePopup() {
        popupTarget = null
        elements.popup.classList.remove("visible")
    }

    function openInfoCard(moon) {
        if (moon.more) { loadMore(); return }
        var item = moon.item
        var card = elements.infoCard
        card.textContent = ""

        var title = document.createElement("div")
        title.className = "tagmap-card-title"
        title.textContent = item.title + (item.parentTitle ? " | " + item.parentTitle : "")
        card.appendChild(title)

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
        elements.infoCard.classList.remove("visible")
    }

    // ---- gestures ---------------------------------------------------------

    var pointers = {}
    var gesture = null // {mode:'pan'|'pinch', ...}

    function setupGestures() {
        var canvas = elements.canvas

        canvas.addEventListener("pointerdown", function (event) {
            canvas.setPointerCapture(event.pointerId)
            pointers[event.pointerId] = { x: event.clientX, y: event.clientY }
            var ids = Object.keys(pointers)
            if (ids.length === 1) {
                gesture = {
                    mode: "pan",
                    startX: event.clientX, startY: event.clientY,
                    startedAt: performance.now(),
                    camX: camera.x, camY: camera.y,
                    moved: false,
                }
            } else if (ids.length === 2) {
                var a = pointers[ids[0]], b = pointers[ids[1]]
                gesture = {
                    mode: "pinch",
                    startDist: Math.hypot(a.x - b.x, a.y - b.y),
                    startScale: camera.scale,
                    anchor: screenToWorld((a.x + b.x) / 2 - canvasLeft(), (a.y + b.y) / 2 - canvasTop()),
                }
            }
        })

        canvas.addEventListener("pointermove", function (event) {
            var pointer = pointers[event.pointerId]
            if (!pointer || !gesture) return
            pointer.x = event.clientX
            pointer.y = event.clientY
            if (gesture.mode === "pan") {
                var dx = event.clientX - gesture.startX
                var dy = event.clientY - gesture.startY
                if (Math.hypot(dx, dy) > TAP_MOVE_PX) gesture.moved = true
                camera.x = gesture.camX - dx / camera.scale
                camera.y = gesture.camY - dy / camera.scale
                markDirty()
            } else if (gesture.mode === "pinch") {
                var ids = Object.keys(pointers)
                if (ids.length < 2) return
                var a = pointers[ids[0]], b = pointers[ids[1]]
                var dist = Math.hypot(a.x - b.x, a.y - b.y)
                var scale = clampScale(gesture.startScale * dist / gesture.startDist)
                var mid = { x: (a.x + b.x) / 2 - canvasLeft(), y: (a.y + b.y) / 2 - canvasTop() }
                camera.scale = scale
                camera.x = gesture.anchor.x - (mid.x - cssW / 2) / scale
                camera.y = gesture.anchor.y - (mid.y - cssH / 2) / scale
                markDirty()
            }
        })

        function endPointer(event) {
            var pointer = pointers[event.pointerId]
            delete pointers[event.pointerId]
            if (!gesture) return
            if (gesture.mode === "pan" && pointer) {
                var quick = performance.now() - gesture.startedAt < TAP_MS
                if (!gesture.moved && quick) {
                    handleTap(event.clientX - canvasLeft(), event.clientY - canvasTop())
                }
                gesture = null
            } else if (gesture.mode === "pinch" && Object.keys(pointers).length < 2) {
                gesture = null
                maybeZoomBack()
            }
        }
        canvas.addEventListener("pointerup", endPointer)
        canvas.addEventListener("pointercancel", endPointer)

        var wheelTimer = null
        canvas.addEventListener("wheel", function (event) {
            event.preventDefault()
            var factor = Math.exp(-event.deltaY * 0.0012)
            var anchor = screenToWorld(event.clientX - canvasLeft(), event.clientY - canvasTop())
            camera.scale = clampScale(camera.scale * factor)
            camera.x = anchor.x - (event.clientX - canvasLeft() - cssW / 2) / camera.scale
            camera.y = anchor.y - (event.clientY - canvasTop() - cssH / 2) / camera.scale
            markDirty()
            clearTimeout(wheelTimer)
            wheelTimer = setTimeout(maybeZoomBack, 250)
        }, { passive: false })

        // iOS Safari: block page pinch-zoom while over the canvas only.
        canvas.addEventListener("gesturestart", function (event) { event.preventDefault() })
    }

    function canvasLeft() { return elements.canvasRect ? elements.canvasRect.left : 0 }
    function canvasTop() { return elements.canvasRect ? elements.canvasRect.top : 0 }

    function clampScale(scale) {
        return Math.min(SCALE_MAX, Math.max(SCALE_MIN, scale))
    }

    function maybeZoomBack() {
        if (camera.scale >= ZOOM_BACK_SCALE) { zoomBackLatched = false; return }
        if (zoomBackLatched) return
        zoomBackLatched = true
        if (!goBackOneLevel()) zoomBackLatched = false
    }

    function handleTap(px, py) {
        if (popupTarget || elements.infoCard.classList.contains("visible")) {
            // A tap while a popup/card is open only closes it.
            closePopup()
            closeInfoCard()
            markDirty()
            return
        }
        var hit = pick(px, py)
        if (!hit) return
        if (hit.kind === "planet" || hit.kind === "ghost") openPopup(hit.kind, hit.node)
        else if (hit.kind === "moon") openInfoCard(hit.node)
        else if (hit.kind === "ring") navigate(hit.node.segments)
    }

    // ---- chrome -----------------------------------------------------------

    function setLoading(loading) {
        state.loading = loading
        clearTimeout(loadingTimer)
        if (loading) {
            loadingTimer = setTimeout(function () {
                elements.app.classList.add("loading")
            }, 150)
        } else {
            elements.app.classList.remove("loading")
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
            state.data.chipTags.forEach(function (c) { chipCounts[c.tag] = c.count })
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

    // Hidden mirror of the scene: screen-reader access + deterministic E2E.
    function rebuildSrList() {
        var list = elements.srList
        list.textContent = ""
        if (!state.data) return
        state.data.coTags.forEach(function (t) {
            var item = document.createElement("div")
            item.setAttribute("role", "listitem")
            var fly = document.createElement("button")
            fly.textContent = t.tag + " " + localize("へ飛ぶ", "fly") + " (" + t.count + ")"
            fly.setAttribute("data-tagmap-action", "and")
            fly.setAttribute("data-tag", t.tag)
            fly.onclick = function () {
                drillDown({ tag: t.tag, count: t.count, orCount: t.orCount, angle: 0, dist: 0, r: 0, seed: 0 })
            }
            item.appendChild(fly)
            var join = document.createElement("button")
            join.textContent = t.tag + " " + localize("を一緒に見る", "view together") + " (" + t.orCount + ")"
            join.setAttribute("data-tagmap-action", "or")
            join.setAttribute("data-tag", t.tag)
            join.onclick = function () { orAdd(t.tag) }
            item.appendChild(join)
            list.appendChild(item)
        })
        state.data.contents.items.forEach(function (item) {
            var row = document.createElement("div")
            row.setAttribute("role", "listitem")
            var link = document.createElement("a")
            link.href = item.url
            link.textContent = item.title
            row.appendChild(link)
            list.appendChild(row)
        })
    }

    function buildChrome() {
        var app = elements.app
        app.textContent = ""

        elements.canvas = document.createElement("canvas")
        elements.canvas.className = "tagmap-canvas"
        elements.canvas.setAttribute("role", "img")
        elements.canvas.setAttribute("aria-label",
            localize("タグの宇宙マップ", "Tag universe map"))
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

        var spinner = document.createElement("div")
        spinner.className = "tagmap-spinner"
        app.appendChild(spinner)

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
        buildStarfields()
        markDirty()
    }

    function updateNote() {
        if (!state.data) return
        elements.note.textContent = state.data.totalCoTags > state.data.coTags.length
            ? localize("上位", "Top ") + state.data.coTags.length + " / " + state.data.totalCoTags
            : ""
    }

    // ---- bootstrap --------------------------------------------------------

    function isV2Data(data) {
        return data && data.coTags
            && (data.coTags.length === 0 || data.coTags[0].orCount !== undefined)
    }

    function currentUrlSegments() {
        var index = location.pathname.toLowerCase().indexOf("/:tagmap")
        var raw = index >= 0
            ? decodeURIComponent(location.pathname.slice(index + "/:tagmap".length))
            : ""
        return parseTagPath(raw)
    }

    function init() {
        elements.app = document.getElementById("tagmap-app")
        if (!elements.app) return

        state.serviceUri = readMeta("service-uri")
        state.contentPath = readMeta("content-path")
        state.csrfToken = readMeta("token")

        var layerMatch = location.search.match(/[?&]layer=([^&]+)/)
        if (layerMatch) state.layer = decodeURIComponent(layerMatch[1])

        var initial = null
        var inline = document.getElementById("tagmap-initial-state")
        if (inline) {
            try { initial = JSON.parse(inline.textContent) } catch (error) { /* fall through */ }
        }
        // Returning to this document (e.g. back from a content page): prefer
        // the state the browser kept, trace included — unless it is from the
        // v1 client (no orCount), in which case refetch by URL.
        if (history.state && history.state.data) {
            if (isV2Data(history.state.data)) {
                initial = history.state.data
                state.trace = history.state.trace || []
            } else {
                initial = null
            }
        }

        buildChrome()

        if (!initial || !isV2Data(initial)) {
            navigate(currentUrlSegments(), { replace: true })
        } else {
            state.layer = initial.layer || state.layer
            if (state.trace.length === 0) {
                state.trace = [{
                    segments: initial.segments,
                    label: segmentsLabel(initial.segments),
                    ts: Date.now(),
                }]
            }
            applyData(initial, { replace: true, noTrace: true })
        }
        updateNote()

        window.addEventListener("popstate", function (event) {
            transition = null
            if (event.state && event.state.data && isV2Data(event.state.data)) {
                state.trace = event.state.trace || state.trace
                applyData(event.state.data, { replace: true, noTrace: true })
                updateNote()
            } else {
                navigate(currentUrlSegments(), { replace: true, noTrace: true })
            }
        })

        var resizeTimer = null
        window.addEventListener("resize", function () {
            clearTimeout(resizeTimer)
            resizeTimer = setTimeout(function () {
                transition = null
                resizeCanvas()
            }, 200)
        })

        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) ensureLoop()
        })

        window.addEventListener("keydown", function (event) {
            if (event.key === "Escape") { closePopup(); closeInfoCard(); markDirty() }
        })

        if (window.ThemeChanger && ThemeChanger.onChangeThemeCallbacks) {
            ThemeChanger.onChangeThemeCallbacks.push(onThemeChanged)
        }

        ensureLoop()
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init)
    } else {
        init()
    }

    TM.state = state
    window.TagMap = TM
})()
