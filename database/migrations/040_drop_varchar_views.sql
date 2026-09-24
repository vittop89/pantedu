-- G22.S20 v2.C2 Fase D — DROP varchar + VIEW back-compat.
--
-- Architettura finale: source of truth = FK ids; varchar non più presente
-- nelle tabelle reali. Esposti come columns derivate via VIEW JOIN per
-- mantenere back-compat con il SELECT layer (30+ file invariati).
--
-- Sequenza:
--   A. DROP TRIGGER (14 trigger sync varchar↔FK, non più necessari)
--   B. DROP INDEX UNIQUE basati su varchar (verifica_documents materia,
--      classe_keys (indirizzo, classe, anno))
--   C. RENAME 8 tabelle → *_data
--   D. DROP COLUMN varchar (indirizzo, classe, materia, subject_code)
--   E. ADD UNIQUE su FK ids (materia_id, indirizzo_id, classe_id)
--   F. CREATE VIEW con stesso nome originale, JOIN curriculum_entries
--
-- Effetto sul codice:
--   - INSERT/UPDATE/DELETE: puntano a *_data (refactor in 9 file)
--   - SELECT: invariato (VIEW espone code come columns derivate)
--   - Trigger: rimossi (FK ids sono primary, varchar derivato sempre)

-- ─────── A. DROP TRIGGERS ───────
DROP TRIGGER IF EXISTS trg_verifdoc_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_verifdoc_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_teachcont_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_teachcont_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_exercises_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_exercises_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_printinfo_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_printinfo_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_risdoccomp_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_risdoccomp_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_teachcred_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_teachcred_sync_codes_bu;
DROP TRIGGER IF EXISTS trg_clskeys_sync_codes_bi;
DROP TRIGGER IF EXISTS trg_clskeys_sync_codes_bu;

-- ─────── B. DROP INDEX UNIQUE varchar-based ───────
ALTER TABLE verifica_documents DROP INDEX uq_verif_doc_title;
ALTER TABLE classe_keys DROP INDEX uniq_classe_anno;
ALTER TABLE classe_keys DROP INDEX idx_active;

-- ─────── C. RENAME tabelle → *_data ───────
RENAME TABLE verifica_documents          TO verifica_documents_data;
RENAME TABLE teacher_content             TO teacher_content_data;
RENAME TABLE exercises                   TO exercises_data;
RENAME TABLE print_info                  TO print_info_data;
RENAME TABLE risdoc_compilations         TO risdoc_compilations_data;
RENAME TABLE teacher_access_credentials  TO teacher_access_credentials_data;
RENAME TABLE classe_keys                 TO classe_keys_data;
RENAME TABLE published_content           TO published_content_data;

-- ─────── D. DROP COLUMN varchar ───────
ALTER TABLE verifica_documents_data
    DROP COLUMN indirizzo, DROP COLUMN classe, DROP COLUMN materia;

ALTER TABLE teacher_content_data
    DROP COLUMN indirizzo, DROP COLUMN classe, DROP COLUMN subject_code;

ALTER TABLE exercises_data
    DROP COLUMN indirizzo, DROP COLUMN classe, DROP COLUMN materia;

ALTER TABLE print_info_data
    DROP COLUMN indirizzo, DROP COLUMN classe, DROP COLUMN materia;

ALTER TABLE risdoc_compilations_data
    DROP COLUMN indirizzo, DROP COLUMN classe;

ALTER TABLE teacher_access_credentials_data
    DROP COLUMN indirizzo, DROP COLUMN classe;

ALTER TABLE classe_keys_data
    DROP COLUMN indirizzo, DROP COLUMN classe;

ALTER TABLE published_content_data
    DROP COLUMN subject_code;

-- ─────── E. ADD UNIQUE su FK ids ───────
ALTER TABLE verifica_documents_data
    ADD UNIQUE INDEX uq_verif_doc_title_fk (teacher_id, materia_id, title, variant, version_label);

ALTER TABLE classe_keys_data
    ADD UNIQUE INDEX uniq_classe_anno_fk (indirizzo_id, classe_id, anno_scolastico, key_version);
ALTER TABLE classe_keys_data
    ADD INDEX idx_active_fk (indirizzo_id, classe_id, archived_at);

-- ─────── F. CREATE VIEW back-compat ───────
CREATE OR REPLACE VIEW verifica_documents AS
SELECT vd.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cm.code AS materia
FROM verifica_documents_data vd
LEFT JOIN curriculum_entries ci ON ci.id = vd.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = vd.classe_id    AND cc.kind='classi'
LEFT JOIN curriculum_entries cm ON cm.id = vd.materia_id   AND cm.kind='materie';

CREATE OR REPLACE VIEW teacher_content AS
SELECT tc.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cs.code AS subject_code
FROM teacher_content_data tc
LEFT JOIN curriculum_entries ci ON ci.id = tc.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tc.classe_id    AND cc.kind='classi'
LEFT JOIN curriculum_entries cs ON cs.id = tc.subject_id   AND cs.kind='materie';

CREATE OR REPLACE VIEW exercises AS
SELECT ex.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cm.code AS materia
FROM exercises_data ex
LEFT JOIN curriculum_entries ci ON ci.id = ex.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = ex.classe_id    AND cc.kind='classi'
LEFT JOIN curriculum_entries cm ON cm.id = ex.materia_id   AND cm.kind='materie';

CREATE OR REPLACE VIEW print_info AS
SELECT pi.*,
       ci.code AS indirizzo,
       cc.code AS classe,
       cm.code AS materia
FROM print_info_data pi
LEFT JOIN curriculum_entries ci ON ci.id = pi.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = pi.classe_id    AND cc.kind='classi'
LEFT JOIN curriculum_entries cm ON cm.id = pi.materia_id   AND cm.kind='materie';

CREATE OR REPLACE VIEW risdoc_compilations AS
SELECT rc.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM risdoc_compilations_data rc
LEFT JOIN curriculum_entries ci ON ci.id = rc.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = rc.classe_id    AND cc.kind='classi';

CREATE OR REPLACE VIEW teacher_access_credentials AS
SELECT tac.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM teacher_access_credentials_data tac
LEFT JOIN curriculum_entries ci ON ci.id = tac.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tac.classe_id    AND cc.kind='classi';

CREATE OR REPLACE VIEW classe_keys AS
SELECT ck.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM classe_keys_data ck
LEFT JOIN curriculum_entries ci ON ci.id = ck.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = ck.classe_id    AND cc.kind='classi';

CREATE OR REPLACE VIEW published_content AS
SELECT pc.*,
       cs.code AS subject_code
FROM published_content_data pc
LEFT JOIN curriculum_entries cs ON cs.id = pc.subject_id AND cs.kind='materie';
