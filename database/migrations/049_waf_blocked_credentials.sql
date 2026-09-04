-- 049_waf_blocked_credentials.sql
-- Modernizzazione Phase 25.F:
-- Sposta `log/data/blocked_credentials.json` + `log/data/blocked_ips.json`
-- da storage JSON ad-hoc → tabelle DB integrate nel sistema WAF.
--
-- Strategia:
--   waf_blocked_credentials  → nuova tabella (username-level lockout, AuthCode compat)
--   waf_blocked_ips          → riusata (già esistente da 048); aggiunta colonna `section`
--                              per back-compat con SecurityAdminController per-sezione.
--   JSON files               → restano scrivibili come read-cache per AuthCode legacy,
--                              ma DB è source of truth (auto-sync via SecurityAdminController).

SET NAMES utf8mb4;
SET TIME_ZONE = '+00:00';

-- =========================================================================
-- waf_blocked_credentials: lockout username-level (sostituisce blocked_credentials.json)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_blocked_credentials` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`    VARCHAR(128) NOT NULL,
    `reason`      VARCHAR(255) DEFAULT NULL,
    `blocked_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  TIMESTAMP    NULL DEFAULT NULL,
    `blocked_by`  VARCHAR(64)  DEFAULT NULL,
    `source`      VARCHAR(32)  NOT NULL DEFAULT 'manual',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_blocked_ips: estendi con `section` (back-compat SecurityAdminController)
-- ALTER condizionale: se la colonna non esiste, aggiungila.
-- =========================================================================
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waf_blocked_ips'
      AND COLUMN_NAME = 'section'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `waf_blocked_ips` ADD COLUMN `section` VARCHAR(32) DEFAULT NULL AFTER `reason`, ADD COLUMN `source` VARCHAR(32) NOT NULL DEFAULT ''manual'' AFTER `section`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop UNIQUE KEY su ip_or_cidr (incompatibile con per-section blocking).
-- Sostituiamo con (ip_or_cidr, section) composite.
SET @uk_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waf_blocked_ips'
      AND INDEX_NAME = 'uk_ip'
);
SET @sql := IF(@uk_exists > 0,
    'ALTER TABLE `waf_blocked_ips` DROP INDEX `uk_ip`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uk_new := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waf_blocked_ips'
      AND INDEX_NAME = 'uk_ip_section'
);
SET @sql := IF(@uk_new = 0,
    'ALTER TABLE `waf_blocked_ips` ADD UNIQUE KEY `uk_ip_section` (`ip_or_cidr`, `section`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
