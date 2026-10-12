;(function () {
    "use strict"

    /**
     * AccessGate client: mints a self-verified v2 token locally and stores
     * it in the `cv_gate` cookie (see Module/AccessGate.php for the
     * protocol). No network access: the parameters (own IP, difficulty,
     * TTL, weekly beacon) arrive in the `cv_gp` cookie, set by Apache when
     * the static challenge page is served and by PHP on every 428 -- so a
     * caller that just received a 428 always holds fresh parameters.
     *
     * Used by XHR callers (ContentsViewer feedback, TagMap, Contact form)
     * via acquireToken()/ensureToken(). challenge.html inlines its own
     * copy of this solver to stay a self-contained static file.
     */
    var AG = {}

    AG.COOKIE_NAME = "cv_gate"
    AG.PARAMS_COOKIE_NAME = "cv_gp"

    // --- SHA-256, synchronous pure JS ------------------------------------
    // Tokens are short ASCII strings; a sync implementation hashes them 2-3
    // orders of magnitude faster than awaiting crypto.subtle per attempt.

    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ]

    /** sha256 of an ASCII string, returned as 8 big-endian int32 words. */
    function sha256Words(str) {
        var len = str.length
        var blocks = ((len + 8) >> 6) + 1
        var m = new Int32Array(blocks * 16)
        var i
        for (i = 0; i < len; i++) {
            m[i >> 2] |= (str.charCodeAt(i) & 0xff) << ((3 - (i & 3)) << 3)
        }
        m[i >> 2] |= 0x80 << ((3 - (i & 3)) << 3)
        m[blocks * 16 - 1] = len << 3 // bit length; inputs are far below 2^29 bytes

        var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a
        var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19
        var w = sha256Words.w || (sha256Words.w = new Int32Array(64))

        for (var off = 0; off < m.length; off += 16) {
            var t, x, y
            for (t = 0; t < 16; t++) {
                w[t] = m[off + t]
            }
            for (t = 16; t < 64; t++) {
                x = w[t - 15]
                y = w[t - 2]
                w[t] = (w[t - 16] +
                    (((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3)) +
                    w[t - 7] +
                    (((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10))) | 0
            }
            var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7
            for (t = 0; t < 64; t++) {
                var t1 = (h +
                    (((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7))) +
                    ((e & f) ^ (~e & g)) + K[t] + w[t]) | 0
                var t2 = ((((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10))) +
                    ((a & b) ^ (a & c) ^ (b & c))) | 0
                h = g; g = f; f = e; e = (d + t1) | 0
                d = c; c = b; b = a; a = (t1 + t2) | 0
            }
            h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0
            h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0
        }
        return [h0, h1, h2, h3, h4, h5, h6, h7]
    }

    function wordsToHex(words) {
        var hex = ""
        for (var i = 0; i < words.length; i++) {
            hex += ("00000000" + (words[i] >>> 0).toString(16)).slice(-8)
        }
        return hex
    }

    /** True when the big-endian word array starts with `bits` zero bits. */
    function hasLeadingZeroBits(words, bits) {
        var full = bits >> 5
        for (var i = 0; i < full; i++) {
            if (words[i] !== 0) return false
        }
        var rest = bits & 31
        return rest === 0 || (words[full] >>> (32 - rest)) === 0
    }

    // Self-check against a known vector once at load. A failure would mean
    // a broken implementation; fall back to crypto.subtle then, so a bug
    // here can never lock every visitor out.
    var sha256Usable = (function () {
        try {
            return wordsToHex(sha256Words("abc")) ===
                "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        } catch (error) {
            return false
        }
    })()

    function subtleUsable() {
        return !!(window.crypto && window.crypto.subtle && window.TextEncoder)
    }

    AG.isSupported = function () {
        return sha256Usable || subtleUsable()
    }

    // --- cookies ----------------------------------------------------------

    function readCookie(name) {
        var parts = document.cookie.split("; ")
        for (var i = 0; i < parts.length; i++) {
            if (parts[i].indexOf(name + "=") === 0) {
                return parts[i].slice(name.length + 1)
            }
        }
        return null
    }

    AG.hasToken = function () {
        return readCookie(AG.COOKIE_NAME) !== null
    }

    /**
     * Parses the `cv_gp` params cookie: {ip}|{bits}|{ttl}|{beacon}.
     * Returns null when absent or malformed.
     */
    AG.readParams = function () {
        var raw = readCookie(AG.PARAMS_COOKIE_NAME)
        if (!raw) return null
        var f = raw.split("|")
        if (f.length !== 4) return null
        var bits = parseInt(f[1], 10)
        var ttl = parseInt(f[2], 10)
        if (!f[0] || !(bits >= 1 && bits <= 64) || !(ttl >= 60) || !/^[0-9a-f]{8}$/.test(f[3])) {
            return null
        }
        return { ip: f[0], bits: bits, ttl: ttl, beacon: f[3] }
    }

    AG.setCookie = function (token) {
        // token = v2~{expiry}~...; cookie lifetime follows the token expiry.
        var expiry = parseInt(token.split("~")[1], 10)
        var maxAge = Math.max(60, expiry - Math.floor(Date.now() / 1000))
        document.cookie = AG.COOKIE_NAME + "=" + token +
            "; path=/; max-age=" + maxAge + "; SameSite=Lax" +
            (location.protocol === "https:" ? "; Secure" : "")
        return AG.hasToken()
    }

    // --- proof of work ----------------------------------------------------

    /**
     * Finds a nonce such that sha256(prefix + nonce) has `bits` leading
     * zero bits. Hashes synchronously in slices, yielding to the event
     * loop between slices; onProgress(attempts) is called per slice.
     */
    AG.solve = async function (prefix, bits, onProgress) {
        var SLICE = 16384
        for (var nonce = 0; ; ) {
            var end = nonce + SLICE
            for (; nonce < end; nonce++) {
                if (hasLeadingZeroBits(sha256Words(prefix + nonce), bits)) return nonce
            }
            if (onProgress) onProgress(nonce)
            await new Promise(function (resolve) { setTimeout(resolve, 0) })
        }
    }

    /** crypto.subtle variant, used only if the pure-JS self-check failed. */
    AG.solveSubtle = async function (prefix, bits, onProgress) {
        var encoder = new TextEncoder()
        var fullBytes = bits >> 3
        var restBits = bits & 7
        for (var nonce = 0; ; nonce++) {
            var digest = new Uint8Array(
                await crypto.subtle.digest("SHA-256", encoder.encode(prefix + nonce))
            )
            var ok = true
            for (var i = 0; i < fullBytes; i++) {
                if (digest[i] !== 0) { ok = false; break }
            }
            if (ok && restBits > 0 && (digest[fullBytes] >> (8 - restBits)) !== 0) {
                ok = false
            }
            if (ok) return nonce
            if (onProgress && (nonce & 255) === 0) onProgress(nonce)
        }
    }

    /**
     * Mints a v2 token from the cv_gp params and stores the cookie.
     * Resolves with {ok: true} or {ok: false, reason: string}.
     * serviceUri is accepted for caller compatibility but unused: minting
     * is fully local.
     */
    AG.acquireToken = async function (serviceUri, onProgress) {
        if (!AG.isSupported()) return { ok: false, reason: "unsupported" }
        if (!navigator.cookieEnabled) return { ok: false, reason: "cookie-disabled" }
        var params = AG.readParams()
        if (!params) {
            // No cv_gp yet: the next request will answer 428 and set it;
            // the caller's one-shot retry then succeeds.
            return { ok: false, reason: "params-missing" }
        }
        var expiry = Math.floor(Date.now() / 1000) + params.ttl
        var prefix = "v2~" + expiry + "~" + params.bits + "~" + params.beacon + "~" + params.ip + "~"
        var nonce = sha256Usable
            ? await AG.solve(prefix, params.bits, onProgress)
            : await AG.solveSubtle(prefix, params.bits, onProgress)
        if (!AG.setCookie(prefix + nonce)) {
            return { ok: false, reason: "cookie-disabled" }
        }
        return { ok: true }
    }

    /** acquireToken() unless a token cookie is already present. */
    AG.ensureToken = async function (serviceUri, onProgress) {
        if (AG.hasToken()) return { ok: true }
        return AG.acquireToken(serviceUri, onProgress)
    }

    window.AccessGate = AG
})()
