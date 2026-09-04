-- 058_teacher_content_source_type.sql
-- Phase 25.P.1 — Copyright source-type protection for sharing.
--
-- Aggiunge campo `source_type` a teacher_content_data + verifica_documents_data
-- (tabelle BASE; teacher_content e verifica_documents sono VIEW che ne fanno
-- lookup con curriculum_entries).
--
-- Valori source_type:
--   - 'personal'      : contenuto creato dal docente, condivisibile
--   - 'book_textbook' : contenuto derivato da libro di testo,
--                       NON condivisibile (uso privato docente ex art. 70-bis)
--   - 'mixed'         : verifica con esercizi misti (personali + libro),
--                       NON condivisibile per cautela
--   - 'public_domain' : oltre 70 anni morte autore o licenza compatibile
--                       → condivisibile
--   - 'cc_licensed'   : licenza Creative Commons compatibile
--                       → condivisibile
--   - NULL            : legacy, strict policy (no share fino a classificazione)
--
-- Rationale: implementa controllo tecnico ex art. 70-bis L. 633/1941
-- (D.Lgs. 177/2021 attuativo Direttiva UE 2019/790):
-- conservazione privata docente LECITA, ma condivisione con terzi VIETATA.
--
-- Le VIEW teacher_content e verifica_documents vengono ricreate per esporre
-- source_type via SELECT esistenti.
--
-- Vedi:
--   - docs/legal/tos_docente.md §2.1
--   - docs/legal/aup.md §1.1, §2.1
--   - app/Services/Sharing/SharedContentPolicy.php

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────
-- 1. Aggiungi colonna source_type a teacher_content_data (idempotente)
-- ─────────────────────────────────────────────────────────────────
SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'teacher_content_data'
      AND COLUMN_NAME = 'source_type');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE teacher_content_data
        ADD COLUMN source_type ENUM(''personal'', ''book_textbook'', ''mixed'', ''public_domain'', ''cc_licensed'')
            DEFAULT NULL
            COMMENT ''Phase 25.P.1 — copyright source classification per share-block''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index per query share-eligibility
SET @i1 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'teacher_content_data'
      AND INDEX_NAME = 'idx_tcd_source_type');
SET @sql := IF(@i1 = 0,
    'CREATE INDEX idx_tcd_source_type ON teacher_content_data (source_type, shared_with_pool)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────
-- 2. Aggiungi colonna source_type a verifica_documents_data (idempotente)
-- ─────────────────────────────────────────────────────────────────
SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'verifica_documents_data'
      AND COLUMN_NAME = 'source_type');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE verifica_documents_data
        ADD COLUMN source_type ENUM(''personal'', ''book_textbook'', ''mixed'', ''public_domain'', ''cc_licensed'')
            DEFAULT NULL
            COMMENT ''Phase 25.P.1 — copyright source classification per share-block''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @i2 := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'verifica_documents_data'
      AND INDEX_NAME = 'idx_vdd_source_type');
SET @sql := IF(@i2 = 0,
    'CREATE INDEX idx_vdd_source_type ON verifica_documents_data (source_type, shared_with_pool)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────
-- 3. Ricrea VIEW teacher_content per esporre source_type
-- ─────────────────────────────────────────────────────────────────
DROP VIEW IF EXISTS teacher_content;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW teacher_content AS
SELECT
    tc.id,
    tc.teacher_id,
    tc.content_type,
    tc.subject_id,
    tc.indirizzo_id,
    tc.classe_id,
    tc.topic,
    tc.title,
    tc.body_html,
    tc.body_pt_ct,
    tc.body_pt_iv,
    tc.body_pt_tag,
    tc.body_pt_kv,
    tc.body_html_ct,
    tc.body_html_iv,
    tc.body_html_tag,
    tc.body_html_kv,
    tc.metadata_ct,
    tc.metadata_iv,
    tc.metadata_tag,
    tc.metadata_kv,
    tc.metadata_json,
    tc.source_type,    -- Phase 25.P.1 NEW
    tc.map_blob_path,
    tc.map_mime,
    tc.map_size,
    tc.map_drive_id,
    tc.map_origin,
    tc.map_is_public,
    tc.map_version,
    tc.visibility,
    tc.shared_with_pool,
    tc.source_content_id,
    tc.created_at,
    tc.updated_at,
    ci.code AS indirizzo,
    cc.code AS classe,
    cs.code AS subject_code
FROM teacher_content_data tc
LEFT JOIN curriculum_entries ci ON ci.id = tc.indirizzo_id AND ci.kind = 'indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tc.classe_id AND cc.kind = 'classi'
LEFT JOIN curriculum_entries cs ON cs.id = tc.subject_id AND cs.kind = 'materie';

-- ─────────────────────────────────────────────────────────────────
-- 4. Ricrea VIEW verifica_documents per esporre source_type
-- ─────────────────────────────────────────────────────────────────
DROP VIEW IF EXISTS verifica_documents;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW verifica_documents AS
SELECT
    vd.id,
    vd.teacher_id,
    vd.materia_id,
    vd.indirizzo_id,
    vd.classe_id,
    vd.title,
    vd.fm_db_section,
    vd.batch_id,
    vd.variant,
    vd.shared_with_pool,
    vd.source_type,     -- Phase 25.P.1 NEW
    vd.version_label,
    vd.exercise_ids,
    vd.selection_json,
    vd.tex_blob_path,
    vd.tex_blob_kv,
    vd.tex_size,
    vd.tex_files,
    vd.tex_sha256,
    vd.pdf_blob_path,
    vd.pdf_blob_kv,
    vd.pdf_size,
    vd.pdf_filename,
    vd.pdf_uploaded_at,
    vd.drive_file_id,
    vd.drive_synced_at,
    vd.created_at,
    vd.updated_at,
    ci.code AS indirizzo,
    cc.code AS classe,
    cm.code AS materia
FROM verifica_documents_data vd
LEFT JOIN curriculum_entries ci ON ci.id = vd.indirizzo_id AND ci.kind = 'indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = vd.classe_id AND cc.kind = 'classi'
LEFT JOIN curriculum_entries cm ON cm.id = vd.materia_id AND cm.kind = 'materie';
