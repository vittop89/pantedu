-- Migration 093 — ADR-027: modalità assegnazione 'docenti' per sezione sidebar.
--
-- teacher_scope:
--   all      → visibile a tutti i docenti (default)
--   scope    → visibile ai docenti che insegnano nell'istituto/indirizzo/classe
--              indicati in teacher_scope_value (JSON {institute_id,indirizzo,classe});
--              i campi vuoti = nessun vincolo su quella dimensione (→ tutti).
--   teachers → visibile solo ai docenti elencati in sidebar_section_teachers (mig 092)

ALTER TABLE sidebar_sections
    ADD COLUMN teacher_scope ENUM('all','scope','teachers') NOT NULL DEFAULT 'all' AFTER publish_public,
    ADD COLUMN teacher_scope_value VARCHAR(255) NULL AFTER teacher_scope;
