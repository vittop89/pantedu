-- 074_drop_teacher_sidebar_sections.sql — ADR-027 Step 9
--
-- Deprecazione della tabella legacy teacher_sidebar_sections (Phase 15):
-- sostituita dal modello sidebar_sections (template istituto, 070) +
-- sidebar_section_overrides + UI /admin/sidebar-config.
--
-- Audit (DR-4) prod: 6 righe, TUTTE is_default=1, un solo docente, zero
-- personalizzazioni reali → nessun dato significativo da migrare (la riga
-- 'risorsa' è l'incoerenza nota content_type non in TYPES). API /api/teacher/
-- sidebar* rimosse (erano orfane, nessun consumer JS). Backup DB prod completo
-- preso prima della 073 (preview073_*.sql).
--
-- Idempotente.

DROP TABLE IF EXISTS teacher_sidebar_sections;
