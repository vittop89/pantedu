-- Phase 25.R.25 — Content Action Log (append-only audit trail eventi docente).
--
-- Risolve gap di logging identificato nell'audit Phase 25.R.24:
--   - Creazione, pubblicazione, archiviazione, eliminazione contenuti docente
--     NON erano registrati in un log evento-centrico (solo timestamp colonna
--     o snapshot in content_versions).
--
-- Eventi tracciati (`action` enum):
--   - content_created      teacher_content nuovo record
--   - content_updated      modifica fields (title/topic/body/metadata)
--   - content_published    visibility draft → published
--   - content_archived     visibility * → archived
--   - content_unpublished  visibility published → draft
--   - content_deleted      soft o hard delete
--   - content_cloned_from  import/clone da source_content_id
--   - content_shared       shared_with_pool=1
--   - content_unshared     shared_with_pool=0
--   - content_exported     incluso in bundle authority-export
--
-- Append-only: nessun UPDATE/DELETE consentito a livello applicativo.
-- Retention: 7 anni (Art. 32 GDPR best practice). Cleanup via cron.
--
-- Rollback:
--   DROP TABLE content_action_log;

CREATE TABLE IF NOT EXISTS content_action_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    teacher_id      INT UNSIGNED    NOT NULL,
    actor_user_id   INT UNSIGNED    NULL,
    -- NULL = sistema (es. cron purge, auto-block scheduled job)
    content_id      BIGINT UNSIGNED NOT NULL,
    content_type    VARCHAR(32)     NOT NULL,
    -- Mirror teacher_content.content_type: mappa, esercizio, lab, verifica, ecc.
    action          VARCHAR(32)     NOT NULL,
    -- Enum applicativo (vedi descrizione sopra)
    details_json    LONGTEXT        NULL,
    -- Metadata libero: change_summary, source_id (clone), previous_visibility,
    -- new_visibility, bundle_sha256 (export), ip, user_agent.
    ip_address      VARCHAR(45)     NULL,
    user_agent      VARCHAR(512)    NULL,

    KEY idx_cal_teacher    (teacher_id, occurred_at),
    KEY idx_cal_content    (content_id, occurred_at),
    KEY idx_cal_action     (action, occurred_at),
    KEY idx_cal_occurred   (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 25.R.25 — Audit append-only eventi contenuti docente';
