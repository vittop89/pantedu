-- SICUREZZA: la colonna si allarga (VARCHAR(45) → VARCHAR(64)), e il codice della versione precedente ci scrive ancora un IP, che ci sta. Si svuotano solo gli IP in chiaro delle segnalazioni già scritte: nessun codice li rilegge per decidere qualcosa, li mostrava solo la pagina di dettaglio dell'amministratore.
-- ROLLBACK: la colonna larga va bene anche al codice di prima; gli IP svuotati non tornano, e non devono: erano in chiaro senza termine.
--
-- Le segnalazioni di rimozione non tengono più l'IP in chiaro (24/9/2026).
--
-- PERCHÉ
--
-- `takedown_requests.submitter_ip` conservava l'IP di chi segnalava, in chiaro,
-- per i cinque anni della segnalazione, e lo mandava anche nell'email
-- all'amministratore. La rilettura dei documenti legali prima dell'invio al
-- DPO l'ha trovato senza un termine e senza una ragione: per ricostruire un
-- abuso ripetuto basta riconoscere lo stesso indirizzo, non leggerlo.
--
-- Dal 24/9/2026 il codice scrive in `submitter_ip` l'impronta con chiave
-- dell'IP (App\Support\ImprontaIp, HMAC-SHA256, 64 caratteri esadecimali),
-- come gli altri registri. La colonna si riusa: il nome resta, cambia che cosa
-- contiene, e il commento qui sotto lo dice.
--
-- CHE COSA FA QUI
--
-- Allarga la colonna a 64 caratteri e svuota gli IP in chiaro delle righe
-- scritte prima: calcolarne qui l'impronta non si può, perché la chiave sta
-- nell'ambiente dell'applicazione e non nel database. Una riga che ha già
-- un'impronta (64 caratteri esadecimali) resta com'è: la migrazione si può
-- ripetere.

ALTER TABLE takedown_requests
    MODIFY submitter_ip VARCHAR(64) DEFAULT NULL
        COMMENT 'impronta con chiave dell IP (ImprontaIp), non l IP';

UPDATE takedown_requests
   SET submitter_ip = NULL
 WHERE submitter_ip IS NOT NULL
   AND submitter_ip NOT REGEXP '^[0-9a-f]{64}$';
