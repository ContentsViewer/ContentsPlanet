/*
 * Invariants of the nested-circle layout. These are the properties that
 * cannot be checked by looking at a screenshot -- determinism, non-overlap,
 * containment, band stability under paging, and the re-anchor identity that
 * makes entering a child visually continuous.
 *
 * Run: node --test Client/TagMap/tests/
 */
"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const L = require("../tagmap-layout.js")

// --- fixtures ---------------------------------------------------------------

function children(weights) {
    return weights.map((w, i) => ({ tag: "tag" + i, weight: w }))
}

/** Counts shaped like real tag data: a few big, a long thin tail. */
function zipf(n) {
    return children(Array.from({ length: n }, (_, i) => Math.max(1, Math.round(500 / (i + 1)))))
}

const DISTRIBUTIONS = {
    equal: (n) => children(Array.from({ length: n }, () => 7)),
    zipf,
    extreme: (n) => children(Array.from({ length: n }, (_, i) => (i === 0 ? 1000 : 1))),
    ascending: (n) => children(Array.from({ length: n }, (_, i) => i + 1)),
}
const SIZES = [1, 2, 3, 5, 17, 60, 200]

// --- determinism ------------------------------------------------------------

test("layoutChildren is deterministic and free of hidden state", () => {
    const input = zipf(40)
    const a = L.layoutChildren(input)
    const b = L.layoutChildren(input)
    assert.deepEqual(a.slots, b.slots)

    // No dependence on object identity: a JSON round-trip must give the same
    // answer, or the layout is keyed on something other than the data.
    const clone = JSON.parse(JSON.stringify(input))
    assert.deepEqual(L.layoutChildren(clone).slots, a.slots)
})

test("layout uses no clock and no randomness", () => {
    const realRandom = Math.random
    const realNow = Date.now
    Math.random = () => { throw new Error("Math.random must not be reachable") }
    Date.now = () => { throw new Error("Date.now must not be reachable") }
    try {
        L.layoutChildren(zipf(50))
        L.layoutBand(Array.from({ length: 30 }, (_, i) => ({ url: "/c" + i })))
        L.packSiblings([5, 3, 8, 1])
    } finally {
        Math.random = realRandom
        Date.now = realNow
    }
})

// --- non-overlap and containment -------------------------------------------

for (const [name, make] of Object.entries(DISTRIBUTIONS)) {
    for (const n of SIZES) {
        test(`slots never overlap and stay inside: ${name}, n=${n}`, () => {
            const slots = L.layoutChildren(make(n)).slots
            assert.equal(slots.length, n)

            for (const slot of slots) {
                assert.ok(Number.isFinite(slot.x) && Number.isFinite(slot.y) && Number.isFinite(slot.r),
                    `non-finite slot: ${JSON.stringify(slot)}`)
                assert.ok(slot.r > 0, `zero radius for ${slot.tag}`)
                const reach = Math.hypot(slot.x, slot.y) + slot.r
                assert.ok(reach <= L.INNER_FILL + 1e-9,
                    `${slot.tag} reaches ${reach} > INNER_FILL ${L.INNER_FILL}`)
            }

            for (let i = 0; i < slots.length; i++) {
                for (let j = i + 1; j < slots.length; j++) {
                    const a = slots[i], b = slots[j]
                    const distance = Math.hypot(a.x - b.x, a.y - b.y)
                    const touching = a.r + b.r
                    // Relative epsilon: front-chain packing lands tangent to
                    // within a few ULPs, so an absolute one is too strict.
                    assert.ok(distance >= touching - 1e-9 * touching,
                        `${a.tag} overlaps ${b.tag}: gap ${distance - touching}`)
                }
            }
        })
    }
}

test("the radius floor keeps the smallest child tappable", () => {
    const slots = L.layoutChildren(DISTRIBUTIONS.extreme(200)).slots
    const radii = slots.map((s) => s.r)
    const ratio = Math.min(...radii) / Math.max(...radii)
    assert.ok(ratio >= L.MIN_CHILD_RATIO - 1e-12, `ratio ${ratio} below the floor`)
})

test("area is monotone in weight above the floor", () => {
    const layout = L.layoutChildren(children([100, 50, 20, 10, 5]))
    const radii = layout.slots.map((s) => s.r)
    for (let i = 1; i < radii.length; i++) {
        assert.ok(radii[i] <= radii[i - 1] + 1e-12,
            `weight ${i} is smaller but its circle is bigger`)
    }
})

// --- OR fusion --------------------------------------------------------------

