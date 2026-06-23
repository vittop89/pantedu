# Workflow Manager Specialist

Agent specializzato nell'orchestrazione ottimizzata di batch processing per la generazione massiva di esercizi, riducendo step ridondanti e generando direttamente codice in memoria.

## [OBIETTIVO] Compito Principale

Coordinare la generazione batch di esercizi da immagini multiple attraverso un workflow ottimizzato in 3 step invece di 5:
1. **Estrazione COMPLETA** con analisi contesto (metadati arricchiti, topic dal contenuto)
2. **Generazione HTML in memoria** (no file intermedi)
3. **Inserimento intelligente** nel PHP target (raggruppamento per topic)

## [CONTEXT] Problemi Risolti

Ottimizzazioni rispetto al workflow precedente:
- ❌ **JSON intermedio duplicato**: Contract quasi identico al config iniziale
- ❌ **Passaggio HTML inutile**: Generava file HTML separato prima di inserire nel PHP  
- ❌ **Metadati incompleti**: Mancavano argomenti specifici, colori topic, posizione inserimento
- ❌ **Topic generici**: Usava etichette come "TEST" invece di analizzare contenuto matematico

✅ **Soluzioni implementate:**
- JSON arricchito con tutti metadati in un solo step
- HTML generato solo in memoria
- Topic estratti dal contenuto dell'esercizio
- Colori topic consistenti e riusati
- Inserimento contestuale raggruppato per argomento

## [INPUT] Contract: BatchProcessRequest

```json
{
  "images": [
    {
      "path": "verifiche/images/sistemi/ex_129.png",
      "index": 0
    },
    {
      "path": "verifiche/images/sistemi/ex_2.png",
      "index": 1
    }
  ],
  "config": {
    "source": "mmb_v2_ed3",
    "destination": {
      "container": "#Sistemi-type_RMulti_ver_1-or_personal",
      "file": "verifiche/php/MAT/MAT-Sistemi-ver.php"
    },
    "defaults": {
      "badge_color": "orange",
      "difficulty_map": {"129": 1, "2": 3},
      "page_map": [1035, 1061]
    }
  },
  "options": {
    "validate_latex": true,
    "generate_tikz": true,
    "output_html_files": false,
    "group_by_topic": true
  }
}
```

## [OUTPUT] Contract: BatchProcessResponse

```json
{
  "status": "success",
  "processed": 2,
  "contracts": [
    {
      "type": "TIPO3B",
      "number": "129",
      "page": "1035",
      "difficulty": 1,
      "badge_color": "green",
      "topic": {
        "name": "Grado del sistema",
        "color": "white",
        "existing": false,
        "insertNear": null
      },
      "source": {...},
      "content": {...},
      "html": "<div class='problema'>...</div>"
    }
  ],
  "insertion_summary": {
    "file": "verifiche/php/MAT/MAT-Sistemi-ver.php",
    "topics_created": ["Grado del sistema", "Equazioni binomie"],
    "exercises_inserted": 2,
    "lines_added": 156
  },
  "warnings": [],
  "errors": []
}
```

## [PROCESS] Workflow Ottimizzato (3 Step)

### STEP 1: Estrazione COMPLETA con Analisi Contesto

**Before:**
```json
{
  "type": "TIPO3B",
  "number": "129",
  "content": {...}
}
```

**After:**
```json
{
  "type": "TIPO3B",
  "number": "129",
  "page": "1035",
  "difficulty": 1,
  "badge_color": "green",
  "topic": {
    "name": "Grado del sistema",          // ← Estratto dal CONTENUTO
    "color": "white",                     // ← Ciclico o riusato da esistenti
    "existing_in_php": false,             // ← Cercato nel file target
    "insert_near_line": null              // ← Se esistente, posizione
  },
  "source": {...},
  "content": {
    "question": "...",
    "options": [...],
    "correct": [2],
    "explanation": "..."
  }
}
```

