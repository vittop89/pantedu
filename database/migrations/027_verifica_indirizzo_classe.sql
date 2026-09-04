-- Phase G19.48 — Aggiunge indirizzo + classe a verifica_documents.
--
-- Motivazione: il sync Drive (VerificaSyncService) deve costruire il
-- path cartella mirror della struttura mappe:
--   `Pantedu/{istituto}/{indirizzo}/{classe}/{materia}/verifiche/{titolo}/{version_folder}`
-- Prima della G19.48 il path saltava `indirizzo` e `classe` (non noti
-- in DB), incollando `materia` direttamente sotto `istituto`. Da ora
-- tutte le verifiche salvate via saveBatch persistono lo snapshot della
-- selezione client (`selectedIIS` → indirizzo, `selectedCLS` → classe).
--
-- Compat: record esistenti hanno indirizzo/classe NULL → trattati come
-- `general` lato sync (fallback in VerificaSyncService.buildFolderPath).
-- Re-sync di verifiche legacy crea cartelle nuove sotto la struttura
-- corretta; vecchie cartelle "piatte" restano orfane e vengono
-- ripulite dal delete-orphans della prossima sync.
--
-- Rollback safe:
--   ALTER TABLE verifica_documents
--       DROP COLUMN indirizzo,
--       DROP COLUMN classe;

ALTER TABLE verifica_documents
    ADD COLUMN indirizzo VARCHAR(8) NULL AFTER materia,
    ADD COLUMN classe    VARCHAR(8) NULL AFTER indirizzo;
