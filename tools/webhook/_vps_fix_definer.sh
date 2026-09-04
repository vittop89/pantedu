#!/bin/bash
# Fix MySQL definer fismapant_app -> pantedu_app on pantedu DB.
# After DROP USER fismapant_app, all views/triggers/procs/events with old
# definer return ERROR 1449. Re-issue with new definer.

set -e
DB=pantedu
NEW_DEFINER='pantedu_app@localhost'

echo "=== Objects with fismapant_app definer ==="
mysql "$DB" -e "
SELECT 'VIEW' AS kind, table_name AS name
  FROM information_schema.views
  WHERE table_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'TRIGGER', trigger_name
  FROM information_schema.triggers
  WHERE trigger_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'PROCEDURE/FUNCTION', routine_name
  FROM information_schema.routines
  WHERE routine_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'EVENT', event_name
  FROM information_schema.events
  WHERE event_schema='$DB' AND definer='fismapant_app@localhost';
"

echo "=== Dump + restore with new definer ==="
DUMP=/tmp/pantedu-fix-definer.sql
mysqldump --single-transaction --routines --triggers --events --no-data --no-create-info \
    --skip-add-drop-table --no-create-db --no-tablespaces \
    --set-gtid-purged=OFF 2>/dev/null "$DB" > /dev/null || true

# Approach: extract each object's DDL, re-issue with new definer.
# Use sed for in-DB ALTER (works for views; for triggers/procs we DROP+CREATE).

# 1. Views — ALTER doesn't change definer; must DROP+CREATE.
mysql "$DB" -BN -e "
  SELECT table_name FROM information_schema.views
  WHERE table_schema='$DB' AND definer='fismapant_app@localhost'
" | while read -r v; do
    [ -z "$v" ] && continue
    DEF=$(mysql "$DB" -BN -e "SHOW CREATE VIEW \`$v\`" | awk -F'\t' '{print $2}')
    # Replace old definer with new in the CREATE statement.
    NEW=$(echo "$DEF" | sed -E "s|DEFINER=\`fismapant_app\`@\`localhost\`|DEFINER=\`pantedu_app\`@\`localhost\`|g")
    echo "  fix VIEW $v"
    mysql "$DB" -e "DROP VIEW IF EXISTS \`$v\`; $NEW;"
done

# 2. Triggers — SHOW TRIGGERS, drop, recreate with new definer.
mysql "$DB" -BN -e "
  SELECT trigger_name FROM information_schema.triggers
  WHERE trigger_schema='$DB' AND definer='fismapant_app@localhost'
" | while read -r t; do
    [ -z "$t" ] && continue
    DEF=$(mysql "$DB" -BN -e "SHOW CREATE TRIGGER \`$t\`" | awk -F'\t' '{print $3}')
    NEW=$(echo "$DEF" | sed -E "s|DEFINER=\`fismapant_app\`@\`localhost\`|DEFINER=\`pantedu_app\`@\`localhost\`|g")
    echo "  fix TRIGGER $t"
    mysql "$DB" -e "DROP TRIGGER IF EXISTS \`$t\`; DELIMITER //
$NEW//
DELIMITER ;" 2>&1 || mysql "$DB" -e "DROP TRIGGER IF EXISTS \`$t\`;" && mysql "$DB" -e "$NEW"
done

# 3. Procedures / functions — drop+create.
mysql "$DB" -BN -e "
  SELECT routine_name, routine_type FROM information_schema.routines
  WHERE routine_schema='$DB' AND definer='fismapant_app@localhost'
" | while read -r name kind; do
    [ -z "$name" ] && continue
    DEF=$(mysql "$DB" -BN -e "SHOW CREATE $kind \`$name\`" | awk -F'\t' '{print $3}')
    NEW=$(echo "$DEF" | sed -E "s|DEFINER=\`fismapant_app\`@\`localhost\`|DEFINER=\`pantedu_app\`@\`localhost\`|g")
    echo "  fix $kind $name"
    mysql "$DB" -e "DROP $kind IF EXISTS \`$name\`;"
    mysql "$DB" -e "$NEW"
done

# 4. Events.
mysql "$DB" -BN -e "
  SELECT event_name FROM information_schema.events
  WHERE event_schema='$DB' AND definer='fismapant_app@localhost'
" | while read -r e; do
    [ -z "$e" ] && continue
    DEF=$(mysql "$DB" -BN -e "SHOW CREATE EVENT \`$e\`" | awk -F'\t' '{print $4}')
    NEW=$(echo "$DEF" | sed -E "s|DEFINER=\`fismapant_app\`@\`localhost\`|DEFINER=\`pantedu_app\`@\`localhost\`|g")
    echo "  fix EVENT $e"
    mysql "$DB" -e "DROP EVENT IF EXISTS \`$e\`;"
    mysql "$DB" -e "$NEW"
done

echo "=== Verify: remaining objects with old definer ==="
mysql "$DB" -e "
SELECT 'VIEW' AS kind, COUNT(*) FROM information_schema.views WHERE table_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'TRIGGER', COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'ROUTINE', COUNT(*) FROM information_schema.routines WHERE routine_schema='$DB' AND definer='fismapant_app@localhost'
UNION ALL
SELECT 'EVENT', COUNT(*) FROM information_schema.events WHERE event_schema='$DB' AND definer='fismapant_app@localhost';
"

echo "=== Test endpoint ==="
mysql "$DB" -e "SELECT subject_code, COUNT(*) FROM teacher_content GROUP BY subject_code ORDER BY subject_code"
