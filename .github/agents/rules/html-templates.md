# HTML Templates — Generatore Esercizi

Genera HTML `collex-item` da ExerciseContract JSON. Output: **SOLO HTML grezzo**, niente markdown, niente spiegazioni.

> Regole normative complete: `rules/latex-rules.md`, `rules/style-rules.md`
> ⚠️ I blocchi JavaScript sono **specifiche pseudocodice**, non codice eseguibile. Descrivono la logica da seguire.

---

## Input: ExerciseContract

> Schema canonico: `config/schemas.json`. Qui sotto il formato concreto per il subagent.

```json
{
  "type": "type_Collect | type_VF | type_RMultiA | type_RMultiB",
  "source": {
    "code": "pcf_v1_ed1",
    "title": "Fisica - Le regole del gioco",
    "volume": "Vol.1 Ed.1",
    "publisher": "ZANICHELLI",
    "authors": "Claudio Romeni"
  },
  "metadata": {
    "difficulty": "1",
    "page": "393",
    "number": "25",
    "badge_color": "blue",
    "topic": "nome topic"
  },
  "content": { }
}
```

### Contenuti per tipo

**type_Collect** (singolo):
```json
{ "question": "testo", "data_table": "LaTeX tabella DATI & INCOGNITE", "steps": ["passo1", "passo2"], "final_answer": "42" }
```

**type_Collect** (multi-punto):
```json
{ "question": "testo", "data_table": "LaTeX tabella DATI & INCOGNITE", "points": [{ "label": "a", "steps": ["..."], "solution": "..." }] }
```

**type_VF**:
```json
{ "statements": [{ "text": "...", "isTrue": true, "explanation": "..." }] }
```

**type_RMultiA**:
```json
{ "question": "...", "statements": [{ "letter": "a", "text": "...", "isTrue": null, "explanation": "..." }] }
```

**type_RMultiB**:
```json
{ "question": "...", "options": ["opt1", "opt2", "opt3", "opt4"], "correctIndices": [0], "explanations": ["..."] }
```

---

## Badge Bibliografico: `generateBibHeader()`

Per **`type_Collect`**, **`type_RMultiA`**, **`type_RMultiB`** (MAI per `type_VF`).
Il badge va nello **stesso** `<div>` della traccia — mai in un `<div>` separato.

```javascript
function generateBibHeader(source, metadata) {
  const { title, volume, publisher, authors } = source;
  const { badge_color, difficulty, page, number } = metadata;
  const pallini = "\\bullet".repeat(difficulty) + "\\circ".repeat(4 - difficulty);

  // Ogni riga del badge va in un <div> separato.
  // Gli spazi interni usano &nbsp; (non spazi normali).
  // Il testo della traccia va SULLA STESSA RIGA della chiusura }\quad\)
  return [
    `<div>\\(\\begin{array}{|c|}</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\small{\\text{${title.replace(/ /g, '&nbsp;')}}}\\\\[-5pt]</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${volume.replace(/ /g, '&nbsp;')}&nbsp;-&nbsp;${publisher.replace(/ /g, '&nbsp;')}}}\\\\[-5pt]</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${authors.replace(/ /g, '&nbsp;')}}}\\\\[-5pt]</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>`,
    `<div>\\end{array}\\quad</div>`,
    `<div>\\overset{\\color{red}\\huge&nbsp;${pallini}}{</div>`,
    `<div>&nbsp;&nbsp;&nbsp;&nbsp;\\underset{\\text{P-}${page}}{\\bbox[border:&nbsp;1px&nbsp;solid&nbsp;white;&nbsp;background:&nbsp;${badge_color},3pt]{{\\mathmakebox[cm][c]{\\textcolor{white}{\\large&nbsp;${number}}}}}}</div>`,
    // ⚠️ L'ultimo div contiene la chiusura }\quad seguita dal testo traccia (senza a capo)
    // Se la traccia INIZIA con testo normale: chiudi \) e poi scrivi il testo
    //   es: `<div>}\\quad\\) Calcola la seguente equazione: \\(3x=2+1\\)`
    // Se la traccia INIZIA con formula LaTeX: NON chiudere \), metti la formula subito dopo \\quad
    //   es: `<div>}\\quad 3x=2\\)`
    `<div>}\\quad\\)`  // + QUESTION_TEXT sulla stessa riga (adattare chiusura \) in base al contenuto)
  ].join('\n');
}
```

