# Copilot Instructions - FisMatPant

Sistema di istruzioni per agenti Copilot specializzati nella generazione automatica di esercizi HTML con supporto LaTeX e TikZ.

## 📁 Struttura

```
.github/copilot-instructions/
├── orchestrator.md           ← Coordinatore principale (JSON contracts)
├── workflow-manager.md       ← Orchestratore batch ottimizzato
├── specialists/              ← Agent specializzati
│   ├── image-extractor.md    ← Analisi immagini → JSON contracts
│   ├── exercise-builder.md   ← JSON contracts → HTML esercizi
│   ├── latex-validator.md    ← Validazione formule LaTeX
│   ├── tikz-generator.md     ← Generazione figure TikZ
│   └── README.md            ← Documentazione specialists
└── README.md                ← Questo file
```

## 🏗️ Architettura

### Design Pattern: Agent Orchestration

Il sistema usa il pattern **Orchestrator + Specialists** dove:

1. **Orchestrator** (`orchestrator.md`)
   - Coordina workflow attraverso JSON contracts minimali
   - NON genera codice direttamente
   - Delega task agli specialists appropriati
   - Valida solo completezza strutturale del risultato

2. **Specialists** (`specialists/*.md`)
   - Ricevono contracts JSON con parametri essenziali
   - Eseguono task specifici (single-purpose)
   - Ritornano output strutturati
   - Comunicano solo tramite contracts

3. **Workflow Manager** (`workflow-manager.md`)
   - Orchestratore batch per 3+ esercizi
   - Ottimizza flussi riducendo step ridondanti (3 invece di 5)
   - Parallelizza operazioni per performance
   - Gestisce inserimento intelligente raggruppato per topic

---

## 🔄 Workflow Completo

### Caso d'Uso: Esercizio con Triangolo

```
┌────────────────────────────────────────────────────────────────┐
│ INPUT UTENTE                                                   │
│ "Crea esercizio 127: calcola area triangolo ABC"              │
│ [Allega immagine con triangolo]                               │
└─────────────────────┬──────────────────────────────────────────┘
                      │
                      ▼
┌────────────────────────────────────────────────────────────────┐
│ 🎯 ORCHESTRATOR                                                │
│ 1. Parse richiesta → tipo=TIPO1, topic="Geometria"           │
│ 2. Identifica immagine → delega image-extractor              │
└─────────────────────┬──────────────────────────────────────────┘
                      │
                      ▼
┌────────────────────────────────────────────────────────────────┐
│ 📸 IMAGE-EXTRACTOR                                            │
│ 1. Analizza immagine → rileva triangolo ABC                  │
│ 2. Estrae metadati → numero=127, difficoltà=2               │
│ 3. Rileva figura geometrica → triangolo con misure          │
│ 4. Output: ExerciseContract + tikz_figures[]                │
└─────────────────────┬──────────────────────────────────────────┘
                      │
                      ├─────────────────────┐
                      │                     │
                      ▼                     ▼
┌──────────────────────────────┐  ┌──────────────────────────────┐
│ 🎨 TIKZ-GENERATOR            │  │ 📝 EXERCISE-BUILDER          │
│ 1. Riceve tikz_figures[0]   │  │ 1. Riceve ExerciseContract   │
│ 2. Seleziona template        │  │ 2. Genera HTML base          │
│    "poligono" da JSON        │  │ 3. Attende TikZ script       │
│ 3. Personalizza coordinate   │  │                              │
│ 4. Genera <script>           │──┼►4. Integra script in HTML   │
│ 5. Output: TikZResponse      │  │ 5. Output: HTML completo     │
└──────────────────────────────┘  └──────────┬───────────────────┘
                                             │
                                             ▼
┌────────────────────────────────────────────────────────────────┐
│ ✅ LATEX-VALIDATOR (opzionale)                                │
│ 1. Estrae formule da HTML                                     │
│ 2. Valida sintassi LaTeX                                      │
│ 3. Corregge errori                                            │
│ 4. Output: Corrections JSON                                   │
└─────────────────────┬──────────────────────────────────────────┘
                      │
                      ▼
┌────────────────────────────────────────────────────────────────┐
│ 🎯 ORCHESTRATOR                                                │
│ 1. Riceve HTML + Corrections                                  │
│ 2. Applica correzioni se necessario                           │
│ 3. Return HTML finale all'utente                              │
└────────────────────────────────────────────────────────────────┘
```

