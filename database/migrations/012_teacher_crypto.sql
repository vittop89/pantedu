-- Phase 25.D2 — Envelope encryption foundation.
--
-- Aggiunge:
--   1. teacher_keys: 1 row per docente con KEK wrapped (encrypted con
--      KMS_MASTER via HKDF→AES-GCM). Cancellazione di questa riga = crypto-
--      shredding O(1) del docente (Art. 17 GDPR efficiente).
--   2. crypto_access_log: append-only log di ogni operazione encrypt/
--      decrypt/shred/rotate. Reason obbligatorio se accessor != teacher
--      (super_admin reading altrui content).
--   3. ALTER ADD su teacher_content + risdoc_overrides: colonne *_ct/iv/
--      tag/kv per ciphertext. PLAINTEXT PRESERVATO durante migration
--      (additive only) — drop in migration 014 dopo backfill verificato.
--
-- Rollback safe:
--   ALTER TABLE teacher_content
--     DROP COLUMN body_pt_ct, DROP COLUMN body_pt_iv, ...;
--   DROP TABLE teacher_keys;
--   DROP TABLE crypto_access_log;
-- Plaintext columns intatte → zero data loss.

-- ─────── 1. teacher_keys ───────
CREATE TABLE IF NOT EXISTS teacher_keys (
    teacher_id INT UNSIGNED NOT NULL,
    -- Versione chiave: rotation annuale incrementa key_version. Le row
    -- teacher_content/risdoc_overrides hanno il proprio body_*_kv che
    -- referenzia la versione usata per encrypt; durante rotation, vecchie
    -- versioni restano disponibili finché tutte le row sono re-wrapped.
    key_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- wrapped KEK = AES-256-GCM(KMS_MASTER, KEK_random_32B)
    -- Layout binario: iv (12B) || ciphertext (32B) || tag (16B) = 60 bytes
    wrapped_kek VARBINARY(80) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rotated_at  TIMESTAMP NULL,
    PRIMARY KEY (teacher_id, key_version),
    CONSTRAINT fk_teacher_keys_user FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 2. crypto_access_log ───────
CREATE TABLE IF NOT EXISTS crypto_access_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    accessor_id INT UNSIGNED NOT NULL,
    teacher_id  INT UNSIGNED NOT NULL,
    table_name  VARCHAR(64) NOT NULL,
    row_id      INT UNSIGNED NULL,
    operation   ENUM('encrypt', 'decrypt', 'shred', 'rotate', 'wrap', 'unwrap') NOT NULL,
    -- Obbligatorio se accessor_id != teacher_id (super_admin reading altrui).
    -- Free text (10-512 char) — no validation server-side oltre lunghezza.
    reason      VARCHAR(512) NULL,
    -- Outcome: 'ok' (operation completed), 'denied' (auth/permission deny),
    -- 'error' (crypto failure, tag mismatch, etc.)
    outcome     ENUM('ok', 'denied', 'error') NOT NULL DEFAULT 'ok',
    accessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_teacher_time  (teacher_id, accessed_at),
    INDEX idx_accessor_time (accessor_id, accessed_at),
    INDEX idx_operation     (operation, accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 3. teacher_content: ALTER ADD ciphertext columns ───────
-- Phase 25.D2 PRESERVA body_html / metadata_json plaintext durante backfill.
-- Phase 25.D13 (migration successiva) DROP plaintext dopo verifica byte-byte.
--
-- Layout dati cifrato:
--   body_pt_ct  = AES-256-GCM(KEK, json_encode(body_pt))
--   body_pt_iv  = 12-byte random IV (uno per riga)
--   body_pt_tag = 16-byte GCM authentication tag
--   body_pt_kv  = key_version usata (FK virtual a teacher_keys.key_version)
--
-- Stesso pattern per body_html_* e metadata_*.
ALTER TABLE teacher_content
    ADD COLUMN body_pt_ct      MEDIUMBLOB NULL AFTER body_html,
    ADD COLUMN body_pt_iv      VARBINARY(12) NULL AFTER body_pt_ct,
    ADD COLUMN body_pt_tag     VARBINARY(16) NULL AFTER body_pt_iv,
    ADD COLUMN body_pt_kv      SMALLINT UNSIGNED NULL AFTER body_pt_tag,
    ADD COLUMN body_html_ct    MEDIUMBLOB NULL AFTER body_pt_kv,
    ADD COLUMN body_html_iv    VARBINARY(12) NULL AFTER body_html_ct,
    ADD COLUMN body_html_tag   VARBINARY(16) NULL AFTER body_html_iv,
    ADD COLUMN body_html_kv    SMALLINT UNSIGNED NULL AFTER body_html_tag,
    ADD COLUMN metadata_ct     MEDIUMBLOB NULL AFTER body_html_kv,
    ADD COLUMN metadata_iv     VARBINARY(12) NULL AFTER metadata_ct,
    ADD COLUMN metadata_tag    VARBINARY(16) NULL AFTER metadata_iv,
    ADD COLUMN metadata_kv     SMALLINT UNSIGNED NULL AFTER metadata_tag,
    ADD INDEX idx_body_pt_kv   (body_pt_kv),
    ADD INDEX idx_body_html_kv (body_html_kv),
    ADD INDEX idx_metadata_kv  (metadata_kv);

-- ─────── 4. risdoc_teacher_overrides: ALTER ADD ciphertext columns ───────
ALTER TABLE risdoc_teacher_overrides
    ADD COLUMN body_ct  LONGBLOB NULL AFTER body,
    ADD COLUMN body_iv  VARBINARY(12) NULL AFTER body_ct,
    ADD COLUMN body_tag VARBINARY(16) NULL AFTER body_iv,
    ADD COLUMN body_kv  SMALLINT UNSIGNED NULL AFTER body_tag,
    ADD INDEX idx_body_kv (body_kv);
