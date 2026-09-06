;(function () {
    "use strict"

    /**
     * AccessGate client: solves the proof-of-work challenge issued by
     * Service/access-gate-seed-service.php and stores the resulting token
     * in the `cv_gate` cookie (see Module/AccessGate.php for the protocol).
     *
     * Used by Client/AccessGate/challenge.html (full-page challenge) and by
     * XHR/fetch callers (e.g. ContentsViewer feedback) via ensureToken().
     */
    var AG = {}

    AG.COOKIE_NAME = "cv_gate"

    AG.isSupported = function () {
        return !!(window.crypto && window.crypto.subtle && window.TextEncoder)
    }

    AG.hasToken = function () {
        return document.cookie.indexOf(AG.COOKIE_NAME + "=") !== -1
    }

    /**
     * Finds a nonce such that sha256(seed + "." + nonce) has `bits` leading
     * zero bits. Resolves with the nonce (number). onProgress(attempts) is
     * called periodically.
     */
    AG.solve = async function (seed, bits, onProgress) {
        var encoder = new TextEncoder()
        var fullBytes = bits >> 3
        var restBits = bits & 7
        for (var nonce = 0; ; nonce++) {
            var data = encoder.encode(seed + "." + nonce)
            var digest = new Uint8Array(await crypto.subtle.digest("SHA-256", data))
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

    AG.setCookie = function (token) {
        // seed = v1.{expiry}.{bits}.{mac}; cookie lifetime follows the token expiry.
        var expiry = parseInt(token.split(".")[1], 10)
        var maxAge = Math.max(60, expiry - Math.floor(Date.now() / 1000))
        document.cookie = AG.COOKIE_NAME + "=" + token +
            "; path=/; max-age=" + maxAge + "; SameSite=Lax" +
            (location.protocol === "https:" ? "; Secure" : "")
        return AG.hasToken()
    }

    /**
     * Fetches a seed from `serviceUri` (e.g. CV.vars.serviceUri), solves the
     * proof-of-work and stores the cookie.
     * Resolves with {ok: true} or {ok: false, reason: string}.
     */
    AG.acquireToken = async function (serviceUri, onProgress) {
        if (!AG.isSupported()) return { ok: false, reason: "unsupported" }
        if (!navigator.cookieEnabled) return { ok: false, reason: "cookie-disabled" }
        var response
        try {
            response = await fetch(serviceUri + "/access-gate-seed-service.php", {
                method: "POST",
                cache: "no-store",
            })
        } catch (error) {
            return { ok: false, reason: "network" }
        }
        if (!response.ok) return { ok: false, reason: "seed-failed" }
        var body
        try {
            body = await response.json()
        } catch (error) {
            return { ok: false, reason: "seed-failed" }
        }
        if (!body || typeof body.seed !== "string") return { ok: false, reason: "seed-failed" }
        var nonce = await AG.solve(body.seed, body.bits, onProgress)
        if (!AG.setCookie(body.seed + "." + nonce)) {
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
