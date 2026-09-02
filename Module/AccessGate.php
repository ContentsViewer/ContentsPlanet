<?php

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");

/**
 * Stateless client-attestation gate.
 *
 * Protects configured URIs (and services) against automated mass access by
 * requiring a signed, IP-bound, expiring token whose acquisition costs a
 * SHA-256 proof-of-work. Verification is stateless: no per-client storage,
 * one HMAC and one hash per request.
 *
 * Token format (cookie `cv_gate`):
 *   v1.{expiry}.{bits}.{mac}.{nonce}
 *     expiry: unix seconds (issued as time() + ACCESS_GATE_TOKEN_TTL)
 *     bits:   required leading zero bits of sha256(token)
 *     mac:    first 32 hex chars of HMAC-SHA256("v1|{ipKey}|{expiry}|{bits}", secret)
 *     nonce:  decimal solution found by the client
 *
 * Configuration (ContentsPlanet.php):
 *   ACCESS_GATE_SECRET         HMAC key; empty/undefined disables the gate (fail-open)
 *   ACCESS_GATE_POW_BITS       proof-of-work difficulty (default 16)
 *   ACCESS_GATE_TOKEN_TTL      token lifetime in seconds (default 86400)
 *   ACCESS_GATE_PROTECTED_URIS list of URI substrings to protect (e.g. [':tagmap'])
 */
final class AccessGate
{
    public const COOKIE_NAME = 'cv_gate';
    public const TOKEN_VERSION = 'v1';
    public const DEFAULT_POW_BITS = 16;
    public const DEFAULT_TOKEN_TTL = 86400;

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
        // Sampled logging only: writing takes an exclusive lock on the log
        // file, which must not become a serialization point under load.
        if (mt_rand(1, 1000) === 1 && defined('MODULE_DIR')) {
            require_once(MODULE_DIR . '/Logger.php');
            logger()->notice('AccessGate: challenge issued for ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . $path);
        }
        self::respondChallenge();
    }

    /**
     * Issues a proof-of-work seed bound to the given client address.
     * Returns false when the gate is not configured or the address is invalid.
     */
    public static function issueSeed(string $remoteAddr): string|false
    {
        if (!self::isConfigured()) {
            return false;
        }
        $ipKey = self::ipKey($remoteAddr);
        if ($ipKey === null) {
            return false;
        }
        $expiry = time() + self::tokenTtl();
        $bits = self::powBits();
        $mac = self::sign(self::TOKEN_VERSION . "|{$ipKey}|{$expiry}|{$bits}");
        return self::TOKEN_VERSION . ".{$expiry}.{$bits}.{$mac}";
    }

    /**
     * Verifies a token for the given client address.
     * Checks, cheapest first: format, expiry, difficulty, HMAC (IP-bound),
     * then the proof-of-work itself.
     */
    public static function verifyToken(string $token, string $remoteAddr): bool
    {
        if (!self::isConfigured()) {
            return true; // fail-open by design
        }
        if (!preg_match('/^v1\.(\d{10})\.(\d{1,2})\.([0-9a-f]{32})\.(\d{1,12})$/', $token, $m)) {
            return false;
        }
        if ((int)$m[1] < time()) {
            return false;
        }
        // Raising ACCESS_GATE_POW_BITS instantly invalidates weaker tokens.
        if ((int)$m[2] < self::powBits()) {
            return false;
        }
        $ipKey = self::ipKey($remoteAddr);
        if ($ipKey === null) {
            return false;
        }
        $expected = self::sign(self::TOKEN_VERSION . "|{$ipKey}|{$m[1]}|{$m[2]}");
        if (!hash_equals($expected, $m[3])) {
            return false;
        }
        return self::hasLeadingZeroBits(hash('sha256', $token, true), (int)$m[2]);
    }

    /**
     * HMAC-SHA256 signing primitive (truncated to 128 bits, hex).
     * Exposed for reuse by other one-shot token schemes.
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
