#!/usr/bin/env bash
# Phase 25.K.2 — fail2ban integration con WAF.
# Crea filter + jail che bannano IP che generano >5 outcome=blocked_*
# in 10min su waf_logs (via export_blocked_to_log.php cron).

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Crea log file iniziale + permessi
# ──────────────────────────────────────────────────────────────
C "=== [1/4] Setup log file + state dir ==="
mkdir -p /var/lib/pantedu
chown pantedu:pantedu /var/lib/pantedu
touch /var/log/pantedu-waf-blocked.log
chown pantedu:adm /var/log/pantedu-waf-blocked.log
chmod 640 /var/log/pantedu-waf-blocked.log
G "  ✓ log file ready"

# ──────────────────────────────────────────────────────────────
# 2. fail2ban filter + jail
# ──────────────────────────────────────────────────────────────
C "=== [2/4] Install fail2ban filter + jail ==="

cat > /etc/fail2ban/filter.d/pantedu-waf.conf <<'EOF'
# Phase 25.K.2 — match righe waf_logs export con outcome blocked_*.
# Format atteso: "YYYY-MM-DD HH:MM:SS ip=A.B.C.D country=XX outcome=blocked_* uri=..."

[Definition]
failregex = ^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+ip=<HOST>\s.*\boutcome=blocked_\S+
ignoreregex =
datepattern = ^%%Y-%%m-%%d %%H:%%M:%%S
EOF

cat > /etc/fail2ban/jail.d/pantedu-waf.conf <<'EOF'
# Phase 25.K.2 — Network-level ban per IP che WAF ha gia' bloccato 5+ volte.
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

G "  ✓ filter + jail installati"

# ──────────────────────────────────────────────────────────────
# 3. Install systemd timer per export blocked → log
# ──────────────────────────────────────────────────────────────
C "=== [3/4] Install systemd timer export ==="
# Se non già installati da deploy.sh (idempotente)
if [[ ! -f /etc/systemd/system/pantedu-waf-export-blocked.service ]]; then
    cp /var/www/pantedu/tools/systemd/pantedu-waf-export-blocked.service /etc/systemd/system/
    cp /var/www/pantedu/tools/systemd/pantedu-waf-export-blocked.timer   /etc/systemd/system/
    systemctl daemon-reload
fi
systemctl enable --now pantedu-waf-export-blocked.timer
G "  ✓ timer enabled + started"

# Run una volta manualmente per popolare log iniziale
sudo -u pantedu php /var/www/pantedu/tools/waf/export_blocked_to_log.php 2>&1 | head -3

# ──────────────────────────────────────────────────────────────
# 4. logrotate per non far esplodere il log
# ──────────────────────────────────────────────────────────────
C "=== [4/4] logrotate config ==="
cat > /etc/logrotate.d/pantedu-waf <<'EOF'
/var/log/pantedu-waf-blocked.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 0640 pantedu adm
    postrotate
        systemctl reload fail2ban >/dev/null 2>&1 || true
    endscript
}
EOF
G "  ✓ logrotate installato (rotation daily, 14 giorni retention)"

# ──────────────────────────────────────────────────────────────
# Reload fail2ban + test filter
# ──────────────────────────────────────────────────────────────
C "=== Reload fail2ban + validate ==="
fail2ban-client reload pantedu-waf 2>&1 | head -5
fail2ban-client status pantedu-waf 2>&1 | head -10

echo
G "════════════════════════════════════════"
G "Phase 25.K.2 — fail2ban WAF integration"
G "════════════════════════════════════════"
echo "Status:"
echo "  fail2ban-client status pantedu-waf"
echo "Banned IPs:"
echo "  fail2ban-client status pantedu-waf | grep 'IP list'"
echo "Manual unban:"
echo "  fail2ban-client set pantedu-waf unbanip <IP>"
echo "Test filter (regex match):"
echo "  fail2ban-regex /var/log/pantedu-waf-blocked.log /etc/fail2ban/filter.d/pantedu-waf.conf"
