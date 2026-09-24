---
tags:
  - documentazione/adr
date: 2026-09-13
tipo: adr
status: accettato
aliases: ["ADR-036", "catalogo adozioni", "libri in adozione", "fonti dal catalogo"]
cssclasses: []
---

# ADR-036 — I libri in adozione sono un catalogo dell'istituto, e le fonti del docente ci attingono

## Stato

**ACCETTATO ED ESEGUITO** il 2026-09-13 nel ramo `feat/catalogo-istituto`
(migrazione 115), insieme alle fasi di [[decisions/ADR-035-catalogo-istituto-centralizzato]]
che condividono lo stesso dataset.

## Contesto

Il docente cita i libri da cui prende i quesiti: il **registro delle fonti**
(`institutes/{casa}/private/{tid}/sources.registry.json`, nella casa dei
file privati del docente, vedi [[glossary]]; pagina
`/area-docente/fonti`) è un elenco per docente di `{key, book, volume,
authors}`, scritto a mano, e la `key` finisce sul cartellino stampato accanto
al quesito. Funziona, ma ogni docente ricopia titolo, volume, editore e autori
dei libri che la scuola ha già scelto e pubblicato.

Il dataset MIUR delle adozioni, che l'amministratore carica dal pannello degli
istituti per ricavarne indirizzi, sezioni e materie
(`MiurAdozioniImporter`), porta **per ogni riga** anche `CODICEISBN`,
`TITOLO`, `SOTTOTITOLO`, `AUTORI`, `VOLUME`, `EDITORE`, `PREZZO` e le tre
spunte (nuova adozione, da acquistare, consigliato). L'importatore leggeva
quelle colonne e le buttava via.

## Decisione

1. **Una tabella per istituto**, `adozioni_libri`: anno scolastico, classe
   (anno più sezione, come nel dataset), corso e materia nelle sigle del
   vocabolario quando l'importatore le riconosce, disciplina nel testo del
   MIUR, ISBN, titolo, sottotitolo, autori, editore, volume, prezzo, le tre
   spunte, `origine` (`miur` o `istituto`). Unica su
   `(istituto, anno, classe, ISBN, disciplina)`: un secondo caricamento dello
   stesso file non scrive niente, e lo dice nel piano prima di applicare.
2. **L'importatore mette a catalogo i libri** nello stesso giro in cui ricava
   indirizzi e sezioni, e ne riporta il conto nell'anteprima e nell'esito. Un
   file senza `TITOLO` o `CODICEISBN` produce il vocabolario e nessun libro.
3. **Il docente vede i libri delle proprie classi e materie**
   (`GET /api/teacher/adozioni`): per default quelli delle classi e materie che
   ha spuntato nel catalogo, con la regola dei contenuti — «2» copre 2A e 2B,
   «2A» solo se stessa (`App\Domain\ClassCode::covers`) — e con «tutto
   l'istituto» per chi cerca un libro di una classe che non ha ancora
   attivato. Ogni riga porta la **proposta di fonte** già nella forma del
   registro (`key` dal titolo, `volume` come `Vol.N - EDITORE`, che è la forma
   che il cartellino separa) e dice se il libro è già fra le fonti.
4. **«Aggiungi» scrive nel registro** con lo stesso `PUT` di sempre: da lì in
   poi è una fonte come le altre, si modifica e si cancella dalla stessa
   tabella. La fonte porta con sé `isbn` ed `editore`, facoltativi, così la
   pagina la riconosce come già presa anche se il docente ne ritocca il
   titolo. Le fonti scritte a mano non cambiano in niente.
5. **L'amministratore aggiunge e toglie un libro a mano**
   (`POST /api/admin/adozioni`, `POST /api/admin/adozioni/{id}/delete`) per il
   libro adottato dopo la pubblicazione del dataset, con `origine =
   'istituto'`. È anche la strada con cui la suite end-to-end mette un libro a
   catalogo senza caricare un file da cinquanta megabyte.

## Conseguenze

- Il registro delle fonti resta per docente, su file: nessuna migrazione dei
  registri esistenti, nessun cambiamento per chi non usa il catalogo.
- Il catalogo è per istituto, come il vocabolario: nello scenario 1 lo carica
  chi gestisce l'istanza, nello scenario 3 la scuola (ADR-032).
- L'ISBN è un testo di 13 caratteri, non una chiave esterna: due scuole con
  lo stesso libro hanno due righe, ed è giusto così — sono due adozioni.
- Il prezzo si conserva ma non si mostra al docente: serve all'amministratore
  per il confronto con il dataset, non al cartellino.

## Verifica

- `tests/Integration/AdozioniLibriTest.php`: l'importatore mette a catalogo
  tre adozioni distinte da quattro righe e non le riscrive al secondo giro; il
  filtro segue la regola dei contenuti nei due versi; la proposta ha la forma
  che `SourcesRegistry::toLegacyDict` separa in volume ed editore.
- `tests/e2e/area-docente/fonti-dal-catalogo.spec.js`: l'amministratore
  mette a catalogo un libro per una classe del docente; dall'API c'è per
  quella classe, non per una che il docente non ha, e con «tutto l'istituto»
  c'è comunque; dalla pagina «Aggiungi» lo porta nel registro con ISBN,
  volume ed editore, e la tabella delle fonti si aggiorna da sola.

## Riferimenti

- [[decisions/ADR-035-catalogo-istituto-centralizzato]] — il vocabolario e le spunte
- [[decisions/ADR-032-deployment-scenarios]] — chi carica il catalogo nei tre scenari
- `database/migrations/115_adozioni_libri.sql`
- `app/Repositories/Curriculum/AdozioniRepository.php`,
  `app/Controllers/TeacherAdozioniController.php`,
  `app/Controllers/Admin/AdminAdozioniController.php`,
  `app/Support/Sources/SourcesRegistryStore.php`
