/*
 * The absolute scale: one content radius is the world unit.
 *
 * Nothing here is normalised per level, which is the whole point -- the old
 * model made the current level radius 1 and renormalised on every step, so
 * you could zoom for ever and no two levels were comparable. These tests pin
 * the replacement: a region's size states a content count, and it states the
 * same count wherever it appears on the map.
 *
 * The numbers in the comments were measured on the reference corpus (192
 * contents, 106 groups) -- see the plan's W1 section.
 *
 * Run: node --test Client/TagMap/tests/
 */
"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const L = require("../tagmap-layout.js")

// --- fixtures ---------------------------------------------------------------

/** Group reaches shaped like real tag data: a few big, a long thin tail. */
function zipfReach(n) {
    return Array.from({ length: n }, (_, i) => ({
        tag: "tag" + i,
        reach: Math.max(1, Math.round(40 / (i + 1))),
    }))
}

const DISTRIBUTIONS = {
    equal: (n) => Array.from({ length: n }, (_, i) => ({ tag: "t" + i, reach: 7 })),
    zipf: zipfReach,
    extreme: (n) => Array.from({ length: n }, (_, i) => ({ tag: "t" + i, reach: i === 0 ? 1000 : 1 })),
    ascending: (n) => Array.from({ length: n }, (_, i) => ({ tag: "t" + i, reach: i + 1 })),
}
const SIZES = [1, 2, 3, 5, 17, 60, 200]

/** A level: its territories, and the radius they oblige it to have. */
function levelOf(children, direct) {
    const layout = L.layoutAnchors(children)
    return Object.assign({
        levelR: L.levelRadiusFor(layout.packExtent, direct || 0),
        direct: direct || 0,
    }, layout)
}

/**
 * A level with actual marks in it: reaches derived from the manifest, so the
 * fixture cannot claim a group holds fewer contents than it is given.
 */
function markedLevel(groups, contents) {
    const rows = []
    for (let i = 0; i < contents; i++) {
        const pick = (...offsets) => Array.from(new Set(offsets.map((o) => (i + o) % groups)))
        if (i % 7 === 0) rows.push(["c" + i, []])
        else if (i % 5 === 0) rows.push(["c" + i, pick(0, 3)])
        else rows.push(["c" + i, pick(0)])
    }
    const counts = new Array(groups).fill(0)
    let direct = 0
    for (const row of rows) {
        const known = row[1].filter((g) => g >= 0 && g < groups)
        if (known.length === 0) direct++
        for (const g of known) counts[g]++
    }
    const level = levelOf(counts.map((count, i) => ({ tag: "g" + i, reach: Math.max(1, count) })), direct)
    return Object.assign({
        rows,
        marks: L.placeMarks(rows, level.anchors, { levelRadius: level.levelR }),
    }, level)
}

// --- the sizing function ----------------------------------------------------

test("the packing constant IS the pitch, so a region of n contents is sqrt(n) pitches", () => {
    // PACK_K = (CONTENT_PITCH/2)/sqrt(fill) with fill = 0.25. Stating it as
    // the pitch removes a constant rather than hiding one: there is no
    // separate fill factor to tune, and the derivation is checkable here.
    assert.equal(L.PACK_K, L.CONTENT_PITCH)
    assert.ok(Math.abs(L.PACK_K - (L.CONTENT_PITCH / 2) / Math.sqrt(0.25)) < 1e-12)
    assert.equal(L.territoryRadius(1), L.CONTENT_PITCH)
    assert.ok(Math.abs(L.territoryRadius(9) - 3 * L.CONTENT_PITCH) < 1e-12)
})

