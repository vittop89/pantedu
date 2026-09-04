-- G22.S23 — verifica_documents.shared_with_pool
--
-- Le verifiche reali (file TEX/PDF cifrati) vivono in verifica_documents.
-- Finora il share-pool era solo su teacher_content (esercizi/mappe).
-- Aggiungiamo il flag per permettere ai docenti di condividere LE VERIFICHE
-- TEX/PDF con i colleghi dello stesso istituto.
--
-- Idempotente.

ALTER TABLE verifica_documents_data
    ADD COLUMN IF NOT EXISTS shared_with_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER variant;

-- Ricrea la view verifica_documents per esporre la nuova colonna.
DROP VIEW IF EXISTS verifica_documents;
CREATE VIEW verifica_documents AS
SELECT vd.id, vd.teacher_id, vd.materia_id, vd.indirizzo_id, vd.classe_id,
       vd.title, vd.fm_db_section, vd.batch_id, vd.variant, vd.shared_with_pool,
       vd.version_label, vd.exercise_ids, vd.selection_json,
       vd.tex_blob_path, vd.tex_blob_kv, vd.tex_size, vd.tex_files, vd.tex_sha256,
       vd.pdf_blob_path, vd.pdf_blob_kv, vd.pdf_size, vd.pdf_filename, vd.pdf_uploaded_at,
       vd.drive_file_id, vd.drive_synced_at,
       vd.created_at, vd.updated_at,
       ci.code AS indirizzo, cc.code AS classe, cm.code AS materia
  FROM verifica_documents_data vd
  LEFT JOIN curriculum_entries ci ON ci.id = vd.indirizzo_id AND ci.kind = 'indirizzi'
  LEFT JOIN curriculum_entries cc ON cc.id = vd.classe_id    AND cc.kind = 'classi'
  LEFT JOIN curriculum_entries cm ON cm.id = vd.materia_id   AND cm.kind = 'materie';

-- Index per query pool eligibility frequente.
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'verifica_documents_data'
      AND INDEX_NAME = 'idx_vd_shared_with_pool'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE verifica_documents_data ADD INDEX idx_vd_shared_with_pool (shared_with_pool, teacher_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