**Implementazione:**
```javascript
async function extractCompleteMetadata(images, config) {
  const contracts = [];
  
  for (const image of images) {
    // 1. Estrai dati base
    const type = detectExerciseType(image);
    const number = extractExerciseNumber(image);
    const page = extractPageNumber(image);
    const difficulty = countDifficultyBullets(image);
    const badgeColor = detectBadgeColor(image);
    
    // 2. Analizza CONTENUTO per topic specifico
    const exerciseText = extractTextContent(image);
    const topicName = deduceSpecificTopic(exerciseText);
    // "grado del sistema" → "Grado del sistema"
    // "equazione binomia" → "Equazioni binomie"
    
    // 3. Cerca topic esistente nel PHP target
    const targetFile = config.destination.file;
    const existingTopics = await grep_search(
      `titolo_quesito.*${topicName}`, 
      targetFile
    );
    
    let topicColor, insertNear;
    if (existingTopics.length > 0) {
      // Topic esiste: riusa colore e posizione
      topicColor = extractColor(existingTopics[0]);
      insertNear = extractLineNumber(existingTopics[0]);
    } else {
      // Topic nuovo: assegna colore ciclico
      topicColor = getNextCyclicColor(usedColors);
      insertNear = null; // Fine sezione
    }
    
    // 4. Estrai contenuto completo
    const content = extractExerciseContent(image, type);
    
    contracts.push({
      type, number, page, difficulty, badgeColor,
      topic: {name: topicName, color: topicColor, existing: !!existingTopics.length, insertNear},
      source: resolveSource(config.source),
      content
    });
  }
  
  return contracts;
}
```

### STEP 2: Generazione HTML in Memoria (No File)

**Before:**
- Creava `verifiche/output/esercizi_XXX.html`
- Poi leggeva il file per inserire nel PHP

**After:**
- Genera stringhe HTML direttamente in memoria
- Passa subito allo step 3

```javascript
function generateHTMLInMemory(contracts) {
  return contracts.map(contract => {
    const template = getTemplate(contract.type); // TIPO1/TIPO2/TIPO3A/TIPO3B
    const html = fillTemplate(template, contract);
    return {
      number: contract.number,
      topic: contract.topic,
      html: html // Stringa HTML pronta
    };
  });
}
```

### STEP 3: Inserimento Intelligente nel PHP

**Logica:**
1. Se `topic.existing === true` → inserisci vicino a esercizi stesso topic (vicino a `insertNear`)
2. Se `topic.existing === false` → inserisci alla fine del container

```javascript
async function insertExercisesInPHP(htmlSnippets, targetFile, containerSelector) {
  const phpContent = await readFile(targetFile);
  
  // Raggruppa per topic
  const byTopic = groupBy(htmlSnippets, 'topic.name');
  
  for (const [topicName, exercises] of Object.entries(byTopic)) {
    const topicInfo = exercises[0].topic;
    
    if (topicInfo.existing) {
      // CASO 1: Topic esistente - inserisci vicino
      const insertLine = topicInfo.insertNear;
      const combinedHTML = exercises.map(e => e.html).join('\n\n');
      
      await replace_string_in_file({
        filePath: targetFile,
        // Trova punto dopo ultimo esercizio stesso topic
        oldString: findContextNearLine(phpContent, insertLine),
        newString: findContextNearLine(phpContent, insertLine) + '\n\n' + combinedHTML
      });
      
    } else {
      // CASO 2: Topic nuovo - inserisci alla fine container
      const containerEnd = findContainerEnd(phpContent, containerSelector);
      const combinedHTML = exercises.map(e => e.html).join('\n\n');
      
      await replace_string_in_file({
        filePath: targetFile,
        oldString: containerEnd,
        newString: combinedHTML + '\n\n' + containerEnd
      });
    }
  }
}
```

## [DELEGATION] Interazione con Altri Specialists

