-- G22.S15.bis Fase 5 — Recovery Key opzionale per docente.
-- Permette di recuperare i blob cifrati anche se il KMS master è compromesso/perso.
--
-- Flow:
--   1. Signup: docente sceglie di generare R (32 bytes random)
--   2. Server: AES-256-GCM(R, KMS_MASTER) → wrapped_recovery (in DB)
--   3. Server: AES-256-GCM(KEK_teacher, R) → kek_recovery_wrapped (in teacher_keys)
--   4. Frontend: riceve R una sola volta, genera PDF stampabile + QR code
--   5. Docente conserva PDF in cassaforte fisica
--
-- Recovery: docente fornisce R → ricostruisce KEK senza KMS → blob decifrabili.

CREATE TABLE IF NOT EXISTS teacher_recovery_keys (
    user_id              INT UNSIGNED NOT NULL PRIMARY KEY,
    -- AES-GCM(R, KMS_MASTER): se KMS è OK, server può ricostruire R per audit;
    -- se KMS è perso, R serve via path utente (PDF cassaforte).
    wrapped_recovery     BLOB         NOT NULL,
    recovery_kv          INT UNSIGNED NOT NULL,
    -- Audit / lifecycle
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_downloaded_at   DATETIME     DEFAULT NULL,
    download_count       INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at         DATETIME     DEFAULT NULL,
    use_count            INT UNSIGNED NOT NULL DEFAULT 0,
    revoked_at           DATETIME     DEFAULT NULL,
    CONSTRAINT fk_teacher_recovery_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riavvolgimento KEK con R: nuova colonna in teacher_keys
ALTER TABLE teacher_keys
    ADD COLUMN IF NOT EXISTS kek_recovery_wrapped BLOB NULL,
    ADD COLUMN IF NOT EXISTS recovery_wrap_kv     INT UNSIGNED NULL;

-- Audit accessi recovery (per detection abuso)
CREATE TABLE IF NOT EXISTS teacher_recovery_audit (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    action       ENUM('generate','download','use','revoke') NOT NULL,
    ip           VARCHAR(45) NULL,
    user_agent   VARCHAR(500) NULL,
    success      TINYINT(1) NOT NULL DEFAULT 1,
    note         TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recovery_audit_user (user_id, created_at),
    CONSTRAINT fk_recovery_audit_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
