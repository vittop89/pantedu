-- 140 — un codice dell'app di autenticazione vale una volta sola (23/9/2026).
--
-- PERCHE'
--
-- `TotpService::verifyCode` accetta il codice del passo di trenta secondi
-- corrente e dei due vicini (tolleranza per l'orologio del telefono), e fino a
-- oggi non ricordava quale codice avesse gia' accettato. Lo stesso codice
-- apriva quindi l'accesso per circa novanta secondi, quante volte si voleva:
-- chi lo leggeva da sopra una spalla, o da una pagina di phishing che lo
-- inoltrava, entrava con il codice gia' usato dall'utente. RFC 6238 §5.2 lo
-- vieta: il verificatore non deve accettare due volte lo stesso codice. Il
-- limite dei tentativi non aiuta, perche' il codice riusato e' giusto al primo
-- colpo. (Revisione architetturale 2026-09, rilievo A-65.)
--
-- LA COLONNA
--
--   `totp_last_counter`  il passo (secondi Unix diviso trenta) dell'ultimo
--                        codice accettato. Si accetta un codice solo se il suo
--                        passo e' STRETTAMENTE maggiore, con un UPDATE
--                        condizionato che fa da confronto e scrittura insieme
--                        (TwoFactorPolicy::verifyTotp): due richieste parallele
--                        con lo stesso codice non passano tutte e due.
--                        NULL finche' non si e' accettato niente.
--
-- La regola vive nel codice, non qui: qui c'e' solo la colonna.
--
-- Solo aggiunta, e idempotente: il codice della versione precedente non la
-- legge e non ne risente.
--
-- Rollback: ALTER TABLE users DROP COLUMN totp_last_counter;
-- Non si perde niente: al primo accesso con l'app il contatore riparte.

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_last_counter BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Passo TOTP (unix/30) dell ultimo codice accettato: vale una volta sola.'
        AFTER totp_enrolled_at;
