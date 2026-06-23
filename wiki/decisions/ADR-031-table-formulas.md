# ADR-031 — Formule nelle tabelle (mini-Excel)

**Stato:** Accettato e in produzione (2026-06-13)
**Contesto:** estende le tabelle PT (ptTable) con calcoli automatici; si compone con [[ADR-030]] (valori per terna).

## Decisione

Le celle di una tabella possono contenere una **formula** stile Excel: `cell.formula` (stringa che inizia con `=`). La cella mostra il **risultato calcolato** (read-only); la formula si modifica dal popover ⚙ (tipo cella "∑ Formula").

### Sintassi
- Riferimenti **A1**: colonna = lettere (A, B, …, AA), riga = numero 1-based. Range `A1:B3`.
- Operatori `+ - * / ^`, unari, confronti `= <> < > <= >=` (ritornano 1/0).
- Separatore argomenti `;` (Excel IT) o `,`. Decimali nei letterali: `.`.
- Funzioni (alias IT/EN): **SOMMA**/SUM, **MEDIA**/AVERAGE, **MEDIANA**/MEDIAN, MIN, MAX, **CONTA**/COUNT, **CONTA.SE**/COUNTIF, **SOMMA.SE**/SUMIF, **ARROTONDA**/ROUND, **ARROTONDA.PER.DIF**/ROUNDDOWN, **ARROTONDA.PER.ECC**/ROUNDUP, **SE**/IF, **SE.ERRORE**/IFERROR, **E**/AND, **O**/OR, **NON**/NOT, **RADQ**/SQRT, **POTENZA**/POWER, **RESTO**/MOD, **INTERO**/INT, ABS, **PRODOTTO**/PRODUCT.
- CONTA.SE/SOMMA.SE accettano un criterio: numero (uguaglianza) o stringa `">10"`, `"<=5"`, `"<>0"`; SOMMA.SE ha un range-somma opzionale. Letterali stringa con `"..."`; nomi funzione puntati (CONTA.SE).
- Valori celle letti come numeri (IT: `7,5` → 7.5; `1.234,56` → 1234.56). Vuoto/non numerico = 0 (CONTA conta solo i numerici).
- Errori: `#DIV/0!`, `#REF!`, `#NAME?`, `#CIRC!`, `#VALUE!`, `#ERR!`.

### Motore
- `js/modules/risdoc/pt/formula-engine.js` — parser ricorsivo **sicuro (no eval)**: tokenizer → AST → valutatore. `computeTableValues(grid)` calcola tutte le celle formula con **ricalcolo memoizzato + rilevamento cicli** (computeCell ricorsivo con cycle-set).
- `app/Services/Risdoc/Pt/FormulaEngine.php` — **mirror** PHP identico (pattern ADR-030: JS per l'editor live, PHP per il render server/PDF). Parità verificata su 15+ casi.

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
- ⚠️ v1: niente riferimenti tra tabelle diverse, niente funzioni di testo/data; ampliabile aggiungendo funzioni al registro (un punto, JS+PHP).

## File
- `js/modules/risdoc/pt/formula-engine.js`, `app/Services/Risdoc/Pt/FormulaEngine.php` (motori).
- `js/modules/risdoc/pt/pm-schema.js` (NodeView ptTable, popover config, badge A1, render risultato).
- `js/modules/risdoc/pt/terna-binding.js` + `app/Services/Risdoc/Pt/TernaBinding.php` (esclusione formula dal per-classe).
- `app/Services/Risdoc/Pt/PtToHtml.php` (render server).
- `js/components/risdoc/fm-risdoc-pt-editor.js` (CSS `.pt-table-cell-formula` / `--err` / `.pt-table-cell-ref`).

## Verifica
Unit test motore JS (17) + PHP (15) verdi. Live su pantedu.eu: calcolo, **ricalcolo live** al cambio input, errori, render server, e **risultato per-classe** diverso su due classi.
