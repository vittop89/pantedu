-- 007 — Risdoc per-teacher compilations.
--
-- Scope: salvare le ISTANZE compilate di un template risdoc (checkbox,
-- textarea, input valorizzati dal docente) legate al docente loggato,
-- in modo che lo stesso docente possa ritrovarle da qualunque browser.
--
-- Differenza con risdoc_teacher_overrides:
--   - overrides: markup del template (html/tex/css/json) — modifica struttura
--   - compilations: valori inseriti nel form (checkbox/textarea/input) —
--     istanze di compilazione (pieno di dati) legate a classe/sez/materia.
--
-- La chiave `compilation_key` è uno slug generato client-side dai campi
-- .dynamic-selector-container (classe, sezione, indirizzo, disciplina):
-- lo stesso docente con lo stesso contesto sovrascrive il proprio
-- salvataggio precedente (UPSERT). Il campo `label` preserva il nome
-- human-readable scelto dal docente al save.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS risdoc_compilations (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id      INT UNSIGNED NOT NULL,
    template_id     INT UNSIGNED NOT NULL,
    compilation_key VARCHAR(255) NOT NULL,
    label           VARCHAR(255) NOT NULL,
    -- Campi del .dynamic-selector-container, preservati per filtrare la
    -- lista al load (mostrare solo le compilazioni che matchano il
    -- contesto corrente) senza dover parsare il JSON.
    classe          VARCHAR(32)  NULL,
    sezione         VARCHAR(32)  NULL,
    indirizzo       VARCHAR(64)  NULL,
    disciplina      VARCHAR(64)  NULL,
    data_json       LONGTEXT     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rc (teacher_id, template_id, compilation_key),
    INDEX idx_rc_teacher (teacher_id),
    INDEX idx_rc_teacher_template (teacher_id, template_id),
    INDEX idx_rc_filter (teacher_id, template_id, classe, sezione, indirizzo, disciplina),
    CONSTRAINT fk_rc_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id)            ON DELETE CASCADE,
    CONSTRAINT fk_rc_template FOREIGN KEY (template_id) REFERENCES risdoc_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
