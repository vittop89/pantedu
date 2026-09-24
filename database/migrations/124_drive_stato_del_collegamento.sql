-- SICUREZZA: aggiunge tre colonne con un valore di partenza, che il codice di prima non legge; toglie solo righe di cache delle cartelle Drive di docenti che non hanno più un collegamento, righe che nessun percorso del codice può usare senza il collegamento.
-- ROLLBACK: nessuno necessario. Le colonne restano inerti per il codice di prima; la cache si ricostruisce da sola da Drive alla prima sincronizzazione.
--
-- Lo stato del collegamento a Drive di ogni docente (ADR-038, 14/9/2026).
--
-- Fino a oggi un collegamento c'era o non c'era. Quando Google lo rifiutava
-- (accesso revocato dall'account del docente, o scaduto), la riga restava
-- uguale a una buona: ogni notte il giro ci riprovava, falliva, e l'avviso
-- andava all'amministratore, che non può ricollegare al posto del docente.
--
-- Ora il collegamento ha uno stato:
--   - 'attivo';
--   - 'da_ricollegare', con il motivo (accesso_revocato,
--     permessi_insufficienti) e da quando.
-- Il giro salta chi è da ricollegare, il docente lo vede nel suo cruscotto,
-- e ricollegando torna attivo.
--
-- La cache delle cartelle (019) sopravviveva allo scollegamento: chi
-- ricollegava con un altro account Google si ritrovava gli identificativi
-- delle cartelle del primo, e ogni caricamento falliva. Da oggi lo
-- scollegamento e ogni nuovo collegamento la svuotano; qui si tolgono le
-- righe rimaste di chi è già scollegato (in produzione, il 14/9: 42 righe di
-- un docente senza collegamento).

ALTER TABLE teacher_drive_oauth
    ADD COLUMN IF NOT EXISTS stato ENUM('attivo', 'da_ricollegare') NOT NULL DEFAULT 'attivo' AFTER last_sync_at,
    ADD COLUMN IF NOT EXISTS stato_motivo VARCHAR(40) NULL AFTER stato,
    ADD COLUMN IF NOT EXISTS stato_dal DATETIME NULL AFTER stato_motivo;

DELETE c FROM teacher_drive_folder_cache c
    LEFT JOIN teacher_drive_oauth o ON o.teacher_id = c.teacher_id
    WHERE o.teacher_id IS NULL;
