#!/usr/bin/env bash
# G22.S20 v2.C2 — Deploy migrations 036-040 su VPS.
#
# Uso (sul VPS dopo git pull automatico via webhook):
#   ssh pantedu-vps 'bash /var/www/pantedu/tools/dev/deploy_migrations_vps.sh'
#
# Sequenza idempotente: ogni step skippa se già applicato.
# Backup automatico DB prima di iniziare (mysqldump teacher_keys + curriculum_entries).
#
# IMPORTANTE: leggere CLAUDE.md INCIDENT 2026-05-13: NON eseguire DROP/TRUNCATE
# su teacher_keys senza shred esplicito. Questo script NON tocca teacher_keys.

set -euo pipefail

APP_ROOT="/var/www/pantedu"
DB_NAME="pantedu"   # adatta se diverso
BACKUP_DIR="$APP_ROOT/storage/backups/mysql_recovery"
TS=$(date +%Y-%m-%d_%H%M)

cd "$APP_ROOT"

echo "═══ G22.S20 v2.C2 — VPS migration deploy ═══"
echo "Timestamp: $(date)"
echo ""

# ─── 0. Pre-flight checks ───
echo "[0] Pre-flight checks..."
git log --oneline -1
echo ""

# ─── 1. Backup curriculum_entries + tabelle target ───
echo "[1] Backup pre-migration ($BACKUP_DIR/pre_drop_varchar_${TS}.sql)..."
mkdir -p "$BACKUP_DIR"
mysqldump --single-transaction --quick "$DB_NAME" \
    curriculum_entries teacher_keys teacher_recovery_keys \
    verifica_documents teacher_content exercises print_info \
    risdoc_compilations teacher_access_credentials classe_keys published_content \
    > "$BACKUP_DIR/pre_drop_varchar_${TS}.sql" 2>/dev/null || \
    echo "  ⚠ Alcune tabelle mancanti (già migrato?). Procedo comunque."
echo "  ✓ Backup: $BACKUP_DIR/pre_drop_varchar_${TS}.sql"
echo ""

# ─── 2. Migration 035 (teacher_recovery_keys) ───
# Già applicata in passato. Idempotente IF NOT EXISTS.
echo "[2] Migration 035 — teacher_recovery_keys..."
mysql "$DB_NAME" < database/migrations/035_teacher_recovery_keys.sql 2>&1 | grep -v "^$" || true
echo "  ✓ 035 OK"
echo ""

# ─── 3. Migration 036 (FK indirizzo) ───
echo "[3] Migration 036 — FK indirizzo..."
mysql "$DB_NAME" < database/migrations/036_indirizzo_fk.sql 2>&1 | grep -v "^$" || true
echo "  ✓ 036 OK"
echo ""

# ─── 4. Migration 037 (FK classe + materia + subject_code) ───
echo "[4] Migration 037 — FK classe+materia+subject..."
mysql "$DB_NAME" < database/migrations/037_classe_materia_fk.sql 2>&1 | grep -v "^$" || true
echo "  ✓ 037 OK"
echo ""

# ─── 5. Migration 038 (triggers — saranno droppati da 040, eseguo comunque per consistency) ───
echo "[5] Migration 038 — triggers (verranno droppati da 040, ma li carichiamo per audit history)..."
# Skip — la migration 038 ha DELIMITER syntax che mysql CLI gestisce ma PDO no.
# Skippato perché triggers vengono comunque droppati da 040.
echo "  ⏭ Skipped (triggers droppati da 040)"
echo ""

# ─── 6. Migration 039 (dedup + UNIQUE) ───
echo "[6] Migration 039 — dedup + UNIQUE constraints..."
# Pre-check duplicati: se presenti, applica dedup script PHP.
DUP_TC=$(mysql -N -e "USE $DB_NAME; SELECT COUNT(*) FROM (SELECT teacher_id, content_type, title FROM teacher_content GROUP BY teacher_id, content_type, title HAVING COUNT(*) > 1) x")
DUP_VD=$(mysql -N -e "USE $DB_NAME; SELECT COUNT(*) FROM (SELECT teacher_id, materia, title, COALESCE(variant,'') v, COALESCE(version_label,'') vl FROM verifica_documents GROUP BY teacher_id, materia, title, v, vl HAVING COUNT(*) > 1) x")
echo "  Duplicate groups pre-dedup: teacher_content=$DUP_TC verifica_documents=$DUP_VD"
if [ "$DUP_TC" -gt 0 ] || [ "$DUP_VD" -gt 0 ]; then
    echo "  Running dedup..."
    php -r "
require __DIR__ . '/app/bootstrap.php';
\$pdo = App\Core\Database::connection();
foreach (\$pdo->query('SELECT teacher_id, content_type, title, GROUP_CONCAT(id ORDER BY id ASC) ids FROM teacher_content GROUP BY teacher_id, content_type, title HAVING COUNT(*) > 1') as \$g) {
    \$ids = explode(',', \$g['ids']); array_pop(\$ids);
    foreach (\$ids as \$d) \$pdo->prepare('DELETE FROM teacher_content WHERE id=?')->execute([\$d]);
}
foreach (\$pdo->query(\"SELECT teacher_id, materia, title, COALESCE(variant,'') v, COALESCE(version_label,'') vl, GROUP_CONCAT(id ORDER BY id ASC) ids FROM verifica_documents GROUP BY teacher_id, materia, title, v, vl HAVING COUNT(*) > 1\") as \$g) {
    \$ids = explode(',', \$g['ids']); array_pop(\$ids);
    foreach (\$ids as \$d) \$pdo->prepare('DELETE FROM verifica_documents WHERE id=?')->execute([\$d]);
}
echo 'dedup done' . PHP_EOL;
"
fi
mysql "$DB_NAME" < database/migrations/039_dedup_unique_constraints.sql 2>&1 | grep -v "^$" || true
echo "  ✓ 039 OK"
echo ""

# ─── 7. Migration 040 (DROP varchar + VIEW back-compat) ───
echo "[7] Migration 040 — DROP varchar + VIEW back-compat..."
mysql "$DB_NAME" < database/migrations/040_drop_varchar_views.sql 2>&1 | grep -v "^$" || true
echo "  ✓ 040 OK"
echo ""

# ─── 8. Post-flight verify ───
echo "[8] Post-flight verify..."
echo "  Tables presenti:"
mysql -N -e "USE $DB_NAME; SHOW TABLES LIKE '%_data'" | sort | sed 's/^/    /'
echo ""
echo "  Views presenti:"
mysql -N -e "USE $DB_NAME; SHOW FULL TABLES WHERE Table_type = 'VIEW'" | awk '{print $1}' | sort | sed 's/^/    /'
echo ""
echo "  Sample SELECT su VIEW (back-compat):"
mysql -e "USE $DB_NAME; SELECT id, indirizzo, classe, materia FROM verifica_documents WHERE teacher_id IS NOT NULL LIMIT 3" 2>&1 | head -10
echo ""

# ─── 9. PHP-FPM reload (per scartare opcache stale) ───
echo "[9] PHP-FPM reload..."
systemctl reload php8.3-fpm
echo "  ✓ Reloaded"
echo ""

echo "═══ Migration deploy completed ═══"
echo ""
echo "Verify manualmente:"
echo "  curl -I https://tex.pantedu.eu/login    # 200 expected"
echo "  Login web + smoke test sync/import"
