-- G22.S15.bis Fase 5 — GitHub sync configuration per docente.
-- Memorizza il PAT (cifrato envelope con TKEK del docente, riuso EncryptedBlobStore
-- pattern) + repo target + ultima sync.
--
-- Il PAT è dati sensibili → mai in chiaro in DB. Cifratura:
--   pat_encrypted: BLOB cifrato AES-256-GCM con teacher TKEK (envelope)
--   pat_kv:        key version
-- Lookup: solo l'owner della TKEK può decifrare.
--
-- repo_owner / repo_name: esposti in chiaro (non sensibili, sono nomi pubblici).
-- branch: default 'main', l'utente può cambiarlo.

CREATE TABLE IF NOT EXISTS teacher_github_sync (
    user_id          INT UNSIGNED NOT NULL PRIMARY KEY,
    repo_owner       VARCHAR(64)  NOT NULL,
    repo_name        VARCHAR(128) NOT NULL,
    branch           VARCHAR(64)  NOT NULL DEFAULT 'main',
    pat_encrypted    BLOB         NOT NULL,
    pat_kv           INT UNSIGNED NOT NULL,
    last_sync_at     DATETIME     DEFAULT NULL,
    last_error       TEXT         DEFAULT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_teacher_github_sync_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