test("radius is exactly proportional to sqrt(count), so area states the count", () => {
    // Not merely monotone. The old layout only promised "a heavier child is
    // not smaller", which permitted any curve; here the AREA is the count,
    // and it is the same count at every level in the map.
    for (const n of [1, 2, 7, 50, 192, 4000]) {
        assert.ok(Math.abs(L.territoryRadius(n) - L.PACK_K * Math.sqrt(n)) < 1e-12)
        assert.ok(Math.abs(L.territoryRadius(4 * n) - 2 * L.territoryRadius(n)) < 1e-12,
            `four times the contents must be exactly twice the radius (n=${n})`)
    }
    assert.equal(L.territoryRadius(0), 0)
    assert.equal(L.territoryRadius(-5), 0, "a negative count is not a radius")
    // The cell spread is the same function, which is what keeps an anonymous
    // mark and the content it becomes on the same point.
    assert.equal(L.spreadForCell(9), L.territoryRadius(9))
})

test("the smallest possible group is never smaller than one content", () => {
    // This is what replaces MIN_CHILD_RATIO, and it replaces it with a
    // scale-free statement instead of a ratio.
    //
    // The floor existed because a normalised layout made the smallest of 200
    // children sub-pixel: its size was a fraction of the largest, so a long
    // tail vanished. Absolutely, the smallest group is CONTENT_PITCH -- one
    // content plus its gutter -- no matter what else is on the map. So it can
    // only be sub-pixel if a CONTENT is sub-pixel, and at that zoom the level
    // of detail is drawing density rather than marks anyway.
    //
    // Note the ratio to the largest is now 0.03, where the deleted
    // MIN_CHILD_RATIO floor would have held it at 0.12. That is not a
    // regression: the ratio stopped being the thing that decides visibility.
    const level = levelOf(DISTRIBUTIONS.extreme(200))
    const radii = level.anchors.map((a) => a.r)
    assert.equal(Math.min(...radii), L.territoryRadius(1))
    assert.ok(Math.min(...radii) >= 2 * L.CONTENT_R,
        "a group must be at least as wide as the content it holds")
    assert.ok(Math.min(...radii) / Math.max(...radii) < 0.05,
        "a ratio near the old 0.12 floor would mean radii are being rescaled again")
})

test("a level is sized to hold its pack, not the pack squeezed to fit it", () => {
    // The direction of the dependency is the whole reason the territories can
    // stay disjoint: nothing is scaled to fit, so the packSiblings guarantee
    // survives to the screen.
    for (const n of SIZES) {
        const level = levelOf(zipfReach(n))
        assert.ok(level.packExtent > 0)
        assert.ok(Math.abs(level.levelR - level.packExtent / L.INNER_FILL) < 1e-9,
            `n=${n}: level ${level.levelR} is not the pack ${level.packExtent} over INNER_FILL`)
        // The band outside the pack is what INNER_FILL leaves room for.
        assert.ok(level.levelR > level.packExtent)
    }
    // A level with many direct contents and few groups is sized by the band
    // instead, so those contents have somewhere to be.
    const banded = levelOf(zipfReach(1), 400)
    assert.ok(Math.abs(banded.levelR - L.territoryRadius(400) / L.INNER_FILL) < 1e-9)
    // And an empty level is not a division by zero.
    assert.equal(L.levelRadiusFor(0, 0), 0)
})

test("the zoom range is finite and grows only as sqrt(N)", () => {
    // The whole reason for the absolute scale: "you cannot keep zooming" has
    // to be a number, not an intention.
    const K_MAX = 28, FIT_FILL = 0.94, viewport = 800
    const rangeFor = (marks) => {
        const levelR = L.levelRadiusFor(L.territoryRadius(marks), 0)
        return K_MAX / ((viewport * FIT_FILL) / (2 * levelR))
    }
    assert.ok(rangeFor(411) < 10, `the root's 411 marks should be under 10x, got ${rangeFor(411).toFixed(2)}`)
    assert.ok(rangeFor(100000) < 150, `even 100k marks stay bounded, got ${rangeFor(100000).toFixed(2)}`)
    // Quadrupling the map exactly doubles the range.
    assert.ok(Math.abs(rangeFor(4 * 411) - 2 * rangeFor(411)) < 1e-9)
})

