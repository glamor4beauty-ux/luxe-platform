<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Voice TwiML — /api/voice-twiml.php
   This is the URL configured as the TwiML App's "Voice Request URL". Twilio
   (NOT the browser) calls it. It must be publicly reachable.

   • Outbound: device.connect({params:{To}}) → Twilio POSTs here with To set and
     From = "client:<identity>". We return <Dial> using that admin's caller ID.
   • Inbound: a call to your Twilio number → route to the configured client.

   No session here — the caller is Twilio. Per-admin caller ID is resolved from
   the calling client's identity, not from anything the browser sent.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

header('Content-Type: text/xml; charset=utf-8');

function xesc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

$to   = trim((string)($_REQUEST['To']   ?? ''));
$from = trim((string)($_REQUEST['From'] ?? ''));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($to !== '') {
    // Outbound from a browser client. Resolve caller ID from the calling identity.
    $callerId = '';
    if (strpos($from, 'client:') === 0) {
        $identity = substr($from, strlen('client:'));
        $callerId = user_setting($identity, 'voice_caller_id');     // per-admin
    }
    if ($callerId === '') $callerId = app_setting('twilio_from_number'); // global fallback

    if (preg_match('/^[+\d][\d\-() ]*$/', $to)) {
        // Destination looks like a PSTN number
        echo '<Response><Dial callerId="' . xesc($callerId) . '" answerOnBridge="true">'
           . '<Number>' . xesc($to) . '</Number></Dial></Response>';
    } else {
        // Destination is another browser client identity
        echo '<Response><Dial callerId="' . xesc($callerId) . '">'
           . '<Client>' . xesc($to) . '</Client></Dial></Response>';
    }
} else {
    // Inbound call to your Twilio number → ring the configured browser client.
    $client = app_setting('voice_inbound_client');
    if ($client !== '') {
        echo '<Response><Dial><Client>' . xesc($client) . '</Client></Dial></Response>';
    } else {
        echo '<Response><Say>Sorry, no agent is available right now.</Say></Response>';
    }
}