---

## 📋 Contracts JSON

### ExerciseRequest
Input principale per generare un esercizio.

```json
{
  "type": "TIPO1|TIPO2|TIPO3A|TIPO3B",
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
    "topic": "Geometria piana",
    "color": "#FF6B35"
  },
  "content": {
    "question": "Calcola l'area del triangolo ABC...",
    "data": {"AB": "5 cm", "BC": "4 cm"},
    "unknowns": {"area": "?"}
  },
  "tikz_figures": [
    {
      "position": "after_question",
      "figure_type": "geometric",
      "detected_shape": "triangle",
      "image_analysis": {
        "points": [
          {"name": "A", "x": 0, "y": 0, "label": "$A$"}
        ]
      }
    }
  ]
}
```

### TikZRequest
Input per generare figure TikZ.

```json
{
  "figure_type": "geometric|graph|physics|table",
  "detected_shape": "triangle|polygon|function_graph",
  "image_analysis": {
    "points": [...],
    "segments": [...],
    "colors": {...}
  },
  "context": {
    "exercise_type": "TIPO1",
    "topic": "Geometria"
  }
}
```

### LaTeXValidationRequest
Input per validare formule.

```json
{
  "formulas": [
    {
      "id": "F1",
      "context": "main_question",
      "latex": "x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}"
    }
  ]
}
```

---

## 🎯 Quando Usare Cosa

### Scenario 1: Screenshot di Esercizio Libro
**Workflow:** Image-Extractor → TikZ-Generator (se figura) → Exercise-Builder → LaTeX-Validator

```bash
# Utente allega immagine
@orchestrator "Analizza questo esercizio e genera HTML"

# Orchestrator:
# 1. Delega @image-extractor → ExerciseContract + tikz_figures
# 2. Se tikz_figures → @tikz-generator → TikZResponse
# 3. Delega @exercise-builder con entrambi → HTML
# 4. Delega @latex-validator → Correzioni
# 5. Return HTML finale
```

### Scenario 2: Creazione Manuale Esercizio
**Workflow:** Exercise-Builder diretto

```bash
@exercise-builder "Crea TIPO3B: 4 opzioni su equazioni 2° grado"

# Exercise-Builder:
# 1. Genera contract minimale
# 2. Produce HTML
# 3. Return diretto
```

### Scenario 3: Solo Figura TikZ
**Workflow:** TikZ-Generator diretto

```bash
@tikz-generator "Triangolo isoscele con base 6cm e altezza 4cm"

# TikZ-Generator:
# 1. Seleziona template "poligono"
# 2. Personalizza misure
# 3. Return <script> HTML
```

### Scenario 4: Batch 10 Esercizi
**Workflow:** Workflow-Manager ottimizzato

`workflow-manager "Processa 10 screenshot dalla cartella verifiche/"

# Workflow-Manager:
# 1. Batch @image-extractor (parallelo, 10 immagini insieme)
# 2. Rilevate 6 con figure → Batch @tikz-generator (parallelo)
# 3. Batch @exercise-builder (in memoria, no file)
# 4. @latex-validator (validazione unica)
# 5. Inserimento PHP raggruppato per topic
# 6. Return BatchProcessResponse
# ⚡ Tempo: ~8 min (vs 20 min sequenziale)
```

Oppure direttamente:
```bash
@orchestrator "Processa questi 10 esercizi (veloce)"

# Orchestrator rileva batch → delega automaticamente a workflow-managernale
# 6. Return Array[10] HTML
```

---

## 🛠️ File di Supporto

### Template e Dati
- **TikZ Templates:** [`../../modelli_tikz_elements.json`](../../modelli_tikz_elements.json)
- **Config Sources:** `verifiche/configs/SOURCES_COMMON.json`
- **Config Exercises:** `verifiche/configs/*.json`

