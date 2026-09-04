-- 077_template_partitions_flat.sql — ADR-027
-- Partizioni template flat = category: modelli, risorse, altro, bes (STRCOMP→bes).
-- category era un ENUM → convertito a VARCHAR per ammettere i nuovi nomi.
-- origin e source_dir INVARIATI (rendering usa body_pt; path file-based legacy).
-- Idempotente.

ALTER TABLE risdoc_templates MODIFY category VARCHAR(64) NOT NULL DEFAULT '';

UPDATE risdoc_templates SET category='modelli' WHERE category='MODELLI';
UPDATE risdoc_templates SET category='risorse' WHERE category='RISORSE';
UPDATE risdoc_templates SET category='altro'   WHERE category='ALTRO';
UPDATE risdoc_templates SET category='bes'     WHERE category='STRCOMP';
-- fix: la riga STRCOMP svuotata da un tentativo precedente (enum reject) → bes
UPDATE risdoc_templates SET category='bes'     WHERE category='';

UPDATE sidebar_sections SET template_groups='["modelli","risorse"]', default_categories='["modelli","risorse"]'
    WHERE institute_id=0 AND section_key='risdoc';
UPDATE sidebar_sections SET template_groups='["bes","altro"]', default_categories='["bes","altro"]'
    WHERE institute_id=0 AND section_key='bes';
