-- Phase G19.44 — Verifica version_label per dedup conflict detection.
--
-- Aggiunge:
--   version_label  VARCHAR(64) NULL — etichetta utente da `#versione` input
--                  (es. "v1", "v2rc"). Multiple save dello stesso (title,
--                  variant) con DIVERSO version_label coesistono come
--                  versioni separate (visualizzate nel modal detail).
--                  Stesso version_label → conflict 409 (overwrite confirm).
--
-- Compat: record esistenti hanno version_label NULL → trattati come una
-- "version unica" senza label (visualizzati con created_at come fallback).

ALTER TABLE verifica_documents
    ADD COLUMN version_label VARCHAR(64) NULL AFTER variant,
    ADD INDEX idx_vd_title_version (teacher_id, materia, title(64), variant, version_label);