**⚠️ Formato reale del badge nel PHP:**
```html
<div>\(\begin{array}{|c|}</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\hline</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\small{\text{Titolo&nbsp;Libro}}\\[-5pt]</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\tiny{\text{Vol.1&nbsp;Ed.1&nbsp;-&nbsp;EDITORE}}\\[-5pt]</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\tiny{\text{Nome&nbsp;Autore}}\\[-5pt]</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\hline</div>
<div>\end{array}\quad</div>
<div>\overset{\color{red}\huge&nbsp;\bullet\circ\circ\circ}{</div>
<div>&nbsp;&nbsp;&nbsp;&nbsp;\underset{\text{P-}393}{\bbox[border:&nbsp;1px&nbsp;solid&nbsp;white;&nbsp;background:&nbsp;blue,3pt]{{\mathmakebox[cm][c]{\textcolor{white}{\large&nbsp;25}}}}}</div>
<div>}\quad\) TESTO TRACCIA QUI (stessa riga)</div>
```

---

## Calcolo colonne: `calculateOptimalColumns()`

Usato da **`type_RMultiA`** e **`type_RMultiB`** per determinare quante colonne di `<td>` per riga.

```javascript
function calculateOptimalColumns(items) {
  if (!items || items.length === 0) return 1;
  const avgLength = items.reduce((sum, item) => {
    const clean = item.replace(/\\\(.*?\\\)/g, ' ').replace(/\\\[.*?\\\]/g, ' ');
    const words = clean.trim().split(/\s+/).filter(w => w.length > 0).length;
    const latexCount = (item.match(/\\\(/g) || []).length;
    const latexComplex = (item.match(/\\frac|\\sum|\\int|\\sqrt/g) || []).length;
    return sum + words + (latexCount * 2) + (latexComplex * 3);
  }, 0) / items.length;

  let cols;
  if (avgLength > 8)      cols = 1;
  else if (avgLength > 5) cols = 2;
  else if (avgLength > 3) cols = 3;
  else if (avgLength > 2) cols = 4;
  else                    cols = 5;

  // Correzione parità: se n.items PARI e colonne DISPARI > 1 → forza pari
  if (items.length % 2 === 0 && cols > 1 && cols % 2 === 1) cols = cols > 2 ? cols - 1 : 2;
  return cols;
}
```

`data-typecell` = `"|"` + `"X|"` ripetuto `cols` volte (es: 2 col → `"|X|X|"`).

---

## Template HTML per tipo

### type_Collect (problema)

**Singolo** (senza sotto-punti):
```html
                            <div class="collex-item SOURCE_CODE diffDIFFICULTY">
                                <div class="titolo_quesito" style="background-color: white;">TOPIC_NAME</div>
                                <li class="li-inline">
                                    <div class="collex">
                                        <div>\(\begin{array}{|c|}</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\hline</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\small{\text{TITOLO}}\\[-5pt]</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\tiny{\text{VOLUME&nbsp;-&nbsp;EDITORE}}\\[-5pt]</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\tiny{\text{AUTORI}}\\[-5pt]</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\hline</div>
                                        <div>\end{array}\quad</div>
                                        <div>\overset{\color{red}\huge&nbsp;PALLINI}{</div>
                                        <div>&nbsp;&nbsp;&nbsp;&nbsp;\underset{\text{P-}PAGE}{\bbox[border:&nbsp;1px&nbsp;solid&nbsp;white;&nbsp;background:&nbsp;BADGE_COLOR,3pt]{{\mathmakebox[cm][c]{\textcolor{white}{\large&nbsp;NUMBER}}}}}</div>
                                        <div>}\quad\) QUESTION_TEXT</div>
                                    </div>
                                    <div class="sol">
                                        <div>\(\begin{array}{|l|l|}<br>\hline<br>DATI &amp; INCOGNITE\\<br>\hline<br>DATO_1&amp;INCOGNITA\\<br>DATO_2&amp;\\<br>\hline<br>\end{array}\)</div>
                                        <div>STEP_1 (formula con unità di misura)</div>
                                        <div>STEP_N (con \cancel{} per unità e \fcolorbox per risultato)</div>
                                    </div>
                                </li>
                            </div>
```

