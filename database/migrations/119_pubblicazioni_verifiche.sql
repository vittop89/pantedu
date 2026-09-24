-- 119 — ADR-037, fase 3: le verifiche nelle pubblicazioni.
--
-- Le verifiche (verifica_documents_data: il TEX e il PDF di ogni variante)
-- entrano nella stessa tabella dei contenuti, con la loro colonna
-- (verifica_document_id, già presente dalla 117). La principale viene dalle
-- etichette della riga (materia, indirizzo, classe), come per i contenuti.
--
-- LO STATO, E IL PUNTO APERTO DI ADR-032
--   Fino a qui una verifica arrivava agli studenti con account se era
--   CONDIVISA con i colleghi (shared_with_pool): condividere con i colleghi e
--   pubblicare agli studenti erano la stessa casella. ADR-032 lo teneva aperto
--   dal 4/9, e la DPIA dichiara le due cose distinte. Da qui agli studenti
--   conta lo stato della pubblicazione, e la condivisione resta fra colleghi.
--
--   Lo stato iniziale è «in bozza» per tutte, anche per le condivise: una
--   condivisione con i colleghi non è un consenso a pubblicare agli studenti,
--   e ereditarla lo trasformerebbe in uno. Misurato in produzione il 13/9/2026
--   (tools/curriculum/pubblicazioni_fase3.php): 25 varianti, nessuna
--   condivisa, e i due studenti con account non ne vedevano nessuna. Nessuno
--   perde niente. Da qui lo stato lo sceglie il docente da «Dove vale».
--
--   Una verifica non ha una colonna di visibilità nella riga: lo stato della
--   sua principale non si deriva da niente, e i trigger non lo toccano mai.
--
-- CHE COSA FA
--   1. pub_ricalcola_verifica: sposta la principale quando cambiano le
--      etichette (conservandone lo stato), la crea in bozza per una verifica
--      nuova, la toglie se la riga perde tutte le etichette.
--   2. I trigger trg_pub_vd_ai e trg_pub_vd_au.
--   3. Le principali delle verifiche esistenti, in bozza.
--   4. pub_verifica_allineamento controlla anche le verifiche.
--
-- SICUREZZA: additiva. Il codice della versione precedente legge le
--   verifiche dalla riga e da shared_with_pool, e continua a vedere le stesse
--   cose: la migrazione non tocca le righe. Una verifica creata mentre la
--   migrazione gira ha la principale dal trigger o dal calcolo.
-- ROLLBACK: DROP TRIGGER IF EXISTS trg_pub_vd_ai; DROP TRIGGER IF EXISTS
--   trg_pub_vd_au; DROP PROCEDURE IF EXISTS pub_ricalcola_verifica; DELETE
--   FROM content_publications WHERE verifica_document_id IS NOT NULL;
--   riapplicare pub_verifica_allineamento della 118. Gli stati scelti dai
--   docenti dopo il rilascio si perdono: vanno esportati prima (SELECT dalla
--   tabella).

SET NAMES utf8mb4;

DELIMITER //

