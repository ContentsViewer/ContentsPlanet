/*
 * Placing marks: one per (content, group) pair.
 *
 * This file used to assert the opposite -- that a content shared by two
 * groups got exactly ONE mark, as a property of a partition. That property
 * was achieved and then given up on purpose. A single mark has to sit between
 * its groups, which stretches both outlines toward it, and on the reference
 * corpus (192 contents, 106 groups) every one of the 5565 outline pairs then
 * crossed: the level was unreadable. Duplicating the mark brings that to
 * zero, and identity moves out of the geometry into the `key`, where the
 * instances of one content can be tied together on demand.
 *
 * What replaces "exactly once" is stronger than it sounds: every mark is
 * inside its own group, and no two marks ever overlap -- provably, with no
 * relaxation pass at all.
 *
 * Run: node --test Client/TagMap/tests/
 */
"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const L = require("../tagmap-layout.js")

/**
 * A membership manifest shaped like the server's: `[[key, [group, ...]], ...]`
 * mostly solo, some shared, some direct.
 */
function manifest(contentCount, groupCount) {
    const rows = []
    for (let i = 0; i < contentCount; i++) {
        const key = "c" + i
        const pick = (...offsets) => Array.from(new Set(offsets.map((o) => (i + o) % groupCount)))
        if (i % 7 === 0) rows.push([key, []])                       // direct
        else if (i % 5 === 0) rows.push([key, pick(0, 3)])
        else if (i % 11 === 0) rows.push([key, pick(1, 2, 5)])
        else rows.push([key, pick(0)])
    }
    return rows
}

/**
 * A level whose group reaches are DERIVED from its manifest, so the fixture
 * cannot claim a group holds fewer contents than the manifest puts in it.
 * The server guarantees that relation (reach is the child view's content
 * count), and a fixture that breaks it measures the containment guard rather
 * than the layout.
 */
function level(groups, contents) {
    const rows = manifest(contents === undefined ? groups * 4 : contents, groups)
    const counts = new Array(groups).fill(0)
    let direct = 0
    for (const row of rows) {
        const known = row[1].filter((g) => g >= 0 && g < groups)
        if (known.length === 0) direct++
        for (const g of known) counts[g]++
    }
    const children = counts.map((count, i) => ({ tag: "g" + i, reach: Math.max(1, count) }))
    const layout = L.layoutAnchors(children)
    return Object.assign({
        rows, children, direct,
        levelR: L.levelRadiusFor(layout.packExtent, direct),
    }, layout)
}

/** Just the territories, for tests that do not care about the level. */
function territories(n) {
    return level(n).anchors
}

// --- one mark per membership -------------------------------------------------

test("a content gets one mark in each of its groups, and no others", () => {
    for (const [contents, groups] of [[9, 3], [29, 21], [192, 106], [400, 60]]) {
        const lvl = level(groups, contents)
        const rows = lvl.rows
        const marks = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })

        let expected = 0
        rows.forEach((row) => {
            const known = row[1].filter((g) => lvl.anchors[g])
            expected += known.length === 0 ? 1 : known.length
        })
        assert.equal(marks.length, expected,
            `${contents} contents over ${groups} groups: expected ${expected} marks`)

        // Every mark is in a group the content actually carries, and every
        // membership produced exactly one mark.
        const seen = new Set()
        for (const mark of marks) {
            assert.ok(Number.isFinite(mark.x) && Number.isFinite(mark.y))
            const pair = mark.content + ":" + mark.group
            assert.ok(!seen.has(pair), `duplicate mark for ${pair}`)
            seen.add(pair)
            if (mark.group >= 0) {
                assert.ok(rows[mark.content][1].indexOf(mark.group) >= 0,
                    `mark placed in a group its content does not carry`)
            } else {
                assert.ok(rows[mark.content][1].every((g) => !lvl.anchors[g]),
                    `a content with a known group was sent to the band`)
            }
        }
        // Nothing is dropped: every content appears at least once.
        assert.equal(new Set(marks.map((m) => m.content)).size, contents)
    }
})

test("every instance of one content carries the same key", () => {
    // The identity duplication gave up in the geometry has to survive
    // somewhere, or the instances cannot be tied together, tweened across a
    // level change, or counted.
    const lvl = level(6)
    const rows = [["shared", [0, 1, 2]], ["solo", [3]], ["direct", []]]
    const marks = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })

    const shared = marks.filter((m) => m.key === "shared")
    assert.equal(shared.length, 3)
    assert.deepEqual(shared.map((m) => m.group).sort((a, b) => a - b), [0, 1, 2])
    assert.equal(marks.filter((m) => m.key === "solo").length, 1)
    assert.equal(marks.filter((m) => m.key === "direct").length, 1)
    assert.equal(marks.find((m) => m.key === "direct").inBand, true)
})

