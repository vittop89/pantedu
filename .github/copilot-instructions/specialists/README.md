# Specialists Directory

Directory contenente gli agent specializzati per il sistema di generazione esercizi FisMatPant.

## 📂 Struttura

```
specialists/
├── image-extractor.md      ← Analizza immagini, estrae dati esercizi
├── exercise-builder.md     ← Costruisce HTML da contracts JSON
├── latex-validator.md      ← Valida e corregge formule LaTeX
├── tikz-generator.md       ← Genera codice TikZ per figure geometriche
└── README.md              ← Questo file
```

**Nota:** Il file `workflow-manager.md` è al livello superiore (non in specialists/) perché orchestra gli altri specialists invece di essere uno specialist puro.

## 🔄 Workflow Integrato

```
┌─────────────────────────────────────────┐
│  UTENTE                                 │
│  "Inserisci esercizio con triangolo"   │
└─────────────┬───────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────┐
│  ORCHESTRATOR                           │
│  • Coordina specialists via JSON        │
│  • Non genera codice direttamente       │
└─────────────┬───────────────────────────┘
              │
              ├─────────────────────────────┐
              │                             │
              ▼                             ▼
┌──────────────────────────┐  ┌──────────────────────────┐
│  IMAGE-EXTRACTOR         │  │  TIKZ-GENERATOR          │
│  • Analizza immagine     │  │  • Rileva figura         │
│  • Estrae metadati       │  │  • Seleziona template    │
│  • Crea ExerciseContract │  │  • Personalizza codice   │
│  • Rileva figure TikZ    │──│► • Genera HTML <script>  │
└──────────┬───────────────┘  └──────────┬───────────────┘
           │                             │
           ▼                             │
┌──────────────────────────┐             │
│  EXERCISE-BUILDER        │             │
│  • Riceve contract       │◄────────────┘
│  • Integra TikZ script   │
│  • Genera HTML completo  │
└──────────┬───────────────┘
           │
           ▼
┌──────────────────────────┐
│  LATEX-VALIDATOR         │
│  • Valida formule        │
│  • Corregge sintassi     │
│  • Return corrections    │
└──────────┬───────────────┘
           │
           ▼
┌─────────────────────────────────────────┐
│  ORCHESTRATOR → HTML FINALE             │
└─────────────────────────────────────────┘
```

## 🎯 Quando Usare Ogni Specialist

### 0. **workflow-manager.md** (Orchestratore Batch)
**Usa quando:**
- Hai 3+ esercizi da processare insieme
- Vuoi ottimizzazione automatica parallelizzata
- Serve raggruppamento intelligente per topic
- Vuoi evitare file intermedi (generazione in memoria)

**Input:** BatchProcessRequest JSON  
**Output:** BatchProcessResponse con insertion_summary

**Esempio:**
```bash
@workflow-manager "Processa 10 esercizi da questa cartella"
```

---

### 1. **image-extractor.md**
**Usa quando:**
- Hai screenshot di esercizi da libri
- Devi estrarre automaticamente numero, tipo, contenuto
- L'immagine contiene figure geometriche/grafici (rileva e segnala)

**Input:** Immagine + config JSON  
**Output:** ExerciseContract JSON + opzionalmente `tikz_figures[]`

**Esempio:**
```bash
@image-extractor "Analizza questo screenshot di 3 esercizi"
```

---

### 2. **tikz-generator.md**
**Usa quando:**
- Immagine contiene triangoli, poligoni, grafici
- Serve codice TikZ formattato HTML
- Devi personalizzare template da modelli_tikz_elements.json

**Input:** TikZRequest JSON  
**Output:** TikZResponse con `<script type="text/tikz">` HTML

**Esempio:**
```bash
@tikz-generator "Genera triangolo ABC con lati 3-4-5 cm"
```

---

### 3. **exercise-builder.md**
**Usa quando:**
- Hai già un ExerciseContract completo
- Devi generare HTML da JSON
- Vuoi integrare script TikZ nell'esercizio

**Input:** ExerciseContract JSON + opzionalmente TikZResponse[]  
**Output:** HTML completo esercizio

**Esempio:**
```bash
@exercise-builder "Genera HTML per questo contract"
```

---

### 4. **latex-validator.md**
**Usa quando:**
- Formule LaTeX potrebbero avere errori sintattici
- Devi validare delimitatori `\(` `\)` corretti
- Serve report di correzioni applicate

