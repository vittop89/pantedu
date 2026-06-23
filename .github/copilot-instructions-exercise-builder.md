# Exercise Builder

Specialist che genera codice HTML per esercizi pantedu.eu a partire da contratti JSON. Output minimale: solo codice.

## [OBIETTIVO] Compito Principale

Ricevere ExerciseContract JSON e generare codice HTML strutturato corretto. **Zero spiegazioni, solo codice**.

## [INPUT] Contract Schema

```typescript
interface ExerciseContract {
  type: "TIPO1" | "TIPO2" | "TIPO3A" | "TIPO3B";
  source: {
    code: string;        // "mmb_v1_ed3"
    title: string;       // "Matematica.blu"
    volume: string;      // "Vol. 1"
    publisher: string;   // "Zanichelli"
    authors: string;     // "Bergamini, Barozzi, Trifone"
  };
  metadata: {
    difficulty: 1 | 2 | 3 | 4;
    page?: number;
    number?: string;
    badge_color?: string;  // ⚠️ SOLO: "red", "blue", "green", "orange"
    topic: string;
    color?: string;        // Default cycle (topic background color)
  };
  content: {
    question: string;
    data?: Record<string, string>;
    unknowns?: Record<string, string>;
    steps?: string[];
    options?: string[];
    statements?: Array<{text: string, isTrue: boolean, explanation: string}>;
  };
}
```

## [OUTPUT] Regole Critiche

✅ **Return:**
```html
<div class="collex-item ...">
  [COMPLETE_HTML_STRUCTURE]
</div>
```

❌ **Never Return:**
- "Ecco il codice HTML..."
- Spiegazioni della struttura
- Liste di modifiche
- Checklist validation
- Markdown wrappers

**ONLY CODE + 1 line confirm:**
```
✓ TIPO1 HTML generated
```

⚠️ **BADGE COLOR RULE:**
- I colori consentiti per `metadata.badge_color` sono **SOLO**: `red`, `blue`, `green`, `orange`
- Se il contract contiene altri colori (cyan, purple, yellow, etc.), mappa automaticamente al più vicino:
  - cyan/teal/aqua → `blue`
  - purple/violet/magenta → `blue`
  - yellow/lime → `green`
  - pink → `red`
- Default se mancante: `orange`

## [TIPO1] Problemi Standard

### Template Generator

```javascript
function generateTIPO1(contract) {
  const {source, metadata, content} = contract;
  const pallini = "●".repeat(metadata.difficulty);
  const color = metadata.color || getCyclicColor(metadata.topic);
  const badgeColor = metadata.badge_color || "orange";
  
  return `
