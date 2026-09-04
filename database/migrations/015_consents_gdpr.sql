-- Phase 25.C1 — Schema consents + parent_consents per GDPR compliance.
--
-- Copre Art. 6 (base giuridica), Art. 7 (condizioni per il consenso),
-- Art. 8 (minori), Art. 9 (categorie particolari = BES/DSA come sanitario).
--
-- Tabella `consents`:
--   1 row per (user_id, consent_type, text_version). Audit trail completo.
--   Revoke = UPDATE revoked_at, NON DELETE (preserve history).
--
-- Tabella `parent_consents`:
--   Solo per studenti < 16 anni (Art. 8 GDPR + recepimento italiano D.Lgs.
--   101/2018: età 14 anni soglia consent autonomo). Doppio opt-in via
--   email del genitore (token 30g cooling-off).
--
-- Tabella `deletion_requests`:
--   Phase 25.C4 — coda per Art. 17 oblio con cooling-off 30g.
--   Da NEW → CONFIRMED (token email click) → COOLING_OFF (30g) → EXECUTED.
--   Cancellazione = soft-delete users + crypto-shredding teacher_keys
--   (Phase 25.D infrastructure).

-- ─────── 1. consents ───────
CREATE TABLE IF NOT EXISTS consents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    -- Tipo di consenso. Estensibile (futuri: 'biometric', 'profiling',
    -- 'third_party_share_pool'). Phase 25.C iniziale: 6 valori obbligatori.
    consent_type    ENUM(
        'art9_bes_dsa',           -- Categoria particolare (Art. 9): BES/DSA come dato sanitario
        'analytics',              -- Cookie/tracking analytics (non strettamente necessari)
        'marketing',              -- Email/notifiche marketing (futuro)
        'institute_share',        -- Condivisione contenuti con altri docenti dell'istituto
        'pool_share',             -- Pool repository condiviso (Phase 18 shared_with_pool)
        'third_party_export'      -- Export verso GDrive / Overleaf (esterni)
    ) NOT NULL,
    granted         TINYINT(1) NOT NULL,
    granted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      TIMESTAMP NULL,
    -- Hash IP/UA (no PII raw): SHA-256 dei primi 2 octet (aggregazione coarse)
    ip_hash         VARBINARY(32) NULL,
    user_agent_hash VARBINARY(32) NULL,
    -- Base giuridica esplicita (Art. 6 + Art. 9 §2)
    legal_basis     ENUM(
        'art6_1_a_consent',        -- Consenso esplicito (default per Art. 9)
        'art6_1_b_contract',       -- Esecuzione contratto (registrazione utenza)
        'art6_1_c_legal_obligation',-- Obbligo legale (logging accessi)
        'art6_1_e_public_interest', -- Interesse pubblico (didattica)
        'art6_1_f_legitimate',     -- Interesse legittimo (sicurezza, audit)
        'art9_2_a_explicit_consent' -- Consenso esplicito categoria particolare
    ) NOT NULL DEFAULT 'art6_1_a_consent',
    -- Versione informativa al momento del consenso (per revisione)
    text_version    VARCHAR(16) NOT NULL,
    -- Note libere dell'admin / DPO (es. "Consenso ottenuto in classe 2025-09-15")
    notes           VARCHAR(512) NULL,
    INDEX idx_user_type        (user_id, consent_type),
    INDEX idx_user_active      (user_id, revoked_at, consent_type),
    INDEX idx_consent_type     (consent_type, granted_at),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 2. parent_consents ───────
-- Per studenti minori (< 14 in Italia, < 16 default GDPR).
-- Modello double-opt-in:
--   1. Studente registra → parent_email obbligatoria
--   2. Sistema invia mail genitore con token (TTL 30 giorni)
--   3. Genitore clicca link → conferma consent → utenza attivata
--   4. Senza conferma in 30 giorni: registrazione cancellata
CREATE TABLE IF NOT EXISTS parent_consents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id INT UNSIGNED NOT NULL,
    parent_email    VARCHAR(255) NOT NULL,
    parent_name     VARCHAR(120) NULL,
    -- Token per doppio opt-in (random_bytes(32) → hex 64-char)
    confirm_token   VARCHAR(64) NOT NULL,
    -- Stato workflow
    status          ENUM('pending', 'confirmed', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    requested_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    TIMESTAMP NULL,
    revoked_at      TIMESTAMP NULL,
    expires_at      TIMESTAMP NULL,  -- requested_at + 30 days
    -- Hash IP/UA del genitore al click confirm (audit Art. 30)
    confirm_ip_hash VARBINARY(32) NULL,
    confirm_ua_hash VARBINARY(32) NULL,
    UNIQUE KEY uniq_token (confirm_token),
    INDEX idx_student (student_user_id, status),
    INDEX idx_parent_email (parent_email),
    CONSTRAINT fk_parent_consents_user
        FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 3. deletion_requests (Phase 25.C4 — Art. 17 GDPR) ───────
CREATE TABLE IF NOT EXISTS deletion_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    -- Token email confirm (il user clicca link da mail per confermare)
    confirm_token   VARCHAR(64) NOT NULL,
    -- Workflow:
    --   PENDING_CONFIRM  → mail inviata, attesa click conferma
    --   CONFIRMED        → cooling-off 30g iniziato
    --   COOLING_OFF      → in attesa di esecuzione (revocabile)
    --   EXECUTED         → cancellazione + crypto-shredding eseguiti
    --   CANCELLED        → utente ha annullato la richiesta
    --   EXPIRED          → confirm_token scaduto, request abbandonata
    status          ENUM('pending_confirm', 'confirmed', 'cooling_off',
                         'executed', 'cancelled', 'expired')
                    NOT NULL DEFAULT 'pending_confirm',
    requested_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    TIMESTAMP NULL,
    -- Tempo dopo cui la cancellazione è eseguita (default = confirmed_at + 30g)
    execute_after   TIMESTAMP NULL,
    executed_at     TIMESTAMP NULL,
    cancelled_at    TIMESTAMP NULL,
    expires_at      TIMESTAMP NULL,  -- confirm_token expiry (default 7g da requested_at)
    -- Reason (opzionale): user può specificare ragione
    reason          VARCHAR(512) NULL,
    -- Audit IP/UA della richiesta + confirm
    request_ip_hash VARBINARY(32) NULL,
    confirm_ip_hash VARBINARY(32) NULL,
    UNIQUE KEY uniq_token (confirm_token),
    INDEX idx_user_status (user_id, status),
    INDEX idx_status_execute (status, execute_after),
    CONSTRAINT fk_deletion_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 4. consent_audit (event log immutabile) ───────
-- Append-only log di ogni grant/revoke/expire per audit DPO.
CREATE TABLE IF NOT EXISTS consent_audit (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consent_id      INT UNSIGNED NULL,
    user_id         INT UNSIGNED NOT NULL,
    consent_type    VARCHAR(64) NOT NULL,
    event           ENUM('granted', 'revoked', 'expired', 'reconfirmed_after_text_change') NOT NULL,
    text_version    VARCHAR(16) NULL,
    accessed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash         VARBINARY(32) NULL,
    INDEX idx_user_event (user_id, accessed_at),
    INDEX idx_consent_event (consent_type, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
