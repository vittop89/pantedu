-- G22.S15.bis Fase 5+ — Drop tabelle M11 legacy (post deprecation codice).
--
-- Pre-condizione: codice M11 rimosso dal repo (commit precedente):
--   - app/Controllers/VerificaBuilderController.php (deleted)
--   - js/modules/features/verifica-builder.js (deleted)
--   - app/Controllers/TeacherController::verifiche/downloadVerifica/cloneExercise (removed)
--   - app/Controllers/TeacherPrintController::saveToDb → no-op stub
--   - js/modules/core/endpoints.js: rimossi verifiche + cloneExercise
--   - views/partials/_topbar_modern.php: rimosso #btnCopyver hidden bridge
--   - routes/web.php: rimossi 5 endpoint M11
--
-- Audit pre-drop:
--   - teacher_verifiche  : 0 rows locale + 0 rows VPS (mai usata in prod)
--   - teacher_exercises  : 0 rows locale + 0 rows VPS (mai usata in prod)
--
-- Backup pre-migration:
--   - locale: storage/_backups/db_pre_unification_20260509_120204.sql (4.4MB)
--   - VPS:    /root/db_backups/db_pre_unification_20260509_100917.sql (3.9MB)
--
-- NB: verifica_documents (21 rows VPS / 8 locale) NON droppata in questa
-- migration: ha FK su verifica_compile_jobs.doc_id, richiede migration
-- separata con cascade/orphan cleanup. Tracked in 039.

DROP TABLE IF EXISTS teacher_verifiche;
DROP TABLE IF EXISTS teacher_exercises;
