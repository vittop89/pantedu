#!/bin/bash
# G26 — Daily backup di teacher_keys table.
#
# Setup cron VPS:
#   crontab -e
#   # Daily 03:00:
#   0 3 * * * /var/www/pantedu/tools/crypto/backup_teacher_keys.sh
#
# Retention: 30 giorni (rotation via filename data).
# Output: storage/backups/teacher_keys/teacher_keys_YYYY-MM-DD.sql.gz

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BACKUP_DIR="$ROOT/storage/backups/teacher_keys"
mkdir -p "$BACKUP_DIR"

# 23/9/2026 (A-86) — la password non va più sulla riga di comando di
# mysqldump: passa da un file di opzioni temporaneo (vedi il file qui sotto).
# shellcheck source=../security/opzioni-mysql.sh
. "$ROOT/tools/security/opzioni-mysql.sh"

# Carica credenziali DB da .env.local (production override).
# Senza `set -a` dal 23/9/2026: esportava tutto il file, password comprese,
# nell'ambiente di ogni processo figlio. Qui servono solo a questa shell.
if [ -f "$ROOT/.env.local" ]; then
    # shellcheck source=/dev/null
    source "$ROOT/.env.local"
fi
# Fallback .env
if [ -f "$ROOT/.env" ] && [ -z "${DB_HOST:-}" ]; then
    # shellcheck source=/dev/null
    source "$ROOT/.env"
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-pantedu}"
DB_USER="${DB_USER:-pantedu_app}"
DB_PASS="${DB_PASS:-}"

if [ -z "$DB_PASS" ]; then
    echo "[backup_teacher_keys] ❌ DB_PASS vuota — abort" >&2
    exit 1
fi

TS=$(date +%Y-%m-%d)
OUT="$BACKUP_DIR/teacher_keys_$TS.sql.gz"

con_credenziali_mysql "$DB_USER" "$DB_PASS" mysqldump \
    -h "$DB_HOST" \
    --single-transaction \
    --no-create-info \
    --skip-add-locks \
    --skip-comments \
    --hex-blob \
    "$DB_NAME" teacher_keys \
    | gzip > "$OUT"

echo "[backup_teacher_keys] ✅ $OUT ($(du -h "$OUT" | cut -f1))"

# Retention: cancella backup > 30 giorni
find "$BACKUP_DIR" -name 'teacher_keys_*.sql.gz' -mtime +30 -delete

# Verifica sanity: file non vuoto, contiene INSERT
if [ ! -s "$OUT" ] || ! gzip -dc "$OUT" | grep -q 'INSERT INTO'; then
    echo "[backup_teacher_keys] ⚠ backup sospetto — contenuto vuoto o senza INSERT" >&2
    exit 1
fi
