-- Phase G1.a — Google Drive OAuth integration foundation.
--
-- Aggiunge:
--   1. teacher_drive_oauth: 1 row per docente con refresh_token cifrato
--      via TeacherCryptoService (envelope, ADR-006). Disconnect = DELETE
--      della row (idempotent). Connessione = OAuth consent + scambio code
--      + storage del refresh_token wrapped.
--   2. teacher_drive_folder_cache: cache (teacher_id, folder_path) →
--      drive_folder_id. Evita ripetuti folder.list su Drive API durante
--      sync. TTL implicito (cached_at): rebuild se folder_path richiesto
--      ma row mancante o scaduta.
--
-- Scope OAuth iniziale: drive.file (read+write SOLO file creati dall'app
-- via picker o upload). Scope esteso drive.readonly UNA TANTUM in fase G6
-- migrazione (download legacy .drawio già su Drive del docente). Dopo
-- migrazione il refresh_token resta a drive.file.
--
-- Rollback safe:
--   DROP TABLE teacher_drive_folder_cache;
--   DROP TABLE teacher_drive_oauth;
-- Nessuna FK fuori da users → no cascade su altre entita'.

-- ─────── 1. teacher_drive_oauth ───────
CREATE TABLE IF NOT EXISTS teacher_drive_oauth (
    teacher_id INT UNSIGNED NOT NULL,

    -- refresh_token cifrato via TeacherCryptoService::encrypt() (envelope
    -- KEK del teacher). Disconnect = DELETE row → token unreadable.
    refresh_token_ct  VARBINARY(512) NOT NULL,
    refresh_token_iv  VARBINARY(12)  NOT NULL,
    refresh_token_tag VARBINARY(16)  NOT NULL,
    refresh_token_kv  SMALLINT UNSIGNED NOT NULL,

    -- Scope OAuth concesso (es. "https://www.googleapis.com/auth/drive.file").
    -- Reso esplicito per audit / decisione di richiedere re-consent in caso
    -- di scope upgrade (es. da drive.file a drive.readonly per migrazione).
    scope         VARCHAR(255)  NOT NULL,

    -- Email Google account collegato (display + audit).
    email         VARCHAR(255)  NULL,

    -- Drive folder ID della root "Pantedu/" creata in Drive del docente
    -- alla prima connessione. Cache per evitare folder.search ripetuti.
    drive_root_id VARCHAR(64)   NULL,

    connected_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sync_at  DATETIME      NULL,

    PRIMARY KEY (teacher_id),
    CONSTRAINT fk_tdo_user
        FOREIGN KEY (teacher_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 2. teacher_drive_folder_cache ───────
CREATE TABLE IF NOT EXISTS teacher_drive_folder_cache (
    teacher_id      INT UNSIGNED NOT NULL,
    folder_path     VARCHAR(512) NOT NULL,
    drive_folder_id VARCHAR(64)  NOT NULL,
    cached_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (teacher_id, folder_path),
    KEY idx_drive_folder_id (drive_folder_id),
    CONSTRAINT fk_tdfc_user
        FOREIGN KEY (teacher_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
