-- G22.S15.bis Fase 5+ — Drop tabelle legacy vuote senza references PHP.
--
-- Audit pre-drop:
--   - template_cache         : 0 rows (locale + VPS), zero references in
--                              app/Controllers, app/Services, app/Repositories.
--                              Già drop in migration 005 ma resuscitata da
--                              CREATE TABLE IF NOT EXISTS in schema.sql.
--   - teacher_recovery_audit : 0 rows (locale + VPS), feature scaffold mai
--                              utilizzata. La tabella aderente
--                              teacher_recovery_keys è anch'essa vuota ma
--                              tenuta come placeholder per futura feature
--                              recovery key (vedi migration 035).
--
-- Backup pre-migration:
--   - locale: storage/_backups/db_pre_unification_YYYYMMDD_HHMMSS.sql (4.4MB)
--   - VPS:    /root/db_backups/db_pre_unification_YYYYMMDD_HHMMSS.sql (3.9MB)
--
-- Tabelle NON droppate (rischio alto, richiedono full migration data):
--   - exercises (57 rows)         : legacy, ancora referenziato da
--                                    ExerciseController + repository
--   - verifica_documents (21 rows): legacy ma con dati storici, alcune
--                                    risorse ancora puntano qui
--   - teacher_exercises  (0 rows) : codice live INSERT/SELECT in
--                                    TeacherController + VerificaBuilderController
--   - teacher_verifiche  (0 rows) : idem (TeacherController, VerificaBuilderController,
--                                    TeacherPrintController)

DROP TABLE IF EXISTS template_cache;
DROP TABLE IF EXISTS teacher_recovery_audit;

-- Plus rimuovo le tabelle dal schema.sql canonico (vedere commit accanto:
-- aggiorno schema.sql per non ricreare al prossimo `tools/setup_db.php`).
