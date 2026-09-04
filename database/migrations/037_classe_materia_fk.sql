-- G22.S20 v2.C2 — Fase A: estensione FK refactor a classe + materia + subject_code.
--
-- Pattern identico a migration 036 per `indirizzo`. Aggiunge classe_id /
-- materia_id / subject_id INT FK su curriculum_entries(id) con cleanup
-- dati legacy:
--   - verifica_documents/risdoc_compilations/teacher_access_credentials:
--     classe '1s'/'2s' → '1'/'2' (suffisso 's' = legacy, no semantica)
--   - exercises: classe 'ART3'/'SCI1' → '3'/'1' (prefisso indirizzo
--     duplicato, già storato in colonna indirizzo separata)
--
-- Prerequisiti:
--   - curriculum_entries.institute_id già NULLABLE (fatto in 036)
--   - row globali per classi (1-5 + 1b-5b) e materie (MAT/FIS/GEO/CHI/...)

-- ─────── 1. INSERT global rows per kind=classi e kind=materie ───────
INSERT IGNORE INTO curriculum_entries (kind, institute_id, code, label, active)
VALUES
  -- classi
  ('classi', NULL, '1',  'Classe I',   1),
  ('classi', NULL, '2',  'Classe II',  1),
  ('classi', NULL, '3',  'Classe III', 1),
  ('classi', NULL, '4',  'Classe IV',  1),
  ('classi', NULL, '5',  'Classe V',   1),
  ('classi', NULL, '1B', 'Classe I (br)',   1),
  ('classi', NULL, '2B', 'Classe II (br)',  1),
  ('classi', NULL, '3B', 'Classe III (br)', 1),
  ('classi', NULL, '4B', 'Classe IV (br)',  1),
  ('classi', NULL, '5B', 'Classe V (br)',   1),
  -- materie comuni (lista non esaustiva: solo quelle presenti in dati attuali)
  ('materie', NULL, 'MAT', 'Matematica', 1),
  ('materie', NULL, 'FIS', 'Fisica',     1),
  ('materie', NULL, 'GEO', 'Geometria',  1),
  ('materie', NULL, 'CHI', 'Chimica',    1),
  ('materie', NULL, 'ITA', 'Italiano',   1),
  ('materie', NULL, 'STO', 'Storia',     1),
  ('materie', NULL, 'SCN', 'Scienze',    1),
  ('materie', NULL, 'FIL', 'Filosofia',  1),
  ('materie', NULL, 'LAT', 'Latino',     1),
  ('materie', NULL, 'ING', 'Inglese',    1),
  ('materie', NULL, 'EDM', 'Educazione Motoria', 1);

-- ─────── 2. DATA CLEANUP: '1s'/'2s' → '1'/'2' (suffisso s legacy) ───────
UPDATE verifica_documents
   SET classe = CASE WHEN classe = '1s' THEN '1' WHEN classe = '2s' THEN '2' ELSE classe END
 WHERE classe IN ('1s', '2s');

UPDATE risdoc_compilations
   SET classe = CASE WHEN classe = '1s' THEN '1' WHEN classe = '2s' THEN '2' ELSE classe END
 WHERE classe IN ('1s', '2s');

UPDATE teacher_access_credentials
   SET classe = CASE WHEN classe = '1s' THEN '1' WHEN classe = '2s' THEN '2' ELSE classe END
 WHERE classe IN ('1s', '2s');

-- ─────── 3. DATA CLEANUP exercises: ART3/SCI1 → 3/1 (rimuovi prefisso indirizzo)
UPDATE exercises
   SET classe = REGEXP_REPLACE(classe, '^[A-Z]{3}', '')
 WHERE classe REGEXP '^[A-Z]{3}';

-- ─────── 4. Add columns classe_id, materia_id, subject_id (additive) ───────
ALTER TABLE verifica_documents          ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE verifica_documents          ADD COLUMN IF NOT EXISTS materia_id INT UNSIGNED NULL AFTER materia;
ALTER TABLE teacher_content             ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE teacher_content             ADD COLUMN IF NOT EXISTS subject_id INT UNSIGNED NULL AFTER subject_code;
ALTER TABLE exercises                   ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE exercises                   ADD COLUMN IF NOT EXISTS materia_id INT UNSIGNED NULL AFTER materia;
ALTER TABLE print_info                  ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE print_info                  ADD COLUMN IF NOT EXISTS materia_id INT UNSIGNED NULL AFTER materia;
ALTER TABLE risdoc_compilations         ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE teacher_access_credentials  ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE classe_keys                 ADD COLUMN IF NOT EXISTS classe_id  INT UNSIGNED NULL AFTER classe;
ALTER TABLE published_content           ADD COLUMN IF NOT EXISTS subject_id INT UNSIGNED NULL AFTER subject_code;

-- ─────── 5. Backfill classe_id ───────
-- verifica_documents/teacher_content/risdoc_compilations: tramite teacher_institutes
UPDATE verifica_documents vd
JOIN teacher_institutes ti ON ti.user_id = vd.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'classi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = vd.classe
SET vd.classe_id = ce.id
WHERE vd.classe IS NOT NULL AND vd.classe_id IS NULL;

UPDATE verifica_documents vd
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = vd.classe
SET vd.classe_id = ce.id
WHERE vd.classe IS NOT NULL AND vd.classe_id IS NULL;

UPDATE teacher_content tc
JOIN teacher_institutes ti ON ti.user_id = tc.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'classi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = tc.classe
SET tc.classe_id = ce.id
WHERE tc.classe IS NOT NULL AND tc.classe_id IS NULL;

UPDATE teacher_content tc
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = tc.classe
SET tc.classe_id = ce.id
WHERE tc.classe IS NOT NULL AND tc.classe_id IS NULL;

