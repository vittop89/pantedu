-- 121 — ADR-037, fase 4c, primo rilascio: le sigle dalla principale.
--
-- La fase 4c toglie dalle righe le tre etichette (indirizzo_id, classe_id,
-- subject_id; materia_id per le verifiche). La visibilità della riga resta:
-- è l'interruttore del documento (opzione 2, scelta dall'utente il 14/9/2026).
-- Tre rilasci, ognuno in produzione prima del successivo:
--
--   1. (questa) l'applicazione scrive anche la principale (App\Support\
--      PostoPrincipale), in modo idempotente con i trigger della 117 e della
--      119, e le viste ricavano indirizzo, classe e materia dalla principale;
--   2. l'applicazione smette di scrivere le colonne, e una migrazione toglie i
--      trigger;
--   3. una migrazione toglie le colonne.
--
-- CHE COSA FA
--   1. Controlla, prima di cambiare qualunque cosa, che le etichette di ogni
--      riga coincidano con quelle della sua principale (una riga senza
--      etichette non ha principale e resta senza sigle). Se una sola non
--      coincide si ferma: le viste nuove mostrerebbero un'altra classe.
--   2. Ricrea le viste teacher_content (definizione della 104) e
--      verifica_documents (della 058) con le tre etichette e le tre sigle dalla
--      principale. Stesse colonne, stesso ordine, stesso DEFINER delle
--      precedenti: il ripristino le tratta come prima (docs/ops).
--   3. Ricrea la guardia trg_curriculum_no_orphan con una tabella in più,
--      content_publications: quando le colonne della riga non saranno più
--      scritte, una voce usata solo da una principale resterebbe cancellabile,
--      e la chiave esterna a SET NULL toglierebbe il posto senza avvisare.
--      Stesso corpo di tools/curriculum/apply_no_orphan_guard.php, aggiornato
--      nello stesso rilascio.
--   4. Ricontrolla attraverso le viste, e chiama pub_verifica_allineamento().
--
-- Misurato prima di scriverla, in produzione (sola lettura, 14/9/2026): 396
-- contenuti, 390 con etichette, 0 con etichette ma senza scuola; 25 verifiche,
-- 23 con etichette, 0 senza scuola; curriculum_entries.institute_id è NOT
-- NULL, quindi un'etichetta trova sempre la sua scuola e la principale c'è.
--
-- SICUREZZA: le viste danno le stesse righe e le stesse sigle di prima, e la migrazione lo controlla prima di cambiarle (etichette della riga uguali a quelle della principale, riga per riga, altrimenti si ferma senza toccare niente) e dopo, attraverso le viste; il codice del rilascio precedente legge le viste e scrive le colonne, e i trigger della 117 e della 119 tengono la principale uguale alle colonne. La guardia delle voci si ricrea con una tabella sorvegliata in più.
-- ROLLBACK: ricreare teacher_content con la definizione della 104 e verifica_documents con quella della 058 (sigle dalle colonne della riga, CREATE OR REPLACE); la guardia si rimette con `php tools/curriculum/apply_no_orphan_guard.php --apply` del rilascio precedente.

SET NAMES utf8mb4;

DELIMITER //

