-- 011 — risdoc_teacher_overrides: multi-instance (Phase 24.58, opzione α).
--
-- Permette al docente di forkare lo stesso template in più istanze
-- distinte (es. "Piano annuale 3A", "Piano annuale 4B", "Recupero
-- BES Mario", ecc.) — ognuna con override markup separati e reset
-- indipendente al template istituzionale.
--
-- Cambia UNIQUE: prima `(teacher_id, template_id, kind, relative_path)`,
-- ora `(teacher_id, template_id, instance_key, kind, relative_path)`.
-- Le righe esistenti diventano implicitamente instance_key='' (default).
--
-- instance_label è la label visualizzata in UI (es. "Piano annuale 3A");
-- instance_key è uno slug stabile generato server-side (UNIQUE within
-- teacher+template).
--
-- Idempotente: controlla esistenza colonne e riconfigura UNIQUE.

SET @col_key_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_teacher_overrides'
      AND COLUMN_NAME  = 'instance_key'
);
SET @sql := IF(
    @col_key_exists = 0,
    'ALTER TABLE risdoc_teacher_overrides
        ADD COLUMN instance_key VARCHAR(64) NOT NULL DEFAULT '''' AFTER template_id,
        ADD COLUMN instance_label VARCHAR(255) NULL AFTER instance_key',
    'SELECT "instance_key already exists" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop old UNIQUE se esiste
SET @uq_old_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_teacher_overrides'
      AND INDEX_NAME   = 'uq_rto'
);
SET @sql := IF(
    @uq_old_exists > 0,
    'ALTER TABLE risdoc_teacher_overrides DROP INDEX uq_rto',
    'SELECT "uq_rto already dropped" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add new UNIQUE che include instance_key
SET @uq_new_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_teacher_overrides'
      AND INDEX_NAME   = 'uq_rto_instance'
);
SET @sql := IF(
    @uq_new_exists = 0,
    'ALTER TABLE risdoc_teacher_overrides
        ADD UNIQUE KEY uq_rto_instance (teacher_id, template_id, instance_key, kind, relative_path)',
    'SELECT "uq_rto_instance already exists" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
