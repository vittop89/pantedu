-- 113 — ADR-035, fase 1: il catalogo è dell'istituto, il docente lo spunta.
--
-- SICUREZZA: additiva. Una tabella nuova (curriculum_teacher) e una colonna
-- nuova con valore predefinito (curriculum_entries.origine). Le copie per
-- docente restano dove sono e non vengono toccate: il codice del rilascio
-- precedente continua a leggerle, quello nuovo legge la relazione. Con questo
-- schema girano tutti e due.
-- ROLLBACK: DROP TABLE curriculum_teacher; ALTER TABLE curriculum_entries
-- DROP COLUMN origine. Niente va perso: la relazione si ricostruisce dalle
-- copie, che sono ancora lì.
--
-- ── Perché ─────────────────────────────────────────────────────────────────
--
-- `curriculum_entries` tiene nella stessa tabella il VOCABOLARIO dell'istituto
-- (`owner_user_id IS NULL`: «qui si insegna Matematica», «qui esiste la 2A») e
-- una COPIA per ogni docente che lo usa (`owner_user_id = docente`), con la
-- propria etichetta, il proprio corso, il proprio stato. Le copie nascono dalle
-- migrazioni 042 e 044, che hanno clonato le righe per docente e cancellato il
-- pivot `curriculum_users`; da allora la stessa aula esiste in tante versioni
-- quanti sono i docenti che la usano. Misurato il 13 settembre 2026 in
-- produzione: 22 copie di classi su 33 voci, e 2.256 riferimenti dei contenuti
-- su 2.425 che puntano a una copia anziché alla voce della scuola (fase 0,
-- tools/curriculum/catalogo_fase0.php).
--
-- Da qui in poi il legame docente↔voce è una riga di RELAZIONE, con gli
-- attributi che giustificavano le copie: `active` (il docente si toglie una
-- voce senza toglierla alla scuola), `shared_with_pool` (la condivisione è del
-- legame, non della materia), `label_override` (come il docente vuole vederla
-- nei propri menù). È `curriculum_users` con le colonne che ne avevano
-- motivato la rimozione.
--
-- Il vocabolario dichiara la propria provenienza (`origine`): `miur` lo scrive
-- l'importatore delle adozioni, `istituto` l'amministratore, `docente` è una
-- voce promossa da una copia che nel vocabolario non c'era. Le righe che
-- esistono già non si sanno distinguere: partono tutte come `istituto`, e
-- l'importatore marca `miur` da adesso in poi.
--
-- Cosa fa, in ordine:
--   1. la colonna `origine`;
--   2. la tabella `curriculum_teacher`;
--   3. le copie il cui codice NON esiste nel vocabolario dell'istituto
--      diventano voce di vocabolario, marcata `docente` («promuovere non è
--      inventare»: la riga esisteva già in quell'istituto);
--   4. per ogni copia, una riga di relazione verso la voce di vocabolario con
--      lo stesso (kind, code, istituto), con stato e condivisione della copia e
--      l'etichetta della copia come override se diversa da quella della scuola.
--
-- Idempotente: ADD COLUMN IF NOT EXISTS, CREATE TABLE IF NOT EXISTS, la
-- promozione trova zero righe al secondo giro, l'INSERT IGNORE non duplica.

SET NAMES utf8mb4;

-- ─────── 1. Provenienza della voce ───────
ALTER TABLE curriculum_entries
    ADD COLUMN IF NOT EXISTS origine VARCHAR(16) NOT NULL DEFAULT 'istituto'
        COMMENT 'Chi ha messo la voce nel vocabolario: miur (importatore delle adozioni), istituto (amministratore), docente (promossa da una copia)'
        AFTER shared_with_pool;

-- ─────── 2. La relazione docente↔voce ───────
CREATE TABLE IF NOT EXISTS curriculum_teacher (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    curriculum_id    INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    active           TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = il docente ha tolto la voce dai propri menù, senza toglierla alla scuola',
    shared_with_pool TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'i contenuti del docente in questa voce sono nel pool dei colleghi',
    label_override   VARCHAR(255) NULL COMMENT 'come il docente vuole vedere la voce nei propri menù; NULL = etichetta della scuola',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curriculum_teacher (curriculum_id, user_id),
    KEY idx_ct_user (user_id, active),
    CONSTRAINT fk_ct_curriculum FOREIGN KEY (curriculum_id) REFERENCES curriculum_entries (id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Le voci del vocabolario che ogni docente ha spuntato (ADR-035)';

-- ─────── 3. Promozione delle copie senza ancora ───────
INSERT INTO curriculum_entries
    (kind, institute_id, owner_user_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
SELECT c.kind, c.institute_id, NULL, c.code, MIN(c.label), MIN(c.grp), MIN(c.indirizzo), 1, 0, 'docente'
  FROM curriculum_entries c
  LEFT JOIN curriculum_entries a
         ON a.kind = c.kind AND a.code = c.code
        AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
 WHERE c.owner_user_id IS NOT NULL
   AND c.institute_id IS NOT NULL
   AND a.id IS NULL
 GROUP BY c.kind, c.institute_id, c.code;

-- ─────── 4. La relazione, dalle copie ───────
INSERT IGNORE INTO curriculum_teacher (curriculum_id, user_id, active, shared_with_pool, label_override)
SELECT a.id, c.owner_user_id, c.active, c.shared_with_pool,
       IF(c.label <> a.label, c.label, NULL)
  FROM curriculum_entries c
  JOIN curriculum_entries a
    ON a.kind = c.kind AND a.code = c.code
   AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
 WHERE c.owner_user_id IS NOT NULL;
