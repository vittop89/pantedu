-- 122 — ADR-037, fase 4c, secondo rilascio: via i trigger delle etichette.
--
-- Dalla 4c-2 l'applicazione non scrive più indirizzo_id, classe_id e
-- subject_id nelle righe dei contenuti, né indirizzo_id e classe_id in quelle
-- delle verifiche: il posto di un documento è la sua pubblicazione principale,
-- e la scrive
-- App\Support\PostoPrincipale. I trigger della 117 e della 119 la ricavavano
-- da quelle colonne: se restassero, alla prima modifica di una riga la
-- ricalcolerebbero dalle colonne ferme e la riporterebbero dov'era, o la
-- toglierebbero a un documento creato dopo questo rilascio (colonne vuote).
--
-- CHE COSA FA
--   1. Ricrea pub_verifica_allineamento senza le colonne della riga, e la
--      esegue prima di togliere qualunque cosa: se una pubblicazione non torna,
--      si ferma con i trigger ancora al loro posto.
--   2. Toglie i quattro trigger (trg_pub_tc_ai, trg_pub_tc_au, trg_pub_vd_ai,
--      trg_pub_vd_au) e le procedure di ricalcolo che chiamavano
--      (pub_ricalcola_contenuto, pub_ricalcola_verifica, pub_ricalcola_tutti),
--      e rifà la verifica. La verifica nuova non guarda le colonne della riga, che da qui
--      non dicono più niente. Controlla ciò che resta vero: lo stato di ogni
--      principale di contenuto è quello della riga (bozza se «per più classi»),
--      nessuna pubblicazione viene dai bersagli, ogni pubblicazione sta nella
--      scuola delle sue voci (dalla materia, poi dalla classe, poi
--      dall'indirizzo), e ogni verifica con una materia ha la principale con la
--      stessa materia. La chiamano questa migrazione e tools/ops/diagnostica.php.
--
-- LA MATERIA DELLE VERIFICHE RESTA NELLA RIGA
--   verifica_documents_data.materia_id è la chiave dell'indice unico
--   uq_verif_doc_title_fk (docente, materia, titolo, variante, versione), che
--   ferma due salvataggi simultanei della stessa verifica: con la colonna
--   vuota un indice unico non ferma più niente (i NULL sono tutti diversi).
--   La materia è parte di che cos'è la verifica, non solo di dove sta; toglierla
--   vuol dire spostare prima quella protezione, ed è un passo a parte.
--
-- Misurato prima di scriverla, in produzione (sola lettura, 14/9/2026, con la
-- 120 applicata): 664 pubblicazioni, zero principali con uno stato diverso
-- dalla riga, zero dai bersagli, zero in una scuola diversa da quella delle voci;
-- 25 verifiche, zero con una materia diversa dalla principale, zero con la
-- materia e senza principale.
--
-- SICUREZZA: il codice del rilascio precedente (ADR-037, fase 4c-1) scrive la principale da sé (App\Support\PostoPrincipale) per ogni scrittura di etichette o di stato, e le viste leggono le sigle dalla principale: misurato con la suite PHPUnit su un database senza questi trigger, 23 prove rosse su 1380 e tutte in prove che scrivevano le colonne a mano o provavano i trigger stessi. Nessun codice dell'applicazione chiama le procedure che escono. Da unire solo quando la 4c-1 è in produzione.
-- ROLLBACK: il codice della 4c-1 funziona senza trigger (vedi SICUREZZA): per tornare indietro basta il container di prima, senza rimettere i trigger. Rimetterli (le definizioni della 117, 118 e 119) dopo che questo rilascio ha scritto righe senza etichette sposterebbe o toglierebbe le loro principali alla prima modifica della riga. La pub_verifica_allineamento di prima è nella 119.

SET NAMES utf8mb4;

-- Prima la verifica nuova, ed eseguita: se una sola pubblicazione non torna la
-- migrazione si ferma qui, con i trigger ancora al loro posto.
DROP PROCEDURE IF EXISTS pub_verifica_allineamento;

DELIMITER //

CREATE PROCEDURE pub_verifica_allineamento()
READS SQL DATA
BEGIN
    DECLARE v_stato INT DEFAULT 0;
    DECLARE v_bersagli INT DEFAULT 0;
    DECLARE v_scuola INT DEFAULT 0;
    DECLARE v_materia INT DEFAULT 0;
    DECLARE v_senza INT DEFAULT 0;
    DECLARE v_msg VARCHAR(512);

    -- La principale di un contenuto ha lo stato della riga, che resta
    -- l'interruttore del documento (ADR-037, decisione 5).
    SELECT COUNT(*) INTO v_stato
      FROM content_publications p
      JOIN teacher_content_data d ON d.id = p.primary_of_tc
     WHERE NOT (p.archive_visible = d.archive_visible
            AND p.visibility = IF(d.publish_scope = 'classes', 'draft', d.visibility));

    SELECT COUNT(*) INTO v_bersagli
      FROM content_publications
     WHERE origine = 'bersaglio';

    -- Una pubblicazione nella scuola sbagliata la vedrebbero gli studenti di
    -- un'altra scuola, con le sigle di questa.
    SELECT COUNT(*) INTO v_scuola
      FROM content_publications p
     WHERE (p.subject_id IS NOT NULL OR p.classe_id IS NOT NULL OR p.indirizzo_id IS NOT NULL)
       AND NOT (p.institute_id <=> COALESCE(
               (SELECT institute_id FROM curriculum_entries WHERE id = p.subject_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = p.classe_id),
               (SELECT institute_id FROM curriculum_entries WHERE id = p.indirizzo_id)));

    -- Una verifica tiene la materia nella riga (è la chiave del suo indice
    -- unico): la sua principale deve dire la stessa, e deve esserci.
    SELECT COUNT(*) INTO v_materia
      FROM content_publications p
      JOIN verifica_documents_data v ON v.id = p.primary_of_vd
     WHERE NOT (p.subject_id <=> v.materia_id);

    SELECT COUNT(*) INTO v_senza
      FROM verifica_documents_data v
     WHERE v.materia_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM content_publications p WHERE p.primary_of_vd = v.id);

    IF v_stato + v_bersagli + v_scuola + v_materia + v_senza > 0 THEN
        SET v_msg = CONCAT('ADR-037: pubblicazioni non allineate: principali con uno stato diverso dalla riga ', v_stato,
                           ', derivate dai bersagli ', v_bersagli,
                           ', in una scuola diversa da quella delle loro voci ', v_scuola,
                           ', verifiche con una materia diversa dalla principale ', v_materia,
                           ', verifiche con la materia e senza principale ', v_senza);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

DELIMITER ;

CALL pub_verifica_allineamento();

-- Poi i trigger e le procedure di ricalcolo che chiamavano.
DROP TRIGGER IF EXISTS trg_pub_tc_ai;
DROP TRIGGER IF EXISTS trg_pub_tc_au;
DROP TRIGGER IF EXISTS trg_pub_vd_ai;
DROP TRIGGER IF EXISTS trg_pub_vd_au;

DROP PROCEDURE IF EXISTS pub_ricalcola_contenuto;
DROP PROCEDURE IF EXISTS pub_ricalcola_verifica;
DROP PROCEDURE IF EXISTS pub_ricalcola_tutti;

CALL pub_verifica_allineamento();
