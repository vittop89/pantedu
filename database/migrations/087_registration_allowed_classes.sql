-- Migration 087 — ADR-028 Fase 1: classi ammesse all'iscrizione (trasversale).
--
-- Allowlist di coppie (indirizzo, classe) per cui è consentita la
-- registrazione studente. Trasversale: vale anche in modo SINGLE, indipendente
-- dall'attivazione INSTITUTE.
--
-- Semantica: tabella VUOTA = nessuna restrizione (tutte le classi ammesse,
-- retrocompat). Con almeno una riga = SOLO le coppie elencate sono ammesse.
--
-- institute_id NULL = regola globale; valorizzato = regola per-istituto
-- (multi-tenant futuro). Per ora si usa NULL (deployment single/istituto unico).
-- Idempotente.

CREATE TABLE IF NOT EXISTS registration_allowed_classes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    institute_id INT UNSIGNED NULL,
    indirizzo    VARCHAR(64)  NOT NULL,
    classe       VARCHAR(32)  NOT NULL,
    created_by   VARCHAR(190) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inst_ind_cls (institute_id, indirizzo, classe),
    INDEX idx_lookup (indirizzo, classe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
