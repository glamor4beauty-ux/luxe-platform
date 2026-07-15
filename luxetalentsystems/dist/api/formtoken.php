<?php
/**
 * Luxe Talent System — anti-bot token issuer
 * File location:  api/formtoken.php
 *
 * index.html fetches this once on page load and drops the token into the
 * hidden #luxe_token field. api/register.php then verifies it.
 * Because index.html is a static file, the token is issued here (PHP)
 * instead of being printed into the page.
 */
require __DIR__ . '/../cors.php';      // same cross-origin headers as register.php
require __DIR__ . '/antibot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['token' => antibot_issue_token()]);