// --- territories ------------------------------------------------------------

for (const [name, make] of Object.entries(DISTRIBUTIONS)) {
    for (const n of SIZES) {
        test(`territories state their counts and never overlap: ${name}, n=${n}`, () => {
            const children = make(n)
            const level = levelOf(children)
            assert.equal(level.anchors.length, n)

            for (const anchor of level.anchors) {
                assert.ok(Number.isFinite(anchor.x) && Number.isFinite(anchor.y)
                    && Number.isFinite(anchor.r), `non-finite anchor: ${JSON.stringify(anchor)}`)
                // (a) the radius is the count, exactly -- never rescaled to
                // fit, because a rescaled radius would state a different
                // count than the label beside it.
                assert.equal(anchor.r, L.territoryRadius(anchor.reach))
                // (b) the whole territory is inside the level, rim included.
                assert.ok(Math.hypot(anchor.x, anchor.y) + anchor.r
                    <= L.INNER_FILL * level.levelR + 1e-9,
                    `${anchor.tag} reaches past the level's inner field`)
            }

            // (c) tangent, never overlapping -- all the way to the screen.
            for (let i = 0; i < level.anchors.length; i++) {
                for (let j = i + 1; j < level.anchors.length; j++) {
                    const a = level.anchors[i], b = level.anchors[j]
                    const distance = Math.hypot(a.x - b.x, a.y - b.y)
                    const touching = a.r + b.r
                    // Relative epsilon: front-chain packing lands tangent to
                    // within a few ULPs, so an absolute one is too strict.
                    assert.ok(distance >= touching - 1e-9 * touching,
                        `${a.tag} overlaps ${b.tag} by ${touching - distance}`)
                }
            }
        })
    }
}

test("territories must NOT overlap, whatever the sharing", () => {
    // This test was once the opposite. Under one-mark-per-content the
    // territories had to overlap -- the groups' counts sum to about 2.5x the
    // level's, so absolutely-sized disjoint circles could not fit -- and the
    // overlapping outlines made a hundred-group level unreadable: all 5565
    // outline pairs crossed.
    //
    // Duplicating the shared marks removed the obligation, and the level is
    // now sized to hold the pack rather than the pack squeezed into the
    // level. If territories start overlapping again, either something is
    // renormalising the level or the marks have gone back to being shared.
    const level = levelOf(zipfReach(60))
    for (let i = 0; i < level.anchors.length; i++) {
        for (let j = i + 1; j < level.anchors.length; j++) {
            const a = level.anchors[i], b = level.anchors[j]
            assert.ok(Math.hypot(a.x - b.x, a.y - b.y) >= a.r + b.r - 1e-9 * (a.r + b.r),
                `${a.tag} and ${b.tag} overlap`)
        }
    }
})

test("packSiblings resizes nothing", () => {
    // The radius carries a content count, so a packing that adjusted it would
    // make the circle state a different number from its label.
    for (const n of SIZES) {
        const radii = zipfReach(n).map((c) => L.territoryRadius(c.reach))
        const packed = L.packSiblings(radii)
        assert.equal(packed.length, n)
        for (let i = 0; i < packed.length; i++) assert.equal(packed[i].r, radii[i])
    }
})

test("an empty level yields no territories rather than an invented one", () => {
    for (const empty of [[], null, undefined]) {
        const level = L.layoutAnchors(empty)
        assert.deepEqual(level.anchors, [])
        assert.deepEqual(level.byTag, {})
        assert.equal(level.packExtent, 0)
    }
    // A group that somehow reports no contents still gets the minimum size:
    // it is in the list, so at least one content put it there.
    assert.equal(L.layoutAnchors([{ tag: "x", reach: 0 }]).anchors[0].r, L.territoryRadius(1))
})

// --- entering blooms, and the bloom is bounded ------------------------------

