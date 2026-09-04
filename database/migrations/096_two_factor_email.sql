-- 096_two_factor_email.sql
-- Secondo fattore via email, in alternativa all'app di autenticazione.
--
-- PERCHE' (2026-09-02)
--
-- La verifica in due passaggi richiedeva un'app TOTP sul telefono. Chi non ha
-- uno smartphone, o non vuole installarne una, restava senza secondo fattore.
--
-- IL COMPROMESSO, SCRITTO DOVE SI VEDE
--
-- L'email e' un secondo fattore PIU' DEBOLE, e in questo applicativo lo e' in
-- modo particolare: il recupero password passa dalla stessa casella
-- (migration 095). Chi ne prende il controllo ottiene quindi entrambi i
-- fattori, e la verifica in due passaggi smette di aggiungere una barriera —
-- diventa un secondo giro sullo stesso lucchetto.
--
-- Resta comunque meglio della sola password: intercetta il riuso di
-- credenziali e il credential stuffing, che sono gli attacchi frequenti. Ma va
-- offerta come alternativa dichiarata, non come pari grado dell'app: la
-- pagina di scelta lo dice a chiare lettere e consiglia l'app.
--
-- SCELTE
--
--   · `two_factor_method` su users: 'app' | 'email' | NULL. NULL significa
--     nessun secondo fattore, coerente con totp_enabled = 0.
--
--   · I codici in tabella a parte e come HASH, non in chiaro: valgono dieci
--     minuti, ma un dump del database non deve consegnare codici spendibili.
--     Stesso criterio di password_resets.
--
--   · used_at invece di DELETE, per la stessa ragione: resta la traccia di
--     quando un codice e' stato speso.

SET NAMES utf8mb4;

-- 1. Metodo scelto dall'utente.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'two_factor_method');
SET @sql := IF(@c = 0,
    'ALTER TABLE users ADD COLUMN two_factor_method VARCHAR(10) DEFAULT NULL COMMENT "app | email | NULL" AFTER totp_enabled',
    'SELECT "users.two_factor_method already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Codici usa-e-getta spediti per email.
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'two_factor_email_codes');
SET @sql := IF(@t = 0,
'CREATE TABLE two_factor_email_codes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    code_hash   CHAR(64)     NOT NULL COMMENT "SHA-256 del codice; il codice in chiaro vive solo nell email",
    purpose     VARCHAR(16)  NOT NULL DEFAULT "login" COMMENT "login | enrol",
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     DEFAULT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_2fa_email_user (user_id, used_at),
    KEY idx_2fa_email_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
'SELECT "two_factor_email_codes already exists" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Chi ha gia' la 2FA attiva la usa con l'app: nessun altro metodo esisteva.
UPDATE users SET two_factor_method = 'app' WHERE totp_enabled = 1 AND two_factor_method IS NULL;
