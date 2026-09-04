-- 083_sidebar_sections_lock_default_categories.sql — Phase 25
-- L'admin decide per-sezione se le CATEGORIE PREDEFINITE (modelli/risorse,
-- bes/altro) possono essere rinominate/eliminate dal docente nella pagina
-- /area-docente/categorie. Default 1 = bloccate (comportamento attuale).
-- Idempotente.

ALTER TABLE sidebar_sections
    ADD COLUMN IF NOT EXISTS lock_default_categories TINYINT(1) NOT NULL DEFAULT 1
    AFTER allow_template_fork;
