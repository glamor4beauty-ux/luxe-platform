<?php
/**
 * /api/twilio-settings.php
 * GET  -> returns current IVR/forwarding settings as JSON
 * POST -> saves submitted settings to JSON file
 *
 * Storage: /var/www/luxe-talent/dist/api/twilio-settings.json
 */

header('Content-Type: application/json');

$CONFIG_FILE = __DIR__ . '/twilio-settings.json';

$DEFAULTS = [
    'mode' => 'ivr', // 'ivr' or 'forwarding'
    'ivr' => [
        'greeting' => 'Welcome to Luxe Talent, a division of Luxe Model Collective. Please listen to the menu and make a selection. Press one for Recruiting. Press two to hear how to register. Press three to leave a voicemail message.',
        'option1_label' => 'Recruiting',
        'option1_forward' => '',           // phone number to forward
        'option2_label' => 'How to register',
        'option2_message' => 'To register, visit luxetalentsystems.com. On the home page, click Get Started. Fill in your name, email, and phone number. Upload one clear photo of yourself. Confirm your age and agree to the consent form. We will review your application and contact you within 24 hours.',
        'option3_label' => 'Voicemail',
        'voicemail_email' => '',           // where transcribed VMs go
    ],
    'forwarding' => [
        'forward_to' => '',                // phone number to ring
        'ring_timeout' => 20,              // seconds before sending to VM
        'voicemail_email' => '',
        'voicemail_greeting' => 'You have reached Luxe Talent. We are unable to take your call right now. Please leave a message after the tone, and we will return your call as soon as possible.',
    ],
];

function load_settings($file, $defaults) {
    if (!file_exists($file)) return $defaults;
    $raw = @file_get_contents($file);
    if ($raw === false) return $defaults;
    $data = json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    // merge with defaults so newly added keys appear
    return array_replace_recursive($defaults, $data);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(load_settings($CONFIG_FILE, $DEFAULTS));
    exit;
}

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid JSON']);
        exit;
    }
    $current = load_settings($CONFIG_FILE, $DEFAULTS);
    $merged = array_replace_recursive($current, $data);
    if (file_put_contents($CONFIG_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'write failed (check directory permissions)']);
        exit;
    }
    echo json_encode(['ok' => true, 'settings' => $merged]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method not allowed']);
