-- 055_users_totp.sql
-- Phase 25.J.4 — 2FA TOTP infrastructure (DISABLED di default).
--
-- Aggiunge colonne TOTP a users:
--   totp_secret         varbinary(64)  — Base32 secret (cifrato AES sarebbe meglio,
--                                          ma per ora bytea diretto, accesso solo DBA)
--   totp_enabled        tinyint(1) 0   — user ha completato setup + abilitato
--   totp_backup_codes   json           — 10 codici single-use cifrati (bcrypt)
--   totp_enrolled_at    timestamp NULL — data attivazione
--
-- ALTER condizionale: skip se colonne già esistono (idempotente).

SET NAMES utf8mb4;

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE users ADD COLUMN totp_secret VARBINARY(64) DEFAULT NULL AFTER password_hash',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_enabled');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_backup_codes');
SET @sql := IF(@c3 = 0,
    'ALTER TABLE users ADD COLUMN totp_backup_codes JSON DEFAULT NULL AFTER totp_enabled',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c4 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_enrolled_at');
SET @sql := IF(@c4 = 0,
    'ALTER TABLE users ADD COLUMN totp_enrolled_at TIMESTAMP NULL DEFAULT NULL AFTER totp_backup_codes',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
