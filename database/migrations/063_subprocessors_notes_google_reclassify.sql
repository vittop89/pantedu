-- Phase 25.R follow-up — Riclassifica Google da "sub-processor" a
-- "third-party service user-initiated" (flow OAuth opt-in del docente
-- verso il SUO Drive personale, non back-end pantedu).
--
-- Background giuridico (Art. 28 GDPR):
--   - Sub-processor = soggetto che processa dati personali PER CONTO DEL
--     titolare/responsabile principale.
--   - L'integrazione Drive pantedu funziona via OAuth: docente collega
--     il PROPRIO account Google, materiali finiscono nel SUO Drive.
--   - pantedu non sceglie dove/come Google processa, non ha account
--     Google centralizzato, non firma DPA con Google.
--   - Conseguenza: Google NON è sub-processor classico. Resta in lista per
--     trasparenza utente, ma la natura del rapporto va chiarita.
--
-- Modifiche:
--   1. ALTER TABLE: aggiunge colonna `notes` (text libero per spiegare
--      casi atipici come questo).
--   2. UPDATE riga Google con descrizione corretta + nota giuridica.
--
-- Rollback:
--   UPDATE subprocessors SET service_description='OAuth login + Google Drive integration (opt-in)', notes=NULL WHERE name='Google LLC';
--   ALTER TABLE subprocessors DROP COLUMN notes;
-- ═════════════════════════════════════════════════════════════════════════

ALTER TABLE subprocessors
    ADD COLUMN notes TEXT NULL
    COMMENT 'Note libere — casi atipici (es. Google opt-in non sub-processor)'
    AFTER contact_email;

UPDATE subprocessors
   SET service_description = 'Identity Provider OAuth (login opzionale, opt-in del docente)',
       notes = 'NON sub-processor di pantedu in senso stretto. Il flow attiva integrazione tra il SINGOLO docente e il SUO account Google Drive personale: i materiali sincronizzati finiscono nel Drive del docente, non in un backend pantedu. Si applica direttamente la Privacy Policy + ToS di Google al rapporto docente-Google. pantedu funge solo da middleware OAuth (riceve token, scrive in Drive del docente con scope drive.file). DPA pantedu-Google non richiesto. Voce mantenuta in lista per trasparenza informativa (Art. 13 GDPR §1(f) + §2(a)).'
 WHERE name = 'Google LLC';
