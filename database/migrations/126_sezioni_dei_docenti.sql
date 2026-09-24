-- SICUREZZA: aggiunge due colonne con un valore di partenza, che il codice di prima non legge, e sospende (active = 0) le spunte dei docenti su sezioni non coperte da un loro incarico. Misurato il 14/9/2026 in produzione: tutte le 12 sezioni spuntate e tutte le 251 pubblicazioni su sezione sono coperte da un incarico, quindi nessuna spunta cambia. Il codice di prima rispetta la sospensione, perché legge le spunte attive.
-- ROLLBACK: UPDATE curriculum_teacher SET active = 1, sospesa_dalla_scuola = 0 WHERE sospesa_dalla_scuola = 1; ALTER TABLE curriculum_teacher DROP COLUMN sospesa_dalla_scuola; ALTER TABLE institutes DROP COLUMN sezioni_docenti.
--
-- Le sezioni delle classi per i docenti, istituto per istituto (ADR-041,
-- 14/9/2026).
--
-- L'elenco delle sezioni di una scuola è pubblico (il dataset MIUR delle
-- adozioni). Il legame «il docente X insegna in 2A» no: messo insieme per
-- molti docenti ricostruisce l'organigramma della scuola. La modalità dice
-- quali docenti possono legarsi a una sezione:
--   - 'tutti': ogni docente dell'istituto;
--   - 'solo_incaricati': solo il docente che l'amministratore ha incaricato di
--     quella sezione (`teacher_sections`); gli altri usano gli anni («2» vale
--     per tutte le seconde);
--   - 'nessuno': solo gli anni.
-- Scelta dell'utente: «solo incaricati» per tutti gli istituti, da subito.
--
-- Una spunta che la modalità non ammette non si cancella: si sospende
-- (active = 0, sospesa_dalla_scuola = 1) e si riprende se la modalità torna a
-- permetterla. Il docente non la vede e non la può riaccendere; quelle che il
-- docente ha spento da sé (sospesa_dalla_scuola = 0) restano sue.

SET NAMES utf8mb4;

ALTER TABLE institutes
    ADD COLUMN IF NOT EXISTS sezioni_docenti ENUM('tutti', 'solo_incaricati', 'nessuno')
        NOT NULL DEFAULT 'solo_incaricati'
        COMMENT 'ADR-041 — chi dei docenti può legarsi a una sezione';

ALTER TABLE curriculum_teacher
    ADD COLUMN IF NOT EXISTS sospesa_dalla_scuola TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'ADR-041 — spenta dalla modalità delle sezioni, non dal docente';

UPDATE curriculum_teacher ct
  JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
  JOIN institutes i ON i.id = ce.institute_id
   SET ct.active = 0, ct.sospesa_dalla_scuola = 1
 WHERE ce.kind = 'classi'
   AND ce.code REGEXP '^[1-9][A-Za-z0-9]+$'
   AND ct.active = 1
   AND NOT (
        i.sezioni_docenti = 'tutti'
        OR (i.sezioni_docenti = 'solo_incaricati'
            AND EXISTS (SELECT 1 FROM teacher_sections ts
                         WHERE ts.user_id = ct.user_id
                           AND ts.institute_id = ce.institute_id
                           AND UPPER(ts.classe) = UPPER(ce.code)
                           AND (ce.indirizzo IS NULL OR UPPER(ts.indirizzo) = UPPER(ce.indirizzo))))
   );