test("out-of-cap and direct contents both ring the band", () => {
    const lvl = level(4, 3)
    // -1 means "its group is past the response's cap", which is not the same
    // as "it has no group" -- but both belong to the level rather than to a
    // territory, so both ring the band.
    const rows = [["a", []], ["b", [-1]], ["c", [0]]]
    const marks = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })
    const band = marks.filter((m) => m.inBand)
    assert.equal(band.length, 2)
    assert.deepEqual(band.map((m) => m.key), ["a", "b"])
    for (const mark of band) {
        const reach = Math.hypot(mark.x, mark.y) / lvl.levelR
        assert.ok(reach > L.BAND_RINGS[0] - 1e-9 && reach < L.BAND_RINGS[2] + 1e-9,
            `band mark at ${reach} of the level radius`)
    }
    // The two band marks must not be stacked on each other.
    assert.ok(Math.hypot(band[0].x - band[1].x, band[0].y - band[1].y) > L.CONTENT_PITCH)
})

// --- containment and separation ----------------------------------------------

test("every mark is inside its own group", () => {
    for (const [contents, groups] of [[29, 21], [192, 106], [400, 60]]) {
        const lvl = level(groups, contents)
        const marks = L.placeMarks(lvl.rows, lvl.anchors, { levelRadius: lvl.levelR })
        for (const mark of marks) {
            if (mark.inBand) continue
            const anchor = lvl.anchors[mark.group]
            const inside = Math.hypot(mark.x - anchor.x, mark.y - anchor.y)
            assert.ok(inside <= anchor.r + 1e-9,
                `${mark.key} sits ${inside} from its group's centre, radius ${anchor.r}`)
        }
    }
})

test("two marks never overlap, with no relaxation pass at all", () => {
    // This is what replaces the old relax() and its honest disclaimer that it
    // could not promise a bound. The sunflower inside a group is at least
    // 1.02 CONTENT_PITCH apart at every size, and the groups are disjoint, so
    // the bound is a property of the construction rather than of a budget.
    for (const [contents, groups] of [[29, 21], [192, 106], [400, 60]]) {
        const lvl = level(groups, contents)
        const marks = L.placeMarks(lvl.rows, lvl.anchors, { levelRadius: lvl.levelR })
        let closest = Infinity
        for (let i = 0; i < marks.length; i++) {
            for (let j = i + 1; j < marks.length; j++) {
                const d = Math.hypot(marks[i].x - marks[j].x, marks[i].y - marks[j].y)
                if (d < closest) closest = d
            }
        }
        assert.ok(closest >= 2 * L.CONTENT_R,
            `${contents}/${groups}: two marks are ${closest} apart, discs of radius ${L.CONTENT_R} overlap`)
    }
})

test("the sunflower alone keeps a group's marks a pitch apart", () => {
    // Measured across sizes rather than argued: the worst case is 1.02
    // pitches, and it is flat above four marks.
    for (const count of [2, 3, 4, 5, 7, 10, 17, 33, 65, 400, 1000]) {
        const spread = L.spreadForCell(count)
        const points = Array.from({ length: count }, (_, i) => {
            const local = L.insideSlot(i, count)
            return { x: local.x * spread, y: local.y * spread }
        })
        let closest = Infinity
        for (let i = 0; i < count; i++) {
            for (let j = i + 1; j < count; j++) {
                const d = Math.hypot(points[i].x - points[j].x, points[i].y - points[j].y)
                if (d < closest) closest = d
            }
        }
        assert.ok(closest >= L.CONTENT_PITCH,
            `${count} marks: closest pair ${closest}, pitch ${L.CONTENT_PITCH}`)
    }
})

test("a group's marks occupy the room its OWN count needs", () => {
    // Not the room its reach needs: when fewer contents are known than the
    // group's reach, the shape states what is known while the label still
    // states the count.
    const lvl = level(8)
    const anchor = lvl.anchors[0]
    for (const known of [1, 4, 25]) {
        const rows = Array.from({ length: known }, (_, i) => ["k" + i, [0]])
        const marks = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })
        const furthest = Math.max(...marks.map((m) => Math.hypot(m.x - anchor.x, m.y - anchor.y)))
        assert.ok(furthest <= L.spreadForCell(known) + 1e-9,
            `${known} marks reached ${furthest}, budget ${L.spreadForCell(known)}`)
    }
})

// --- the anonymous-to-real invariant ------------------------------------------

