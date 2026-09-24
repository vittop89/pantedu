-- 117 — ADR-037, fase 1: le pubblicazioni.
--
-- Un documento, più pubblicazioni: la collocazione esce dalla riga
-- (wiki/decisions/ADR-037-pubblicazioni-e-copie.md). Questa migrazione crea
-- la tabella, la procedura che ricalcola le pubblicazioni di un contenuto, i
-- trigger che la richiamano, poi le calcola per tutti i contenuti esistenti e
-- verifica il risultato. Le verifiche (verifica_documents_data) entrano con
-- la fase 3: la colonna c'e' gia', procedura e trigger no.
--
-- CHE COSA NON FA
--   Non tocca le righe. indirizzo_id, classe_id, subject_id, visibility,
--   publish_scope e archive_visible restano l'interfaccia di SCRITTURA, e i
--   trigger ne derivano le pubblicazioni: una direzione sola, dalla riga (e
--   dai bersagli di content_target_classes) alla tabella. Le pubblicazioni
--   sono l'interfaccia di LETTURA. Non cambia la vista teacher_content.
--
-- PERCHE' TRIGGER E NON CODICE
--   Le colonne le scrivono quattordici file fra applicazione e strumenti, e
--   durante il rilascio il container vecchio serve ancora mentre le
--   migrazioni girano: le righe che scrive non saprebbero niente delle
--   pubblicazioni. Un trigger le tiene allineate chiunque scriva. E' la
--   stessa scelta della 038 (sigle e id), fatta per la stessa ragione.
--
-- LE REGOLE (fase 0: docs/analysis/pubblicazioni-fase0-2026-09-13.md)
--   - scuola: l'istituto della prima etichetta che ne ha uno (materia, poi
--     classe, poi indirizzo). Senza scuola, nessuna pubblicazione: il
--     contenuto resta fra i «da categorizzare» del proprietario.
--   - principale: la terna della riga. Stato: quello della riga, tranne con
--     publish_scope 'classes', dove e' 'draft': con quello scope la terna
--     della riga non arriva agli studenti, arrivano solo i bersagli.
--   - una pubblicazione per ogni bersaglio di content_target_classes che si
--     risolve nel vocabolario della stessa scuola: nello stato della riga se
--     lo scope e' 'classes', altrimenti 'draft' (fuori da 'classes' un
--     bersaglio oggi non conta).
--   - archive_visible: quello della riga, su tutte le derivate.
--   - origine: 'riga' per la principale, 'bersaglio' per i bersagli. La
--     fase 2 aggiunge le pubblicazioni scelte dal docente ('docente'), che
--     nessun ricalcolo tocca.
--
-- SICUREZZA: additiva. Il codice della versione precedente non legge ne'
--   scrive la tabella, e i trigger gliela tengono allineata anche mentre gira.
--   I trigger scattano DOPO la scrittura e non modificano la tabella su cui
--   scattano; la procedura cancella e riscrive solo le pubblicazioni del
--   contenuto che ricalcola. La verifica finale fa fallire la migrazione se
--   una sola riga non torna, invece di lasciarla registrata come eseguita.
-- ROLLBACK: DROP TRIGGER IF EXISTS trg_pub_tc_ai, trg_pub_tc_au,
--   trg_pub_ctc_ai, trg_pub_ctc_au, trg_pub_ctc_ad; DROP PROCEDURE IF EXISTS
--   pub_ricalcola_contenuto, pub_ricalcola_tutti, pub_verifica_allineamento;
--   DROP TABLE content_publications; DELETE FROM schema_migrations WHERE
--   filename = '117_pubblicazioni.sql'. Nessun dato delle righe va
--   ripristinato: la migrazione non ne scrive.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS content_publications (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Il documento: uno solo dei due (vincolo chk_pub_una_fonte). Due colonne
    -- invece di (source, content_id) perche' una colonna polimorfica non puo'
    -- avere una chiave esterna, e la cascata sul documento serve (Art. 17).
    teacher_content_id   BIGINT UNSIGNED NULL,
    verifica_document_id BIGINT UNSIGNED NULL,
    -- Dove: la scuola e la terna, id del vocabolario DI QUELLA scuola.
    institute_id         INT UNSIGNED NOT NULL,
    indirizzo_id         INT UNSIGNED NULL,
    classe_id            INT UNSIGNED NULL,
    subject_id           INT UNSIGNED NULL,
    -- La principale: una per documento. primary_of_* vale l'id del documento
    -- sulla principale e NULL sulle altre, e la chiave unica lo garantisce.
    is_primary           TINYINT(1) NOT NULL DEFAULT 0,
    -- Da dove viene: 'riga' (la principale, derivata dalle colonne della
    -- riga), 'bersaglio' (derivata da content_target_classes), 'docente'
    -- (scelta dal docente, fase 2). I trigger ricalcolano solo le prime due:
    -- una pubblicazione scelta dal docente non la tocca nessun ricalcolo.
    origine              ENUM('riga','bersaglio','docente') NOT NULL DEFAULT 'riga',
    primary_of_tc        BIGINT UNSIGNED NULL,
    primary_of_vd        BIGINT UNSIGNED NULL,
    -- Con che stato.
    visibility           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    archive_visible      TINYINT(1) NOT NULL DEFAULT 0,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pub_principale_tc (primary_of_tc),
    UNIQUE KEY uq_pub_principale_vd (primary_of_vd),
    KEY idx_pub_tc (teacher_content_id, is_primary),
    KEY idx_pub_vd (verifica_document_id, is_primary),
    KEY idx_pub_luogo (institute_id, classe_id, subject_id),
    CONSTRAINT chk_pub_una_fonte CHECK ((teacher_content_id IS NULL) <> (verifica_document_id IS NULL)),
    -- La principale e' sempre quella della riga, e solo quella.
    CONSTRAINT chk_pub_principale_e_riga CHECK ((is_primary = 1) = (origine = 'riga')),
    CONSTRAINT fk_pub_tc        FOREIGN KEY (teacher_content_id)   REFERENCES teacher_content_data(id)    ON DELETE CASCADE,
    CONSTRAINT fk_pub_vd        FOREIGN KEY (verifica_document_id) REFERENCES verifica_documents_data(id) ON DELETE CASCADE,
    CONSTRAINT fk_pub_istituto  FOREIGN KEY (institute_id)         REFERENCES institutes(id)              ON DELETE CASCADE,
    CONSTRAINT fk_pub_indirizzo FOREIGN KEY (indirizzo_id)         REFERENCES curriculum_entries(id)      ON DELETE SET NULL,
    CONSTRAINT fk_pub_classe    FOREIGN KEY (classe_id)            REFERENCES curriculum_entries(id)      ON DELETE SET NULL,
    CONSTRAINT fk_pub_materia   FOREIGN KEY (subject_id)           REFERENCES curriculum_entries(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

-- Ricalcola da capo le pubblicazioni di un contenuto dallo stato corrente
-- della riga e dei suoi bersagli. Idempotente: chiamata due volte, lo stesso
-- risultato.
DROP PROCEDURE IF EXISTS pub_ricalcola_contenuto//
CREATE PROCEDURE pub_ricalcola_contenuto(IN p_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
    DECLARE v_righe  INT DEFAULT 0;
    DECLARE v_ind    INT UNSIGNED DEFAULT NULL;
    DECLARE v_cls    INT UNSIGNED DEFAULT NULL;
    DECLARE v_mat    INT UNSIGNED DEFAULT NULL;
    DECLARE v_vis    VARCHAR(16) DEFAULT NULL;
    DECLARE v_scope  VARCHAR(16) DEFAULT NULL;
    DECLARE v_arch   TINYINT DEFAULT 0;
    DECLARE v_scuola INT UNSIGNED DEFAULT NULL;

    -- Solo le pubblicazioni derivate: quelle scelte dal docente restano.
    DELETE FROM content_publications
     WHERE teacher_content_id = p_id AND origine IN ('riga', 'bersaglio');

    SELECT COUNT(*), MAX(indirizzo_id), MAX(classe_id), MAX(subject_id),
           MAX(visibility), MAX(publish_scope), MAX(archive_visible)
      INTO v_righe, v_ind, v_cls, v_mat, v_vis, v_scope, v_arch
      FROM teacher_content_data
     WHERE id = p_id;

    IF v_righe > 0 THEN
        SET v_scuola = COALESCE(
            (SELECT institute_id FROM curriculum_entries WHERE id = v_mat),
            (SELECT institute_id FROM curriculum_entries WHERE id = v_cls),
            (SELECT institute_id FROM curriculum_entries WHERE id = v_ind)
        );
    END IF;

    IF v_scuola IS NOT NULL THEN
        INSERT INTO content_publications
            (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id,
             is_primary, origine, primary_of_tc, visibility, archive_visible)
        VALUES
            (p_id, v_scuola, v_ind, v_cls, v_mat,
             1, 'riga', p_id, IF(v_scope = 'classes', 'draft', v_vis), COALESCE(v_arch, 0));

        -- Join semplici, niente tabelle derivate: una derivata con sottoquery
        -- correlate, rieseguita dentro una procedura, al terzo giro MariaDB
        -- 10.11 non risolve piu' i nomi («Unknown column 't.indirizzo'»),
        -- misurato il 13/9/2026. Non serve altro: dalla 116 il vocabolario ha
        -- la chiave unica (kind, code, institute_id), quindi per ogni sigla
        -- c'e' al piu' una voce nella scuola, e i bersagli sono gia' unici
        -- per contenuto (uq_content_target).
        INSERT INTO content_publications
            (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id,
             is_primary, origine, primary_of_tc, visibility, archive_visible)
        SELECT p_id, v_scuola, ci.id, cc.id, v_mat,
               0, 'bersaglio', NULL, IF(v_scope = 'classes', v_vis, 'draft'), COALESCE(v_arch, 0)
          FROM content_target_classes t
          JOIN curriculum_entries ci
            ON ci.kind = 'indirizzi' AND ci.code = t.indirizzo AND ci.institute_id = v_scuola
          JOIN curriculum_entries cc
            ON cc.kind = 'classi' AND cc.code = t.classe AND cc.institute_id = v_scuola
         WHERE t.content_id = p_id;
    END IF;
END//

-- Ricalcola tutti i contenuti. Senza cursore: un aggregato restituisce sempre
-- una riga, quindi nessuna condizione NOT FOUND che una procedura chiamata
-- potrebbe far scattare a meta' giro.
DROP PROCEDURE IF EXISTS pub_ricalcola_tutti//
CREATE PROCEDURE pub_ricalcola_tutti()
MODIFIES SQL DATA
BEGIN
    DECLARE v_id BIGINT UNSIGNED DEFAULT 0;
    ciclo: LOOP
        SELECT MIN(id) INTO v_id FROM teacher_content_data WHERE id > v_id;
        IF v_id IS NULL THEN
            LEAVE ciclo;
        END IF;
        CALL pub_ricalcola_contenuto(v_id);
    END LOOP;
END//

-- La prova che le pubblicazioni dicono quello che dicono le righe. Fallisce
-- con un messaggio che il migratore non scambia per «gia' applicato».
DROP PROCEDURE IF EXISTS pub_verifica_allineamento//
CREATE PROCEDURE pub_verifica_allineamento()
READS SQL DATA
BEGIN
    DECLARE v_senza_principale INT DEFAULT 0;
    DECLARE v_principale_diversa INT DEFAULT 0;
    DECLARE v_senza_scuola_con_pub INT DEFAULT 0;
    DECLARE v_bersagli_mancanti INT DEFAULT 0;
    DECLARE v_msg VARCHAR(255);

    -- Righe con una scuola ma senza principale.
    SELECT COUNT(*) INTO v_senza_principale
      FROM teacher_content_data d
     WHERE COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = d.subject_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.indirizzo_id)
           ) IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM content_publications p WHERE p.primary_of_tc = d.id);

    -- Principali che non coincidono con la riga.
    SELECT COUNT(*) INTO v_principale_diversa
      FROM content_publications p
      JOIN teacher_content_data d ON d.id = p.primary_of_tc
     WHERE NOT (p.indirizzo_id <=> d.indirizzo_id
            AND p.classe_id    <=> d.classe_id
            AND p.subject_id   <=> d.subject_id
            AND p.archive_visible = d.archive_visible
            AND p.visibility = IF(d.publish_scope = 'classes', 'draft', d.visibility));

    -- Pubblicazioni derivate di righe che una scuola non l'hanno.
    SELECT COUNT(*) INTO v_senza_scuola_con_pub
      FROM content_publications p
      JOIN teacher_content_data d ON d.id = p.teacher_content_id
     WHERE p.origine IN ('riga', 'bersaglio')
       AND COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = d.subject_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.indirizzo_id)
           ) IS NULL;

    -- Bersagli risolvibili nella scuola del contenuto senza pubblicazione.
    SELECT COUNT(*) INTO v_bersagli_mancanti
      FROM content_target_classes t
      JOIN teacher_content_data d ON d.id = t.content_id
      JOIN content_publications pr ON pr.primary_of_tc = d.id
     WHERE EXISTS (SELECT 1 FROM curriculum_entries ci
                    WHERE ci.kind = 'indirizzi' AND ci.code = t.indirizzo AND ci.institute_id = pr.institute_id)
       AND EXISTS (SELECT 1 FROM curriculum_entries cc
                    WHERE cc.kind = 'classi' AND cc.code = t.classe AND cc.institute_id = pr.institute_id)
       AND NOT EXISTS (SELECT 1 FROM content_publications p
                        JOIN curriculum_entries pi ON pi.id = p.indirizzo_id
                        JOIN curriculum_entries pc ON pc.id = p.classe_id
                       WHERE p.teacher_content_id = d.id AND p.origine = 'bersaglio'
                         AND pi.code = t.indirizzo AND pc.code = t.classe);

    IF v_senza_principale + v_principale_diversa + v_senza_scuola_con_pub + v_bersagli_mancanti > 0 THEN
        SET v_msg = CONCAT('ADR-037: pubblicazioni non allineate alle righe: senza principale ', v_senza_principale,
                           ', principale diversa ', v_principale_diversa,
                           ', senza scuola ', v_senza_scuola_con_pub,
                           ', bersagli mancanti ', v_bersagli_mancanti);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

