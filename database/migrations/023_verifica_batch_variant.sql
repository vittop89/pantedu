-- Phase G16 — Verifica batch generation (8 varianti A/B × {SOL,NOR,DSA,DIS}).
--
-- Aggiunge:
--   batch_id  ULID che raggruppa N verifica_documents creati nello stesso
--             saveBatch (es. tutte le 8 varianti di una verifica). Permette
--             ZIP scarico di tutto il batch + lista raggruppata in sidebar.
--
--   variant   Variante della verifica:
--             'A_SOL', 'A_NOR', 'A_DSA', 'A_DIS' (versione A)
--             'B_SOL', 'B_NOR', 'B_DSA', 'B_DIS' (versione B)
--             ''       per single-variant (back-compat, default).
--
-- Compat: i record esistenti hanno batch_id NULL e variant '' → si comportano
-- come prima. Non rompiamo niente.

ALTER TABLE verifica_documents
    ADD COLUMN batch_id  CHAR(26)     NULL AFTER fm_db_section,
    ADD COLUMN variant   VARCHAR(8)   NOT NULL DEFAULT '' AFTER batch_id,
    ADD INDEX idx_vd_batch (batch_id);
