-- 006 — Risdoc/BES per-teacher overrides (Phase 21).
--
-- Nuova feature: replica btn3 (BES/DSA) + btn4 (Risorse docente) legacy
-- ma con la possibilità per ogni docente di personalizzare il CONTENUTO
-- (testo HTML, TeX, JSON, immagini) senza toccare la struttura/logica.
--
-- Tabelle:
--   risdoc_templates             → catalogo unified di risdoc + strcomp
--   risdoc_template_collaborators→ owner + collab (multi-edit per-template)
--   risdoc_template_visibility   → super-admin decide chi vede cosa
--   risdoc_teacher_overrides     → fork privato per-docente del contenuto
--
-- Idempotente: CREATE TABLE IF NOT EXISTS + SHOW INDEX prima di ADD INDEX.

CREATE TABLE IF NOT EXISTS risdoc_templates (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(128) NOT NULL UNIQUE,
    origin            ENUM('risdoc','strcomp') NOT NULL,
    category          ENUM('MODELLI','RISORSE','STRCOMP','ALTRO') NOT NULL,
    num_arg           VARCHAR(32)  NOT NULL,
    argomento         VARCHAR(255) NOT NULL,
    discipline        VARCHAR(16)  NULL,
    source_dir        VARCHAR(512) NOT NULL,
    html_file         VARCHAR(255) NOT NULL,
    tex_file          VARCHAR(255) NULL,
    css_file          VARCHAR(255) NULL,
    json_deps         JSON         NULL,
    source_hash       VARCHAR(64)  NOT NULL,
    logic_spec        LONGTEXT     NULL,
    owner_id          INT UNSIGNED NULL,
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

CREATE TABLE IF NOT EXISTS risdoc_teacher_overrides (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT UNSIGNED NOT NULL,
    template_id    INT UNSIGNED NOT NULL,
    kind           ENUM('html','tex','css','json','image') NOT NULL,
    relative_path  VARCHAR(512) NOT NULL,
    body           LONGTEXT     NULL,
    image_hash     VARCHAR(64)  NULL,
    source_version VARCHAR(64)  NOT NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rto (teacher_id, template_id, kind, relative_path),
    INDEX idx_rto_teacher (teacher_id),
    INDEX idx_rto_template (template_id),
    CONSTRAINT fk_rto_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_rto_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
