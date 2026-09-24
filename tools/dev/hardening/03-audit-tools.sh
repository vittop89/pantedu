#!/usr/bin/env bash
# Phase 25.K.3 — Audit tools: AIDE + Lynis + CT log monitor.
#
# AIDE: file integrity monitoring — alert se /etc, /usr/local/bin,
#       /var/www/pantedu/app/Config, /var/www/pantedu/.env.local
#       sono modificati out-of-band (post-compromise detection).
#
# Lynis: security audit script — score 0-100 + checklist remediation.
#        Run weekly cron, salva report.
#
# CT log monitor: cron weekly poll crt.sh API per cert su pantedu.eu.
#                 Alert se cert non-tuo emesso (subdomain takeover prevention).

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. AIDE — file integrity monitoring
# ──────────────────────────────────────────────────────────────
C "=== [1/3] AIDE install + config ==="

if ! command -v aide >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -qy aide aide-common
fi

# Config custom — solo path critici (non scan tutto / che è troppo lento + falsi positivi)
cat > /etc/aide/aide.conf.d/99-pantedu <<'EOF'
# Phase 25.K.3 — paths critici Pantedu.

# Config dir sistema
/etc                   PERMS+CONTENT+SHA256
/etc/cron              PERMS+CONTENT+SHA256
/etc/init.d            PERMS+CONTENT+SHA256
/etc/systemd/system    PERMS+CONTENT+SHA256
/etc/ssh               PERMS+CONTENT+SHA256

# Binaries (mai modificati out-of-band se non da apt)
/usr/local/bin         PERMS+CONTENT+SHA256
/usr/local/sbin        PERMS+CONTENT+SHA256

# Webhook deploy script
/usr/local/bin/pantedu-deploy.sh  PERMS+CONTENT+SHA256

# App config + secrets (modificati raramente, mai a runtime)
/var/www/pantedu/app/Config       PERMS+CONTENT+SHA256
/var/www/pantedu/.env             PERMS+CONTENT+SHA256
/var/www/pantedu/.env.local       PERMS+CONTENT+SHA256
/var/www/pantedu/composer.json    PERMS+CONTENT+SHA256
/var/www/pantedu/composer.lock    PERMS+CONTENT+SHA256

# Nginx + PHP config (modificate solo via deploy manuale)
/etc/nginx/nginx.conf               PERMS+CONTENT+SHA256
/etc/nginx/sites-enabled            PERMS+CONTENT+SHA256
/etc/php                            PERMS+CONTENT+SHA256

# Skip cose volatili (no checksum su quelle)
!/var/log
!/var/cache
!/var/lib
!/tmp
!/proc
!/sys
!/run
!/var/www/pantedu/storage
!/var/www/pantedu/log
!/var/www/pantedu/vendor
!/var/www/pantedu/public/build
EOF

# Inizializza DB AIDE se mancante
if [[ ! -f /var/lib/aide/aide.db ]]; then
    C "  Initializing AIDE database (può richiedere 2-5 min)…"
    aideinit --force 2>&1 | tail -3 || true
    if [[ -f /var/lib/aide/aide.db.new ]]; then
        mv /var/lib/aide/aide.db.new /var/lib/aide/aide.db
        G "  ✓ AIDE database inizializzato"
    fi
else
    G "  ✓ AIDE database già esistente"
fi

# Cron daily check + alert su diff
cat > /etc/cron.daily/aide-check <<'EOF'
#!/bin/sh
# Phase 25.K.3 — AIDE daily check, log to /var/log/aide-check.log
DIFF=$(aide --check 2>&1 | tail -200)
echo "[$(date -Iseconds)] AIDE check completato" >> /var/log/aide-check.log
echo "$DIFF" >> /var/log/aide-check.log
# Se ci sono changes (output non vuoto/standard), mark log conspicuamente
if echo "$DIFF" | grep -q "Total number of differences"; then
    NUM=$(echo "$DIFF" | grep -oE "Total number of differences:\s*[0-9]+" | grep -oE '[0-9]+' || echo 0)
    if [ "${NUM:-0}" -gt 0 ]; then
        echo "[$(date -Iseconds)] ⚠️ AIDE: $NUM diff rilevati — investigare /var/log/aide-check.log" >> /var/log/aide-check.log
        logger -t pantedu-aide "AIDE detected $NUM file changes"
    fi
fi
EOF
chmod 755 /etc/cron.daily/aide-check
G "  ✓ Cron daily AIDE check installato"

# ──────────────────────────────────────────────────────────────
# 2. Lynis — security audit
# ──────────────────────────────────────────────────────────────
C "=== [2/3] Lynis install + weekly audit ==="

if ! command -v lynis >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -qy lynis
fi

