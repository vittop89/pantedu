-- 106 — print_info: campi estesi in una colonna JSON.
--
-- La tabella tiene solo i campi indicizzati (indirizzo/classe/materia/n_print).
-- I campi estesi del modulo «Info verifica» (sezione, istituto, anno, verTime,
-- nPrintDSA/nPrintDIS, addressSchool, versione, nome/cognome, flag compensa/dsa/
-- griglie/misure, verTitlePrefix/verTitle) vivevano nel JSON teacher-scoped
-- scritto in doppia scrittura; tolta la doppia scrittura (2026-09-05) restavano
-- solo in memoria del client: «Carica» restituiva la sola chiave.
-- Ora stanno qui, accanto alla chiave; la vista si ricrea perché `pi.*` viene
-- espanso alla creazione e non vedrebbe la colonna nuova.

ALTER TABLE print_info_data
    ADD COLUMN extra_json TEXT NULL AFTER n_print;

CREATE OR REPLACE VIEW print_info AS
SELECT pi.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cm.code AS materia
FROM print_info_data pi
LEFT JOIN curriculum_entries ci ON ci.id = pi.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = pi.classe_id    AND cc.kind='classi'
LEFT JOIN curriculum_entries cm ON cm.id = pi.materia_id   AND cm.kind='materie';
