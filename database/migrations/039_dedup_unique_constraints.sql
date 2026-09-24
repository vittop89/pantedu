-- G22.S20 v2.C2 — Fase C-3: Dedup + UNIQUE constraint anti-race.
--
-- Prerequisito: dedup script applicato (vedi tools/dev/dedup_content.php).
-- Le 69+8 row duplicate erano test artifacts; rimosse mantenendo MAX(id)
-- per ogni gruppo. Blob orphan generati dalla rimozione si puliscono via
-- TeacherSyncCleanupController::cleanupOrphans (UI dashboard).
--
-- UNIQUE constraints:
--   - teacher_content (teacher_id, content_type, title): garantisce
--     anti-race per i tipi mappa/esercizio/verifica/documento.
--   - verifica_documents (teacher_id, materia, title, variant, version_label):
--     varianti A/B × SOL/NOR/DSA/DIS sono righe distinte; stessa combinazione
--     non si duplica.
--
-- Effetto: 2 INSERT concorrenti con stessa chiave → uno fallisce con
-- HTTP 23000 duplicate key. Application layer gestisce con detectConflict
-- + strategy skip/rename (già implementato in ImportBundleController).

ALTER TABLE teacher_content
    ADD UNIQUE INDEX IF NOT EXISTS uq_teach_content_title (teacher_id, content_type, title);

ALTER TABLE verifica_documents
    ADD UNIQUE INDEX IF NOT EXISTS uq_verif_doc_title (teacher_id, materia, title, variant, version_label);
