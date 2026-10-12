// Tests for Client/AccessGate/access-gate.js (v2 self-minted tokens).
//
// The solver's pure-JS SHA-256 is validated by equivalence: every nonce it
// finds must satisfy the leading-zero-bits condition under Node's reference
// crypto implementation. A wrong SHA-256 would find "solutions" that fail
// this check (or never find one).
//
// Run: node --test Client/AccessGate/tests/   (cwd = www/ContentsPlanet)

"use strict"

const test = require("node:test")
const assert = require("node:assert/strict")
const fs = require("node:fs")
const path = require("node:path")
const crypto = require("node:crypto")

// --- load access-gate.js into a minimal fake browser environment ---------

function makeEnvironment() {
    const jar = {}
    const document = {}
    Object.defineProperty(document, "cookie", {
        get() {
            return Object.keys(jar).map((k) => k + "=" + jar[k]).join("; ")
        },
        set(value) {
            const pair = value.split(";")[0]
            const eq = pair.indexOf("=")
            jar[pair.slice(0, eq)] = pair.slice(eq + 1)
        },
    })
    const window = {}
    const env = {
        window,
        document,
        navigator: { cookieEnabled: true },
        location: { protocol: "https:" },
        jar,
    }
    const source = fs.readFileSync(path.join(__dirname, "..", "access-gate.js"), "utf8")
    new Function("window", "document", "navigator", "location", source)(
        window, document, env.navigator, env.location
    )
    env.AG = window.AccessGate
    return env
}

function leadingZeroBitsOk(buffer, bits) {
    const full = bits >> 3
    for (let i = 0; i < full; i++) {
        if (buffer[i] !== 0) return false
    }
    const rest = bits & 7
    return rest === 0 || buffer[full] >> (8 - rest) === 0
}

function sha256(input) {
    return crypto.createHash("sha256").update(input).digest()
}

// --- tests ----------------------------------------------------------------

test("pure-JS sha256 self-check passes (no subtle fallback in Node)", () => {
    const env = makeEnvironment()
    // Node has no window.crypto here, so isSupported() is true only if the
    // built-in implementation verified itself against the known vector.
    assert.equal(env.AG.isSupported(), true)
})

test("solve finds a nonce valid under Node's reference SHA-256 (single block)", async () => {
    const env = makeEnvironment()
    const prefix = "v2~1760000000~12~a1b2c3d4~203.0.113.5~"
    assert.ok(prefix.length < 56, "stays single-block to exercise that path")
    const nonce = await env.AG.solve(prefix, 12)
    assert.ok(leadingZeroBitsOk(sha256(prefix + nonce), 12))
})

test("solve is correct across the 64-byte block boundary (two blocks)", async () => {
    const env = makeEnvironment()
    const prefix = "v2~1760000000~12~a1b2c3d4~2001:db8:aaaa:bbbb:cccc:dddd:eeee:ffff~"
    assert.ok(prefix.length > 64, "forces two-block hashing")
    const nonce = await env.AG.solve(prefix, 12)
    assert.ok(leadingZeroBitsOk(sha256(prefix + nonce), 12))
})

test("solve respects non-byte-aligned difficulties", async () => {
    const env = makeEnvironment()
    const prefix = "v2~1760000000~11~00000000~192.0.2.1~"
    const nonce = await env.AG.solve(prefix, 11)
    assert.ok(leadingZeroBitsOk(sha256(prefix + nonce), 11))
})

test("readParams parses a well-formed cv_gp and rejects malformed ones", () => {
    const env = makeEnvironment()
    env.jar.cv_gp = "203.0.113.5|20|86400|a1b2c3d4"
    assert.deepEqual(env.AG.readParams(), {
        ip: "203.0.113.5", bits: 20, ttl: 86400, beacon: "a1b2c3d4",
    })

    for (const bad of [
        "203.0.113.5|20|86400",            // missing beacon
        "203.0.113.5|20|86400|xyz",        // beacon not 8 hex
        "203.0.113.5|0|86400|a1b2c3d4",    // bits out of range
        "203.0.113.5|20|10|a1b2c3d4",      // ttl too small
        "|20|86400|a1b2c3d4",              // empty ip
    ]) {
        env.jar.cv_gp = bad
        assert.equal(env.AG.readParams(), null, "accepted: " + bad)
    }

    delete env.jar.cv_gp
    assert.equal(env.AG.readParams(), null)
})

test("acquireToken mints a verifiable v2 token from cv_gp", async () => {
    const env = makeEnvironment()
    env.jar.cv_gp = "198.51.100.7|10|86400|0badf00d"
    const before = Math.floor(Date.now() / 1000)
    const result = await env.AG.acquireToken(null)
    assert.deepEqual(result, { ok: true })

    const token = env.jar.cv_gate
    const fields = token.split("~")
    assert.equal(fields.length, 6)
    assert.equal(fields[0], "v2")
    const expiry = parseInt(fields[1], 10)
    assert.ok(expiry >= before + 86400 && expiry <= before + 86400 + 5, "expiry = now + ttl")
    assert.equal(fields[2], "10")
    assert.equal(fields[3], "0badf00d")
    assert.equal(fields[4], "198.51.100.7")
    assert.ok(leadingZeroBitsOk(sha256(token), 10), "proof of work holds for the whole token")
})

test("acquireToken without cv_gp reports params-missing (the 428 retry path supplies it)", async () => {
    const env = makeEnvironment()
    const result = await env.AG.acquireToken(null)
    assert.deepEqual(result, { ok: false, reason: "params-missing" })
})

test("ensureToken is a no-op when a token cookie exists", async () => {
    const env = makeEnvironment()
    env.jar.cv_gate = "v2~1~1~00000000~1.2.3.4~0"
    assert.deepEqual(await env.AG.ensureToken(null), { ok: true })
})
