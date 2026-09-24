---
tags:
  - documentazione/adr
date: 2026-06-13
tipo: adr
status: accettato
aliases: ["ADR-031"]
cssclasses: []
---

# ADR-031 — Formule nelle tabelle (mini-Excel)

**Stato:** Accettato e in produzione (2026-06-13)
**Contesto:** estende le tabelle PT (ptTable) con calcoli automatici; si compone con [[decisions/ADR-030-document-terna-scoped-values]] (valori per terna).

## Decisione

Le celle di una tabella possono contenere una **formula** stile Excel: `cell.formula` (stringa che inizia con `=`). La cella mostra il **risultato calcolato** (read-only); la formula si modifica dal popover ⚙ (tipo cella "∑ Formula").

### Sintassi
- Riferimenti **A1**: colonna = lettere (A, B, …, AA), riga = numero 1-based. Range `A1:B3`.
- Operatori `+ - * / ^`, unari, confronti `= <> < > <= >=` (ritornano 1/0),
  concatenazione testo `&` (precedenza: sotto `+ -`, sopra i confronti — come
  Excel). La negazione unaria lega più stretto di `^`: `=-2^2` vale `4`
  (`(-2)^2`), non `-4`, come in Excel.
- Separatore argomenti `;` (Excel IT) o `,`. Decimali nei letterali: `.`.
- Funzioni (alias IT/EN): **SOMMA**/SUM, **MEDIA**/AVERAGE, **MEDIANA**/MEDIAN, MIN, MAX, **CONTA**/COUNT, **CONTA.SE**/COUNTIF, **SOMMA.SE**/SUMIF, **ARROTONDA**/ROUND, **ARROTONDA.PER.DIF**/ROUNDDOWN, **ARROTONDA.PER.ECC**/ROUNDUP, **SE**/IF, **SE.ERRORE**/IFERROR, **E**/AND, **O**/OR, **NON**/NOT, **RADQ**/SQRT, **POTENZA**/POWER, **RESTO**/MOD, **INTERO**/INT, ABS, **PRODOTTO**/PRODUCT, **TESTO**/TEXT (numero → stringa IT, decimali opzionali), **PERCENTUALE**/PERCENT (il numero è già la percentuale, non lo moltiplica per 100 — es. `=PERCENTUALE(14.2857)` → `"14,29%"`).
- CONTA.SE/SOMMA.SE accettano un criterio: numero (uguaglianza) o stringa `">10"`, `"<=5"`, `"<>0"`; SOMMA.SE ha un range-somma opzionale. Letterali stringa con `"..."`; nomi funzione puntati (CONTA.SE).
- Valori celle letti come numeri (IT: `7,5` → 7.5; `1.234,56` → 1234.56). Vuoto/non numerico = 0 (CONTA conta solo i numerici).
- Errori: `#DIV/0!`, `#REF!`, `#NAME?`, `#CIRC!`, `#VALUE!`, `#ERR!`.

