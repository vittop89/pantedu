# Workflow Manager — Orchestratore Esercizi

Orchestratore unico per generazione esercizi (1 o N). Legge immagini, estrae contenuti, delega generazione HTML, inserisce nel PHP target.

**Regole modulari** (file di riferimento):
- `rules/extraction-rules.md` — estrazione da immagine, traduzione, FlatContract
- `rules/html-templates.md` — generazione HTML (letto dal subagent)
- `rules/latex-rules.md` — formule e notazione LaTeX
- `rules/style-rules.md` — badge, explanation, struttura HTML
- `rules/config-rules.md` — gestione config, reset template, pagine, formato output
- `config/schemas.json` — schemi contract, catalogo errori
- `config/defaults.json` — policy inserimento e soluzione
- `config/guards.json` — guardrail inserimento

---

## Architettura

| Step | Chi | Cosa |
|------|-----|------|
| **Step 1A** | WM | Analisi immagine → auto-fill campi config (`scope_type`, `type_map`, metadati) |
| **🔒 Checkpoint** | Utente | Revisione config compilato → OK / correzioni |
| Preflight | WM | Config merge + PHP scan → anchor congelati |
| Step 1B | WM | Estrazione contenuti completi → FlatContract JSON |
| Step 2 | `runSubagent` | Contract → HTML (legge `rules/html-templates.md`) |
| Step 3 | WM | Inserimento HTML nel PHP target |

> **Il WM analizza le immagini direttamente** perché `runSubagent` non ha accesso agli allegati immagine della conversazione.

---

## Interpretazione prompt

Un prompt *"carica questi esercizi"* è sufficiente **se** allegati: immagini + file JSON config (anche parziale).

1. Analizza le immagini → compila automaticamente i campi config (**Step 1A**)
2. Presenta il config compilato all'utente → **ATTENDI OK**
3. Solo dopo OK: Preflight + estrazione completa + generazione HTML + inserimento

Se `target_numbers` contiene numeri non estratti → **BLOCCA** (`TARGET_MISMATCH`).

---

## Preflight

1. Risolvi `source` da `config/SOURCES.json` se stringa
2. Normalizza container: rimuovi `#` iniziale
3. Leggi file PHP target **UNA SOLA VOLTA** → congela:

```
preflight_context = {
  last_by_topic:      { topicName → ultimo_numero },
  insertion_anchors:  {
    topicName → {
      anchor_line: int,
      anchor_excerpt: "30 chars",
      container_end_line: int
    }
  },
  solution_format_ref: {
    topicName → { samples: [{ number, difficulty, sol_excerpt, has_points }] }
  },
  target_checksum: "sha256:..."
}
```

4. **MAI rileggere il PHP target dopo il preflight** → vedi `config/guards.json`

### Colore topic

Frontend gestisce automaticamente (`_enforceTopicColorCycle`).
Nell'HTML generato: sempre `background-color: white`.

---

## Step 1A — Auto-fill Config da Immagine

> Questo step è **indipendente** da `destination.file` e `destination.targets.*.container`.
> Scopo: popolare automaticamente i campi `defaults` di ogni target nel config JSON.

### Cosa fare

1. **Analizzare le immagini** allegate per identificare tutti gli esercizi visibili
2. Per ogni esercizio, determinare **autonomamente** dall'immagine:

| Campo da compilare | Come determinarlo |
|---|---|
| `target_numbers` | Numeri nei riquadri colorati dell'immagine |
| `scope_type` / `type_map` | Analisi struttura esercizio (vedi tabella sotto) |
| `badge_color` | Colore del riquadro del numero nell'immagine |
| `difficulty_map` | Livello difficoltà (pallini/indicatori visibili) |
| `page_map` | Numero pagina se visibile nell'immagine |
| `topics` | Titolo sezione/paragrafo visibile nell'immagine |

### Identificazione autonoma `scope_type` / `type_map`

Determinare il tipo di esercizio **esclusivamente dall'analisi del contenuto dell'immagine**:

