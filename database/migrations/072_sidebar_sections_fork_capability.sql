-- 072_sidebar_sections_fork_capability.sql — ADR-027 unificazione loader
--
-- Modello a CAPABILITY (niente più loader db/risdoc/mixed): ogni sezione è
-- label + tipi ammessi + opzione "fork template istituzionale". Il fork crea
-- comunque un documento del docente ancorato alla sezione (section_id).
--
--   allow_template_fork  → la sezione consente di creare per fork da template
--                          istituzionale (oltre ai tipi in allowed_content_types)
--   template_origin      → famiglia template da forkare (strcomp|risdoc|…)
--
-- Inoltre: backfill di teacher_content_data.section_id per i contenuti legacy
-- (content_type → sezione di default globale), così il loader unico potrà
-- caricare per section_id. I tipi senza sezione (es. 'documento', '') restano
-- NULL → fallback applicativo content_type→sezione.
--
-- Additiva, idempotente. MariaDB 11.x.

ALTER TABLE sidebar_sections
    ADD COLUMN IF NOT EXISTS allow_template_fork TINYINT(1) NOT NULL DEFAULT 0 AFTER supports_fork,
    ADD COLUMN IF NOT EXISTS template_origin VARCHAR(32) NULL AFTER allow_template_fork;

-- Capability iniziali dei 6 default (replica lo stato attuale: solo bes/risdoc
-- forkano template istituzionali).
UPDATE sidebar_sections SET allow_template_fork = 1, template_origin = 'strcomp'
    WHERE institute_id = 0 AND section_key = 'bes';
UPDATE sidebar_sections SET allow_template_fork = 1, template_origin = 'risdoc'
    WHERE institute_id = 0 AND section_key = 'risdoc';

-- Backfill section_id sui contenuti esistenti (mapping content_type → default).
UPDATE teacher_content_data tcd
   JOIN sidebar_sections ss
     ON ss.institute_id = 0 AND ss.default_content_type = tcd.content_type
    SET tcd.section_id = ss.id
  WHERE tcd.section_id IS NULL;
