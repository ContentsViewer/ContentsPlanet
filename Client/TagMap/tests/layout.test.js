/*
 * Invariants of the layout that are not about scale.
 *
 * The sizing, packing, composition and determinism tests moved to
 * scale.test.js when the layout became absolute. What stays here is
 * independent of that: OR fusion, the free band's stability under paging,
 * and the in-a-group sunflower. The old grid over layoutChildren, the
 * transform chain and the re-anchor identity went with the machinery they
 * tested -- the renormalised space they made continuous no longer exists.
 *
 * Run: node --test Client/TagMap/tests/
 */
"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const L = require("../tagmap-layout.js")

// --- fixtures ---------------------------------------------------------------

/** A level's territories, shaped like real tag data: a few big, a long tail. */
function territories(n) {
    const layout = L.layoutAnchors(Array.from({ length: n }, (_, i) => ({
        tag: "tag" + i,
        reach: Math.max(1, Math.round(40 / (i + 1))),
    })))
    return { slots: layout.anchors, byTag: layout.byTag, packExtent: layout.packExtent }
}

// --- OR fusion --------------------------------------------------------------

test("a fused segment is one circle containing every member", () => {
    const layout = territories(20)
    for (const size of [2, 3, 4, 5]) {
        const segment = layout.slots.slice(0, size).map((s) => s.tag)
        const fused = L.slotForSegment(layout, segment)
        assert.ok(fused, "fusion returned null")
        assert.deepEqual(fused.members, segment)
        for (const tag of segment) {
            const member = layout.byTag[tag]
            const reach = Math.hypot(member.x - fused.x, member.y - fused.y) + member.r
            assert.ok(reach <= fused.r + 1e-9,
                `${tag} sticks out of the fused circle by ${reach - fused.r}`)
        }
    }
})

test("an unknown tag yields null rather than an invented slot", () => {
    const layout = territories(5)
    assert.equal(L.slotForSegment(layout, ["nope"]), null)
    assert.equal(L.slotForSegment(layout, ["tag0", "nope"]), null)
    assert.equal(L.slotForSegment(layout, []), null)
    assert.equal(L.slotForSegment(null, ["tag0"]), null)
})

// --- the band ---------------------------------------------------------------

test("appending a page cannot move an existing dot", () => {
    const items = Array.from({ length: 200 }, (_, i) => ({ url: "/content/" + i }))
    const first = L.layoutBand(items.slice(0, 10))
    const second = L.layoutBand(items.slice(0, 30))
    const third = L.layoutBand(items)
    assert.deepEqual(second.slice(0, 10), first)
    assert.deepEqual(third.slice(0, 30), second)
})

test("band dots sit outside the children and inside the boundary", () => {
    const dots = L.layoutBand(Array.from({ length: 50 }, (_, i) => ({ url: "/c" + i })))
    for (const dot of dots) {
        const radius = Math.hypot(dot.x, dot.y)
        assert.ok(radius > L.INNER_FILL, `dot at ${radius} is inside the packed children`)
        assert.ok(radius < 1, `dot at ${radius} is outside the boundary`)
    }
})

// --- contents shown inside a child group ------------------------------------

test("inside-a-group contents stay inside and never coincide", () => {
    for (const n of [1, 2, 3, 5, 10, 25]) {
        const dots = L.layoutInside(n)
        assert.equal(dots.length, n)
        for (const dot of dots) {
            const radius = Math.hypot(dot.x, dot.y)
            assert.ok(radius <= L.INSIDE_FILL + 1e-9,
                `n=${n}: a dot at ${radius} escapes INSIDE_FILL ${L.INSIDE_FILL}`)
        }
        for (let i = 0; i < dots.length; i++) {
            for (let j = i + 1; j < dots.length; j++) {
                const gap = Math.hypot(dots[i].x - dots[j].x, dots[i].y - dots[j].y)
                assert.ok(gap > 1e-6, `n=${n}: dots ${i} and ${j} coincide`)
            }
        }
    }
    assert.deepEqual(L.layoutInside(1), [{ index: 0, x: 0, y: 0 }])
    assert.deepEqual(L.layoutInside(0), [])
})

test("inside-a-group layout is deterministic (but not prefix-stable)", () => {
    assert.deepEqual(L.layoutInside(7), L.layoutInside(7))
    // Documented non-property: the ring radii depend on the count, which is
    // why this population is fetched in one go and never paged.
    assert.notDeepEqual(L.layoutInside(3), L.layoutInside(8).slice(0, 3))
})