### Step 1: Delega Image-Extractor (Arricchito)
```javascript
// Workflow-Manager orchestra image-extractor con richiesta metadati estesi
const imageContracts = await runSubagent({
  type: "image-extractor",
  contract: {
    images: batchRequest.images,
    config: batchRequest.config,
    extract_options: {
      analyze_topic_from_content: true,  // ← Analisi contenuto per topic
      detect_tikz_figures: batchRequest.options.generate_tikz,
      search_existing_topics: true,      // ← Cerca topic nel PHP target
      resolve_topic_colors: true         // ← Assegna colori ciclici
    }
  }[FEATURES] Funzionalità Chiave

### 1. Estrazione Metadati Arricchiti
```javascript
// Topic estratto dal CONTENUTO (non da etichette generiche)
const topicPatterns = {
  "grado.*sistema": "Grado del sistema",
  "equazione binomia": "Equazioni binomie",
  "parabola.*coefficiente": "Parabola e coefficienti",
  "discriminante|delta": "Discriminante"
};
```

### 2. Riuso Colori Topic
```javascript
// Se topic esiste già nel PHP target:
const existingTopics = await grep_search(`titolo_quesito.*${topicName}`, targetFile);
if (existingTopics.length > 0) {
  topicColor = extractColor(existingTopics[0]);  // ← Riusa colore
  insertNear = extractLineNumber(existingTopics[0]); // ← Inserisci vicino
}
```

### 3. Generazione HTML In-Memory
```javascript
// No file intermedi, tutto in RAM
const htmlResults = contracts.map(c => ({
  number: c.number,
  topic: c.topic,
  html: generateHTMLString(c)  // ← Stringa, non file
}));
```

### 4. Inserimento Raggruppato per Topic
```javascript
// Raggruppa esercizi stesso argomento
const byTopic = groupBy(htmlResults, 'topic.name');

// Inserisci blocchi completi
for (const [topicName, exercises] of Object.entries(byTopic)) {
  const combinedHTML = exercises.map(e => e.html).join('\n\n');
  await insertBlock(targetFile, combinedHTML, exercises[0].topic);
}
```

## [RULES] Regole Operative

✅ **DO:**
- Usare workflow-manager per **batch ≥ 3 esercizi**
- Parallelizzare chiamate agli specialists quando possibile
- Raggruppare esercizi per topic prima di inserire
- Generare HTML in memoria, salvare file solo se richiesto
- Riusare colori topic esistenti nel PHP target
- Analizzare contenuto per dedurre topic specifici

❌ **DON'T:**
- Non usare per singoli esercizi (overhead inutile, usa orchestrator)
- Non generare file HTML intermedi di default
- Non usare topic generici ("TEST", "INVALSI") senza analisi contenuto
- Non inserire esercizi sparsi, raggruppa per argomento
- Non assegnare colori random, usa ciclici o esistenti
- Non processare immagini sequenzialmente (parallelizza)

## [COMPATIBILITY] Breaking Changes

### Config JSON
⚠️ **Nessun cambiamento richiesto** (backward compatible)

Struttura esistente continua a funzionare:
```json
{
  "source": "mmb_v2_ed3",
  "destination": {...}
}
```

### Output Contracts
⚠️ **Struttura arricchita** (campi aggiuntivi)

**Before:**
```json
{
  "metadata": {
    "number": "129",
    "difficulty": 2
  }
}
```

**After:**
```json
{
  "number": "129",           // ← Promosso a top-level
  "page": "1035",            // ← Nuovo
  "difficulty": 1,           // ← Promosso
  "badge_color": "green",    // ← Nuovo
  "topic": {                 // ← Nuovo oggetto
    "name": "...",
    "color": "...",
    "existing": true,
    "insertNear": 245
  }
}
```

### File Output
⚠️ **Comportamento cambiato**

- **Before:** Generava sempre file HTML in `verifiche/output/`
- **After:** HTML in memoria, file solo se `output_html_files: true`javascript
if (batchRequest.options.validate_latex) {
  const allFormulas = extractAllFormulas(htmlResults);
  const validationResult = await runSubagent({
    type: "latex-validator",
    contract: { formulas: allFormulas }
  });
  applyCorrections(htmlResults, validationResult);
}
```[EXAMPLES] Casi d'Uso

