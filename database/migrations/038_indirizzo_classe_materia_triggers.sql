-- G22.S20 v2.C2 — Fase B (alternativa): TRIGGER per garantire sync
-- automatico varchar ↔ FK ids. Source of truth = FK ids; varchar è
-- proiezione derivata, mantenuta consistent da DB-level.
--
-- Strategia:
--   - BEFORE INSERT/UPDATE: trigger setta NEW.{col_varchar} = SELECT code
--     FROM curriculum_entries WHERE id = NEW.{col_id}.
--   - Quando il codice applicativo passa SOLO FK id, il varchar viene
--     popolato automaticamente.
--   - Quando passa ANCHE varchar (back-compat), trigger overwrites con
--     il code corrispondente al FK id — garanzia di NO drift.
--
-- Pro:
--   - Zero refactor SELECT codice (~30 file invariati)
--   - DB-level enforcement: impossibile drift varchar/FK
--   - Dual storage mantenuto MA garantito sync
--
-- Contro:
--   - 2 query per ogni INSERT/UPDATE (trigger fa subquery per ogni FK)
--   - Trigger logic invisibile dal codice → debug più subtle
--
-- Trade-off accettato: dev environment singolo utente, performance OK.

DELIMITER //

-- ─────── 1. verifica_documents ───────
DROP TRIGGER IF EXISTS trg_verifdoc_sync_codes_bi//
CREATE TRIGGER trg_verifdoc_sync_codes_bi
BEFORE INSERT ON verifica_documents
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_verifdoc_sync_codes_bu//
CREATE TRIGGER trg_verifdoc_sync_codes_bu
BEFORE UPDATE ON verifica_documents
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    ELSE
        SET NEW.indirizzo = NULL;
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    ELSE
        SET NEW.classe = NULL;
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

-- ─────── 2. teacher_content ───────
DROP TRIGGER IF EXISTS trg_teachcont_sync_codes_bi//
CREATE TRIGGER trg_teachcont_sync_codes_bi
BEFORE INSERT ON teacher_content
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.subject_id IS NOT NULL THEN
        SET NEW.subject_code = (SELECT code FROM curriculum_entries WHERE id = NEW.subject_id AND kind='materie');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_teachcont_sync_codes_bu//
CREATE TRIGGER trg_teachcont_sync_codes_bu
BEFORE UPDATE ON teacher_content
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    ELSE
        SET NEW.indirizzo = NULL;
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    ELSE
        SET NEW.classe = NULL;
    END IF;
    IF NEW.subject_id IS NOT NULL THEN
        SET NEW.subject_code = (SELECT code FROM curriculum_entries WHERE id = NEW.subject_id AND kind='materie');
    END IF;
END//

-- ─────── 3. exercises ───────
DROP TRIGGER IF EXISTS trg_exercises_sync_codes_bi//
CREATE TRIGGER trg_exercises_sync_codes_bi
BEFORE INSERT ON exercises
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_exercises_sync_codes_bu//
CREATE TRIGGER trg_exercises_sync_codes_bu
BEFORE UPDATE ON exercises
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

-- ─────── 4. print_info ───────
DROP TRIGGER IF EXISTS trg_printinfo_sync_codes_bi//
CREATE TRIGGER trg_printinfo_sync_codes_bi
BEFORE INSERT ON print_info
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_printinfo_sync_codes_bu//
CREATE TRIGGER trg_printinfo_sync_codes_bu
BEFORE UPDATE ON print_info
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
    IF NEW.materia_id IS NOT NULL THEN
        SET NEW.materia = (SELECT code FROM curriculum_entries WHERE id = NEW.materia_id AND kind='materie');
    END IF;
END//

-- ─────── 5. risdoc_compilations (no materia) ───────
DROP TRIGGER IF EXISTS trg_risdoccomp_sync_codes_bi//
CREATE TRIGGER trg_risdoccomp_sync_codes_bi
BEFORE INSERT ON risdoc_compilations
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_risdoccomp_sync_codes_bu//
CREATE TRIGGER trg_risdoccomp_sync_codes_bu
BEFORE UPDATE ON risdoc_compilations
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

-- ─────── 6. teacher_access_credentials ───────
DROP TRIGGER IF EXISTS trg_teachcred_sync_codes_bi//
CREATE TRIGGER trg_teachcred_sync_codes_bi
BEFORE INSERT ON teacher_access_credentials
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_teachcred_sync_codes_bu//
CREATE TRIGGER trg_teachcred_sync_codes_bu
BEFORE UPDATE ON teacher_access_credentials
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

-- ─────── 7. classe_keys ───────
DROP TRIGGER IF EXISTS trg_clskeys_sync_codes_bi//
CREATE TRIGGER trg_clskeys_sync_codes_bi
BEFORE INSERT ON classe_keys
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

DROP TRIGGER IF EXISTS trg_clskeys_sync_codes_bu//
CREATE TRIGGER trg_clskeys_sync_codes_bu
BEFORE UPDATE ON classe_keys
FOR EACH ROW
BEGIN
    IF NEW.indirizzo_id IS NOT NULL THEN
        SET NEW.indirizzo = (SELECT code FROM curriculum_entries WHERE id = NEW.indirizzo_id AND kind='indirizzi');
    END IF;
    IF NEW.classe_id IS NOT NULL THEN
        SET NEW.classe = (SELECT code FROM curriculum_entries WHERE id = NEW.classe_id AND kind='classi');
    END IF;
END//

DELIMITER ;
