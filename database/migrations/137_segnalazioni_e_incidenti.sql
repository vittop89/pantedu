-- 137 — una segnalazione di violazione non si dimentica (22/9/2026).
--
-- IL BUCO, scritto nel piano prima di essere chiuso.
--
--   `docs/privacy/data_breach_runbook.md`, sezione «Che cosa questo piano NON
--   ha», diceva: «Nessun collegamento automatico fra una segnalazione arrivata
--   dai moduli pubblici e il registro degli incidenti: l'incidente si apre a
--   mano. È un passaggio che si può dimenticare, ed è il punto più debole di
--   questo piano.»
--
--   Le due strade che possono rilevare il rischio R20 — un dato di studente
--   scritto in un campo a testo libero — sono proprio quelle pubbliche:
--   `/dpo-contact` con oggetto `breach_report`, e `/segnalazione-contenuti`
--   con categoria `gdpr_art9`. Nessun controllo automatico vede R20, quindi se
--   si perde quella segnalazione non se ne accorge più nessuno, e le
--   settantadue ore dell'art. 33 scorrono lo stesso.
--
-- PERCHE' NON SI CREA L'INCIDENTE DA SOLI
--
--   Sarebbe la mossa ovvia: una segnalazione arriva, il registro apre una
--   riga. Ma quei moduli sono **pubblici e senza autenticazione**, e dalla
--   migrazione 136 una riga del registro **non si cancella mai**. Aprirli alla
--   scrittura diretta vorrebbe dire che chiunque può riempire per sempre un
--   registro di accountability: il limite di tre invii all'ora non basta, e
--   la conservazione permanente — che è una garanzia — diventerebbe l'arma.
--
--   Si fa quindi il contrario: l'incidente lo apre una persona, e il sistema
--   **si accorge se non lo fa**. Questa colonna è il legame che permette di
--   accorgersene; l'invariante `segnalazioni` della diagnostica lo guarda due
--   volte al giorno e, quando manca da più di sei ore, fa fallire l'unità —
--   cioè manda l'avviso per la stessa strada di tutti gli altri guasti.
--
--   Il rumore arriva quindi entro dodici ore dalla segnalazione, e ne restano
--   sessanta delle settantadue.
--
-- SICUREZZA: due colonne nullable e due chiavi esterne. Il codice della
-- versione precedente non le conosce, gli INSERT elencano le colonne, e
-- nessun vincolo nuovo può essere violato da una riga esistente (tutte le
-- righe nascono con `incident_id` a NULL). Misurato prima di scriverla:
-- dpo_requests e takedown_requests non hanno nessuna colonna con questo nome.
--
-- ROLLBACK:
--     ALTER TABLE dpo_requests DROP FOREIGN KEY fk_dpo_incidente, DROP COLUMN incident_id;
--     ALTER TABLE takedown_requests DROP FOREIGN KEY fk_takedown_incidente, DROP COLUMN incident_id;
-- Toglierle cancella la prova di quali segnalazioni siano state valutate: si
-- torna indietro solo avendo messo al sicuro le righe.

ALTER TABLE dpo_requests
    ADD COLUMN IF NOT EXISTS incident_id INT UNSIGNED NULL
        COMMENT 'Incidente aperto da questa segnalazione (NULL = non ancora valutata)'
        AFTER dpo_notes;

ALTER TABLE dpo_requests
    ADD CONSTRAINT fk_dpo_incidente
        FOREIGN KEY IF NOT EXISTS (incident_id) REFERENCES data_breach_incidents(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE takedown_requests
    ADD COLUMN IF NOT EXISTS incident_id INT UNSIGNED NULL
        COMMENT 'Incidente aperto da questa segnalazione (NULL = non ancora valutata)'
        AFTER notified_at;

ALTER TABLE takedown_requests
    ADD CONSTRAINT fk_takedown_incidente
        FOREIGN KEY IF NOT EXISTS (incident_id) REFERENCES data_breach_incidents(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
