-- 102 — Via il "Modulo di autorizzazione (genitore/tutore)" (2026-09-04).
--
-- Era il modulo con cui, nel modello vecchio, un genitore autorizzava un
-- minorenne a ricevere credenziali nominative per la sezione ESERCIZI: campi
-- per nome, cognome e data di nascita dello studente e dati del genitore.
-- Compilato e salvato dentro la piattaforma, metteva dati di studenti nel
-- database — esattamente cio' che i Termini (§2.4) vietano e che la nota al
-- DPO dichiara di non trattare. Con la credenziale di classe, non nominativa,
-- il modulo non ha piu' ragione di esistere.
--
-- La cancellazione della riga propaga in cascata (FK ON DELETE CASCADE) a
-- compilazioni, override, visibilita', collaboratori e revisioni del modello:
-- e' voluto, quelle compilazioni sono i dati che non devono restare.
-- I file schema (schemas/risdoc/autorizzazione.json e il seed PT) sono
-- rimossi dal repository nello stesso commit, cosi' il sync dei seed non
-- puo' ricrearlo.
--
-- Rollback: nessuno (i dati cancellati non vanno ripristinati).

DELETE FROM risdoc_templates
 WHERE schema_path = 'schemas/risdoc/autorizzazione.json'
    OR LOWER(code) LIKE '%autorizzazione%';
