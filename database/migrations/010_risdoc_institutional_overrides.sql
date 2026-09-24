-- 010 — risdoc_institutional_overrides (Phase 24.55).
--
-- Layer "institutional override" sopra al source file su disco e SOTTO
-- al teacher override. Permette al super-admin di modificare i sorgenti
-- dei template via UI (anziché filesystem + rebuild). I docenti vedono
-- queste modifiche come nuova baseline; possono comunque crearne il
-- proprio override per-teacher (`risdoc_teacher_overrides`).
--
-- TemplateResolver::resolveFile resolve order (Phase 24.55):
--   1. teacher override (se teacher_id presente)
--   2. institutional override (admin-edited via UI)
--   3. source file su disco (legacy filesystem)
--
-- Idempotente: controlla esistenza tabella prima del CREATE.

SET @tbl_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_institutional_overrides'
);

SET @sql := IF(
    @tbl_exists = 0,
    'CREATE TABLE risdoc_institutional_overrides (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id    INT UNSIGNED NOT NULL,
        kind           ENUM(\'html\',\'tex\',\'css\',\'json\',\'image\',\'texCommon\',\'schema\') NOT NULL,
        relative_path  VARCHAR(512) NOT NULL,
        body           LONGTEXT     NULL,
        image_hash     VARCHAR(64)  NULL,
        source_version VARCHAR(64)  NOT NULL,
        updated_by     INT UNSIGNED NULL,
        updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rio (template_id, kind, relative_path),
        INDEX idx_rio_template (template_id),
        CONSTRAINT fk_rio_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE,
        CONSTRAINT fk_rio_admin    FOREIGN KEY (updated_by)  REFERENCES users(id)            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "risdoc_institutional_overrides already exists" AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
