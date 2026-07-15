<?php
/**
 * Luxe Talent System — Performer dashboard entry router
 * File:  dist/performer.php
 *
 * Single canonical URL — https://luxetalentsystems.com/performer.php
 * Auto-detects what the visitor is on and serves the right dashboard:
 *   • Phones (iPhone, Android phone, Windows Phone, BlackBerry, …)
 *       → performer-dashboard.html         (existing mobile, untouched)
 *   • Tablets, laptops, desktops (iPad, Surface, MacBook, PC, …)
 *       → performer-dashboard-desktop.html (built in this session)
 *
 * Detection priority — first match wins:
 *   1. ?view=mobile  or  ?view=desktop   (explicit one-time override —
 *                                         persists as a 30-day cookie)
 *   2. lux_view cookie                   (sticky preference from a prior override)
 *   3. User-Agent sniff                  (server-side, no client-side flicker)
 *   4. JS viewport fallback              (lives in the desktop HTML — catches
 *                                         direct visits that bypass this router)
 *
 * Login flow: change your login redirect from
 *     /performer-dashboard.html   →   /performer.php
 * That's the only wiring change. Bookmarks already pointed at the mobile
 * page keep working (the page is unchanged); new bookmarks made from the
 * router URL survive device changes.
 */

// ── 1. Explicit override via query param ───────────────────────────
$override = $_GET['view'] ?? null;
if (in_array($override, ['mobile', 'desktop'], true)) {
    // Remember the choice for 30 days so subsequent visits without the
    // query param still respect it.
    setcookie('lux_view', $override, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,   // JS in the desktop page reads it for the fallback
    ]);
    $view = $override;
}
// ── 2. Sticky preference from a previous override ──────────────────
elseif (isset($_COOKIE['lux_view']) && in_array($_COOKIE['lux_view'], ['mobile', 'desktop'], true)) {
    $view = $_COOKIE['lux_view'];
}
// ── 3. User-Agent sniff — match PHONES specifically ─────────────────
else {
    $view = detect_view_from_ua($_SERVER['HTTP_USER_AGENT'] ?? '');
}

/**
 * Returns 'mobile' for phone-sized devices, 'desktop' for everything else.
 *
 * Phone-specific markers used here:
 *   • iPhone / iPod                  — Apple phones
 *   • Android + "Mobile" token       — Android phones (tablets drop the token)
 *   • IEMobile / Windows Phone       — Windows Phones
 *   • BlackBerry / BB10 / PlayBook   — BlackBerry phones (PlayBook is tiny)
 *   • webOS / hpwOS                  — Palm/HP phones
 *   • Opera Mini / Opera Mobi        — Opera's mobile builds
 *   • Kindle / Silk-Accelerated      — Amazon Fire phones (NOT Fire tablets;
 *                                       those run desktop Silk without the
 *                                       Accelerated token)
 *
 * Explicitly EXCLUDED — these get the desktop view:
 *   • iPad  — landscape iPad is desktop-sized; iPad on iOS 13+ also reports
 *             a macOS UA by default, so we'd misclassify them as desktop
 *             anyway. Consistent treatment.
 *   • Android tablets (Galaxy Tab, etc.) — UA has "Android" but no "Mobile".
 *
 * If the visitor's UA is novel/unknown, default = desktop. They can switch
 * with ?view=mobile on the next click; the cookie sticks for 30 days.
 */
function detect_view_from_ua(string $ua): string {
    if ($ua === '') return 'desktop';

    // iPad always gets desktop
    if (preg_match('/iPad/i', $ua)) return 'desktop';

    // Android tablets — Android UA without "Mobile" token = tablet = desktop
    if (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) return 'desktop';

    // Anything that smells like a phone
    if (preg_match(
        '/iPhone|iPod|Android.*Mobile|IEMobile|Windows Phone|BlackBerry|BB10|PlayBook|webOS|hpwOS|Opera M(ini|obi)|Kindle|Silk-Accelerated/i',
        $ua
    )) {
        return 'mobile';
    }

    return 'desktop';
}

// ── Serve the right file ───────────────────────────────────────────
$file = ($view === 'mobile')
    ? __DIR__ . '/performer-dashboard.html'
    : __DIR__ . '/performer-dashboard.html';

if (!is_file($file)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dashboard file missing on the server. Expected: " . basename($file);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
// Don't let proxies cache the wrong variant for the wrong UA
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Vary: User-Agent, Cookie');
// Useful when debugging from devtools / curl -I
header('X-Luxe-View: ' . $view);

readfile($file);
