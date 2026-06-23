-- 076_sidebar_sections_template_groups.sql — ADR-027
-- Una sezione fork può selezionare uno o più GRUPPI di template (origin/category)
-- invece di una sola origine. template_groups = JSON list di "origin/category".
-- Backfill: sezioni fork → tutti i gruppi dell'origine corrente.
-- Idempotente.

ALTER TABLE sidebar_sections
    ADD COLUMN IF NOT EXISTS template_groups LONGTEXT NULL
        CHECK (template_groups IS NULL OR json_valid(template_groups)) AFTER template_origin;

UPDATE sidebar_sections SET template_groups = '["risdoc/MODELLI","risdoc/RISORSE"]'
    WHERE allow_template_fork = 1 AND template_origin = 'risdoc' AND template_groups IS NULL;
UPDATE sidebar_sections SET template_groups = '["strcomp/STRCOMP","strcomp/ALTRO"]'
    WHERE allow_template_fork = 1 AND template_origin = 'strcomp' AND template_groups IS NULL;
