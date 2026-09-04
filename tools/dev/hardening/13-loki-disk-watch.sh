#!/usr/bin/env bash
# Phase 25.K.13 — Loki disk usage watchdog.
#
# Loki ha retention 30d built-in (compactor + retention_enabled in loki-config.yaml).
# Questo watchdog è un sanity check: se /var/lib/loki/ supera threshold (default 5 GB)
# allerta via syslog + /var/log/pantedu-alerts.log.
#
# Scenari che possono far esplodere lo storage:
# 1. Promtail config bug → ingestion log file enormi (es. trace mode su nginx)
# 2. Compactor crash / disabled → retention non più applicata
# 3. Schema config corrotto → chunks orphaned non più referenziati
# 4. Aumento traffico / WAF logs spike
#
# Usage: sudo bash tools/dev/hardening/13-loki-disk-watch.sh

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

C "=== [1/2] Install /usr/local/bin/loki-disk-watch.sh ==="

cat > /usr/local/bin/loki-disk-watch.sh <<'WATCH_EOF'
#!/usr/bin/env bash
# Loki disk usage watchdog.

set -euo pipefail

THRESHOLD_MB="${THRESHOLD_MB:-5000}"  # 5 GB default
LOKI_DIR=/var/lib/loki

if [[ ! -d "$LOKI_DIR" ]]; then
    exit 0
fi

USAGE_KB=$(du -sk "$LOKI_DIR" | awk '{print $1}')
USAGE_MB=$((USAGE_KB / 1024))

if [[ "$USAGE_MB" -gt "$THRESHOLD_MB" ]]; then
    MSG="[loki-watch] Loki usage ${USAGE_MB} MB > threshold ${THRESHOLD_MB} MB. Check compactor + retention."
    logger -t loki-watch -p user.warning "$MSG"
    echo "[$(date -Iseconds)] $MSG" >> /var/log/pantedu-alerts.log
    echo "$MSG" >&2
    du -sh /var/lib/loki/* 2>/dev/null | sort -h >&2
    curl -s http://127.0.0.1:3100/metrics 2>/dev/null \
        | grep -E '^loki_compactor_apply_retention_last_successful_run_timestamp_seconds' >&2
    exit 1
fi

logger -t loki-watch -p user.info "Loki usage OK: ${USAGE_MB} MB"
WATCH_EOF

chmod 755 /usr/local/bin/loki-disk-watch.sh
G "  ✓ /usr/local/bin/loki-disk-watch.sh installed"

C "=== [2/2] Install cron daily 03:00 ==="

cat > /etc/cron.d/pantedu-loki-watch <<'CRON_EOF'
# Phase 25.K.13 — Loki disk usage daily check
0 3 * * * root /usr/local/bin/loki-disk-watch.sh >/dev/null 2>&1
CRON_EOF

chmod 644 /etc/cron.d/pantedu-loki-watch
G "  ✓ /etc/cron.d/pantedu-loki-watch installed (daily 03:00)"

echo
G "════════════════════════════════════════"
G "Phase 25.K.13 — Loki watchdog OK"
G "════════════════════════════════════════"
echo "Threshold:  5 GB (override via THRESHOLD_MB env)"
echo "Schedule:   daily 03:00"
echo "Alerts:     /var/log/pantedu-alerts.log + syslog (logger tag loki-watch)"
echo
echo "Manual run:"
echo "  sudo /usr/local/bin/loki-disk-watch.sh"
echo "  sudo THRESHOLD_MB=100 /usr/local/bin/loki-disk-watch.sh   # forza trigger test"
