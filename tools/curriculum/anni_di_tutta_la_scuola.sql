-- Il ritorno indietro della migrazione 132 (ADR-042): gli anni di ogni corso
-- tornano un anno solo per tutta la scuola.
--
-- Da usare solo se si torna al codice precedente la 132: quel codice non sa
-- scegliere fra «3» dello scientifico e «3» dell'artistico. Si lancia con il
-- client mysql (conosce DELIMITER), sul database dell'applicazione, con
-- un'utenza che può fare ALTER:
--
--   sudo mysql pantedu < tools/curriculum/anni_di_tutta_la_scuola.sql
--
-- Che cosa torna com'era e che cosa no:
--   - riferimenti (pubblicazioni, esercizi, stampa, compilazioni, credenziali,
--     importazioni) sull'anno della scuola;
--   - spunte per unione: un anno acceso in almeno un corso torna acceso; il nome
--     personale è il primo trovato;
--   - le righe che la 132 aveva mandato su un corso perché non ne avevano (in
--     produzione le 6 bozze di verifica, 2 impostazioni di stampa, 1
--     compilazione) tengono l'indirizzo: sull'anno della scuola non cambia chi
--     le vede;
--   - indice unico e vincolo di prima, e la riga della 132 in schema_migrations
--     esce, così un rilascio successivo la riapplica.
--
-- Si ferma prima di cancellare se qualche riga punta ancora a un anno di un
-- corso. Provato il 15/9/2026: 132, poi questo file, e i riferimenti per sigla
-- tornano uguali a prima (tests/Integration/AnniPerIndirizzoMigrazioneTest.php
-- e la prova a secco sulla copia di produzione).

SET NAMES utf8mb4;

ALTER TABLE curriculum_entries DROP CONSTRAINT IF EXISTS chk_anno_ha_corso;

-- ── DATI: inizio ──────────────────────────────────────────────────────────

DROP TEMPORARY TABLE IF EXISTS _anni_per_corso;
CREATE TEMPORARY TABLE _anni_per_corso (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    institute_id INT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _anni_per_corso
SELECT id, institute_id, code FROM curriculum_entries
 WHERE kind = 'classi' AND code REGEXP '^[1-9]$' AND indirizzo IS NOT NULL AND indirizzo <> '';

-- Un anno per scuola, con l'etichetta del primo e acceso se lo era uno.
INSERT INTO curriculum_entries (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
SELECT 'classi', ce.institute_id, ce.code, MIN(ce.label), MIN(ce.grp), NULL, MAX(ce.active), MAX(ce.shared_with_pool), MIN(ce.origine)
  FROM curriculum_entries ce
  JOIN _anni_per_corso a ON a.id = ce.id
 GROUP BY ce.institute_id, ce.code
ON DUPLICATE KEY UPDATE curriculum_entries.active = curriculum_entries.active;

DROP TEMPORARY TABLE IF EXISTS _indietro;
CREATE TEMPORARY TABLE _indietro (
    vecchio_id INT UNSIGNED NOT NULL PRIMARY KEY,
    nuovo_id INT UNSIGNED NOT NULL
);
INSERT INTO _indietro
SELECT a.id, s.id
  FROM _anni_per_corso a
  JOIN curriculum_entries s
    ON s.kind = 'classi' AND s.institute_id = a.institute_id AND s.code = a.code
   AND (s.indirizzo IS NULL OR s.indirizzo = '');

UPDATE content_publications t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;
UPDATE exercises_data t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;
UPDATE print_info_data t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;
UPDATE risdoc_compilations_data t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;
UPDATE teacher_access_credentials_data t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;
UPDATE pdf_import_sessions t JOIN _indietro m ON m.vecchio_id = t.classe_id SET t.classe_id = m.nuovo_id;

INSERT INTO curriculum_teacher
    (curriculum_id, user_id, active, shared_with_pool, label_override, created_at, sospesa_dalla_scuola)
SELECT m.nuovo_id, ct.user_id, MAX(ct.active), MAX(ct.shared_with_pool), MIN(ct.label_override), MIN(ct.created_at), MIN(ct.sospesa_dalla_scuola)
  FROM curriculum_teacher ct
  JOIN _indietro m ON m.vecchio_id = ct.curriculum_id
 GROUP BY m.nuovo_id, ct.user_id
ON DUPLICATE KEY UPDATE active = GREATEST(curriculum_teacher.active, VALUES(active));

DELIMITER //
BEGIN NOT ATOMIC
    DECLARE rimasti INT UNSIGNED DEFAULT 0;
    SELECT (SELECT COUNT(*) FROM content_publications t JOIN _anni_per_corso a ON a.id = t.classe_id)
         + (SELECT COUNT(*) FROM exercises_data t JOIN _anni_per_corso a ON a.id = t.classe_id)
         + (SELECT COUNT(*) FROM print_info_data t JOIN _anni_per_corso a ON a.id = t.classe_id)
         + (SELECT COUNT(*) FROM risdoc_compilations_data t JOIN _anni_per_corso a ON a.id = t.classe_id)
         + (SELECT COUNT(*) FROM teacher_access_credentials_data t JOIN _anni_per_corso a ON a.id = t.classe_id)
         + (SELECT COUNT(*) FROM pdf_import_sessions t JOIN _anni_per_corso a ON a.id = t.classe_id)
      INTO rimasti;
    IF rimasti > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ritorno della 132: restano righe sugli anni di un corso, nessun anno cancellato';
    END IF;
END//
DELIMITER ;

DELETE ce FROM curriculum_entries ce JOIN _anni_per_corso a ON a.id = ce.id;

DROP TEMPORARY TABLE IF EXISTS _indietro;
DROP TEMPORARY TABLE IF EXISTS _anni_per_corso;

-- ── DATI: fine ────────────────────────────────────────────────────────────

ALTER TABLE curriculum_entries
    DROP INDEX IF EXISTS uq_curriculum_voce,
    ADD UNIQUE KEY uq_curriculum_voce (kind, code, institute_id);
ALTER TABLE curriculum_entries DROP COLUMN IF EXISTS corso_anno;

DELETE FROM schema_migrations WHERE filename = '132_anni_per_indirizzo.sql';