⚠️ Il testo della traccia va sulla **stessa riga** della chiusura `}\quad\)` — MAI in un div separato.

#### Tassonomia `.sol` per type_Collect

> ⚠️ Tre categorie con struttura diversa. Scegliere in base al contenuto dell'esercizio.

| Categoria | Quando | Struttura `.sol` |
|-----------|--------|-----------------|
| **A. Problemi fisico-matematici** | Dati numerici, formula, incognita da calcolare | DATI & INCOGNITE + `align*` + incognite colorate → dettagli in `rules/latex-rules.md` |
| **B. Matematica pura** | Equazioni, disequazioni, domini, limiti, derivate | `\begin{array}` + `\enclose{circle}` numerati + `\begin{cases}` + `\begin{align}` (vedi sotto) |
| **C. Altri** | Completamenti, conversioni semplici, discorsive | Svolgimento libero (senza DATI, senza struttura fissa) |

#### A. Problemi fisico-matematici — `.sol`

> Regole complete, sintassi `align*`, incognite colorate, sostituzione in avanti, unità con `\cancel{}`: → **`rules/latex-rules.md`**

Struttura obbligatoria:
1. **Primo `<div>`**: tabella DATI & INCOGNITE (`\begin{array}{|l|l|}...\end{array}`)
2. **Svolgimento in `align*`**: incognita principale `\enclose{circle}[mathcolor=red]{...}`, ausiliarie blue/purple/green
3. **Risultato finale**: `\fcolorbox{red}{yellow}{$\color{black}<span class="solution">VALORE\text{ UNITÀ}</span>$}` **dentro `\(\…\)`**
4. **Riferimenti numerati** (opzionali): `\underset{\colorbox{yellow}{...}}{=}` + `\lower{-1pt}\colorbox{yellow}{...}`

#### B. Matematica pura (equazioni, disequazioni, dominio) — `.sol`

> **REGOLA CHIAVE:** Questa struttura è OBBLIGATORIA per equazioni, disequazioni e domini.
> NON usare "svolgimento libero" per questi tipi — devono seguire il pattern sotto.

**Struttura comune:**

1. **`\begin{array}`** con condizioni numerate (`\enclose{circle}[mathcolor=red]{1}`, `{2}`, …)
2. **`\begin{cases}`** con condizioni di esistenza (C.E.) + equazione/disequazione
3. **Frecce** con etichette colorate: `\underset{\colorbox{yellow}{$…\text{ I }…$}}{\Longrightarrow}` (equazioni/disequazioni) o `{\Longleftrightarrow}` (domini)
4. **Sistema risolutivo** (`\begin{cases}`) → risultato con `\fcolorbox`
5. **Riferimenti numerati** (`\lower{-1pt}\colorbox{yellow}`) con svolgimento algebrico in `\begin{align}` o `\begin{align*}`