### Codice JavaScript
- **Content Processor:** [`../../functions-mod.js`](../../functions-mod.js)
- **TikZ Methods:** `functions-mod.js:4390-4440`
- **ID Generator:** `functions-mod.js:4436`

---

## ⚙️ Configurazione

### Setup Nuovo Specialist

1. Crea file `specialists/nuovo-specialist.md`
2. Definisci contracts INPUT/OUTPUT
3. Specifica regole operative (✅ DO / ❌ DON'T)
4. Aggiungi delegation pattern in `orchestrator.md`
5. Aggiorna questo README

### Esempio Template Specialist:
```markdown
# Nuovo Specialist

## [OBIETTIVO]
Cosa fa questo specialist

## [INPUT] Contract: InputRequest
```json
{...}
```

## [OUTPUT] Contract: OutputResponse
```json
{...}
```

## [PROCESS] Algoritmo
...

## [RULES]
✅ DO / ❌ DON'T
```

---

## 📚 Best Practices

### ✅ DO:
- **Comunicare sempre via JSON contracts** tra specialists
- **Delegare completamente** senza modificare output
- **Validare schema** contracts prima/dopo ogni operazione
- **Mantenere specialists single-purpose** (una responsabilità)
- **Documentare decisioni** nel workflow

### ❌ DON'T:
- **Non generare codice** direttamente in Orchestrator
- **Non mescolare responsabilità** tra specialists
- **Non accumulare context inutile** nei contracts
- **Non modificare** output di altri specialists
- **Non duplicare logica** tra più specialists

---

## 🎯 Quando Usare Cosa

### Decision Tree

```
┌─────────────────────────────────────┐
│  Quanti esercizi devi processare?  │
└─────────────┬───────────────────────┘
              │
   ┌──────────┴──────────┐
   │                     │
   ▼                     ▼
┌──────────┐      ┌──────────────┐
│ 1-2 Eser │      │ 3+ Esercizi  │
└─────┬────┘      └──────┬───────┘
      │                  │
      ▼                  ▼
┌─────────────────┐  ┌──────────────────────┐
│ ORCHESTRATOR    │  │ WORKFLOW-MANAGER     │
│ • Workflow std  │  │ • Workflow batch     │
│ • Delegazioni   │  │ • Parallelizzazione  │
│ • ~1-2 min/eser │  │ • ~30 sec/eser       │
└─────────────────┘  └──────────────────────┘
```

### Criterio Scelta Workflow

| Situazione | Usa | Perché |
|---|---|---|
| 1-2 esercizi semplici | **Orchestrator** | Overhead minimo, workflow lineare |
| 3+ esercizi | **Workflow-Manager** | Parallelizzazione, batch optimization |
| Singolo con TikZ complesso | **Orchestrator** | Focus su qualità singolo output |
| 10+ esercizi batch | **Workflow-Manager** | 60% risparmio tempo, topic grouping |
| Test/Preview | **Orchestrator** | Feedback immediato esercizio |
| Produzione bulk | **Workflow-Manager** | Performance, inserimento intelligente |

---

## 🚀 Quick Start

### Uso Base
```bash
# Analizza immagine e genera esercizio
@orchestrator "Crea esercizio da questa immagine"

# Genera solo figura TikZ
@tikz-generator "Triangolo rettangolo 3-4-5"

# Valida formule esistenti
@latex-validator "Correggi formule in questo HTML"
```

### Uso Avanzato
```bash
# Batch processing
@workflow-manager "Processa 20 esercizi da verifiche/images/"

# Con config custom
@image-extractor "Analizza con config: verifiche/configs/custom.json"

# Pipeline completa
@orchestrator "Analizza → Genera TikZ → Valida LaTeX → Output HTML"
```

---

## 📖 Documentazione Aggiuntiva

- **Orchestrator:** [orchestrator.md](./orchestrator.md) - Coordinatore principale
- **Workflow Manager:** [workflow-manager.md](./workflow-manager.md) - Ottimizzazioni workflow
- **Specialists README:** [specialists/README.md](./specialists/README.md) - Dettagli agents

---

**Versione:** 1.0.0  
**Data:** Febbraio 2026  
**Progetto:** FisMatPant - Sistema automatico generazione esercizi
