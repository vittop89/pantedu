#!/usr/bin/env bash
# Phase 25.K.14 — Logrotate policy per i log custom pantedu.
#
# NON sovrascrive i logrotate esistenti (mariadb, suricata, pantedu-waf).
# Gestisce solo i log applicativi custom: modsec_audit, pantedu-deploy,
# pantedu-alerts, pantedu-backup.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

C "=== [1/2] Install /etc/logrotate.d/pantedu ==="

cat > /etc/logrotate.d/pantedu <<'LR_EOF'
# Phase 25.K.14 — pantedu log rotation custom (no overlap con mariadb/suricata/pantedu-waf)

/var/log/modsec_audit.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 660 www-data adm
    sharedscripts
    postrotate
        # nginx riapre file su SIGUSR1 (audit log handle refresh)
        [ -f /var/run/nginx.pid ] && kill -USR1 $(cat /var/run/nginx.pid) 2>/dev/null || true
    endscript
}

/var/log/pantedu-deploy.log /var/log/pantedu-alerts.log /var/log/pantedu-backup.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 root root
}
LR_EOF

chmod 644 /etc/logrotate.d/pantedu
G "  ✓ /etc/logrotate.d/pantedu installato"

C "=== [2/2] Validate config ==="
logrotate -d /etc/logrotate.d/pantedu 2>&1 | grep -E 'error|fatal' && { R "Errori validation"; exit 2; } || true
G "  ✓ Config validato (no errori)"

echo
G "════════════════════════════════════════"
G "Phase 25.K.14 — Logrotate OK"
G "════════════════════════════════════════"
echo "Policy:"
echo "  modsec_audit.log         daily, retain 30g compressi, USR1 nginx"
echo "  pantedu-deploy.log     daily, retain 30g compressi"
echo "  pantedu-alerts.log     daily, retain 30g compressi"
echo "  pantedu-backup.log     daily, retain 30g compressi"
echo
echo "NOT toccati (gestiti altrove):"
echo "  /var/log/mysql/*.log     → /etc/logrotate.d/mariadb (monthly + maxsize 500M)"
echo "  /var/log/suricata/*      → /etc/logrotate.d/suricata (daily x 14g)"
echo "  /var/log/pantedu-waf-blocked.log → /etc/logrotate.d/pantedu-waf"
echo
echo "Test manual rotation:"
echo "  sudo logrotate -f /etc/logrotate.d/pantedu"
