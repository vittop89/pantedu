#!/usr/bin/env bash
# Phase 25.J.3 — Setup CrowdSec Agent + LAPI bouncer.
# Eseguito DIRETTAMENTE sul VPS (ssh pantedu-vps "bash ...").
# Idempotente: skippa step già fatti.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }

BOUNCER_NAME="${BOUNCER_NAME:-pantedu-php}"
ENV_FILE="${ENV_FILE:-/var/www/pantedu/.env.local}"

C "=== Setup CrowdSec Agent (Phase 25.J.3) ==="
C "Bouncer: ${BOUNCER_NAME}"
C "ENV file: ${ENV_FILE}"
echo

# ── Step 1: install CrowdSec agent ──
if ! command -v cscli >/dev/null 2>&1; then
    C "[1/5] Installing crowdsec agent…"
    curl -s https://install.crowdsec.net | sudo sh
    sudo apt install -y crowdsec
else
    G "[1/5] crowdsec already installed: $(cscli version 2>&1 | head -1)"
fi

# ── Step 2: install collections base ──
C "[2/5] Installing collections (idempotent)…"
sudo cscli collections install crowdsecurity/nginx 2>&1 | grep -v "already" || true
sudo cscli collections install crowdsecurity/http-cve 2>&1 | grep -v "already" || true
sudo cscli collections install crowdsecurity/sshd 2>&1 | grep -v "already" || true
sudo cscli collections install crowdsecurity/linux 2>&1 | grep -v "already" || true
sudo cscli hub update >/dev/null 2>&1 || true
sudo cscli hub upgrade >/dev/null 2>&1 || true

# Reload agent (apply collections)
sudo systemctl reload crowdsec 2>/dev/null || sudo systemctl restart crowdsec

# ── Step 3: create bouncer (idempotent) ──
C "[3/5] Configure bouncer '${BOUNCER_NAME}'…"
if sudo cscli bouncers list 2>/dev/null | grep -q "${BOUNCER_NAME}"; then
    G "  Bouncer '${BOUNCER_NAME}' già esistente"
    if grep -q "^CROWDSEC_LAPI_KEY=" "${ENV_FILE}" 2>/dev/null; then
        G "  CROWDSEC_LAPI_KEY già in ${ENV_FILE} — skip"
    else
        R "  ATTENZIONE: bouncer esiste ma .env senza CROWDSEC_LAPI_KEY"
        R "  Rigenera con: sudo cscli bouncers delete ${BOUNCER_NAME} && re-run script"
        exit 2
    fi
else
    C "  Creazione nuovo bouncer…"
    BOUNCER_KEY=$(sudo cscli bouncers add "${BOUNCER_NAME}" -o raw 2>&1 | tail -1 | tr -d '\r\n')
    if [[ -z "${BOUNCER_KEY}" || "${BOUNCER_KEY}" =~ ^Error ]]; then
        R "ERROR: failed to create bouncer"
        echo "${BOUNCER_KEY}"
        exit 1
    fi
    G "  Bouncer key generata (len=${#BOUNCER_KEY})"

    # Append to .env.local
    if grep -q "^CROWDSEC_LAPI_KEY=" "${ENV_FILE}" 2>/dev/null; then
        sudo sed -i "s|^CROWDSEC_LAPI_KEY=.*|CROWDSEC_LAPI_KEY=${BOUNCER_KEY}|" "${ENV_FILE}"
    else
        echo "CROWDSEC_LAPI_KEY=${BOUNCER_KEY}" | sudo tee -a "${ENV_FILE}" >/dev/null
    fi
    if ! grep -q "^CROWDSEC_LAPI_URL=" "${ENV_FILE}" 2>/dev/null; then
        echo "CROWDSEC_LAPI_URL=http://127.0.0.1:8080" | sudo tee -a "${ENV_FILE}" >/dev/null
    fi
    sudo chown pantedu:www-data "${ENV_FILE}"
    sudo chmod 640 "${ENV_FILE}"
    G "  ${ENV_FILE} aggiornato"
fi

# ── Step 4: smoke test LAPI ──
C "[4/5] Test LAPI reachable…"
KEY=$(sudo grep "^CROWDSEC_LAPI_KEY=" "${ENV_FILE}" | cut -d= -f2)
if curl -sf -m 3 -H "X-Api-Key: ${KEY}" "http://127.0.0.1:8080/v1/decisions?ip=127.0.0.1" >/dev/null; then
    G "  LAPI OK (HTTP 200)"
elif curl -sf -m 3 -H "X-Api-Key: ${KEY}" "http://127.0.0.1:8080/v1/decisions?ip=127.0.0.1" -o /dev/null -w "%{http_code}" | grep -q "200\|404"; then
    G "  LAPI OK (404 = no decisions yet, normale)"
else
    R "  WARN: LAPI not reachable yet"
    sudo systemctl status crowdsec --no-pager | head -10
fi

# ── Step 5: reload php-fpm ──
C "[5/5] Reload php8.4-fpm (carica nuovo env)…"
sudo systemctl reload php8.4-fpm
G "  php8.4-fpm reloaded"

echo
G "=== Setup completo ==="
echo "Verifica admin: /admin/waf/diag → sezione 🐝 CrowdSec Bouncer"
echo "Agent log: sudo journalctl -u crowdsec -f"
echo "Decisioni attive: sudo cscli decisions list"
echo
echo "Test manuale block:"
echo "  sudo cscli decisions add --ip 1.2.3.4 --duration 1h --reason 'test'"
echo "  curl -H 'X-Forwarded-For: 1.2.3.4' https://beta.pantedu.eu/  # → 403"
