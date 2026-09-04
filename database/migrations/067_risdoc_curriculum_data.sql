-- ADR-025 (B) — obiettivi/competenze/abilità/conoscenze/programmi/minimi come
-- DATI ISTITUZIONALI dinamici (non più solo file statici).
--
-- I dataset options_source dei modelli risdoc (es. obiettivi_disciplinari_LG2010/
-- abilita) erano file statici in storage/templates/risdoc/{dataset}/{IIS}/{mat}/
-- {IIS}_{cls}_{mat}.json, con codici indirizzo legacy (LSc) rimappati a mano in JS.
-- Step A: rinominati ai codici canonici + mappe JS rese dinamiche.
-- Step B (questa migration): tabella per override per-ISTITUTO admin-editabili,
-- keyata sui codici curriculum CANONICI (dinamici, da curriculum_entries).
--
-- Resolver (endpoint /api/risdoc/curriculum-options):
--   1) row istituto del docente (institute_id = N)
--   2) row globale (institute_id = 0) — seed dai file statici
--   3) fallback file statico storage/templates/risdoc/...
-- I file restano come DEFAULT/seed (importati come righe globali institute_id=0).

CREATE TABLE IF NOT EXISTS risdoc_curriculum_data (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 0 = riga GLOBALE (default/seed). >0 = override specifico dell'istituto.
    institute_id  INT UNSIGNED NOT NULL DEFAULT 0,
    -- dataset = "cartella" options_source, es. 'obiettivi_disciplinari_LG2010/abilita',
    -- 'obiettivi_disciplinari_dipartimento/competenze', 'programmi_svolti'.
    dataset       VARCHAR(160) NOT NULL,
    indirizzo     VARCHAR(32)  NOT NULL,   -- codice canonico (SCI/ART/LIN/…)
    classe        VARCHAR(16)  NOT NULL,   -- forma short canonica ('2')
    materia       VARCHAR(32)  NOT NULL,   -- codice canonico UPPER (MAT/FIS/…)
    body          JSON         NOT NULL,   -- array opzioni [{value,label,group?,default?}]
    updated_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curr_data (institute_id, dataset, indirizzo, classe, materia),
    KEY idx_lookup (dataset, indirizzo, classe, materia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