| Struttura nell'immagine | → `scope_type` | Note |
|---|---|---|
| Problema con dati numerici, formula, richiesta di calcolo | `type_Collect` | Esercizio con svolgimento algebrico |
| Affermazioni Vero/Falso | `type_VF` | Lista di statement con V/F |
| Domanda + affermazioni da valutare (a, b, c…) | `type_RMultiA` | Statements con lettera, ciascuno vero/falso |
| Domanda + opzioni scelta multipla (A, B, C, D) | `type_RMultiB` | Una sola risposta corretta tra le opzioni |

Se il tipo **non è determinabile con sicurezza** → segnalare con `"type": "UNKNOWN"` e `requiresUserValidation: true`.

### Raggruppamento in target

- Esercizi dello **stesso tipo** vanno raggruppati nello stesso target
- Se esistono già target nel config → associare per tipo compatibile
- Se nessun target è compatibile → proporre un nuovo target con `scope_type` identificato
- `container` e `file` restano **invariati** (o vuoti se non forniti) — verranno confermati dopo

### Output Step 1A

> Regole complete di formato: `rules/config-rules.md`

1. **Reset** del `template_exercise.json`: svuotare tutti i campi numerici (`target_numbers`, array in `badge_color`, `difficulty_map`, `page_map`, `topics`, `type_map`)
2. **Ripopolare** solo con gli esercizi del batch corrente (estratti dalle immagini)
3. **Pagine**: inserire in `page_map` solo se visibili nell'immagine. Se non visibili → usare chiavi vuote (`""`, `"_2"`, `"_3"`) raggruppando gli esercizi per immagine di provenienza. Per immagini diverse con pagine diverse → chiavi separate.
4. **Scrivere fisicamente** il file `template_exercise.json` aggiornato usando `replace_string_in_file` o simili — **NON mostrare il JSON in chat**
5. **Presentare** al checkpoint: breve riepilogo testuale (2-3 righe). **NO tabelle riassuntive, NO JSON in chat.**

### 🔒 Checkpoint — Attesa conferma utente

**BLOCCARE l'esecuzione** e chiedere conferma all'utente:
- Breve riepilogo testuale: quanti esercizi, in quali target, eventuali anomalie
- Il file `template_exercise.json` è **già stato aggiornato** — l'utente può aprirlo per revisione
- Indicare eventuali esercizi con `type: "UNKNOWN"` o pagine non visibili
- L'utente può: **confermare**, **correggere** tipi/metadati, **escludere** esercizi

> **NON** mostrare tabelle riassuntive — il JSON è sufficiente.
> **NON procedere a Preflight, Step 1B, Step 2 o Step 3 senza OK esplicito dell'utente.**

---

## Step 1B — Estrazione Contenuti Completi

> Eseguito **solo dopo OK utente** dal checkpoint.
> Regole complete: `rules/extraction-rules.md`

Il WM analizza le immagini allegate e produce FlatContract (→ `config/schemas.json`) per ogni esercizio confermato in-scope.
Regole chiave: numero da riquadro colorato, trascrizione fedele, **traduzione EN → IT obbligatoria**, rimuovere pre-testo.

---

## Step 2 — Generazione HTML (delegata)

Per ogni FlatContract, normalizzare in ExerciseContract:

```json
{
  "type": "...",
  "source": { "code": "...", "title": "...", "volume": "...", "publisher": "...", "authors": "..." },
  "metadata": {
    "difficulty": "1", "page": "393", "number": "25",
    "badge_color": "blue", "topic": "nome topic"
  },
  "content": { "..." }
}
```

Chiamare `runSubagent("exercise-builder", prompt)` con:
- Il contract JSON normalizzato
- Istruzione: `"Leggi .github/agents/rules/html-templates.md e .github/agents/rules/latex-rules.md prima di generare HTML"`
- Se `solution_format_ref` contiene campioni di soluzioni esistenti nello stesso container → **includerli nel prompt** come riferimento stile
- **NON** includere regole inline — il builder ha tutto nei file di regole

### 🔒 Persistenza risultati subagent (OBBLIGATORIA)

