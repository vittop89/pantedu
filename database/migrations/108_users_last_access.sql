-- 108 — `users.last_access_at`: quando quell'account è stato usato l'ultima volta.
--
-- ── Perché serve ───────────────────────────────────────────────────────────
--
-- La retention dichiara che gli account **inattivi** da due anni vengono
-- anonimizzati, e `tools/gdpr/anonymize_expired.php` dovrebbe applicarla. Ma
-- questo dato non esisteva, e il codice aveva ripiegato su quello che c'era:
--
--     WHERE status <> 'anonymized'
--       AND (approved_at IS NULL OR approved_at < ?)
--       AND created_at < ?
--
-- `approved_at < cutoff` non vuol dire «inattivo da due anni»: vuol dire
-- «approvato più di due anni fa». Per un docente che entra ogni giorno è vero.
-- Con la retention accesa, quel giro avrebbe anonimizzato l'account di chi
-- stava usando il sistema — email sostituita, nome e cognome svuotati,
-- password azzerata, `active = 0`.
--
-- Non è mai successo per due motivi che non c'entrano niente con la
-- correttezza: il timer non è mai partito, e `GDPR_RETENTION_ENABLED` non era
-- valorizzata, quindi lo script girava in simulazione. Il conto alla rovescia
-- però era vero: l'account più vecchio compiva 730 giorni a metà gennaio 2027.
--
-- ── Il valore iniziale ─────────────────────────────────────────────────────
--
-- `NULL` significa «non lo sappiamo»: nessun accesso registrato da quando
-- questa colonna esiste. La query della retention lo tratta come «non
-- anonimizzare», che è la scelta prudente — meglio tenere un account in più
-- che cancellare quello di qualcuno che sta lavorando.
--
-- Gli account si popolano da soli al primo accesso di ciascuno.

ALTER TABLE users
    ADD COLUMN last_access_at DATETIME NULL DEFAULT NULL
        COMMENT 'Ultimo accesso riuscito. NULL = mai registrato da quando esiste la colonna.'
        AFTER approved_at;

-- L'indice serve alla query della retention, che filtra su questa colonna
-- confrontandola con una data. Senza, ogni giro fa una scansione completa —
-- oggi irrilevante con cinque righe, meno se un giorno saranno diecimila.
CREATE INDEX idx_users_last_access ON users (last_access_at);
