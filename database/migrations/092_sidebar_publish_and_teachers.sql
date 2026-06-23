-- Migration 092 — ADR-027 estensione sidebar: pubblicazione pubblica + assegnazione docenti.
--
-- WS4:
--  - publish_public: la sezione è esposta in rete SENZA login (guscio sel-wrapper
--    senza istituto), via GET /public/sidebar/{section_key}.
--  - sidebar_section_teachers: assegna una sezione a docenti SPECIFICI (allowlist).
--    Se per una sezione esistono assegnazioni, la vedono solo i docenti elencati;
--    nessuna assegnazione = tutti i docenti (default, retrocompat).

ALTER TABLE sidebar_sections
    ADD COLUMN publish_public TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_roles;

CREATE TABLE IF NOT EXISTS sidebar_section_teachers (
    section_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id, teacher_id),
    KEY idx_sst_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
