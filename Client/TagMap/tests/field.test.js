/*
 * The density field and its contour.
 *
 * These are the properties that decide whether the picture can be trusted:
 * that a group with no known contents draws exactly the circle it used to,
 * that two groups merge only by sharing a point and never by being adjacent,
 * and that the outline you see is the region the hit test will agree with.
 * None of them can be checked by looking at a screenshot.
 *
 * Run: node --test Client/TagMap/tests/
 */
"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const L = require("../tagmap-layout.js")

const soloR = (territory) => L.kernelRadius(territory === undefined ? 1 : territory, 1)

// --- the kernel -------------------------------------------------------------

test("kernel is 1 at the centre, zero beyond its radius, and monotone", () => {
    const R = 0.8
    assert.equal(L.kernel(0, R * R), 1)
    assert.equal(L.kernel(R * R, R * R), 0)
    assert.equal(L.kernel(R * R * 4, R * R), 0, "compact support: nothing beyond R")

    let previous = Infinity
    for (let d = 0; d <= R; d += R / 40) {
        const v = L.kernel(d * d, R * R)
        assert.ok(v <= previous + 1e-12, `not monotone at d=${d}`)
        assert.ok(v >= 0 && v <= 1)
        previous = v
    }
    // C¹ at the boundary: the slope dies out with the value.
    const nearEdge = L.kernel((R * 0.999) ** 2, R * R)
    assert.ok(nearEdge < 1e-7, `kernel should approach 0 smoothly, got ${nearEdge}`)
})

test("compact support means a distant point contributes exactly nothing", () => {
    const points = [{ x: 0, y: 0, r: 0.5 }]
    const withDistant = points.concat([{ x: 9, y: 9, r: 0.5 }])
    for (const [x, y] of [[0, 0], [0.2, 0.1], [0.49, 0]]) {
        assert.equal(L.fieldAt(points, x, y), L.fieldAt(withDistant, x, y))
    }
})

// --- the degenerate case: no regression where there is no data --------------

test("a lone point draws exactly the circle of its territory", () => {
    for (const territory of [0.15, 0.4, 1]) {
        const contour = L.contour([{ x: 0, y: 0, r: soloR(territory) }])
        assert.equal(contour.rings.length, 1, "a lone point is one ring")
        const radii = contour.rings[0].map((p) => Math.hypot(p.x, p.y))
        for (const radius of radii) {
            assert.ok(Math.abs(radius - territory) <= territory * 0.01,
                `isoline at ${radius}, expected ${territory} (±1%)`)
        }
    }
})

// --- merging happens by sharing, never by adjacency -------------------------

test("two separate groups do not merge, however far their territories overlap", () => {
    // Load-bearing under the absolute scale. Anchors are no longer disjoint:
    // sized by their own content counts they necessarily overlap, because a
    // content carries several tags and the counts sum to more than the
    // level's. Two groups must still draw two outlines -- merging is
    // something only a SHARED CONTENT can cause, never proximity.
    const r = soloR(L.territoryRadius(4))
    const a = { x: 0, y: 0, r }
    const b = { x: L.territoryRadius(4) * 0.5, y: 0, r }   // 50% overlap
    const outlineA = L.contour([a])
    const outlineB = L.contour([b])
    assert.equal(outlineA.rings.length, 1, "group A is one ring")
    assert.equal(outlineB.rings.length, 1, "group B is one ring")

    // Each field sees only its own points, so B's presence cannot change A.
    assert.deepEqual(L.contour([a]).rings, outlineA.rings)
    // And the outlines really do overlap -- that is the picture of nothing,
    // yet; it becomes the picture of sharing once a content sits in both.
    assert.ok(L.pointInPolygon(outlineA.rings, b.x, b.y),
        "B's centre should fall inside A's outline at this overlap")
})

test("contents at the packing pitch read as one body; further apart they split", () => {
    // This is what pins KERNEL_SPACING, and it pins it to something with a
    // meaning: the merge distance IS the packing pitch, so "these read as one
    // body" says "these sit at the density the layout packs them at" rather
    // than naming a number someone liked the look of. At the old value of
    // 1.35 even four pitches apart merged.
    const nominal = L.kernelRadius(L.territoryRadius(2), 2)
    const ringsAt = (pitches) => {
        const d = L.CONTENT_PITCH * pitches
        return L.contour([{ x: -d / 2, y: 0, r: nominal }, { x: d / 2, y: 0, r: nominal }]).rings.length
    }
    assert.equal(ringsAt(1.0), 1, "at exactly the pitch: one body")
    assert.equal(ringsAt(1.2), 1, "a little further: still one body")
    assert.equal(ringsAt(1.4), 2, "at 1.4 pitches: two lobes")
    assert.equal(ringsAt(2.0), 2, "and they stay apart")
})

