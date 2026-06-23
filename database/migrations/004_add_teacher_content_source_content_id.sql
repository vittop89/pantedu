-- Phase 20 — Migration 004 — teacher_content.source_content_id
-- Audit trail clone (Phase 18): FK self-referencing per risalire
-- alla riga sorgente di un clone.

ALTER TABLE teacher_content
    ADD COLUMN source_content_id BIGINT UNSIGNED NULL AFTER shared_with_pool;

ALTER TABLE teacher_content
    ADD INDEX idx_tc_source (source_content_id);

ALTER TABLE teacher_content
    ADD CONSTRAINT fk_tc_source
        FOREIGN KEY (source_content_id) REFERENCES teacher_content(id) ON DELETE SET NULL;
