#!/bin/bash
# ═══════════════════════════════════════════════════
# Deploy Twilio SMS API
# Run on luxetalentsystems.com server
# ═══════════════════════════════════════════════════

echo "═══ Step 1: Update nginx to route sms.php ═══"
# Add 'sms' to the PHP location regex
if grep -q '|sms)' /etc/nginx/conf.d/luxe-talent.conf; then
    echo "sms already in nginx config"
else
    sed -i 's/|filestorage)/|filestorage|sms)/' /etc/nginx/conf.d/luxe-talent.conf
    echo "Added sms to nginx config"
fi
nginx -t && systemctl restart nginx
echo "Nginx restarted"

echo ""
echo "═══ Step 2: Set permissions ═══"
chown nginx:nginx /var/www/luxe-talent/dist/api/sms.php
chmod 644 /var/www/luxe-talent/dist/api/sms.php
echo "Permissions set"

echo ""
echo "═══ Step 3: Configure Twilio webhook ═══"
echo ""
echo "IMPORTANT: Set this in your Twilio console:"
echo "  Phone Number: +15715836064"
echo "  Messaging > Webhook URL:"
echo "  https://luxetalentsystems.com/api/sms.php?action=webhook"
echo "  Method: HTTP POST"
echo ""

echo "═══ Step 4: Patch register.php for auto-confirm SMS ═══"
# Add SMS auto-confirm call after successful registration
if grep -q 'auto_confirm' /var/www/luxe-talent/dist/api/register.php; then
    echo "register.php already patched"
else
    # Find the success response line and add SMS call before it
    python3 << 'PYEOF'
import re

f = '/var/www/luxe-talent/dist/api/register.php'
t = open(f).read()

# Backup
open(f + '.bak-sms', 'w').write(t)

# Find where success response is sent — look for json_response with success=>true
# Add SMS auto-confirm before the success response
sms_code = '''
    // Auto-confirm SMS
    try {
        $smsPhone = preg_replace('/[^+0-9]/', '', $phone);
        if($smsPhone[0] !== '+') $smsPhone = '+1' . $smsPhone;
        if(strlen($smsPhone) >= 11) {
            $smsBody = "Welcome to Luxe Model Collective, {$firstName}! Your registration is received. Login: https://luxetalentsystems.com/login.html Email: {$email}. Reply HELP for assistance.";
            $smsCh = curl_init('https://api.twilio.com/2010-04-01/Accounts/AC906dbbb445fc44f915f813107748e499/Messages.json');
            curl_setopt_array($smsCh, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => 'AC906dbbb445fc44f915f813107748e499:1e44f3902ff20ae0ba527e4c84287e3b',
                CURLOPT_POSTFIELDS => http_build_query(['From'=>'+15715836064','To'=>$smsPhone,'Body'=>$smsBody]),
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($smsCh);
            curl_close($smsCh);
        }
    } catch(Throwable $smsErr) {
        error_log('[sms auto-confirm] ' . $smsErr->getMessage());
    }
'''

# Try to insert before json_response success
success_pattern = "json_response(['success'=>true"
idx = t.find(success_pattern)
if idx > 0:
    t = t[:idx] + sms_code + '\n    ' + t[idx:]
    open(f, 'w').write(t)
    print("register.php patched — SMS auto-confirm added")
else:
    print("WARNING: Could not find success response in register.php")
    print("You may need to add SMS auto-confirm manually")

PYEOF
fi

echo ""
echo "═══ Step 5: Test ═══"
echo "Testing SMS API..."
curl -s https://localhost/api/sms.php?action=stats -k
echo ""

echo ""
echo "═══════════════════════════════════════════════"
echo "  SMS SYSTEM DEPLOYED"
echo "═══════════════════════════════════════════════"
echo ""
echo "API Endpoints:"
echo "  POST /api/sms.php?action=send          — send single SMS"
echo "  POST /api/sms.php?action=send_bulk     — send to multiple performers"
echo "  GET  /api/sms.php?action=history       — all SMS history"
echo "  GET  /api/sms.php?action=conversation&phone=+1... — thread"
echo "  POST /api/sms.php?action=auto_confirm  — registration confirm"
echo "  POST /api/sms.php?action=webhook       — Twilio incoming"
echo "  GET  /api/sms.php?action=stats         — stats"
echo ""
echo "TWILIO WEBHOOK (set in Twilio console):"
echo "  https://luxetalentsystems.com/api/sms.php?action=webhook"
echo ""
echo "TEST SEND:"
echo '  curl -s -X POST https://luxetalentsystems.com/api/sms.php?action=send \'
echo '    -H "Content-Type: application/json" \'
echo '    -d "{\"to\":\"+1YOURNUMBER\",\"body\":\"Test from Luxe Talent\"}"'
echo "═══════════════════════════════════════════════"
