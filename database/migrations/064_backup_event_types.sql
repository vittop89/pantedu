-- Phase 25.R follow-up — Estende ENUM crypto_custody_events.event_type per
-- supportare i nuovi eventi della sezione /admin/backup unificata.
--
-- Eventi aggiunti:
--   - cold_backup_completed   → cold backup HDD esterno mensile completato
--   - b2_backup_verified      → verifica integrità ultimo backup B2
--   - hetzner_snapshot_taken  → snapshot Hetzner Cloud creato (registrazione manuale)
--
-- Rationale: i tipi esistenti (kms_backup_created/verified) erano focalizzati
-- sulle copie KMS_MASTER. Per il pannello /admin/backup serve granularità per
-- tracciare anche backup completi (DB+storage) e snapshot infra.
--
-- Rollback: ALTER TABLE crypto_custody_events MODIFY event_type ENUM(... senza i 3 nuovi);
-- (richiede prima cancellare eventuali righe con i nuovi tipi).
-- ═════════════════════════════════════════════════════════════════════════

ALTER TABLE crypto_custody_events
    MODIFY COLUMN event_type ENUM(
        'kms_generated',
        'kms_rotated',
        'kms_backup_created',
        'kms_backup_verified',
        'authority_request',
        'authority_granted',
        'authority_denied',
        'data_recovered',
        'data_provided',
        'kek_emergency_access',
        'key_destroyed',
        -- Phase 25.R follow-up — pannello /admin/backup
        'cold_backup_completed',
        'b2_backup_verified',
        'hetzner_snapshot_taken'
    ) NOT NULL;
