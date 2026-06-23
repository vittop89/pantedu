-- G22.S21 — Catalog ownership refactor: materie diventano per-docente.
--
-- Cambio modello:
--   PRIMA: curriculum_entries(kind='materie') condivise per istituto.
--   DOPO : ogni docente possiede le proprie righe materia (owner_user_id).
--          Indirizzi/classi restano per-istituto (owner_user_id NULL).
--
-- Si mantiene per ogni codice materia istituto la riga "ancora"
-- (owner_user_id NULL) usata da exercises_data (no teacher_id).
-- Tutti i FK per-docente vengono ri-mappati alla nuova riga per-docente:
--   teacher_content.subject_id, verifica_documents.materia_id,
--   print_info.materia_id, curriculum_users.curriculum_id.
--
-- Nuova colonna shared_with_pool: il docente puo' flaggare la sua materia
-- come "condivisibile". Altri docenti dello stesso istituto vedranno una
-- voce "Recupera da altri docenti" nella dashboard.
--
-- ORDINE CRITICO:
--   1. Add columns owner_user_id, shared_with_pool, owner_key (generated).
--   2. Add FK + index su owner_user_id.
--   3. DROP old UNIQUE(kind,code,institute_id) e ADD UNIQUE includendo owner_key.
--      (FONDAMENTALE prima di clonare: altrimenti INSERT IGNORE collide.)
--   4. INSERT clones per ogni (materia, docente).
--   5. Re-map FKs verso le clones per-docente.
--
-- Idempotente: re-esecuzione no-op (IF NOT EXISTS guards + JOIN WHERE owner IS NULL).

-- ─────── 1. Aggiunta colonne ───────
ALTER TABLE curriculum_entries
    ADD COLUMN IF NOT EXISTS owner_user_id INT UNSIGNED NULL AFTER institute_id,
    ADD COLUMN IF NOT EXISTS shared_with_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

-- ─────── 2. FK + index su owner ───────
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND CONSTRAINT_NAME = 'fk_ce_owner'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE curriculum_entries ADD CONSTRAINT fk_ce_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND INDEX_NAME = 'idx_ce_owner_kind'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE curriculum_entries ADD INDEX idx_ce_owner_kind (owner_user_id, kind, active)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────── 3. Colonna generata owner_key + sostituzione UNIQUE ───────
-- owner_key = COALESCE(owner_user_id, 0): unisce NULL e 0 per coerenza
-- nel constraint UNIQUE (MySQL tratta NULL come distinto da NULL → ambiguo).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND COLUMN_NAME = 'owner_key'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE curriculum_entries ADD COLUMN owner_key INT UNSIGNED AS (COALESCE(owner_user_id, 0)) STORED AFTER owner_user_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop old UNIQUE (kind, code, institute_id) PRIMA degli INSERT cloni.
SET @old_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND INDEX_NAME = 'uq_curriculum_inst'
);
SET @sql = IF(@old_idx > 0,
    'ALTER TABLE curriculum_entries DROP INDEX uq_curriculum_inst',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add new UNIQUE includendo owner_key.
SET @new_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curriculum_entries'
      AND INDEX_NAME = 'uq_curriculum_owner'
);
SET @sql = IF(@new_idx = 0,
    'ALTER TABLE curriculum_entries ADD UNIQUE KEY uq_curriculum_owner (kind, code, institute_id, owner_key)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────── 4. Clona materie per ogni docente che le usa ───────
-- Source A: curriculum_users (pivot) — ogni (user, materia) pair genera clone owner=user.
INSERT IGNORE INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
SELECT ce.kind, ce.institute_id, cu.user_id, ce.code, ce.label, ce.grp, ce.active, 0
  FROM curriculum_entries ce
  JOIN curriculum_users cu ON cu.curriculum_id = ce.id
 WHERE ce.kind = 'materie'
   AND ce.owner_user_id IS NULL;

-- Source B: docenti con teacher_content su una materia ma NON nel pivot.
INSERT IGNORE INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
SELECT DISTINCT ce.kind, ce.institute_id, tc.teacher_id, ce.code, ce.label, ce.grp, ce.active, 0
  FROM curriculum_entries ce
  JOIN teacher_content tc ON tc.subject_id = ce.id
 WHERE ce.kind = 'materie'
   AND ce.owner_user_id IS NULL;

-- ─────── 5. Re-map FK alle clones per-docente ───────
-- curriculum_users.curriculum_id → clone owner=user
UPDATE curriculum_users cu
  JOIN curriculum_entries ce_old
    ON ce_old.id = cu.curriculum_id
   AND ce_old.kind = 'materie'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'materie'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = cu.user_id
   SET cu.curriculum_id = ce_new.id;

-- teacher_content.subject_id → clone owner=teacher_id
UPDATE teacher_content tc
  JOIN curriculum_entries ce_old
    ON ce_old.id = tc.subject_id
   AND ce_old.kind = 'materie'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'materie'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = tc.teacher_id
   SET tc.subject_id = ce_new.id;

-- verifica_documents.materia_id → clone owner=teacher_id
UPDATE verifica_documents vd
  JOIN curriculum_entries ce_old
    ON ce_old.id = vd.materia_id
   AND ce_old.kind = 'materie'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'materie'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = vd.teacher_id
   SET vd.materia_id = ce_new.id;

-- print_info.materia_id → clone owner=user_id
UPDATE print_info pi
  JOIN curriculum_entries ce_old
    ON ce_old.id = pi.materia_id
   AND ce_old.kind = 'materie'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'materie'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = pi.user_id
   SET pi.materia_id = ce_new.id;

-- exercises.materia_id resta su institute-level (owner NULL): catalogo condiviso.
-- Nessuna remap. La riga institute-level (owner NULL) viene preservata.
