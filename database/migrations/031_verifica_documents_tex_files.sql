-- G22.S4.B.2 — Multi-file storage nativo per verifica_documents.
--
-- Una verifica ora si salva come BUNDLE di file cifrati separati invece
-- che blob singolo monolitico:
--   verifica.sty, intestazione.tex, ulteriori_misure.tex, BES_DSA/...,
--   griglie/{ind}_{mat}.tex, versioni/main_*.tex, versioni/esercizi_*.tex
--
-- Vantaggi:
--   - layout di storage = layout ZIP/VSC (no piu' "schiacciamento" inline)
--   - dedup possibile: texCommon/* potenzialmente condiviso fra varianti
--     dello stesso batch (futuro, S4.B.4)
--   - VPS compile via /compile-bundle (S4.B.3): tar.gz materialized da
--     manifest, niente flattening lato app
--
-- Schema:
--   tex_files JSON: array di {path, blob_path, blob_kv, sha256}
--     - path: posizione canonica nel bundle (es. "versioni/main_NOR.tex")
--     - blob_path: relPath del file cifrato in storage/verifiche_enc/
--     - blob_kv: kv envelope per decifratura
--     - sha256: hash del plaintext (audit + dedup futuro)
--
-- Back-compat: tex_blob_path/kv/size restano NULLable per row legacy
-- pre-S4.B.2. La logica di lettura controlla tex_files prima e cade su
-- tex_blob_path se assente.
--
-- Rollback safe:
--   ALTER TABLE verifica_documents DROP COLUMN tex_files;
--   ALTER TABLE verifica_documents
--     MODIFY COLUMN tex_blob_path varchar(255) NOT NULL,
--     MODIFY COLUMN tex_blob_kv smallint UNSIGNED NOT NULL,
--     MODIFY COLUMN tex_size int UNSIGNED NOT NULL;

ALTER TABLE verifica_documents
    ADD COLUMN tex_files LONGTEXT NULL AFTER tex_size;

ALTER TABLE verifica_documents
    MODIFY COLUMN tex_blob_path VARCHAR(255) NULL,
    MODIFY COLUMN tex_blob_kv   SMALLINT UNSIGNED NULL,
    MODIFY COLUMN tex_size      INT UNSIGNED NULL;
