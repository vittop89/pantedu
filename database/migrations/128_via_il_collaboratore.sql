-- SICUREZZA: toglie il valore `collaborator` dai ruoli che il database accetta, e converte in `teacher` chi lo avesse. Misurato il 15/9/2026: in produzione, sviluppo e prova nessun utente ha quel ruolo, quindi nessuna riga cambia; nei dati di produzione la parola compare solo nel testo di due contenuti didattici, che non si toccano. Il codice di prima non scrive `collaborator` da nessuna parte fuori dal pannello «Utenti», che con questa versione non lo offre più.
-- ROLLBACK: ALTER TABLE users DROP CONSTRAINT chk_users_ruolo; ALTER TABLE users ADD CONSTRAINT chk_users_ruolo CHECK (role IN ('student', 'teacher', 'collaborator', 'administrator', 'institute_admin')). Le righe convertite, se ce ne fossero state, non si distinguono più dai docenti.
--
-- Il collaboratore non esiste più (15/9/2026, scelta dell'utente: «togli
-- completamente il collaboratore»).
--
-- Il ruolo non apriva niente che un docente non aprisse: la sua zona di
-- accesso aveva tre indirizzi vecchi che rispondono 410 (ora nella zona
-- dell'amministratore) e un gruppo di API vuoto dal 26 agosto. Nei controlli
-- di accesso era trattato come un docente. Il pannello «Utenti» lo offriva
-- ancora come ruolo da assegnare.
--
-- Il vincolo dei ruoli è quello della migrazione 125, senza `collaborator`.
-- Non riguarda i collaboratori dei modelli risdoc
-- (`risdoc_template_collaborators`): sono docenti che lavorano su un modello,
-- e restano.

SET NAMES utf8mb4;

UPDATE users SET role = 'teacher' WHERE role = 'collaborator';

ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_ruolo;
ALTER TABLE users
    ADD CONSTRAINT chk_users_ruolo
        CHECK (role IN ('student', 'teacher', 'administrator', 'institute_admin'));
