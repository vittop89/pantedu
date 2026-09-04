-- Phase 25.C13 — DPO contact form requests.
--
-- Tabella append-friendly per richieste GDPR via /dpo-contact.
-- Permette:
--   - Tracking SLA Art. 12 §3 GDPR (risposta entro 30g, prorogabile a 60g
--     con comunicazione motivata).
--   - Audit ispettivo (chi ha contattato il DPO, quando, per quale ragione).
--   - Antispam analysis (IP hash + rate-limit).
--
-- Subject ENUM mappato ai 6 diritti principali GDPR (Art. 15-22) + altro.
--
-- Workflow status:
--   open → acknowledged (auto-reply 72h) → responded → closed
--   open → spam (admin manual flag)
--
-- Rollback: DROP TABLE dpo_requests;

CREATE TABLE IF NOT EXISTS dpo_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Identificativi richiedente
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    subject         ENUM(
        'access',         -- Art. 15 — accesso ai propri dati
        'rectification',  -- Art. 16 — rettifica
        'erasure',        -- Art. 17 — oblio
        'restriction',    -- Art. 18 — limitazione
        'portability',    -- Art. 20 — portabilità
        'objection',      -- Art. 21 — opposizione
        'consent_revoke', -- Art. 7 §3 — revoca consenso
        'breach_report',  -- segnalazione possibile data breach
        'other'           -- altre richieste DPO
    ) NOT NULL,
    -- Phase 25.C7 — flag se la richiesta è da/per un minore (parent_email
    -- diversa da email principale → richiesta del genitore).
    is_minor_related TINYINT(1) NOT NULL DEFAULT 0,
    -- Messaggio libero (max 8KB)
    message         TEXT NOT NULL,
    -- Audit metadata
    ip_hash         VARBINARY(32) NULL,
    user_agent_hash VARBINARY(32) NULL,
    -- Workflow
    status          ENUM('open', 'acknowledged', 'responded', 'closed', 'spam')
                    NOT NULL DEFAULT 'open',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,  -- auto-set 72h SLA marker
    responded_at    TIMESTAMP NULL,  -- DPO ha risposto (manual update)
    closed_at       TIMESTAMP NULL,  -- richiesta chiusa
    -- DPO note (admin only, audit trail)
    dpo_notes       TEXT NULL,
    INDEX idx_status_created (status, created_at),
    INDEX idx_subject_status (subject, status),
    INDEX idx_email          (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
