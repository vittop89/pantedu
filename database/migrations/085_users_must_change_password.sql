-- 085_users_must_change_password.sql — Phase 25.R.31 (Audit L7)
-- Forza il cambio password al primo login per gli account creati con password
-- one-time (es. admin iniziale di un nuovo istituto). Default 0 = nessun obbligo.
-- Idempotente.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0
    AFTER password_hash;
