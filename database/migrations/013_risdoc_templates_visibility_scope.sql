-- Phase 25.B3 — visibility_scope per template istituzionali.
--
-- Pre-Phase 25.B3: Permission::canView ritorna true per qualsiasi teacher
-- autenticato se `owner_id IS NULL` (template istituzionale). Default
-- "all-teachers-public" intenzionale per backward-compat ma troppo permissivo
-- per scenari multi-istituto / multi-classe / multi-indirizzo.
--
-- Phase 25.B3: aggiunge enum `visibility_scope` per gating granulare:
--   - 'public'    → tutti i teacher autenticati (default, backward-compat)
--   - 'institute' → solo teacher dello stesso institute_id (M2 future)
--   - 'indirizzo' → solo teacher con curriculum.indirizzo che match
--   - 'classe'    → solo teacher con curriculum.classe che match
--   - 'denied'    → solo owner+collab+super_admin (nessun visibility default)
--
-- ALTER additivo (zero-downtime, safe rollback):
--   ALTER TABLE risdoc_templates DROP COLUMN visibility_scope;
--
-- Lo scope effettivo (institute/indirizzo/classe) richiede campi tassonomia
-- sul template (institute_id / scope_indirizzo / scope_classe) — aggiunti
-- come opzionali per evitare M2 cross-table check costosi. Default NULL =
-- "qualsiasi" per il dimensione corrispondente.

ALTER TABLE risdoc_templates
    ADD COLUMN visibility_scope ENUM('public', 'institute', 'indirizzo', 'classe', 'denied')
        NOT NULL DEFAULT 'public'
        AFTER owner_id,
    ADD COLUMN scope_institute_id INT UNSIGNED NULL
        AFTER visibility_scope,
    ADD COLUMN scope_indirizzo VARCHAR(16) NULL
        AFTER scope_institute_id,
    ADD COLUMN scope_classe VARCHAR(16) NULL
        AFTER scope_indirizzo,
    ADD INDEX idx_visibility_scope (visibility_scope),
    ADD INDEX idx_scope_indirizzo (scope_indirizzo),
    ADD INDEX idx_scope_classe (scope_classe);

-- Backfill: tutti i template esistenti con owner_id IS NULL restano 'public'
-- (default), preservando il comportamento Phase 24.62.
-- I template con owner_id NOT NULL (privati) → 'denied' (gestiti via
-- isOwner/isCollaborator/hasVisibility).
UPDATE risdoc_templates SET visibility_scope = 'denied' WHERE owner_id IS NOT NULL;
