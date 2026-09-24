-- Phase 20 — Migration 002 — users.is_super_admin
-- Flag tecnico ortogonale al role (Phase 14).

ALTER TABLE users
    ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Promozione admin esistenti → super-admin (una tantum al momento della
-- migrazione; se l'utente vuole rollback, set = 0 manualmente).
UPDATE users SET is_super_admin = 1 WHERE role = 'administrator' OR role = 'admin';