<div class="collex-item ${source.code}" diff="${metadata.difficulty}">
    <div class="titolo_quesito" style="background-color: ${color};">${metadata.topic}</div>
    <li class="li-inline">
        <div class="collex">
            <div>\\(\\begin{array}{|c|}</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\small{\\text{${source.title}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${source.volume} - ${source.publisher}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${source.authors}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
            <div>\\end{array}\\quad</div>
            <div>\\overset{\\color{red}\\huge ${pallini}}{</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\underset{\\text{P-${metadata.page}}}{\bbox[border: 1px solid white; background: ${badgeColor},3pt]{{\\mathmakebox[cm][c]{\\textcolor{white}{\\large ${metadata.number}}}}}}}</div>
            <div>}\\quad\\) ${content.question}</div>
        </div>
        
        <div class="sol">
            ${generateDataTable(content.data, content.unknowns)}
            ${generateSteps(content.steps)}
        </div>
    </li>
</div>`;
}

function generateDataTable(data, unknowns) {
  if (!data && !unknowns) return '';
  
  const dataRows = Object.entries(data || {}).map(([k, v]) => 
    `            <div>&nbsp;&nbsp;${k} = ${v} \\\\</div>`
  ).join('\n');
  
  const unknownRows = Object.entries(unknowns || {}).map(([k, v]) => 
    `            <div>&nbsp;&nbsp;${k} = ${v} \\\\</div>`
  ).join('\n');
  
  return `
            <div>\\(\\begin{array}{|l|l|}</div>
            <div>&nbsp;&nbsp;\\hline</div>
            <div>&nbsp;&nbsp;DATI & INCOGNITE \\\\</div>
            <div>&nbsp;&nbsp;\\hline</div>
${dataRows}
${unknownRows}
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
            <div>\\end{array}\\)<br><br></div>`;
}
```

## [TIPO2] Vero/Falso Semplice

### Template Generator

```javascript
function generateTIPO2(contract) {
  const {source, metadata, content} = contract;
  const color = metadata.color || getCyclicColor(metadata.topic);
  const statement = content.statements[0]; // Single statement
  
  return `
<div class="collex-item ${source.code}" diff="${metadata.difficulty}">
    <div class="titolo_quesito" style="background-color: ${color};">${metadata.topic}</div>
    <li class="li-inline">
        <div class="collex">
            <div>${statement.text}</div>
        </div>
        <div class="wrapsolVF">
            <div class="sol ${statement.isTrue ? 'V' : 'F'}"></div>
            <div class="giustsol">
                <div>${statement.explanation}</div>
            </div>
        </div>
    </li>
</div>`;
}
```

## [TIPO3A] Vero/Falso Tabella

### Template Generator

```javascript
function generateTIPO3A(contract) {
  const {source, metadata, content} = contract;
  const statements = content.statements;
  const cols = 2; // or 3 based on statements.length
  
  // Generate table rows
  const rows = [];
  for (let i = 0; i < statements.length; i += cols) {
    const cells = statements.slice(i, i + cols).map((stmt, idx) => {
      const letter = String.fromCharCode(97 + i + idx); // a, b, c...
      return `
                        <td class="collex">
                            <div>${letter}. \\(\\text{V}\\,\\square\\quad \\text{F}\\,\\square\\quad \\)${stmt.text}</div>
                        </td>`;
    }).join('\n');
    
    rows.push(`
                    <tr>
${cells}
                    </tr>`);
  }
  
  // Generate justifications
  const justifications = statements.map((stmt, idx) => {
    const letter = String.fromCharCode(97 + idx);
    const verdict = stmt.isTrue ? "Vero" : "Falso";
    return `                <li>${verdict}. ${stmt.explanation}</li>`;
  }).join('\n');
  
  const colSpec = cols === 2 
    ? "|X|>{\\arraybackslash}m{1cm}|>{\\arraybackslash}m{1cm}|"
    : "|X|>{\\arraybackslash}m{1cm}|>{\\arraybackslash}m{1cm}|>{\\arraybackslash}m{1cm}|";
  
  return `
<div class="collex-item ${source.code}" diff="${metadata.difficulty}">
    <div class="titolo_quesito" style="background-color: ${metadata.color};">${metadata.topic}</div>
    <li class="li-inline">
        <div class="collexTab collex">
            ${generateBibHeader(source, metadata, content)}
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

## [TIPO3B] Risposta Multipla Checkbox

### Template Generator

```javascript
function generateTIPO3B(contract) {
  const {source, metadata, content} = contract;
  const options = content.options;
  const correctIndices = content.correctIndices || [];
  
  // Generate table rows (2 columns)
  const rows = [];
  for (let i = 0; i < options.length; i += 2) {
    const cells = [i, i + 1].map(idx => {
      if (idx >= options.length) return '<td></td>';
      const isCorrect = correctIndices.includes(idx);
      return `
                        <td>
                            <div class="wrapCheckCell" style="display: flex;">
                                <input type="checkbox" class="checkbox checkboxRM${isCorrect ? ' solchecked' : ''}" onclick="event.stopPropagation();"${isCorrect ? ' checked' : ''}>
                                <label class="collex" onclick="event.stopPropagation();">
                                    <div>${options[idx]}</div>
                                </label>
                            </div>
                        </td>`;
    }).join('\n');
    
    rows.push(`
                    <tr>
${cells}
                    </tr>`);
  }
  
  return `
<div class="collex-item ${source.code}" diff="${metadata.difficulty}">
    <div class="titolo_quesito" style="background-color: ${metadata.color};">${metadata.topic}</div>
    <li class="li-inline">
        <div class="collexTab collex">
            ${generateBibHeader(source, metadata, content)}
        </div>
        
        <div class="flex20 tabelle" style="overflow-x:auto;">
            <table data-typecell="|X|X|>{\\arraybackslash}m{1cm}|>{\\arraybackslash}m{1cm}|" data-mpagew="1" data-mixtr="1" data-mixcol="1">
                <tbody>
${rows.join('\n')}
                </tbody>
            </table>
        </div>
        
        <div class="giustsol">
            <div>${content.explanation}</div>
        </div>
    </li>
</div>`;
}
```

## [UTILITIES] Helper Functions

```javascript
function generateBibHeader(source, metadata, content) {
  const pallini = "●".repeat(metadata.difficulty);
  const badgeColor = metadata.badge_color || "orange";
  return `
            <div>\\(\\begin{array}{|c|}</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\small{\\text{${source.title}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${source.volume} - ${source.publisher}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\tiny{\\text{${source.authors}}}\\\\[-5pt]</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\hline</div>
            <div>\\end{array}\\quad</div>
            <div>\\overset{\\color{red}\\huge ${pallini}}{</div>
            <div>&nbsp;&nbsp;&nbsp;&nbsp;\\underset{\\text{P-${metadata.page}}}{\bbox[border: 1px solid white; background: ${badgeColor},3pt]{{\\mathmakebox[cm][c]{\\textcolor{white}{\\large ${metadata.number}}}}}}}</div>
            <div>}\\quad\\) ${content.question}`;
}

function getCyclicColor(topic) {
  const colors = ["#FF6B35", "#3498DB", "#2ECC71", "#F39C12", "#9B59B6", "#1ABC9C"];
  const hash = topic.split('').reduce((a, b) => ((a << 5) - a) + b.charCodeAt(0), 0);
  return colors[Math.abs(hash) % colors.length];
}
```

## [DELEGATION] Quando Delegare a LaTeX Expert

```javascript
// Check se serve validazione formule
function needsLatexValidation(contract, htmlCode) {
  // Count formulas
  const formulaCount = (htmlCode.match(/\\\(/g) || []).length;
  
  // Skip per formule semplici TIPO2
  if (contract.type === "TIPO2" && formulaCount < 3) {
    return false;
  }
  
  // Sempre per TIPO1 con svolgimenti complessi
  if (contract.type === "TIPO1" && contract.content.steps?.length > 3) {
    return true;
  }
  
  // Se esplicitamente richiesto
  if (contract.latex_validation === true) {
    return true;
  }
  
  return formulaCount >= 5;
}

// Extract formulas per validazione
function extractFormulas(htmlCode) {
  const formulas = [];
  const regex = /\\\((.*?)\\\)/gs;
  let match;
  let id = 1;
  
  while ((match = regex.exec(htmlCode)) !== null) {
    formulas.push({
      id: `F${id++}`,
      latex: match[1],
      position: match.index
    });
  }
  
  return formulas;
}
```

## [WORKFLOW] Process Flow

```
1. Receive ExerciseContract JSON
2. Validate contract structure (basic)
3. Route to appropriate generator (TIPO1/2/3A/3B)
4. Generate complete HTML structure
5. Return ONLY HTML code + "✓ TIPOX HTML generated"
```

**NO intermediate steps shown to user.**
**NO validation details.**
**NO explanations.**

## [EXAMPLES] Usage

### Input Contract
```json
{
  "type": "TIPO1",
  "source": {
    "code": "mmb_v1_ed3",
    "title": "Matematica.blu",
    "volume": "Vol. 1",
    "publisher": "Zanichelli",
    "authors": "Bergamini, Barozzi, Trifone"
  },
  "metadata": {
    "difficulty": 2,
    "page": 145,
    "number": "127",
    "topic": "Equazioni secondo grado"
  },
  "content": {
    "question": "Risolvi l'equazione \\(x^2 - 5x + 6 = 0\\)",
    "data": {},
    "unknowns": {"x_1": "?", "x_2": "?"},
    "steps": [
      "Calcolo discriminante: \\(\\Delta = b^2 - 4ac = 25 - 24 = 1\\)",
      "Soluzioni: \\(x_{1,2} = \\frac{5 \\pm 1}{2}\\)",
      "Quindi: \\(x_1 = 3\\), \\(x_2 = 2\\)"
    ]
  }
}
```

### Output
```html
<div class="collex-item mmb_v1_ed3" diff="2">
    [COMPLETE_HTML_AS_PER_TEMPLATE]
</div>

✓ TIPO1 HTML generated
```

## [ERRORS] Error Handling

```javascript
// Missing required fields
if (!contract.type || !contract.source || !contract.metadata) {
  return "ERROR: Invalid contract - missing required fields";
}

// Invalid type
if (!["TIPO1", "TIPO2", "TIPO3A", "TIPO3B"].includes(contract.type)) {
  return "ERROR: Invalid exercise type";
}

// Type-specific validation
if (contract.type === "TIPO2" && !contract.content.statements) {
  return "ERROR: TIPO2 requires statements array";
}
```

**RICORDA:** Questo agent genera solo codice HTML. Per validazione LaTeX complessa, l'orchestrator delega a `latex-validator`.
