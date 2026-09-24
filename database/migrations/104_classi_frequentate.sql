-- 104 — Classi frequentate: archivio degli anni passati e storico delle sezioni (2026-09-05)
--
-- Piano docs/plans/classi-credenziali-scenari.md, passo D. Uno studente di
-- terza puo' rivedere i materiali di seconda e di prima. Due cose servono:
--
--   1. teacher_content_data.archive_visible — le verifiche degli anni passati
--      restano fuori dall'archivio per default (un docente le riusa con la
--      classe piu' giovane); il docente puo' marcare la singola verifica
--      «visibile anche dopo l'anno».
--   2. student_class_history — le sezioni lasciate dallo studente con account
--      (scenario 3), annotate dal sistema a ogni cambio di classe. Senza, gli
--      anni passati si guardano al livello di anno; con, anche la sezione
--      («2B»). Nessuna informazione nuova sullo studente: e' cio' che il
--      profilo diceva prima del cambio.
--
-- La VIEW teacher_content e' a colonne esplicite dalla 079 (alias
-- content_subtype → content_type): va ricreata con la stessa lista piu'
-- archive_visible, non con `tc.*`, che perderebbe l'alias e romperebbe ogni
-- query su content_type. Idempotente: re-esecuzione no-op (information_schema
-- guard + OR REPLACE + IF NOT EXISTS).

SET NAMES utf8mb4;

-- ─────── 1. teacher_content_data.archive_visible ───────
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'teacher_content_data'
      AND COLUMN_NAME  = 'archive_visible'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE teacher_content_data
        ADD COLUMN archive_visible TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''1 = visibile anche dopo l''''anno, nell''''archivio degli anni passati degli studenti''
            AFTER publish_scope',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────── 2. Ricrea la VIEW teacher_content (definizione della 079 + archive_visible) ───────
CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `teacher_content` AS
  select
    `tc`.`id` AS `id`,`tc`.`teacher_id` AS `teacher_id`,
    `tc`.`content_subtype` AS `content_type`,
    `tc`.`content_subtype` AS `content_subtype`,
    `tc`.`content_format` AS `content_format`,
    `tc`.`section_id` AS `section_id`,`tc`.`subject_id` AS `subject_id`,
    `tc`.`indirizzo_id` AS `indirizzo_id`,`tc`.`classe_id` AS `classe_id`,
    `tc`.`topic` AS `topic`,`tc`.`title` AS `title`,`tc`.`body_html` AS `body_html`,
    `tc`.`body_pt_ct` AS `body_pt_ct`,`tc`.`body_pt_iv` AS `body_pt_iv`,
    `tc`.`body_pt_tag` AS `body_pt_tag`,`tc`.`body_pt_kv` AS `body_pt_kv`,
    `tc`.`body_html_ct` AS `body_html_ct`,`tc`.`body_html_iv` AS `body_html_iv`,
    `tc`.`body_html_tag` AS `body_html_tag`,`tc`.`body_html_kv` AS `body_html_kv`,
    `tc`.`metadata_ct` AS `metadata_ct`,`tc`.`metadata_iv` AS `metadata_iv`,
    `tc`.`metadata_tag` AS `metadata_tag`,`tc`.`metadata_kv` AS `metadata_kv`,
    `tc`.`metadata_json` AS `metadata_json`,`tc`.`map_blob_path` AS `map_blob_path`,
    `tc`.`map_mime` AS `map_mime`,`tc`.`map_size` AS `map_size`,
    `tc`.`map_drive_id` AS `map_drive_id`,`tc`.`map_origin` AS `map_origin`,
    `tc`.`map_is_public` AS `map_is_public`,`tc`.`map_version` AS `map_version`,
    `tc`.`visibility` AS `visibility`,`tc`.`publish_scope` AS `publish_scope`,
    `tc`.`archive_visible` AS `archive_visible`,
    `tc`.`shared_with_pool` AS `shared_with_pool`,`tc`.`source_content_id` AS `source_content_id`,
    `tc`.`created_at` AS `created_at`,`tc`.`updated_at` AS `updated_at`,
    `tc`.`source_type` AS `source_type`,
    `ci`.`code` AS `indirizzo`,`cc`.`code` AS `classe`,`cs`.`code` AS `subject_code`
  from (((`teacher_content_data` `tc`
    left join `curriculum_entries` `ci` on(`ci`.`id` = `tc`.`indirizzo_id` and `ci`.`kind` = 'indirizzi'))
    left join `curriculum_entries` `cc` on(`cc`.`id` = `tc`.`classe_id` and `cc`.`kind` = 'classi'))
    left join `curriculum_entries` `cs` on(`cs`.`id` = `tc`.`subject_id` and `cs`.`kind` = 'materie'));

-- ─────── 3. student_class_history ───────
CREATE TABLE IF NOT EXISTS student_class_history (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    institute_id INT UNSIGNED NULL,
    indirizzo    VARCHAR(16) NULL,
    classe       VARCHAR(16) NOT NULL,
    recorded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_class (user_id, indirizzo, classe),
    INDEX idx_student_history (user_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sezioni lasciate dagli studenti con account: le classi frequentate (piano classi, D)';
