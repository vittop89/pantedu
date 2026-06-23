#!/usr/bin/env bash
# Phase 25.K.10 — Grafana reverse-proxy nginx + auth basic + IP whitelist.
#
# Grafana di default ascolta su 0.0.0.0:3000. Se questa porta è esposta
# pubblica = login form Grafana raggiungibile da chiunque + dictionary
# attack su user/pass admin. Soluzioni:
#
# Approach 1 (consigliato): bind Grafana a 127.0.0.1, accesso via SSH tunnel
#   ssh -L 3000:127.0.0.1:3000 vps
#   Poi http://127.0.0.1:3000 nel browser locale
#   PRO: zero attack surface esterno
#   CON: tunnel manuale ogni volta
#
# Approach 2: reverse-proxy nginx /grafana/ con auth basic + IP whitelist
#   PRO: accesso via browser senza tunnel
#   CON: superficie attacco (form basic auth pubblico)
#
# Questo script implementa Approach 1 (bind localhost) come default sicuro.
# Approach 2 commentato come optional.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Bind Grafana to 127.0.0.1 (no public)
# ──────────────────────────────────────────────────────────────
C "=== [1/2] Bind Grafana to 127.0.0.1 ==="

GRAFANA_CONF="/etc/grafana/grafana.ini"
[[ ! -f "${GRAFANA_CONF}.bak.pantedu" ]] && cp -a "$GRAFANA_CONF" "${GRAFANA_CONF}.bak.pantedu"

# Set http_addr = 127.0.0.1 (default è "" = 0.0.0.0)
if grep -qE "^;?\s*http_addr\s*=" "$GRAFANA_CONF"; then
    sed -i 's/^;\?\s*http_addr\s*=.*/http_addr = 127.0.0.1/' "$GRAFANA_CONF"
else
    # Aggiungi sotto la sezione [server]
    sed -i '/^\[server\]/a http_addr = 127.0.0.1' "$GRAFANA_CONF"
fi

systemctl restart grafana-server
sleep 3
if systemctl is-active --quiet grafana-server; then
    G "  ✓ grafana-server bound 127.0.0.1:3000 (no public)"
else
    R "  ✗ grafana-server NON parte dopo bind change → rollback"
    cp -a "${GRAFANA_CONF}.bak.pantedu" "$GRAFANA_CONF"
    systemctl restart grafana-server
    exit 2
fi

# Verifica bind
if ss -tlnp 2>/dev/null | grep -q "127.0.0.1:3000"; then
    G "  ✓ Listening 127.0.0.1:3000"
fi
if ss -tlnp 2>/dev/null | grep -E "0.0.0.0:3000|:::3000"; then
    W "  ⚠️ Ancora bind 0.0.0.0:3000 — check grafana.ini"
fi

# ──────────────────────────────────────────────────────────────
# 2. Optional: nginx reverse-proxy /grafana/
# ──────────────────────────────────────────────────────────────
C "=== [2/2] Reverse-proxy nginx /grafana/ (OPTIONAL) ==="
W "  SKIPPED di default — usa SSH tunnel:"
echo "    ssh -L 3000:127.0.0.1:3000 pantedu-vps"
echo "    Poi nel browser: http://127.0.0.1:3000"
echo
echo "Per abilitare reverse-proxy /grafana/ pubblico:"
echo "  1. Aggiungi questo block dentro server {} del tuo nginx site:"
cat <<'EOF'

    # /grafana/ reverse-proxy con basic auth + IP whitelist
    location /grafana/ {
        # IP whitelist (modifica per i tuoi IP)
        allow 79.18.139.97;
        # allow OTHER_OFFICE_IP;
        deny all;

        auth_basic "Grafana admin";
        auth_basic_user_file /etc/nginx/.grafana-htpasswd;

        proxy_pass http://127.0.0.1:3000/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
EOF
echo
echo "  2. Crea htpasswd file (richiede apache2-utils):"
echo "       apt install apache2-utils"
echo "       htpasswd -c /etc/nginx/.grafana-htpasswd grafanaadmin"
echo "  3. Configura Grafana per subpath /grafana/:"
echo "       sed -i 's|^;\\?root_url.*|root_url = https://beta.pantedu.eu/grafana/|' /etc/grafana/grafana.ini"
echo "       sed -i 's|^;\\?serve_from_sub_path.*|serve_from_sub_path = true|' /etc/grafana/grafana.ini"
echo "       systemctl restart grafana-server"
echo "  4. nginx -t && systemctl reload nginx"
echo

# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════"
G "Phase 25.K.10 — Grafana proxy setup"
G "════════════════════════════════════════"
echo "Status: Grafana bound 127.0.0.1:3000 (no public exposure)"
echo
echo "Access via SSH tunnel:"
echo "  ssh -L 3000:127.0.0.1:3000 pantedu-vps"
echo "  http://127.0.0.1:3000  (admin/admin → cambia password!)"
echo
echo "Rollback bind:"
echo "  sudo cp ${GRAFANA_CONF}.bak.pantedu ${GRAFANA_CONF}"
echo "  sudo systemctl restart grafana-server"
