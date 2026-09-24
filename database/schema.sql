-- Pantedu MySQL schema (Phase 11 M1–M8).
-- Idempotent: `CREATE TABLE IF NOT EXISTS` ovunque. Safe to rerun.
-- Target: MariaDB 10.4+ (JSON type + utf8mb4). NON MySQL: cinquantaquattro
-- istruzioni delle migrazioni usano `ADD COLUMN IF NOT EXISTS` e
-- `DROP COLUMN IF EXISTS`, che MySQL non conosce — verificato il 7 settembre
-- 2026 su MySQL 8, dove `php tools/migrate.php` si ferma alla 035.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M2: users
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    role            VARCHAR(32)  NOT NULL DEFAULT 'student',
    first_name      VARCHAR(128) NOT NULL DEFAULT '',
    last_name       VARCHAR(128) NOT NULL DEFAULT '',
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    status          VARCHAR(32)  NOT NULL DEFAULT 'pending',
    active          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at     DATETIME     NULL,
    approved_by     VARCHAR(64)  NULL,
    INDEX idx_users_role   (role),
    INDEX idx_users_status (status),
    INDEX idx_users_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M3: ownership (chi possiede cosa)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ownership (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    kind       VARCHAR(32)  NOT NULL,     -- verifiche|eser|mappe|lab|...
    path       VARCHAR(512) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_path (user_id, kind, path),
    INDEX idx_owner_kind (kind),
    CONSTRAINT fk_ownership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M4: registrations (coda approvazione)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS registrations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(64)  NOT NULL,
    first_name     VARCHAR(128) NOT NULL DEFAULT '',
    last_name      VARCHAR(128) NOT NULL DEFAULT '',
    email          VARCHAR(255) NOT NULL,
    role           VARCHAR(32)  NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    status         VARCHAR(32)  NOT NULL DEFAULT 'pending',
    requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at     DATETIME     NULL,
    decided_by     VARCHAR(64)  NULL,
    INDEX idx_reg_status (status),
    INDEX idx_reg_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M5: curriculum + pivot scoping per-teacher
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS curriculum_entries (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind    VARCHAR(32)  NOT NULL,        -- indirizzi|classi|materie
    code    VARCHAR(64)  NOT NULL,
    label   VARCHAR(255) NOT NULL,
    grp     VARCHAR(64)  NULL,
    active  TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_curriculum (kind, code),
    INDEX idx_curriculum_kind_active (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_users (
    curriculum_id INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (curriculum_id, user_id),
    CONSTRAINT fk_cu_curriculum FOREIGN KEY (curriculum_id) REFERENCES curriculum_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_user       FOREIGN KEY (user_id)       REFERENCES users(id)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M6: exercises (da one-shot parsing eser/**.php)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exercises (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indirizzo     VARCHAR(16)  NOT NULL,  -- sc|ar|...
    classe        VARCHAR(16)  NOT NULL,  -- sc1s|ar3s|...
    materia       VARCHAR(16)  NOT NULL,  -- MAT|FIS
    topic         VARCHAR(128) NOT NULL,
    title         VARCHAR(255) NOT NULL,
    difficulty    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    tags          JSON         NULL,
    body_html     MEDIUMTEXT   NOT NULL,
    solution_html MEDIUMTEXT   NULL,
    source        VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ex_scope  (indirizzo, classe, materia),
    INDEX idx_ex_topic  (topic),
    INDEX idx_ex_diff   (difficulty),
    FULLTEXT KEY ft_ex_body (body_html)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M7: teacher workspace (clone esercizio + verifiche generate)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teacher_exercises (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    parent_exercise_id  INT UNSIGNED NULL,
    title               VARCHAR(255) NOT NULL,
    body_html           MEDIUMTEXT   NOT NULL,
    solution_html       MEDIUMTEXT   NULL,
    tags                JSON         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_te_user (user_id),
    CONSTRAINT fk_te_user   FOREIGN KEY (user_id)            REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_te_parent FOREIGN KEY (parent_exercise_id) REFERENCES exercises(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_verifiche (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    variant     VARCHAR(32)  NOT NULL DEFAULT 'normal',
    filename    VARCHAR(255) NOT NULL,
    tex_content MEDIUMTEXT   NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tv_user (user_id),
    UNIQUE KEY uq_tv_file (user_id, filename),
    CONSTRAINT fk_tv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- M8: print_info per-user
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS print_info (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    page_key    VARCHAR(255) NOT NULL,
    indirizzo   VARCHAR(16)  NULL,
    classe      VARCHAR(16)  NULL,
    materia     VARCHAR(16)  NULL,
    n_print     INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pi_user_page (user_id, page_key),
    CONSTRAINT fk_pi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 20 — template_cache rimossa (zero uso). DROP via migration 005.

-- ═════════════════════════════════════════════════════════════
-- Phase 20 — schema_migrations: tracking delle migration eseguite.
-- Popolata da app/Core/Migrator. Ogni riga = 1 file .sql eseguito.
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(255) NOT NULL PRIMARY KEY,
    executed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M11: teacher_content — multi-materia, multi-tipo content
-- ═════════════════════════════════════════════════════════════
-- Tabella unificata per il content creato dai docenti, agnostica
-- rispetto alla materia (subject_code può essere qualunque codice
-- in curriculum_entries kind=materie, non solo MAT/FIS) e al tipo
-- (mappa, esercizio, lab, verifica). Affianca `exercises` (legacy
-- admin-imported) e `teacher_exercises` (clone editing).
--
-- Visibilità:
--   - draft     → solo l'autore vede
--   - published → studenti della sezione vedono via /studio/...
--   - archived  → nascosto ma preservato
CREATE TABLE IF NOT EXISTS teacher_content (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id        INT UNSIGNED NOT NULL,
    content_type      ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica') NOT NULL,
    subject_code      VARCHAR(16)  NOT NULL,        -- es. MAT, FIS, CHI, STO, ART
    indirizzo         VARCHAR(16)  NULL,            -- scope (NULL = admin-only view)
    classe            VARCHAR(16)  NULL,            -- scope (NULL = admin-only view)
    topic             VARCHAR(128) NOT NULL DEFAULT '',
    title             VARCHAR(255) NOT NULL,
    body_html         MEDIUMTEXT   NULL,
    metadata_json     JSON         NULL,
    visibility        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    shared_with_pool  TINYINT(1)   NOT NULL DEFAULT 0,
    source_content_id BIGINT UNSIGNED NULL,         -- audit trail clone (Phase 18)
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tc_teacher        (teacher_id, content_type),
    INDEX idx_tc_subject        (subject_code, content_type, visibility),
    INDEX idx_tc_section        (indirizzo, classe, subject_code, content_type, visibility),
    INDEX idx_tc_source         (source_content_id),
    CONSTRAINT fk_tc_teacher    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tc_source     FOREIGN KEY (source_content_id) REFERENCES teacher_content(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════
-- M12: institutes + teacher_institutes + teacher_access_credentials
-- ═════════════════════════════════════════════════════════════
-- Ogni docente lavora in N istituti (pivot teacher_institutes).
-- Ogni studente afferisce a 1 istituto (users.institute_id).
-- Ogni docente espone N coppie (username/password) per consentire
-- agli studenti di accedere alle proprie risorse: teacher_access_credentials.

CREATE TABLE IF NOT EXISTS institutes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(32)  NOT NULL UNIQUE,        -- es. ITIS-MAGGI-LECCO
    name        VARCHAR(255) NOT NULL,               -- es. ITIS Maggi - Lecco
    city        VARCHAR(128) NULL,
    region      VARCHAR(64)  NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inst_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pivot teacher ↔ istituti (un docente lavora in N istituti)
CREATE TABLE IF NOT EXISTS teacher_institutes (
    user_id      INT UNSIGNED NOT NULL,
    institute_id INT UNSIGNED NOT NULL,
    role_at_inst VARCHAR(64) NULL,                    -- opzionale: docente|tutor|...
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, institute_id),
    CONSTRAINT fk_ti_user FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
    CONSTRAINT fk_ti_inst FOREIGN KEY (institute_id) REFERENCES institutes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Studenti: associazione 1:1 con istituto (column su users).
-- Phase 20 — ALTER automatizzati via database/migrations/001_*.sql
-- Eseguire `php tools/migrate.php` post schema apply per garantire
-- coerenza colonne institute_id + FK.

-- ═════════════════════════════════════════════════════════════
-- M15: teacher_sidebar_sections — sezioni sidebar per-docente,
--      estensibili dinamicamente (n sezioni). Phase 15.
-- ═════════════════════════════════════════════════════════════
-- Default 6 sezioni per ogni nuovo docente (Mappe, Lab, Eser, Verif,
-- BES, RisDoc). Il docente può aggiungere/rimuovere/riordinare via UI.
-- Content-type determina quale API popola la sezione (teacher_content
-- filtrato per content_type) e la route /studio/... di destinazione.
CREATE TABLE IF NOT EXISTS teacher_sidebar_sections (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id    INT UNSIGNED NOT NULL,
    code          VARCHAR(32)  NOT NULL,              -- es. "mappe", "eser", oppure custom
    label         VARCHAR(128) NOT NULL,              -- UI label
    icon          VARCHAR(32)  NULL,                  -- emoji o class css
    content_type  VARCHAR(32)  NOT NULL,              -- mappa|esercizio|lab|verifica|custom
    color         VARCHAR(16)  NULL,                  -- hex per tinta sezione
    position      INT UNSIGNED NOT NULL DEFAULT 0,    -- ordine visualizzazione
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    is_default    TINYINT(1)   NOT NULL DEFAULT 0,    -- default seed (non rimovibile)
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tss_user_code (teacher_id, code),
    INDEX idx_tss_teacher_position (teacher_id, position),
    CONSTRAINT fk_tss_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════
-- M13: Super-Admin flag, privileged access log (append-only),
--      institute pool policy, teacher_content.shared_with_pool
-- ═════════════════════════════════════════════════════════════
-- Phase 20 — ALTER automatizzati via database/migrations/002-004_*.sql.
-- Eseguire `php tools/migrate.php` post schema apply:
--   002 → users.is_super_admin + promozione admin esistenti
--   003 → teacher_content.shared_with_pool
--   004 → teacher_content.source_content_id + FK self-ref

-- Append-only log per accessi privilegiati. Il ruolo DB dovrebbe avere
-- solo SELECT+INSERT su questa tabella; trigger BEFORE UPDATE/DELETE
-- bloccano mutazioni. Usato da PrivilegedAccessLogger.
--   100 → ip_address/user_agent sostituiti da ip_hash/ua_hash VARBINARY(32).
--         Qui resta la definizione di partenza: la migration converte le
--         righe e cambia le colonne anche su un'installazione nuova.
CREATE TABLE IF NOT EXISTS privileged_access_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NULL,                  -- actor (super-admin) se DB-known
    actor_name    VARCHAR(128) NOT NULL,              -- fallback username
    actor_role    VARCHAR(32)  NOT NULL,              -- admin|teacher|audit
    action        VARCHAR(64)  NOT NULL,              -- read|write|list|export
    resource_type VARCHAR(64)  NOT NULL,              -- user|material|pool|log|...
    resource_id   VARCHAR(128) NULL,
    reason        VARCHAR(255) NOT NULL,              -- motivazione obbligatoria
    ip_address    VARCHAR(64)  NULL,
    user_agent    VARCHAR(255) NULL,
    outcome       VARCHAR(32)  NOT NULL DEFAULT 'ok', -- ok|denied|error
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_actor    (user_id, created_at),
    INDEX idx_pal_resource (resource_type, resource_id),
    INDEX idx_pal_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 19 — rate_limits: storage DB per burst-window rate limiting.
-- Alternativa a $_SESSION (RateLimitMiddleware). Vantaggio: cross-session,
-- cross-tab, revocabile da admin, query-able per monitoring.
-- Pulizia giornaliera: pantedu-rate-limit-cleanup.timer (tools/systemd, dal
-- 23/9/2026) lancia tools/rate_limit_cleanup.php e toglie le righe più vecchie
-- di un'ora. Prima nessun cron lo lanciava, e gli IP restavano (A-84).
CREATE TABLE IF NOT EXISTS rate_limits (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket     VARCHAR(128) NOT NULL,              -- es. "write:77", "login:user"
    ts         INT UNSIGNED NOT NULL,              -- unix timestamp
    ip_address VARCHAR(64)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_bucket_ts (bucket, ts),
    INDEX idx_rl_ts        (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Policy pool d'istituto per condivisione materiali tra docenti dello stesso
-- istituto. `editor_role` (opzionale) può promuovere alcuni docenti a editor.
CREATE TABLE IF NOT EXISTS institute_pool_policy (
    institute_id  INT UNSIGNED NOT NULL PRIMARY KEY,
    pool_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
    editor_role   VARCHAR(32)  NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ipp_inst FOREIGN KEY (institute_id) REFERENCES institutes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════
-- M14: storage_objects — metadati indipendenti dal provider.
-- ═════════════════════════════════════════════════════════════
-- Un record per "oggetto" (file) gestito via StorageProvider. Il blob
-- vive nel provider (local/s3); qui stanno solo metadati per quota,
-- ACL, versioning logico. Chiave unica per (provider, key).
CREATE TABLE IF NOT EXISTS storage_objects (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider        VARCHAR(32)  NOT NULL,              -- local|s3|...
    storage_key     VARCHAR(512) NOT NULL,              -- key scheme: institutes/{id}/...
    checksum        VARCHAR(128) NOT NULL,              -- sha256 hex
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime            VARCHAR(128) NULL,
    visibility      ENUM('private','pool','public') NOT NULL DEFAULT 'private',
    owner_user_id   INT UNSIGNED NULL,
    institute_id    INT UNSIGNED NULL,
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_so_provider_key (provider, storage_key),
    INDEX idx_so_owner (owner_user_id),
    INDEX idx_so_inst  (institute_id),
    CONSTRAINT fk_so_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)      ON DELETE SET NULL,
    CONSTRAINT fk_so_inst  FOREIGN KEY (institute_id)  REFERENCES institutes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Docente espone N coppie credenziali per accesso studenti alle sue risorse
-- (username + bcrypt password hash). Lo studente le inserisce quando vuole
-- entrare nella sezione del docente; il match viene confrontato
-- coi propri privileges (institute + section) per scoping.
CREATE TABLE IF NOT EXISTS teacher_access_credentials (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id      INT UNSIGNED NOT NULL,
    label           VARCHAR(128) NOT NULL,              -- es. "Classe 3A 2025/26"
    access_username VARCHAR(64)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,              -- bcrypt
    indirizzo       VARCHAR(16)  NULL,                  -- scope opzionale
    classe          VARCHAR(16)  NULL,                  -- scope opzionale
    institute_id    INT UNSIGNED NULL,                  -- scope opzionale
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tac_user (teacher_id, access_username),
    INDEX idx_tac_active   (active, teacher_id),
    CONSTRAINT fk_tac_teacher FOREIGN KEY (teacher_id)   REFERENCES users(id)      ON DELETE CASCADE,
    CONSTRAINT fk_tac_inst    FOREIGN KEY (institute_id) REFERENCES institutes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 17 — DB-backed sessions (App\Core\DbSessionHandler)
-- =========================================================================
CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(128) PRIMARY KEY,
    data        LONGBLOB     NOT NULL,
    last_access INT UNSIGNED NOT NULL,
    ip          VARCHAR(45)  NULL,
    ua          VARCHAR(255) NULL,
    INDEX idx_sessions_last_access (last_access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 17 — Poor man's job queue (sync/async boundary per mail/pdf/svg)
-- =========================================================================
CREATE TABLE IF NOT EXISTS jobs (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    queue           VARCHAR(64)  NOT NULL DEFAULT 'default',
    handler         VARCHAR(128) NOT NULL,    -- es. "App\Jobs\SendMail"
    payload         JSON         NOT NULL,
    status          ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at    INT UNSIGNED NOT NULL,    -- unix ts: il worker può iniziare solo dopo
    reserved_at     INT UNSIGNED NULL,        -- locked dal worker
    reserved_by     VARCHAR(64)  NULL,        -- worker id/hostname
    completed_at    INT UNSIGNED NULL,
    last_error      TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_jobs_ready (queue, status, available_at),
    INDEX idx_jobs_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 17 — content_versions: append-only history dei contract JSON.
-- Ogni save() di ContractRepository archivia la version precedente qui.
-- Permette point-in-time recovery + audit trail "chi ha modificato cosa".
-- =========================================================================
CREATE TABLE IF NOT EXISTS content_versions (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    content_id      BIGINT UNSIGNED NOT NULL,
    version         INT UNSIGNED    NOT NULL,
    snapshot_json   LONGBLOB        NOT NULL,
    actor_user_id   INT UNSIGNED    NULL,
    actor_name      VARCHAR(64)     NULL,
    change_summary  VARCHAR(255)    NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cv_content (content_id, version),
    INDEX idx_cv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═════════════════════════════════════════════════════════════
-- M15: Risdoc/BES per-teacher overrides (Phase 21).
--      Replica btn3 (BES/DSA) e btn4 (Risorse docente) legacy con
--      overrides privati per docente su contenuto HTML/TeX/JSON/immagini.
--      Super-admin gestisce visibility + ownership.
-- ═════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS risdoc_templates (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(128) NOT NULL UNIQUE,        -- es. 'risdoc/MODELLI/0.0_Piano_annuale'
    origin            ENUM('risdoc','strcomp') NOT NULL,
    category          ENUM('MODELLI','RISORSE','STRCOMP','ALTRO') NOT NULL,
    num_arg           VARCHAR(32)  NOT NULL,               -- '0.0', '2.1'
    argomento         VARCHAR(255) NOT NULL,
    discipline        VARCHAR(16)  NULL,                   -- 'FIS'/'MAT'/null
    source_dir        VARCHAR(512) NOT NULL,               -- 'storage/templates/risdoc/MODELLI'
    html_file         VARCHAR(255) NOT NULL,
    tex_file          VARCHAR(255) NULL,
    css_file          VARCHAR(255) NULL,
    json_deps         JSON         NULL,                   -- paths dei JSON linkati
    source_hash       VARCHAR(64)  NOT NULL,               -- sha256 del bundle sorgenti
    logic_spec        LONGTEXT     NULL,                   -- guida HTML↔TeX (JSON)
    body_pt           LONGTEXT     NULL,                   -- Phase 24.50: PT AST seed (copiato in teacher_content.metadata.body_pt al pick)
    owner_id          INT UNSIGNED NULL,                   -- docente proprietario (null=globale)
    requires_password TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rt_origin_category (origin, category),
    INDEX idx_rt_owner (owner_id),
    CONSTRAINT fk_rt_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS risdoc_template_collaborators (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id   INT UNSIGNED NOT NULL,
    teacher_id    INT UNSIGNED NOT NULL,
    role          ENUM('collab') NOT NULL DEFAULT 'collab',
    invited_by    INT UNSIGNED NULL,
    invited_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rtc (template_id, teacher_id),
    INDEX idx_rtc_teacher (teacher_id),
    CONSTRAINT fk_rtc_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_rtc_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_rtc_inviter  FOREIGN KEY (invited_by)  REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS risdoc_template_visibility (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id   INT UNSIGNED NOT NULL,
    teacher_id    INT UNSIGNED NOT NULL,
    visible       TINYINT(1)   NOT NULL DEFAULT 1,
    granted_by    INT UNSIGNED NULL,
    granted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rtv (template_id, teacher_id),
    INDEX idx_rtv_teacher (teacher_id, visible),
    CONSTRAINT fk_rtv_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_rtv_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_rtv_granter  FOREIGN KEY (granted_by)  REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 24.55 — institutional overrides: layer admin-editabile sopra
-- ai source file su disco e sotto agli override per-teacher. Permette
-- al super-admin di modificare i template istituzionali via UI invece
-- che filesystem + rebuild manuale.
CREATE TABLE IF NOT EXISTS risdoc_institutional_overrides (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id    INT UNSIGNED NOT NULL,
    kind           ENUM('html','tex','css','json','image','texCommon','schema') NOT NULL,
    relative_path  VARCHAR(512) NOT NULL,
    body           LONGTEXT     NULL,
    image_hash     VARCHAR(64)  NULL,
    source_version VARCHAR(64)  NOT NULL,
    updated_by     INT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rio (template_id, kind, relative_path),
    INDEX idx_rio_template (template_id),
    CONSTRAINT fk_rio_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_rio_admin    FOREIGN KEY (updated_by)  REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 24.58 — multi-instance: stesso template forkabile in N istanze
-- distinte per docente (es. "Piano annuale 3A", "Piano annuale 4B").
-- instance_key = slug stabile (server-generated); instance_label = UI label.
-- Default '' (stringa vuota) = istanza "base/default" — backward compat.
CREATE TABLE IF NOT EXISTS risdoc_teacher_overrides (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT UNSIGNED NOT NULL,
    template_id    INT UNSIGNED NOT NULL,
    instance_key   VARCHAR(64)  NOT NULL DEFAULT '',
    instance_label VARCHAR(255) NULL,
    kind           ENUM('html','tex','css','json','image','texCommon','schema') NOT NULL,
    relative_path  VARCHAR(512) NOT NULL,              -- 'main.tex' | 'images/logo.png' | ...
    body           LONGTEXT     NULL,                  -- testo (null per kind='image')
    image_hash     VARCHAR(64)  NULL,                  -- sha256 file upload → storage/overrides/teacher_<id>/<hash>
    source_version VARCHAR(64)  NOT NULL,              -- hash sorgente al fork (drift detection)
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rto_instance (teacher_id, template_id, instance_key, kind, relative_path),
    INDEX idx_rto_teacher (teacher_id),
    INDEX idx_rto_template (template_id),
    CONSTRAINT fk_rto_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_rto_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
