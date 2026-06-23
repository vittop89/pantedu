# Style Rules — Badge, Explanation, Struttura HTML

Regole di stile per la generazione HTML degli esercizi.

---

## Tassonomia Tipi

`type_Collect` | `type_VF` | `type_RMultiA` | `type_RMultiB`

Template HTML di riferimento: `modelli_eser.php` → `#type_Collect-1`, `#type_VF-1`, `#type_RMulti-6` (per A e B).

---

## Colori Badge (`badge_color`)

Valori ammessi: `red` | `blue` | `green` | `orange` (default: `orange`)

### Normalizzazione

| Rilevato | Mappa a |
|----------|---------|
| cyan / teal / aqua | `blue` |
| purple / violet / magenta | `blue` |
| yellow / lime | `green` |
| pink | `red` |
| altro non riconosciuto | `orange` |

---

## Colori Topic

I colori topic vengono gestiti automaticamente dal frontend (`functions-mod.js` → `_enforceTopicColorCycle`).
Ciclo: **`white → green → blue → red → purple → orange`** (poi riparte).
Ogni cambio di testo in `.titolo_quesito` avanza al colore successivo.

**Nell'HTML generato: usare sempre `background-color: white`** — il frontend correggerà al caricamento.

---

## Struttura HTML `collex-item`

> Template completi con badge multi-div e pseudocodice: `rules/html-templates.md`

### Regole struttura

> Indentazione, badge multi-div, tag vietati, accenti: → `rules/html-templates.md` (file canonico per struttura HTML)

- **Indentazione**: 28 spazi (7×4) per `collex-item` radice
- Badge: per `type_Collect`, `type_RMultiA`, `type_RMultiB`. **NON** per `type_VF`
- Traccia sulla **stessa riga** della chiusura `}\quad\)` — mai in div separato

---

## Solution Span

> Formato canonico e regole complete: → `rules/latex-rules.md` § Risultato finale evidenziato

- `<span class="solution">` va **sempre** incapsulato in `\fcolorbox`
- Formato: `\fcolorbox{red}{yellow}{$\color{black}<span class="solution">ANSWER</span>$}`
- **⚠️ `\fcolorbox` DEVE essere dentro `\(…\)` — mai fuori dai delimitatori**
- Singolo: nell'ultimo `<div>` di `.sol`. Multi-punto: alla fine di **ogni** `<li>`

---

## Svolgimento `.sol` (type_Collect)

> Template completi per ogni categoria: → `rules/html-templates.md` § Tassonomia .sol per type_Collect
> Regole LaTeX, `align*`, incognite colorate: → `rules/latex-rules.md`

| Categoria | Struttura `.sol` |
|-----------|------------------|
| **A. Fisico-matematici** | DATI & INCOGNITE + `align*` + incognite colorate + unità con `\cancel{}` |
| **B. Matematica pura** (eq/diseq/dominio) | `\begin{array}` + `\enclose{circle}` numerati + `\begin{cases}` + `\begin{align}` |
| **C. Altri** (completamenti, discorsive) | Svolgimento libero |

- DEVE contenere svolgimento reale (≥ 2 passaggi algebrici), MAI solo risultato
- Ogni passaggio in un `<div>` separato
- Multi-punto: `<ol class="custom-lower-alpha" style="list-style-type: lower-alpha;">`

---

## Simboli vietati

> Lista completa: → `rules/latex-rules.md` § Simboli vietati

`\checkmark`, `\xmark`, `\square` e Unicode `✓✗☑☐✅❌` sono vietati in **qualsiasi** contesto (sol, giustsol, traccia).
Per risposte V/F: usare classi CSS (`V`, `F`, `solchecked`).

---

## Giustsol (type_VF, type_RMultiA, type_RMultiB)

- Breve: 1-3 righe
- Formule LaTeX > parole
- **MAI** lettere come prefisso: `A:`, `B:`, `C:`, `D:` → citare l'elemento chiave
- **MAI** simboli checkbox: `✓`, `✗`, `☑`, `☐`, `✅`, `❌`

### Per tipo

| Tipo | Container giustsol |
|------|--------------------|
| type_VF | `<div class="giustsol"><div>EXPLANATION</div></div>` |
| type_RMultiA | `<div class="giustsol"><ol class="custom-lower-alpha">...</ol></div>` |
| type_RMultiB | `<div class="giustsol"><div><ul style="list-style-type: disc;">...</ul></div></div>` |

---

## Tabelle RMulti

- MAI `colspan` / `rowspan`
- `data-typecell`: `"|X|"` per 1 col, `"|X|X|"` per 2, ecc.
- type_RMultiA: `data-mixtr="0" data-mixcol="0"`
- type_RMultiB: `data-mixtr="1" data-mixcol="1"`
- `data-mpagew="1"` sempre
- Opzioni type_RMultiB: NO lettere A/B/C/D

### Calcolo colonne

→ Algoritmo completo: `rules/html-templates.md` → `calculateOptimalColumns()`

Correzione parità: se n. items PARI e colonne DISPARI > 1 → forza pari (3→2, 5→4).
Celle mancanti nell'ultima riga: `<td></td>` vuote.