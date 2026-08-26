#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
#  Nightly backup of the Shopify store.
#
#  Install:
#      sudo mkdir -p /var/backups/everyday-shopify
#      sudo chown www-data:www-data /var/backups/everyday-shopify
#      sudo chmod 700 /var/backups/everyday-shopify
#      sudo cp backup_cron.sh /usr/local/bin/ && sudo chmod +x /usr/local/bin/backup_cron.sh
#      sudo crontab -e
#        15 3 * * * /usr/local/bin/backup_cron.sh >> /var/log/ew-backup.log 2>&1
#
#  03:15 — quiet, and hours before the 07:30 alert mail, so a failed backup
#  is visible in the same morning rather than a day late.
# ═══════════════════════════════════════════════════════════════════════════
APP=/var/www/sites/everyday/shopify

echo "── $(date '+%Y-%m-%d %H:%M:%S') ─────────────────────────"
cd "$APP" || { echo "cannot reach $APP"; exit 1; }

sudo -u www-data php backup.php
STATUS=$?

if [ $STATUS -ne 0 ]; then
  echo
  echo "BACKUP DID NOT COMPLETE CLEANLY — see above."
  # a failed backup is worth an email of its own, not a line in a log nobody reads
  if command -v mail >/dev/null; then
    TO=$(grep -oP "ALERT_EMAIL',\s*'\K[^']+" "$APP/config.php" 2>/dev/null)
    [ -n "$TO" ] && echo "The nightly Shopify backup did not finish. Check /var/log/ew-backup.log" \
      | mail -s "Backup failed — EverydayWomenwear" "$TO"
  fi
fi
echo
exit $STATUS
