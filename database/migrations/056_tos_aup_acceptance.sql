-- 056_tos_aup_acceptance.sql
-- Phase 25.P — ToS / AUP click-acceptance tracking per multi-tenancy.
--
-- Pre-requisito per Scenario B/C (estensione pantedu ad altri docenti).
-- Vedi: docs/todo/multitenancy_responsibility_framework.md §3.1
--       docs/legal/tos_docente.md
--       docs/legal/aup.md
--
-- Tabella: user_tos_acceptance
--   user_id         FK users.id
--   tos_version     versione ToS accettata (es. "1.0")
--   aup_version     versione AUP accettata (es. "1.0")
--   accepted_at     timestamp accettazione
--   accepted_ip     IP da cui è stata fatta l'accettazione
--   user_agent      browser/dispositivo accettazione
--   pk: (user_id, tos_version)   — un utente può avere più righe se ToS cambia

SET NAMES utf8mb4;

SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tos_acceptance');
SET @sql := IF(@t_exists = 0,
    'CREATE TABLE user_tos_acceptance (
        user_id        INT UNSIGNED NOT NULL,
        tos_version    VARCHAR(20) NOT NULL,
        aup_version    VARCHAR(20) NOT NULL DEFAULT ''1.0'',
        accepted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        accepted_ip    VARCHAR(45) NOT NULL,
        user_agent     VARCHAR(512) DEFAULT NULL,
        PRIMARY KEY (user_id, tos_version),
        KEY idx_accepted_at (accepted_at),
        CONSTRAINT fk_tos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Phase 25.P — track ToS/AUP click-acceptance per Scenario B/C''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
