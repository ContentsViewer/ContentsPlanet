<?php

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");

/**
 * Stateless client-attestation gate.
 *
 * Protects configured URIs (and services) against automated mass access by
 * requiring an IP-bound, expiring token whose creation costs a SHA-256
 * proof-of-work. Verification is stateless: no per-client storage, a few
 * hashes per request.
 *
 * v2 tokens are SELF-MINTED: the server never issues anything per client.
 * It only tells the client its own address and the PoW parameters via the
 * `cv_gp` cookie (set by Apache when the static challenge page is served,
 * and by PHP on every 428), and verifies the result. Every self-declared
 * field is checked directly at verification time, and the PoW seals them
 * all: changing one character of the token invalidates the work.
 *
 * Token format (cookie `cv_gate`):
 *   v2~{expiry}~{bits}~{beacon}~{ip}~{nonce}
 *     expiry: unix seconds, client-chosen; accepted only within
 *             (now, now + TTL + CLOCK_SKEW]
 *     bits:   leading zero bits of sha256(token); must be >= configured
 *     beacon: epochBeacon() of this or the previous week -- an
 *             unpredictable weekly value, so tokens cannot be stockpiled
 *             for far-future validity windows
 *     ip:     the address the client was told via `cv_gp`; must match
 *             REMOTE_ADDR under ipKey() (IPv4 /32, IPv6 /64)
 *     nonce:  decimal solution found by the client
 *
 * Params cookie `cv_gp` (plain hint, carries no authority; `|`-separated
 * because RFC 6265 forbids commas in cookie values):
 *   {REMOTE_ADDR}|{bits}|{ttl}|{beacon}
 *
 * Configuration (ContentsPlanet.php):
 *   ACCESS_GATE_SECRET         enables the gate (empty/undefined = fail-open)
 *                              and keys the weekly beacon; rotating it
 *                              invalidates every outstanding token at once
 *   ACCESS_GATE_POW_BITS       proof-of-work difficulty (default 16)
 *   ACCESS_GATE_TOKEN_TTL      token lifetime in seconds (default 86400)
 *   ACCESS_GATE_PROTECTED_URIS list of URI substrings to protect (e.g. [':tagmap'])
 */
final class AccessGate
{
    public const COOKIE_NAME = 'cv_gate';
    public const PARAMS_COOKIE_NAME = 'cv_gp';
    public const TOKEN_VERSION_V2 = 'v2';
    public const DEFAULT_POW_BITS = 16;
    public const DEFAULT_TOKEN_TTL = 86400;
    /** Tolerated client clock skew when validating a self-chosen expiry. */
    public const CLOCK_SKEW = 3600;
    /** Width of one beacon generation. */
    private const BEACON_PERIOD = 7 * 86400;

    /** Path of the challenge page, relative to ROOT_DIR (and to the docroot in rewrites). */
    public const CHALLENGE_PATH = 'Client/AccessGate/challenge.html';

    private function __construct()
    {
    }

    public static function isConfigured(): bool
    {
        return defined('ACCESS_GATE_SECRET') && ACCESS_GATE_SECRET !== '';
    }

    public static function powBits(): int
    {
        return defined('ACCESS_GATE_POW_BITS') ? (int)ACCESS_GATE_POW_BITS : self::DEFAULT_POW_BITS;
    }

    public static function tokenTtl(): int
    {
        return defined('ACCESS_GATE_TOKEN_TTL') ? (int)ACCESS_GATE_TOKEN_TTL : self::DEFAULT_TOKEN_TTL;
    }

    /** @return string[] */
    public static function protectedUris(): array
    {
        return defined('ACCESS_GATE_PROTECTED_URIS') && is_array(ACCESS_GATE_PROTECTED_URIS)
            ? ACCESS_GATE_PROTECTED_URIS : [];
    }

