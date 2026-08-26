#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
#  Nightly alert check.
#
#  Install:
#      sudo cp alerts_cron.sh /usr/local/bin/ && sudo chmod +x /usr/local/bin/alerts_cron.sh
#      sudo crontab -e
#        30 7 * * * /usr/local/bin/alerts_cron.sh >> /var/log/ew-alerts.log 2>&1
#
#  07:30 rather than midnight: the point is to be read with the first coffee,
#  and an alert that lands at 3am has eight hours to be buried by other mail.
# ═══════════════════════════════════════════════════════════════════════════
APP=/var/www/sites/everyday/shopify

echo "── $(date '+%Y-%m-%d %H:%M:%S') ─────────────────────────"
cd "$APP" || { echo "cannot reach $APP"; exit 1; }
sudo -u www-data php alerts.php --send
echo
