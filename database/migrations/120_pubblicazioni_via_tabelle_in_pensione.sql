-- 120 — ADR-037, fase 4b: via le tabelle in pensione.
--
-- Escono:
--   - le copie cifrate per classe (published_content_data, vista
--     published_content) e le loro chiavi (classe_keys_data, vista
--     classe_keys): mai usate, zero righe in produzione (fase 0, 13/9/2026);
--   - i bersagli di «per più classi» (content_target_classes) con i loro tre
--     trigger: senza effetto dalla 118, che li ha convertiti in pubblicazioni
--     del docente.
--
-- Con le due viste escono anche due viste con DEFINER, la trappola del
-- ripristino (le viste girano con l'utente che le ha create).
--
-- SICUREZZA: il codice del rilascio precedente (ADR-037, fase 4a) non legge né scrive le tre tabelle né le due viste: lo misura tests/Unit/TabelleInPensioneTest.php, che fallisce se un file dell'applicazione, delle rotte o delle viste le usa in SQL. La migrazione si ferma da sola, prima di togliere qualunque cosa, se published_content_data o classe_keys_data hanno righe. Le righe di content_target_classes sono diventate pubblicazioni del docente con la 118 e da allora non decidono chi vede cosa. Da unire solo quando la fase 4a è in produzione.
-- ROLLBACK: il codice della fase 4a funziona anche senza le tabelle. Per riaverle, dal dump che il deploy salva prima di migrare: le definizioni (SHOW CREATE TABLE, SHOW CREATE VIEW) di published_content_data, classe_keys_data, content_target_classes, published_content e classe_keys, e le righe di content_target_classes; poi i trigger trg_pub_ctc_ai, trg_pub_ctc_au e trg_pub_ctc_ad come nella 117.

SET NAMES utf8mb4;

DELIMITER //

DROP PROCEDURE IF EXISTS pub_120_controlla_vuote//
CREATE PROCEDURE pub_120_controlla_vuote()
READS SQL DATA
BEGIN
    DECLARE v_copie INT DEFAULT 0;
    DECLARE v_chiavi INT DEFAULT 0;
    DECLARE v_msg VARCHAR(255);

    IF (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'published_content_data') > 0 THEN
        SELECT COUNT(*) INTO v_copie FROM published_content_data;
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classe_keys_data') > 0 THEN
        SELECT COUNT(*) INTO v_chiavi FROM classe_keys_data;
    END IF;

    IF v_copie + v_chiavi > 0 THEN
        SET v_msg = CONCAT('ADR-037 fase 4b: le tabelle cifrate da togliere non sono vuote: copie ', v_copie,
                           ', chiavi ', v_chiavi, '. Non si toglie niente: misurare e decidere prima.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

DELIMITER ;

CALL pub_120_controlla_vuote();
DROP PROCEDURE IF EXISTS pub_120_controlla_vuote;

-- I trigger dei bersagli, poi le viste, poi le tabelle (le copie hanno una
-- chiave esterna sulle chiavi).
DROP TRIGGER IF EXISTS trg_pub_ctc_ai;
DROP TRIGGER IF EXISTS trg_pub_ctc_au;
DROP TRIGGER IF EXISTS trg_pub_ctc_ad;
DROP VIEW IF EXISTS published_content;
DROP VIEW IF EXISTS classe_keys;
DROP TABLE IF EXISTS published_content_data;
DROP TABLE IF EXISTS classe_keys_data;
DROP TABLE IF EXISTS content_target_classes;

-- Le pubblicazioni non dipendevano da niente di tutto questo: la verifica di
-- allineamento lo conferma.
CALL pub_verifica_allineamento();
