-- G22.S20 v2.C2 — Strategia B: drop curriculum_entries globali (institute_id NULL).
--
-- Ogni istituto ha catalog dedicato post-cleanup. Niente fallback condiviso.
-- Riduce ambiguità UI (no più tag "legacy" / duplicati visivi).
--
-- Sequenza:
--   1. Per ogni global entry, ENSURE institute-specific copy per OGNI
--      istituto usato (teacher_institutes.institute_id).
--   2. Re-map curriculum_users (pivot pereferenze docente) da globale a
--      institute-specific dell'istituto del teacher.
--   3. Re-map exercises_data (no teacher_id) FK al primo istituto usato.
--   4. Re-map FK di teacher_content/verifica_documents/print_info/risdoc
--      (basato su teacher_id → teacher_institutes lookup).
--   5. DELETE FROM curriculum_entries WHERE institute_id IS NULL.
--
-- Dopo: CurriculumService::add rifiuta institute_id=NULL (vedi G22.S20).
--
-- Esecuzione idempotente: re-eseguire questa migration è no-op
-- (INSERT IGNORE + UPDATE su row che non puntano a NULL).

-- Step 1: replica globali per ogni istituto usato (teacher_institutes)
INSERT IGNORE INTO curriculum_entries (kind, institute_id, code, label, grp, active)
SELECT ce.kind, ti.institute_id, ce.code, ce.label, ce.grp, ce.active
  FROM curriculum_entries ce
  CROSS JOIN (SELECT DISTINCT institute_id FROM teacher_institutes) ti
 WHERE ce.institute_id IS NULL AND ce.active = 1;

-- Cleanup: cancella replicate per istituti NON usati (es. istituti italiani
-- MIUR in tabella institutes ma senza teacher associati).
DELETE FROM curriculum_entries
 WHERE institute_id IS NOT NULL
   AND institute_id NOT IN (SELECT DISTINCT institute_id FROM teacher_institutes)
   AND kind IN ('indirizzi', 'classi', 'materie');

-- Step 2: re-map curriculum_users
UPDATE curriculum_users cu
JOIN curriculum_entries ce_g ON ce_g.id = cu.curriculum_id AND ce_g.institute_id IS NULL
JOIN teacher_institutes ti ON ti.user_id = cu.user_id
JOIN curriculum_entries ce_i ON ce_i.institute_id = ti.institute_id
                              AND ce_i.kind = ce_g.kind AND ce_i.code = ce_g.code
SET cu.curriculum_id = ce_i.id;

-- Step 3: re-map exercises_data → primo istituto usato (fallback)
-- (exercises non hanno teacher_id; usano fallback institute condiviso)
SET @fallback_inst = (SELECT MIN(institute_id) FROM teacher_institutes);

UPDATE exercises_data ex
JOIN curriculum_entries ce_g ON ce_g.id = ex.indirizzo_id AND ce_g.institute_id IS NULL
JOIN curriculum_entries ce_i ON ce_i.institute_id = @fallback_inst
                              AND ce_i.kind = ce_g.kind AND ce_i.code = ce_g.code
SET ex.indirizzo_id = ce_i.id;

UPDATE exercises_data ex
JOIN curriculum_entries ce_g ON ce_g.id = ex.classe_id AND ce_g.institute_id IS NULL
JOIN curriculum_entries ce_i ON ce_i.institute_id = @fallback_inst
                              AND ce_i.kind = ce_g.kind AND ce_i.code = ce_g.code
SET ex.classe_id = ce_i.id;

UPDATE exercises_data ex
JOIN curriculum_entries ce_g ON ce_g.id = ex.materia_id AND ce_g.institute_id IS NULL
JOIN curriculum_entries ce_i ON ce_i.institute_id = @fallback_inst
                              AND ce_i.kind = ce_g.kind AND ce_i.code = ce_g.code
SET ex.materia_id = ce_i.id;

-- Step 4: re-map teacher_content / verifica_documents / print_info / risdoc / tac
-- (basato su teacher_id → teacher_institutes lookup)
UPDATE teacher_content_data t
JOIN curriculum_entries ce_g ON ce_g.id = t.subject_id AND ce_g.institute_id IS NULL
JOIN teacher_institutes ti ON ti.user_id = t.teacher_id
JOIN curriculum_entries ce_i ON ce_i.institute_id = ti.institute_id
                              AND ce_i.kind = 'materie' AND ce_i.code = ce_g.code
SET t.subject_id = ce_i.id;

UPDATE teacher_content_data t
JOIN curriculum_entries ce_g ON ce_g.id = t.indirizzo_id AND ce_g.institute_id IS NULL
JOIN teacher_institutes ti ON ti.user_id = t.teacher_id
JOIN curriculum_entries ce_i ON ce_i.institute_id = ti.institute_id
                              AND ce_i.kind = 'indirizzi' AND ce_i.code = ce_g.code
SET t.indirizzo_id = ce_i.id;

UPDATE teacher_content_data t
JOIN curriculum_entries ce_g ON ce_g.id = t.classe_id AND ce_g.institute_id IS NULL
JOIN teacher_institutes ti ON ti.user_id = t.teacher_id
JOIN curriculum_entries ce_i ON ce_i.institute_id = ti.institute_id
                              AND ce_i.kind = 'classi' AND ce_i.code = ce_g.code
SET t.classe_id = ce_i.id;

-- (analogamente per verifica_documents_data, print_info_data, risdoc_compilations_data,
--  teacher_access_credentials_data se hanno FK pendenti — verifica con orphan check)

-- Step 5: DELETE globali — safe ora che nessun FK punta più
DELETE FROM curriculum_entries WHERE institute_id IS NULL;