/** A child level of `contents` contents, with reaches a child could have. */
function childLevelOf(contents) {
    const children = []
    let marks = 0
    for (let i = 0; i < contents && marks < contents * 2; i++) {
        const reach = Math.max(1, Math.round(contents / (i + 1)))
        children.push({ tag: "c" + i, reach: reach })
        marks += reach
    }
    return { children: children, marks: marks, level: levelOf(children) }
}

test("entering a group blooms it, by a factor the model can state", () => {
    // The consequence of drawing a content in each of its groups: the level
    // behind a circle holds a mark per (content, group) pair, so it needs
    // more room than the circle you clicked.
    //
    // Reserving the room up front cannot avoid this: the factor is
    // scale-invariant, so padding every territory scales the level by the
    // same amount and cancels out. What CAN be pinned is that the factor is
    // stable and derivable, rather than a surprise at run time. Measured on
    // the real corpus: 1.55 entering /Arduino, 2.00 entering /OS.
    const blooms = []
    for (const contents of [4, 17, 65, 192, 1000]) {
        const child = childLevelOf(contents)
        const bloom = child.level.levelR / L.territoryRadius(contents)
        blooms.push(bloom)
        assert.ok(bloom > 1, `a level of ${contents} should need more room than its circle`)
        assert.ok(bloom < 3, `bloom ${bloom.toFixed(2)} for ${contents} contents is out of hand`)
        // It is the membership multiplier over the packing efficiency, not an
        // accident of this fixture's shape.
        const predicted = Math.sqrt((child.marks / contents) / 0.78) / L.INNER_FILL
        assert.ok(Math.abs(bloom - predicted) < 0.25,
            `bloom ${bloom.toFixed(2)} against predicted ${predicted.toFixed(2)}`)
    }
    // Stable across three orders of magnitude, which is what lets a
    // transition be planned rather than discovered.
    assert.ok(Math.max(...blooms) - Math.min(...blooms) < 0.1,
        `bloom varies from ${Math.min(...blooms).toFixed(2)} to ${Math.max(...blooms).toFixed(2)}`)
})

test("entering must NOT re-pack the level: re-packing scatters the siblings", () => {
    // Why the bloom has to be a dilation about the entered group rather than
    // a fresh packing with one radius grown.
    //
    // Re-running packSiblings with the entered territory enlarged looks like
    // the obvious implementation and is measurably wrong: siblings travel up
    // to 1.5 LEVEL RADII -- further than the level is wide -- and up to 11 of
    // 23 move INWARD, which is a scatter, not a transition. This is the
    // defect the nested-circle model was built to remove, and it comes
    // straight back if a bloom is implemented by re-packing.
    //
    // The measurement lives here so that anyone tempted by the shortcut finds
    // the number before shipping it.
    const children = zipfReach(24)
    const before = levelOf(children)

    let worstTravel = 0, worstInward = 0
    for (let entered = 0; entered < children.length; entered++) {
        const after = levelOf(children.map((child, i) => i === entered
            ? { tag: child.tag, reach: Math.max(2, Math.round(child.reach * 4.8)) }
            : child))
        for (const child of children) {
            if (child.tag === children[entered].tag) continue
            const was = before.byTag[child.tag], now = after.byTag[child.tag]
            worstTravel = Math.max(worstTravel,
                Math.hypot(now.x - was.x, now.y - was.y) / before.levelR)
            if (Math.hypot(now.x, now.y) < Math.hypot(was.x, was.y) - 1e-9) worstInward++
        }
    }
    assert.ok(worstTravel > 1,
        `re-packing moved siblings only ${worstTravel.toFixed(2)} level radii; if this has `
        + `become small, re-packing may now be an acceptable bloom after all`)
    assert.ok(worstInward > 0, "re-packing used to pull siblings inward; it no longer does")
})

