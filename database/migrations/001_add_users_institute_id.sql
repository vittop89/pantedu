-- Phase 20 — Migration 001 — users.institute_id
-- Aggiunge colonna istituto allo user (studenti afferiscono a 1 istituto).
-- Originariamente commento in schema.sql, ora eseguita automaticamente.

ALTER TABLE users
    ADD COLUMN institute_id INT UNSIGNED NULL AFTER active;

ALTER TABLE users
    ADD CONSTRAINT fk_users_inst
        FOREIGN KEY (institute_id) REFERENCES institutes(id) ON DELETE SET NULL;
