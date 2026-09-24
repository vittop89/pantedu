-- 105 — Portachiavi di credenziali di classe (2026-09-05)
--
-- Piano docs/plans/classi-credenziali-scenari.md, passo C. Lo studente
-- tiene piu' credenziali insieme (una per docente); ogni credenziale ha:
--
--   expires_at              scadenza, per default il 31 agosto dell'anno
--                           scolastico in corso: a fine anno cade da se';
--   last_used_at, use_count quante volte e quando e' stata usata — solo
--                           numeri, nessuna identita' di chi entra;
--   qr_token                il codice del QR permanente della credenziale:
--                           chi lo scansiona entra senza digitare nulla;
--   temp_token (+ scadenza) il codice a tempo proiettato in classe, dieci
--                           minuti: niente password stabile da far girare.
--
-- La VIEW teacher_access_credentials e' `SELECT tac.*` (040): MySQL espande
-- `*` alla creazione, quindi va ricreata per vedere le colonne nuove.
-- Idempotente: information_schema guard + OR REPLACE.

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data' AND COLUMN_NAME = 'expires_at');
SET @sql := IF(@c = 0, 'ALTER TABLE teacher_access_credentials_data ADD COLUMN expires_at DATE NULL AFTER active', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data' AND COLUMN_NAME = 'last_used_at');
SET @sql := IF(@c = 0, 'ALTER TABLE teacher_access_credentials_data ADD COLUMN last_used_at DATETIME NULL AFTER expires_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data' AND COLUMN_NAME = 'use_count');
SET @sql := IF(@c = 0, 'ALTER TABLE teacher_access_credentials_data ADD COLUMN use_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_used_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data' AND COLUMN_NAME = 'qr_token');
SET @sql := IF(@c = 0, 'ALTER TABLE teacher_access_credentials_data ADD COLUMN qr_token CHAR(43) NULL AFTER use_count, ADD UNIQUE KEY uq_tac_qr_token (qr_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data' AND COLUMN_NAME = 'temp_token');
SET @sql := IF(@c = 0, 'ALTER TABLE teacher_access_credentials_data ADD COLUMN temp_token CHAR(43) NULL AFTER qr_token, ADD COLUMN temp_token_expires_at DATETIME NULL AFTER temp_token, ADD INDEX idx_tac_temp_token (temp_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Stessa definizione della 040: `tac.*` viene riespanso alla ricreazione.
CREATE OR REPLACE VIEW teacher_access_credentials AS
SELECT tac.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM teacher_access_credentials_data tac
LEFT JOIN curriculum_entries ci ON ci.id = tac.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tac.classe_id    AND cc.kind='classi';
