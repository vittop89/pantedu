-- Migration 091 — Scope studente persistito su `users` (ADR student-scope).
--
-- Perché: indirizzo/classe dello studente vivevano SOLO nello store JSON utenti
-- (non in colonne DB), così `ContentStudyController::viewerContext()` non poteva
-- ancorare lo scope (istituto+indirizzo+classe) all'account → lo scope cadeva sui
-- parametri URL e l'istituto restava NULL (possibile leak cross-istituto).
--
-- Da ora le colonne sono autoritative; alimentate da RegistrationService::approve
-- e lette da StudentProfileService per costruire il ViewerContext studente.
--
-- institute_id esiste già su users (Phase 13). Qui aggiungiamo indirizzo+classe.

ALTER TABLE users
    ADD COLUMN indirizzo VARCHAR(64) NULL AFTER institute_id,
    ADD COLUMN classe    VARCHAR(32) NULL AFTER indirizzo;

-- Indice per lo scope studente (institute + indirizzo + classe).
ALTER TABLE users
    ADD INDEX idx_users_scope (institute_id, indirizzo, classe);
