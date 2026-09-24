-- 116 — ADR-035, fase 3: via le copie per docente, e le due colonne che le reggevano.
--
-- Dal rilascio precedente (migrazioni 113 e 114) la spunta del docente è una
-- riga di curriculum_teacher e ogni riferimento dei contenuti punta alla voce
-- della scuola: le righe con owner_user_id NOT NULL non le legge e non le
-- scrive più nessuno. Questa migrazione le cancella e toglie owner_user_id e
-- owner_key; l'indice unico torna a (kind, code, institute_id): una voce per
-- codice, per istituto. È il secondo passo della regola dei due passi.
--
-- SICUREZZA: il codice del rilascio precedente non legge né scrive owner_user_id, owner_key né le righe con owner_user_id NOT NULL (ADR-035, fasi 1 e 2: spunte in curriculum_teacher, riferimenti ri-puntati dalla 114). Prima di eseguire, `php tools/curriculum/catalogo_fase0.php` (presente fino al rilascio precedente) deve riportare 0 riferimenti «su copia» in ogni tabella e 0 chiavi di classe su copie; il trigger trg_curriculum_no_orphan rifiuta comunque di cancellare una voce ancora usata.
-- ROLLBACK: dal dump di curriculum_entries preso prima del rilascio — le righe cancellate non si ricostruiscono da curriculum_teacher, che conserva active, shared_with_pool e label_override ma non grp e indirizzo delle copie. Lo schema si rimette con: ALTER TABLE curriculum_entries DROP INDEX uq_curriculum_voce, ADD COLUMN owner_user_id INT UNSIGNED NULL, ADD COLUMN owner_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(owner_user_id, 0)) STORED, ADD UNIQUE KEY uq_curriculum_owner (kind, code, institute_id, owner_key), ADD KEY idx_ce_owner_kind (owner_user_id, kind, active), ADD CONSTRAINT fk_ce_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE.

-- 1. Le copie. Una spunta che puntasse a una copia (non dovrebbe esistere: la
--    113 le ha create sulle ancore) se ne va con lei, per la FK ON DELETE
--    CASCADE di curriculum_teacher. Il trigger trg_curriculum_no_orphan si
--    ferma se una copia fosse ancora usata da un contenuto.
DELETE FROM curriculum_entries WHERE owner_user_id IS NOT NULL;

-- 2. Le colonne e gli indici che le reggevano. Prima la chiave esterna (che
--    si appoggia a idx_ce_owner_kind), poi gli indici, poi owner_key (generata
--    da owner_user_id) e infine owner_user_id.
ALTER TABLE curriculum_entries DROP FOREIGN KEY fk_ce_owner;
ALTER TABLE curriculum_entries DROP INDEX uq_curriculum_owner;
ALTER TABLE curriculum_entries DROP INDEX idx_ce_owner_kind;
ALTER TABLE curriculum_entries DROP COLUMN owner_key;
ALTER TABLE curriculum_entries DROP COLUMN owner_user_id;

-- 3. Una voce per codice, per istituto.
ALTER TABLE curriculum_entries ADD UNIQUE KEY uq_curriculum_voce (kind, code, institute_id);
