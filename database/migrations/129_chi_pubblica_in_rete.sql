-- SICUREZZA: aggiunge a `users` il docente i cui contenuti pubblicati sono visibili senza login, e un indice che ne ammette uno solo. Sceglie il primo super-amministratore docente attivo, se c'è: è l'account che la regola di prima rendeva pubblico, quindi nessun contenuto diventa pubblico che non lo fosse già. Dove non c'è (in produzione dal 4/9/2026), nessuno è scelto e senza login resta solo l'accesso.
-- ROLLBACK: ALTER TABLE users DROP INDEX uq_users_pubblica_in_rete; ALTER TABLE users DROP COLUMN pubblica_in_rete_unica, DROP COLUMN pubblica_in_rete. La scelta si perde; il codice di prima torna alla regola del super-amministratore docente.
--
-- Chi pubblica in rete (15/9/2026, scelta dell'utente).
--
-- I contenuti visibili senza login erano per regola quelli dell'account che è
-- insieme docente e super-amministratore. Il 4/9/2026 il flag di
-- super-amministratore è stato tolto al docente e tenuto dall'account di
-- amministrazione, che non è docente: da allora quell'account non esiste, e la
-- home pubblica mostrava sezioni vuote (misurato: «Mappe» e «BES/DSA» pubbliche,
-- selettori vuoti).
--
-- Adesso chi pubblica in rete si sceglie in /admin/sidebar-config, separato dal
-- super-amministratore; senza scelta non c'è niente in rete, e la barra dei
-- visitatori mostra solo l'accesso. `pubblica_in_rete_unica` vale 1 per il
-- docente scelto e NULL per tutti gli altri: l'indice unico ammette infiniti
-- NULL e un solo 1, quindi due docenti scelti insieme il database li rifiuta.

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS pubblica_in_rete TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pubblica_in_rete_unica TINYINT(1)
        AS (IF(pubblica_in_rete = 1, 1, NULL)) PERSISTENT;

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_pubblica_in_rete ON users (pubblica_in_rete_unica);

-- La regola di prima, resa una scelta: solo se nessuno è già scelto.
UPDATE users
   SET pubblica_in_rete = 1
 WHERE id = (SELECT id FROM (
                SELECT id FROM users
                 WHERE is_super_admin = 1 AND role = 'teacher' AND active = 1
                 ORDER BY id LIMIT 1
             ) AS primo)
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE pubblica_in_rete = 1) AS gia_scelto);