DROP PROCEDURE IF EXISTS pub_121_controlla//
CREATE PROCEDURE pub_121_controlla(IN p_quando VARCHAR(16))
READS SQL DATA
BEGIN
    DECLARE v_tc INT DEFAULT 0;
    DECLARE v_vd INT DEFAULT 0;
    DECLARE v_righe INT DEFAULT 0;
    DECLARE v_msg VARCHAR(512);

    IF p_quando = 'prima' THEN
        SELECT COUNT(*) INTO v_tc
          FROM teacher_content_data d
          LEFT JOIN content_publications p ON p.primary_of_tc = d.id
         WHERE NOT (p.indirizzo_id <=> d.indirizzo_id
                AND p.classe_id    <=> d.classe_id
                AND p.subject_id   <=> d.subject_id);
        SELECT COUNT(*) INTO v_vd
          FROM verifica_documents_data d
          LEFT JOIN content_publications p ON p.primary_of_vd = d.id
         WHERE NOT (p.indirizzo_id <=> d.indirizzo_id
                AND p.classe_id    <=> d.classe_id
                AND p.subject_id   <=> d.materia_id);
    ELSE
        SELECT COUNT(*) INTO v_tc
          FROM teacher_content_data d
          JOIN teacher_content v ON v.id = d.id
         WHERE NOT (v.indirizzo_id <=> d.indirizzo_id
                AND v.classe_id    <=> d.classe_id
                AND v.subject_id   <=> d.subject_id);
        SELECT COUNT(*) INTO v_vd
          FROM verifica_documents_data d
          JOIN verifica_documents v ON v.id = d.id
         WHERE NOT (v.indirizzo_id <=> d.indirizzo_id
                AND v.classe_id    <=> d.classe_id
                AND v.materia_id   <=> d.materia_id);
        SELECT ABS((SELECT COUNT(*) FROM teacher_content) - (SELECT COUNT(*) FROM teacher_content_data))
             + ABS((SELECT COUNT(*) FROM verifica_documents) - (SELECT COUNT(*) FROM verifica_documents_data))
          INTO v_righe;
    END IF;

    IF v_tc + v_vd + v_righe > 0 THEN
        SET v_msg = CONCAT('ADR-037 fase 4c (', p_quando, '): sigle diverse fra righe e principali: contenuti ', v_tc,
                           ', verifiche ', v_vd, ', righe in più o in meno nelle viste ', v_righe,
                           '. Allineare prima (pub_verifica_allineamento) e rieseguire.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
END//

DELIMITER ;

CALL pub_121_controlla('prima');

-- ─────── Le viste: indirizzo, classe e materia dalla principale ───────
CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `teacher_content` AS
  select
    `tc`.`id` AS `id`,`tc`.`teacher_id` AS `teacher_id`,
    `tc`.`content_subtype` AS `content_type`,
    `tc`.`content_subtype` AS `content_subtype`,
    `tc`.`content_format` AS `content_format`,
    `tc`.`section_id` AS `section_id`,`p`.`subject_id` AS `subject_id`,
    `p`.`indirizzo_id` AS `indirizzo_id`,`p`.`classe_id` AS `classe_id`,
    `tc`.`topic` AS `topic`,`tc`.`title` AS `title`,`tc`.`body_html` AS `body_html`,
    `tc`.`body_pt_ct` AS `body_pt_ct`,`tc`.`body_pt_iv` AS `body_pt_iv`,
    `tc`.`body_pt_tag` AS `body_pt_tag`,`tc`.`body_pt_kv` AS `body_pt_kv`,
    `tc`.`body_html_ct` AS `body_html_ct`,`tc`.`body_html_iv` AS `body_html_iv`,
    `tc`.`body_html_tag` AS `body_html_tag`,`tc`.`body_html_kv` AS `body_html_kv`,
    `tc`.`metadata_ct` AS `metadata_ct`,`tc`.`metadata_iv` AS `metadata_iv`,
    `tc`.`metadata_tag` AS `metadata_tag`,`tc`.`metadata_kv` AS `metadata_kv`,
    `tc`.`metadata_json` AS `metadata_json`,`tc`.`map_blob_path` AS `map_blob_path`,
    `tc`.`map_mime` AS `map_mime`,`tc`.`map_size` AS `map_size`,
    `tc`.`map_drive_id` AS `map_drive_id`,`tc`.`map_origin` AS `map_origin`,
    `tc`.`map_is_public` AS `map_is_public`,`tc`.`map_version` AS `map_version`,
    `tc`.`visibility` AS `visibility`,`tc`.`publish_scope` AS `publish_scope`,
    `tc`.`archive_visible` AS `archive_visible`,
    `tc`.`shared_with_pool` AS `shared_with_pool`,`tc`.`source_content_id` AS `source_content_id`,
    `tc`.`created_at` AS `created_at`,`tc`.`updated_at` AS `updated_at`,
    `tc`.`source_type` AS `source_type`,
    `ci`.`code` AS `indirizzo`,`cc`.`code` AS `classe`,`cs`.`code` AS `subject_code`
  from ((((`teacher_content_data` `tc`
    left join `content_publications` `p` on(`p`.`primary_of_tc` = `tc`.`id`))
    left join `curriculum_entries` `ci` on(`ci`.`id` = `p`.`indirizzo_id` and `ci`.`kind` = 'indirizzi'))
    left join `curriculum_entries` `cc` on(`cc`.`id` = `p`.`classe_id` and `cc`.`kind` = 'classi'))
    left join `curriculum_entries` `cs` on(`cs`.`id` = `p`.`subject_id` and `cs`.`kind` = 'materie'));

CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `verifica_documents` AS
  select
    `vd`.`id` AS `id`,`vd`.`teacher_id` AS `teacher_id`,
    `p`.`subject_id` AS `materia_id`,`p`.`indirizzo_id` AS `indirizzo_id`,`p`.`classe_id` AS `classe_id`,
    `vd`.`title` AS `title`,`vd`.`fm_db_section` AS `fm_db_section`,`vd`.`batch_id` AS `batch_id`,
    `vd`.`variant` AS `variant`,`vd`.`shared_with_pool` AS `shared_with_pool`,
    `vd`.`source_type` AS `source_type`,`vd`.`version_label` AS `version_label`,
    `vd`.`exercise_ids` AS `exercise_ids`,`vd`.`selection_json` AS `selection_json`,
    `vd`.`tex_blob_path` AS `tex_blob_path`,`vd`.`tex_blob_kv` AS `tex_blob_kv`,
    `vd`.`tex_size` AS `tex_size`,`vd`.`tex_files` AS `tex_files`,`vd`.`tex_sha256` AS `tex_sha256`,
    `vd`.`pdf_blob_path` AS `pdf_blob_path`,`vd`.`pdf_blob_kv` AS `pdf_blob_kv`,
    `vd`.`pdf_size` AS `pdf_size`,`vd`.`pdf_filename` AS `pdf_filename`,
    `vd`.`pdf_uploaded_at` AS `pdf_uploaded_at`,`vd`.`drive_file_id` AS `drive_file_id`,
    `vd`.`drive_synced_at` AS `drive_synced_at`,`vd`.`created_at` AS `created_at`,
    `vd`.`updated_at` AS `updated_at`,
    `ci`.`code` AS `indirizzo`,`cc`.`code` AS `classe`,`cm`.`code` AS `materia`
  from ((((`verifica_documents_data` `vd`
    left join `content_publications` `p` on(`p`.`primary_of_vd` = `vd`.`id`))
    left join `curriculum_entries` `ci` on(`ci`.`id` = `p`.`indirizzo_id` and `ci`.`kind` = 'indirizzi'))
    left join `curriculum_entries` `cc` on(`cc`.`id` = `p`.`classe_id` and `cc`.`kind` = 'classi'))
    left join `curriculum_entries` `cm` on(`cm`.`id` = `p`.`subject_id` and `cm`.`kind` = 'materie'));

-- ─────── La guardia delle voci: anche le pubblicazioni ───────
DROP TRIGGER IF EXISTS trg_curriculum_no_orphan;

DELIMITER //

CREATE TRIGGER `trg_curriculum_no_orphan` BEFORE DELETE ON `curriculum_entries`
FOR EACH ROW
BEGIN
    DECLARE usi INT DEFAULT 0;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `teacher_content_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `subject_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `exercises_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `materia_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `verifica_documents_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `materia_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `risdoc_compilations_data` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id; END IF;
    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `content_publications` WHERE `indirizzo_id` = OLD.id OR `classe_id` = OLD.id OR `subject_id` = OLD.id; END IF;
    IF usi > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voce di curriculum ancora usata: sposta i riferimenti prima di cancellarla (tools/institutes/prune_cloned_curriculum.php)';
    END IF;
END//

DELIMITER ;

CALL pub_121_controlla('dopo');
DROP PROCEDURE IF EXISTS pub_121_controlla;

CALL pub_verifica_allineamento();
