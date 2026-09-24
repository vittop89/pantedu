-- 133 — ADR-043: anche gli anni seguono gli incarichi (15/9/2026).
--
-- Con «solo incaricati» un docente usa un anno di un corso («3» di
-- architettura) solo se l'amministratore gliene ha dato l'incarico, come per le
-- sezioni. Fino a qui gli anni erano sempre ammessi: il docente vedeva gli anni
-- di ogni corso anche dove non gli era stato dato niente (segnalato
-- dall'utente; scelta: «solo con incarico»). Con «tutti» e con «nessuno» gli
-- anni restano ammessi.
--
-- Le spunte sugli anni che la modalità non ammette si sospendono
-- (active = 0, sospesa_dalla_scuola = 1), non si cancellano: si riprendono da
-- sole quando l'amministratore dà l'incarico (SezioniDeiDocenti::riallinea). I
-- materiali restano dove sono.
--
-- Misurato in produzione il 15/9/2026 prima di scriverla: 15 spunte da
-- sospendere (docente.uno: ART 1–5, corso che aveva già spento;
-- docente.due: SCI 1–5 e ART 1–5), nessuna da riprendere; 232 pubblicazioni
-- restano su anni senza incarico e compaiono fra i «materiali su classi senza
-- incarico».
--
-- Non cancella e non toglie niente: nessuna dichiarazione obbligatoria. Per
-- tornare indietro:
--   UPDATE curriculum_teacher ct JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
--      SET ct.active = 1, ct.sospesa_dalla_scuola = 0
--    WHERE ct.sospesa_dalla_scuola = 1 AND ce.kind = 'classi' AND ce.code REGEXP '^[1-9]$';

SET NAMES utf8mb4;

UPDATE curriculum_teacher ct
  JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
  JOIN institutes i ON i.id = ce.institute_id
   SET ct.active = 0, ct.sospesa_dalla_scuola = 1
 WHERE ce.kind = 'classi'
   AND ce.code REGEXP '^[1-9]$'
   AND ct.active = 1
   AND i.sezioni_docenti = 'solo_incaricati'
   AND NOT EXISTS (SELECT 1 FROM teacher_sections ts
                    WHERE ts.user_id = ct.user_id
                      AND ts.institute_id = ce.institute_id
                      AND UPPER(ts.classe) = UPPER(ce.code)
                      AND (ce.indirizzo IS NULL OR UPPER(ts.indirizzo) = UPPER(ce.indirizzo)));
