#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  provision.sh — build and publish a paying affiliate's site.
#
#  Run by the Stripe webhook when a first payment clears, detached so Stripe
#  is not kept waiting. Everything it does is logged, because when a customer
#  says her site never appeared, the log is the only honest answer.
#
#      provision.sh AFFJOHN47
#
#  Safe to run twice. An existing site is refreshed rather than duplicated.
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

CODE="${1:-}"
SITES=/var/www/sites/affiliate/dist
BUILDS=$SITES/hosted
BASE=modelsboutique.com
LOG=/var/log/mb-provision.log

say() { echo "$(date '+%Y-%m-%d %H:%M:%S')  $CODE  $*"; }

if [ -z "$CODE" ]; then
  say "no affiliate code given"
  exit 1
fi

say "───────────────────────────────────────────────"
say "starting"

# ── who is this ────────────────────────────────────────────────────────────
read -r STORE TEMPLATE STATUS < <(
  php -r '
    require "/var/www/sites/affiliate/dist/config.php";
    $q = db()->prepare("SELECT store_name, template, status FROM affiliates WHERE code = ?");
    $q->execute([$argv[1]]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) { echo "NONE NONE NONE"; exit; }
    /* spaces would split the read, so the store name is slugged here */
    $slug = strtolower(preg_replace("/[^A-Za-z0-9]+/", "-", $a["store_name"]));
    echo trim($slug, "-") . " " . $a["template"] . " " . $a["status"];
  ' "$CODE" 2>/dev/null
)

if [ "${STORE:-NONE}" = "NONE" ] || [ -z "${STORE:-}" ]; then
  say "FAILED — no affiliate with that code"
  exit 1
fi

say "store=$STORE template=$TEMPLATE status=$STATUS"

# ── her address ────────────────────────────────────────────────────────────
# The store name, made safe, under the main domain. Collisions get the code
# appended rather than one shop quietly overwriting another.
SUB="$STORE"
DIR="$BUILDS/$SUB"

if [ -d "$DIR" ] && [ ! -f "$DIR/.$CODE" ]; then
  SUB="$STORE-$(echo "$CODE" | tr '[:upper:]' '[:lower:]')"
  DIR="$BUILDS/$SUB"
  say "name taken, using $SUB"
fi

HOST="$SUB.$BASE"
say "address will be https://$HOST"

# The address is recorded before the work starts, so the welcome page can show
# it the moment it exists rather than after the certificate is issued.
php -r '
  require "/var/www/sites/affiliate/dist/config.php";
  db()->prepare("UPDATE affiliates SET site_url = ? WHERE code = ?")
      ->execute([$argv[2], $argv[1]]);
' "$CODE" "https://$HOST" 2>/dev/null

# ── the page ───────────────────────────────────────────────────────────────
mkdir -p "$DIR/images"

# The product photographs the template expects. Copied rather than linked to a
# shared folder, so her site keeps working even if ours is reorganised — and
# so she can replace them with her own without affecting anybody else.
if [ -d "$SITES/images/Creatives" ]; then
  cp -n "$SITES"/images/Creatives/*.png "$DIR/images/" 2>/dev/null
  cp -n "$SITES"/images/Creatives/*.jpg "$DIR/images/" 2>/dev/null
  say "images $(ls -1 "$DIR/images" 2>/dev/null | wc -l) copied"
fi
touch "$DIR/.$CODE"

php -r '
  $_GET = ["action" => "build", "code" => $argv[1]];
  require "/var/www/sites/affiliate/dist/api/build.php";
' "$CODE" > "$DIR/index.html" 2>/dev/null

if [ ! -s "$DIR/index.html" ]; then
  say "FAILED — the page came out empty"
  exit 1
fi

say "built  $(wc -c < "$DIR/index.html") bytes"

chown -R www-data:www-data "$DIR"
find "$DIR" -type d -exec chmod 755 {} \;
find "$DIR" -type f -exec chmod 644 {} \;

# ── the vhost ──────────────────────────────────────────────────────────────
VHOST=/etc/nginx/sites-available/mb-$SUB

if [ -f "$VHOST" ]; then
  say "vhost already there, leaving it"
else
  cat > "$VHOST" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $HOST;
    root $DIR;
    index index.html;

    location / { try_files \$uri \$uri/ =404; }
    location ~ /\. { deny all; }
}
EOF
  ln -sf "$VHOST" /etc/nginx/sites-enabled/mb-$SUB
  say "vhost written"
fi

if ! nginx -t 2>/dev/null; then
  say "FAILED — nginx refused the config; the vhost has been left in place for inspection"
  exit 1
fi

systemctl reload nginx
say "nginx reloaded"

# ── the certificate ────────────────────────────────────────────────────────
# DNS has to resolve before Let's Encrypt will issue. A wildcard record on
# *.modelsboutique.com makes this work the moment the vhost exists; without
# one, this step fails and the site still works over plain http until the
# record is added.
if certbot --nginx -d "$HOST" --non-interactive --agree-tos \
     -m support@modelsboutique.com --redirect >/dev/null 2>&1; then
  say "ssl issued"
  systemctl reload nginx
else
  say "WARNING — ssl could not be issued; check the DNS record for $HOST"
fi

# ── record it ──────────────────────────────────────────────────────────────
php -r '
  require "/var/www/sites/affiliate/dist/config.php";
  db()->prepare("UPDATE affiliates SET site_url = ?, status = \"active\" WHERE code = ?")
      ->execute([$argv[2], $argv[1]]);
' "$CODE" "https://$HOST" 2>/dev/null

say "done — https://$HOST"
exit 0