test("a content lands exactly where its anonymous stand-in was", () => {
    const lvl = level(5)
    const groupIndex = 2
    const count = 9
    const marks = L.anonymousMarks(count, lvl.anchors[groupIndex])
    assert.equal(marks.length, count)

    const rows = Array.from({ length: count }, (_, i) => ["c" + i, [groupIndex]])
    const real = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })

    for (let i = 0; i < count; i++) {
        assert.ok(Math.abs(real[i].x - marks[i].x) < 1e-12
            && Math.abs(real[i].y - marks[i].y) < 1e-12,
            `content ${i} moved: mark (${marks[i].x}, ${marks[i].y}) `
            + `vs real (${real[i].x}, ${real[i].y})`)
    }
})

test("capping the marks preserves the group's mass", () => {
    const anchor = territories(3)[0]
    const capped = L.anonymousMarks(500, anchor, 40)
    assert.equal(capped.length, 40)
    const mass = capped.reduce((sum, m) => sum + m.w, 0)
    assert.ok(Math.abs(mass - 500) < 1e-9, `mass ${mass}, expected 500`)
    for (const mark of capped) assert.equal(mark.anonymous, true)
})

test("insideSlot is prefix-stable in the index for a fixed capacity", () => {
    const capacity = 12
    const few = L.layoutInside(4, capacity)
    const many = L.layoutInside(capacity, capacity)
    assert.deepEqual(few, many.slice(0, 4))
})

// --- determinism --------------------------------------------------------------

test("placement is deterministic and free of hidden state", () => {
    const lvl = level(12, 60)
    const rows = lvl.rows
    const once = L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR })
    assert.deepEqual(L.placeMarks(rows, lvl.anchors, { levelRadius: lvl.levelR }), once)
    // No dependence on object identity.
    assert.deepEqual(
        L.placeMarks(JSON.parse(JSON.stringify(rows)), lvl.anchors, { levelRadius: lvl.levelR }),
        once)
})

test("placement uses no clock and no randomness", () => {
    const realRandom = Math.random
    const realNow = Date.now
    Math.random = () => { throw new Error("Math.random must not be reachable") }
    Date.now = () => { throw new Error("Date.now must not be reachable") }
    try {
        const lvl = level(8, 40)
        L.placeMarks(lvl.rows, lvl.anchors, { levelRadius: lvl.levelR })
        L.anonymousMarks(20, lvl.anchors[0], 10)
        L.spreadForCell(7)
        L.levelRadiusFor(30, 4)
    } finally {
        Math.random = realRandom
        Date.now = realNow
    }
})

// --- the outlines stay apart --------------------------------------------------

test("a group's contour contains all of its own marks", () => {
    const lvl = level(6, 40)
    const marks = L.placeMarks(lvl.rows, lvl.anchors, { levelRadius: lvl.levelR })
    for (let g = 0; g < lvl.anchors.length; g++) {
        const members = marks.filter((m) => m.group === g)
        if (members.length === 0) continue
        const r = L.kernelForPoints(members, lvl.anchors[g].r)
        const outline = L.contour(members.map((m) => ({ x: m.x, y: m.y, r })))
        for (const mark of members) {
            assert.ok(L.pointInPolygon(outline.rings, mark.x, mark.y),
                `group ${g}: a mark lies outside its own contour`)
        }
    }
})

test("no two contours overlap, which is what duplication bought", () => {
    // The claim the reversal rests on. With one mark per content the shared
    // marks sat between their groups and every outline crossed every other;
    // with a mark per membership each outline stays inside its own territory.
    // If this ever fails, the level has gone back to being a hairball.
    const groups = 40
    const lvl = level(groups, 120)
    const marks = L.placeMarks(lvl.rows, lvl.anchors, { levelRadius: lvl.levelR })

    const outlines = []
    for (let g = 0; g < lvl.anchors.length; g++) {
        const members = marks.filter((m) => m.group === g)
        if (members.length === 0) continue
        const r = L.kernelForPoints(members, lvl.anchors[g].r)
        outlines.push({ g, rings: L.contour(members.map((m) => ({ x: m.x, y: m.y, r }))).rings })
    }

    let crossing = 0
    for (let i = 0; i < outlines.length; i++) {
        for (let j = i + 1; j < outlines.length; j++) {
            const a = outlines[i], b = outlines[j]
            const hits = (from, into) => from.rings.some(
                (ring) => ring.some((v) => L.pointInPolygon(into.rings, v.x, v.y)))
            if (hits(a, b) || hits(b, a)) crossing++
        }
    }
    assert.equal(crossing, 0,
        `${crossing} of ${(outlines.length * (outlines.length - 1)) / 2} outline pairs overlap`)
})