**B1. Equazioni — template `.sol`:**
```html
                                    <div class="sol">
                                        <div>\(\begin{array}{r}<br>\text{C.E.:&nbsp;}\enclose{circle}[mathcolor=red]{1}\\<br>\text{eq.}\enclose{circle}[mathcolor=red]{2}<br>\end{array}<br>\begin{cases}<br>CONDIZIONE_CE\\<br>EQUAZIONE=0<br>\end{cases}\underset{\colorbox{yellow}{$&nbsp;\,\text{&nbsp;I&nbsp;}\,$}}{\Longrightarrow&nbsp;}<br>\begin{cases}<br>CE_SEMPLIFICATA\\<br>SOLUZIONE<br>\end{cases}\Longrightarrow&nbsp;RISULTATO \fcolorbox{red}{yellow}{$\color{black}<span class="solution">x=VALORE</span>$}<br>\)</div>
                                        <div>\(<br>\begin{align}<br>\lower{-1pt}\colorbox{yellow}{$&nbsp;\text{&nbsp;I&nbsp;}$}\quad&nbsp;\enclose{circle}[mathcolor=red]{2}\,EQUAZIONE&amp;=0\\<br>PASSAGGIO_1\\<br>SOLUZIONE<br>\end{align}\)</div>
                                    </div>
```
- Etichette: `\text{C.E.:&nbsp;}` per condizioni, `\text{eq.}` per equazione
- Se più C.E.: `\enclose{circle}[mathcolor=red]{1}` `{2}` …, equazione prende il numero successivo
- Freccia: `\Longrightarrow` (implicazione)
- Casi speciali: `\text{impossibile}`, `\text{sempre vera}` dove applicabile

**B2. Disequazioni — template `.sol`:**
```html
                                    <div class="sol">
                                        <div>\(\begin{array}{r}<br>\text{C.E.:&nbsp;}\enclose{circle}[mathcolor=red]{1}\enclose{circle}[mathcolor=red]{2}\\</div>
                                        <div>\text{dis.}\enclose{circle}[mathcolor=red]{3}<br></div>
                                        <div>\end{array}<br>\begin{cases}<br>CE_1\\<br>CE_2\\<br>DISEQUAZIONE<br>\end{cases}&nbsp;\underset{\colorbox{yellow}{$&nbsp;\,\text{&nbsp;I&nbsp;}\,$}}{\Longrightarrow&nbsp;}\begin{cases}<br>CE_1_RISOLTA\\<br>CE_2_RISOLTA\\<br>DISEQ_RISOLTA<br></div>
                                        <div>\end{cases}\underset{\colorbox{yellow}{$&nbsp;\text{&nbsp;II&nbsp;}$}}{\Longrightarrow&nbsp;}\fcolorbox{red}{yellow}{$\color{black}<span class="solution">DOMINIO_SOLUZIONE</span>$}\)<br></div>
                                        <div>\(<br>\begin{align}<br>\lower{-1pt}\colorbox{yellow}{$&nbsp;\text{&nbsp;I&nbsp;}$}\quad&nbsp;\enclose{circle}[mathcolor=red]{3}\,DISEQUAZIONE&amp;\geq\text{...}\\<br>PASSAGGIO\\<br>RISULTATO<br>\end{align}\)</div>
                                    </div>
```
- Etichette: `\text{C.E.:&nbsp;}` per condizioni, `\text{dis.}` per disequazione
- Freccia I: risoluzione algebrica della disequazione. Freccia II: intersezione C.E. → dominio/soluzione