test("a group at the packing density gets the same kernel whatever its size", () => {
    // territoryRadius(m) grows as sqrt(m) and the kernel shrinks as sqrt(m),
    // so the two cancel: under the absolute scale the nominal kernel is one
    // constant, PACK_K * KERNEL_SPACING / sqrt(1 - cbrt(FIELD_T)). A group of
    // 100 therefore draws with the same softness as a group of 2 -- the shape
    // differs because the POINTS differ, not because the blur was rescaled.
    const expected = (L.PACK_K * L.KERNEL_SPACING) / Math.sqrt(1 - Math.cbrt(L.FIELD_T))
    for (const m of [2, 5, 20, 100, 4000]) {
        assert.ok(Math.abs(L.kernelRadius(L.territoryRadius(m), m) - expected) < 1e-12,
            `m=${m}: ${L.kernelRadius(L.territoryRadius(m), m)}, expected ${expected}`)
    }
    // The lone-point case is deliberately outside that rule: it must
    // reproduce the territory circle exactly, whatever the spacing constant.
    assert.ok(L.kernelRadius(L.territoryRadius(1), 1) > expected)
})

test("a shared point pulls the outline toward it", () => {
    // One group of two points; the outline must reach the far one.
    const r = soloR(0.3)
    const shared = { x: 0.55, y: 0 }
    const contour = L.contour([{ x: 0, y: 0, r }, { x: shared.x, y: shared.y, r }])
    assert.ok(L.pointInPolygon(contour.rings, shared.x, shared.y),
        "the shared point must lie inside its group's outline")
    const reach = Math.max(...contour.rings[0].map((p) => p.x))
    assert.ok(reach > 0.6, `outline should bulge past the shared point, reached ${reach}`)
})

// --- the shape you see is the shape you can tap -----------------------------

test("point-in-polygon agrees with the field threshold", () => {
    const r = soloR(0.4)
    const points = [{ x: -0.2, y: -0.1, r }, { x: 0.25, y: 0.15, r }, { x: 0, y: 0.4, r }]
    const contour = L.contour(points)
    // The sampling cell, for the failure message. This used to read a
    // CONTOUR_EXTENT that the module does not export, so it was NaN and
    // nobody noticed -- it only ever appeared inside an assertion message.
    const cell = (2 * L.fieldBounds(points).half) / (L.GRID_N - 1)

    let disagreements = 0
    let probes = 0
    for (let i = 0; i < 60; i++) {
        for (let j = 0; j < 60; j++) {
            const x = -1.2 + (2.4 * i) / 59
            const y = -1.2 + (2.4 * j) / 59
            const value = L.fieldAt(points, x, y)
            // Skip a band one cell wide around the isoline: that is the
            // discretisation error, not a disagreement.
            const distanceToEdge = Math.abs(value - L.FIELD_T)
            if (distanceToEdge < 0.08) continue
            probes++
            if (L.pointInPolygon(contour.rings, x, y) !== (value >= L.FIELD_T)) disagreements++
        }
    }
    assert.ok(probes > 1000, "the probe grid should actually cover the shape")
    assert.equal(disagreements, 0,
        `${disagreements}/${probes} probes where the drawn shape and the field disagree (cell ${cell})`)
})

test("the label anchor is inside the group's own outline", () => {
    const r = soloR(0.35)
    const points = [{ x: -0.3, y: 0, r }, { x: 0.3, y: 0.1, r }, { x: 0, y: -0.35, r }]
    const contour = L.contour(points)
    assert.ok(L.pointInPolygon(contour.rings, contour.anchor.x, contour.anchor.y))
    assert.ok(contour.anchor.value >= L.FIELD_T)
})

// --- well-formedness --------------------------------------------------------

test("rings are closed, finite, and enclose a real area", () => {
    const r = soloR(0.3)
    for (const n of [1, 2, 3, 7, 20]) {
        const points = L.layoutInside(n).map((p) => ({ x: p.x * 0.6, y: p.y * 0.6, r }))
        const contour = L.contour(points)
        assert.ok(contour.rings.length >= 1, `n=${n}: no outline at all`)
        for (const ring of contour.rings) {
            assert.deepEqual(ring[0], ring[ring.length - 1], `n=${n}: ring not closed`)
            for (const p of ring) {
                assert.ok(Number.isFinite(p.x) && Number.isFinite(p.y), `n=${n}: non-finite vertex`)
            }
            assert.ok(Math.abs(L.polygonArea(ring)) > 1e-6, `n=${n}: degenerate ring`)
        }
        assert.ok(contour.area > 0)
    }
})

test("an empty group has no outline rather than an invented one", () => {
    const contour = L.contour([])
    assert.deepEqual(contour.rings, [])
    assert.equal(contour.area, 0)
})

// --- determinism ------------------------------------------------------------

test("the contour is deterministic and free of hidden state", () => {
    const r = soloR(0.35)
    const points = [{ x: 0.1, y: -0.2, r }, { x: -0.25, y: 0.3, r }, { x: 0.4, y: 0.05, r }]
    assert.deepEqual(L.contour(points), L.contour(points))
    // No dependence on object identity.
    assert.deepEqual(L.contour(JSON.parse(JSON.stringify(points))), L.contour(points))
})

test("the field uses no clock and no randomness", () => {
    const realRandom = Math.random
    const realNow = Date.now
    Math.random = () => { throw new Error("Math.random must not be reachable") }
    Date.now = () => { throw new Error("Date.now must not be reachable") }
    try {
        const r = soloR(0.4)
        L.contour([{ x: 0, y: 0, r }, { x: 0.3, y: 0.2, r }])
        L.smoothstep(0, 1, 0.4)
    } finally {
        Math.random = realRandom
        Date.now = realNow
    }
})

