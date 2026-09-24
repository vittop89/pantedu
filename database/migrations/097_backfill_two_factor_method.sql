-- 097_backfill_two_factor_method.sql
-- Allinea `users.two_factor_method` per le iscrizioni fatte via app.
--
-- PERCHE' (2026-09-02)
--
-- La migration 096 aveva riempito la colonna per le righe preesistenti, ma
-- `TotpController::enable()` non la scriveva: le iscrizioni completate DOPO la
-- 096 e prima della correzione restano con il valore nullo.
--
-- Non e' un guasto in atto — `TwoFactorPolicy::methodFor()` assume 'app'
-- quando il valore manca, ripiego che serve alle iscrizioni anteriori alla
-- 096 — ma lasciare righe incoerenti significa che il ripiego smette di essere
-- una compatibilita' e diventa il modo normale di funzionare, cioe' qualcosa
-- su cui nessuno si accorgera' piu' di un errore.
--
-- Idempotente: tocca solo le righe con secondo fattore attivo e metodo nullo.
-- Chi usa il metodo email ha gia' il valore scritto da `enableEmail()` e non
-- viene toccato.

SET NAMES utf8mb4;

UPDATE users
   SET two_factor_method = 'app'
 WHERE totp_enabled = 1
   AND two_factor_method IS NULL;