**B3. Dominio — template `.sol`:**
```html
                                    <div class="sol">
                                        <div>\(<br>&nbsp;\begin{array}{ll}<br>&nbsp;\enclose{circle}[mathcolor=red]{1}\\<br>&nbsp;\enclose{circle}[mathcolor=red]{2}\\<br>&nbsp;\end{array}<br>\begin{cases}<br>CONDIZIONE_1&gt;0\\<br>CONDIZIONE_2&gt;0<br>\end{cases}<br>\underset{\colorbox{yellow}{$\,\text{&nbsp;I&nbsp;}\,$}}{\Longleftrightarrow}<br>\begin{cases}<br>COND_1_RISOLTA\\<br>COND_2_RISOLTA<br>\end{cases}\underset{\colorbox{yellow}{$\text{&nbsp;II&nbsp;}$}}{\Longleftrightarrow}&nbsp;\fcolorbox{red}{yellow}{$\color{black}<span class="solution">DOMINIO</span>$}<br>\)<br></div>
                                        <div><br></div>
                                        <div>\(\lower{-1pt}\colorbox{yellow}{$&nbsp;\text{&nbsp;I&nbsp;}$}\)<br></div>
                                        <div>\(\begin{align*}<br>\enclose{circle}[mathcolor=red]{1}\,\,CONDIZIONE_1&amp;&gt;0\\<br>PASSAGGIO<br>\end{align*}\)<br></div>
                                        <div>\(\begin{align*}<br>\enclose{circle}[mathcolor=red]{2}\,\,CONDIZIONE_2&amp;&gt;0\\<br>PASSAGGIO<br>\end{align*}\)</div>
                                        <div>\(\lower{-1pt}\colorbox{yellow}{$\text{&nbsp;II&nbsp;}$}\)</div>
                                    </div>
```
- Array: `\begin{array}{ll}` (non `{r}`)
- Frecce: `\Longleftrightarrow` (equivalenza, non implicazione)
- Riferimenti: ogni condizione risolta in un `\begin{align*}` separato
- `\lower{-1pt}\colorbox{yellow}{$…$}` come separatore tra sezioni dei riferimenti

#### Regole comuni `.sol` (tutte le categorie)

- `\fcolorbox` DEVE essere **dentro** `\(\…\)` — mai fuori dai delimitatori
- `<span class="solution">` va **dentro** il `$…$` del fcolorbox
- DEVE contenere svolgimento reale (≥ 2 passaggi algebrici), MAI solo risultato
- MAI soluzione riepilogativa separata a fine esercizio
- Simboli vietati: vedi `rules/latex-rules.md` § Simboli vietati
- Se il preflight fornisce `solution_format_ref` → replicare lo stile degli esercizi esistenti

**Multi-punto** (a, b, c…):
```html
                            <div class="collex-item SOURCE_CODE diffDIFFICULTY">
                                <div class="titolo_quesito" style="background-color: white;">TOPIC_NAME</div>
                                <li class="li-inline">
                                    <div class="collex">
                                        <!-- Badge multi-div (stesso formato del singolo) -->
                                        <div>}\quad\) QUESTION_TEXT</div>
                                    </div>
                                    <div class="sol">
                                        <div>\(\begin{array}{|l|l|}<br>\hline<br>DATI &amp; INCOGNITE\\<br>\hline<br>DATI_COMUNI&amp;INCOGNITA_A=?\\<br>\hdashline<br>&amp;INCOGNITA_B=?\\<br>\hline<br>\end{array}\)</div>
                                        <ol class="custom-lower-alpha" style="list-style-type: lower-alpha;">
                                            <li>STEPS_A ...\fcolorbox{red}{yellow}{$\color{black}<span class="solution">SOLUTION_A</span>$}...</li>
                                            <li>STEPS_B ...\fcolorbox{red}{yellow}{$\color{black}<span class="solution">SOLUTION_B</span>$}...</li>
                                        </ol>
                                    </div>
                                </li>
                            </div>
```

Regole `.sol` multi-punto:
- Tabella DATI & INCOGNITE (se fisico-mat) **prima** dell'`<ol>`, con `\hdashline` tra sotto-punti
- `\fcolorbox` con `<span class="solution">` alla fine di **ogni** `<li>`

---

### type_VF (vero/falso)

```html
                            <div class="collex-item SOURCE_CODE diffDIFFICULTY">
                                <div class="titolo_quesito" style="background-color: white;">TOPIC_NAME</div>
                                <li class="li-inline">
                                    <div class="collex">
                                        <div>STATEMENT_TEXT</div>
                                    </div>
                                    <div class="wrapsolVF">
                                        <div class="sol V_OR_F"></div>
                                        <div class="giustsol">
                                            <div>EXPLANATION</div>
                                        </div>
                                    </div>
                                </li>
                            </div>
```

