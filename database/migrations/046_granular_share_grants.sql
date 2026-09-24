-- G22.S25 — Granularità share: oltre istituto, docenti specifici e gruppi.
--
-- Concetto:
--   - shared_with_pool=1 (legacy) RIMANE = share con istituto attivo (back-compat).
--   - content_shares (nuova): grants espliciti polimorfici a target diversi.
--   - share_groups: gruppi di docenti definiti dall'owner per share rapidi.
--
-- Eligibility pool browse = OR di:
--   (a) shared_with_pool=1 AND actor in istituto del row owner
--   (b) EXISTS content_shares con (target_type='institute', target_id IN actor.istituti)
--   (c) EXISTS content_shares con (target_type='teacher',   target_id = actor.id)
--   (d) EXISTS content_shares con (target_type='group',     target_id IN groups con actor membro)

CREATE TABLE IF NOT EXISTS share_groups (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL,
    description  VARCHAR(500) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sg_owner (owner_user_id),
    UNIQUE KEY uq_sg_owner_name (owner_user_id, name),
    CONSTRAINT fk_sg_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_group_members (
    group_id        INT UNSIGNED NOT NULL,
    member_user_id  INT UNSIGNED NOT NULL,
    added_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, member_user_id),
    INDEX idx_sgm_member (member_user_id),
    CONSTRAINT fk_sgm_group  FOREIGN KEY (group_id)       REFERENCES share_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_sgm_member FOREIGN KEY (member_user_id) REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_shares (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id  INT UNSIGNED NOT NULL,
    content_source ENUM('teacher_content','verifica_documents') NOT NULL,
    content_id     BIGINT UNSIGNED NOT NULL,
    target_type    ENUM('institute','teacher','group') NOT NULL,
    target_id      INT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cs_grant (content_source, content_id, target_type, target_id),
    INDEX idx_cs_owner (owner_user_id),
    INDEX idx_cs_target (target_type, target_id),
    INDEX idx_cs_content (content_source, content_id),
    CONSTRAINT fk_cs_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