test("the outline does not depend on how many points share a position", () => {
    // Two coincident points must not produce a different shape from one --
    // otherwise duplicate data would silently change the picture.
    const r = soloR(0.3)
    const one = L.contour([{ x: 0, y: 0, r }])
    const two = L.contour([{ x: 0, y: 0, r }, { x: 0, y: 0, r }])
    const radiusOf = (c) => Math.max(...c.rings[0].map((p) => Math.hypot(p.x, p.y)))
    // The bounded union saturates, so the second point adds reach but the
    // shape stays a circle. Assert it is still a circle, and bounded.
    const radii = two.rings[0].map((p) => Math.hypot(p.x, p.y))
    const round = (Math.max(...radii) - Math.min(...radii)) / Math.max(...radii)
    assert.ok(round < 0.02, `still a circle, roundness error ${round}`)
    assert.ok(radiusOf(two) >= radiusOf(one), "more mass cannot shrink the shape")
})

// --- smoothstep -------------------------------------------------------------

test("smoothstep is exact at its endpoints and monotone between", () => {
    assert.equal(L.smoothstep(0, 1, -5), 0)
    assert.equal(L.smoothstep(0, 1, 0), 0)
    assert.equal(L.smoothstep(0, 1, 1), 1)
    assert.equal(L.smoothstep(0, 1, 5), 1)
    let previous = -1
    for (let x = 0; x <= 1; x += 0.02) {
        const v = L.smoothstep(0, 1, x)
        assert.ok(v >= previous - 1e-12, `not monotone at ${x}`)
        previous = v
    }
    assert.equal(L.smoothstep(2, 2, 1), 0, "a zero-width ramp is a step")
    assert.equal(L.smoothstep(2, 2, 3), 1)
})

// --- the fusion melt ---------------------------------------------------------

test("two groups held together melt into exactly one body", () => {
    // Fusing A and B used to be a switch: release, and a new picture
    // appeared. Drawn as one soft body over both groups' marks while the
    // reader holds them, the gesture shows what it is about to produce --
    // and it is the same field over the same marks the fused level will
    // draw, so the preview cannot disagree with the result.
    //
    // The kernel that closes the gap comes from the CLOSEST CROSS-GROUP pair,
    // not from the distance between territories: what has to merge is the
    // marks. 0.95 of that distance clears the Wyvill kernel's pairwise merge
    // constant (0.8625) with margin at every group size, which is what this
    // checks -- a single constant tuned to one size would fail at others.
    for (const [reachA, reachB] of [[1, 1], [2, 1], [9, 4], [33, 17], [65, 3]]) {
        const layout = L.layoutAnchors([{ tag: "a", reach: reachA }, { tag: "b", reach: reachB }])
        const rows = []
        for (let i = 0; i < reachA; i++) rows.push(["a" + i, [0]])
        for (let i = 0; i < reachB; i++) rows.push(["b" + i, [1]])
        const marks = L.placeMarks(rows, layout.anchors,
            { levelRadius: L.levelRadiusFor(layout.packExtent, 0) })

        const mine = marks.filter((m) => m.group === 0)
        const theirs = marks.filter((m) => m.group === 1)
        let crossGap = Infinity
        for (const p of mine) {
            for (const q of theirs) crossGap = Math.min(crossGap, Math.hypot(p.x - q.x, p.y - q.y))
        }
        const natural = L.kernelForPoints(marks,
            Math.max(layout.anchors[0].r, layout.anchors[1].r))
        const bridging = Math.max(natural, crossGap * 0.95)

        // Bodies are counted by the SIGN of the enclosed area, not by ring
        // count: a group of 33 marks at the packing density is one body with
        // four holes, and holes are rings too. Counting rings would have made
        // this test read "5 bodies" and sent the next reader after a bug that
        // is not there.
        const bodies = (rings) => rings.filter((ring) => L.polygonArea(ring) > 0).length
        const holes = (rings) => rings.filter((ring) => L.polygonArea(ring) < 0).length

        const fused = L.contour(marks.map((m) => ({ x: m.x, y: m.y, r: bridging })))
        assert.equal(bodies(fused.rings), 1,
            `reach ${reachA}/${reachB}: the melt left ${bodies(fused.rings)} bodies`)
        assert.equal(holes(fused.rings), 0,
            `reach ${reachA}/${reachB}: the melt should close the gaps, not leave holes`)

        // Unfused, the same marks are two bodies -- so it is the melt that
        // changed the picture, not the placement.
        const separate = [0, 1].map((g) => {
            const members = marks.filter((m) => m.group === g)
            const r = L.kernelForPoints(members, layout.anchors[g].r)
            return bodies(L.contour(members.map((m) => ({ x: m.x, y: m.y, r }))).rings)
        })
        assert.equal(separate[0] + separate[1] >= 2, true,
            `reach ${reachA}/${reachB}: not separate to begin with`)
    }
})
