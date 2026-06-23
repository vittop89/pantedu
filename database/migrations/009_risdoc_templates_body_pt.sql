-- 009 — risdoc_templates.body_pt (Phase 24.50).
--
-- Aggiunge il PT AST seed sui template istituzionali. Quando un teacher
-- crea un teacher_content con layout="exercises" e seleziona un template
-- come base, body_pt viene COPIATO nel suo metadata.body_pt — il
-- teacher_content è poi indipendente (no link al template originale).
--
-- LONGTEXT JSON: i body_pt seed dei template possono essere grandi
-- (sectionHeader + table + checkboxGroup multipli per "Piano annuale" ecc.).
--
-- Idempotente: controlla prima dell'ALTER.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_templates'
      AND COLUMN_NAME  = 'body_pt'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE risdoc_templates ADD COLUMN body_pt LONGTEXT NULL AFTER schema_path',
    'SELECT "body_pt already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
