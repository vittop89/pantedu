-- 068 — risdoc_teacher_overrides.kind: aggiunge 'texCommon' e 'schema' all'ENUM.
--
-- BUG (modal TeX/PDF "Salva TEX" → HTTP 500): il salvataggio degli override
-- texCommon dei modelli (main.tex / risdoc.sty / intestaLAteX_IIS.tex via
-- TexFilesController::saveFiles → OverrideRepository::saveText con kind='texCommon')
-- falliva con "SQLSTATE[01000] 1265 Data truncated for column 'kind'".
--
-- Causa: la migration 006 creò la colonna come
--   ENUM('html','tex','css','json','image')
-- e NESSUNA migration successiva la estese sul DB live. Solo schema.sql
-- (install puliti) include già 'texCommon','schema'. OverrideRepository::KINDS
-- e la tabella institutional (migration 010) li prevedono da tempo → la
-- tabella teacher era l'unica rimasta indietro.
--
-- Fix: MODIFY idempotente dell'ENUM (rieseguibile senza effetti collaterali).

SET @has_texcommon := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'risdoc_teacher_overrides'
      AND COLUMN_NAME  = 'kind'
      AND COLUMN_TYPE LIKE '%texCommon%'
);
SET @sql := IF(
    @has_texcommon = 0,
    'ALTER TABLE risdoc_teacher_overrides
        MODIFY kind ENUM(''html'',''tex'',''css'',''json'',''image'',''texCommon'',''schema'') NOT NULL',
    'SELECT "kind enum already includes texCommon" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
