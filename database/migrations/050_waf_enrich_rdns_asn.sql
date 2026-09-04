-- 050_waf_enrich_rdns_asn.sql
-- Phase 25.H — toggle config "enrich_rdns_asn".
-- Quando attivo, le tabelle admin (IP Lists / Reports / Credentials /
-- Dashboard recent) mostrano colonne rDNS + ASN, popolate via
-- GeoIpService::enrich($ip) (PTR DNS query + lookup mmdb ASN).

SET NAMES utf8mb4;

INSERT INTO `waf_config` (`config_key`, `config_value`, `description`) VALUES
    ('enrich_rdns_asn', '0', 'Enrichment rDNS + ASN nelle tabelle IP admin (heavy: PTR DNS + mmdb lookup)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
