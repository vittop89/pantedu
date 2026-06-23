-- 057_takedown_requests.sql
-- Phase 25.P — Notice & Takedown procedure per multi-tenancy.
--
-- Pre-requisito per Scenario B/C — safe harbor giuridico ex D.Lgs. 70/2003 art. 16.
-- Vedi: docs/todo/multitenancy_responsibility_framework.md §3.3
--       docs/legal/takedown_procedure.md
--
-- Tabella: takedown_requests
--   id              auto-increment
--   submitted_at    timestamp submission
--   submitter_name  identità del segnalante (può essere anonimo se cittadino)
--   submitter_email contatto del segnalante
--   submitter_role  tipo: editor / dpo_other / authority / private / parent / self
--   content_ref     riferimento al contenuto (URL, ID DB, hash blob, ecc.)
--   uploader_user_id  FK users.id — chi ha caricato il contenuto contestato
--   violation_type  tipo violazione: copyright / gdpr_art9 / illegal / inappropriate / other
--   description     descrizione testuale violazione
--   attachments     JSON array di link/file allegati a sostegno
--   status          new / under_review / actioned / rejected / closed
--   action_taken    cosa fatto (removed / suspended_user / dismissed / forwarded_authority)
--   action_notes    note interne sulla valutazione
--   actioned_at     timestamp azione
--   actioned_by     user_id admin che ha gestito
--   notified_uploader  bool — uploader notificato della rimozione

SET NAMES utf8mb4;

SET @t_exists := (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'takedown_requests');
SET @sql := IF(@t_exists = 0,
    'CREATE TABLE takedown_requests (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        submitted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitter_name      VARCHAR(255) DEFAULT NULL,
        submitter_email     VARCHAR(255) DEFAULT NULL,
        submitter_role      ENUM(''editor'', ''dpo_other'', ''authority'', ''private'', ''parent'', ''self'', ''anonymous'') DEFAULT ''private'',
        submitter_ip        VARCHAR(45) DEFAULT NULL,
        content_ref         VARCHAR(1024) NOT NULL,
        uploader_user_id    INT UNSIGNED DEFAULT NULL,
        violation_type      ENUM(''copyright'', ''gdpr_art9'', ''illegal'', ''inappropriate'', ''spam'', ''other'') NOT NULL,
        description         TEXT NOT NULL,
        attachments         JSON DEFAULT NULL,
        status              ENUM(''new'', ''under_review'', ''actioned'', ''rejected'', ''closed'') NOT NULL DEFAULT ''new'',
        action_taken        ENUM(''pending'', ''removed'', ''suspended_user'', ''dismissed'', ''forwarded_authority'') DEFAULT ''pending'',
        action_notes        TEXT DEFAULT NULL,
        actioned_at         TIMESTAMP NULL DEFAULT NULL,
        actioned_by         INT UNSIGNED DEFAULT NULL,
        notified_uploader   TINYINT(1) NOT NULL DEFAULT 0,
        notified_at         TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_status (status),
        KEY idx_submitted_at (submitted_at),
        KEY idx_uploader (uploader_user_id),
        KEY idx_violation_type (violation_type),
        CONSTRAINT fk_takedown_uploader FOREIGN KEY (uploader_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_takedown_admin FOREIGN KEY (actioned_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Phase 25.P — Notice & Takedown per safe harbor D.Lgs.70/2003 art.16''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