DROP PROCEDURE IF EXISTS pub_ricalcola_verifica//
CREATE PROCEDURE pub_ricalcola_verifica(IN p_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
    DECLARE v_righe  INT DEFAULT 0;
    DECLARE v_ind    INT UNSIGNED DEFAULT NULL;
    DECLARE v_cls    INT UNSIGNED DEFAULT NULL;
    DECLARE v_mat    INT UNSIGNED DEFAULT NULL;
    DECLARE v_scuola INT UNSIGNED DEFAULT NULL;
    DECLARE v_c      INT DEFAULT 0;

    SELECT COUNT(*), MAX(indirizzo_id), MAX(classe_id), MAX(materia_id)
      INTO v_righe, v_ind, v_cls, v_mat
      FROM verifica_documents_data
     WHERE id = p_id;

    IF v_righe > 0 THEN
        SET v_scuola = COALESCE(
            (SELECT institute_id FROM curriculum_entries WHERE id = v_mat),
            (SELECT institute_id FROM curriculum_entries WHERE id = v_cls),
            (SELECT institute_id FROM curriculum_entries WHERE id = v_ind)
        );
    END IF;

    IF v_scuola IS NULL THEN
        DELETE FROM content_publications WHERE primary_of_vd = p_id;
    ELSE
        SELECT COUNT(*) INTO v_c FROM content_publications WHERE primary_of_vd = p_id;
        IF v_c > 0 THEN
            -- Si sposta il posto, lo stato resta quello scelto dal docente.
            UPDATE content_publications
               SET institute_id = v_scuola, indirizzo_id = v_ind, classe_id = v_cls, subject_id = v_mat
             WHERE primary_of_vd = p_id;
        ELSE
            INSERT INTO content_publications
                (verifica_document_id, institute_id, indirizzo_id, classe_id, subject_id,
                 is_primary, origine, primary_of_vd, visibility, archive_visible)
            VALUES
                (p_id, v_scuola, v_ind, v_cls, v_mat, 1, 'riga', p_id, 'draft', 0);
        END IF;
    END IF;
END//

DROP PROCEDURE IF EXISTS pub_verifica_allineamento//
CREATE PROCEDURE pub_verifica_allineamento()
READS SQL DATA
BEGIN
    DECLARE v_senza_principale INT DEFAULT 0;
    DECLARE v_principale_diversa INT DEFAULT 0;
    DECLARE v_senza_scuola_con_pub INT DEFAULT 0;
    DECLARE v_bersagli_derivati INT DEFAULT 0;
    DECLARE v_verifiche_senza INT DEFAULT 0;
    DECLARE v_verifiche_diverse INT DEFAULT 0;
    DECLARE v_verifiche_senza_scuola INT DEFAULT 0;
    DECLARE v_msg VARCHAR(512);

    SELECT COUNT(*) INTO v_senza_principale
      FROM teacher_content_data d
     WHERE COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = d.subject_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.indirizzo_id)
           ) IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM content_publications p WHERE p.primary_of_tc = d.id);

    SELECT COUNT(*) INTO v_principale_diversa
      FROM content_publications p
      JOIN teacher_content_data d ON d.id = p.primary_of_tc
     WHERE NOT (p.indirizzo_id <=> d.indirizzo_id
            AND p.classe_id    <=> d.classe_id
            AND p.subject_id   <=> d.subject_id
            AND p.archive_visible = d.archive_visible
            AND p.visibility = IF(d.publish_scope = 'classes', 'draft', d.visibility));

    SELECT COUNT(*) INTO v_senza_scuola_con_pub
      FROM content_publications p
      JOIN teacher_content_data d ON d.id = p.teacher_content_id
     WHERE p.origine = 'riga'
       AND COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = d.subject_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = d.indirizzo_id)
           ) IS NULL;

    SELECT COUNT(*) INTO v_bersagli_derivati
      FROM content_publications p
     WHERE p.origine = 'bersaglio';

    -- Le verifiche: una principale per ogni verifica con una scuola, nel posto
    -- della riga (lo stato è del docente e non si confronta).
    SELECT COUNT(*) INTO v_verifiche_senza
      FROM verifica_documents_data v
     WHERE COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = v.materia_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = v.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = v.indirizzo_id)
           ) IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM content_publications p WHERE p.primary_of_vd = v.id);

    SELECT COUNT(*) INTO v_verifiche_diverse
      FROM content_publications p
      JOIN verifica_documents_data v ON v.id = p.primary_of_vd
     WHERE NOT (p.indirizzo_id <=> v.indirizzo_id
            AND p.classe_id    <=> v.classe_id
            AND p.subject_id   <=> v.materia_id);

    -- Una principale di verifica la cui riga non ha più una scuola (le chiavi
    -- esterne a SET NULL sulle voci non fanno scattare i trigger).
    SELECT COUNT(*) INTO v_verifiche_senza_scuola
      FROM content_publications p
      JOIN verifica_documents_data v ON v.id = p.primary_of_vd
     WHERE COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = v.materia_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = v.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = v.indirizzo_id)
           ) IS NULL;

    IF v_senza_principale + v_principale_diversa + v_senza_scuola_con_pub + v_bersagli_derivati
       + v_verifiche_senza + v_verifiche_diverse + v_verifiche_senza_scuola > 0 THEN
        SET v_msg = CONCAT('ADR-037: pubblicazioni non allineate alle righe: senza principale ', v_senza_principale,
                           ', principale diversa ', v_principale_diversa,
                           ', senza scuola ', v_senza_scuola_con_pub,
                           ', derivate dai bersagli ', v_bersagli_derivati,
                           ', verifiche senza principale ', v_verifiche_senza,
                           ', verifiche in un altro posto ', v_verifiche_diverse,
                           ', verifiche senza scuola ', v_verifiche_senza_scuola);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

DROP TRIGGER IF EXISTS trg_pub_vd_ai//
CREATE TRIGGER trg_pub_vd_ai AFTER INSERT ON verifica_documents_data
FOR EACH ROW
BEGIN
    CALL pub_ricalcola_verifica(NEW.id);
END//

DROP TRIGGER IF EXISTS trg_pub_vd_au//
CREATE TRIGGER trg_pub_vd_au AFTER UPDATE ON verifica_documents_data
FOR EACH ROW
BEGIN
    IF NOT (OLD.indirizzo_id <=> NEW.indirizzo_id
        AND OLD.classe_id <=> NEW.classe_id
        AND OLD.materia_id <=> NEW.materia_id) THEN
        CALL pub_ricalcola_verifica(NEW.id);
    END IF;
END//

DELIMITER ;

-- Le principali delle verifiche esistenti, tutte in bozza (vedi sopra).
INSERT INTO content_publications
    (verifica_document_id, institute_id, indirizzo_id, classe_id, subject_id,
     is_primary, origine, primary_of_vd, visibility, archive_visible)
SELECT v.id,
       COALESCE(sm.institute_id, sc.institute_id, si.institute_id),
       v.indirizzo_id, v.classe_id, v.materia_id,
       1, 'riga', v.id,
       'draft',
       0
  FROM verifica_documents_data v
  LEFT JOIN curriculum_entries sm ON sm.id = v.materia_id
  LEFT JOIN curriculum_entries sc ON sc.id = v.classe_id
  LEFT JOIN curriculum_entries si ON si.id = v.indirizzo_id
 WHERE COALESCE(sm.institute_id, sc.institute_id, si.institute_id) IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM content_publications p WHERE p.primary_of_vd = v.id);

CALL pub_verifica_allineamento();
