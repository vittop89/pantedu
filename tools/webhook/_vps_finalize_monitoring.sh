#!/bin/bash
# Finalize monitoring chain after fismapant kill:
# - install + enable pantedu-waf-export-blocked timer (populates /var/log/pantedu-waf-blocked.log)
# - rename fail2ban jail+filter fismapant-waf -> pantedu-waf
# - restart fail2ban
# - delete stale Grafana "Fismapant" folder via API

set -e

echo "=== 1. Install + enable pantedu-waf-export-blocked timer ==="
REPO=/var/www/pantedu
install -m 644 -o root -g root "$REPO/tools/systemd/pantedu-waf-export-blocked.service" /etc/systemd/system/
install -m 644 -o root -g root "$REPO/tools/systemd/pantedu-waf-export-blocked.timer"   /etc/systemd/system/
# Ensure /var/lib/pantedu exists for state file
install -d -o pantedu -g pantedu -m 0755 /var/lib/pantedu
# Touch the log file so fail2ban has something to tail
touch /var/log/pantedu-waf-blocked.log
chown pantedu:adm /var/log/pantedu-waf-blocked.log
chmod 0640 /var/log/pantedu-waf-blocked.log
systemctl daemon-reload
systemctl enable --now pantedu-waf-export-blocked.timer
systemctl list-timers pantedu-waf-export-blocked.timer --no-pager | head -3

echo "=== 2. Rename fail2ban jail fismapant-waf -> pantedu-waf ==="
# Stop old jail first
fail2ban-client stop fismapant-waf 2>/dev/null || true

# Rewrite jail config
cat > /etc/fail2ban/jail.d/pantedu-waf.conf <<'EOF'
# Network-level ban per IP che WAF ha gia' bloccato 5+ volte.
# Defense-in-depth: WAF gia' blocca (HTTP 403), fail2ban aggiunge iptables
# DROP a livello kernel = piu' veloce + protegge da DDoS di scanning.

[pantedu-waf]
enabled  = true
filter   = pantedu-waf
logpath  = /var/log/pantedu-waf-blocked.log
maxretry = 5
findtime = 600
bantime  = 3600
action   = iptables-multiport[name=pantedu-waf, port="http,https", protocol=tcp]
backend  = polling
EOF

# Rewrite filter config
cat > /etc/fail2ban/filter.d/pantedu-waf.conf <<'EOF'
# Match righe waf_logs export con outcome blocked_*.
# Format atteso: "YYYY-MM-DD HH:MM:SS ip=A.B.C.D country=XX outcome=blocked_* uri=..."

[Definition]
failregex = ^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+ip=<HOST>\s.*\boutcome=blocked_\S+
ignoreregex =
datepattern = ^%%Y-%%m-%%d %%H:%%M:%%S
EOF

# Remove old fismapant configs
rm -f /etc/fail2ban/jail.d/fismapant-waf.conf
rm -f /etc/fail2ban/filter.d/fismapant-waf.conf
# Remove stale empty log file
rm -f /var/log/fismapant-waf-blocked.log

systemctl restart fail2ban
sleep 2
echo "--- fail2ban active jails ---"
fail2ban-client status

echo "=== 3. Delete Grafana 'Fismapant' folder (via API + sqlite fallback) ==="
# Try API first (admin:<password redatta - usa GRAFANA_ADMIN_PASS> default — works if not changed)
GRAFANA_FOLDER_UID=$(curl -s -u "admin:${GRAFANA_ADMIN_PASS:?set GRAFANA_ADMIN_PASS}" http://127.0.0.1:3000/api/folders 2>/dev/null \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print(next((f['uid'] for f in d if f['title']=='Fismapant'), ''))" 2>/dev/null || echo "")
if [ -n "$GRAFANA_FOLDER_UID" ]; then
    echo "  found folder uid=$GRAFANA_FOLDER_UID — deleting via API"
    curl -s -X DELETE -u "admin:${GRAFANA_ADMIN_PASS:?set GRAFANA_ADMIN_PASS}" "http://127.0.0.1:3000/api/folders/$GRAFANA_FOLDER_UID?forceDeleteRules=true" | head -c 200
    echo ""
else
    echo "  API folder lookup empty — fallback sqlite delete"
    systemctl stop grafana-server
    sqlite3 /var/lib/grafana/grafana.db "DELETE FROM dashboard WHERE title='Fismapant' AND is_folder=1; DELETE FROM dashboard WHERE folder_id IN (SELECT id FROM dashboard WHERE title='Fismapant');" 2>&1 || echo "  (sqlite cmd may have already cleaned)"
    systemctl start grafana-server
fi

echo "=== 4. Verify ==="
sleep 3
echo "--- timer next run ---"
systemctl list-timers pantedu-waf-export-blocked.timer --no-pager | head -3
echo "--- log file ---"
ls -la /var/log/pantedu-waf-blocked.log
echo "--- fail2ban status ---"
fail2ban-client status pantedu-waf 2>&1 | head -8

echo "=== DONE ==="
