-- 079_content_subtype_and_format.sql — ADR-027 / Opzione A (cosmetico coerente)
--
-- Rende esplicito a livello DB il doppio asse emerso dopo il collasso (078):
--   content_subtype ∈ {mappa, esercizio, verifica, document}  (tipo fine, ex content_type)
--   content_format  ∈ {map, exercise, document}               (asse di RENDERING)
-- esercizio E verifica → entrambi format 'exercise' (non 1:1), quindi i 4 subtype
-- NON coincidono coi 3 formati: tenere due colonne è semanticamente corretto.
--
-- content_format è una STORED GENERATED column: il DB la deriva da content_subtype
-- con la STESSA mappa di TeacherContentRepository::formatOf() → zero drift, nessun
-- write-side (la dual-write/crypto NON la elenca negli INSERT → MariaDB la calcola).
--
-- Retro-compat: le viste teacher_content/published_content continuano a esporre
-- una colonna `content_type` (alias di content_subtype) → tutte le ~21 query
-- read-side restano invariate. Solo gli INSERT/SELECT DIRETTI sulle base table
-- passano a content_subtype (vedi commit di pari passo).
--
-- MariaDB (10.4 local / 11.8 prod): CHANGE/ADD COLUMN IF [NOT] EXISTS → idempotente.
-- FOREIGN_KEY_CHECKS=0 per il rebuild bloccato dalla FK map_shares.content_id.

SET FOREIGN_KEY_CHECKS = 0;

-- 1) rinomina la colonna base content_type → content_subtype (valori invariati)
ALTER TABLE teacher_content_data
  CHANGE COLUMN IF EXISTS content_type content_subtype
  ENUM('mappa','esercizio','verifica','document') NOT NULL DEFAULT 'document';
ALTER TABLE published_content_data
  CHANGE COLUMN IF EXISTS content_type content_subtype
  ENUM('mappa','esercizio','verifica','document') NOT NULL DEFAULT 'document';

-- 2) colonna generata content_format (= formatOf(content_subtype))
ALTER TABLE teacher_content_data
  ADD COLUMN IF NOT EXISTS content_format ENUM('map','exercise','document')
  GENERATED ALWAYS AS (CASE content_subtype
      WHEN 'mappa'     THEN 'map'
      WHEN 'esercizio' THEN 'exercise'
      WHEN 'verifica'  THEN 'exercise'
      ELSE 'document' END) STORED
  AFTER content_subtype;
ALTER TABLE published_content_data
  ADD COLUMN IF NOT EXISTS content_format ENUM('map','exercise','document')
  GENERATED ALWAYS AS (CASE content_subtype
      WHEN 'mappa'     THEN 'map'
      WHEN 'esercizio' THEN 'exercise'
      WHEN 'verifica'  THEN 'exercise'
      ELSE 'document' END) STORED
  AFTER content_subtype;

-- indice sul nuovo asse di rendering (per i filtri per formato)
ALTER TABLE teacher_content_data  ADD INDEX IF NOT EXISTS idx_tc_format (content_format, visibility);

SET FOREIGN_KEY_CHECKS = 1;

-- 3) ricrea le viste: espongono content_subtype + content_format + alias
--    content_type (= content_subtype) per retro-compat read-side. Header
--    ALGORITHM=UNDEFINED SQL SECURITY DEFINER preservato (vedi migr 073).
CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `teacher_content` AS
  select
    `tc`.`id` AS `id`,`tc`.`teacher_id` AS `teacher_id`,
    `tc`.`content_subtype` AS `content_type`,            -- alias retro-compat
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
    `tc`.`shared_with_pool` AS `shared_with_pool`,`tc`.`source_content_id` AS `source_content_id`,
    `tc`.`created_at` AS `created_at`,`tc`.`updated_at` AS `updated_at`,
    `tc`.`source_type` AS `source_type`,
    `ci`.`code` AS `indirizzo`,`cc`.`code` AS `classe`,`cs`.`code` AS `subject_code`
  from (((`teacher_content_data` `tc`
    left join `curriculum_entries` `ci` on(`ci`.`id` = `tc`.`indirizzo_id` and `ci`.`kind` = 'indirizzi'))
    left join `curriculum_entries` `cc` on(`cc`.`id` = `tc`.`classe_id` and `cc`.`kind` = 'classi'))
    left join `curriculum_entries` `cs` on(`cs`.`id` = `tc`.`subject_id` and `cs`.`kind` = 'materie'));

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `published_content` AS
  select
    `pc`.`id` AS `id`,`pc`.`source_id` AS `source_id`,`pc`.`teacher_id` AS `teacher_id`,
    `pc`.`classe_key_id` AS `classe_key_id`,
    `pc`.`content_subtype` AS `content_type`,            -- alias retro-compat
    `pc`.`content_subtype` AS `content_subtype`,
    `pc`.`content_format` AS `content_format`,
    `pc`.`title` AS `title`,`pc`.`topic` AS `topic`,`pc`.`subject_id` AS `subject_id`,
    `pc`.`body_ct` AS `body_ct`,`pc`.`body_iv` AS `body_iv`,`pc`.`body_tag` AS `body_tag`,
    `pc`.`body_kv` AS `body_kv`,`pc`.`metadata_json` AS `metadata_json`,
    `pc`.`published_at` AS `published_at`,`pc`.`expires_at` AS `expires_at`,
    `pc`.`revoked_at` AS `revoked_at`,`cs`.`code` AS `subject_code`
  from (`published_content_data` `pc`
    left join `curriculum_entries` `cs` on(`cs`.`id` = `pc`.`subject_id` and `cs`.`kind` = 'materie'));
