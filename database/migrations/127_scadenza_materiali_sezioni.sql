-- La data entro cui i docenti spostano i materiali rimasti su sezioni che non
-- possono più usare (ADR-041, 14/9/2026).
--
-- Quando un incarico si toglie, o la modalità delle sezioni si stringe, i
-- materiali del docente su quella sezione restano dove sono: la spunta si
-- sospende, il contenuto no. Il docente li sposta da «Sposta di classe»; chi
-- non lo fa, li sposta l'amministratore da «Sezioni e incarichi», a mano.
--
-- La data è solo un avviso: l'area docente la mostra accanto al numero dei
-- materiali da spostare. Non fa partire niente da sé: scelta dell'utente, «pulsante
-- a mano», perché uno spostamento automatico renderebbe definitivo anche un
-- incarico tolto per sbaglio.

SET NAMES utf8mb4;

ALTER TABLE institutes
    ADD COLUMN IF NOT EXISTS sezioni_scadenza DATE NULL DEFAULT NULL
        COMMENT 'ADR-041 — entro quando i docenti spostano i materiali da sezioni non ammesse (solo avviso)';
