-- G19.8 — Curriculum classi: short codes ("1"/"2"/"3"/"4"/"5") attivi,
-- legacy ("1s"/"2s"/.../"1b"/"2b"/...) disattivati.
--
-- Background: il dropdown sidebar mostra `curriculum_entries WHERE active=1`.
-- Pre-G19.8 i codici erano "1s"/"2s"/...; ora il dropdown deve mostrare
-- "1"/"2"/"3"/"4"/"5" senza il suffisso "s" (Standard) o "b" (Breve).
--
-- Strategia: i codici LEGACY vengono mantenuti nella tabella ma con
-- active=0 (non visibili nel dropdown). Il backend `ClsNormalizer::expand()`
-- li traduce comunque a runtime per le query DB (back-compat URL).

INSERT INTO curriculum_entries (kind, code, label, grp, active) VALUES
    ('classi', '1', 'Classe I',   '', 1),
    ('classi', '2', 'Classe II',  '', 1),
    ('classi', '3', 'Classe III', '', 1),
    ('classi', '4', 'Classe IV',  '', 1),
    ('classi', '5', 'Classe V',   '', 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    grp   = VALUES(grp),
    active = 1;

UPDATE curriculum_entries
   SET active = 0
 WHERE kind = 'classi'
   AND code IN ('1s', '2s', '3s', '4s', '5s', '1b', '2b', '3b', '4b');
