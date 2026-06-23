#!/usr/bin/env bash
# Phase 25.H — Setup ASN enrichment su VPS beta.pantedu.eu.
#
# Cosa fa (idempotente):
#   1. Scarica/aggiorna dbip-asn-lite.mmdb in storage/geoip/
#   2. Aggiunge WAF_GEOIP_ASN_DB a .env.local se mancante
#   3. Reload php8.4-fpm (cache OPcache + clear env)
#   4. Verifica enrich via PHP CLI (lookup IP test)
#
# Usage (local Windows → VPS):
#   bash tools/dev/setup_asn_vps.sh
#
# Run anche localmente per re-download mensile dell'mmdb (db-ip aggiorna ogni mese).
#
# Requisiti: ssh key configurata (id_ed25519 con passphrase "pantedu").

set -euo pipefail

VPS_ALIAS="${VPS_ALIAS:-pantedu-vps}"  # vedi ~/.ssh/config
VPS_PATH="${VPS_PATH:-/var/www/pantedu}"
GEOIP_DIR="${VPS_PATH}/storage/geoip"
MMDB_PATH="${GEOIP_DIR}/dbip-asn-lite.mmdb"
ENV_FILE="${VPS_PATH}/.env.local"

# URL mese corrente (db-ip aggiorna il 1° di ogni mese)
YEAR_MONTH="$(date -u +%Y-%m)"
DBIP_URL="https://download.db-ip.com/free/dbip-asn-lite-${YEAR_MONTH}.mmdb.gz"

cyan()  { printf "\033[36m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
red()   { printf "\033[31m%s\033[0m\n" "$*"; }
yellow(){ printf "\033[33m%s\033[0m\n" "$*"; }

cyan "=== Setup ASN enrichment VPS (Phase 25.H) ==="
cyan "SSH alias: ${VPS_ALIAS} (vedi ~/.ssh/config)"
cyan "Path: ${VPS_PATH}"
cyan "Source: ${DBIP_URL}"
echo

# Step 1: scarica mmdb su VPS
cyan "[1/4] Download dbip-asn-lite.mmdb su VPS…"
ssh "${VPS_ALIAS}" bash <<EOSSH
set -euo pipefail
mkdir -p "${GEOIP_DIR}"
TMP="\$(mktemp /tmp/dbip-asn-XXXXXX.mmdb.gz)"
if curl -fsSL "${DBIP_URL}" -o "\$TMP"; then
    gunzip -f -c "\$TMP" > "${MMDB_PATH}.new"
    mv "${MMDB_PATH}.new" "${MMDB_PATH}"
    rm -f "\$TMP"
    chmod 644 "${MMDB_PATH}"
    ls -lh "${MMDB_PATH}"
else
    echo "Download FAILED (URL may be stale — db-ip aggiorna mensilmente)"
    # Fallback: prova mese precedente
    PREV_MONTH=\$(date -u -d "1 month ago" +%Y-%m 2>/dev/null || date -u +%Y-%m)
    PREV_URL="https://download.db-ip.com/free/dbip-asn-lite-\${PREV_MONTH}.mmdb.gz"
    echo "Retry: \$PREV_URL"
    curl -fsSL "\$PREV_URL" -o "\$TMP" || { echo "Both URLs failed"; exit 1; }
    gunzip -f -c "\$TMP" > "${MMDB_PATH}.new"
    mv "${MMDB_PATH}.new" "${MMDB_PATH}"
    rm -f "\$TMP"
    chmod 644 "${MMDB_PATH}"
    ls -lh "${MMDB_PATH}"
fi
EOSSH
green "✓ mmdb scaricato"
echo

# Step 2: aggiungi env var
cyan "[2/4] Configura WAF_GEOIP_ASN_DB in .env.local…"
ssh "${VPS_ALIAS}" bash <<EOSSH
set -euo pipefail
if grep -q "^WAF_GEOIP_ASN_DB=" "${ENV_FILE}" 2>/dev/null; then
    # Update existing
    sed -i "s|^WAF_GEOIP_ASN_DB=.*|WAF_GEOIP_ASN_DB=${MMDB_PATH}|" "${ENV_FILE}"
    echo "Updated existing key in ${ENV_FILE}"
else
    # Append
    echo "WAF_GEOIP_ASN_DB=${MMDB_PATH}" >> "${ENV_FILE}"
    echo "Appended to ${ENV_FILE}"
fi
grep "WAF_GEOIP" "${ENV_FILE}"
EOSSH
green "✓ .env.local aggiornato"
echo

# Step 3: reload PHP-FPM (clears env cache + OPcache)
cyan "[3/4] Reload php8.4-fpm…"
ssh "${VPS_ALIAS}" "sudo systemctl reload php8.4-fpm && echo 'php8.4-fpm reloaded'"
green "✓ PHP-FPM reloaded"
echo

# Step 4: verifica con lookup test
cyan "[4/4] Verifica ASN lookup (PHP CLI test)…"
ssh "${VPS_ALIAS}" bash <<EOSSH
cd "${VPS_PATH}"
php -r "
require 'app/bootstrap.php';
\\\$path = (string)App\\Core\\Config::get('waf.geoip_asn_db', '');
echo 'ASN DB path: ' . \\\$path . PHP_EOL;
echo 'Exists: ' . (file_exists(\\\$path) ? 'YES' : 'NO') . PHP_EOL;
\\\$geo = new App\\Services\\Waf\\GeoIpService(
    (string)App\\Core\\Config::get('waf.geoip_db', ''),
    \\\$path,
);
\\\$r = \\\$geo->enrich('8.8.8.8');
echo 'Test 8.8.8.8 → ';
echo 'AS' . (\\\$r['asn'] ?? '?') . ' ' . (\\\$r['org'] ?? '?');
echo ' / rDNS: ' . (\\\$r['rdns'] ?? '?') . PHP_EOL;
"
EOSSH
green "✓ Lookup ASN funziona"
echo

cyan "=== Setup completo ==="
green "Vai a /admin/waf/config e attiva 'RDNS & ASN' = ON"
green "Poi /admin/waf/blocks per testare il toggle 🔍 RDNS & ASN"
echo
yellow "Re-run mensile: bash tools/dev/setup_asn_vps.sh (db-ip aggiorna il 1°)"