test("a dilation about the entered group pushes every sibling outward", () => {
    // The bloom that IS acceptable: keep the packing, dilate the POSITIONS
    // about the entered group's centre by the bloom factor, and leave every
    // radius alone (a radius states a content count and must not change
    // because the reader navigated).
    //
    // Distances from the centre of the dilation can only grow, so no sibling
    // moves inward, no sibling can be overtaken, and no new overlap can
    // appear -- the entered territory grows by exactly the factor the field
    // around it spreads by.
    const children = zipfReach(24)
    const level = levelOf(children)
    const bloom = 2.19

    for (const entered of [0, 3, 12, 23]) {
        const centre = level.byTag[children[entered].tag]
        const grownR = centre.r * bloom
        for (const child of children) {
            if (child.tag === children[entered].tag) continue
            const was = level.byTag[child.tag]
            const moved = {
                x: (was.x - centre.x) * bloom + centre.x,
                y: (was.y - centre.y) * bloom + centre.y,
                r: was.r,
            }
            const wasOut = Math.hypot(was.x - centre.x, was.y - centre.y)
            const nowOut = Math.hypot(moved.x - centre.x, moved.y - centre.y)
            assert.ok(nowOut >= wasOut - 1e-9, `${child.tag} moved inward`)
            assert.ok(Math.abs(nowOut - wasOut * bloom) < 1e-9, "the push is exactly the bloom")
            // The bloomed territory does not swallow it.
            assert.ok(nowOut - moved.r - grownR > 0,
                `${child.tag} collides with the bloomed territory`)
        }
    }
})

// --- composing levels --------------------------------------------------------

test("the ancestor chain composes the blooms, and a point maps through it", () => {
    // One step: entering a circle at c with bloom beta maps a parent point p
    // to (p - c) * beta, so the entered circle stays put and everything else
    // spreads away from it.
    const one = L.composeBloom([{ x: 3, y: -4, beta: 2 }])
    assert.deepEqual(one, { scale: 2, x: -6, y: 8 })
    const through = (chain, p) => ({ x: p.x * chain.scale + chain.x, y: p.y * chain.scale + chain.y })
    assert.deepEqual(through(one, { x: 3, y: -4 }), { x: 0, y: 0 },
        "the circle you entered must land on the new origin")

    // Two steps compose as a similarity: scale multiplies, offsets accumulate.
    const two = L.composeBloom([{ x: 3, y: -4, beta: 2 }, { x: 10, y: 20, beta: 3 }])
    assert.equal(two.scale, 6)
    assert.deepEqual(through(two, { x: 10, y: 20 }), through(one, { x: 0, y: 0 }),
        "the grandparent point we entered through must land on the parent's origin")

    assert.deepEqual(L.composeBloom([]), { scale: 1, x: 0, y: 0 })
    assert.deepEqual(L.composeBloom(null), { scale: 1, x: 0, y: 0 })
})

test("a missing or degenerate step truncates the chain instead of inventing one", () => {
    // Replaces "a degenerate slot truncates the chain instead of producing
    // Infinity". There is no reciprocal left, so Infinity is unreachable;
    // what remains worth checking is that a gap STOPS the chain rather than
    // being skipped over, which would place an ancestor somewhere
    // plausible-looking and wrong.
    const good = L.composeBloom([{ x: 1, y: 2, beta: 2 }])
    assert.deepEqual(L.composeBloom([{ x: 1, y: 2, beta: 2 }, null, { x: 100, y: 200, beta: 2 }]), good)
    assert.deepEqual(L.composeBloom([{ x: 1, y: 2, beta: 0 }, { x: 5, y: 5, beta: 2 }]),
        { scale: 1, x: 0, y: 0 })
})

