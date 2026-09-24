-- G22.S26 — Refactor permessi risdoc template:
--   1. Drop owner_id (mai usato in produzione — 0 templates non-NULL).
--      Modello semplificato: solo collaborator + visible. Tutti i
--      template senza collab attivi sono "istituzionali" (modificabili
--      solo da super-admin).
--   2. Aggiunge flag requires_review su collaborator: se 1, le modifiche
--      del collaboratore vanno in coda pending invece di applicarsi
--      direttamente all'institutional override.
--   3. Crea tabella risdoc_template_pending_changes per audit + revisione
--      super-admin (approve|reject).

-- Idempotent guards: ogni statement controlla esistenza prima di
-- eseguire (le migration in pantedu non hanno tracking automatico).

-- 1. Drop owner_id se presente. Ordine: FK → INDEX → COLUMN.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'risdoc_templates'
               AND CONSTRAINT_NAME = 'fk_rt_owner'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk > 0,
    'ALTER TABLE risdoc_templates DROP FOREIGN KEY fk_rt_owner',
    'SELECT "fk_rt_owner già rimossa" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'risdoc_templates'
                AND INDEX_NAME = 'idx_rt_owner');
SET @sql := IF(@idx > 0,
    'ALTER TABLE risdoc_templates DROP INDEX idx_rt_owner',
    'SELECT "idx_rt_owner già rimosso" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'risdoc_templates'
                AND COLUMN_NAME = 'owner_id');
SET @sql := IF(@col > 0,
    'ALTER TABLE risdoc_templates DROP COLUMN owner_id',
    'SELECT "owner_id già rimossa" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Aggiunge requires_review a risdoc_template_collaborators
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'risdoc_template_collaborators'
                AND COLUMN_NAME = 'requires_review');
SET @sql := IF(@col = 0,
    'ALTER TABLE risdoc_template_collaborators
        ADD COLUMN requires_review TINYINT(1) NOT NULL DEFAULT 0
        AFTER role',
    'SELECT "requires_review già presente" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Crea risdoc_template_pending_changes
CREATE TABLE IF NOT EXISTS risdoc_template_pending_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL COMMENT 'html|tex|css|json|image|texCommon|schema',
    path VARCHAR(500) NOT NULL DEFAULT '',
    content_encoding ENUM('utf8', 'base64') NOT NULL DEFAULT 'utf8'
        COMMENT 'base64 per file binari (image), utf8 per testo',
    content LONGBLOB NOT NULL COMMENT 'payload della modifica (encoded per encoding)',
    note TEXT NULL COMMENT 'commento opzionale del submitter',
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL COMMENT 'motivazione approve/reject (richiesta per reject)',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_template_status (template_id, status),
    INDEX idx_submitter (submitted_by, submitted_at),
    INDEX idx_pending (status, submitted_at),
    CONSTRAINT fk_pc_template FOREIGN KEY (template_id)
        REFERENCES risdoc_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_submitter FOREIGN KEY (submitted_by)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
