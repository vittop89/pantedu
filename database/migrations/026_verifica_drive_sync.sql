-- Phase G19.47 — Drive sync per verifica_documents.
--
-- Aggiunge:
--   drive_file_id    ID del file su Google Drive (analogo a teacher_content.map_drive_id)
--   drive_synced_at  Timestamp ultimo push successful (per delta sync)
--
-- Permette al `VerificaSyncService::syncAllForTeacher` di:
--   - Identificare verifiche mai syncate (drive_file_id IS NULL)
--   - Filtrare verifiche modificate (updated_at > drive_synced_at)
--   - Idempotency su sync ripetuti (skip se nulla cambia)
--
-- Compat: record esistenti hanno drive_file_id NULL → trattati come
-- "first sync" al prossimo run.

ALTER TABLE verifica_documents
    ADD COLUMN drive_file_id   VARCHAR(64) NULL AFTER pdf_uploaded_at,
    ADD COLUMN drive_synced_at DATETIME    NULL AFTER drive_file_id,
    ADD INDEX idx_vd_drive_sync (teacher_id, drive_synced_at);