test("the composed ancestor scale stays modest at depth 5", () => {
    // Replaces "ancestor scales stay in a sane range and finite at depth 5".
    // The old chain multiplied reciprocal radii and reached the thousands;
    // the bloom is bounded near 2.2, so five levels compose to about 50.
    const steps = []
    let children = zipfReach(20)
    for (let depth = 0; depth < 5; depth++) {
        const level = levelOf(children)
        // Whichever group is there to enter; deeper levels have fewer.
        const slot = level.anchors[Math.min(3, level.anchors.length - 1)]
        const child = childLevelOf(slot.reach)
        steps.push({ x: slot.x, y: slot.y, beta: child.level.levelR / slot.r })
        children = child.children
    }
    const chain = L.composeBloom(steps)
    assert.ok(Number.isFinite(chain.scale) && Number.isFinite(chain.x) && Number.isFinite(chain.y))
    assert.ok(chain.scale > 1 && chain.scale < 200,
        `depth 5 composes to ${chain.scale.toFixed(1)}, which is no longer modest`)
})

// --- determinism -------------------------------------------------------------

test("the absolute scale is deterministic and free of hidden state", () => {
    const children = zipfReach(40)
    const a = L.layoutAnchors(children)
    assert.deepEqual(L.layoutAnchors(children), a)
    assert.deepEqual(L.layoutAnchors(JSON.parse(JSON.stringify(children))), a)
})

test("the absolute scale uses no clock and no randomness", () => {
    const realRandom = Math.random
    const realNow = Date.now
    Math.random = () => { throw new Error("Math.random must not be reachable") }
    Date.now = () => { throw new Error("Date.now must not be reachable") }
    try {
        L.territoryRadius(50)
        L.spreadForCell(9)
        L.levelRadiusFor(30, 4)
        L.layoutAnchors(zipfReach(50))
        L.composeBloom([{ x: 1, y: 1, beta: 2 }])
    } finally {
        Math.random = realRandom
        Date.now = realNow
    }
})

// --- the boundary, and the camera that keeps it still -----------------------

test("a level's boundary contains every one of its marks", () => {
    // The invariant that was already established for a GROUP's contour and
    // then never applied to the level's own boundary. Carrying the clicked
    // outline through left 51 of 85 marks outside it: the outline describes
    // the parent's `count` marks inside a territory of radius r, while the
    // level holds `reach` marks over about 2.2r.
    for (const [groups, contents] of [[3, 9], [21, 29], [106, 192], [60, 400]]) {
        const marked = markedLevel(groups, contents)
        const hull = L.radialHull(marked.marks, L.territoryRadius(1))
        const ring = L.hullRing(hull, 0, 0)
        for (const mark of marked.marks) {
            assert.ok(L.pointInPolygon([ring], mark.x, mark.y),
                `${groups} groups: a mark at ${mark.x},${mark.y} is outside its own level`)
        }
    }
})

test("the boundary is a soft shape, neither a circle nor a starfish", () => {
    // A metaball cannot do this job: one field over a whole level cannot be
    // sampled finely enough. Measured on the root, a level-wide contour on
    // the 25x25 grid left 135 of 411 marks outside and reported between 1 and
    // 34 separate bodies as the kernel changed -- grid aliasing, not
    // geometry. A radial hull has neither failure mode, but it needs a floor:
    // without one a sparse level collapsed to spikes reaching the centre.
    for (const [groups, contents] of [[6, 25], [21, 85], [106, 411]]) {
        const marked = markedLevel(groups, contents)
        const hull = Array.from(L.radialHull(marked.marks, L.territoryRadius(1)))
        const peak = Math.max(...hull), dip = Math.min(...hull)
        assert.ok(dip / peak >= 0.55,
            `${groups} groups: the outline dips to ${(dip / peak).toFixed(2)} of its reach -- a starfish`)
        assert.ok((peak - dip) / peak > 0.02,
            `${groups} groups: the outline is within 2% of a circle`)
    }
})

