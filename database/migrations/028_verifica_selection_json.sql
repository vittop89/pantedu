-- G20.0 — selection_json snapshot per regenerazione multi-file ZIP/VSC.
-- saveBatch persiste lo Selection completo cosi' batchZip/batchFiles
-- possono ricostruire il bundle (texCommon + griglie + main_*.tex +
-- problemi_*.tex) usando il nuovo TexBuilder.build() multi-file API.

ALTER TABLE verifica_documents
    ADD COLUMN selection_json LONGTEXT NULL AFTER exercise_ids;
