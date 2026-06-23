#!/usr/bin/env bash
# Phase 25.C — WAF migration deploy + setup su VPS.
# Run from /var/www/pantedu root.

set -euo pipefail

APP_ROOT="/var/www/pantedu"
cd "$APP_ROOT"

# Parse .env (PHP-style key=value). .env.local override .env (DB_PASS reale).
parse_env() {
    # 1. cerca prima in .env.local (override), poi in .env
    local val
    for src in .env.local .env; do
        if [ -f "$src" ]; then
            val=$(grep "^$1=" "$src" 2>/dev/null | head -1 | sed "s|^$1=||" | sed 's|^"||;s|"$||')
            if [ -n "$val" ]; then echo "$val"; return; fi
        fi
    done
}

DB_NAME=$(parse_env DB_NAME)
DB_USER=$(parse_env DB_USER)
DB_PASS=$(parse_env DB_PASS)

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "ERROR: DB_NAME or DB_USER empty in .env" >&2
    exit 2
fi

echo "[1/4] DB params: USER=$DB_USER NAME=$DB_NAME PASS_LEN=${#DB_PASS}"
echo ""

echo "[2/4] Backup pre-migration..."
TS=$(date +%Y%m%d_%H%M)
BACKUP="storage/backups/mysql_recovery/pre_waf_${TS}.sql.gz"
mkdir -p storage/backups/mysql_recovery
mysqldump -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" --single-transaction --skip-lock-tables \
    "$DB_NAME" 2>/dev/null | gzip > "$BACKUP"
ls -la "$BACKUP"
echo ""

echo "[3/4] Apply migration 048_waf_tables.sql..."
mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/migrations/048_waf_tables.sql
echo "✓ Migration applied"
echo ""

echo "[4/4] Verify..."
echo "--- waf_* tables ---"
mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES LIKE 'waf_%'"
echo ""
echo "--- waf_config defaults ---"
mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT config_key, config_value FROM waf_config ORDER BY config_key"
