<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Models Boutique — configuration

   Two ways to authenticate, and only one is needed.

   A. Admin API token, the short route.
      Shopify admin → Settings → Apps and sales channels → Develop apps
        → your app → API credentials → Admin API access token
      Starts shpat_. Put it in SHOP_TOKEN and nothing else here matters.

   B. OAuth, using the dev dashboard app you already built.
      Put the Client ID and Client secret below, leave SHOP_TOKEN empty, then
      visit auth.php once. The token it gets is written to .token and reused
      from then on.
   ═══════════════════════════════════════════════════════════════════════════ */

define('APP_NAME', 'Models Boutique');

/* The permanent myshopify address, not the admin URL. */
define('SHOP_DOMAIN', 'ykxnu0-cd.myshopify.com');

/* ── A: paste an shpat_ token here and you are done ── */
define('SHOP_TOKEN', '');

/* ── B: or the dev dashboard app's credentials ── */
define('SHOP_CLIENT_ID',     'e3c435171f8fd07854223e25bdb15d17');
define('SHOP_CLIENT_SECRET', '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');

/* Must match the Redirect URL declared on the app, character for character. */
define('SHOP_REDIRECT', 'https://admin.modelsboutique.com/shopify/auth.php');

/* Everything the app reads. Two of these are protected and must be approved by
   Shopify before the install will succeed with them present — see below. */
define('SHOP_SCOPES', implode(',', [
    /* orders and the money on them */
    'read_orders',            'write_orders',
    'read_draft_orders',      'write_draft_orders',
    'read_returns',           'write_returns',
    'read_discounts',         'write_discounts',
    'read_price_rules',       'write_price_rules',

    /* people */
    'read_customers',         'write_customers',

    /* getting it to them */
    'read_fulfillments',      'write_fulfillments',
    'read_shipping',          'write_shipping',
    'read_assigned_fulfillment_orders',        'write_assigned_fulfillment_orders',
    'read_merchant_managed_fulfillment_orders','write_merchant_managed_fulfillment_orders',
    'read_third_party_fulfillment_orders',     'write_third_party_fulfillment_orders',

    /* what there is to sell, and where it sits */
    'read_products',          'write_products',
    'read_inventory',         'write_inventory',
    'read_locations',         /* no write counterpart — locations live in Shopify settings */
]));

/* Shopify dates its API quarterly; versions last a year. */
define('SHOP_API_VERSION', '2025-01');

/* How long a list is held before asking Shopify again. */
define('SHOP_CACHE_SECONDS', 120);

/* read_all_orders is granted by Shopify on request, from the app's API access
   page. Until it is approved, leave it out of the scope list above or the
   install will fail — Shopify refuses the whole request rather than granting
   the rest. Everything except order history works without it. */

/* read_all_orders is a protected scope: Shopify grants it on request, and
   including it before approval makes the whole install fail rather than
   granting the rest. Without it, only the last 60 days of orders are visible.
   Add it here and to the app once approved. */

/* ── nightly alerts ──────────────────────────────────────────────────────
   Run by cron; see alerts_cron.sh. Leave ALERT_EMAIL empty and nothing is
   sent, which is how to try it out safely. */
define('ALERT_EMAIL', '');                 /* where the digest goes */
define('ALERT_FROM',  'alerts@everyday.sluttygirlfriends.com');
define('ALERT_APP_URL', 'https://admin.modelsboutique.com/shopify/');

define('ALERT_LOW_STOCK', 3);              /* this many or fewer is "running low" */
define('ALERT_UNFULFILLED_DAYS', 2);       /* unshipped longer than this */
define('ALERT_UNPAID_DAYS', 3);            /* payment pending longer than this */

/* An all-clear goes out this often even when there is nothing wrong, so that
   silence can be told apart from a job that has died. */
define('ALERT_HEARTBEAT_DAYS', 7);

/* ── nightly backup ──────────────────────────────────────────────────────
   Shopify has no undo, so a copy on disk is the only way back from a
   mistake made through this app or the admin. */
define('BACKUP_DIR', '/var/backups/everyday-shopify');
define('BACKUP_KEEP', 30);                 /* days to keep */