test("entering keeps every point still: cam' = (cam-c)*beta, k' = k/beta", () => {
    // The identity I deleted once, on the mistaken grounds that an absolute
    // scale had made it unnecessary. It removes the RENORMALISATION, not the
    // need to follow a moving origin: without this the picture jumped 264 px
    // on entering and 118 px on leaving, and it lived in the renderer where
    // `node --test` could not see it.
    const view = 700
    const camera = { x: 12.5, y: -4.25, scale: 5.0625 }
    const centre = { x: -11.79, y: 5.99, r: 20.96 }
    const beta = 1.553
    const screen = (cam, p) => view + (p - cam) * cam.scale

    const after = L.bloomCamera(camera, centre, beta, "in")
    assert.ok(after)
    // Every parent point lands where it was, expressed in the child's space.
    for (const p of [{ x: -11.79, y: 5.99 }, { x: 30, y: -20 }, { x: 0, y: 0 }, { x: 44, y: 44 }]) {
        const q = { x: (p.x - centre.x) * beta, y: (p.y - centre.y) * beta }
        const wasX = view + (p.x - camera.x) * camera.scale
        const nowX = view + (q.x - after.x) * after.scale
        const wasY = view + (p.y - camera.y) * camera.scale
        const nowY = view + (q.y - after.y) * after.scale
        assert.ok(Math.abs(nowX - wasX) < 1e-9 && Math.abs(nowY - wasY) < 1e-9,
            `a parent point moved by ${Math.hypot(nowX - wasX, nowY - wasY)} px`)
    }
    // And the entered circle's screen radius survives: beta*r * k/beta = r*k.
    assert.ok(Math.abs(centre.r * beta * after.scale - centre.r * camera.scale) < 1e-9)

    // Leaving is the exact inverse.
    const back = L.bloomCamera(after, centre, beta, "out")
    assert.ok(Math.abs(back.x - camera.x) < 1e-9)
    assert.ok(Math.abs(back.y - camera.y) < 1e-9)
    assert.ok(Math.abs(back.scale - camera.scale) < 1e-9)

    // A bloom that is not a number is refused rather than producing NaN.
    for (const bad of [0, -1, NaN, Infinity, undefined]) {
        assert.equal(L.bloomCamera(camera, centre, bad, "in"), null)
    }
    assert.equal(L.bloomCamera(camera, null, beta, "in"), null)
})

test("two hulls blend angle by angle, and the ends are exact", () => {
    const a = L.radialHull([{ x: 5, y: 0 }, { x: 0, y: 5 }], 2)
    const b = L.radialHull([{ x: 20, y: 0 }, { x: 0, y: 20 }], 4)
    assert.deepEqual(Array.from(L.blendHulls(a, b, 0)), Array.from(a))
    assert.deepEqual(Array.from(L.blendHulls(a, b, 1)), Array.from(b))
    const mid = L.blendHulls(a, b, 0.5)
    for (let i = 0; i < L.HULL_SECTORS; i++) {
        assert.ok(Math.abs(mid[i] - (a[i] + b[i]) / 2) < 1e-12)
    }
    // Out of range is clamped, not extrapolated: a shape must not invert
    // because an easing overshot.
    assert.deepEqual(Array.from(L.blendHulls(a, b, -0.5)), Array.from(a))
    assert.deepEqual(Array.from(L.blendHulls(a, b, 1.5)), Array.from(b))
})

test("the hull and the camera use no clock and no randomness", () => {
    const realRandom = Math.random
    const realNow = Date.now
    Math.random = () => { throw new Error("Math.random must not be reachable") }
    Date.now = () => { throw new Error("Date.now must not be reachable") }
    try {
        const hull = L.radialHull([{ x: 3, y: 4 }, { x: -5, y: 1 }], 2)
        L.hullRing(hull, 0, 0)
        L.radialHullOfRings([L.hullRing(hull, 0, 0)], 0, 0)
        L.blendHulls(hull, hull, 0.5)
        L.bloomCamera({ x: 1, y: 2, scale: 3 }, { x: 0, y: 0 }, 2, "in")
    } finally {
        Math.random = realRandom
        Date.now = realNow
    }
})
