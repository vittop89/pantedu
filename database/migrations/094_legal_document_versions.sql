-- 094_legal_document_versions.sql
-- Versioning dei documenti legali + preavviso 30 giorni.
--
-- Chiude tre buchi lasciati da 056_tos_aup_acceptance.sql:
--
--   1. La versione corrente era una costante PHP (TosAcceptanceService::
--      TOS_VERSION_CURRENT). Bumpare docs/legal/aup.md non aveva alcun
--      effetto finché qualcuno non ricordava di editare il PHP.
--
--   2. Non esisteva il concetto di "versione pubblicata ma non ancora
--      efficace": cambiare la costante bloccava tutti all'istante, cioè
--      l'opposto del preavviso di 30 giorni promesso da ToS §8 e AUP §6.
--
--   3. La PK (user_id, tos_version) rendeva impossibile accettare un
--      aggiornamento della sola AUP: l'INSERT IGNORE collideva con la riga
--      esistente, veniva scartato in silenzio e l'utente restava in loop
--      di redirect permanente sul form di accettazione.
--
-- Vedi: docs/legal/tos_docente.md §8, docs/legal/aup.md §6

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Registro versioni dei documenti legali.
--    L'ordinamento è temporale (effective_from), MAI lessicografico sulla
--    stringa di versione: '1.10' < '1.9' in ordine stringa.
-- ---------------------------------------------------------------------------
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_document_versions');
SET @sql := IF(@t_exists = 0,
    'CREATE TABLE legal_document_versions (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        doc_type       ENUM(''tos'',''aup'') NOT NULL,
        version        VARCHAR(20) NOT NULL,
        published_at   DATETIME NOT NULL,
        effective_from DATETIME NOT NULL,
        is_substantial TINYINT(1) NOT NULL DEFAULT 1,
        checksum       CHAR(64) DEFAULT NULL,
        summary        VARCHAR(500) DEFAULT NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_doc_version (doc_type, version),
        KEY idx_doc_effective (doc_type, effective_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Versioni ToS/AUP con data di efficacia (preavviso 30gg)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Seed delle versioni già in vigore (1.0, pubblicate il 20 maggio 2026).
--    effective_from = published_at: erano vigenti dalla pubblicazione, non
--    c'era ancora un meccanismo di preavviso da rispettare.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO legal_document_versions
    (doc_type, version, published_at, effective_from, is_substantial, summary)
VALUES
    ('tos', '1.0', '2026-05-20 00:00:00', '2026-05-20 00:00:00', 1,
     'Prima pubblicazione dei Termini di Servizio docente.'),
    ('aup', '1.0', '2026-05-20 00:00:00', '2026-05-20 00:00:00', 1,
     'Prima pubblicazione della Acceptable Use Policy.');

-- ---------------------------------------------------------------------------
-- 3. PK di user_tos_acceptance: (user_id, tos_version) -> +aup_version.
--    Senza aup_version nella chiave, un bump della sola AUP non può essere
--    registrato e l'utente resta bloccato per sempre.
-- ---------------------------------------------------------------------------
SET @pk_cols := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_tos_acceptance'
      AND INDEX_NAME = 'PRIMARY');
SET @sql := IF(@pk_cols = 2,
    'ALTER TABLE user_tos_acceptance
        DROP PRIMARY KEY,
        ADD PRIMARY KEY (user_id, tos_version, aup_version)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Dedupe delle email di preavviso: una sola per (utente, versione,
--    milestone). Senza questa tabella il cron rispedirebbe la stessa
--    notifica a ogni giro.
-- ---------------------------------------------------------------------------
SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legal_version_notifications');
SET @sql := IF(@t_exists = 0,
    'CREATE TABLE legal_version_notifications (
        user_id        INT UNSIGNED NOT NULL,
        version_id     INT UNSIGNED NOT NULL,
        milestone_days SMALLINT UNSIGNED NOT NULL,
        sent_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, version_id, milestone_days),
        KEY idx_sent_at (sent_at),
        CONSTRAINT fk_lvn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_lvn_version FOREIGN KEY (version_id)
            REFERENCES legal_document_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Dedupe preavvisi email aggiornamento ToS/AUP''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
