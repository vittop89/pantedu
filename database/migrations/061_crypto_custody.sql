-- Phase 25.R.5.3 — Registro custodia chiavi crypto + log cooperazione autorità.
--
-- Append-only audit log per tracciare:
--   1) custodia/rotazione KMS_MASTER_KEY (chi ha la chiave, dove, quando)
--   2) richieste di accesso ai dati cifrati da parte di autorità (data
--      controller, polizia giudiziaria, tribunale, Garante)
--   3) esiti delle operazioni di recupero/decifratura su ordine
--
-- Conservazione: PERMANENTE (audit accountability Art. 5 §2 GDPR + Art. 32
-- "misure organizzative", DPA Allegato 2 art. 8).
--
-- Rollback: DROP TABLE crypto_custody_events;
-- ═════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS crypto_custody_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Tipo evento
    event_type      ENUM(
        'kms_generated',         -- KMS_MASTER_KEY creata
        'kms_rotated',           -- rotazione
        'kms_backup_created',    -- copia in busta sigillata depositata
        'kms_backup_verified',   -- verifica annuale integrità custodia
        'authority_request',     -- ricevuta richiesta autorità
        'authority_granted',     -- autorizzazione (es. tribunale) ricevuta
        'authority_denied',      -- richiesta respinta (motivo: GDPR, scopo, ecc.)
        'data_recovered',        -- estrazione dati per autorità
        'data_provided',         -- consegna materiale all'autorità
        'kek_emergency_access',  -- accesso amministrativo a KEK docente (es. legal hold)
        'key_destroyed'          -- distruzione chiave (es. fine custodia)
    ) NOT NULL,
    -- Soggetto coinvolto
    teacher_id      INT UNSIGNED NULL,         -- se evento riguarda KEK specifica
    actor_user_id   INT UNSIGNED NULL,         -- chi ha registrato l'evento (super-admin)
    -- Custodia / autorità
    authority_name  VARCHAR(160) NULL,         -- es. "Tribunale di Milano", "Garante", "PG"
    authority_ref   VARCHAR(255) NULL,         -- numero procedimento / decreto / richiesta
    custodian_name  VARCHAR(160) NULL,         -- es. "Notaio Mario Rossi"
    custody_location VARCHAR(255) NULL,        -- es. "Cassetta sicurezza UniCredit Milano"
    -- Documentazione
    description     TEXT NOT NULL,             -- narrativa dell'evento
    legal_basis     VARCHAR(255) NULL,         -- es. "Art. 6(1)(c) GDPR + decreto X/2026"
    evidence_url    VARCHAR(512) NULL,         -- link a PDF firmato / decreto archivato
    -- Audit
    occurred_at     DATETIME NOT NULL,
    recorded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Indici
    INDEX idx_event_type (event_type),
    INDEX idx_teacher    (teacher_id),
    INDEX idx_occurred   (occurred_at),
    CONSTRAINT fk_custody_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