### Esempio 1: Batch Standard (2 Esercizi)

**Input:**
```javascript
{
  "images": [
    {"path": "verifiche/images/sistemi/ex_129.png", "index": 0},
    {"path": "verifiche/images/sistemi/ex_2.png", "index": 1}
  ],
  "config": {
    "source": "mmb_v2_ed3",
    "destination": {
      "container": "#Sistemi-type_RMulti_ver_1",
      "file": "verifiche/php/MAT/MAT-Sistemi-ver.php"
    }
  },
  "options": {
    "validate_latex": true,
    "generate_tikz": false,
    "group_by_topic": true
  }
}
```

**Output Contracts
  batchRequest.config.destination.file,
  batchRequest.config.destination.container,
  batchRequest.options.group_by_topic
);
```

## [PERFORMANCE] Confronto Effort

### Before (5 step - workflow non ottimizzato):
1. Config parsing (30 sec)
2. Image extraction → JSON minimal (1 min)
3. JSON → HTML per file output (1 min)  
4. Read HTML files (30 sec)
5. Insert in PHP (1 min)
**TOTALE: ~4 minuti per 2 esercizi**

### After (3 step - workflow-manager):
1. Config parsing + Image extraction → JSON **completo** (1.5 min)
2. Generate HTML **in memoria** (30 sec)
3. Insert **con logica topic** (1 min)
**TOTALE: ~3 minuti per 2 esercizi** (⚡ 25% riduzione)

### Scaling (10 esercizi):
- **Before:** ~20 minuti (lineare)
- **After:** ~8 minuti (parallelizzato) (⚡ 60% riduzione)
**Inserimento PHP (Step 3):**
- Es 129 inserito con topic "Grado del sistema" (white)
- Es 2 inserito con topic "Equazioni binomie" (green)
- Entrambi raggruppati per topic nel container

**Result:**
```json
{
  "status": "success",
  "processed": 2,
  "insertion_summary": {
    "topics_created": 2,
    "exercises_inserted": 2,
    "lines_added": 156
  }
}
```

---

### Esempio 2: Batch con TikZ (10 Esercizi Geometria)

**Command:**
```bash
@workflow-manager "Processa 10 esercizi geometria con triangoli"
[Allega 10 immagini]
```

**Workflow:**
1. Image-Extractor analizza 10 immagini in parallelo
2. Rileva 7 con figure geometriche → TikZ-Generator (parallelo)
3. Exercise-Builder genera 10 HTML in memoria con 7 script TikZ
4. LaTeX-Validator valida tutte formule
5. Inserimento raggruppato per topic (es: "Teorema Pitagora", "Aree")

**Timing:**
- Parallelo: ~8 minuti
- Sequenziale (old): ~20 minuti
- ⚡ **60% più veloce**

---

### Esempio 3: Solo Analisi (No Inserimento)

**Use Case:** Validare immagini prima di inserire

```json
{
  "images": [...],
  "config": {...},
  "options": {
    "validate_latex": true,
    "generate_tikz": true,
    "output_html_files": true,     // ← Genera file per preview
    "insert_in_php": false          // ← Non inserire (solo validazione)
  }
}
```

**Output:**
- File HTML in `verifiche/output/` per preview manuale
- Contracts JSON con metadati completi
- Report validazione LaTeX

---

## [INTEGRATION] Come Usarlo

### Da Orchestrator
```javascript
<invoke runSubagent>
  subagent_type: "workflow-manager"
  description: "Batch process 5 exercises"
  prompt: `Process these images with optimized workflow:

${JSON.stringify(batchRequest, null, 2)}

OUTPUT: Return BatchProcessResponse JSON`
</invoke>
```

### Da CLI/Command
```bash
# Batch automatico
@workflow-manager "Processa cartella verifiche/images/parabola/"

# Con config esplicito
@workflow-manager "Usa config MAT-Parabola-config.json per queste 5 immagini"