# Cron weekly
cat > /etc/cron.weekly/lynis-audit <<'EOF'
#!/bin/sh
# Phase 25.K.3 — Lynis weekly audit, salva report + score
REPORT=/var/log/lynis-report.$(date +%Y%m%d).log
lynis audit system --cronjob --quiet > "$REPORT" 2>&1
SCORE=$(grep -oE "Hardening index : \[[0-9]+\]" "$REPORT" | grep -oE '[0-9]+' | head -1)
echo "[$(date -Iseconds)] Lynis score: ${SCORE:-?}/100  report: $REPORT" >> /var/log/lynis-history.log
# Mantieni solo ultimi 12 report (rotation)
ls -t /var/log/lynis-report.*.log 2>/dev/null | tail -n +13 | xargs -r rm
EOF
chmod 755 /etc/cron.weekly/lynis-audit
G "  ✓ Lynis cron settimanale installato"

# Run subito una volta per generare baseline
C "  Run lynis audit baseline (può richiedere 1-2 min)…"
lynis audit system --cronjob --quiet > /var/log/lynis-report.baseline.log 2>&1 || true
BASELINE_SCORE=$(grep -oE "Hardening index : \[[0-9]+\]" /var/log/lynis-report.baseline.log 2>/dev/null | grep -oE '[0-9]+' | head -1)
G "  ✓ Lynis baseline score: ${BASELINE_SCORE:-?}/100"

# ──────────────────────────────────────────────────────────────
# 3. CT log monitor (Certificate Transparency)
# ──────────────────────────────────────────────────────────────
C "=== [3/3] CT log monitor cron ==="

mkdir -p /var/lib/pantedu
cat > /usr/local/bin/pantedu-ct-monitor.sh <<'EOF'
#!/bin/bash
# Phase 25.K.3 — Monitor Certificate Transparency log per pantedu.eu.
# Alert se nuovo cert emesso non-da-Letsencrypt (subdomain takeover prevention).
#
# Source: crt.sh (https://crt.sh) — free CT log search.
# Output JSON: tutti i cert mai emessi per pantedu.eu + subdomain.

set -e
DOMAIN="pantedu.eu"
STATE="/var/lib/pantedu/ct-known-certs.txt"
LOG="/var/log/pantedu-ct.log"
EXPECTED_ISSUERS="Let's Encrypt|R3|R10|R11|E1|E5|E6"  # CA accettati (LE varianti)

# Fetch lista cert da crt.sh (formato JSON)
JSON=$(curl -sfL "https://crt.sh/?q=%25.${DOMAIN}&output=json" 2>/dev/null || echo "[]")
[[ "$JSON" == "[]" ]] && exit 0

# Estrai (id|issuer|cn) e confronta con state
echo "$JSON" | python3 -c "
import json, sys
for c in json.load(sys.stdin):
    print(f\"{c['id']}|{c.get('issuer_name','?')}|{c.get('common_name','?')}\")
" 2>/dev/null > /tmp/ct-current.$$

touch "$STATE"
NEW_CERTS=$(comm -23 <(sort -u /tmp/ct-current.$$) <(sort -u "$STATE") || echo "")

if [[ -n "$NEW_CERTS" ]]; then
    echo "[$(date -Iseconds)] ⚠️  Nuovi cert rilevati su CT log per *.${DOMAIN}:" >> "$LOG"
    echo "$NEW_CERTS" | while IFS='|' read -r id issuer cn; do
        # Flag non-Let's Encrypt issuers
        if echo "$issuer" | grep -qE "$EXPECTED_ISSUERS"; then
            echo "    [$id] $cn  (issuer: $issuer) — known CA, OK" >> "$LOG"
        else
            echo "    [$id] $cn  (issuer: $issuer) — ⚠️ UNEXPECTED CA" >> "$LOG"
            logger -t pantedu-ct "UNEXPECTED CT cert: id=$id cn=$cn issuer=$issuer"
        fi
    done
fi

# Update state
cp /tmp/ct-current.$$ "$STATE"
rm /tmp/ct-current.$$
EOF
chmod 755 /usr/local/bin/pantedu-ct-monitor.sh

# Cron weekly run (CT log non cambia frequentemente per piccoli domini)
cat > /etc/cron.weekly/pantedu-ct-monitor <<'EOF'
#!/bin/sh
/usr/local/bin/pantedu-ct-monitor.sh 2>&1
EOF
chmod 755 /etc/cron.weekly/pantedu-ct-monitor

# Run subito una volta per popolare baseline
C "  Run CT monitor baseline…"
/usr/local/bin/pantedu-ct-monitor.sh 2>&1 || true
KNOWN_COUNT=$(wc -l < /var/lib/pantedu/ct-known-certs.txt 2>/dev/null || echo 0)
G "  ✓ CT baseline: $KNOWN_COUNT cert noti per *.pantedu.eu"

# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════════════"
G "Phase 25.K.3 — Audit tools (AIDE+Lynis+CT) DONE"
G "════════════════════════════════════════════════"
echo "Status:"
echo "  AIDE log:    tail /var/log/aide-check.log"
echo "  Lynis score: cat /var/log/lynis-history.log  (latest weekly)"
echo "  CT log:      tail /var/log/pantedu-ct.log"
echo
echo "Run manuali:"
echo "  sudo aide --check           # file integrity scan now"
echo "  sudo lynis audit system     # full audit interactive"
echo "  sudo /usr/local/bin/pantedu-ct-monitor.sh  # CT poll"
