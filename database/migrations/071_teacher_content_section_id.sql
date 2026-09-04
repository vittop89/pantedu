-- 071_teacher_content_section_id.sql — ADR-027 Step 5-6 (DR-3)
-- Ancora ogni contenuto alla sezione sidebar in cui è stato creato, per
-- abilitare la creazione multi-tipo nello stesso pannello e (Step 8) ancorare
-- la visibilità alla sezione invece che al solo content_type.
--
-- Additiva: colonna nullable su teacher_content_data (tabella reale; la view
-- teacher_content verrà estesa in Step 8 quando la lettura servirà). NULL =
-- contenuto legacy → fallback al mapping content_type→sezione di default.
--
-- Idempotente (IF NOT EXISTS). Nessun FK rigido: la sezione può essere globale
-- (institute_id=0) o d'istituto; integrità gestita applicativamente.

ALTER TABLE teacher_content_data
    ADD COLUMN IF NOT EXISTS section_id INT UNSIGNED NULL AFTER content_type;

-- Indice per i futuri filtri di visibilità per-sezione (Step 8).
SET @idx := (SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_content_data'
               AND INDEX_NAME = 'idx_tcd_section');
SET @sql := IF(@idx = 0,
    'ALTER TABLE teacher_content_data ADD INDEX idx_tcd_section (section_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
