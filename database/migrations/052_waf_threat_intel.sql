-- 052_waf_threat_intel.sql
-- Phase 25.I — Threat Intelligence Layer.
--
-- 3 tabelle per import bulk da fonti pubbliche:
--   waf_asn_categories  → ASN classificati (hosting/vpn/tor/malware/cdn)
--   waf_threat_ips      → IP singoli (fast O(1) lookup via PK)
--   waf_threat_cidrs    → CIDR ranges (linear scan, ~700 max Spamhaus)
--
-- Source values (per filtraggio/refresh granulare):
--   bad_asn_list   → brianhama/bad-asn-list (ASN)
--   spamhaus_drop  → Spamhaus DROP+EDROP (CIDR)
--   x4b_vpn        → X4BNet/lists_vpn (IP)
--   crowdsec       → CrowdSec community blocklist (IP)
--   tor            → check.torproject.org/exit-addresses (IP)

SET NAMES utf8mb4;

-- =========================================================================
-- waf_asn_categories: ASN → category (hosting/vpn/tor/cdn/malware)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_asn_categories` (
    `asn`         INT UNSIGNED NOT NULL,
    `category`    VARCHAR(32)  NOT NULL,
    `org`         VARCHAR(255) DEFAULT NULL,
    `source`      VARCHAR(32)  NOT NULL,
    `imported_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`asn`, `source`),
    INDEX `idx_category` (`category`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_threat_ips: IP singoli da threat intel (O(1) lookup)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_threat_ips` (
    `ip`          VARCHAR(45)  NOT NULL,
    `source`      VARCHAR(32)  NOT NULL,
    `action`      VARCHAR(16)  NOT NULL DEFAULT 'block',
    `reason`      VARCHAR(255) DEFAULT NULL,
    `imported_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`ip`, `source`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_source_action` (`source`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_threat_cidrs: CIDR ranges da threat intel (linear scan, max ~few k)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_threat_cidrs` (
    `cidr`        VARCHAR(64)  NOT NULL,
    `source`      VARCHAR(32)  NOT NULL,
    `action`      VARCHAR(16)  NOT NULL DEFAULT 'block',
    `reason`      VARCHAR(255) DEFAULT NULL,
    `imported_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`cidr`, `source`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_source_action` (`source`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- waf_threat_sync_log: tracking sync jobs (per admin UI)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `waf_threat_sync_log` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source`        VARCHAR(32)  NOT NULL,
    `started_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`   TIMESTAMP    NULL DEFAULT NULL,
    `rows_imported` INT UNSIGNED DEFAULT NULL,
    `rows_pruned`   INT UNSIGNED DEFAULT NULL,
    `status`        VARCHAR(16)  NOT NULL DEFAULT 'running',
    `error`         TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_source_started` (`source`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed config keys per threat intel
INSERT INTO `waf_config` (`config_key`, `config_value`, `description`) VALUES
    ('threat_intel_enabled', '1', 'Master switch threat intel (asn_categories + threat_ips + threat_cidrs check in middleware)'),
    ('crowdsec_api_key',     '',  'CrowdSec community blocklist API key (https://app.crowdsec.net free signup)'),
    ('abuseipdb_api_key',    '',  'AbuseIPDB API key (https://www.abuseipdb.com free 1000 req/day) — opzionale')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
