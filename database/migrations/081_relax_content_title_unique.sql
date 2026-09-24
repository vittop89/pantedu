-- 081_relax_content_title_unique.sql — Phase 24.74
-- Rilassa uq_teach_content_title: PRIMA (teacher_id, content_subtype, title)
-- impediva a un docente di avere due documenti con lo STESSO TITOLO anche in
-- classi/sezioni/argomenti diversi (peggiorato dal collasso content_type 078:
-- bes/risdoc/didattica → document). Ora la chiave include section_id + topic →
-- stesso titolo riusabile in sezioni/topic diversi, ma resta bloccato il vero
-- doppione (stessa sezione + stesso topic + stesso titolo).
--
-- Sicuro SENZA dedup: la nuova chiave è un SOVRAINSIEME della vecchia (più
-- colonne ⇒ più permissiva) → nessuna riga valida sotto la vecchia può violare
-- la nuova. La VIEW teacher_content (SELECT tc.*) non è toccata dagli indici.
-- Idempotente. NB: section_id NULL (contenuti legacy) ⇒ NULL distinti in chiave
-- unica → i legacy possono ripetere il titolo liberamente (accettabile).

ALTER TABLE teacher_content_data DROP INDEX IF EXISTS uq_teach_content_title;
ALTER TABLE teacher_content_data
    ADD UNIQUE KEY IF NOT EXISTS uq_teach_content_title
    (teacher_id, content_subtype, section_id, topic, title);
