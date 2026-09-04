-- G22.S22 — Catalog ownership refactor full: anche indirizzi/classi
-- diventano per-docente. Niente piu' condivisione institute-level di
-- queste due categorie. Coerente con la migration 042 (materie).
--
-- Cambio modello:
--   PRIMA: curriculum_entries(kind in 'indirizzi','classi') condivise
--          per istituto. curriculum_users pivot decideva quali ogni
--          docente vedeva.
--   DOPO : ogni docente possiede le proprie righe di tutti i kind.
--          curriculum_users non serve piu'.
--
-- Si mantengono le righe anchor (owner_user_id IS NULL) per ogni istituto
-- usate da exercises.indirizzo_id / exercises.classe_id (no teacher_id).
--
-- Re-map FK:
--   teacher_content.indirizzo_id / classe_id → clone owner=teacher_id
--   verifica_documents.indirizzo_id / classe_id → clone owner=teacher_id
--   print_info.indirizzo_id / classe_id → clone owner=user_id
--   exercises.indirizzo_id / classe_id → restano su anchor institute-level
--
-- Idempotente (re-esecuzione no-op).

-- ─────── 1. Clona indirizzi/classi per ogni docente che li usa ───────
-- Source A: curriculum_users (pivot)
INSERT IGNORE INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
SELECT ce.kind, ce.institute_id, cu.user_id, ce.code, ce.label, ce.grp, ce.active, 0
  FROM curriculum_entries ce
  JOIN curriculum_users cu ON cu.curriculum_id = ce.id
 WHERE ce.kind IN ('indirizzi', 'classi')
   AND ce.owner_user_id IS NULL;

-- Source B: docenti con teacher_content.indirizzo_id o classe_id puntante
-- ad anchor (owner NULL) ma non nel pivot — defensive fill.
INSERT IGNORE INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
SELECT DISTINCT ce.kind, ce.institute_id, tc.teacher_id, ce.code, ce.label, ce.grp, ce.active, 0
  FROM curriculum_entries ce
  JOIN teacher_content tc ON tc.indirizzo_id = ce.id
 WHERE ce.kind = 'indirizzi' AND ce.owner_user_id IS NULL;

INSERT IGNORE INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
SELECT DISTINCT ce.kind, ce.institute_id, tc.teacher_id, ce.code, ce.label, ce.grp, ce.active, 0
  FROM curriculum_entries ce
  JOIN teacher_content tc ON tc.classe_id = ce.id
 WHERE ce.kind = 'classi' AND ce.owner_user_id IS NULL;

-- ─────── 2. Re-map teacher_content.indirizzo_id → clone owner=teacher ───────
UPDATE teacher_content_data tc
  JOIN curriculum_entries ce_old
    ON ce_old.id = tc.indirizzo_id
   AND ce_old.kind = 'indirizzi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'indirizzi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = tc.teacher_id
   SET tc.indirizzo_id = ce_new.id;

UPDATE teacher_content_data tc
  JOIN curriculum_entries ce_old
    ON ce_old.id = tc.classe_id
   AND ce_old.kind = 'classi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'classi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = tc.teacher_id
   SET tc.classe_id = ce_new.id;

-- ─────── 3. Re-map verifica_documents.indirizzo_id / classe_id ───────
UPDATE verifica_documents_data vd
  JOIN curriculum_entries ce_old
    ON ce_old.id = vd.indirizzo_id
   AND ce_old.kind = 'indirizzi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'indirizzi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = vd.teacher_id
   SET vd.indirizzo_id = ce_new.id;

UPDATE verifica_documents_data vd
  JOIN curriculum_entries ce_old
    ON ce_old.id = vd.classe_id
   AND ce_old.kind = 'classi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'classi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = vd.teacher_id
   SET vd.classe_id = ce_new.id;

-- ─────── 4. Re-map print_info.indirizzo_id / classe_id (user_id col) ───────
UPDATE print_info_data pi
  JOIN curriculum_entries ce_old
    ON ce_old.id = pi.indirizzo_id
   AND ce_old.kind = 'indirizzi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'indirizzi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = pi.user_id
   SET pi.indirizzo_id = ce_new.id;

UPDATE print_info_data pi
  JOIN curriculum_entries ce_old
    ON ce_old.id = pi.classe_id
   AND ce_old.kind = 'classi'
   AND ce_old.owner_user_id IS NULL
  JOIN curriculum_entries ce_new
    ON ce_new.kind = 'classi'
   AND ce_new.code = ce_old.code
   AND ce_new.institute_id <=> ce_old.institute_id
   AND ce_new.owner_user_id = pi.user_id
   SET pi.classe_id = ce_new.id;

-- exercises.indirizzo_id / classe_id restano su anchor (no teacher_id).

-- ─────── 5. Drop curriculum_users (pivot non piu' necessario) ───────
DROP TABLE IF EXISTS curriculum_users;
