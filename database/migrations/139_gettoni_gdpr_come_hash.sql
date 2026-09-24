-- SICUREZZA: nessuna struttura cambia. Si ritirano i gettoni di conferma rimasti in chiaro in parent_consents e deletion_requests: ogni riga scritta prima di questa migrazione riceve al posto del gettone il segnaposto «ritirato-<id>», e le richieste ancora da confermare passano a «expired», stato che il codice conosce già. Il codice di questa versione salva e cerca l'hash del gettone, e nessun hash può valere «ritirato-<id>». Misurato in produzione da chi coordina, il 23/9/2026: zero consensi dei genitori e zero cancellazioni in attesa di conferma, quindi nessuna richiesta viva ne è toccata.
-- ROLLBACK: non si torna ai gettoni in chiaro, e non serve: una richiesta ritirata si rifà da capo e genera un gettone nuovo. Con il codice della versione precedente le righe ritirate restano inerti (nessun gettone vale «ritirato-<id>») e quelle nuove, che tengono l'hash, non si confermano: anche lì si rifà la richiesta.
--
-- I gettoni di conferma si conservano come hash (23/9/2026, A-67 della
-- revisione architetturale, R-6 passo 4).
--
-- PERCHÉ
--
-- I gettoni di conferma della cancellazione (art. 17) e del consenso del
-- genitore (art. 8) stavano in chiaro in `confirm_token`. Chi leggeva il
-- database — un dump, una copia locale, un backup — poteva confermare una
-- cancellazione pendente, che dopo i trenta giorni di ripensamento porta al
-- crypto-shredding, oppure dare o rifiutare il consenso al posto del genitore.
-- Il recupero password e il cambio email salvavano già l'hash.
--
-- Dal 23/9/2026 il codice scrive in `confirm_token` lo SHA-256 esadecimale del
-- gettone, 64 caratteri come la colonna, con la stessa funzione di
-- `password_resets.token_hash`; il gettone in chiaro sta solo nel
-- collegamento. La colonna si riusa: un hash ci sta, e una colonna nuova
-- avrebbe chiesto di rendere nullable la vecchia e di toglierla in un
-- rilascio successivo, per arrivare allo stesso punto.
--
-- CHE COSA FA QUI
--
-- Le righe scritte prima tengono ancora il gettone in chiaro. Quelle in attesa
-- di conferma si fanno scadere: il loro gettone è in ogni backup fatto finora,
-- e trasformarlo in hash lo lascerebbe valido per chi lo legge da lì. A tutte,
-- in attesa o già chiuse, il gettone si sostituisce con «ritirato-<id>», che
-- non è un gettone e non è l'hash di niente: nel database non resta nessun
-- gettone in chiaro, e la sostituzione non si confonde con gli hash del
-- codice nuovo, che sono solo cifre esadecimali. `id` rende il valore unico,
-- come chiede `uniq_token`.
--
-- Un consenso del genitore che scade qui scade come quando scade da solo: lo
-- dice `consent_audit`, il registro che il DPO consulta, e il giro notturno
-- (`ParentConsentService::cleanupExpired`) cancella l'account del minore mai
-- attivato.
--
-- Idempotente: al secondo giro non trova più niente da ritirare, e l'evento
-- non si scrive due volte. Non va però rilanciata a mano su un database già in
-- uso con il codice nuovo: gli hash nuovi hanno la stessa forma dei gettoni
-- vecchi, e verrebbero ritirati anche loro. `schema_migrations` la esegue una
-- volta sola.
--
-- Una finestra da sapere: il rilascio esegue le migrazioni prima di far
-- partire il container nuovo (docker/entrypoint.sh, deploy-container.sh), e
-- fra le due il codice vecchio, ancora in servizio, può scrivere qualche
-- gettone in chiaro. Con il codice nuovo quei gettoni non valgono (si cerca
-- per hash), ma restano nella colonna e nei backup finché la richiesta non si
-- chiude. In produzione, il 23/9/2026, i due flussi non avevano richieste in
-- attesa.
--
-- Prova: tests/Integration/Gdpr/GettoniComeHashMigrazioneTest.php.

SET NAMES utf8mb4;

INSERT INTO consent_audit (consent_id, user_id, consent_type, event, accessed_at, ip_hash)
SELECT NULL, pc.student_user_id, 'parent_consent', 'expired', NOW(), NULL
  FROM parent_consents pc
 WHERE pc.status = 'pending'
   AND pc.confirm_token NOT LIKE 'ritirato-%'
   AND NOT EXISTS (SELECT 1 FROM consent_audit ca
                    WHERE ca.user_id = pc.student_user_id
                      AND ca.consent_type = 'parent_consent'
                      AND ca.event = 'expired'
                      AND ca.accessed_at >= pc.requested_at);

UPDATE parent_consents
   SET status = IF(status = 'pending', 'expired', status),
       confirm_token = CONCAT('ritirato-', id)
 WHERE confirm_token NOT LIKE 'ritirato-%';

UPDATE deletion_requests
   SET status = IF(status = 'pending_confirm', 'expired', status),
       confirm_token = CONCAT('ritirato-', id)
 WHERE confirm_token NOT LIKE 'ritirato-%';
