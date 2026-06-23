# Config Rules — Gestione template_exercise.json

Regole per la compilazione e presentazione del config durante Step 1A.

---

## Reset config ad ogni batch

> **REGOLA FONDAMENTALE:** il `template_exercise.json` deve essere **ripulito** da tutti i numeri di esercizi precedenti prima di essere compilato con il nuovo batch.

### Procedura

1. **Leggere** il `template_exercise.json` esistente per ottenere la struttura target (nomi target, container, scope_type)
2. **Svuotare** tutti i campi numerici inside `defaults`:
   - `target_numbers` → `[]`
   - `target_entries` → `[]`
   - Ogni array dentro `badge_color`, `difficulty_map`, `page_map`, `topics`, `type_map` → `[]`
3. **Ripopolare** solo con i numeri degli esercizi del batch corrente (quelli visibili nelle immagini allegate)
4. I campi strutturali (`container`, `scope_type`, `file`, `source`) restano **invariati**

### Esempio

Prima (residuo batch precedente):
```json
"target_numbers": ["30","31"],
"badge_color": { "red": ["30","31"], "blue": [] }
```

Dopo reset + nuovo batch:
```json
"target_numbers": ["43","44"],
"badge_color": { "red": [], "blue": ["43","44"] }
```

---

## Gestione pagine (`page_map`)

> **NON inventare numeri di pagina.**

### Regole

| Situazione | Azione |
|---|---|
| Numero pagina **visibile** nell'immagine | Usarlo come chiave nel `page_map` |
| Numero pagina **NON visibile** | Usare `""` (stringa vuota) come chiave |
| Più immagini con pagine diverse | Creare una chiave `page_map` separata per ogni pagina visibile |
| Più immagini senza pagina visibile | Creare **una chiave `""` per ogni immagine** con gli esercizi di quella immagine |

### Raggruppamento per immagine — OBBLIGATORIO

> Gli esercizi devono **SEMPRE** essere raggruppati per immagine di provenienza nel `page_map`, anche quando la pagina non è visibile.
> Questo permette di tracciare la provenienza visiva di ogni esercizio.

Se le pagine non sono visibili, usare chiavi vuote distinte per immagine. Poiché JSON non ammette chiavi duplicate, usare il formato: `""`, `"_2"`, `"_3"`, ecc.

### Esempi

Pagine visibili:
```json
"page_map": {
  "393": ["43","44"],
  "394": ["45","46","47","48","51"]
}
```

Pagine NON visibili (3 immagini):
```json
"page_map": {
  "": ["43"],
  "_2": ["44","45","46","47","48"],
  "_3": ["51"]
}
```

Mix (alcune visibili, altre no):
```json
"page_map": {
  "393": ["43","44"],
  "": ["45","46"]
}
```

---

## Output Step 1A — Scrittura file e presentazione

### ⚠️ REGOLA CRITICA: scrivere il file, non mostrarlo in chat

> Il `template_exercise.json` deve essere **modificato fisicamente** (con `replace_string_in_file` o riscrittura completa).
> **MAI** mostrare il JSON aggiornato nella chat come output.
> L'utente lo revisionerà aprendo il file nel suo editor.

### ❌ NON fare

- **NON** generare tabelle riassuntive (ASCII table, markdown table, ecc.)
- **NON** incollare il JSON config aggiornato nella chat
- **NON** duplicare informazioni già nel file

### ✅ Cosa fare

1. **Scrivere** il file `template_exercise.json` aggiornato (reset + nuovi esercizi) usando gli strumenti di editing file
2. **Presentare in chat** solo un breve commento testuale (2-4 righe) che riassuma:
   - Quanti esercizi identificati e in quali target
   - Eventuali anomalie: `type: "UNKNOWN"`, pagine non visibili
   - Nuovi topic individuati
3. Indicare che il file è stato aggiornato e l'utente può rivederlo

Formato esempio:
```
Identificati 7 esercizi (43–48, 51), tutti type_Collect → target `collect_problems`.
Nuovo topic: "espansione di volume dei solidi" (es. 45–48, 51).
Pagine: non visibili nelle immagini → page_map vuoto.

File `template_exercise.json` aggiornato. Verifica e conferma (OK / correzioni).
```

---

## Checkpoint — Regole presentazione

Al checkpoint, mostrare:
1. Breve riepilogo testuale (2-3 righe max)
2. Conferma che il file `template_exercise.json` è stato **scritto/aggiornato**
3. Segnalazioni di anomalie se presenti
4. Richiesta esplicita di conferma

> L'utente deve poter revisionare il JSON e confermare o correggere prima di procedere.
