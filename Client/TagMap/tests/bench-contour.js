/*
 * What the contour costs, so the budget is measured rather than assumed.
 *
 * The design depends on one claim: contours are SCENE data, built once per
 * navigation, not FRAME data. This prints the numbers that claim rests on.
 *
 * Run: node Client/TagMap/tests/bench-contour.js
 */
"use strict"

const L = require("../tagmap-layout.js")

/** Group reaches shaped like real tag data: a few big, a long thin tail. */
function zipfReach(n) {
    return Array.from({ length: n }, (_, i) => ({
        tag: "tag" + i,
        reach: Math.max(1, Math.round(40 / (i + 1))),
    }))
}

/** A level with `perGroup` marks in each of `groups` territories. */
function level(groups, perGroup) {
    const children = zipfReach(groups)
    const layout = L.layoutAnchors(children)
    const levelR = L.levelRadiusFor(layout.packExtent, 0)
    const rows = []
    for (let g = 0; g < groups; g++) {
        for (let k = 0; k < perGroup; k++) rows.push(["g" + g + "-" + k, [g]])
    }
    return { anchors: layout.anchors, levelR, marks: L.placeMarks(rows, layout.anchors, { levelRadius: levelR }) }
}

function timed(label, runs, fn) {
    fn() // warm up
    const t0 = process.hrtime.bigint()
    for (let i = 0; i < runs; i++) fn()
    const ms = Number(process.hrtime.bigint() - t0) / 1e6 / runs
    console.log(`  ${label.padEnd(46)} ${ms.toFixed(2)} ms`)
    return ms
}

console.log("\n=== contour build, per level (this happens once per navigation) ===")
for (const [groups, perGroup] of [[25, 1], [25, 4], [106, 1], [106, 4], [200, 1], [200, 3]]) {
    const scene = level(groups, perGroup)
    let vertices = 0
    const ms = timed(`${groups} groups x ${perGroup} mark(s)`, 5, () => {
        vertices = 0
        for (let g = 0; g < scene.anchors.length; g++) {
            const members = scene.marks.filter((mark) => mark.group === g)
            if (members.length === 0) continue
            const r = L.kernelForPoints(members, scene.anchors[g].r)
            const contour = L.contour(members.map((m) => ({ x: m.x, y: m.y, r })))
            for (const ring of contour.rings) vertices += ring.length
        }
    })
    console.log(`  ${"".padEnd(46)} -> ${vertices} vertices, ${(ms / groups * 1000).toFixed(0)} us/group`)
}
console.log("  (compare against a 16.7 ms frame: this must stay well inside it,")
console.log("   and it is paid once per navigation rather than once per frame)")

console.log("\n=== placement, per level (also once per navigation) ===")
for (const [groups, perGroup] of [[106, 4], [200, 20]]) {
    const children = zipfReach(groups)
    const layout = L.layoutAnchors(children)
    const levelR = L.levelRadiusFor(layout.packExtent, 0)
    const rows = []
    for (let g = 0; g < groups; g++) {
        for (let k = 0; k < perGroup; k++) rows.push(["g" + g + "-" + k, [g]])
    }
    timed(`placeMarks: ${rows.length} marks over ${groups} groups`, 20,
        () => L.placeMarks(rows, layout.anchors, { levelRadius: levelR }))
}

console.log("\n=== hit testing (per pointer event) ===")
{
    const scene = level(20, 4)
    const members = scene.marks.filter((mark) => mark.group === 0)
    const r = L.kernelForPoints(members, scene.anchors[0].r)
    const contour = L.contour(members.map((m) => ({ x: m.x, y: m.y, r })))
    timed("pointInPolygon x 1000", 20, () => {
        for (let i = 0; i < 1000; i++) {
            L.pointInPolygon(contour.rings, (i % 40) - 20, (i % 31) - 15)
        }
    })
}

console.log("\n=== accuracy vs grid resolution (a lone mark, target radius PACK_K) ===")
for (const gridN of [17, 21, 25, 33]) {
    const target = L.territoryRadius(1)
    const contour = L.contour([{ x: 0, y: 0, r: L.kernelRadius(target, 1) }], { gridN })
    const radii = contour.rings[0].map((p) => Math.hypot(p.x, p.y))
    const min = Math.min(...radii), max = Math.max(...radii)
    console.log(`  gridN=${String(gridN).padEnd(3)} verts=${String(contour.rings[0].length).padEnd(4)}`
        + ` radius ${min.toFixed(3)}..${max.toFixed(3)} of ${target.toFixed(3)}`
        + `  error ${(((target - min) / target) * 100).toFixed(2)}%`)
}
console.log("")
