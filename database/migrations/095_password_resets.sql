-- 095_password_resets.sql
-- Recupero password via email — token monouso a scadenza.
--
-- PERCHE' (2026-09-01)
--
-- Fino a oggi chi dimenticava la password non aveva alcuna strada: nessuna
-- rotta di recupero, e la pagina di login si limitava a "Contatta
-- l'amministratore". Su una piattaforma dove l'amministratore e' una persona
-- sola, questo significa che ogni dimenticanza si ferma finche' quella
-- persona non legge un messaggio. Con il secondo fattore appena introdotto la
-- questione peggiora: piu' modi di restare chiusi fuori, nessuno di rientrare.
--
-- SCELTE DI PROGETTO
--
--   · Si conserva l'HASH del token, mai il token. Chi legge il database non
--     puo' fabbricare un link valido: un dump non e' una scorciatoia per
--     entrare negli account.
--
--   · used_at invece di DELETE: un token consumato resta a registro. Serve a
--     rispondere in modo verificabile a "qualcuno ha reimpostato la mia
--     password?", che senza traccia sarebbe una domanda senza risposta.
--
--   · L'IP si conserva come HASH, non in chiaro. Il registro art. 30 (B.6)
--     dichiara come misura "hash unidirezionale, no IP/UA in chiaro":
--     aprire qui un archivio di IP leggibili avrebbe contraddetto una misura
--     gia' dichiarata al DPO, e per lo scopo — accorgersi che qualcuno
--     martella il modulo — raggruppare per hash basta, leggere l'indirizzo no.
--     Stesso schema di ConsentService (sha256 dell'IP).
--
--   · Nessuna FK con ON DELETE CASCADE verso users: la cancellazione di un
--     utente passa dal crypto-shredding GDPR, che gestisce le sue tabelle in
--     ordine esplicito. Una cascata silenziosa qui renderebbe piu' difficile
--     dimostrare cosa e' stato cancellato e quando.

SET NAMES utf8mb4;

SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets');

SET @sql := IF(@t_exists = 0,
'CREATE TABLE password_resets (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL COMMENT "SHA-256 del token; il token in chiaro vive solo nel link",
    expires_at    DATETIME     NOT NULL,
    used_at       DATETIME     DEFAULT NULL,
    requested_ip_hash CHAR(64) DEFAULT NULL COMMENT "SHA-256 dell IP: raggruppabile per triage abusi, non leggibile",
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, used_at),
    KEY idx_password_resets_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
'SELECT "password_resets already exists" AS note');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
