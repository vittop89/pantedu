-- 069 — Scope di pubblicazione multi-classe per teacher_content.
--
-- FEATURE (richiesta utente): un documento docente (custom o modello risdoc)
-- può essere pubblicato come:
--   - 'class'    → visibile SOLO alla sua (indirizzo, classe) — default, back-compat.
--   - 'classes'  → fan-out: visibile a un INSIEME di (indirizzo, classe) scelte
--                  dal docente (anche su più indirizzi/istituti). Le coppie
--                  vivono in content_target_classes.
--   - 'general'  → visibile a TUTTI gli studenti della STESSA materia
--                  (subject_code), indipendentemente da indirizzo/classe.
--
-- NB: teacher_content è una VIEW; la tabella BASE è teacher_content_data
-- (vedi migration 012/058). La colonna va su _data e la view va ricreata.
-- NON esiste scope "tutto l'istituto" (scelta esplicita: troppo ampio).
--
-- Rollback safe:
--   DROP TABLE content_target_classes;
--   ALTER TABLE teacher_content_data DROP COLUMN publish_scope;
--   (poi ricreare la view senza publish_scope, vedi migration 058)
--
-- Idempotente: re-esecuzione no-op (information_schema guards + OR REPLACE).

SET NAMES utf8mb4;

-- ─────── 1. teacher_content_data.publish_scope (tabella BASE) ───────
SET @has_scope := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'teacher_content_data'
      AND COLUMN_NAME  = 'publish_scope'
);
SET @sql := IF(@has_scope = 0,
    'ALTER TABLE teacher_content_data
        ADD COLUMN publish_scope ENUM(''class'',''classes'',''general'')
            NOT NULL DEFAULT ''class'' AFTER visibility',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────── 2. content_target_classes (coppie del fan-out 'classes') ───────
-- content_id BIGINT UNSIGNED per combaciare con teacher_content_data.id.
CREATE TABLE IF NOT EXISTS content_target_classes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id  BIGINT UNSIGNED NOT NULL,
    indirizzo   VARCHAR(16) NOT NULL,
    classe      VARCHAR(16) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_target (content_id, indirizzo, classe),
    INDEX idx_target_lookup (indirizzo, classe),
    CONSTRAINT fk_target_content
        FOREIGN KEY (content_id) REFERENCES teacher_content_data(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 3. Ricrea VIEW teacher_content per esporre publish_scope ───────
-- Mirror della migration 058 + colonna publish_scope dopo visibility.
DROP VIEW IF EXISTS teacher_content;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW teacher_content AS
SELECT
    tc.id,
    tc.teacher_id,
    tc.content_type,
    tc.subject_id,
    tc.indirizzo_id,
    tc.classe_id,
    tc.topic,
    tc.title,
    tc.body_html,
    tc.body_pt_ct,
    tc.body_pt_iv,
    tc.body_pt_tag,
    tc.body_pt_kv,
    tc.body_html_ct,
    tc.body_html_iv,
    tc.body_html_tag,
    tc.body_html_kv,
    tc.metadata_ct,
    tc.metadata_iv,
    tc.metadata_tag,
    tc.metadata_kv,
    tc.metadata_json,
    tc.source_type,
    tc.map_blob_path,
    tc.map_mime,
    tc.map_size,
    tc.map_drive_id,
    tc.map_origin,
    tc.map_is_public,
    tc.map_version,
    tc.visibility,
    tc.publish_scope,    -- migration 069 NEW
    tc.shared_with_pool,
    tc.source_content_id,
    tc.created_at,
    tc.updated_at,
    ci.code AS indirizzo,
    cc.code AS classe,
    cs.code AS subject_code
FROM teacher_content_data tc
LEFT JOIN curriculum_entries ci ON ci.id = tc.indirizzo_id AND ci.kind = 'indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tc.classe_id AND cc.kind = 'classi'
LEFT JOIN curriculum_entries cs ON cs.id = tc.subject_id AND cs.kind = 'materie';
