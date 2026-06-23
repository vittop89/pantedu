# Workflow Ottimizzato - Riduzione Step

## Problemi Identificati nel Workflow Precedente

1. **JSON intermedio duplicato**: Il contracts JSON era quasi identico al config iniziale
2. **Passaggio HTML inutile**: Generava file HTML separato prima di inserire nel PHP  
3. **Metadati incompleti**: Mancavano argomenti specifici, colori topic, posizione inserimento
4. **Topic generici**: Usava etichette come "TEST" invece di analizzare contenuto matematico

## Nuovo Workflow (3 Step invece di 5)

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

## Confronto Effort

### Before (5 step):
1. Config parsing (30 sec)
2. Image extraction → JSON minimal (1 min)
3. JSON → HTML per file output (1 min)  
4. Read HTML files (30 sec)
5. Insert in PHP (1 min)
**TOTALE: ~4 minuti**

### After (3 step):
1. Config parsing + Image extraction → JSON **completo** (1.5 min)
2. Generate HTML **in memoria** (30 sec)
3. Insert **con logica topic** (1 min)
**TOTALE: ~3 minuti** (25% riduzione)

## Benefici

✅ **JSON arricchito**: Tutti metadati estratti visibili in un solo punto
✅ **No file intermedi**: HTML generato solo in memoria
✅ **Topic intelligenti**: Argomenti specifici dal contenuto, non etichette generiche
✅ **Colori consistenti**: Stesso argomento = stesso colore
✅ **Inserimento contestuale**: Esercizi raggruppati per topic nel PHP

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

**PROSSIMO STEP**: Aggiornare `copilot-instructions-workflow-manager.md` con questa logica ottimizzata
