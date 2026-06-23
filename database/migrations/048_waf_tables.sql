-- 048_waf_tables.sql
-- WAF Self-Hosted con GeoIP + Browser Fingerprinting
-- Implementa il prompt docs/todo/waf_security_prompt.md adattato a stack
-- pantedu (PHP middleware + admin UI invece di OpenResty/Lua).
--
-- Tabelle:
--   waf_config       key/value singleton — toggle + soglie + modalità
--   waf_logs         log strutturato richieste WAF-scrutiniate (ttl 7gg via cron)
--   waf_rules        custom rules engine (operatori IP/ASN/Country/UA/URL/Referer)
--   waf_blocked_ips  manual blacklist con TTL e reason
--   waf_whitelisted_ips  manual whitelist (es. team dev, monitoring, googlebot)

SET NAMES utf8mb4;
SET TIME_ZONE = '+00:00';

-- =========================================================================
-- waf_config: configurazione singleton key/value
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_config` (
    `config_key`   VARCHAR(64)  NOT NULL,
    `config_value` TEXT         NOT NULL,
    `description`  VARCHAR(255) DEFAULT NULL,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed config defaults
INSERT INTO `waf_config` (`config_key`, `config_value`, `description`) VALUES
    ('enabled',           '0',                      'Master switch: 1=WAF attivo, 0=bypass'),
    ('mode',              'monitor',                'off | monitor | soft | enforce | under_attack'),
    ('threshold_pass',    '40',                     'Score <= passa diretto (default 40)'),
    ('threshold_block',   '70',                     'Score > blocca (default 70). Soft tra pass e block.'),
    ('session_ttl',       '3600',                   'Cookie waf_session TTL secondi (default 1h)'),
    ('geo_allowed',       'IT',                     'CSV codici paese ISO-3166 alpha-2 ammessi'),
    ('geo_mode',          'monitor',                'off | monitor | enforce (blocco geo)'),
    ('challenge_template','invisible',              'invisible | interstitial | under_attack'),
    ('log_retention_days','7',                      'Giorni retention waf_logs (cleanup cron)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- =========================================================================
-- waf_logs: log strutturato di ogni request scrutinizzata dal WAF
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ts`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip`            VARCHAR(45)  NOT NULL,
    `country`       CHAR(2)      DEFAULT NULL,
    `asn`           VARCHAR(64)  DEFAULT NULL,
    `user_agent`    VARCHAR(512) DEFAULT NULL,
    `request_uri`   VARCHAR(512) DEFAULT NULL,
    `method`        VARCHAR(8)   DEFAULT 'GET',
    `referer`       VARCHAR(512) DEFAULT NULL,
    `score`         TINYINT      UNSIGNED DEFAULT NULL,
    `challenge`     VARCHAR(16)  DEFAULT NULL,
    `outcome`       VARCHAR(16)  NOT NULL,
    `rule_id`       INT UNSIGNED DEFAULT NULL,
    `session_token` VARCHAR(64)  DEFAULT NULL,
    `fp_hash`       CHAR(64)     DEFAULT NULL,
    `request_id`    CHAR(36)     DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ts`         (`ts`),
    INDEX `idx_ip`         (`ip`),
    INDEX `idx_outcome`    (`outcome`),
    INDEX `idx_country`    (`country`),
    INDEX `idx_request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_rules: custom rules engine
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_rules` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(128) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
    `priority`    SMALLINT     NOT NULL DEFAULT 100,
    `conditions`  JSON         NOT NULL,
    `action`      VARCHAR(16)  NOT NULL,
    `match_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_enabled_priority` (`enabled`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_blocked_ips: blacklist manuale con TTL
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_blocked_ips` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_or_cidr` VARCHAR(64)  NOT NULL,
    `reason`     VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP    NULL DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `hit_count`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ip` (`ip_or_cidr`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_whitelisted_ips: whitelist manuale (bypass WAF completo)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_whitelisted_ips` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_or_cidr` VARCHAR(64)  NOT NULL,
    `reason`     VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP    NULL DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ip` (`ip_or_cidr`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
