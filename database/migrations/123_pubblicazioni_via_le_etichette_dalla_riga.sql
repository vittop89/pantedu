-- 123 — ADR-037, fase 4c, terzo rilascio: via le etichette dalla riga.
--
-- Il posto di un documento è la sua pubblicazione principale: la scrive
-- App\Support\PostoPrincipale (4c-1, 121), le viste teacher_content e
-- verifica_documents la leggono da lì, e dalla 4c-2 (122) nessuno scrive più
-- le colonne della riga. Qui escono:
--   - teacher_content_data: indirizzo_id, classe_id, subject_id, con le loro
--     chiavi esterne (fk_teachcont_*);
--   - verifica_documents_data: indirizzo_id e classe_id, con le chiavi esterne
--     (fk_verifdoc_indirizzo, fk_verifdoc_classe). La materia delle verifiche
--     resta: è la chiave dell'indice unico uq_verif_doc_title_fk (ADR-037, 4c-2).
--
-- Prima delle colonne si ricrea la guardia delle voci (trg_curriculum_no_orphan)
-- senza di loro: altrimenti ogni cancellazione di una voce di curriculum
-- cercherebbe colonne che non esistono e fallirebbe. Stesso elenco di
-- tools/curriculum/apply_no_orphan_guard.php, aggiornato nello stesso rilascio.
--
-- SICUREZZA: il codice del rilascio precedente (ADR-037, fase 4c-2) non scrive né legge queste colonne: lo misura tests/Unit/SenzaEtichetteNellaRigaTest.php, che fallisce se un file dell'applicazione, delle rotte, delle viste o degli strumenti (tranne quelli d'archivio) le usa, anche con il nome della tabella in una variabile. Le viste (121) e pub_verifica_allineamento (122) non le nominano. Misurato togliendole da una copia del database di prova: la suite PHPUnit del rilascio precedente passa, tranne le prove che le leggono per dire che sono vuote o che le scrivono a mano, adattate qui. Da unire solo quando la 4c-2 è in produzione.
-- ROLLBACK: il codice della 4c-2 non le usa, quindi basta il container di prima. Per riaverle: ALTER TABLE teacher_content_data ADD COLUMN subject_id INT UNSIGNED NULL, ADD COLUMN indirizzo_id INT UNSIGNED NULL, ADD COLUMN classe_id INT UNSIGNED NULL, con le chiavi esterne verso curriculum_entries ON DELETE SET NULL (037); lo stesso per indirizzo_id e classe_id di verifica_documents_data; i valori si rileggono dalle principali (UPDATE teacher_content_data d JOIN content_publications p ON p.primary_of_tc = d.id SET d.subject_id = p.subject_id, d.indirizzo_id = p.indirizzo_id, d.classe_id = p.classe_id; per le verifiche con primary_of_vd). La guardia di prima si rimette con `php tools/curriculum/apply_no_orphan_guard.php --apply` del rilascio precedente.

SET NAMES utf8mb4;

-- ─────── La guardia delle voci, senza le colonne che escono ───────
DROP TRIGGER IF EXISTS trg_curriculum_no_orphan;

DELIMITER //

CREATE TRIGGER `trg_curriculum_no_orphan` BEFORE DELETE ON `curriculum_entries`
FOR EACH ROW
BEGIN
    DECLARE usi INT DEFAULT 0;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `exercises_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `materia_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `verifica_documents_data` WHERE `materia_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `risdoc_compilations_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `content_publications` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `subject_id` = OLD.id; END IF;
    IF usi > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voce di curriculum ancora usata: sposta i riferimenti prima di cancellarla (tools/institutes/prune_cloned_curriculum.php)';
    END IF;
END//

DELIMITER ;

-- ─────── Le colonne, con le loro chiavi esterne ───────
ALTER TABLE teacher_content_data
    DROP FOREIGN KEY IF EXISTS fk_teachcont_classe,
    DROP FOREIGN KEY IF EXISTS fk_teachcont_indirizzo,
    DROP FOREIGN KEY IF EXISTS fk_teachcont_subject;

ALTER TABLE teacher_content_data
    DROP COLUMN IF EXISTS classe_id,
    DROP COLUMN IF EXISTS indirizzo_id,
    DROP COLUMN IF EXISTS subject_id;

ALTER TABLE verifica_documents_data
    DROP FOREIGN KEY IF EXISTS fk_verifdoc_classe,
    DROP FOREIGN KEY IF EXISTS fk_verifdoc_indirizzo;

ALTER TABLE verifica_documents_data
    DROP COLUMN IF EXISTS classe_id,
    DROP COLUMN IF EXISTS indirizzo_id;

CALL pub_verifica_allineamento();