**Input:** LaTeXValidationRequest JSON  
**Output:** LaTeXValidationResponse con correzioni

**Esempio:**
```bash
@latex-validator "Valida queste 5 formule"
```

---

## 📋 Contracts JSON Standard

### ExerciseContract
```json
{
  "type": "TIPO1|TIPO2|TIPO3A|TIPO3B",
  "source": {...},
  "metadata": {
    "difficulty": 1-3,
    "page": 145,
    "number": "127",
    "topic": "Equazioni",
    "color": "#FF6B35"
  },
  "content": {...},
  "tikz_figures": [...]  // Opzionale
}
```

### TikZRequest
```json
{
  "figure_type": "geometric|graph|physics|table",
  "detected_shape": "triangle|polygon|...",
  "image_analysis": {
    "points": [...],
    "segments": [...],
    "colors": {...}
  }
}
```

### TikZResponse
```json
{
  "status": "success",
  "tikz_script": {
    "id": "tikz_xxx",
    "html": "<script>...</script>",
    "template_used": {"group": "...", "label": "..."}
  }
}
```

---

## 🔧 Integrazione Completa

### Flusso Tipico con Figure TikZ (Singolo Esercizio)

1. **Utente allega immagine** con triangolo e testo esercizio
2. **Image-Extractor:**
   - Estrae numero, tipo, contenuto
   - Rileva triangolo → aggiungi `tikz_figures[0]`
   - Return ExerciseContract
3. **Orchestrator** vede `tikz_figures` → delega a TikZ-Generator
4. **TikZ-Generator:**
   - Carica template "poligono" da modelli_tikz_elements.json
   - Personalizza coordinate da image_analysis
   - Return `<script type="text/tikz">...</script>`
5. **Exercise-Builder:**
   - Riceve contract + tikz HTML
   - Integra script dopo la domanda
   - Return HTML completo
6. **LaTeX-Validator (opzionale):**
   - Valida formule nell'HTML
   - Corregge errori sintattici
7. **Orchestrator** → output finale all'utente

---

### Flusso Batch Ottimizzato (10 Esercizi)

1. **Utente allega 10 immagini** o specifica cartella
2. **Orchestrator** rileva batch → delega a **Workflow-Manager**
3. **Workflow-Manager:**
   
   **Step 1 - Estrazione Parallela:**
   - Delega Image-Extractor per 10 immagini (parallelo)
   - Riceve 10 ExerciseContracts arricchiti
   - 6 hanno `tikz_figures`, 4 no
   
   **Step 2 - Generazione Parallela:**
   - Delega TikZ-Generator per 6 figure (parallelo)
   - Delega Exercise-Builder per 10 HTML in memoria (parallelo)
   - Integra script TikZ nei 6 esercizi appropriati
   
   **Step 3 - Validazione e Inserimento:**
   - Delega LaTeX-Validator per tutte formule
   - Raggruppa 10 HTML per topic (es: 5 "Teorema Pitagora", 3 "Aree", 2 "Perimetri")
   - Inserisce blocchi raggruppati nel PHP target
   
4. **Return BatchProcessResponse** con summary

**Timing:**
- ⚡ **Parallelo (Workflow-Manager):** 8 minuti
- 🐢 **Sequenziale (vecchio metodo):** 20 minuti
- 📊 **Risparmio:** 60%

---

## 📖 File di Riferimento

- **Orchestrator principale:** [`../orchestrator.md`](../orchestrator.md)
- **Workflow manager:** [`../workflow-manager.md`](../workflow-manager.md)
- **Template TikZ:** [`../../../modelli_tikz_elements.json`](../../../modelli_tikz_elements.json)
- **Funzioni JS processor:** [`../../../functions-mod.js`](../../../functions-mod.js)

---

## ⚠️ Regole Generali

### ✅ DO:
- Sempre comunicare via JSON contracts
- Delegare task specifici agli specialists appropriati
- Validare input/output secondo schema contracts
- Mantenere specialists **single-purpose**

### ❌ DON'T:
- Non mescolare responsabilità tra specialists
- Non generare HTML direttamente in Orchestrator
- Non modificare output di altri specialists senza contract
- Non accumulare context inutile

---

**Versione:** 1.0.0  
**Data:** Febbraio 2026  
**Progetto:** FisMatPant
