-- 008 — risdoc_templates.schema_path (Plan A modernization).
--
-- Aggiunge una colonna opzionale che punta al file JSON schema
-- (relative path dalla root, es. 'schemas/risdoc/motivazione-voti.json').
-- Se valorizzato, TemplateViewController usa FormRenderer al posto
-- del PHP template file legacy (html_file). Se NULL, fallback legacy.
--
-- Idempotente: controlla prima dell'ALTER.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_templates'
      AND COLUMN_NAME  = 'schema_path'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE risdoc_templates ADD COLUMN schema_path VARCHAR(512) NULL AFTER css_file',
    'SELECT "schema_path already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
