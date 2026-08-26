#!/usr/bin/env php
<?php
/* ═══════════════════════════════════════════════════════════════════════════
   install.php — build the Models Boutique database.

   Every table the affiliate programme needs, and a first admin account so
   somebody can get in.

       php install.php                     the tables
       php install.php you@example.com     the tables, and an admin

   Safe to run again. Existing tables are left alone; a second run of the same
   email tells you it is there rather than making a duplicate.
   ═══════════════════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Command line only.\n"); }

require __DIR__ . '/config.php';

$db = db();

function step(string $what, callable $fn): void {
    try {
        $fn();
        printf("  %-42s ok\n", $what);
    } catch (Throwable $e) {
        printf("  %-42s FAILED — %s\n", $what, $e->getMessage());
    }
}

echo "Models Boutique — " . DB_NAME . "\n\n";

/* ── who runs the business ──────────────────────────────────────────────── */
step('admins', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS mb_admins (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(160) NOT NULL UNIQUE,
        name       VARCHAR(120) NOT NULL DEFAULT '',
        pass_hash  VARCHAR(255) NOT NULL,
        last_seen  TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── who sells ──────────────────────────────────────────────────────────── */
step('affiliates', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliates (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(20)  NOT NULL UNIQUE,
        first_name  VARCHAR(80)  NOT NULL DEFAULT '',
        last_name   VARCHAR(80)  NOT NULL DEFAULT '',
        store_name  VARCHAR(120) NOT NULL DEFAULT '',
        email       VARCHAR(160) NOT NULL DEFAULT '',
        phone       VARCHAR(40)  NOT NULL DEFAULT '',
        street      VARCHAR(160) NOT NULL DEFAULT '',
        city        VARCHAR(80)  NOT NULL DEFAULT '',
        state       VARCHAR(80)  NOT NULL DEFAULT '',
        zip         VARCHAR(20)  NOT NULL DEFAULT '',
        country     VARCHAR(80)  NOT NULL DEFAULT '',
        sms_opt_in  TINYINT(1)   NOT NULL DEFAULT 0,
        hosting     ENUM('managed','diy') NOT NULL DEFAULT 'diy',
        template    VARCHAR(20)  NOT NULL DEFAULT 'template1',
        site_url    VARCHAR(255) NOT NULL DEFAULT '',
        status      ENUM('trial','active','inactive','banned') NOT NULL DEFAULT 'trial',
        profile_img VARCHAR(255) NOT NULL DEFAULT '',
        id_img      VARCHAR(255) NOT NULL DEFAULT '',
        pass_hash   VARCHAR(255) NOT NULL DEFAULT '',
        zip_token   VARCHAR(40)  NOT NULL DEFAULT '',
        agreed_at   TIMESTAMP NULL,
        agreed_ip   VARCHAR(45)  NOT NULL DEFAULT '',
        opened      DATE NULL,
        last_seen   TIMESTAMP NULL,
        last_ping   TIMESTAMP NULL,
        ping_ok     TINYINT(1)   NOT NULL DEFAULT 0,
        loads       INT NOT NULL DEFAULT 0,
        stripe_id   VARCHAR(80)  NOT NULL DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_status (status),
        KEY k_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── what they pay us ───────────────────────────────────────────────────── */
step('subscriptions', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_subs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        code          VARCHAR(20) NOT NULL UNIQUE,
        plan          ENUM('monthly','quarterly') NOT NULL DEFAULT 'monthly',
        stripe_cust   VARCHAR(80) NOT NULL DEFAULT '',
        stripe_sub    VARCHAR(80) NOT NULL DEFAULT '',
        stripe_status VARCHAR(40) NOT NULL DEFAULT '',
        amount        DECIMAL(8,2) NOT NULL DEFAULT 0,
        started       TIMESTAMP NULL,
        renews        DATE NULL,
        last_paid     TIMESTAMP NULL,
        last_failure  VARCHAR(255) NOT NULL DEFAULT '',
        provisioned   TINYINT(1) NOT NULL DEFAULT 0,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY k_sub (stripe_sub)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── what we pay them ───────────────────────────────────────────────────── */
step('commission rates', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_rates (
        ref        VARCHAR(60) PRIMARY KEY,
        percent    DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        updated_by VARCHAR(160) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

step('payouts', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_payouts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        ref        VARCHAR(60) NOT NULL,
        amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
        dated      DATE NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        created_by VARCHAR(160) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_ref (ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── the advertising ────────────────────────────────────────────────────── */
step('sponsored ads', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS sponsored_ads (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        title      VARCHAR(120) NOT NULL DEFAULT '',
        image      VARCHAR(255) NOT NULL DEFAULT '',
        url        VARCHAR(500) NOT NULL DEFAULT '',
        alt        VARCHAR(160) NOT NULL DEFAULT '',
        active     TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        clicks     INT NOT NULL DEFAULT 0,
        created_by VARCHAR(160) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── what Stripe told us ────────────────────────────────────────────────── */
step('stripe events', function () use ($db) {
    /* Kept whether or not each was understood. When a payment is disputed
       months later, what Stripe actually said is the only useful answer, and
       memory is not one. */
    $db->exec("CREATE TABLE IF NOT EXISTS stripe_events (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        event_id   VARCHAR(80) NOT NULL UNIQUE,
        type       VARCHAR(80) NOT NULL DEFAULT '',
        code       VARCHAR(20) NOT NULL DEFAULT '',
        handled    TINYINT(1) NOT NULL DEFAULT 0,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        payload    MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

/* ── the first admin ────────────────────────────────────────────────────── */
$email = strtolower(trim((string)($argv[1] ?? '')));

if ($email === '') {
    echo "\nTables are ready.\n";
    echo "To make an admin account:  php install.php you\@example.com\n";
    exit(0);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("\nThat is not a valid email address.\n");
}

$q = $db->prepare("SELECT id FROM mb_admins WHERE email = ?");
$q->execute([$email]);
if ($q->fetch()) {
    exit("\nThere is already an admin account for {$email}.\n");
}

/* Generated rather than asked for, so the first password into the system is
   not one somebody has used elsewhere. */
$alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$pass = '';
for ($i = 0; $i < 14; $i++) $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];

$db->prepare("INSERT INTO mb_admins (email, name, pass_hash) VALUES (?,?,?)")
   ->execute([$email, strstr($email, '@', true), password_hash($pass, PASSWORD_DEFAULT)]);

echo "\n";
echo "  ┌──────────────────────────────────────────────────┐\n";
echo "  │  Admin account created                           │\n";
echo "  ├──────────────────────────────────────────────────┤\n";
printf("  │  %-48s│\n", $email);
printf("  │  %-48s│\n", $pass);
echo "  └──────────────────────────────────────────────────┘\n\n";
echo "  Write that down now — it is hashed in the database and\n";
echo "  cannot be shown again.\n\n";
echo "  Sign in at " . ADMIN_URL . "/login.php\n\n";
