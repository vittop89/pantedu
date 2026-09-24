-- SICUREZZA: il codice precedente legge le classi per sigla, e con le sigle della vista le pubblicazioni restano visibili come prima (il corso era già sulla pubblicazione). Scrivendo, nel minuto fra questa migrazione e lo scambio dei container risolverebbe un anno con la prima voce che trova, di un corso qualunque: si unisce quando nessun docente sta salvando. Cancella solo gli anni senza corso, dopo aver contato che nessuna riga li punta più (le chiavi esterne sono ON DELETE SET NULL: senza il conteggio una classe si azzererebbe in silenzio). Provata a secco su una copia delle tabelle di produzione il 15/9/2026.
-- ROLLBACK: tools/curriculum/anni_di_tutta_la_scuola.sql (rifonde gli anni di ogni corso in un anno per scuola, con riferimenti e spunte, rimette indice e vincolo di prima e toglie questa riga da schema_migrations), poi il codice precedente.
--
-- 132 — ADR-042: gli anni di corso appartengono a un indirizzo (15/9/2026).
--
-- Fino a qui un anno («1»…«5») era una voce sola per tutta la scuola, senza
-- corso, e valeva sotto ogni indirizzo spuntato: sotto «architettura e
-- ambiente» comparivano la prima e la seconda, che quel corso non ha. Da qui un
-- anno esiste una volta per corso, e il database non ne accetta uno senza.
--
-- Che cosa fa, in ordine:
--   1. l'indice unico lascia ripetere un anno, una volta per corso
--      (`corso_anno`); le sigle delle sezioni restano uniche nella scuola;
--   2. crea gli anni di ogni corso: quelli in cui il corso ha sezioni, quelli
--      su cui ci sono già dati del corso; un corso senza sezioni li prende
--      tutti;
--   3. le righe senza corso vanno al corso con più dati su quell'anno, poi con
--      più sezioni, poi per sigla; e prendono l'indirizzo. In produzione è lo
--      scientifico (scelta dell'utente per le 6 bozze di verifica sulla «2»);
--   4. ripunta pubblicazioni, esercizi, impostazioni di stampa, compilazioni,
--      credenziali e importazioni sull'anno del loro corso;
--   5. divide le spunte: l'anno spuntato diventa l'anno di ogni corso che il
--      docente ha acceso, dove ha dati su quell'anno o dove ha l'incarico;
--   6. conta i riferimenti rimasti agli anni senza corso: se non sono zero si
--      ferma, prima di cancellare;
--   7. cancella gli anni senza corso e mette il vincolo.
--
-- Le istruzioni fra «DATI: inizio» e «DATI: fine» non fanno DDL su tabelle
-- vere: la prova (tests/Integration/AnniPerIndirizzoMigrazioneTest.php) le
-- esegue dentro una transazione.

SET NAMES utf8mb4;

-- ── 1. L'indice unico ─────────────────────────────────────────────────────
ALTER TABLE curriculum_entries
    ADD COLUMN IF NOT EXISTS corso_anno VARCHAR(16)
        AS (IF(kind = 'classi' AND code REGEXP '^[1-9]$', indirizzo, '')) PERSISTENT
        COMMENT 'ADR-042 — il corso di un anno; vuoto per sezioni, indirizzi e materie';

ALTER TABLE curriculum_entries
    DROP INDEX IF EXISTS uq_curriculum_voce,
    ADD UNIQUE KEY uq_curriculum_voce (kind, code, institute_id, corso_anno);

-- ── DATI: inizio ──────────────────────────────────────────────────────────

-- Gli anni senza corso.
DROP TEMPORARY TABLE IF EXISTS _anni_vecchi;
CREATE TEMPORARY TABLE _anni_vecchi (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    institute_id INT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    label VARCHAR(255) NOT NULL,
    grp VARCHAR(64) NULL,
    active TINYINT(1) NOT NULL,
    shared_with_pool TINYINT(1) NOT NULL,
    origine VARCHAR(16) NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _anni_vecchi
SELECT id, institute_id, code, label, grp, active, shared_with_pool, origine
  FROM curriculum_entries
 WHERE kind = 'classi' AND code REGEXP '^[1-9]$' AND (indirizzo IS NULL OR indirizzo = '');

-- I corsi delle scuole che li hanno.
DROP TEMPORARY TABLE IF EXISTS _corsi;
CREATE TEMPORARY TABLE _corsi (
    institute_id INT UNSIGNED NOT NULL,
    corso VARCHAR(16) NOT NULL,
    corso_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (institute_id, corso)
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _corsi
SELECT ce.institute_id, ce.code, MIN(ce.id)
  FROM curriculum_entries ce
 WHERE ce.kind = 'indirizzi'
   AND ce.institute_id IN (SELECT DISTINCT institute_id FROM _anni_vecchi)
 GROUP BY ce.institute_id, ce.code;

-- Quanti dati con il corso ci sono su ogni anno senza corso, per corso.
DROP TEMPORARY TABLE IF EXISTS _peso;
CREATE TEMPORARY TABLE _peso (
    vecchio_id INT UNSIGNED NOT NULL,
    corso VARCHAR(16) NOT NULL,
    dati INT UNSIGNED NOT NULL,
    PRIMARY KEY (vecchio_id, corso)
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM content_publications t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM exercises_data t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM print_info_data t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM risdoc_compilations_data t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM teacher_access_credentials_data t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);
INSERT INTO _peso
SELECT t.classe_id, i.code, COUNT(*) FROM pdf_import_sessions t
  JOIN _anni_vecchi v ON v.id = t.classe_id
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
 GROUP BY t.classe_id, i.code
ON DUPLICATE KEY UPDATE dati = dati + VALUES(dati);

-- 2. Gli anni di ogni corso.
DROP TEMPORARY TABLE IF EXISTS _anni_nuovi;
CREATE TEMPORARY TABLE _anni_nuovi (
    vecchio_id INT UNSIGNED NOT NULL,
    corso VARCHAR(16) NOT NULL,
    PRIMARY KEY (vecchio_id, corso)
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- (a) dove il corso ha sezioni di quell'anno;
INSERT IGNORE INTO _anni_nuovi
SELECT v.id, s.indirizzo
  FROM curriculum_entries s
  JOIN _anni_vecchi v ON v.institute_id = s.institute_id AND v.code = LEFT(s.code, 1)
 WHERE s.kind = 'classi' AND s.code REGEXP '^[1-9][A-Za-z0-9]+$'
   AND s.indirizzo IS NOT NULL AND s.indirizzo <> '';
-- (b) tutti, per un corso che non ha nessuna sezione;
INSERT IGNORE INTO _anni_nuovi
SELECT v.id, c.corso
  FROM _anni_vecchi v
  JOIN _corsi c ON c.institute_id = v.institute_id
 WHERE NOT EXISTS (SELECT 1 FROM curriculum_entries s
                    WHERE s.kind = 'classi' AND s.institute_id = c.institute_id
                      AND s.indirizzo = c.corso AND s.code REGEXP '^[1-9][A-Za-z0-9]+$');
-- (c) dove ci sono già dati di quel corso.
INSERT IGNORE INTO _anni_nuovi SELECT vecchio_id, corso FROM _peso;

-- 3. Dove vanno le righe senza corso: il corso con più dati su quell'anno, poi
--    con più sezioni di quell'anno, poi per sigla.
DROP TEMPORARY TABLE IF EXISTS _destinazione;
CREATE TEMPORARY TABLE _destinazione (
    vecchio_id INT UNSIGNED NOT NULL PRIMARY KEY,
    corso VARCHAR(16) NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _destinazione
SELECT vecchio_id, corso FROM (
    SELECT candidati.*,
           ROW_NUMBER() OVER (PARTITION BY vecchio_id ORDER BY dati DESC, sezioni DESC, corso) AS posto
      FROM (
          SELECT v.id AS vecchio_id, c.corso, COALESCE(p.dati, 0) AS dati,
                 (SELECT COUNT(*) FROM curriculum_entries s
                   WHERE s.kind = 'classi' AND s.institute_id = v.institute_id
                     AND s.indirizzo = c.corso AND LEFT(s.code, 1) = v.code
                     AND s.code REGEXP '^[1-9][A-Za-z0-9]+$') AS sezioni
            FROM _anni_vecchi v
            JOIN _corsi c ON c.institute_id = v.institute_id
            LEFT JOIN _peso p ON p.vecchio_id = v.id AND p.corso = c.corso
      ) candidati
) scelte
WHERE posto = 1;
-- Serve l'anno del corso scelto solo se c'è davvero una riga senza corso.
INSERT IGNORE INTO _anni_nuovi
SELECT d.vecchio_id, d.corso FROM _destinazione d
 WHERE EXISTS (SELECT 1 FROM content_publications t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL)
    OR EXISTS (SELECT 1 FROM exercises_data t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL)
    OR EXISTS (SELECT 1 FROM print_info_data t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL)
    OR EXISTS (SELECT 1 FROM risdoc_compilations_data t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL)
    OR EXISTS (SELECT 1 FROM teacher_access_credentials_data t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL)
    OR EXISTS (SELECT 1 FROM pdf_import_sessions t WHERE t.classe_id = d.vecchio_id AND t.indirizzo_id IS NULL);

-- Le voci nuove, con etichetta e stato dell'anno di prima.
INSERT INTO curriculum_entries (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
SELECT 'classi', v.institute_id, v.code, v.label, v.grp, n.corso, v.active, v.shared_with_pool, v.origine
  FROM _anni_nuovi n
  JOIN _anni_vecchi v ON v.id = n.vecchio_id
ON DUPLICATE KEY UPDATE curriculum_entries.active = curriculum_entries.active;

DROP TEMPORARY TABLE IF EXISTS _mappa;
CREATE TEMPORARY TABLE _mappa (
    vecchio_id INT UNSIGNED NOT NULL,
    corso VARCHAR(16) NOT NULL,
    nuovo_id INT UNSIGNED NOT NULL,
    corso_id INT UNSIGNED NULL,
    PRIMARY KEY (vecchio_id, corso)
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO _mappa
SELECT v.id, ce.indirizzo, ce.id, c.corso_id
  FROM _anni_vecchi v
  JOIN curriculum_entries ce
    ON ce.kind = 'classi' AND ce.institute_id = v.institute_id AND ce.code = v.code
   AND ce.indirizzo IS NOT NULL AND ce.indirizzo <> ''
  LEFT JOIN _corsi c ON c.institute_id = v.institute_id AND c.corso = ce.indirizzo;

-- 4. I dati sull'anno del loro corso…
UPDATE content_publications t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;
UPDATE exercises_data t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;
UPDATE print_info_data t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;
UPDATE risdoc_compilations_data t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;
UPDATE teacher_access_credentials_data t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;
UPDATE pdf_import_sessions t
  JOIN curriculum_entries i ON i.id = t.indirizzo_id AND i.kind = 'indirizzi'
  JOIN _mappa m ON m.vecchio_id = t.classe_id AND m.corso = i.code
   SET t.classe_id = m.nuovo_id;

-- … e quelli senza corso sull'anno del corso scelto, che diventa il loro.
UPDATE content_publications t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;
UPDATE exercises_data t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;
UPDATE print_info_data t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;
UPDATE risdoc_compilations_data t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;
UPDATE teacher_access_credentials_data t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;
UPDATE pdf_import_sessions t
  JOIN _destinazione d ON d.vecchio_id = t.classe_id
  JOIN _mappa m ON m.vecchio_id = d.vecchio_id AND m.corso = d.corso
   SET t.classe_id = m.nuovo_id, t.indirizzo_id = m.corso_id
 WHERE t.indirizzo_id IS NULL;

-- 5. Le spunte: l'anno di ogni corso in cui il docente lavora.
INSERT IGNORE INTO curriculum_teacher
    (curriculum_id, user_id, active, shared_with_pool, label_override, created_at, sospesa_dalla_scuola)
SELECT m.nuovo_id, ct.user_id, ct.active, ct.shared_with_pool, ct.label_override, ct.created_at, ct.sospesa_dalla_scuola
  FROM curriculum_teacher ct
  JOIN _mappa m ON m.vecchio_id = ct.curriculum_id
  JOIN curriculum_entries nuovo ON nuovo.id = m.nuovo_id
 WHERE EXISTS (SELECT 1 FROM curriculum_teacher ci
                 JOIN curriculum_entries ce ON ce.id = ci.curriculum_id
                WHERE ci.user_id = ct.user_id AND ci.active = 1 AND ce.kind = 'indirizzi'
                  AND ce.institute_id = nuovo.institute_id AND ce.code = m.corso)
    OR EXISTS (SELECT 1 FROM teacher_sections ts
                WHERE ts.user_id = ct.user_id AND ts.institute_id = nuovo.institute_id
                  AND UPPER(ts.indirizzo) = UPPER(m.corso) AND UPPER(ts.classe) = UPPER(nuovo.code))
    OR EXISTS (SELECT 1 FROM content_publications p
                 LEFT JOIN teacher_content_data d ON d.id = p.teacher_content_id
                 LEFT JOIN verifica_documents_data vd ON vd.id = p.verifica_document_id
                WHERE p.classe_id = m.nuovo_id AND COALESCE(d.teacher_id, vd.teacher_id) = ct.user_id)
    OR EXISTS (SELECT 1 FROM print_info_data x WHERE x.classe_id = m.nuovo_id AND x.user_id = ct.user_id)
    OR EXISTS (SELECT 1 FROM risdoc_compilations_data x WHERE x.classe_id = m.nuovo_id AND x.teacher_id = ct.user_id)
    OR EXISTS (SELECT 1 FROM teacher_access_credentials_data x WHERE x.classe_id = m.nuovo_id AND x.teacher_id = ct.user_id)
    OR EXISTS (SELECT 1 FROM pdf_import_sessions x WHERE x.classe_id = m.nuovo_id AND x.teacher_id = ct.user_id);

-- 6. Prima di cancellare: nessuna riga deve puntare ancora a un anno senza corso.
DELIMITER //
BEGIN NOT ATOMIC
    DECLARE rimasti INT UNSIGNED DEFAULT 0;
    SELECT (SELECT COUNT(*) FROM content_publications t JOIN _anni_vecchi v ON v.id = t.classe_id)
         + (SELECT COUNT(*) FROM exercises_data t JOIN _anni_vecchi v ON v.id = t.classe_id)
         + (SELECT COUNT(*) FROM print_info_data t JOIN _anni_vecchi v ON v.id = t.classe_id)
         + (SELECT COUNT(*) FROM risdoc_compilations_data t JOIN _anni_vecchi v ON v.id = t.classe_id)
         + (SELECT COUNT(*) FROM teacher_access_credentials_data t JOIN _anni_vecchi v ON v.id = t.classe_id)
         + (SELECT COUNT(*) FROM pdf_import_sessions t JOIN _anni_vecchi v ON v.id = t.classe_id)
      INTO rimasti;
    IF rimasti > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '132 (ADR-042): restano righe sugli anni senza corso, nessun anno cancellato';
    END IF;
END//
DELIMITER ;

-- 7. Via gli anni senza corso (le loro spunte vanno con loro).
DELETE ce FROM curriculum_entries ce JOIN _anni_vecchi v ON v.id = ce.id;

DROP TEMPORARY TABLE IF EXISTS _mappa;
DROP TEMPORARY TABLE IF EXISTS _destinazione;
DROP TEMPORARY TABLE IF EXISTS _anni_nuovi;
DROP TEMPORARY TABLE IF EXISTS _peso;
DROP TEMPORARY TABLE IF EXISTS _corsi;
DROP TEMPORARY TABLE IF EXISTS _anni_vecchi;

-- ── DATI: fine ────────────────────────────────────────────────────────────

ALTER TABLE curriculum_entries DROP CONSTRAINT IF EXISTS chk_anno_ha_corso;
ALTER TABLE curriculum_entries
    ADD CONSTRAINT chk_anno_ha_corso
        CHECK (kind <> 'classi' OR code NOT REGEXP '^[1-9]$' OR (indirizzo IS NOT NULL AND indirizzo <> ''));