test("a fused segment is one circle containing every member", () => {
    const layout = L.layoutChildren(zipf(20))
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
    const layout = L.layoutChildren(zipf(5))
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

// --- the transform chain ----------------------------------------------------

/** A five-level chain from realistic payloads. */
function chainOfDepth(depth) {
    const slots = []
    let layout = L.layoutChildren(zipf(30))
    for (let i = 0; i < depth; i++) {
        const slot = layout.byTag["tag" + (i + 1)]
        slots.push(slot)
        layout = L.layoutChildren(zipf(30 - i * 4))
    }
    return slots.reverse() // nearest ancestor first
}

test("the current level is exactly radius 1 at every depth", () => {
    for (let depth = 0; depth <= 5; depth++) {
        const levels = L.composeChain(chainOfDepth(depth))
        assert.equal(levels[0].ak, 1)
        assert.equal(levels[0].ax, 0)
        assert.equal(levels[0].ay, 0)
    }
})

test("ancestor scales stay in a sane range and finite at depth 5", () => {
    const levels = L.composeChain(chainOfDepth(5), 3)
    assert.ok(levels.length >= 2, "no ancestors composed")
    for (const level of levels) {
        assert.ok(Number.isFinite(level.ak) && Number.isFinite(level.ax) && Number.isFinite(level.ay),
            `non-finite ancestor: ${JSON.stringify(level)}`)
        assert.ok(level.ak >= 1 && level.ak < 1e4, `ancestor scale ${level.ak} out of range`)
    }
})

test("a degenerate slot truncates the chain instead of producing Infinity", () => {
    const levels = L.composeChain([{ x: 0, y: 0, r: 0 }, { x: 0.1, y: 0.1, r: 0.2 }])
    assert.equal(levels.length, 1)
    for (const level of levels) assert.ok(Number.isFinite(level.ak))
})

// --- the continuity theorem -------------------------------------------------

test("entering a slot leaves every point exactly where it was on screen", () => {
    const view = { w: 1280, h: 800 }
    const layout = L.layoutChildren(zipf(25))
    const probes = []
    for (let i = 0; i < 20; i++) {
        probes.push({ x: Math.cos(i) * 0.7, y: Math.sin(i * 1.7) * 0.7 })
    }

    for (const tag of Object.keys(layout.byTag)) {
        const slot = layout.byTag[tag]
        const before = { cx: 0.13, cy: -0.07, k: 431.5 }
        const after = L.reanchorCamera(before, slot, "in")
        const current = { depth: 0, ax: 0, ay: 0, ak: 1 }

        for (const probe of probes) {
            const was = L.project(current, before, view, probe.x, probe.y)
            // The same world point, expressed in the child's space.
            const inChild = { x: (probe.x - slot.x) / slot.r, y: (probe.y - slot.y) / slot.r }
            const now = L.project(current, after, view, inChild.x, inChild.y)
            assert.ok(Math.abs(was.x - now.x) < 1e-6 && Math.abs(was.y - now.y) < 1e-6,
                `${tag}: point moved by ${Math.hypot(was.x - now.x, was.y - now.y)}px`)
        }

        // Radii must be preserved too, or the circle would visibly jump size.
        const wasR = L.projectRadius(current, before, slot.r)
        const nowR = L.projectRadius(current, after, 1)
        assert.ok(Math.abs(wasR - nowR) < 1e-6, `${tag}: radius moved by ${wasR - nowR}px`)
    }
})

test("entering then leaving restores the camera exactly", () => {
    const layout = L.layoutChildren(zipf(12))
    for (const tag of Object.keys(layout.byTag)) {
        const slot = layout.byTag[tag]
        const before = { cx: -0.21, cy: 0.44, k: 288.125 }
        const round = L.reanchorCamera(L.reanchorCamera(before, slot, "in"), slot, "out")
        assert.ok(Math.abs(round.cx - before.cx) < 1e-12, `cx drifted by ${round.cx - before.cx}`)
        assert.ok(Math.abs(round.cy - before.cy) < 1e-12, `cy drifted by ${round.cy - before.cy}`)
        assert.ok(Math.abs(round.k - before.k) < 1e-9, `k drifted by ${round.k - before.k}`)
    }
})

test("a fused segment is enterable with the same continuity", () => {
    const view = { w: 900, h: 900 }
    const layout = L.layoutChildren(zipf(15))
    const fused = L.slotForSegment(layout, ["tag0", "tag3"])
    const before = L.fitCamera(view.w, view.h, 0.86)
    const after = L.reanchorCamera(before, fused, "in")
    const current = { depth: 0, ax: 0, ay: 0, ak: 1 }

    const was = L.project(current, before, view, fused.x, fused.y)
    const now = L.project(current, after, view, 0, 0)
    assert.ok(Math.hypot(was.x - now.x, was.y - now.y) < 1e-6, "the fused circle jumped")
    const wasR = L.projectRadius(current, before, fused.r)
    const nowR = L.projectRadius(current, after, 1)
    assert.ok(Math.abs(wasR - nowR) < 1e-6, "the fused radius jumped")
})
