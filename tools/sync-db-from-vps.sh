#!/usr/bin/env bash
# Sync DB pantedu_dev locale dal VPS (per test login locale).
#
# Workflow:
#   1. SSH al VPS, mysqldump database pantedu
#   2. Base64 encode per evitare banner SSH che corrompe stream binario
#   3. Decode + sanitize per MariaDB 10.4 locale (VPS è 11.x):
#      - rimuove `/*M!999999\- enable sandbox mode */` (riga 1)
#      - sostituisce collation `utf8mb4_uca1400_*` con `utf8mb4_unicode_ci`
#      - strip DEFINER clauses (eviter "user does not exist" errors)
#   4. Re-create database + import
#   5. Conferma user superadmin presente
#
# Pre-requisiti (one-time setup):
#   - SSH key pantedu-vps configurato (~/.ssh/config Host pantedu-vps)
#   - XAMPP MariaDB running
#   - MySQL user `pantedu_app@localhost` esistente (CREATE USER + GRANT)
#
# Usage:
#   bash tools/sync-db-from-vps.sh

set -euo pipefail

XAMPP_MYSQL=/c/xampp/mysql/bin/mysql.exe
DB_NAME=pantedu_dev
DUMP_DIR=/tmp/pantedu-dev

mkdir -p "$DUMP_DIR"
DUMP_FILE="$DUMP_DIR/db.sql"

echo "[1/5] SSH dump da pantedu-vps..."
ssh -q pantedu-vps "mysqldump --defaults-file=/root/.my.cnf --single-transaction --quick --routines --triggers --events pantedu 2>/dev/null | base64" \
    | base64 -d > "$DUMP_FILE"
echo "      OK ($(du -h "$DUMP_FILE" | cut -f1))"

echo "[2/5] Sanitize per MariaDB 10.4 locale..."
# Riga 1 ha `/*M!999999\- enable sandbox mode */` (MariaDB 11.x only)
sed -i '1d' "$DUMP_FILE"
# Collation 11.x → 10.4 compat
sed -i 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g; s/utf8mb4_uca1400_as_cs/utf8mb4_unicode_ci/g; s/utf8mb4_uca1400_[a-z_]*/utf8mb4_unicode_ci/g' "$DUMP_FILE"
# Strip DEFINER (views/triggers/routines) — fallback a INVOKER, evita "user does not exist"
sed -i -E 's/\sDEFINER=`[^`]+`@`[^`]+`//g' "$DUMP_FILE"
echo "      OK"

echo "[3/5] DROP + CREATE $DB_NAME..."
$XAMPP_MYSQL -u root -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo "      OK"

echo "[4/5] Import dump..."
$XAMPP_MYSQL -u root "$DB_NAME" < "$DUMP_FILE" 2>&1 | grep -vE "^$" || true
TABLES=$($XAMPP_MYSQL -u root "$DB_NAME" -e "SHOW TABLES;" 2>&1 | wc -l)
echo "      OK ($((TABLES-1)) tabelle)"

echo "[5/5] Verifica user pantedu_app@localhost + dati..."
$XAMPP_MYSQL -u root -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO 'pantedu_app'@'localhost' IDENTIFIED BY ''; FLUSH PRIVILEGES;" 2>&1 | grep -v "wrong checksum" || true
$XAMPP_MYSQL -u root "$DB_NAME" -e "SELECT COUNT(*) AS users, MAX(updated_at) AS last_update FROM users;"

echo
echo "✓ DB pantedu_dev sincronizzato. Test login: http://pantedu.local/login"
