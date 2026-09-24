-- 051_waf_seed_bot_detection_rules.sql
-- Phase 25.H — seed custom rules WAF per pattern bot comuni.
--
-- IDEMPOTENTE: INSERT IGNORE su uk_name (UNIQUE su name).
-- Action default: 'challenge' (non block) per evitare falsi positivi.
-- Admin può cambiare action a 'block' da /admin/waf/rules dopo aver
-- verificato hit count.
--
-- RULE 1: Bot cloud ASN + Mozilla UA → challenge
--   Pattern: scraper su hosting cloud che simulano browser desktop.
--   Esempi reali osservati: PL DEDIK (AS207043), BG Tamatiya (AS50360),
--     US Linode/Akamai (AS63949), AWS (AS16509), DigitalOcean (AS14061),
--     OVH (AS16276).
--   NOTA: Hetzner (AS24940) ESCLUSO perché il VPS stesso gira lì.
--
-- RULE 2: Fake Googlebot — UA contiene "Googlebot" da ASN NON Google
--   Implementazione semplificata: rule "Googlebot UA + ASN cloud
--   non-Google" via is_in_list. Per ora copre i casi noti.

SET NAMES utf8mb4;

-- Aggiungi UNIQUE constraint su name se non esiste (idempotente)
SET @uk := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waf_rules' AND INDEX_NAME = 'uk_name');
SET @sql := IF(@uk = 0,
    'ALTER TABLE waf_rules ADD UNIQUE KEY uk_name (name)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `waf_rules`
    (`name`, `description`, `enabled`, `priority`, `conditions`, `action`)
VALUES
(
    'Bot detection: cloud ASN + browser UA',
    'Hosting/cloud ASN (non-residential) con UA browser Mozilla — probabile scraper. Challenge: bot non risponde, browser legittimi su hosting passano cookie.',
    1,
    50,
    '{"logic":"AND","conditions":[{"field":"asn","operator":"is_in_list","value":["AS207043","AS63949","AS50360","AS16509","AS14061","AS16276","AS62240","AS398101"]},{"field":"user_agent","operator":"starts_with","value":"Mozilla/5.0"}]}',
    'challenge'
),
(
    'Fake Googlebot UA',
    'UA contiene "Googlebot" ma ASN NON è AS15169 (Google) — frode SEO/scraping. Real Googlebot rDNS *.googlebot.com da AS15169.',
    1,
    40,
    '{"logic":"AND","conditions":[{"field":"user_agent","operator":"contains","value":"Googlebot"},{"field":"asn","operator":"is_in_list","value":["AS207043","AS63949","AS50360","AS16509","AS14061","AS16276","AS62240","AS398101","AS200612","AS9009","AS47583"]}]}',
    'block'
),
(
    'Fake Bingbot UA',
    'UA contiene "Bingbot/Bingpreview" da ASN NON Microsoft (AS8068/AS8075). Scraper SEO mascherato.',
    1,
    41,
    '{"logic":"AND","conditions":[{"field":"user_agent","operator":"contains","value":"bingbot"},{"field":"asn","operator":"is_in_list","value":["AS207043","AS63949","AS50360","AS16509","AS14061","AS16276","AS62240","AS398101"]}]}',
    'block'
);
