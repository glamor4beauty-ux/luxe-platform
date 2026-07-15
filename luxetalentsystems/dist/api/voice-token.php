<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Voice Access Token — /api/voice-token.php
   Mints a Twilio Access Token (JWT) with a Voice grant for the logged-in admin's
   softphone (Twilio.Device). Identity is per-admin. Credentials come from the
   DB-backed Settings store — nothing hardcoded.

   The Device SDK uses this token:  const device = new Device(token);
     GET → { ok, token, identity, ttl }
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok' => false, 'error' => 'Not signed in'], 401);

$identity = identity_for_email($email);                 // alphanumeric + underscore, ≤121

$accountSid = app_setting('twilio_account_sid');
$apiKey     = app_setting('twilio_api_key');
$apiSecret  = app_setting('twilio_api_secret');
$appSid     = app_setting('twilio_twiml_app_sid');

$missing = [];
if ($accountSid === '') $missing[] = 'Account SID';
if ($apiKey === '')     $missing[] = 'API Key SID';
if ($apiSecret === '')  $missing[] = 'API Key Secret';
if ($appSid === '')     $missing[] = 'TwiML App SID';
if ($missing) {
    json_response(['ok' => false, 'error' => 'Voice not configured — set in Settings: ' . implode(', ', $missing)], 400);
}

function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

$now = time();
$ttl = 3600;   // 1 hour; the SDK emits tokenWillExpire so the client refreshes

$header = ['alg' => 'HS256', 'typ' => 'JWT', 'cty' => 'twilio-fpa;v=1'];

$grants = [
    'identity' => $identity,
    'voice'    => [
        'incoming' => ['allow' => true],
        'outgoing' => ['application_sid' => $appSid],
    ],
];

$payload = [
    'jti'    => $apiKey . '-' . $now,
    'iss'    => $apiKey,
    'sub'    => $accountSid,
    'nbf'    => $now,
    'exp'    => $now + $ttl,
    'grants' => $grants,
];

$signingInput = b64url(json_encode($header, JSON_UNESCAPED_SLASHES))
              . '.' . b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
$signature    = b64url(hash_hmac('sha256', $signingInput, $apiSecret, true));
$token        = $signingInput . '.' . $signature;

json_response(['ok' => true, 'token' => $token, 'identity' => $identity, 'ttl' => $ttl]);
