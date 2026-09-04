#!/usr/bin/env bash
# Phase 25.C — WAF post-migration setup su VPS (run ONE-TIME).
# Idempotente: verifica esistenza prima di scrivere.

set -euo pipefail
APP_ROOT="/var/www/pantedu"
cd "$APP_ROOT"

# 1. Genera HMAC secret + path GeoIP in .env.local
if ! grep -q "^WAF_HMAC_SECRET=" .env.local 2>/dev/null; then
    SECRET=$(openssl rand -hex 32)
    echo "" >> .env.local
    echo "# Phase 25.C WAF — self-hosted firewall (vedi wiki/waf.md)" >> .env.local
    echo "WAF_HMAC_SECRET=$SECRET" >> .env.local
    echo "WAF_GEOIP_DB=$APP_ROOT/storage/geoip/dbip-country-lite.mmdb" >> .env.local
    echo "[1/3] ✓ WAF_HMAC_SECRET + WAF_GEOIP_DB added to .env.local"
else
    echo "[1/3] WAF_HMAC_SECRET già presente in .env.local — skip"
fi

# 2. Download GeoIP DB (db-ip.com Lite, no signup)
mkdir -p storage/geoip
MONTH=$(date +%Y-%m)
DB_FILE="storage/geoip/dbip-country-lite.mmdb"
if [ ! -s "$DB_FILE" ]; then
    echo "[2/3] Download db-ip-country-lite-${MONTH}.mmdb.gz..."
    curl -sL "https://download.db-ip.com/free/dbip-country-lite-${MONTH}.mmdb.gz" \
      | gunzip > "$DB_FILE"
    ls -la "$DB_FILE"
else
    echo "[2/3] GeoIP DB already present: $DB_FILE — skip"
fi

# 3. Composer install (geoip2 SDK)
if [ ! -d vendor/geoip2 ]; then
    echo "[3/3] composer install (geoip2/geoip2)..."
    composer install --no-dev --optimize-autoloader 2>&1 | tail -5
else
    echo "[3/3] composer vendor/geoip2 present — skip"
fi

echo ""
echo "═══ SETUP COMPLETE ═══"
echo "Next: apri /admin/waf nel browser → enable + mode=monitor"
