# Agents Directory — v3.0

Agent specializzati per la generazione esercizi FisMatPant.

## Struttura

```
.github/agents/
├── workflow-manager.md              # Orchestratore (mode instructions)
├── rules/
│   ├── html-templates.md            # Generazione HTML per tipo (subagent)
│   ├── extraction-rules.md          # Regole estrazione da immagine
│   ├── latex-rules.md               # Formule e notazione LaTeX
│   ├── style-rules.md               # Badge, explanation, struttura HTML
│   └── config-rules.md              # Gestione template_exercise.json
├── config/
│   ├── SOURCES.json                 # Fonti bibliografiche
│   ├── schemas.json                 # FlatContract, ExerciseContract, errori
│   ├── defaults.json                # Policy inserimento e soluzione
│   ├── guards.json                  # Guardrail inserimento
│   └── template_exercise.json       # Template per nuovi batch
└── README.md
```

## Flusso

```
Preflight → Step 1 (estrazione) → Step 2 (HTML) → Step 3 (inserimento)
   WM           WM               subagent            WM
```

1. **Preflight**: WM legge config + scansiona PHP target → congela anchor e topic
2. **Step 1**: WM analizza immagine → FlatContract JSON (estrazione diretta)
3. **Step 2**: WM delega a subagent che legge `rules/html-templates.md` → HTML
4. **Step 3**: WM inserisce HTML nel PHP target usando anchor congelati

## Moduli

| File | Contenuto |
|------|-----------|
| `workflow-manager.md` | Orchestratore: preflight, estrazione, delegazione, inserimento |
| `rules/html-templates.md` | `generateBibHeader()`, `calculateOptimalColumns()`, template HTML per tipo, validazione |
| `rules/extraction-rules.md` | Regole estrazione, traduzione EN→IT, struttura content, assemblaggio FlatContract |
| `rules/latex-rules.md` | Tabella conversione simboli, delimitatori, stile svolgimento |
| `rules/style-rules.md` | Badge colors, topic colors, struttura `collex-item`, giustsol, tabelle |
| `rules/config-rules.md` | Gestione template_exercise.json, reset batch, page_map, output Step 1A |
| `config/schemas.json` | Schemi FlatContract/ExerciseContract, catalogo errori canonici |
| `config/defaults.json` | Policy inserimento (by_topic, append), policy soluzioni |
| `config/guards.json` | Preflight freeze, anchor policy, dedup, checksum |

## Changelog

### v3.0 (2025-06-28)
- Struttura modulare: `rules/` per regole separate, `config/` per configurazioni
- `exercise-builder.md` → `rules/html-templates.md` (autosufficiente per subagent)
- `shared-rules.md` → split in `rules/latex-rules.md` + `rules/style-rules.md`
- Nuovo `rules/extraction-rules.md` (da workflow-manager Step 1 + old image-extractor)
- `system_defaults.json` → `config/defaults.json` + `config/guards.json` + `config/schemas.json`
- `SOURCES_COMMON.json` → `SOURCES.json`
- Ciclo colori topic gestito dal frontend JS (`_enforceTopicColorCycle`)

### v2.0 (2025-06-27)
- `image-extractor.md` rimosso → logica integrata in workflow-manager
- Config consolidati in `system_defaults.json`
- `exercise-builder.md` reso autosufficiente