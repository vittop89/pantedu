-- 053_waf_rule_asn_category.sql
-- Phase 25.I — rule che usa nuovo operator asn_in_category.
-- Vantaggio: copre dinamicamente TUTTI i 722+ ASN di brianhama/bad-asn-list
-- (refresh settimanale) invece di lista hardcoded in 051.
--
-- La rule 051 "Bot detection: cloud ASN + browser UA" (hardcoded list)
-- resta attiva per backup + ASN noti non in bulk list (DEDIK, etc.).
-- Le 2 rules sono complementari, valutate in priorità.

SET NAMES utf8mb4;

INSERT IGNORE INTO `waf_rules`
    (`name`, `description`, `enabled`, `priority`, `conditions`, `action`)
VALUES
(
    'Bot detection: ASN category hosting + browser UA',
    'Match dinamico contro waf_asn_categories (refresh settimanale da brianhama/bad-asn-list). Copre ~700 ASN cloud/hosting. UA browser Mozilla → challenge (cookie obbliga JS execution, bot scraper falliscono).',
    1,
    55,
    '{"logic":"AND","conditions":[{"field":"asn","operator":"asn_in_category","value":"hosting"},{"field":"user_agent","operator":"starts_with","value":"Mozilla/5.0"}]}',
    'challenge'
);