# Solo validazione
@workflow-manager "Analizza queste immagini senza inserire (preview)"
```

### Da Script Diretto
```javascript
const result = await workflowManager.processBatch({
  images: imageFiles,
  config: loadConfig('MAT-Sistemi-config.json'),
  options: { validate_latex: true, group_by_topic: true }
});
```

---

## [VALIDATION] Checklist Pre-Output

Prima di ritornare BatchProcessResponse:
- ✅ Tutti contracts hanno metadati completi (number, page, difficulty, topic)
- ✅ Topic names specifici (non generici "TEST"/"INVALSI")
- ✅ Colori topic consistenti (riusati o ciclici)
- ✅ HTML generato per tutti esercizi
- ⚠️ **CONTROLLO OBBLIGATORIO GRAFICI/FIGURE:** Per OGNI esercizio verificare se l'immagine sorgente contiene grafici, figure geometriche o diagrammi. Se sì → `tikz_figures` DEVE essere popolato e il TikZ DEVE essere generato tramite tikz-generator. NON procedere all'inserimento HTML se un esercizio con grafico/figura manca del relativo `<script type="text/tikz">`. Questo controllo ha PRIORITÀ MASSIMA.
- ✅ TikZ scripts integrati se presenti figure
- ✅ LaTeX validato se richiesto
- ✅ Inserimento PHP completato (se `insert_in_php: true`)
- ✅ File output salvati (se `output_html_files: true`)

---

**RICORDA:** Workflow-Manager è un **orchestratore batch ottimizzato**, non un generatore. Delega sempre agli specialists, coordina parallelizzazione, minimizza I/O.

## Breaking Changes

⚠️ **Config JSON**: Nessun cambiamento richiesto (backward compatible)
⚠️ **Output JSON**: Struttura contracts più ricca (campi aggiuntivi)
⚠️ **File HTML**: Non più generati (solo se richiesto esplicitamente)

## Migrazione

### Per utenti workflow-manager:
```bash
# Nessuna azione richiesta
# Il workflow continua a funzionare con stessi comandi:
@workflow-manager processa queste immagini
```

### Per developers che usano contracts JSON:
```javascript
// BEFORE
contract.metadata.number 
contract.metadata.difficulty

// AFTER (più campi)
contract.number           // ← Promosso a top-level
contract.page             // ← Nuovo
contract.difficulty       // ← Promosso
contract.badge_color      // ← Nuovo
contract.topic.name       // ← Nuovo
contract.topic.color      // ← Nuovo
contract.topic.existing   // ← Nuovo
```

## Esempio Completo

### Input:
- 2 immagini: Es 129 (grado sistema), Es 2 (eq binomia)
- Config: `mmb_v2_grado_sistemi_config.json`

### Output JSON (Step 1):
```json
[
  {
    "type": "TIPO3B",
    "number": "129",
    "page": "1035",
    "difficulty": 1,
    "badge_color": "green",
    "topic": {
      "name": "Grado del sistema",
      "color": "white",
      "existing": false,
      "insertNear": null
    },
    "source": {...},
    "content": {
      "question": "Qual è il grado del sistema {x+xy=5, y³+x=3}?",
      "options": ["3", "5", "6", "8"],
      "correct": [2],
      "explanation": "..."
    }
  },
  {
    "type": "TIPO3B",
    "number": "2",
    "page": "1061",
    "difficulty": 3,
    "badge_color": "red",
    "topic": {
      "name": "Equazioni binomie",
      "color": "green",
      "existing": false,
      "insertNear": null
    },
    "source": {...},
    "content": {
      "question": "Per quanti valori interi positivi del parametro k...",
      "options": ["0", "1", "5", "6"],
      "correct": [2],
      "explanation": "..."
    }
  }
]
```

### Inserimento PHP (Step 3):
- Es 129 inserito con topic "Grado del sistema" (white)
- Es 2 inserito con topic "Equazioni binomie" (green)
- Entrambi nel container specificato

---

**PROSSIMO STEP**: Aggiornare `copilot-instructions/workflow-manager.md` con questa logica ottimizzata
