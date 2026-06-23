-- 075_sidebar_sections_all_types.sql — ADR-027 unificazione "untyped panels"
--
-- Ogni pannello può creare qualunque "Modello documento" (il content_type
-- deriva dal doc_mode scelto nel modal). La distinzione per content_type a
-- livello sezione NON ha più senso → ogni sezione ammette tutti i tipi, così
-- la validazione server (type ∈ allowed_content_types) passa sempre.
--
-- Idempotente.

UPDATE sidebar_sections
   SET allowed_content_types = '["mappa","esercizio","lab","verifica","bes","risdoc","didattica"]';
