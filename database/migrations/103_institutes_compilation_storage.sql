-- 103 — Salvataggio delle compilazioni dei modelli istituzionali, per Istituto (2026-09-04)
--
-- I modelli della categoria `modelli` (piano annuale, relazione finale, schede
-- di progetto e di recupero) servono a redigere bozze di atti della scuola e,
-- per natura, parlano di studenti. Il Titolare della piattaforma puo' decidere
-- che per i docenti di un Istituto la compilazione non resti sul server: la
-- bozza vive nel browser del docente, si esporta il PDF, sul server resta il
-- solo modello. L'amministratore lo imposta per Istituto.
-- (2026-09-22: il commento attribuiva la scelta al DPO dell'Istituto; solo
-- commenti, lo schema non cambia. tests/Unit/IlCodiceNonAttribuisceAlDpoLeScelteTest.php)
-- Default 1: oggi le compilazioni si salvano, come sempre.
ALTER TABLE institutes
    ADD COLUMN compilation_storage TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = le compilazioni dei modelli istituzionali si salvano sul server; 0 = restano nel browser del docente';
