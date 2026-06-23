-- Phase 25.R.4.1 — GDPR governance admin tables.
--
-- Aggiunge interfacce super-admin per gestione:
--   1) data_breach_incidents      → Art. 33-34 GDPR (notifica Garante 72h + utenti)
--   2) subprocessors              → DPA art. 9 (lista responsabili esterni)
--   (data_requests usa già dpo_requests, no schema change qui)
--
-- Rollback:
--   DROP TABLE data_breach_incidents;
--   DROP TABLE subprocessors;
-- ═════════════════════════════════════════════════════════════════════════

-- ─── data_breach_incidents ──────────────────────────────────────────────
-- Append-only register dei data breach (incidenti rilevati). Conservazione
-- permanente (audit + accountability Art. 5 §2). Workflow:
--   detected → assessing → notified_garante → notified_users → closed
CREATE TABLE IF NOT EXISTS data_breach_incidents (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Cronologia
    occurred_at          DATETIME NOT NULL,            -- stima ora del breach
    detected_at          DATETIME NOT NULL,            -- ora rilevamento (start SLA 72h Art. 33)
    -- Severity (impatto)
    severity             ENUM('low','medium','high','critical') NOT NULL,
    -- Stima impatto
    affected_users_count INT UNSIGNED NULL,            -- numero stimato utenti coinvolti
    data_categories      VARCHAR(255) NULL,            -- CSV: 'auth', 'pii', 'content', 'crypto'
    -- Descrizione e azioni
    description          TEXT NOT NULL,                -- cosa è successo
    root_cause           TEXT NULL,                    -- analisi root cause
    remedial_actions     TEXT NULL,                    -- mitigazioni intraprese
    -- Workflow status
    status               ENUM('detected','assessing','notified_garante','notified_users','closed')
                         NOT NULL DEFAULT 'detected',
    -- Notifiche (Art. 33 = Garante; Art. 34 = utenti se rischio elevato)
    notified_garante_at  DATETIME NULL,                -- timestamp notifica al Garante
    garante_ref          VARCHAR(128) NULL,            -- ID procedimento Garante
    notified_users_at    DATETIME NULL,                -- timestamp comunicazione utenti
    users_notification_method VARCHAR(64) NULL,        -- email|banner|press
    -- Audit
    reported_by_user_id  INT UNSIGNED NULL,            -- chi ha aperto l'incident
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at            DATETIME NULL,
    -- Indici
    INDEX idx_status (status),
    INDEX idx_detected (detected_at),
    INDEX idx_severity (severity),
    CONSTRAINT fk_breach_reporter FOREIGN KEY (reported_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── subprocessors ──────────────────────────────────────────────────────
-- Lista responsabili esterni (DPA art. 9 + GDPR Art. 28). CRUD super-admin
-- only. Storico revisioni in updated_at (trigger non necessario per ora).
CREATE TABLE IF NOT EXISTS subprocessors (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Identità
    name                VARCHAR(160) NOT NULL,         -- es. "Aruba S.p.A."
    service_description VARCHAR(255) NOT NULL,         -- es. "Web hosting + DB + storage"
    -- Localizzazione + trasferimenti
    country             VARCHAR(64) NOT NULL,          -- es. "Italia"
    extra_eu_transfer   TINYINT(1) NOT NULL DEFAULT 0, -- 0=no, 1=sì
    transfer_safeguards VARCHAR(255) NULL,             -- es. "SCC + DPF"
    -- Contratto
    dpa_signed          TINYINT(1) NOT NULL DEFAULT 0,
    dpa_url             VARCHAR(512) NULL,             -- link al DPA PDF/firmato
    contact_email       VARCHAR(255) NULL,             -- contatto privacy del subprocessor
    -- Stato
    active              TINYINT(1) NOT NULL DEFAULT 1,
    -- Audit
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active),
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed iniziale (snapshot dell'informativa attuale)
INSERT IGNORE INTO subprocessors (name, service_description, country, extra_eu_transfer, transfer_safeguards, dpa_signed, contact_email, active)
VALUES
    ('Aruba S.p.A.', 'Web hosting + database + storage', 'Italia', 0, NULL, 1, 'privacy@staff.aruba.it', 1),
    ('Google LLC',   'OAuth login + Google Drive integration (opt-in)', 'USA', 1, 'SCC + DPF', 0, NULL, 1);