- `V_OR_F`: usare `V` se `isTrue=true`, `F` se `isTrue=false`
- **NO badge** bibliografico per type_VF
- Ogni statement = un `collex-item` separato
- Giustsol: breve (1-3 righe), formule > parole

---

### type_RMultiA (affermazioni V/F tabellare)

Colonne calcolate dinamicamente con `calculateOptimalColumns(statements.map(s => s.text))`.

```javascript
function generateTypeRMultiA(contract) {
  const { source, metadata, content } = contract;
  const statements = content.statements;
  const cols = calculateOptimalColumns(statements.map(s => s.text));

  const rows = [];
  for (let i = 0; i < statements.length; i += cols) {
    const cells = statements.slice(i, i + cols).map((stmt) => `
                                                    <td>
                                                        <div style="display: flex;">
                                                            \\(\\text{V}\\,\\square\\quad \\text{F}\\,\\square\\quad\\) <label class="collex">
                                                                <div>${stmt.text}</div>
                                                            </label>
                                                        </div>
                                                    </td>`).join('\n');
    rows.push(`                                                <tr>${cells}\n                                                </tr>`);
  }

  const colSpec = "|" + "X|".repeat(cols);
  const justifications = statements.map((stmt) =>
    `                                            <li>${stmt.explanation}</li>`
  ).join('\n');

  return `
                            <div class="collex-item ${source.code} diff${metadata.difficulty}">
                                <div class="titolo_quesito" style="background-color: white;">${metadata.topic}</div>
                                <li class="li-inline">
                                    <div class="collexTab collex">
                                        ${generateBibHeader(source, metadata)} ${content.question}</div>
                                    </div>
                                    <div class="flex20 tabelle" style="overflow-x:auto;">
                                        <table data-typecell="${colSpec}" data-mpagew="1" data-mixtr="0" data-mixcol="0">
                                            <tbody>
${rows.join('\n')}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="giustsol">
                                        <ol class="custom-lower-alpha" style="list-style-type: lower-alpha;">
${justifications}
                                        </ol>
                                    </div>
                                </li>
                            </div>`;
}
```

---

### type_RMultiB (checkbox multipla)

Colonne calcolate con `calculateOptimalColumns(options)`.
Se il numero di opzioni non è multiplo di `cols`, le celle mancanti sono `<td></td>` vuote.

```javascript
function generateTypeRMultiB(contract) {
  const { source, metadata, content } = contract;
  const options = content.options;
  const correctIndices = content.correctIndices || [];
  const cols = calculateOptimalColumns(options);

  const rows = [];
  for (let i = 0; i < options.length; i += cols) {
    const cells = [];
    for (let j = 0; j < cols; j++) {
      const idx = i + j;
      if (idx >= options.length) {
        cells.push('                                                    <td></td>');
      } else {
        const isCorrect = correctIndices.includes(idx);
        cells.push(`                                                    <td>
                                                        <div style="display: flex;"> <input type="checkbox"
                                                                class="checkbox checkboxRM${isCorrect ? ' solchecked' : ''}"> <label class="collex">
                                                                <div>${options[idx]}</div>
                                                            </label> </div>
                                                    </td>`);
      }
    }
    rows.push(`                                                <tr>\n${cells.join('\n')}\n                                                </tr>`);
  }

  const colSpec = "|" + "X|".repeat(cols);

  const giustsolBody = content.explanations?.length > 0
    ? `<ul style="list-style-type: disc;">\n${content.explanations.map(exp => `                                            <li>${exp}</li>`).join('\n')}\n                                        </ul>`
    : `<div>${content.explanation}</div>`;

  return `
                            <div class="collex-item ${source.code} diff${metadata.difficulty}">
                                <div class="titolo_quesito" style="background-color: white;">${metadata.topic}</div>
                                <li class="li-inline">
                                    <div class="collexTab collex">
                                        ${generateBibHeader(source, metadata)} ${content.question}</div>
                                    </div>
                                    <div class="flex20 tabelle" style="overflow-x:auto;">
                                        <table data-typecell="${colSpec}" data-mpagew="1" data-mixtr="1" data-mixcol="1">
                                            <tbody>
