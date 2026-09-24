-- 134 — ADR-044: username delle credenziali di classe unico su tutta la
-- piattaforma, e le parti dell'etichetta composta dal server (19/9/2026).
--
-- 1. USERNAME UNICO. Fino a qui l'indice era per docente (uq_tac_user:
--    teacher_id + access_username): due docenti potevano dare agli studenti la
--    stessa coppia, e con quella lo studente entrava nella credenziale che la
--    query trovava per prima (misurato sul server di sviluppo: quella del
--    secondo docente restava irraggiungibile). L'indice nuovo vale per tutti i
--    docenti; con la collation utf8mb4_unicode_ci «3A-Mate» e «3a-mate» sono lo
--    stesso username, come all'accesso.
--    Prima dell'indice, un controllo: se ci sono username ripetuti la
--    migrazione si ferma con un messaggio, invece di lasciare l'indice a metà.
--    Misurato il 19/9/2026: 0 ripetuti in produzione (2 credenziali), 0 in
--    sviluppo (26), 0 in pantedu_test.
--    uq_tac_user resta: è l'indice che regge la chiave esterna fk_tac_teacher
--    (nessun altro indice comincia con teacher_id), e non costa niente.
--
-- 2. LE PARTI DELL'ETICHETTA. L'etichetta la compone il server:
--    {CLASSE}_{INDIRIZZO}_{MATERIE}[_{AGGIUNTA}] (App\Domain\EtichettaCredenziale).
--    Si salvano anche le parti, per ricomporla («Rigenera etichetta») e per
--    ricalcolarla se una migrazione del catalogo ripunta le classi, come ha
--    fatto la 132:
--      materie   sigle separate da virgola, in ordine alfabetico; '' = etichetta
--                composta senza materie; NULL = etichetta libera, scritta dal
--                docente prima di questa migrazione;
--      aggiunta  l'aggiunta facoltativa, in maiuscolo, al massimo 12 caratteri.
--    Le credenziali esistenti non si toccano: le rigenera il docente dalla riga.
--
-- 3. La VIEW teacher_access_credentials è `SELECT tac.*` (040, 105): MariaDB
--    espande `*` alla creazione, quindi si ricrea per vedere le colonne nuove
--    (wiki/dev-workflow.md, «Le viste non vedono le colonne aggiunte dopo»).
--
-- Non cancella e non rinomina niente: nessuna dichiarazione obbligatoria. Il
-- codice della versione precedente funziona sullo schema nuovo (le colonne
-- sono facoltative; un doppione dava già un errore del database). Per tornare
-- indietro:
--   ALTER TABLE teacher_access_credentials_data DROP INDEX uq_tac_access_username;
--   (le due colonne possono restare: il codice di prima non le legge)

SET NAMES utf8mb4;

-- 1. Nessun username ripetuto, prima dell'indice.
DELIMITER //
BEGIN NOT ATOMIC
    DECLARE ripetuti INT UNSIGNED DEFAULT 0;
    SELECT COUNT(*) INTO ripetuti
      FROM (SELECT access_username
              FROM teacher_access_credentials_data
             GROUP BY access_username
            HAVING COUNT(*) > 1) d;
    IF ripetuti > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '134 (ADR-044): ci sono username di credenziali ripetuti fra docenti; vanno rinominati prima dell''indice unico';
    END IF;
END//
DELIMITER ;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_access_credentials_data'
              AND INDEX_NAME = 'uq_tac_access_username');
SET @sql := IF(@c = 0,
    'ALTER TABLE teacher_access_credentials_data ADD UNIQUE KEY uq_tac_access_username (access_username)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Le parti dell'etichetta.
ALTER TABLE teacher_access_credentials_data
    ADD COLUMN IF NOT EXISTS materie VARCHAR(255) NULL AFTER label,
    ADD COLUMN IF NOT EXISTS aggiunta VARCHAR(12) NULL AFTER materie;

-- 3. Stessa definizione della 105: `tac.*` viene riespanso alla ricreazione.
CREATE OR REPLACE VIEW teacher_access_credentials AS
SELECT tac.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM teacher_access_credentials_data tac
LEFT JOIN curriculum_entries ci ON ci.id = tac.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = tac.classe_id    AND cc.kind='classi';