UPDATE risdoc_compilations rc
JOIN teacher_institutes ti ON ti.user_id = rc.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'classi' AND ce.institute_id = ti.institute_id AND ce.code = rc.classe
SET rc.classe_id = ce.id
WHERE rc.classe IS NOT NULL AND rc.classe_id IS NULL;

UPDATE risdoc_compilations rc
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = rc.classe
SET rc.classe_id = ce.id
WHERE rc.classe IS NOT NULL AND rc.classe_id IS NULL;

-- exercises: no teacher_id → solo globals
UPDATE exercises ex
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = ex.classe
SET ex.classe_id = ce.id
WHERE ex.classe IS NOT NULL AND ex.classe_id IS NULL;

-- print_info: user_id
UPDATE print_info pi
JOIN teacher_institutes ti ON ti.user_id = pi.user_id
JOIN curriculum_entries ce
    ON ce.kind = 'classi' AND ce.institute_id = ti.institute_id AND ce.code = pi.classe
SET pi.classe_id = ce.id
WHERE pi.classe IS NOT NULL AND pi.classe_id IS NULL;

UPDATE print_info pi
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = pi.classe
SET pi.classe_id = ce.id
WHERE pi.classe IS NOT NULL AND pi.classe_id IS NULL;

-- teacher_access_credentials: institute_id diretto
UPDATE teacher_access_credentials tac
JOIN curriculum_entries ce
    ON ce.kind = 'classi' AND ce.institute_id = tac.institute_id AND ce.code = tac.classe
SET tac.classe_id = ce.id
WHERE tac.classe IS NOT NULL AND tac.classe_id IS NULL;

UPDATE teacher_access_credentials tac
JOIN curriculum_entries ce ON ce.kind = 'classi' AND ce.institute_id IS NULL AND ce.code = tac.classe
SET tac.classe_id = ce.id
WHERE tac.classe IS NOT NULL AND tac.classe_id IS NULL;

-- ─────── 6. Backfill materia_id (verifica_documents/exercises/print_info) ───────
UPDATE verifica_documents vd
JOIN teacher_institutes ti ON ti.user_id = vd.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'materie' AND ce.institute_id = ti.institute_id AND ce.code = vd.materia
SET vd.materia_id = ce.id
WHERE vd.materia IS NOT NULL AND vd.materia_id IS NULL;

UPDATE verifica_documents vd
JOIN curriculum_entries ce ON ce.kind = 'materie' AND ce.institute_id IS NULL AND ce.code = vd.materia
SET vd.materia_id = ce.id
WHERE vd.materia IS NOT NULL AND vd.materia_id IS NULL;

UPDATE exercises ex
JOIN curriculum_entries ce ON ce.kind = 'materie' AND ce.institute_id IS NULL AND ce.code = ex.materia
SET ex.materia_id = ce.id
WHERE ex.materia IS NOT NULL AND ex.materia_id IS NULL;

UPDATE print_info pi
JOIN teacher_institutes ti ON ti.user_id = pi.user_id
JOIN curriculum_entries ce
    ON ce.kind = 'materie' AND ce.institute_id = ti.institute_id AND ce.code = pi.materia
SET pi.materia_id = ce.id
WHERE pi.materia IS NOT NULL AND pi.materia_id IS NULL;

UPDATE print_info pi
JOIN curriculum_entries ce ON ce.kind = 'materie' AND ce.institute_id IS NULL AND ce.code = pi.materia
SET pi.materia_id = ce.id
WHERE pi.materia IS NOT NULL AND pi.materia_id IS NULL;

-- ─────── 7. Backfill subject_id (teacher_content/published_content) ───────
-- subject_code è semanticamente equivalente a materia → curriculum_entries kind=materie
UPDATE teacher_content tc
JOIN teacher_institutes ti ON ti.user_id = tc.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'materie' AND ce.institute_id = ti.institute_id AND ce.code = tc.subject_code
SET tc.subject_id = ce.id
WHERE tc.subject_code IS NOT NULL AND tc.subject_id IS NULL;

UPDATE teacher_content tc
JOIN curriculum_entries ce ON ce.kind = 'materie' AND ce.institute_id IS NULL AND ce.code = tc.subject_code
SET tc.subject_id = ce.id
WHERE tc.subject_code IS NOT NULL AND tc.subject_id IS NULL;

-- ─────── 8. FK constraints ───────
ALTER TABLE verifica_documents          ADD CONSTRAINT fk_verifdoc_classe   FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE verifica_documents          ADD CONSTRAINT fk_verifdoc_materia  FOREIGN KEY (materia_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE teacher_content             ADD CONSTRAINT fk_teachcont_classe  FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE teacher_content             ADD CONSTRAINT fk_teachcont_subject FOREIGN KEY (subject_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE exercises                   ADD CONSTRAINT fk_exercises_classe  FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE exercises                   ADD CONSTRAINT fk_exercises_materia FOREIGN KEY (materia_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE print_info                  ADD CONSTRAINT fk_printinfo_classe  FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE print_info                  ADD CONSTRAINT fk_printinfo_materia FOREIGN KEY (materia_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE risdoc_compilations         ADD CONSTRAINT fk_risdoccomp_classe FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE teacher_access_credentials  ADD CONSTRAINT fk_teachcred_classe  FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE classe_keys                 ADD CONSTRAINT fk_clskeys_classe    FOREIGN KEY (classe_id)  REFERENCES curriculum_entries(id) ON DELETE SET NULL;
ALTER TABLE published_content           ADD CONSTRAINT fk_published_subject FOREIGN KEY (subject_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;
