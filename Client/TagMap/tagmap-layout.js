/*
 * TagMap layout: the pure geometry of the nested-circle model.
 *
 * The selection path IS the nesting. `/Library/C#` means "the root field
 * contains Library's circle, which contains C#'s circle", and a child's
 * position is always a slot inside its parent. That makes "the circle you
 * click is the circle you enter" a property of the model rather than
 * behaviour to maintain: the previous design placed a child at a gathered
 * position inside its parent but derived the selection's circle from the
 * child's own global coordinates, so drilling in teleported the view and
 * scattered the siblings.
 *
 * Everything here is a pure function of payload data. No coordinates are
 * ever sent by the server (layout is a presentation concern), no randomness
 * and no clock, so the same tag path yields the same picture for every
 * visitor and a revisit reproduces it exactly. That also lets this file be
 * required from Node and checked mechanically -- determinism, non-overlap
 * and depth-5 precision are exactly the properties you cannot eyeball.
 *
 * Coordinates are PARENT-UNIT space: the circle being laid out is the origin
 * with radius 1. There is deliberately no single absolute world space: at
 * depth 5 a nested child would be ~1/3000 of the root field, which wrecks
 * float precision and line widths. Ancestors are instead scaled up around
 * the current level (see composeChain), so the current circle is always
 * exactly radius 1 however deep it sits.
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
    // Radius floor as a share of the largest child: without it the smallest
    // of 200 children is a sub-pixel dot and cannot be tapped.
    var MIN_CHILD_RATIO = 0.12
    var EPSILON = 1e-9

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
    function layoutChildren(children, options) {
        options = options || {}
        var innerFill = options.innerFill || INNER_FILL
        var result = { slots: [], byTag: {} }
        if (!children || children.length === 0) return result

        var raw = children.map(function (child) {
            return Math.sqrt(Math.max(0, child.weight || 0))
        })
        var largest = raw.reduce(function (m, r) { return Math.max(m, r) }, 0)
        if (largest <= 0) {
            // Every child weighs nothing: fall back to equal circles rather
            // than dividing by zero.
            raw = raw.map(function () { return 1 })
            largest = 1
        }
        var floor = largest * MIN_CHILD_RATIO
        var radii = raw.map(function (r) { return Math.max(r, floor) })

        var packed = packSiblings(radii)
        var bounds = enclose(packed)
        var scale = bounds.r > 0 ? innerFill / bounds.r : 0

        packed.forEach(function (circle, index) {
            var slot = {
                tag: children[index].tag,
                weight: children[index].weight || 0,
                index: index,
                x: (circle.x - bounds.x) * scale,
                y: (circle.y - bounds.y) * scale,
                r: circle.r * scale,
            }
            result.slots.push(slot)
            result.byTag[slot.tag] = slot
        })
        return result
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
    function layoutInside(count) {
        var positions = []
        if (count <= 0) return positions
        if (count === 1) {
            return [{ index: 0, x: 0, y: 0 }]
        }
        for (var i = 0; i < count; i++) {
            // Sunflower packing: even area coverage without a preferred axis.
            var radius = INSIDE_FILL * Math.sqrt((i + 0.5) / count)
            var angle = i * GOLDEN_ANGLE
            positions.push({
                index: i,
                x: Math.cos(angle) * radius,
                y: Math.sin(angle) * radius,
            })
        }
        return positions
    }

    // ---- the transform chain ---------------------------------------------

    /**
     * Transforms for the current level and its visible ancestors.
     *
     * The current level is the identity (radius 1 at the origin). Ancestor j
     * is placed so that the level below it sits exactly at the slot it
     * occupies inside it -- the algebraic inverse of entering. Composing
     * upward from the focus (never downward from an absolute root) keeps the
     * numbers small: with slots around 0.15, two ancestors compose to ~45x.
     *
     * @param slotChain [slot] the slot each level occupies inside its parent,
     *   nearest ancestor first. A null entry truncates the chain (an unknown
     *   slot must not become Infinity).
     * @return [{depth, ax, ay, ak}] starting with the current level
     */
    function composeChain(slotChain, maxAncestors) {
        var limit = maxAncestors === undefined ? 2 : maxAncestors
        var levels = [{ depth: 0, ax: 0, ay: 0, ak: 1 }]
        var previous = levels[0]
        for (var i = 0; i < slotChain.length && i < limit; i++) {
            var slot = slotChain[i]
            if (!slot || !(slot.r > EPSILON)) break
            var level = {
                depth: i + 1,
                ak: previous.ak / slot.r,
                ax: (previous.ax - slot.x) / slot.r,
                ay: (previous.ay - slot.y) / slot.r,
            }
            levels.push(level)
            previous = level
        }
        return levels
    }

    /**
     * Camera that frames the current circle, filling `fill` of the viewport.
     */
    function fitCamera(viewWidth, viewHeight, fill) {
        return {
            cx: 0,
            cy: 0,
            k: Math.min(viewWidth, viewHeight) * (fill || 0.86) / 2,
        }
    }

    /**
     * Re-anchor the camera when entering (`"in"`) or leaving (`"out"`) a slot.
     *
     * Screen mapping is f(p) = C + (p - c) * k. Entering rewrites the space as
     * p' = (p - s) / s.r, and setting c' = (c - s)/s.r, k' = k * s.r gives
     *
     *   f'(p') = C + ((p - s)/s.r - (c - s)/s.r) * (k * s.r)
     *          = C + (p - c) * k = f(p)
     *
     * for EVERY point, not just the slot's centre. So at the instant of
     * entering, the circle being entered stays exactly where it was on
     * screen, every sibling stays exactly where it was (they are now drawn
     * through the ancestor transform, which is this map's inverse), and the
     * ancestor boundary stays put. Nothing can teleport and nothing can
     * scatter: it is an identity, not a tuning parameter. The camera then
     * eases to fitCamera(), which grows the entered circle to fill the view
     * while the ancestors expand past the edges.
     */
    function reanchorCamera(camera, slot, direction) {
        if (!slot || !(slot.r > EPSILON)) return { cx: camera.cx, cy: camera.cy, k: camera.k }
        if (direction === "out") {
            return {
                cx: camera.cx * slot.r + slot.x,
                cy: camera.cy * slot.r + slot.y,
                k: camera.k / slot.r,
            }
        }
        return {
            cx: (camera.cx - slot.x) / slot.r,
            cy: (camera.cy - slot.y) / slot.r,
            k: camera.k * slot.r,
        }
    }

    /** Level-relative point -> screen pixels. Hit tests use this too, so a
     *  hit radius can never drift from the radius that was drawn. */
    function project(level, camera, view, x, y) {
        return {
            x: view.w / 2 + ((x * level.ak + level.ax) - camera.cx) * camera.k,
            y: view.h / 2 + ((y * level.ak + level.ay) - camera.cy) * camera.k,
        }
    }

    function projectRadius(level, camera, r) {
        return r * level.ak * camera.k
    }

    return {
        TAU: TAU,
        GOLDEN_ANGLE: GOLDEN_ANGLE,
        INNER_FILL: INNER_FILL,
        BAND_RINGS: BAND_RINGS,
        FUSE_PAD: FUSE_PAD,
        INSIDE_FILL: INSIDE_FILL,
        MIN_CHILD_RATIO: MIN_CHILD_RATIO,
        fnv1a: fnv1a,
        clamp01: clamp01,
        enclose: enclose,
        packSiblings: packSiblings,
        layoutChildren: layoutChildren,
        slotForSegment: slotForSegment,
        layoutBand: layoutBand,
        layoutInside: layoutInside,
        composeChain: composeChain,
        fitCamera: fitCamera,
        reanchorCamera: reanchorCamera,
        project: project,
        projectRadius: projectRadius,
    }
})
