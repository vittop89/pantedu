-- 118 — ADR-037, fase 2: le pubblicazioni le sceglie il docente.
--
-- Dalla fase 2 il modale del contenuto ha «Dove vale»: il docente aggiunge,
-- toglie e cambia di stato le pubblicazioni del suo contenuto, anche in
-- un'altra delle sue scuole. La principale resta quella della riga, e la
-- sposta chi sposta la riga («Sposta di classe», il modale, gli strumenti).
-- «Per più classi» (publish_scope 'classes' con content_target_classes) non
-- si offre più: più pubblicazioni fanno la stessa cosa, con una scuola e uno
-- stato ciascuna.
--
-- CHE COSA FA
--   1. Le pubblicazioni derivate dai bersagli (origine 'bersaglio') diventano
--      pubblicazioni del docente (origine 'docente'), con la scuola, la terna
--      e lo stato che hanno: chi le vedeva le vede ancora, e da adesso il
--      docente le governa una per una.
--   2. La procedura pub_ricalcola_contenuto non deriva più pubblicazioni dai
--      bersagli: ricalcola solo la principale. I bersagli restano in tabella
--      fino alla fase 4, e i trigger su di essi non fanno danni (ricalcolano
--      la principale).
--   3. pub_verifica_allineamento smette di contare i bersagli senza
--      pubblicazione, che non sono più un difetto.
--   4. Verifica finale, come nella 117.
--
-- CHE COSA NON FA
--   Non tocca le righe, non cancella i bersagli, non cambia lo stato di
--   nessuna pubblicazione. publish_scope 'classes' resta sui contenuti che lo
--   hanno: la loro principale resta in bozza (la regola della 117), perché la
--   terna della riga non era un posto dove li vedevano gli studenti.
--
-- SICUREZZA: il codice della fase 1 (che potrebbe servire mentre la
--   migrazione gira) legge le pubblicazioni senza guardare l'origine, quindi
--   vede le stesse righe prima e dopo. Se nella finestra del rilascio il
--   modale vecchio salva un contenuto «per più classi», i bersagli nuovi non
--   diventano pubblicazioni finché il docente non li aggiunge da «Dove vale»:
--   si perde una scrittura di pochi minuti, non una visibilità esistente.
--   La procedura cancella solo pubblicazioni con origine 'riga' o 'bersaglio'.
-- ROLLBACK: riapplicare le definizioni della 117 di pub_ricalcola_contenuto e
--   pub_verifica_allineamento, poi CALL pub_ricalcola_tutti(): la procedura
--   della 117 ricrea le pubblicazioni dei bersagli ancora in
--   content_target_classes. Le pubblicazioni con origine 'docente' restano
--   (la 117 non le tocca); quelle che corrispondono a un bersaglio vanno tolte
--   a mano (DELETE FROM content_publications WHERE origine = 'docente' AND
--   id IN (...)), elencandole prima per scuola e terna, per non avere lo
--   stesso posto due volte.

SET NAMES utf8mb4;

UPDATE content_publications SET origine = 'docente' WHERE origine = 'bersaglio';

DELIMITER //

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
    DECLARE v_msg VARCHAR(255);

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

    -- Dalla fase 2 nessuna pubblicazione deriva più dai bersagli.
    SELECT COUNT(*) INTO v_bersagli_derivati
      FROM content_publications p
     WHERE p.origine = 'bersaglio';

    IF v_senza_principale + v_principale_diversa + v_senza_scuola_con_pub + v_bersagli_derivati > 0 THEN
        SET v_msg = CONCAT('ADR-037: pubblicazioni non allineate alle righe: senza principale ', v_senza_principale,
                           ', principale diversa ', v_principale_diversa,
                           ', senza scuola ', v_senza_scuola_con_pub,
                           ', derivate dai bersagli ', v_bersagli_derivati);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

DELIMITER ;

CALL pub_verifica_allineamento();
