-- Phase 20 — Migration 003 — teacher_content.shared_with_pool
-- Flag condivisione pool istituto (Phase 14).

ALTER TABLE teacher_content
    ADD COLUMN shared_with_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility;
