-- G22.S20 v2.C2 — Refactor indirizzo da varchar a FK INT su curriculum_entries.id
--
-- Strategia: additive (non drop varchar). Aggiunge indirizzo_id INT FK su
-- curriculum_entries(id) per 7 tabelle. Backfill via JOIN normalize legacy
-- sc/ar/cl/li/ling/af → SCI/ART/CLA/LIN/LIN/AFM, match curriculum_entries
-- preferendo institute_id del teacher, fallback institute_id NULL (globali).
--
-- La colonna varchar `indirizzo` esistente resta in DUAL STORAGE (mantenuta
-- canonicalizzata da App\Support\IndirizzoCode::canonByCode). DROP varchar
-- pianificato in migration 037 DOPO refactor SELECT layer (~30 file).
--
-- IMPORTANTE prerequisiti:
--   1) curriculum_entries.institute_id deve essere NULLABLE per le row
--      "globali" (used da exercises che non ha teacher_id).
--      Eseguire prima: ALTER TABLE curriculum_entries MODIFY institute_id
--                       INT UNSIGNED NULL;
--   2) Inserire le 5 row globali (institute_id NULL) per SCI/CLA/LIN/ART/AFM.
--
-- Idempotency: ADD COLUMN IF NOT EXISTS / ADD CONSTRAINT.

-- ─────── 0. Prerequisiti: institute_id nullable + globals ───────
ALTER TABLE curriculum_entries MODIFY COLUMN institute_id INT UNSIGNED NULL;

-- ─────── 1. Global rows in curriculum_entries (institute_id NULL) ───────
-- exercises e altre tabelle senza teacher_id riferiscono questi globali.
INSERT IGNORE INTO curriculum_entries (kind, institute_id, code, label, grp, active)
VALUES
  ('indirizzi', NULL, 'SCI', 'Scientifico',                    NULL, 1),
  ('indirizzi', NULL, 'CLA', 'Classico',                       NULL, 1),
  ('indirizzi', NULL, 'LIN', 'Linguistico',                    NULL, 1),
  ('indirizzi', NULL, 'ART', 'Artistico',                      NULL, 1),
  ('indirizzi', NULL, 'AFM', 'Amministrazione, Finanza e Marketing', NULL, 0);

-- ─────── 2. Add indirizzo_id columns (additive, INT FK ready) ───────
ALTER TABLE verifica_documents
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE teacher_content
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE classe_keys
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE exercises
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE print_info
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE risdoc_compilations
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

ALTER TABLE teacher_access_credentials
    ADD COLUMN IF NOT EXISTS indirizzo_id INT UNSIGNED NULL AFTER indirizzo;

-- ─────── 3. Helper view: lookup id da varchar normalizzato + institute_id ───────
-- Eseguo backfill via UPDATE…JOIN (no stored proc, semplice da revertire).
--
-- Map legacy code → canonical (CASE inline):
--   sc/scientifico  → SCI
--   ar/artistico    → ART
--   cl/classico     → CLA
--   li/ling/lingu*  → LIN
--   af/afm/amm*     → AFM
--   altri valori 3-6 UPPER → invariati (validati da pattern app)

-- ─────── 4. Backfill verifica_documents (via teacher_institutes) ───────
UPDATE verifica_documents vd
JOIN teacher_institutes ti ON ti.user_id = vd.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = CASE LOWER(vd.indirizzo)
        WHEN 'sc' THEN 'SCI'
        WHEN 'ar' THEN 'ART'
        WHEN 'cl' THEN 'CLA'
        WHEN 'li' THEN 'LIN'
        WHEN 'ling' THEN 'LIN'
        WHEN 'af' THEN 'AFM'
        ELSE UPPER(vd.indirizzo)
   END
SET vd.indirizzo_id = ce.id
WHERE vd.indirizzo IS NOT NULL AND vd.indirizzo_id IS NULL;

-- Fallback per row senza match (teacher senza institute o code non in catalog):
-- punta alle global rows (institute_id NULL).
UPDATE verifica_documents vd
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id IS NULL
   AND ce.code = CASE LOWER(vd.indirizzo)
        WHEN 'sc' THEN 'SCI' WHEN 'ar' THEN 'ART' WHEN 'cl' THEN 'CLA'
        WHEN 'li' THEN 'LIN' WHEN 'ling' THEN 'LIN' WHEN 'af' THEN 'AFM'
        ELSE UPPER(vd.indirizzo)
   END
SET vd.indirizzo_id = ce.id
WHERE vd.indirizzo IS NOT NULL AND vd.indirizzo_id IS NULL;

-- ─────── 5. Backfill teacher_content (via teacher_institutes) ───────
UPDATE teacher_content tc
JOIN teacher_institutes ti ON ti.user_id = tc.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = CASE LOWER(tc.indirizzo)
        WHEN 'sc' THEN 'SCI' WHEN 'ar' THEN 'ART' WHEN 'cl' THEN 'CLA'
        WHEN 'li' THEN 'LIN' WHEN 'ling' THEN 'LIN' WHEN 'af' THEN 'AFM'
        ELSE UPPER(tc.indirizzo)
   END
