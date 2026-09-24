-- 084_sidebar_sections_lock_custom_categories.sql — Phase 25
-- L'admin decide per-sezione se il docente può CREARE nuove categorie custom
-- (in /area-docente/categorie). Default 0 = creazione consentita (comportamento
-- attuale). Complementa lock_default_categories (rinomina predefinite).
-- Idempotente.

ALTER TABLE sidebar_sections
    ADD COLUMN IF NOT EXISTS lock_custom_categories TINYINT(1) NOT NULL DEFAULT 0
    AFTER lock_default_categories;