    /**
     * Entry point called from index.php before any other module is loaded.
     * Returns silently when the gate is disabled or the request is not for
     * a protected URI; otherwise verifies the token and, on failure, serves
     * the challenge page and exits.
     */
    public static function handle(): void
    {
        if (!self::isConfigured()) {
            return;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') {
            return;
        }
        // Match on the percent-decoded path: Apache rewrites see the decoded
        // URI, and index.php routes on a urldecoded subURI, so an encoded
        // request (e.g. %3Atagmap) would otherwise bypass the gate while
        // still reaching the protected route.
        $path = rawurldecode(strtok($uri, '?'));
        if (!self::isProtectedPath($path)) {
            return;
        }
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (self::verifyToken($token, $_SERVER['REMOTE_ADDR'] ?? '')) {
            return;
        }
        self::logSampled('AccessGate: challenge issued for ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . $path);
        self::respondChallenge();
    }

    /**
     * Verifies a token for the given client address.
     */
    public static function verifyToken(string $token, string $remoteAddr): bool
    {
        if (!self::isConfigured()) {
            return true; // fail-open by design
        }
        if (!self::verifyTokenV2($token, $remoteAddr)) {
            return false;
        }
        self::logSampled('AccessGate: token verified for ' . $remoteAddr);
        return true;
    }

    /**
     * v2: self-minted token. Every self-declared field is checked against
     * server-side truth, cheapest first: format, expiry window, difficulty
     * floor, weekly beacon, IP binding, then the proof-of-work itself.
     */
    private static function verifyTokenV2(string $token, string $remoteAddr): bool
    {
        if (!preg_match('/^v2~(\d{10})~(\d{1,2})~([0-9a-f]{8})~([0-9a-fA-F:.]{2,45})~(\d{1,16})$/', $token, $m)) {
            return false;
        }
        $expiry = (int)$m[1];
        $now = time();
        // The expiry is client-chosen: cap how far ahead it may lie, so the
        // effective lifetime stays TTL (+ clock-skew allowance).
        if ($expiry <= $now || $expiry > $now + self::tokenTtl() + self::CLOCK_SKEW) {
            return false;
        }
        // Raising ACCESS_GATE_POW_BITS instantly invalidates weaker tokens.
        if ((int)$m[2] < self::powBits()) {
            return false;
        }
        if (!hash_equals(self::epochBeacon(), $m[3]) && !hash_equals(self::epochBeacon(-1), $m[3])) {
            return false;
        }
        $ipKey = self::ipKey($remoteAddr);
        if ($ipKey === null || $ipKey !== self::ipKey($m[4])) {
            return false;
        }
        return self::hasLeadingZeroBits(hash('sha256', $token, true), (int)$m[2]);
    }

    /**
     * Weekly generation beacon embedded in every v2 token. Future values
     * are unpredictable without the secret, which bounds how far ahead
     * tokens can be pre-computed (~2 generations, since the previous one
     * is also accepted as grace across the weekly flip).
     */
    public static function epochBeacon(int $weekOffset = 0): string
    {
        return substr(self::sign('epoch|' . (intdiv(time(), self::BEACON_PERIOD) + $weekOffset)), 0, 8);
    }

    /**
     * Tells the client its own address and the current PoW parameters via
     * the `cv_gp` cookie. Pure hint: verification never reads it. Sent raw
     * (setrawcookie) so the value matches the one Apache sets statically.
     */
    public static function emitParamsCookie(): void
    {
        if (!self::isConfigured() || headers_sent()) {
            return;
        }
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteAddr === '') {
            return;
        }
        $value = $remoteAddr . '|' . self::powBits() . '|' . self::tokenTtl() . '|' . self::epochBeacon();
        if (($_COOKIE[self::PARAMS_COOKIE_NAME] ?? '') === $value) {
            return;
        }
        setrawcookie(self::PARAMS_COOKIE_NAME, $value, [
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => defined('REDIRECT_HTTPS_ENABLED') && REDIRECT_HTTPS_ENABLED,
        ]);
    }

    /** Sampled notice (1/1000): logging locks the log file and must not serialize hot paths. */
    private static function logSampled(string $message): void
    {
        if (mt_rand(1, 1000) === 1 && defined('MODULE_DIR')) {
            require_once(MODULE_DIR . '/Logger.php');
            logger()->notice($message);
        }
    }

    /**
     * HMAC-SHA256 signing primitive (truncated to 128 bits, hex).
     * Keys the weekly beacon; exposed for reuse by other token schemes.
     */
    public static function sign(string $message): string
    {
        return substr(hash_hmac('sha256', $message, ACCESS_GATE_SECRET), 0, 32);
    }

    /**
     * Key identifying the client network location:
     * IPv4 -> full /32; IPv6 -> /64 prefix (absorbs privacy-address rotation);
     * IPv4-mapped IPv6 -> the embedded IPv4 (otherwise all mapped clients
     * would collapse into a single /64). Returns null for unparsable input.
     */
    private static function ipKey(string $remoteAddr): ?string
    {
        $bin = @inet_pton($remoteAddr);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 4) {
            return bin2hex($bin);
        }
        if (strlen($bin) === 16) {
            if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                return bin2hex(substr($bin, 12, 4));
            }
            return bin2hex(substr($bin, 0, 8));
        }
        return null;
    }

    private static function isProtectedPath(string $path): bool
    {
        foreach (self::protectedUris() as $needle) {
            if ($needle !== '' && stripos($path, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function hasLeadingZeroBits(string $rawHash, int $bits): bool
    {
        $fullBytes = $bits >> 3;
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($rawHash[$i] !== "\x00") {
                return false;
            }
        }
        $rest = $bits & 7;
        if ($rest > 0 && (ord($rawHash[$fullBytes]) >> (8 - $rest)) !== 0) {
            return false;
        }
        return true;
    }

    /**
     * Serves the challenge page inline at the requested URL and exits.
     * 428 makes gate re-challenges distinguishable from the static rewrite
     * (200) in access logs, and is treated as an error by API clients.
     */
    private static function respondChallenge(): never
    {
        // The 428 is the "teach" moment: hand out fresh PoW parameters so
        // the client can mint locally -- this also refreshes a stale cv_gp
        // after the client's address changed.
        self::emitParamsCookie();
        http_response_code(428);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $file = defined('ROOT_DIR') ? ROOT_DIR . '/' . self::CHALLENGE_PATH : '';
        if ($file === '' || !is_file($file) || @readfile($file) === false) {
            echo '<!doctype html><meta charset="utf-8"><p>Please reload this page.</p>';
        }
        exit;
    }
}
