-- 070_sidebar_sections.sql — ADR-027 Step 1
-- Sidebar dinamica: template per-istituto + override per-docente.
--
-- Scope (DR-1 = solo admin crea sezioni; il docente personalizza estetica/ordine):
--   sidebar_sections          → template (institute_id = 0 ⇒ default globale ereditato; NON NULL)
--   sidebar_section_overrides → personalizzazione per-docente (label/color/icon/position/active)
--
-- Additiva e reversibile (down = drop delle 2 tabelle). NON tocca
-- teacher_sidebar_sections (riconciliazione/deprecazione = step separato gateato).
--
-- I 6 seed default replicano 1:1 lo stato attuale di views/partials/sidebar.php:
--   - color = NULL ⇒ il colore continua a derivare dai token CSS esistenti
--     (--fm-c-sec-<key>) → render data-driven (Step 3) pixel-identico.
--   - risdoc: visible_roles SENZA "student" (replica if($isTeacher||$isAdmin)).
--
-- DB target: MariaDB 11.x (CHECK supportati; JSON = alias LONGTEXT + json_valid).
-- Idempotente: sentinel institute_id=0 (NOT NULL) → UNIQUE(institute_id,section_key)
-- morde sempre → ON DUPLICATE KEY UPDATE sicuro su re-run.

CREATE TABLE IF NOT EXISTS sidebar_sections (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institute_id          INT UNSIGNED NOT NULL DEFAULT 0,
    section_key           VARCHAR(32)  NOT NULL,
    label                 VARCHAR(128) NOT NULL,
    icon                  VARCHAR(32)  NULL,
    color                 VARCHAR(16)  NULL,
    color_border          VARCHAR(16)  NULL,
    position              INT UNSIGNED NOT NULL DEFAULT 0,
    loader_kind           ENUM('db','risdoc','mixed') NOT NULL DEFAULT 'db',
    group_mode            ENUM('subject','category')  NOT NULL DEFAULT 'subject',
    allowed_content_types LONGTEXT NOT NULL CHECK (json_valid(allowed_content_types)),
    default_content_type  VARCHAR(32) NOT NULL,
    origin                VARCHAR(32) NULL,
    default_categories    LONGTEXT NULL CHECK (default_categories IS NULL OR json_valid(default_categories)),
    custom_categories     TINYINT(1) NOT NULL DEFAULT 0,
    supports_fork         TINYINT(1) NOT NULL DEFAULT 0,
    visible_roles         LONGTEXT NOT NULL CHECK (json_valid(visible_roles)),
    active                TINYINT(1) NOT NULL DEFAULT 1,
    is_default            TINYINT(1) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ss_inst_key (institute_id, section_key),
    INDEX idx_ss_inst_pos (institute_id, position),
    CONSTRAINT chk_ss_color  CHECK (color IS NULL OR color RLIKE '^#[0-9a-fA-F]{3,8}$'),
    CONSTRAINT chk_ss_border CHECK (color_border IS NULL OR color_border RLIKE '^#[0-9a-fA-F]{3,8}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sidebar_section_overrides (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id    INT UNSIGNED NOT NULL,
    teacher_id    INT UNSIGNED NOT NULL,
    label         VARCHAR(128) NULL,
    color         VARCHAR(16)  NULL,
    icon          VARCHAR(32)  NULL,
    position      INT UNSIGNED NULL,
    active        TINYINT(1)   NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sso (section_id, teacher_id),
    INDEX idx_sso_teacher (teacher_id),
    CONSTRAINT chk_sso_color CHECK (color IS NULL OR color RLIKE '^#[0-9a-fA-F]{3,8}$'),
    CONSTRAINT fk_sso_sec     FOREIGN KEY (section_id) REFERENCES sidebar_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_sso_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sidebar_sections
  (institute_id, section_key, label, color, color_border, position,
   loader_kind, group_mode, allowed_content_types, default_content_type,
   origin, default_categories, custom_categories, supports_fork,
   visible_roles, is_default)
VALUES
 (0,'mappe', 'Mappe concettuali', NULL, NULL, 0,'db','subject',
   '["mappa"]','mappa',NULL,NULL,0,0,'["student","teacher","admin"]',1),
 (0,'lab',   'Laboratorio', NULL, NULL, 1,'db','subject',
   '["lab"]','lab',NULL,NULL,0,0,'["student","teacher","admin"]',1),
 (0,'eser',  'Esercizi', NULL, NULL, 2,'db','subject',
   '["esercizio"]','esercizio',NULL,NULL,0,0,'["student","teacher","admin"]',1),
 (0,'verif', 'Verifiche', NULL, NULL, 3,'db','category',
   '["verifica"]','verifica',NULL,'["VERIFICHE"]',1,0,'["student","teacher","admin"]',1),
 (0,'bes',   'BES/DSA - RECUPERI', NULL, NULL, 4,'risdoc','category',
   '["bes"]','bes','strcomp','["STRCOMP","ALTRO"]',1,1,'["student","teacher","admin"]',1),
 (0,'risdoc','Risorse docente (riservato)', NULL, NULL, 5,'risdoc','category',
   '["risdoc"]','risdoc','risdoc','["MODELLI","RISORSE"]',1,1,'["teacher","admin"]',1)
ON DUPLICATE KEY UPDATE
  label=VALUES(label), position=VALUES(position),
  loader_kind=VALUES(loader_kind), group_mode=VALUES(group_mode),
  allowed_content_types=VALUES(allowed_content_types), default_content_type=VALUES(default_content_type),
  origin=VALUES(origin), default_categories=VALUES(default_categories),
  custom_categories=VALUES(custom_categories), supports_fork=VALUES(supports_fork),
  visible_roles=VALUES(visible_roles), is_default=VALUES(is_default);
