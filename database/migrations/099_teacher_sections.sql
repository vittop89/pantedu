-- 099_teacher_sections.sql
-- Assegnazione docente → sezione, decisa dall'amministratore.
--
-- Oggi il filtro dei contenuti per uno studente e', in
-- TeacherContentRepository::search():
--
--     teacher_id IN (SELECT user_id FROM teacher_institutes WHERE institute_id = ?)
--
-- cioe' TUTTI i docenti dell'istituto. Con un solo insegnante non si nota;
-- al secondo di matematica uno studente di 1A si troverebbe in elenco anche
-- le mappe e le 41 verifiche della 1B, senza modo di distinguerle.
--
-- La sezione e' un fatto organizzativo della scuola — chi insegna in 1A non
-- lo decide chi insegna — quindi l'assegnazione e' un'azione amministrativa,
-- non una preferenza del docente. E' la ragione per cui questa tabella non
-- torna dentro curriculum_entries, che invece e' per-docente.
--
-- NB: il progetto aveva gia' avuto un pivot (curriculum_users), droppato dalla
-- migration 044 quando tutti i kind sono diventati per-docente. Questo non e'
-- un ritorno indietro: quello mappava il CATALOGO, questo mappa l'INCARICO.
--
-- Vedi: app/Services/TeacherSectionService.php
--       app/Repositories/TeacherContentRepository.php

SET NAMES utf8mb4;

SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_sections');
SET @sql := IF(@t_exists = 0,
    'CREATE TABLE teacher_sections (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED NOT NULL,
        institute_id  INT UNSIGNED NOT NULL,
        indirizzo     VARCHAR(16)  NOT NULL,
        -- Stesso dominio di curriculum_entries.classi: "1" e il corso intero,
        -- "1A" la singola sezione. Il matching e gerarchico, non un uguale
        -- secco: vedi TeacherSectionService::classeMatches().
        classe        VARCHAR(16)  NOT NULL,
        assigned_by   INT UNSIGNED DEFAULT NULL,
        assigned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note          VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_teacher_section (user_id, institute_id, indirizzo, classe),
        KEY idx_ts_lookup (institute_id, indirizzo, classe),
        CONSTRAINT fk_ts_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
        CONSTRAINT fk_ts_institute FOREIGN KEY (institute_id) REFERENCES institutes(id) ON DELETE CASCADE,
        CONSTRAINT fk_ts_admin     FOREIGN KEY (assigned_by)  REFERENCES users(id)      ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Incarichi docente-sezione, assegnati dall''''amministratore''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Sezione dello studente. users.classe oggi contiene l'anno ("1"); con le
-- sezioni attive conterra' "1A". La colonna esiste gia' (migration 091), qui
-- si allarga soltanto il commento perche' il significato cambia.
SET @c_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'classe');
SET @sql := IF(@c_exists = 1,
    'ALTER TABLE users MODIFY COLUMN classe VARCHAR(16) DEFAULT NULL
     COMMENT ''Anno ("1") o sezione ("1A") a cui lo studente e iscritto''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