> Guardrail: `config/guards.json` → `subagent_persistence`

**TUTTI** i risultati dei subagent devono essere salvati su file, **MAI** fare affidamento sul return inline.
Motivo: il context summarization delle conversazioni lunghe elimina i risultati inline; solo i file su disco sopravvivono.

1. Prima di chiamare il subagent, definire il path di output: `.github/agents/_temp_results/step2_{target}_{batch}.html`
2. Nel prompt del subagent includere: `"Scrivi il risultato HTML COMPLETO nel file {path} usando create_file. NON restituirlo come messaggio."`
3. Dopo il subagent, **leggere il file** per verificare il contenuto
4. Dopo Step 3 completato, eliminare la cartella `_temp_results`

### Ottimizzazione prompt subagent

Passare **SOLO** i dati minimi: contract JSON + path dei 2 file istruzioni + path file output.
NON ri-spiegare regole HTML, badge, colori, LaTeX.

---

## Step 3 — Inserimento nel PHP Target

> Guardrail completi: `config/guards.json`

1. **Usa anchor congelati** dal preflight — MAI riscoprirli
2. Raggruppa snippet per topic
3. Per ogni topic:
   - Topic **esistente** → inserisci dopo anchor congelato
   - Topic **nuovo** → inserisci a fine container
4. Verifica: no duplicati (stesso numero nello stesso container)

---

## Regole Operative

| ✅ DO | ❌ DON'T |
|-------|---------|
| Step 1A: auto-fill config **solo da immagine** | Chiedere all'utente dati ricavabili dall'immagine |
| **Reset config** prima di compilare (svuotare numeri batch precedente) | Lasciare numeri di batch precedenti nel config |
| Determinare `scope_type`/`type_map` dall'analisi struttura | Indovinare il tipo senza evidenze visive |
| Inserire pagine in `page_map` **solo se visibili** nell'immagine | Inventare o indovinare numeri di pagina non visibili |
| **Scrivere fisicamente** il `template_exercise.json` con tool di editing | Mostrare il JSON config aggiornato nella chat |
| Checkpoint: breve riepilogo testuale (2-3 righe) | Mostrare tabelle riassuntive ASCII/markdown |
| **Attendere OK utente** prima di procedere oltre Step 1A | Procedere a Preflight/Step 1B/2/3 senza conferma |
| Leggere PHP target **una sola volta** in preflight | Rileggere il PHP dopo il preflight |
| Estrarre contenuti dalle immagini direttamente | Delegare estrazione immagine (subagent non le vede) |
| Delegare generazione HTML via subagent | Generare HTML direttamente |
| Tradurre sempre EN → IT | Lasciare testo in inglese |
| Usare anchor congelati per inserimento | Riscoprire anchor durante Step 3 |
| Topic color: `background-color: white` | Assegnare colori topic manualmente |
| Prompt subagent minimale: JSON + path + sol samples | Prompt con regole duplicate |
| **Salvare OGNI risultato subagent su file** (`_temp_results/`) | Fare affidamento su return inline del subagent |
| Compilare `defaults` indipendentemente da `file`/`container` | Richiedere `file`/`container` per compilare metadati |
| Passare `solution_format_ref` al subagent se disponibile | Ignorare lo stile degli esercizi esistenti |
| **`\fcolorbox` sempre dentro `\(…\)`** | `\fcolorbox` fuori dai delimitatori LaTeX |
| **MAI** usare `\checkmark`, `\xmark`, `\square` | Usare simboli LaTeX per V/F (usare classi CSS) |

---

## Gestione errori

Formato e catalogo completo: `config/schemas.json` → `error_catalog`

```json
{ "code": "TARGET_MISMATCH", "severity": "critical", "human_message": "...", "details": {} }
```

---

## Config Files

```
config/
├── SOURCES.json             ← fonti bibliografiche
├── schemas.json             ← FlatContract, ExerciseContract, errori
├── defaults.json            ← policy inserimento e soluzione
├── guards.json              ← guardrail inserimento
└── template_exercise.json   ← template per nuovi batch
```