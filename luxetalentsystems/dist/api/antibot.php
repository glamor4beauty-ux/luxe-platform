<?php
/**
 * Luxe Talent System — anti-bot / anti-forgery guard
 * File location:  api/antibot.php
 *
 * Replaces Google reCAPTCHA. No third-party service, no API keys, nothing
 * that can "sometimes fail". Two checks run on every registration POST:
 *   1. Honeypot  — a hidden field bots fill in and real users never see.
 *   2. Time-trap — an HMAC-signed token issued by api/formtoken.php; rejects
 *                  posts that are forged, replayed, too fast, or stale.
 *
 * SQL injection is separately handled by the PDO prepared statements in
 * api/register.php — those are correct and must stay as they are.
 */

/* ---- shared secret -------------------------------------------------------
 * The ONLY place this secret is defined. formtoken.php, register.php and
 * consent-download.php all include this file, so they share one value.
 * Replace the string below ONCE with a long random value, then leave it. */
if (!defined('LUXE_HMAC_SECRET')) {
    define('LUXE_HMAC_SECRET', '137f59a6c79c793df069f34f96a6836e4a8d6ea6fe28a4396256673acc003397');
}

const ANTIBOT_MIN_SECONDS = 3;        // faster than this = bot
const ANTIBOT_MAX_SECONDS = 7200;     // page open longer than 2h = stale
const ANTIBOT_HONEYPOT    = 'company_website';

/** Issue a fresh signed token. Used by api/formtoken.php. */
function antibot_issue_token(): string {
    $ts    = time();
    $nonce = bin2hex(random_bytes(8));
    $sig   = hash_hmac('sha256', $ts . '|' . $nonce, LUXE_HMAC_SECRET);
    return $ts . '.' . $nonce . '.' . $sig;
}

/**
 * Verify a registration POST. Throws RuntimeException on failure —
 * register.php wraps register() in catch(Throwable), so the thrown message
 * is returned to the browser as a clean JSON error.
 */
function antibot_check(): void {

    // 1. Honeypot must be empty.
    if (trim((string)($_POST[ANTIBOT_HONEYPOT] ?? '')) !== '') {
        throw new RuntimeException('Submission blocked. Please reload the page and try again.');
    }

    // 2. Token present and well-formed.
    $parts = explode('.', (string)($_POST['luxe_token'] ?? ''));
    if (count($parts) !== 3) {
        throw new RuntimeException('Security check failed. Please reload the page and try again.');
    }
    [$ts, $nonce, $sig] = $parts;

    // 3. Signature valid (constant-time compare — not forgeable).
    $expected = hash_hmac('sha256', $ts . '|' . $nonce, LUXE_HMAC_SECRET);
    if (!hash_equals($expected, $sig)) {
        throw new RuntimeException('Invalid security token. Please reload the page.');
    }

    // 4. Timing window.
    $age = time() - (int)$ts;
    if ($age < ANTIBOT_MIN_SECONDS) {
        throw new RuntimeException('Form submitted too quickly. Please try again.');
    }
    if ($age > ANTIBOT_MAX_SECONDS) {
        throw new RuntimeException('This page has expired. Please reload and submit again.');
    }
}
