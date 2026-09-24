-- SICUREZZA: nuova tabella per il cambio dell'email dell'account con conferma. Conserva l'hash del link (mai il link), il nuovo indirizzo finché il cambio non è confermato o scaduto, l'hash dell'IP e le date. Non tocca dati esistenti.
-- ROLLBACK: DROP TABLE email_change_requests; il codice di prima non cambia l'email da nessuna pagina con conferma (restava solo POST /me/profile, senza conferma).
--
-- Il cambio dell'email dell'account (15/9/2026, richiesta dell'utente: «manca
-- proprio la gestione dell'email»).
--
-- L'email è il canale del recupero password e del secondo fattore via email:
-- chi la cambia può prendersi l'account. Fino a oggi POST /me/profile la
-- cambiava con il solo gettone CSRF, senza password e senza verificare il nuovo
-- indirizzo. Adesso si chiede la password, si manda un link monouso al nuovo
-- indirizzo e un avviso al vecchio, e l'email cambia solo aprendo il link.
--
-- Come password_resets (migrazione 095): hash del token, used_at invece di
-- DELETE (resta la traccia di chi ha cambiato l'email e quando), IP come hash.
-- La purga è in tools/audit/tabelle_da_purgare.php, un anno come i log d'accesso.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS email_change_requests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    new_email         VARCHAR(255) NOT NULL COMMENT 'il nuovo indirizzo, da confermare',
    token_hash        CHAR(64)     NOT NULL COMMENT 'SHA-256 del token; il token in chiaro vive solo nel link',
    expires_at        DATETIME     NOT NULL,
    used_at           DATETIME     DEFAULT NULL,
    requested_ip_hash CHAR(64)     DEFAULT NULL COMMENT 'SHA-256 dell IP',
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_change_token (token_hash),
    KEY idx_email_change_user (user_id, used_at),
    KEY idx_email_change_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
