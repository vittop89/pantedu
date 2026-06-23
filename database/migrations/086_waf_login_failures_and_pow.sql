-- Migration 086 — WAF hardening (audit sicurezza 2026-06-01)
--   1. Tabella waf_login_failures: traccia i fallimenti di login per il ponte
--      brute-force → auto-ban (per-IP credential stuffing + lockout temporaneo
--      per-username). Enforcement riusa waf_blocked_ips / waf_blocked_credentials.
--   2. Seed dei nuovi toggle waf_config: Proof-of-Work + soglie brute-force.
-- Idempotente.

CREATE TABLE IF NOT EXISTS waf_login_failures (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45)  NOT NULL,
    username   VARCHAR(190) NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time   (ip, created_at),
    INDEX idx_user_time (username, created_at),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nuovi toggle operativi (INSERT IGNORE: non sovrascrive valori esistenti).
INSERT IGNORE INTO waf_config (config_key, config_value) VALUES
    ('pow_enabled',              '1'),    -- Proof-of-Work attivo
    ('pow_required',             '0'),    -- roll-out: non rifiutare PoW assente
    ('pow_bits',                 '14'),   -- difficoltà (3G-safe); admin può alzare
    ('bf_user_threshold',        '10'),   -- N fallimenti/username/finestra → lockout
    ('bf_user_window_sec',       '900'),  -- finestra conteggio username (15 min)
    ('bf_user_lock_sec',         '900'),  -- durata lockout username (15 min)
    ('bf_ip_distinct_threshold', '15'),   -- N username DISTINTI falliti/IP → ban IP
    ('bf_ip_window_sec',         '600'),  -- finestra conteggio IP (10 min)
    ('bf_ip_ban_sec',            '1800'); -- durata ban IP (30 min)
