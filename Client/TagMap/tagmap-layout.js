/*
 * TagMap layout: the pure geometry of the nested-circle model.
 *
 * The selection path IS the nesting. `/Library/C#` means "the root field
 * contains Library's circle, which contains C#'s circle", and a group's
 * position is always a territory inside its parent. That makes "the circle
 * you click is the circle you enter" a property of the model rather than
 * behaviour to maintain.
 *
 * Everything here is a pure function of payload data. No coordinates are
 * ever sent by the server (layout is a presentation concern), no randomness
 * and no clock, so the same tag path yields the same picture for every
 * visitor and a revisit reproduces it exactly. That also lets this file be
 * required from Node and checked mechanically -- determinism, non-overlap
 * and depth-5 precision are exactly the properties you cannot eyeball.
 *
 * Coordinates are ABSOLUTE: one content radius is the world unit, at every
 * depth. A region's size therefore states a content count that means the
 * same thing everywhere on the map, and the zoom range is finite -- it grows
 * only as sqrt(N). The older model renormalised the current level to radius
 * 1 on every step, which made depth invisible and the zoom unbounded.
 *
 * A content belonging to several groups is DRAWN IN EACH of them. That is
 * the one place this file trades a property away, and it is deliberate: a
 * single mark per content has to sit between its groups, which stretches
 * both outlines toward it, and with a hundred groups every outline crosses
 * every other. Measured on the reference corpus, all 5565 outline pairs
 * crossed; duplicating the mark brings that to zero. Identity lives in the
 * `key` instead of in the geometry, so the instances of one content can be
 * tied together when the reader asks rather than always.
 */
