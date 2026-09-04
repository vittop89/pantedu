-- 100_classi_indirizzo.sql
-- Le righe `classi` di curriculum_entries acquistano un indirizzo.
--
-- Finora una classe era solo un codice dell'istituto: "1A" e "1BLSS" stavano
-- entrambe sotto il 106 senza sapere a quale corso appartenessero. Con le
-- sezioni in uso questo diventa un problema concreto: in registrazione uno
-- studente che sceglie Scientifico si vedrebbe offrire anche 1BLSS, che e'
-- dello sportivo, e finirebbe ancorato a una sezione del corso sbagliato.
--
-- L'accoppiata sezione↔indirizzo esiste gia' nei dati MIUR: il dataset delle
-- adozioni (DS0712ALTPIEMONTE e omologhi) porta COMBINAZIONE e SEZIONEANNO
-- sulla stessa riga. Per il Esempio:
--     LICEO SCIENTIFICO                → 1A 2A 2B 3A 3B 3C 4A 5A 5B
--     SCIENTIFICO AD INDIRIZZO SPORT.  → 1ALSS 1BLSS 2ALSS 2BLSS
--     LICEO ARTISTICO BIENNIO COMUNE   → 1AA 2AA 2BA
--
-- NULL = vale per TUTTI gli indirizzi dell'istituto. E' il caso degli anni
-- senza sezione ("1", "2", "3"), che restano trasversali — coerente con la
-- regola di copertura in TeacherSectionService: "1" copre tutte le sue
-- sezioni, e non ha senso legarlo a un corso solo.
--
-- La colonna e' significativa SOLO per kind='classi'. Per 'indirizzi' e
-- 'materie' resta NULL: le materie sono per-docente e gli indirizzi sono
-- l'indirizzo essi stessi.

SET NAMES utf8mb4;

SET @c_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND COLUMN_NAME = 'indirizzo');
SET @sql := IF(@c_exists = 0,
    'ALTER TABLE curriculum_entries
       ADD COLUMN indirizzo VARCHAR(16) DEFAULT NULL
       COMMENT ''Solo per kind=classi: corso di appartenenza. NULL = tutti''
       AFTER grp',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Lookup della registrazione: "classi attive di questo istituto per questo
-- indirizzo", che e' la query che il form fara' a ogni scelta di indirizzo.
SET @i_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND INDEX_NAME = 'idx_ce_classi_indirizzo');
SET @sql := IF(@i_exists = 0,
    'CREATE INDEX idx_ce_classi_indirizzo
        ON curriculum_entries (kind, institute_id, indirizzo, active)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
