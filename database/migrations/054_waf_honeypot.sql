-- 054_waf_honeypot.sql
-- Phase 25.J — Honeypot endpoints (auto-blacklist scanner).
--
-- Aggiunge seed config + honeypot stats counter. I log degli hit
-- vanno in waf_logs (outcome='honeypot_trap'), gli IP catturati
-- vengono auto-blacklist in waf_threat_ips (source='honeypot', TTL 30gg).

SET NAMES utf8mb4;

INSERT INTO `waf_config` (`config_key`, `config_value`, `description`) VALUES
    ('honeypot_enabled', '1', 'Honeypot trap su path fake (wp-login, .env, .git, phpmyadmin) — auto-blacklist 30gg'),
    ('honeypot_action',  'block', 'Action su hit honeypot: block | challenge | log_only')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