SET tc.indirizzo_id = ce.id
WHERE tc.indirizzo IS NOT NULL AND tc.indirizzo_id IS NULL;

UPDATE teacher_content tc
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi' AND ce.institute_id IS NULL
   AND ce.code = CASE LOWER(tc.indirizzo)
        WHEN 'sc' THEN 'SCI' WHEN 'ar' THEN 'ART' WHEN 'cl' THEN 'CLA'
        WHEN 'li' THEN 'LIN' WHEN 'ling' THEN 'LIN' WHEN 'af' THEN 'AFM'
        ELSE UPPER(tc.indirizzo)
   END
SET tc.indirizzo_id = ce.id
WHERE tc.indirizzo IS NOT NULL AND tc.indirizzo_id IS NULL;

-- ─────── 6. Backfill exercises (no teacher_id → solo global) ───────
UPDATE exercises ex
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi' AND ce.institute_id IS NULL
   AND ce.code = CASE LOWER(ex.indirizzo)
        WHEN 'sc' THEN 'SCI' WHEN 'ar' THEN 'ART' WHEN 'cl' THEN 'CLA'
        WHEN 'li' THEN 'LIN' WHEN 'ling' THEN 'LIN' WHEN 'af' THEN 'AFM'
        ELSE UPPER(ex.indirizzo)
   END
SET ex.indirizzo_id = ce.id
WHERE ex.indirizzo IS NOT NULL AND ex.indirizzo_id IS NULL;

-- ─────── 7. Backfill print_info (user_id → teacher_institutes) ───────
UPDATE print_info pi
JOIN teacher_institutes ti ON ti.user_id = pi.user_id
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = UPPER(pi.indirizzo)
SET pi.indirizzo_id = ce.id
WHERE pi.indirizzo IS NOT NULL AND pi.indirizzo_id IS NULL;

UPDATE print_info pi
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi' AND ce.institute_id IS NULL
   AND ce.code = UPPER(pi.indirizzo)
SET pi.indirizzo_id = ce.id
WHERE pi.indirizzo IS NOT NULL AND pi.indirizzo_id IS NULL;

-- ─────── 8. Backfill risdoc_compilations ───────
UPDATE risdoc_compilations rc
JOIN teacher_institutes ti ON ti.user_id = rc.teacher_id
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id = ti.institute_id
   AND ce.code = UPPER(rc.indirizzo)
SET rc.indirizzo_id = ce.id
WHERE rc.indirizzo IS NOT NULL AND rc.indirizzo_id IS NULL;

UPDATE risdoc_compilations rc
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi' AND ce.institute_id IS NULL
   AND ce.code = UPPER(rc.indirizzo)
SET rc.indirizzo_id = ce.id
WHERE rc.indirizzo IS NOT NULL AND rc.indirizzo_id IS NULL;

-- ─────── 9. Backfill teacher_access_credentials (ha institute_id diretto) ───────
UPDATE teacher_access_credentials tac
JOIN curriculum_entries ce
    ON ce.kind = 'indirizzi'
   AND ce.institute_id = tac.institute_id
   AND ce.code = UPPER(tac.indirizzo)
SET tac.indirizzo_id = ce.id
WHERE tac.indirizzo IS NOT NULL AND tac.indirizzo_id IS NULL;

-- ─────── 10. FK constraints (ON DELETE SET NULL: se elimino curriculum entry,
--             non distruggo row legate, le lascio NULL pendenti) ───────
ALTER TABLE verifica_documents
    ADD CONSTRAINT fk_verifdoc_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE teacher_content
    ADD CONSTRAINT fk_teachcont_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE classe_keys
    ADD CONSTRAINT fk_clskeys_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE exercises
    ADD CONSTRAINT fk_exercises_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE print_info
    ADD CONSTRAINT fk_printinfo_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE risdoc_compilations
    ADD CONSTRAINT fk_risdoccomp_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

ALTER TABLE teacher_access_credentials
    ADD CONSTRAINT fk_teachcred_indirizzo
    FOREIGN KEY (indirizzo_id) REFERENCES curriculum_entries(id) ON DELETE SET NULL;

-- ─────── 11. Cleanup varchar storico: normalizza in canonical ───────
-- Mantiene la column varchar consistente con curriculum_entries.code,
-- evitando doppioni nei sync output (es. 'ar' + 'ART' come cartelle separate).
UPDATE verifica_documents SET indirizzo = 'SCI' WHERE indirizzo = 'sc';
UPDATE verifica_documents SET indirizzo = 'ART' WHERE indirizzo = 'ar';
UPDATE verifica_documents SET indirizzo = 'CLA' WHERE indirizzo = 'cl';
UPDATE verifica_documents SET indirizzo = 'LIN' WHERE indirizzo IN ('li', 'ling');
UPDATE verifica_documents SET indirizzo = 'AFM' WHERE indirizzo = 'af';