${rows.join('\n')}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="giustsol">
                                        <div>${giustsolBody}</div>
                                    </div>
                                </li>
                            </div>`;
}
```

Regole RMultiB:
- `solchecked` aggiunto a checkbox corrette
- **NO** lettere A/B/C/D nelle opzioni
- Celle mancanti: `<td></td>` vuote
- Giustsol: `<ul style="list-style-type: disc;">`, MAI lettere prefisso, MAI simboli checkbox

---

## Regole rapide (quick reference)

> Il subagent legge anche `rules/latex-rules.md` per le regole LaTeX complete.

- **Indentazione**: 28 spazi (7×4) per `collex-item` radice
- Tag **vietati**: `<hr>`, `<strong>`, `<code>`, `<b>`, `<script>`, `<svg>`, `<img>`
- Accenti: `&egrave;`, `&agrave;`, `&ugrave;`, `&ograve;`, `&eacute;`
- LaTeX: `\(...\)` inline, `\[...\]` block — per il resto → `rules/latex-rules.md`
- Sol type_Collect (A. fisico-mat): DATI&INCOGNITE + `align*` + incognite colorate → `rules/latex-rules.md`
- Sol type_Collect (B. matematica pura: eq/diseq/dominio): `array` + `enclose{circle}` numerati + `cases` + `align` → vedi § B sopra
- Sol type_Collect (C. altri): svolgimento libero
- Simboli vietati: `\checkmark`, `\xmark`, `\square`, Unicode ✓✗ → `rules/latex-rules.md` § Simboli vietati
- `\fcolorbox` SEMPRE dentro `\(…\)` — mai fuori dai delimitatori
- Giustsol: breve (1-3 righe), formule > parole, MAI lettere A/B/C/D prefisso, MAI simboli

---

## Validazione per tipo

```javascript
if (!contract.type || !contract.source || !contract.metadata)
  return err('MISSING_REQUIRED_FIELD', 'critical', 'Contract non valido: campi obbligatori mancanti', { required: ['type', 'source', 'metadata', 'content'] });

if (!["type_Collect","type_VF","type_RMultiA","type_RMultiB"].includes(contract.type))
  return err('TYPE_AMBIGUOUS', 'error', 'Tipo esercizio non supportato', { received: contract.type });

if (contract.type === "type_Collect" && (!contract.content.steps || contract.content.steps.length === 0) && (!contract.content.points || contract.content.points.length === 0))
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.steps o content.points obbligatorio', { type: 'type_Collect' });

if (contract.type === "type_VF" && !contract.content.statements)
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.statements obbligatorio', { type: 'type_VF' });

if (contract.type === "type_VF" && !String(contract.content.statements?.[0]?.explanation || '').trim())
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.statements[0].explanation obbligatorio', { type: 'type_VF' });

if (contract.type === "type_RMultiA" && (!contract.content.statements || contract.content.statements.length === 0))
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.statements obbligatorio', { type: 'type_RMultiA' });

if (contract.type === "type_RMultiA" && contract.content.statements.some(s => !String(s?.explanation || '').trim()))
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.statements[].explanation obbligatorio', { type: 'type_RMultiA' });

if (contract.type === "type_RMultiB" && (!Array.isArray(contract.content.explanations) || contract.content.explanations.length === 0) && !String(contract.content.explanation || '').trim())
  return err('MISSING_REQUIRED_FIELD', 'critical', 'content.explanations obbligatorio', { type: 'type_RMultiB' });

// Ignora silenziosamente: figure_tikz, has_figure, tikz, svg, data, unknowns
```

Formato errore: `{ code, severity, human_message, details }` → vedi `config/schemas.json`

---

## Output

Restituire **SOLO** HTML grezzo. Niente markdown wrapper, niente spiegazioni, niente JSON wrapper.