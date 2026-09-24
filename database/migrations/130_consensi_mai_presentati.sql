-- SICUREZZA: revoca i consensi ad «analytics» e «marketing» ancora attivi. Il banner dei cookie non ha mai presentato queste due categorie: li registrava da solo, per gli utenti autenticati che sceglievano «Accetta tutti». Un consenso a una categoria che non è stata mostrata non è un consenso (art. 4, n. 11 e art. 7 GDPR). Nessuna riga si cancella: si valorizza revoked_at, si annota il motivo e si scrive l'evento in consent_audit.
-- ROLLBACK: UPDATE consents SET revoked_at = NULL WHERE notes LIKE '%migrazione 130%'; le righe di consent_audit restano (append-only). Un ritorno va motivato: quelle categorie non erano presentate.
--
-- I consensi mai presentati (15/9/2026).
--
-- Il banner mostrava due categorie, «strettamente necessari» e «funzionali»;
-- il JavaScript mandava però a /me/consents/grant anche «analytics» e
-- «marketing», con il valore di interruttori che nella pagina non esistevano
-- («Accetta tutti» li metteva a vero). Nessun codice leggeva quei consensi.
-- In produzione, misurato il 15/9/2026: uno di ciascuno attivo, dello stesso
-- utente, del 19/5/2026. Il banner è stato tolto nello stesso giorno.

SET NAMES utf8mb4;

INSERT INTO consent_audit (consent_id, user_id, consent_type, event, text_version)
SELECT id, user_id, consent_type, 'revoked', text_version
  FROM consents
 WHERE consent_type IN ('analytics', 'marketing') AND revoked_at IS NULL;

UPDATE consents
   SET revoked_at = NOW(),
       notes = LEFT(CONCAT_WS(' · ', NULLIF(notes, ''), 'Revocato dalla migrazione 130: categoria mai presentata dal banner'), 512)
 WHERE consent_type IN ('analytics', 'marketing') AND revoked_at IS NULL;
