#!/bin/bash
# Phase B — Step 11: VPS-only kill of fismapant.
# Domain on Aruba + GitHub repo are NOT touched.
# Run from root on pantedu-vps.

set -e
echo "=== Step 11.1: stop+disable fismapant systemd units ==="
for u in fismapant-deploy.path fismapant-deploy.service \
         fismapant-backup-encrypted.timer fismapant-backup-encrypted.service \
         fismapant-tikz-prewarm.timer fismapant-tikz-prewarm.service \
         fismapant-waf-export-blocked.timer fismapant-waf-export-blocked.service \
         fismapant-waf-threat-intel@asn.timer fismapant-waf-threat-intel@crowdsec.timer \
         fismapant-waf-threat-intel@spamhaus.timer fismapant-waf-threat-intel@tor.timer \
         fismapant-waf-threat-intel@x4b.timer fismapant-ct-monitor.service \
         fismapant-ct-monitor.timer; do
    systemctl is-enabled "$u" >/dev/null 2>&1 || continue
    echo "  stop+disable $u"
    systemctl stop "$u" 2>/dev/null || true
    systemctl disable "$u" 2>/dev/null || true
done

echo "=== Step 11.2: remove nginx vhost beta.fismapant.com ==="
rm -f /etc/nginx/sites-enabled/beta.fismapant.com*
rm -f /etc/nginx/sites-enabled/tex.fismapant.com*
ls /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
echo "  nginx reloaded"

echo "=== Step 11.3: DROP DATABASE fismapant + DROP USER ==="
mysql -e "DROP DATABASE IF EXISTS fismapant;"
mysql -e "DROP USER IF EXISTS 'fismapant_app'@'localhost';" 2>&1 || true
mysql -e "DROP USER IF EXISTS 'fismapant'@'localhost';" 2>&1 || true
mysql -e "SHOW DATABASES;" | grep -E 'fismapant|pantedu' || true

echo "=== Step 11.4: remove /var/www/fismapant + /var/lib/fismapant-data ==="
chattr -i /var/www/fismapant/.env 2>/dev/null || true
chattr -i /var/www/fismapant/.env.local 2>/dev/null || true
rm -rf /var/www/fismapant
rm -rf /var/lib/fismapant-data
rm -rf /var/lib/fismapant-deploy
ls /var/www/ | grep -E 'fismapant|pantedu' || true
ls /var/lib/ | grep -E 'fismapant|pantedu' || true

echo "=== Step 11.5: certbot delete beta.fismapant.com + tex.fismapant.com ==="
certbot delete --cert-name beta.fismapant.com --non-interactive 2>&1 | tail -5 || true
certbot delete --cert-name tex.fismapant.com --non-interactive 2>&1 | tail -5 || true
certbot certificates 2>&1 | grep 'Certificate Name'

echo "=== Step 11.6: cleanup sudoers + php-fpm drop-ins fismapant ==="
rm -f /etc/sudoers.d/fismapant-deploy
rm -f /etc/sudoers.d/fismapant
rm -f /etc/systemd/system/fismapant-*.{service,path,timer}
rm -f /etc/systemd/system/multi-user.target.wants/fismapant-*
rm -f /etc/systemd/system/php8.4-fpm.service.d/fismapant-sandbox.conf
rm -f /etc/systemd/system/php8.4-fpm.service.d/fismapant-auto-deploy.conf
rm -rf /etc/fismapant
rm -f /usr/local/bin/fismapant-* /usr/local/sbin/fismapant-*
systemctl daemon-reload
systemctl restart php8.4-fpm
ls /etc/systemd/system/ | grep -i fismapant || echo '  no fismapant systemd left'

echo "=== Step 11.7: userdel fismapant ==="
# Check no running processes under fismapant before delete
if pgrep -u fismapant >/dev/null 2>&1; then
    echo "  WARN: processes still running as fismapant — listing then SIGKILL"
    pgrep -u fismapant -a
    pkill -9 -u fismapant 2>/dev/null || true
    sleep 2
fi
if id fismapant >/dev/null 2>&1; then
    userdel -r fismapant 2>&1 || echo "  WARN: userdel failed (manual cleanup may be needed)"
    id fismapant 2>&1 || echo "  user fismapant: GONE"
fi

echo "=== POST-KILL STATE ==="
echo "--- pantedu app ---"
curl -sk -o /dev/null -w 'pantedu: HTTP %{http_code}\n' --resolve pantedu.eu:443:127.0.0.1 https://pantedu.eu/
echo "--- DBs ---"
mysql -e "SHOW DATABASES;" 2>&1
echo "--- /var/www ---"
ls /var/www/
echo "--- /var/lib pantedu/fismapant ---"
ls /var/lib/ | grep -E 'pantedu|fismapant' || echo '  only pantedu remains'

echo "=== ALL DONE ==="
