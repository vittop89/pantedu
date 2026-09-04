-- 073_teacher_content_view_section_id.sql — ADR-027 Passo 5
--
-- Ricrea la view `teacher_content` per esporre `section_id` (aggiunta a
-- teacher_content_data dalla 071, ma la view a colonne esplicite non la
-- vedeva). Necessario al loader unico per filtrare/raggruppare per sezione.
--
-- Sicurezza:
--   - CREATE OR REPLACE VIEW è ATOMICO (nessuna finestra di drop → nessun 500
--     site-wide sui read durante lo switch).
--   - Usa `tc.*` invece dell'elenco esplicito: include section_id e qualunque
--     colonna futura di teacher_content_data, mantenendo le 3 derivate
--     (indirizzo/classe/subject_code) via gli stessi JOIN dell'originale.
--   - I consumer leggono per NOME colonna (PDO FETCH_ASSOC) → l'ordine e la
--     colonna extra non rompono nulla.
--
-- ROLLBACK: la definizione precedente è salvata in
-- tools/dev/_view_backup/teacher_content_view_local.txt. Per ripristinare:
-- CREATE OR REPLACE VIEW teacher_content AS <vecchia SELECT esplicita>.

CREATE OR REPLACE
ALGORITHM = UNDEFINED SQL SECURITY DEFINER
VIEW teacher_content AS
SELECT tc.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cs.code AS subject_code
  FROM teacher_content_data tc
  LEFT JOIN curriculum_entries ci ON ci.id = tc.indirizzo_id AND ci.kind = 'indirizzi'
  LEFT JOIN curriculum_entries cc ON cc.id = tc.classe_id    AND cc.kind = 'classi'
  LEFT JOIN curriculum_entries cs ON cs.id = tc.subject_id   AND cs.kind = 'materie';