-- I trigger. Prima dei calcoli: una riga scritta dal codice vecchio mentre
-- la migrazione gira ha le sue pubblicazioni o dal trigger o dal ricalcolo.
DROP TRIGGER IF EXISTS trg_pub_tc_ai//
CREATE TRIGGER trg_pub_tc_ai AFTER INSERT ON teacher_content_data
FOR EACH ROW
BEGIN
    CALL pub_ricalcola_contenuto(NEW.id);
END//

DROP TRIGGER IF EXISTS trg_pub_tc_au//
CREATE TRIGGER trg_pub_tc_au AFTER UPDATE ON teacher_content_data
FOR EACH ROW
BEGIN
    IF NOT (OLD.indirizzo_id <=> NEW.indirizzo_id
        AND OLD.classe_id <=> NEW.classe_id
        AND OLD.subject_id <=> NEW.subject_id
        AND OLD.visibility <=> NEW.visibility
        AND OLD.publish_scope <=> NEW.publish_scope
        AND OLD.archive_visible <=> NEW.archive_visible) THEN
        CALL pub_ricalcola_contenuto(NEW.id);
    END IF;
END//

DROP TRIGGER IF EXISTS trg_pub_ctc_ai//
CREATE TRIGGER trg_pub_ctc_ai AFTER INSERT ON content_target_classes
FOR EACH ROW
BEGIN
    CALL pub_ricalcola_contenuto(NEW.content_id);
END//

DROP TRIGGER IF EXISTS trg_pub_ctc_au//
CREATE TRIGGER trg_pub_ctc_au AFTER UPDATE ON content_target_classes
FOR EACH ROW
BEGIN
    CALL pub_ricalcola_contenuto(NEW.content_id);
    IF OLD.content_id <> NEW.content_id THEN
        CALL pub_ricalcola_contenuto(OLD.content_id);
    END IF;
END//

DROP TRIGGER IF EXISTS trg_pub_ctc_ad//
CREATE TRIGGER trg_pub_ctc_ad AFTER DELETE ON content_target_classes
FOR EACH ROW
BEGIN
    CALL pub_ricalcola_contenuto(OLD.content_id);
END//

DELIMITER ;

CALL pub_ricalcola_tutti();
CALL pub_verifica_allineamento();
