-- 082_teacher_category_labels.sql — Phase 24.76
-- Persistenza DB (cross-dispositivo) delle rinomine di categoria PER-DOCENTE.
-- Prima vivevano solo in localStorage per-username (fm.sidepage.catLabels.*),
-- quindi legate al singolo browser. Ora il nome segue il docente ovunque.
-- localStorage resta come cache sincrona (idratata da qui). Idempotente.

CREATE TABLE IF NOT EXISTS teacher_category_labels (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teacher_id   INT UNSIGNED NOT NULL,
    category_key VARCHAR(64)  NOT NULL,
    label        VARCHAR(255) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teacher_cat (teacher_id, category_key),
    KEY idx_tcl_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
