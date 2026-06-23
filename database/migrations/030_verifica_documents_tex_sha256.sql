-- G22.S2 — Cache PDF content-addressed via SHA256(tex).
--
-- Aggiunge `tex_sha256` (CHAR(64) hex) a verifica_documents + index
-- composito (teacher_id, tex_sha256) per lookup O(log n) della cache.
--
-- Scopo: prima di chiamare il VPS tex-compile-vps su POST
-- /api/verifica/{id}/compile, il controller verifica se esiste un altro
-- verifica_document dello STESSO docente con lo stesso tex_sha256 e
-- pdf_blob_path popolato. Se sì → riusa il PDF cached (decifra + ricifra
-- per la nuova row, attach), evitando il round-trip + pdflatex.
--
-- Hit rate atteso: 40-60% (utente risalva lo stesso contenuto, varianti
-- A_NOR vs B_NOR spesso identiche, ecc.). Speedup proporzionale alla CPU
-- VPS evitata.
--
-- Scope per-teacher OBBLIGATO: l'envelope encryption ADR-006 richiede la
-- TKEK del proprietario per decifrare. Cross-teacher cache violerebbe il
-- principio di separation-of-secrets (richiederebbe 2 TKEK in chiaro
-- contemporaneamente lato server).
--
-- Backfill: niente. La colonna nasce NULL per le row pre-S2; il flusso
-- saveTex/saveBatch popola tex_sha256 solo dalle nuove row. Le row legacy
-- non beneficiano della cache (cache miss → compila come prima).
--
-- Rollback safe:
--   ALTER TABLE verifica_documents DROP INDEX idx_verifica_docs_teacher_sha;
--   ALTER TABLE verifica_documents DROP COLUMN tex_sha256;

ALTER TABLE verifica_documents
    ADD COLUMN tex_sha256 CHAR(64) NULL AFTER tex_size;

-- Index composito: ottimizza WHERE teacher_id = ? AND tex_sha256 = ?.
-- Niente UNIQUE: due varianti DSA/NOR dello stesso batch possono avere
-- contenuto identico (es. NOR e SOL senza solutions abilitate) e creano
-- volutamente row distinte.
CREATE INDEX idx_verifica_docs_teacher_sha
    ON verifica_documents(teacher_id, tex_sha256);
