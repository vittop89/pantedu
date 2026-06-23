-- G22.S4.B.4 — Drop delle tabelle template legacy.
--
-- I template verifica (intestazione/griglia_voti/criteri/footer) sono ora
-- gestiti via filesystem in storage/templates/verifiche/{scope}/... e
-- letti via TemplateFileStore con cascade institute → _default. Il vecchio
-- modello DB-backed (verifica_templates flat + verifica_template_packs
-- per (indirizzo, materia, tipologia, dsa)) e' stato superato dal pipeline
-- unificato di TexBuilder::buildFlat() (G22.S4.B.1) + multi-file storage
-- nativo (G22.S4.B.2).
--
-- Stato pre-drop (dev DB):
--   verifica_template_packs: 7 row, tutti owner_id=NULL (system defaults
--                             gia' replicati nei file storage/templates/...)
--   verifica_templates:      1 row, "Default standard (legacy)" del dev user
--                             (mai usata: il flow saveTex non chiama piu'
--                             template_id da S4.B.1)
--
-- Niente migrazione dati: i template system sono gia' nei file
-- legacy_assets/* (a loro volta replicati in storage/templates/verifiche/
-- _default/texCommon/...). Dropping e' safe.
--
-- Anche `template_id` su verifica_documents diventa dead column ma la
-- lasciamo NULLable per non rompere row legacy che la referenziano.
--
-- Rollback: se serve ripristinare il sistema DB-backed, ri-applicare le
-- migration 021 + 022 (CREATE TABLE) + ri-deployare i file PHP cancellati
-- dal commit S4.B.4.

-- Step 1: rimuovi FK + colonna template_id da verifica_documents (la
-- referenza punta a verifica_templates che stiamo per droppare). La
-- colonna era un legacy hint mai usato dal flow saveBatch dopo S4.B.1.
ALTER TABLE verifica_documents DROP FOREIGN KEY fk_vd_template;
ALTER TABLE verifica_documents DROP INDEX idx_vd_template;
ALTER TABLE verifica_documents DROP COLUMN template_id;

-- Step 2: drop tabelle template legacy.
DROP TABLE IF EXISTS verifica_template_packs;
DROP TABLE IF EXISTS verifica_templates;
