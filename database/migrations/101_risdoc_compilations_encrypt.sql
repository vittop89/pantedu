-- 101 — Compilazioni risdoc cifrate come i contenuti (2026-09-04).
--
-- `risdoc_compilations_data.data_json` conteneva in chiaro tutto cio' che il
-- docente scrive dentro un modello: campi, testo libero, e — per i modelli
-- sbagliati — nomi di studenti. I contenuti didattici sono cifrati a riposo
-- con la chiave del docente (body_pt_ct/iv/tag/kv, ADR-006); le compilazioni
-- no, e la DPIA parlava di "contenuti cifrati per docente" senza eccezioni.
--
-- Stesso envelope: AES-256-GCM con la KEK del docente, quattro colonne
-- accanto a `data_json`, che diventa NULL-abile e resta solo per le righe
-- legacy finche' `tools/gdpr/encrypt_risdoc_compilations.php` non le converte.
-- Il crypto-shredding alla cancellazione dell'account copre da ora anche le
-- compilazioni.
--
-- La view `risdoc_compilations` (migration 040) espande `rc.*` al momento
-- della creazione: senza ricrearla le colonne nuove non si vedrebbero.
--
-- Rollback: ALTER TABLE risdoc_compilations_data DROP COLUMN data_ct, DROP
-- COLUMN data_iv, DROP COLUMN data_tag, DROP COLUMN data_kv; — solo se nessuna
-- riga e' stata convertita (il plaintext delle righe convertite non esiste piu').

ALTER TABLE risdoc_compilations_data
    ADD COLUMN IF NOT EXISTS data_ct  MEDIUMBLOB        NULL AFTER data_json,
    ADD COLUMN IF NOT EXISTS data_iv  VARBINARY(12)     NULL AFTER data_ct,
    ADD COLUMN IF NOT EXISTS data_tag VARBINARY(16)     NULL AFTER data_iv,
    ADD COLUMN IF NOT EXISTS data_kv  SMALLINT UNSIGNED NULL AFTER data_tag,
    MODIFY COLUMN data_json LONGTEXT NULL;

CREATE OR REPLACE VIEW risdoc_compilations AS
SELECT rc.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM risdoc_compilations_data rc
LEFT JOIN curriculum_entries ci ON ci.id = rc.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = rc.classe_id    AND cc.kind='classi';
