-- Phase 25.C4 — Aggiunge users.deleted_at per soft-delete Art. 17 GDPR.
--
-- Soft-delete pattern: invece di DELETE FROM users (rompe FK + audit),
-- settiamo deleted_at = NOW() + email/name anonymized + active=0.
-- Hard-delete (rimozione fisica) avviene 90g dopo via cron separato.
--
-- WHERE clauses esistenti devono escludere righe deleted_at IS NOT NULL
-- (Phase 25.C lavoro su query esistenti).

ALTER TABLE users
    ADD COLUMN deleted_at TIMESTAMP NULL AFTER active,
    ADD INDEX idx_deleted_at (deleted_at);