;(function (root, factory) {
    if (typeof module === "object" && module.exports) module.exports = factory()
    else root.TagMapLayout = factory()
})(typeof self !== "undefined" ? self : this, function () {
    "use strict"

    var TAU = Math.PI * 2
    var GOLDEN_ANGLE = 2.399963229728653

    // Children are packed within this share of the parent radius; the ring
    // outside it is the free band where direct contents sit.
    var INNER_FILL = 0.78
    var BAND_RINGS = [0.815, 0.885, 0.95]
    var FUSE_PAD = 1.04
    // Contents shown inside a child group stay within this share of it.
    var INSIDE_FILL = 0.66

    // --- density field ---
    // Isolevel of the contour. 0.5 is the natural midpoint of the bounded
    // union, and kernelRadius() is derived from it so that a lone point
    // reproduces the circle exactly.
    var FIELD_T = 0.5
    // Kernel radius as a multiple of the points' own spacing. Derived, not
    // tuned: at 0.50 two contents one CONTENT_PITCH apart draw a single ring
    // and two at 1.4 pitches draw two, so "these read as one body" means
    // exactly "these sit at the packing density".
    var KERNEL_SPACING = 0.50
    // Points sampled when sizing a kernel from a group's actual spread. The
    // mean-nearest-neighbour scan is O(m²): at the manifest cap of 4000 rows
    // in one group that is 16M distance computations. Sampling by index keeps
    // it deterministic.
    var KERNEL_SAMPLE_MAX = 400

    // --- the absolute scale ---
    // One content radius IS the world unit, so nothing is renormalised per
    // level and the zoom range is finite: it grows only as sqrt(N).
    var CONTENT_R = 1
    // Centre-to-centre minimum between two contents: a 0.6 R gutter.
    var CONTENT_PITCH = 2.6
    // Radius per sqrt(content). PACK_K = (CONTENT_PITCH/2)/sqrt(fill) with a
    // fill of 0.25, which lands exactly on the pitch -- so a region holding n
    // contents is sqrt(n) pitches across. Measured on the reference corpus:
    // at a fill of 0.40 six times as many content discs actually overlapped.
    var PACK_K = CONTENT_PITCH

    // --- the card a content grows into ---
    //
    // A content's title and summary go INSIDE its own mark, not beside it.
    // Beside it they collide, and the collision test is both expensive and
    // arbitrary: measured on /Arduino, 70-77 marks had a title to show and
    // only 7-12 got the space, chosen by nothing but distance from the
    // screen centre. Inside the mark, collision is impossible -- placeMarks
    // guarantees two marks are at least CONTENT_PITCH apart, which
    // placement.test.js already checks.
    //
    // Two axis-aligned w x h rectangles whose centres are `d` apart at angle
    // t miss each other when d|cos t| >= w OR d|sin t| >= h. Holding for
    // EVERY t requires the DIAGONAL to fit: sqrt(w^2 + h^2) <= d. Imposing
    // the silver ratio w/h = sqrt(2) then fixes both sides.
    //
    // So the card is derived from CONTENT_PITCH and introduces no new
    // constant of its own. The largest card the REAL data would allow is 11%
    // bigger (2.40 x 1.70, diagonal 2.94), but only because no real pair sits
    // at the worst angle of 35.3 degrees -- that is an accident of the
    // corpus, not a guarantee, so the derived size is used instead.
    var CARD_ASPECT = Math.SQRT2
    var CARD_H = CONTENT_PITCH / Math.sqrt(1 + CARD_ASPECT * CARD_ASPECT)
    var CARD_W = CARD_H * CARD_ASPECT

    // Landscape rather than portrait, decided by measurement: at a card
    // height of 140 px, landscape gives 16 CJK characters per line and
    // portrait gives 7. Seven is not a Japanese title.

    // Type inside the card, in CSS px. Fixed sizes: text never scales with
    // the camera, which is what stopped the old thresholds feeling arbitrary.
    var CARD_PAD = 8
    var CARD_TITLE_PX = 12
    var CARD_BODY_PX = 11
    var CARD_LINE_PX = 15
    // Height at which the card holds a full excerpt -- two title lines, the
    // parent, and five of summary. The zoom ceiling is derived from this.
    var CARD_FULL_PX = 140

    var GRID_N = 25             // samples per axis over the group's own space
    var EPSILON = 1e-9

    // ---- the absolute scale ------------------------------------------------

    /**
     * The radius a group occupies, from what entering it yields.
     *
     * Sized by `reach` (the next view's content count), never by `count`
     * (this view's). They differ for 11% of groups on the reference corpus
     * and by up to 4x, so a circle drawn from `count` is not the size of the
     * view behind it.
     *
     * The circle therefore states exactly the number its label states. It is
     * NOT the size the level behind it will be: that level draws a mark per
     * (content, group) pair, so it needs 1.5-2.3x this radius and blooms when
     * entered, pushing the siblings outward. Reserving the room up front
     * cannot fix that -- the factor is scale-invariant, so padding every
     * anchor scales the level too and cancels out -- and it would cost the
     * agreement between the circle and its label, which is worth more.
     */
    function territoryRadius(contentCount) {
        return PACK_K * Math.sqrt(Math.max(0, contentCount || 0))
    }

    /** The disc a cell of `m` contents occupies at the packing density. */
    function spreadForCell(memberCount) {
        return territoryRadius(memberCount)
    }

    /**
     * The zoom ceiling: the scale at which a card holds a full excerpt.
     *
     * This is what replaces "a content is never wider than 56 px". That rule
     * was right about a DISC -- text does not scale with the camera, so
     * magnifying a circle buys nothing. It is the wrong rule for a card,
     * whose point is the text: the ceiling has to be where the text is
     * legible, which is a measurable quantity rather than a taste.
     */
    function zoomCeiling() {
        return CARD_FULL_PX / CARD_H
    }

    /**
     * What a content's mark can show at this scale.
     *
     * There are no tiers and no cross-fades. The mark is always a card; how
     * much fits is a function of how big it is, and lines appear one at a
     * time as the room arrives. That is simpler than the three smoothstep
     * ramps it replaces, and it is continuous by construction.
     *
     * `round` is how far the mark has morphed from a circle to a rectangle:
     * 1 is a circle (corner radius = half the shorter side), 0 is the card.
     *
     * @param scale CSS px per content radius
     * @return {w, h, round, lines, charWidth, showTitle, showParent, summaryLines}
     */
    function cardFor(scale) {
        var k = scale > 0 ? scale : 0
        var w = CARD_W * k
        var h = CARD_H * k
        // The circle holds on while the mark is too small for any text, then
        // gives way over the range where the first line becomes readable.
        var round = 1 - smoothstep(20, 42, k)
        var usableH = h - CARD_PAD * 2
        var usableW = w - CARD_PAD * 2
        var lines = usableH > 0 ? Math.floor(usableH / CARD_LINE_PX) : 0
        return {
            w: w,
            h: h,
            round: round,
            usableW: usableW,
            lines: lines,
            // Title first, then where it lives, then the excerpt. Dropping
            // from the bottom keeps the most identifying line longest.
            showTitle: lines >= 1 && usableW >= 40,
            titleLines: lines >= 3 ? 2 : 1,
            showParent: lines >= 4 && usableW >= 80,
            summaryLines: lines >= 5 ? lines - 3 : 0,
        }
    }

    /**
     * The child groups as territories: absolute radii, tangent, disjoint.
     *
     * Nothing is scaled to fit. The LEVEL is sized to hold the pack (see
     * levelRadiusFor), not the pack squeezed to fit the level, which is what
     * makes the packSiblings guarantee -- mutually tangent, never
     * overlapping -- survive all the way to the screen.
     *
     * That is only possible because a content shared by several groups is
     * DRAWN IN EACH of them. Keeping one mark per content forces the shared
     * mark to sit between its groups, which stretches both outlines toward
     * it; with a hundred groups every outline crosses every other and the
     * level reads as a hairball. Measured on the root: 5565 outline pairs,
     * all of them crossing, against zero once the mark is duplicated.
     *
     * @param children [{tag, reach}]
     */
    function layoutAnchors(children) {
        var result = { anchors: [], byTag: {}, packExtent: 0 }
        if (!children || children.length === 0) return result

        var radii = children.map(function (child) {
            // A group in the list has at least the one content that put it
            // there; a zero radius would make the packing degenerate.
            return territoryRadius(Math.max(1, child.reach || 0))
        })
        var packed = packSiblings(radii)
        var bounds = enclose(packed)
        result.packExtent = bounds.r

        for (var i = 0; i < packed.length; i++) {
            var anchor = {
                tag: children[i].tag,
                reach: Math.max(1, children[i].reach || 0),
                index: i,
                x: packed[i].x - bounds.x,
                y: packed[i].y - bounds.y,
                r: packed[i].r,
            }
            result.anchors.push(anchor)
            result.byTag[anchor.tag] = anchor
        }
        return result
    }

    /**
     * The radius of a level that has to hold this pack and this many direct
     * contents.
     *
     * Derived from the pack rather than from the level's content count: with
     * a content drawn in each of its groups a level shows more marks than it
     * has contents, and the room it needs is the room the marks need.
     * INNER_FILL leaves the ring outside the pack for the direct contents.
     */
    function levelRadiusFor(packExtent, directCount) {
        return Math.max(packExtent, territoryRadius(directCount || 0)) / INNER_FILL
    }

    /**
     * One mark per (content, group) pair, each inside its own group.
     *
     * A content in three groups gets three marks. That is a deliberate
     * reversal: the partition that gave every content exactly one mark also
     * forced shared marks between their groups, and the resulting overlap
     * made a level of a hundred groups unreadable. Identity is kept in the
     * `key` instead of in the geometry, so the instances of one content can
     * be tied together on demand rather than always.
     *
     * Needs no relaxation: within a group the sunflower is at least 1.02
     * CONTENT_PITCH apart at every size, and the groups are disjoint, so two
     * marks can never overlap.
     *
     * @param memberships [[key, [groupIndex, ...]], ...] in payload order
     * @param anchors from layoutAnchors
     * @param options {levelRadius} scales the band the group-less marks ring
     * @return [{key, content, group, x, y, inBand}]
     */
    function placeMarks(memberships, anchors, options) {
        options = options || {}
        var levelR = options.levelRadius === undefined ? 1 : options.levelRadius
        var rows = memberships || []
        var byGroup = []
        for (var g = 0; g < anchors.length; g++) byGroup.push([])
        var bandBound = []

        for (var i = 0; i < rows.length; i++) {
            var groups = rows[i][1] || []
            var known = 0
            for (var k = 0; k < groups.length; k++) {
                var g = groups[k]
                if (!anchors[g]) continue
                // One mark per (content, group), so a group named twice in a
                // row still yields one mark. Otherwise the guarantee would
                // depend on the manifest never repeating itself.
                if (groups.indexOf(g) !== k) continue
                byGroup[g].push(i)
                known++
            }
            // No group of this level: either it carries no child tag at all,
            // or its group is past the response's cap. Both ring the band.
            if (known === 0) bandBound.push(i)
        }

        var marks = []
        for (var a = 0; a < anchors.length; a++) {
            var anchor = anchors[a]
            var members = byGroup[a]
            // The marks occupy the room the marks need, which is less than
            // the territory whenever fewer contents are known than the
            // group's reach. The shape then states what is known; the label
            // still states the count.
            //
            // Capped by the territory. On consistent data the cap never
            // binds: a group holds at most `reach` contents, so its marks
            // need at most territoryRadius(reach). It binds only when the
            // manifest and coTags disagree -- a truncated list, a cache
            // straddling two schemas -- and then the group crowds, which is
            // truthful, instead of spilling over its neighbours.
            var spread = Math.min(spreadForCell(members.length), anchor.r)
            for (var m = 0; m < members.length; m++) {
                var local = insideSlot(m, members.length)
                marks.push({
                    key: rows[members[m]][0],
                    content: members[m],
                    group: a,
                    x: anchor.x + local.x * spread,
                    y: anchor.y + local.y * spread,
                    inBand: false,
                })
            }
        }

        var band = layoutBand(bandBound.map(function (index) {
            return { url: String(index) }
        }))
        for (var b = 0; b < bandBound.length; b++) {
            marks.push({
                key: rows[bandBound[b]][0],
                content: bandBound[b],
                group: -1,
                x: band[b].x * levelR,
                y: band[b].y * levelR,
                inBand: true,
            })
        }
        return marks
    }

    /**
     * Where an ancestor's field sits in the current level's space.
     *
     * Entering a group dilates its parent's field about that group's centre
     * by the BLOOM -- the ratio between the level the group becomes and the
     * circle it was, about 2.2 on real data. So the chain is
     *
     *     q = p * scale + offset,   scale = product of the blooms
     *
     * and a point maps through it while a TERRITORY RADIUS does not: one
     * circle grew (the one entered, into the level you are now in) and the
     * rest were pushed apart without changing size, because a radius states a
     * content count and must not change because the reader navigated. A
     * level BOUNDARY does scale, since it has to keep containing the field
     * that spread inside it.
     *
     * Why a dilation rather than re-packing with one radius enlarged: measured
     * on a 24-group level, re-packing moves siblings up to 1.5 level radii and
     * pulls up to 11 of 23 INWARD -- a scatter, not a transition. A dilation
     * can only increase distance from its centre, so nothing moves inward,
     * nothing is overtaken and no new overlap appears.
     *
     * The bloom is bounded, so depth 5 composes to about 50 rather than the
     * thousands the renormalised model reached. A step with no bloom
     * truncates the chain rather than inventing a position.
     *
     * @param steps [{x, y, beta}] nearest ancestor first; x,y is the entered
     *   circle's centre in THAT ancestor's own coordinates
     */
    // Angular resolution of a level's boundary. 96 sectors is under 4 degrees
    // apiece -- fine enough that the outline reads as a curve, coarse enough
    // that a lone mark makes a bump rather than a spike.
    var HULL_SECTORS = 96
    // Circular smoothing passes over the radii, and a floor as a share of
    // the hull's own peak. Both tuned by measurement: with no floor a sparse
    // level collapsed to a starfish (radius varying by 0.97 of its peak on a
    // 25-mark level), and with more smoothing than this the shape rounds off
    // into the circle it was supposed to stop being. At 0.60 and 8 passes the
    // radius varies by 0.08 on the root, 0.31 on /Arduino, 0.37 on /OS --
    // organic at every density, spiky at none.
    var HULL_SMOOTH = 8
    var HULL_FLOOR = 0.60
    // Clearance beyond the furthest mark in a sector, in content radii: the
    // mark's own radius plus a gutter, so the outline never grazes a disc.
    var HULL_PAD = 2.2

    /**
     * How far a level's contents reach, per direction: its boundary.
     *
     * NOT a metaball, and that is the point. A single density field over a
     * whole level cannot be sampled finely enough to be trusted: measured on
     * the root, a level-wide contour on the 25x25 grid left 135 of 411 marks
     * OUTSIDE its own outline, and reported anywhere from 1 to 34 separate
     * bodies as the kernel changed -- grid aliasing, not geometry. Per-group
     * contours escape this because each is sampled over its own bounds.
     *
     * A radial hull has none of those failure modes. It contains every mark
     * by construction (each sector is pushed out past its furthest mark), it
     * is one closed body by construction, it costs a pass over the marks
     * rather than a grid, and being star-shaped about the centre it can be
     * interpolated with another hull angle by angle -- which is what makes
     * entering a level a morph rather than a cut.
     *
     * @param points [{x, y}] in the level's own coordinates
     * @param minRadius a floor, so a level with one mark still has a shape
     * @return radii by sector, HULL_SECTORS of them, starting at angle 0
     */
    function radialHull(points, minRadius) {
        var radii = new Float64Array(HULL_SECTORS)
        var floor = Math.max(0, minRadius || 0)
        for (var i = 0; i < HULL_SECTORS; i++) radii[i] = floor

        for (var p = 0; p < (points || []).length; p++) {
            var point = points[p]
            var reach = Math.hypot(point.x, point.y)
            var distance = reach + HULL_PAD
            var angle = Math.atan2(point.y, point.x)
            if (angle < 0) angle += TAU
            var sector = Math.floor((angle / TAU) * HULL_SECTORS) % HULL_SECTORS
            // A mark has width, so it pushes the outline out over an ARC --
            // and how wide that arc is depends on how far out the mark is.
            // A fixed three sectors was wrong at both ends: too wide for a
            // mark near the rim and too narrow for one near the middle. The
            // half-width of what is DRAWN (half a card) subtends this angle
            // at this distance, so the sectors a mark's own shape occupies
            // are exactly the ones it pushes.
            //
            // Measured before the fix: on a 3-group level one card corner of
            // 60 hung 1.34 units outside the boundary, because it fell two
            // sectors from its mark and only one was pushed. The disc it
            // replaced stayed inside, which is why this went unnoticed.
            var half = reach > 1e-9 ? Math.atan2(CARD_W / 2, reach) : Math.PI
            var span = Math.max(1, Math.ceil((half / TAU) * HULL_SECTORS))
            for (var d = -span; d <= span; d++) {
                var s = (sector + d + HULL_SECTORS) % HULL_SECTORS
                if (radii[s] < distance) radii[s] = distance
            }
        }

        // A floor relative to the hull's own reach, so an empty direction
        // dents the outline instead of cutting it to the centre.
        var peak = 0
        for (var m = 0; m < HULL_SECTORS; m++) if (radii[m] > peak) peak = radii[m]
        var relative = peak * HULL_FLOOR
        for (var n = 0; n < HULL_SECTORS; n++) {
            if (radii[n] < relative) radii[n] = relative
        }

        // Circular smoothing, so the outline is a curve rather than a comb.
        // Never below what a sector needs, or smoothing would cut a mark out.
        var floors = radii.slice()
        for (var pass = 0; pass < HULL_SMOOTH; pass++) {
            var next = new Float64Array(HULL_SECTORS)
            for (var k = 0; k < HULL_SECTORS; k++) {
                var a = radii[(k - 1 + HULL_SECTORS) % HULL_SECTORS]
                var b = radii[k]
                var c = radii[(k + 1) % HULL_SECTORS]
                next[k] = Math.max(floors[k], (a + 2 * b + c) / 4)
            }
            radii = next
        }
        return radii
    }

    /** The same representation, taken off an existing outline's vertices. */
    function radialHullOfRings(rings, centreX, centreY) {
        var points = []
        var cx = centreX || 0, cy = centreY || 0
        ;(rings || []).forEach(function (ring) {
            for (var i = 0; i < ring.length; i++) {
                points.push({ x: ring[i].x - cx, y: ring[i].y - cy })
            }
        })
        // The vertices already sit ON the outline, so no extra clearance.
        var radii = radialHull(points, 0)
        for (var k = 0; k < radii.length; k++) radii[k] -= HULL_PAD
        return radii
    }

    /**
     * Two hulls blended angle by angle: a boundary in mid-morph.
     *
     * The ends are returned exactly rather than computed. `a + (b-a)*1` is
     * not bitwise `b` for every value -- measured, 4 of 96 sectors drifted by
     * 2e-15 -- and the settled boundary of a level should BE that level's own
     * hull, not a copy of it that a later comparison finds unequal.
     *
     * Out of range clamps rather than extrapolating, so an easing that
     * overshoots cannot turn a shape inside out.
     */
    function blendHulls(from, to, t) {
        if (!(t > 0)) return from || to
        if (t >= 1) return to || from
        var out = new Float64Array(HULL_SECTORS)
        for (var i = 0; i < HULL_SECTORS; i++) {
            var a = from ? from[i] : 0
            var b = to ? to[i] : 0
            out[i] = a + (b - a) * t
        }
        return out
    }

    /**
     * The hull's radius in the direction of (x, y), measured from its centre.
     *
     * The inverse of hullRing: given a point, which sector's radius decides
     * where the outline runs there. hullRing puts a sector's radius at the
     * MIDDLE of its arc, so the sector holding an angle is simply the floor
     * of it -- dropping that and rounding to the nearest VERTEX instead is
     * off by one everywhere, which scale.test.js catches.
     */
    function hullRadiusAt(radii, x, y) {
        if (!radii || radii.length === 0) return null
        var n = radii.length
        var angle = Math.atan2(y, x)
        if (angle < 0) angle += TAU
        return radii[Math.floor((angle / TAU) * n) % n]
    }

    /** A hull as a closed ring of points, ready to stroke. */
    function hullRing(radii, centreX, centreY) {
        var ring = []
        var cx = centreX || 0, cy = centreY || 0
        for (var i = 0; i < HULL_SECTORS; i++) {
            // Sector centres, so a radius describes the middle of its arc.
            var angle = ((i + 0.5) / HULL_SECTORS) * TAU
            ring.push({ x: cx + Math.cos(angle) * radii[i], y: cy + Math.sin(angle) * radii[i] })
        }
        ring.push({ x: ring[0].x, y: ring[0].y })
        return ring
    }

    /**
     * The camera that keeps the picture still across a level change.
     *
     * Two things move and the camera has to follow both: the coordinate
     * ORIGIN moves to the circle being entered, and that circle BLOOMS into
     * the level it becomes (radius r -> beta*r). Screen mapping is
     * S(p) = C + (p - cam)*k, and entering rewrites the space as
     * q = (p - c)*beta, so
     *
     *     cam' = (cam - c)*beta,   k' = k/beta
     *
     * gives S'(q) = C + ((p-c)beta - (cam-c)beta) * k/beta = C + (p-cam)*k
     * for EVERY point, and preserves the entered circle's screen radius too
     * (beta*r * k/beta = r*k). The bloom is therefore exactly a camera move:
     * the beta in the geometry and the 1/beta in the camera cancel, and what
     * reveals the bloom afterwards is the camera easing to the new fit.
     *
     * This lives here, as arithmetic over numbers, because it was once
     * deleted on the mistaken grounds that an absolute scale had made it
     * unnecessary. An absolute scale removes the RENORMALISATION, not the
     * need to follow a moving origin -- and without it the picture jumped
     * 264 px on entering and 118 px on leaving. In the renderer that was
     * invisible to `node --test`; here it is not.
     *
     * @param camera {x, y, scale}
     * @param centre the entered circle, in the OUTER level's coordinates
     * @param beta the bloom: the level's radius over the circle's
     * @return {x, y, scale}, or null when the bloom is not usable
     */
    function bloomCamera(camera, centre, beta, direction) {
        if (!camera || !centre) return null
        if (!(beta > 0) || !isFinite(beta)) return null
        if (direction === "out") {
            return {
                x: camera.x / beta + centre.x,
                y: camera.y / beta + centre.y,
                scale: camera.scale * beta,
            }
        }
        return {
            x: (camera.x - centre.x) * beta,
            y: (camera.y - centre.y) * beta,
            scale: camera.scale / beta,
        }
    }

    function composeBloom(steps) {
        var scale = 1, x = 0, y = 0
        for (var i = 0; i < (steps || []).length; i++) {
            var step = steps[i]
            if (!step || !(step.beta > 0)) break
            scale *= step.beta
            x -= step.x * scale
            y -= step.y * scale
        }
        return { scale: scale, x: x, y: y }
    }

    // ---- hashing ----------------------------------------------------------

    function fnv1a(text) {
        var hash = 0x811c9dc5
        for (var i = 0; i < text.length; i++) {
            hash ^= text.charCodeAt(i)
            hash = (hash * 0x01000193) >>> 0
        }
        return hash
    }

    function clamp01(value) {
        return value < 0 ? 0 : (value > 1 ? 1 : value)
    }

    // ---- smallest enclosing circle ---------------------------------------

    /**
     * An enclosing circle of the given circles, tight enough for layout.
     *
     * Iterative shrink-wrap from the centroid: repeatedly step the centre
     * toward whichever circle sticks out furthest, with a decaying step. A
     * fixed iteration count keeps it deterministic, and the final radius is
     * measured (not estimated), so containment is exact even if the circle is
     * a hair larger than the true minimum. Welzl's algorithm would be minimal
     * but needs a shuffle, and minimality buys nothing here.
     */
    function enclose(circles) {
        if (circles.length === 0) return { x: 0, y: 0, r: 0 }
        if (circles.length === 1) {
            return { x: circles[0].x, y: circles[0].y, r: circles[0].r }
        }

        var cx = 0, cy = 0
        circles.forEach(function (c) { cx += c.x; cy += c.y })
        cx /= circles.length
        cy /= circles.length

        var farthest = function (x, y) {
            var best = circles[0], bestReach = -Infinity
            for (var i = 0; i < circles.length; i++) {
                var reach = Math.hypot(circles[i].x - x, circles[i].y - y) + circles[i].r
                if (reach > bestReach) { bestReach = reach; best = circles[i] }
            }
            return { circle: best, reach: bestReach }
        }

        var step = 0.5
        for (var iteration = 0; iteration < 96; iteration++) {
            var far = farthest(cx, cy)
            var dx = far.circle.x - cx, dy = far.circle.y - cy
            var distance = Math.hypot(dx, dy)
            if (distance < EPSILON) break
            cx += (dx / distance) * step * far.reach * 0.5
            cy += (dy / distance) * step * far.reach * 0.5
            step *= 0.9
        }

        return { x: cx, y: cy, r: farthest(cx, cy).reach }
    }

    // ---- sibling packing --------------------------------------------------

    // Place circle c tangent to both a and b.
    function placeTangent(a, b, c) {
        var dx = b.x - a.x, dy = b.y - a.y
        var d2 = dx * dx + dy * dy
        if (d2 === 0) {
            c.x = a.x + a.r + c.r
            c.y = a.y
            return
        }
        var a2 = (a.r + c.r) * (a.r + c.r)
        var b2 = (b.r + c.r) * (b.r + c.r)
        if (a2 > b2) {
            var xb = (d2 + b2 - a2) / (2 * d2)
            var yb = Math.sqrt(Math.max(0, b2 / d2 - xb * xb))
            c.x = b.x - xb * dx - yb * dy
            c.y = b.y - xb * dy + yb * dx
        } else {
            var xa = (d2 + a2 - b2) / (2 * d2)
            var ya = Math.sqrt(Math.max(0, a2 / d2 - xa * xa))
            c.x = a.x + xa * dx - ya * dy
            c.y = a.y + xa * dy + ya * dx
        }
    }

    function overlaps(a, b) {
        var reach = a.r + b.r - 1e-6
        var dx = b.x - a.x, dy = b.y - a.y
        return reach > 0 && reach * reach > dx * dx + dy * dy
    }

    function chainScore(node) {
        var a = node.circle, b = node.next.circle
        var ab = a.r + b.r
        var dx = (a.x * b.r + b.x * a.r) / ab
        var dy = (a.y * b.r + b.y * a.r) / ab
        return dx * dx + dy * dy
    }

    /**
     * Pack circles of the given radii around the origin, tangentially and
     * without overlap. Front-chain algorithm (the one d3-hierarchy's
     * packSiblings uses, ISC-licensed by Mike Bostock; reimplemented here
     * because the project ships no bundler and d3 was dropped).
     *
     * Non-overlap holds BY CONSTRUCTION -- there is no iteration budget that
     * can expire. The previous design pushed circles apart for at most 120
     * tries and then let them stack, which for a busy tag meant a pile.
     *
     * @param radii number[] in the order they should be placed (largest first
     *   gives the tightest result)
     * @return [{x, y, r}] in the same order as `radii`
     */
    function packSiblings(radii) {
        var circles = radii.map(function (r) { return { x: 0, y: 0, r: r } })
        var n = circles.length
        if (n === 0) return circles

        circles[0].x = 0
        circles[0].y = 0
        if (n === 1) return circles

        circles[0].x = -circles[1].r
        circles[1].x = circles[0].r
        circles[1].y = 0
        if (n === 2) return circles

        placeTangent(circles[1], circles[0], circles[2])

        var a = { circle: circles[0] }
        var b = { circle: circles[1] }
        var c = { circle: circles[2] }
        a.next = c.previous = b
        b.next = a.previous = c
        c.next = b.previous = a

        pack: for (var i = 3; i < n; ++i) {
            placeTangent(a.circle, b.circle, circles[i])
            c = { circle: circles[i] }

            // Walk outward from the insertion point in both directions,
            // taking the nearer side first; on a collision, drop the chain
            // back to the offender and retry this circle.
            var j = b.next, k = a.previous
            var sj = b.circle.r, sk = a.circle.r
            do {
                if (sj <= sk) {
                    if (overlaps(j.circle, c.circle)) {
                        b = j
                        a.next = b
                        b.previous = a
                        --i
                        continue pack
                    }
                    sj += j.circle.r
                    j = j.next
                } else {
                    if (overlaps(k.circle, c.circle)) {
                        a = k
                        a.next = b
                        b.previous = a
                        --i
                        continue pack
                    }
                    sk += k.circle.r
                    k = k.previous
                }
            } while (j !== k.next)

            c.previous = a
            c.next = b
            a.next = b.previous = b = c

            // Re-anchor the chain at the pair closest to the centroid.
            var best = chainScore(a)
            while ((c = c.next) !== b) {
                var score = chainScore(c)
                if (score < best) { a = c; best = score }
            }
            b = a.next
        }

        return circles
    }

    // ---- children inside a parent ----------------------------------------

    /**
     * Lay the parent's children out as slots inside it.
     *
     * Radii come from the DATA first and the whole pack is then scaled to the
     * inner disc -- not the other way round. Sizing each child as a fraction
     * of the parent (as the previous design did) makes the packing density
     * scale-invariant, so growing the parent buys no room and 200 children
     * need more than twice the area available.
     *
     * @param children [{tag, weight}] weight = the count this circle's label
     *   will state. Order decides packing order; pass the server's order
     *   (count desc, natural-order tie-break) so the result is reproducible.
     * @return {slots: [{tag, x, y, r, index, weight}], byTag: {tag: slot}}
     */
    /**
     * Reorders groups so that ones sharing contents are packed next to each
     * other.
     *
     * packSiblings places circles in the order it is given, so the order IS
     * the adjacency. Ordering purely by count -- as before -- scatters
     * sharing partners across the field, and a content shared between two
     * distant groups then has to sit halfway between them, far outside both.
     * Measured on the real corpus: a point 25x its group's radius away from
     * it. A greedy chain keeps partners together, so a shared content lands
     * between neighbours instead of in the void.
     *
     * Sizes are untouched: this decides only WHO SITS BESIDE WHOM.
     *
     * @param children [{tag, weight}] in the server's order (count desc)
     * @param sharing {tag: {otherTag: sharedCount}} may be absent
     * @return the same children, reordered
     */
    function orderBySharing(children, sharing) {
        if (!sharing || children.length < 3) return children
        var remaining = children.slice()
        var ordered = [remaining.shift()]   // the biggest group anchors the chain
        var placed = {}
        placed[ordered[0].tag] = true
        while (remaining.length > 0) {
            var bestIndex = 0, bestScore = -1
            for (var i = 0; i < remaining.length; i++) {
                var shared = sharing[remaining[i].tag] || {}
                var score = 0
                for (var tag in shared) {
                    if (placed[tag]) score += shared[tag]
                }
                // Ties keep the server's order, which is count desc -- so with
                // no sharing at all this is exactly the previous behaviour.
                if (score > bestScore) { bestScore = score; bestIndex = i }
            }
            var next = remaining.splice(bestIndex, 1)[0]
            placed[next.tag] = true
            ordered.push(next)
        }
        return ordered
    }

    /**
     * The circle for one path segment inside its parent's layout. A single
     * tag is its own slot; an OR segment is ONE circle -- the enclosing
     * circle of its members, so the circle you get after fusing A and B
     * visibly contains both circles you just dragged together.
     *
     * Returns null when a tag is absent from the parent's layout (a truncated
     * coTag list, or a hand-typed URL). Callers must render the level without
     * ancestors rather than invent a slot.
     */
    function slotForSegment(layout, segment) {
        if (!layout || !segment || segment.length === 0) return null
        var members = []
        for (var i = 0; i < segment.length; i++) {
            var slot = layout.byTag[segment[i]]
            if (!slot) return null
            members.push(slot)
        }
        if (members.length === 1) return members[0]
        var fused = enclose(members)
        return {
            tag: segment.join(","),
            members: segment.slice(),
            x: fused.x,
            y: fused.y,
            r: fused.r * FUSE_PAD,
            index: members[0].index,
            weight: 0,
        }
    }

    // ---- the free band ----------------------------------------------------

    /**
     * Positions for direct contents, on rings just inside the boundary.
     *
     * Both terms depend only on the index, so layoutBand(10) is an exact
     * element-wise prefix of layoutBand(30): appending a page cannot move a
     * dot that is already on screen. The previous design derived each angle
     * from the current total, so paging re-scrambled every existing dot.
     *
     * @param items [{url}] used only for a deterministic per-item jitter
     */
    function layoutBand(items) {
        return (items || []).map(function (item, index) {
            var seed = fnv1a(String(item && item.url ? item.url : index))
            var ring = BAND_RINGS[index % BAND_RINGS.length]
            var angle = index * GOLDEN_ANGLE + ((seed % 100) / 100) * 0.02
            return {
                index: index,
                angle: angle,
                x: Math.cos(angle) * ring,
                y: Math.sin(angle) * ring,
                r: ring,
            }
        })
    }

    /**
     * Positions for contents shown INSIDE a circle (a child group whose few
     * contents are worth showing in place rather than making the reader
     * enter it). Returned in the circle's own unit space, packed within
     * INSIDE_FILL so they stay clear of its outline.
     *
     * Unlike layoutBand this is NOT prefix-stable: the ring radii depend on
     * the count, so the whole arrangement changes when the count does. That
     * is fine here and nowhere else -- this population is never paged. It is
     * requested in one go, only when it is small enough to be worth showing
     * in place at all.
     */
    function layoutInside(count, capacity) {
        var positions = []
        if (count <= 0) return positions
        var slots = capacity === undefined ? count : capacity
        for (var i = 0; i < count; i++) {
            positions.push(insideSlot(i, slots))
        }
        return positions
    }

    /**
     * One slot of the sunflower, parameterised by the CAPACITY it is laid out
     * for rather than by how many are filled.
     *
     * Splitting capacity from count is what makes the anonymous marks exact:
     * a group's marks are drawn for `capacity = count` before any content is
     * known, and when the real contents arrive a content that belongs to this
     * group alone lands on `insideSlot(itsIndex, count)` — the very slot its
     * stand-in occupied. Nothing jumps. Only the contents that turn out to be
     * SHARED move, and that movement is itself the information.
     */
    function insideSlot(index, capacity) {
        var slots = Math.max(1, capacity)
        if (slots === 1) return { index: index, x: 0, y: 0 }
        // Sunflower packing: even area coverage without a preferred axis.
        var radius = INSIDE_FILL * Math.sqrt((index + 0.5) / slots)
        var angle = index * GOLDEN_ANGLE
        return {
            index: index,
            x: Math.cos(angle) * radius,
            y: Math.sin(angle) * radius,
        }
    }


    /**
     * Stand-ins for a group whose contents are not known yet: `count` marks
     * on the same sunflower the real contents will use.
     *
     * They are drawn as anonymous — no label, not tappable — because that is
     * what they are. What makes them honest is the count: "N things are here"
     * is a fact, and each mark is one of them. What makes them harmless is
     * insideSlot's capacity parameter: when the real data lands, a content
     * belonging to this group alone occupies exactly the mark it replaces.
     */
    function anonymousMarks(count, slot, limit) {
        var marks = []
        var shown = Math.min(count, limit || count)
        if (shown <= 0) return marks
        // Mass is preserved when capped, so a 500-count group still reads as
        // big instead of collapsing to the cap.
        var weight = count / shown
        // The SAME spread placeMarks() uses, cap included, so a mark and the
        // content it resolves into occupy the same point and nothing jumps
        // when the bodies arrive. Changing one without the other breaks that
        // silently -- the marks simply land somewhere else.
        var spread = Math.min(spreadForCell(count), slot.r)
        for (var i = 0; i < shown; i++) {
            var local = insideSlot(i, count)
            marks.push({
                index: i,
                x: slot.x + local.x * spread,
                y: slot.y + local.y * spread,
                w: weight,
                anonymous: true,
            })
        }
        return marks
    }

    // ---- density field and its contour ------------------------------------
    //
    // A group's shape is the isoline of a scalar field over ITS OWN points, so
    // the outline follows what is inside rather than being a circle decided in
    // advance. Two groups merge only by literally sharing a point: the field
    // is per-group, so mere adjacency cannot fuse anything -- which matters
    // because packSiblings makes every sibling mutually TANGENT, and a single
    // global field would melt every level into one blob and claim a
    // relationship the data does not contain.

    /**
     * Wyvill soft-object kernel, in squared distance to avoid a sqrt.
     * Compact support is not an optimisation but a determinism property: a
     * point beyond `radius` contributes exactly zero, so a group's contour
     * cannot depend on anything outside it.
     */
    function kernel(distanceSquared, radiusSquared) {
        if (distanceSquared >= radiusSquared) return 0
        var t = 1 - distanceSquared / radiusSquared
        return t * t * t
    }

    /**
     * The kernel radius at which ONE point's isoline is a circle of exactly
     * `territoryRadius`. Solving (1 - d²/R²)³ = T for d = territoryRadius.
     *
     * This is what makes the degenerate case free: a group with no known
     * contents is a single point and draws precisely the circle the previous
     * design drew, so nothing regresses where there is no data to shape it.
     */
    function kernelRadius(territoryRadius, memberCount) {
        var solo = territoryRadius / Math.sqrt(1 - Math.cbrt(FIELD_T))
        var m = Math.max(1, memberCount || 1)
        // A lone point must reproduce the circle EXACTLY -- that is the
        // invariant that makes "no contents known" cost nothing. The spacing
        // factor only applies once there is more than one point to space.
        if (m === 1) return solo
        // Shrink with density so a crowded group reads as one body rather
        // than a bag of separate beads.
        return solo * KERNEL_SPACING / Math.sqrt(m)
    }

    /**
     * The kernel a group's ACTUAL points need, so its outline reaches them.
     *
     * The territory-derived radius assumes the members sit inside the
     * territory. A content shared with a distant group does not: it is pulled
     * toward that group, and a kernel sized for the territory cannot reach
     * it, leaving the point outside its own outline. Sizing from the spread
     * of the points that are really there keeps "the shape follows the
     * contents" true even when the contents are far-flung.
     */
    function kernelForPoints(points, territoryRadius) {
        var nominal = kernelRadius(territoryRadius, points.length)
        if (points.length < 2) return nominal
        // Every k-th point, so the O(m²) scan stays bounded on a large group.
        // By index, not by position, so the sample is the same every time.
        var step = Math.max(1, Math.ceil(points.length / KERNEL_SAMPLE_MAX))
        var sample = []
        for (var s = 0; s < points.length; s += step) sample.push(points[s])
        if (sample.length < 2) sample = points.slice(0, 2)

        // Mean nearest-neighbour distance: the scale at which these points
        // read as one body.
        var total = 0
        for (var i = 0; i < sample.length; i++) {
            var nearest = Infinity
            for (var j = 0; j < sample.length; j++) {
                if (i === j) continue
                var d = Math.hypot(sample[i].x - sample[j].x, sample[i].y - sample[j].y)
                if (d < nearest) nearest = d
            }
            total += nearest === Infinity ? 0 : nearest
        }
        var spread = (total / sample.length) * KERNEL_SPACING / Math.sqrt(1 - Math.cbrt(FIELD_T))
        return Math.max(nominal, spread)
    }

    /**
     * Bounded union of the points' kernels: 1 - Π(1 - fᵢ).
     * Bounded to [0,1] however many points there are, so a dense group cannot
     * swell without limit, and smooth, so nearby points merge.
     *
     * @param points [{x, y, r, w}] r = kernel radius, w = weight (default 1)
     */
    function fieldAt(points, x, y) {
        var product = 1
        for (var i = 0; i < points.length; i++) {
            var p = points[i]
            var dx = x - p.x, dy = y - p.y
            var r = p.r
            var value = kernel(dx * dx + dy * dy, r * r)
            if (value <= 0) continue
            var w = p.w === undefined ? 1 : p.w
            product *= 1 - Math.min(1, value * w)
            if (product <= 0) return 1
        }
        return 1 - product
    }

    /**
     * A square window just big enough to hold every kernel, plus a margin.
     *
     * Fitting the window to the shape rather than sampling a fixed extent is
     * what keeps the accuracy the same for a small group as for a large one:
     * the grid always spends its resolution on the shape instead of on empty
     * space around it. It depends only on the points, so it stays
     * deterministic.
     */
    function fieldBounds(points) {
        var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity
        for (var i = 0; i < points.length; i++) {
            var p = points[i]
            if (p.x - p.r < minX) minX = p.x - p.r
            if (p.x + p.r > maxX) maxX = p.x + p.r
            if (p.y - p.r < minY) minY = p.y - p.r
            if (p.y + p.r > maxY) maxY = p.y + p.r
        }
        var centreX = (minX + maxX) / 2, centreY = (minY + maxY) / 2
        var half = Math.max(maxX - minX, maxY - minY) / 2
        half *= 1.06   // a margin so the isoline never touches the border
        return { centreX: centreX, centreY: centreY, half: half }
    }

    /** Samples the field on a regular grid. Row-major, y increasing with j. */
    function sampleField(points, bounds, n) {
        var values = new Float64Array(n * n)
        var step = (2 * bounds.half) / (n - 1)
        var originX = bounds.centreX - bounds.half
        var originY = bounds.centreY - bounds.half
        for (var j = 0; j < n; j++) {
            var y = originY + j * step
            for (var i = 0; i < n; i++) {
                values[j * n + i] = fieldAt(points, originX + i * step, y)
            }
        }
        return { values: values, n: n, originX: originX, originY: originY, step: step }
    }

    // Directed segments per marching-squares case, "inside on the left" so
    // every ring comes out consistently wound. Edges: 0 bottom, 1 right,
    // 2 top, 3 left. Cases 5 and 10 are the ambiguous saddles, resolved
    // separately by the value at the cell centre.
    var MS_CASES = [
        [],             // 0  none inside
        [[0, 3]],       // 1  BL
        [[1, 0]],       // 2  BR
        [[1, 3]],       // 3  BL BR
        [[2, 1]],       // 4  TR
        null,           // 5  BL TR   (saddle)
        [[2, 0]],       // 6  BR TR
        [[2, 3]],       // 7  BL BR TR
        [[3, 2]],       // 8  TL
        [[0, 2]],       // 9  BL TL
        null,           // 10 BR TL   (saddle)
        [[1, 2]],       // 11 BL BR TL
        [[3, 1]],       // 12 TR TL
        [[0, 1]],       // 13 BL TR TL
        [[3, 0]],       // 14 BR TR TL
        [],             // 15 all inside
    ]

    /**
     * Marching squares over a sampled field, returning closed rings.
     *
     * Crossings are identified by the EDGE they lie on, not by their
     * coordinates: two neighbouring cells share an edge, so keying on the
     * edge makes them the same vertex by integer identity rather than by
     * float comparison. That is what makes ring assembly exact.
     */
    function marchingSquares(grid, threshold) {
        var n = grid.n, values = grid.values, step = grid.step
        var inside = function (i, j) { return values[j * n + i] >= threshold }
        var coordX = function (i) { return grid.originX + i * step }
        var coordY = function (j) { return grid.originY + j * step }

        // Edge ids: horizontal (i,j) = 2*(j*n+i), vertical (i,j) = that + 1.
        var vertices = new Map()
        var crossing = function (edgeId, i0, j0, i1, j1) {
            var found = vertices.get(edgeId)
            if (found) return found
            var a = values[j0 * n + i0], b = values[j1 * n + i1]
            var t = (b === a) ? 0.5 : (threshold - a) / (b - a)
            if (t < 0) t = 0
            else if (t > 1) t = 1
            var point = {
                x: coordX(i0) + (coordX(i1) - coordX(i0)) * t,
                y: coordY(j0) + (coordY(j1) - coordY(j0)) * t,
            }
            vertices.set(edgeId, point)
            return point
        }

        var next = new Map()   // start edge id -> end edge id
        for (var j = 0; j < n - 1; j++) {
            for (var i = 0; i < n - 1; i++) {
                var code = (inside(i, j) ? 1 : 0)
                    | (inside(i + 1, j) ? 2 : 0)
                    | (inside(i + 1, j + 1) ? 4 : 0)
                    | (inside(i, j + 1) ? 8 : 0)
                var pairs = MS_CASES[code]
                if (pairs === null) {
                    // Saddle: the cell centre decides whether the two inside
                    // corners are connected. A fixed rule, so the topology is
                    // reproducible rather than dependent on float noise.
                    var centre = (values[j * n + i] + values[j * n + i + 1]
                        + values[(j + 1) * n + i + 1] + values[(j + 1) * n + i]) / 4
                    var joined = centre >= threshold
                    if (code === 5) {
                        pairs = joined ? [[1, 0], [3, 2]] : [[0, 3], [2, 1]]
                    } else {
                        pairs = joined ? [[3, 0], [1, 2]] : [[1, 0], [3, 2]]
                    }
                }
                if (pairs.length === 0) continue

                var edgeId = function (side) {
                    if (side === 0) return 2 * (j * n + i)                 // bottom
                    if (side === 1) return 2 * (j * n + i + 1) + 1         // right
                    if (side === 2) return 2 * ((j + 1) * n + i)           // top
                    return 2 * (j * n + i) + 1                             // left
                }
                var ensure = function (side) {
                    var id = edgeId(side)
                    if (side === 0) crossing(id, i, j, i + 1, j)
                    else if (side === 1) crossing(id, i + 1, j, i + 1, j + 1)
                    else if (side === 2) crossing(id, i, j + 1, i + 1, j + 1)
                    else crossing(id, i, j, i, j + 1)
                    return id
                }
                for (var p = 0; p < pairs.length; p++) {
                    next.set(ensure(pairs[p][0]), ensure(pairs[p][1]))
                }
            }
        }

        // Walk the links into closed rings. Deterministic: the start order is
        // insertion order, which is the fixed cell scan order above.
        var rings = []
        var visited = new Set()
        next.forEach(function (_end, start) {
            if (visited.has(start)) return
            var ring = []
            var edge = start
            while (edge !== undefined && !visited.has(edge)) {
                visited.add(edge)
                ring.push(vertices.get(edge))
                edge = next.get(edge)
            }
            if (ring.length >= 3) {
                ring.push(ring[0])   // closed
                rings.push(ring)
            }
        })
        return rings
    }

    /** Signed area (shoelace). Positive = counter-clockwise. */
    function polygonArea(ring) {
        var sum = 0
        for (var i = 0; i < ring.length - 1; i++) {
            sum += ring[i].x * ring[i + 1].y - ring[i + 1].x * ring[i].y
        }
        return sum / 2
    }

    /** Even-odd test across every ring, so holes behave. */
    function pointInPolygon(rings, x, y) {
        var inside = false
        for (var r = 0; r < rings.length; r++) {
            var ring = rings[r]
            for (var i = 0, j = ring.length - 2; i < ring.length - 1; j = i++) {
                var a = ring[i], b = ring[j]
                if ((a.y > y) !== (b.y > y)
                    && x < (b.x - a.x) * (y - a.y) / (b.y - a.y) + a.x) {
                    inside = !inside
                }
            }
        }
        return inside
    }

    /** One Chaikin pass: takes the corners off a marching-squares ring. */
    function smoothRing(ring) {
        if (ring.length < 4) return ring
        var out = []
        for (var i = 0; i < ring.length - 1; i++) {
            var a = ring[i], b = ring[i + 1]
            out.push({ x: a.x * 0.75 + b.x * 0.25, y: a.y * 0.75 + b.y * 0.25 })
            out.push({ x: a.x * 0.25 + b.x * 0.75, y: a.y * 0.25 + b.y * 0.75 })
        }
        out.push(out[0])
        return out
    }

    /**
     * The grid point where the field is strongest — where a group's label
     * belongs, and guaranteed inside its own contour.
     */
    function fieldArgmax(grid) {
        var best = -1, bx = 0, by = 0
        for (var j = 0; j < grid.n; j++) {
            for (var i = 0; i < grid.n; i++) {
                var v = grid.values[j * grid.n + i]
                if (v > best) {
                    best = v
                    bx = grid.originX + i * grid.step
                    by = grid.originY + j * grid.step
                }
            }
        }
        return { x: bx, y: by, value: best }
    }

    /**
     * A group's outline, from its points. Everything is in the group's own
     * unit space (its territory is the origin with radius 1).
     *
     * @param points [{x, y, r, w}]
     * @return {rings, area, anchor} — rings smoothed and closed
     */
    function contour(points, options) {
        options = options || {}
        var threshold = options.threshold === undefined ? FIELD_T : options.threshold
        var n = options.gridN || GRID_N
        if (!points || points.length === 0) return { rings: [], area: 0, anchor: { x: 0, y: 0 } }

        var grid = sampleField(points, options.bounds || fieldBounds(points), n)
        var rings = marchingSquares(grid, threshold).map(smoothRing)
        var area = 0
        rings.forEach(function (ring) { area += Math.abs(polygonArea(ring)) })
        return { rings: rings, area: area, anchor: fieldArgmax(grid) }
    }

    /** Smooth 0→1 ramp, for cross-fading level of detail without steps. */
    function smoothstep(edge0, edge1, x) {
        if (edge1 === edge0) return x < edge0 ? 0 : 1
        var t = clamp01((x - edge0) / (edge1 - edge0))
        return t * t * (3 - 2 * t)
    }

    return {
        TAU: TAU,
        GOLDEN_ANGLE: GOLDEN_ANGLE,
        INNER_FILL: INNER_FILL,
        BAND_RINGS: BAND_RINGS,
        CONTENT_R: CONTENT_R,
        CONTENT_PITCH: CONTENT_PITCH,
        PACK_K: PACK_K,
        KERNEL_SPACING: KERNEL_SPACING,
        territoryRadius: territoryRadius,
        spreadForCell: spreadForCell,
        CARD_W: CARD_W,
        CARD_H: CARD_H,
        CARD_ASPECT: CARD_ASPECT,
        CARD_FULL_PX: CARD_FULL_PX,
        CARD_PAD: CARD_PAD,
        CARD_TITLE_PX: CARD_TITLE_PX,
        CARD_BODY_PX: CARD_BODY_PX,
        CARD_LINE_PX: CARD_LINE_PX,
        cardFor: cardFor,
        zoomCeiling: zoomCeiling,
        layoutAnchors: layoutAnchors,
        HULL_SECTORS: HULL_SECTORS,
        radialHull: radialHull,
        radialHullOfRings: radialHullOfRings,
        blendHulls: blendHulls,
        hullRing: hullRing,
        hullRadiusAt: hullRadiusAt,
        bloomCamera: bloomCamera,
        composeBloom: composeBloom,
        FUSE_PAD: FUSE_PAD,
        INSIDE_FILL: INSIDE_FILL,
        FIELD_T: FIELD_T,
        GRID_N: GRID_N,
        fieldBounds: fieldBounds,
        kernel: kernel,
        kernelRadius: kernelRadius,
        kernelForPoints: kernelForPoints,
        orderBySharing: orderBySharing,
        fieldAt: fieldAt,
        sampleField: sampleField,
        marchingSquares: marchingSquares,
        polygonArea: polygonArea,
        pointInPolygon: pointInPolygon,
        smoothRing: smoothRing,
        fieldArgmax: fieldArgmax,
        contour: contour,
        smoothstep: smoothstep,
        fnv1a: fnv1a,
        clamp01: clamp01,
        enclose: enclose,
        packSiblings: packSiblings,
        slotForSegment: slotForSegment,
        layoutBand: layoutBand,
        layoutInside: layoutInside,
        insideSlot: insideSlot,
        levelRadiusFor: levelRadiusFor,
        placeMarks: placeMarks,
        anonymousMarks: anonymousMarks,
    }
})
