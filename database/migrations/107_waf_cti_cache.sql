-- 107 — cache delle interrogazioni CrowdSec CTI, per indirizzo.
--
-- La chiave CTI del piano gratuito ha una quota giornaliera, e l'endpoint che
-- serve qui è `/v2/smoke/{ip}`: risponde su UN indirizzo per volta, quando
-- l'amministratore lo chiede guardando il pannello. Senza cache, riaprire la
-- stessa pagina brucerebbe la quota su indirizzi già visti.
--
-- Perché una tabella e non un file: l'informazione è per-indirizzo e va letta
-- accanto alle altre righe del WAF, che stanno già nel database. E perché la
-- scadenza si esprime in SQL invece che in codice.
--
-- Non serve `-- SICUREZZA:` / `-- ROLLBACK:` (regola dalla 107 in avanti):
-- questa migrazione aggiunge e non toglie niente. Per annullarla basta
-- `DROP TABLE waf_cti_cache`, e non si perde nulla che non si possa richiedere
-- di nuovo.

CREATE TABLE IF NOT EXISTS waf_cti_cache (
    ip           VARCHAR(45)  NOT NULL PRIMARY KEY,
    -- La risposta come è arrivata, così se domani serve un campo in più non
    -- bisogna interrogare di nuovo: c'è già.
    payload      LONGTEXT     NOT NULL,
    -- `ok` distingue «l'abbiamo chiesto e la risposta è questa» da «l'abbiamo
    -- chiesto e non è andata»: senza, un errore verrebbe ritentato a ogni
    -- apertura della pagina, che è il modo più veloce di finire la quota.
    ok           TINYINT(1)   NOT NULL DEFAULT 1,
    fetched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cti_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
