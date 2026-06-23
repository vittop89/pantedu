-- 059_users_admin_institute.sql
-- Phase 25.Q — Multi-tenancy per istituto: scoping admin a singolo istituto.
--
-- Aggiunge `users.admin_institute_id` (FK opzionale a institutes).
--
-- Semantica:
--   - role='admin' + admin_institute_id = N  → admin di istituto N (scope limitato)
--   - role='admin' + admin_institute_id = NULL + is_super_admin = 1 → super-admin globale
--   - role='admin' + admin_institute_id = NULL + is_super_admin = 0 → admin operativo locale
--                                                                    senza scoping istituto
--                                                                    (transitional, da migrare)
--
-- Studenti hanno già users.institute_id come scope (1:1 fisso).
-- Docenti usano teacher_institutes pivot (M:N).
--
-- Vedi:
--   - app/Core/Auth.php::currentInstitute()
--   - app/Middleware/TenantMiddleware.php
--   - docs/todo/multitenancy_responsibility_framework.md

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────
-- 1. Aggiungi colonna admin_institute_id a users (idempotente)
-- ─────────────────────────────────────────────────────────────────
SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'admin_institute_id');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE users
        ADD COLUMN admin_institute_id INT UNSIGNED DEFAULT NULL
            COMMENT ''Phase 25.Q — scope admin per singolo istituto (NULL=globale o non-admin)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────
-- 2. Foreign key verso institutes (idempotente)
-- ─────────────────────────────────────────────────────────────────
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_admin_institute');
SET @sql := IF(@fk = 0,
    'ALTER TABLE users
        ADD CONSTRAINT fk_users_admin_institute
        FOREIGN KEY (admin_institute_id) REFERENCES institutes(id)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────
-- 3. Index per filtri admin per istituto
-- ─────────────────────────────────────────────────────────────────
SET @i1 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_admin_institute');
SET @sql := IF(@i1 = 0,
    'CREATE INDEX idx_users_admin_institute ON users (admin_institute_id, role)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
