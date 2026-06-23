-- 080_drop_template_origin.sql — Phase 24.58
-- Rimuove la colonna `origin` (enum risdoc/strcomp) da risdoc_templates.
-- `origin` NON è una partizione: le partizioni sono i `category` flat (077).
-- Il rendering usa body_pt; gli asset (json/immagini come loghi/stemma) sono
-- ora risolti da un'unica cartella storage/templates/risdoc (codice aggiornato:
-- TemplateResolver, TemplateController, RisdocAdminController). Idempotente.

-- 1) sgancia l'indice composito che include `origin`
ALTER TABLE risdoc_templates DROP INDEX IF EXISTS idx_rt_origin_category;

-- 2) indice su sola category (filtro/rinomina/listati)
ALTER TABLE risdoc_templates ADD INDEX IF NOT EXISTS idx_rt_category (category);

-- 3) elimina la colonna
ALTER TABLE risdoc_templates DROP COLUMN IF EXISTS origin;