### Motore
- `js/modules/risdoc/pt/formula-engine.js` — parser ricorsivo **sicuro (no eval)**: tokenizer → AST → valutatore. `computeTableValues(grid)` calcola tutte le celle formula con **ricalcolo memoizzato + rilevamento cicli** (computeCell ricorsivo con cycle-set).
- `app/Services/Risdoc/Pt/FormulaEngine.php` — **mirror** PHP identico (pattern ADR-030: JS per l'editor live, PHP per il render server/PDF). Parità garantita dalle prove automatiche, non da un controllo a occhio: vedi «Prove di parità» sotto.

### Prove di parità (JS ⇆ PHP)
Fino al 23/9/2026 questa riga diceva «parità verificata su 15+ casi» e «unit
test motore JS (17) + PHP (15) verdi»: nessuno dei due esisteva come file nel
repository (si può controllare con `git log -- '**FormulaEngine*'`) — il
guasto che la revisione architetturale del 23/9/2026 chiama «il verde che non
ha guardato» (rilievo A-20, voce 125 del registro del debito). I motori
risultavano comunque allineati quando finalmente misurati (54 casi, nessuna
divergenza), ma il rischio — editor e PDF che mostrano risultati diversi
sulla stessa tabella senza che nessun controllo se ne accorga — restava vero.

Le prove vere:
- `tests/fixtures/formule/*.json` — i casi condivisi: ogni file ha `{"casi":
  [...]}`, ogni caso una `griglia` (celle `{"raw": ...}` o `{"formula":
  "=..."}`, come le accetta `computeTableValues`), un `atteso` opzionale
  (`opzioni.decimals`) e un `atteso` (mappa `"riga,colonna" → {display,
  value, error}`, si controllano solo i campi presenti).
- `tests/Unit/Risdoc/Pt/FormulaEngineParitaTest.php` — legge tutti i file,
  chiama `FormulaEngine::computeTableValues` e confronta.
- `tests/js-unit/formula-engine-parita.test.js` — legge GLI STESSI file,
  chiama `computeTableValues` del modulo JS e confronta. Le due prove non si
  parlano fra loro: la parità è che concordano entrambe con la stessa lista
  di casi attesi, non un confronto diretto in un solo processo.

**Per aggiungere un caso:** una voce nuova in uno dei file di
`tests/fixtures/formule/` (o un file nuovo, il glob li prende tutti). Se un
caso fa divergere i due motori, la correzione non è nel caso: si decide quale
motore ha ragione — per il documento finale conta il PDF, quindi PHP, salvo
un motivo scritto per l'opposto — si corregge l'altro motore, e si scrive
perché nel commit.

La stessa logica vale per `TernaBinding` (ADR-030): `tests/fixtures/terna-binding/*.json`,
`tests/Unit/Risdoc/Pt/TernaBindingParitaTest.php`,
`tests/js-unit/terna-binding-parita.test.js`.

### Editor
- NodeView `ptTable`: prima del render calcola la griglia (`computeTableValues`) e ogni cella formula mostra il risultato; **ricalcolo automatico** ad ogni render (il NodeView ri-renderizza al cambio di un valore → live).
- Badge **riferimento A1** (es. "B2") in ogni cella in modifica.
- `cell.formula` preservata in `compactCell`/`normalizeCell` (roundtrip PT↔PM, come cid/binding).

### Render server (vista studente / PDF)
- `PtToHtml::renderTable` costruisce la griglia per indice di colonna (come l'editor) e calcola le formule (`FormulaEngine`) → mostra il risultato in `<span class="fm-pt-formula">`.

### Composizione con "Valori per classe" (ADR-030)
- Una cella formula **non è mai per-classe** (`cellIsLinked` → false): la formula è **struttura condivisa**.
- I valori referenziati (input/select/celle) sono **per-classe** se il doc è terna_scoped.
- Poiché `TernaBinding::applyAndStrip` applica i valori della terna **prima** del render, e l'editor applica la terna lente al load, **la stessa formula calcola sui valori della classe corrente → risultato per classe in automatico**. (Verificato live: stessa tabella, SCI/2 Totale=24, SCI/3 Totale=40.)

## Conseguenze
- ✅ Calcoli automatici (somme, medie, percentuali, condizioni) nelle tabelle, lato editor e lato PDF/HTML.
- ✅ Si compone con il per-classe senza codice dedicato.
- ✅ Nessun `eval`: parser proprio → sicuro.
- Celle **unite**: i riferimenti puntano all'ancora (cella in alto-a-sinistra con colspan/rowspan); le celle coperte valgono 0 (come in Excel). L'indice di colonna A1 segue i badge mostrati in modifica.
- ⚠️ v1: niente riferimenti tra tabelle diverse, niente funzioni di data; ampliabile aggiungendo funzioni al registro (un punto, JS+PHP).

## File
- `js/modules/risdoc/pt/formula-engine.js`, `app/Services/Risdoc/Pt/FormulaEngine.php` (motori).
- `js/modules/risdoc/pt/pm-schema.js` (NodeView ptTable, popover config, badge A1, render risultato).
- `js/modules/risdoc/pt/terna-binding.js` + `app/Services/Risdoc/Pt/TernaBinding.php` (esclusione formula dal per-classe).
- `app/Services/Risdoc/Pt/PtToHtml.php` (render server).
- `js/components/risdoc/fm-risdoc-pt-editor.js` (CSS `.pt-table-cell-formula` / `--err` / `.pt-table-cell-ref`).
- `tests/fixtures/formule/*.json`, `tests/Unit/Risdoc/Pt/FormulaEngineParitaTest.php`, `tests/js-unit/formula-engine-parita.test.js` (prove di parità, vedi sopra).

## Verifica
Le prove di parità (`FormulaEngineParitaTest.php` + `formula-engine-parita.test.js`,
54 casi al 23/9/2026) girano nel cancello PHPUnit/`npm run ci` di ogni ramo:
una divergenza fra i due motori — introdotta a mano per misurarlo, per
esempio cambiando un arrotondamento — fa fallire la prova sul lato mutato.
Live su pantedu.eu: calcolo, **ricalcolo live** al cambio input, errori,
render server, e **risultato per-classe** diverso su due classi.
