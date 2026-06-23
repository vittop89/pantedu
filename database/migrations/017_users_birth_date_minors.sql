-- Phase 25.C7 — Aggiunge users.birth_date per validazione Art. 8 GDPR (minori).
--
-- D.Lgs. 101/2018 (Italia): consenso autonomo da 14 anni.
-- Sotto 14 anni: parent_consent obbligatorio (vedi parent_consents Phase 25.C1).
--
-- Field nullable: utenti pre-Phase 25.C7 non hanno birth_date. RegistrationService
-- richiede il campo solo per role='student' nei nuovi signup. Esistenti restano
-- legacy (se serve, prompt al login per completare profilo).
--
-- Rollback safe:
--   ALTER TABLE users DROP COLUMN birth_date, DROP INDEX idx_birth_date;

ALTER TABLE users
    ADD COLUMN birth_date DATE NULL AFTER email,
    ADD INDEX idx_birth_date (birth_date);
