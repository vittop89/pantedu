# Extraction Rules — Regole Estrazione da Immagine

Regole per l'estrazione di contenuti esercizi dalle immagini allegate.
Il **workflow-manager** esegue l'estrazione direttamente (il subagent non ha accesso alle immagini).

---

## Cosa estrarre dall'immagine vs config

| Dato | Fonte |
|------|-------|
| Numero esercizio | 🖼 Immagine (riquadro colorato) |
| Testo / traccia / opzioni / affermazioni | 🖼 Immagine (trascrizione completa) |
| type, difficulty, badge_color, page, topic | 📄 Config (`destination.targets.<id>.defaults`) |
| source | 📄 Config → `config/SOURCES.json` |
| topic.existing, insertNear | 📊 Preflight context |

---

## Regole generali

### Numero esercizio
- ✅ Leggi **ESATTAMENTE** il numero nel riquadro colorato
- ❌ NON dedurre da numerazione progressiva, NON inventare

### Testo e tracce
- ✅ Trascrivi completamente, mantieni notazione matematica originale
- ❌ NON parafrasare, NON abbreviare

### Pre-testo
Non includere etichette: `TEST`, `PROVE INVALSI`, `ESAME`, `INVALSI`.

### target_numbers
- Numeri richiesti in target ma non estratti → `TARGET_MISMATCH` (critical, blocca)
- Numeri estratti non in target → `OUT_OF_SCOPE` (warning, skip)

---

## Traduzione obbligatoria EN → IT

> **REGOLA MANDATORIA:** Se il testo dell'esercizio è in lingua inglese, **DEVE essere tradotto interamente in italiano** prima di inserirlo nel contract.

| Elemento | Azione |
|---|---|
| Traccia / domanda in EN | Traduci in IT |
| Opzioni / affermazioni in EN | Traduci in IT |
| Parole suggerite (word bank) in EN | Traduci in IT |
| Istruzioni (es. "Fill in the blanks") in EN | Traduci in IT |
| Formule matematiche / simboli | Mantieni invariati |
| Nomi propri / citazioni bibliografiche | Mantieni invariati |

Esempio:
```
// ❌ "Thermal .................... occurs also in .................... as well."
// ✅ "La .................... termica avviene anche nei .................... ."
```

---

## Regole per tipo di contenuto

> Schemi JSON completi: `config/schemas.json` → `content_shapes`

### type_Collect
- `steps`/`points` OBBLIGATORIO con risoluzione algebrica completa (≥ 2 passaggi)
- Se risultato noto ma svolgimento non visibile → RISOLVERE algebricamente
- Se svolgimento visibile → estrarre fedelmente
- Stile: **solo formule LaTeX**, minimo verbale → vedi `rules/latex-rules.md`
- Se `solution_format_ref` disponibile dal preflight → adattare stile
- **Problemi fisico-matematici**: DATI & INCOGNITE + `align*` + incognite colorate + unità con `\cancel{}` → regole complete in `rules/latex-rules.md`
- **Risultato finale**: formato `\fcolorbox` + `<span class="solution">` → `rules/style-rules.md`

### type_RMultiA
- `explanation` OBBLIGATORIA per ogni statement — se non ricavabile: `""` e segnala in `warnings[]`

### type_RMultiB
- Regole opzioni/explanations → vedi `rules/style-rules.md` § Giustsol

---

## Risposta V/F e opzioni corrette

1. Leggi attentamente grafico/dati forniti
2. Applica teoria matematica/fisica
3. Valuta ogni affermazione/opzione
4. Flagga corrette in `isTrue` o `correctIndices`

Se NON sei sicuro: emetti warning con `{ "details": { "requiresUserValidation": true } }`.

---

## Assemblaggio FlatContract

Per ogni esercizio estratto:
```json
{
  "type": "da config scope_type o type_map",
  "number": "da immagine",
  "page": "da config page_map",
  "difficulty": "da config difficulty_map",
  "badge_color": "da config badge_color",
  "topic": {
    "name": "da config topics",
    "existing": true/false,
    "insertNear": "da preflight last_by_topic"
  },
  "source": "risolto da config/SOURCES.json",
  "scope_id": "id target scope",
  "content": { "..." }
}
```

### Risoluzione metadati

Base: `config.destination.targets.<id>.defaults` (merge opzionale da `config.defaults_shared`).
Se `target_entries[]` presente → matching deterministico su `number` + discriminanti (page, type, topic).
Se ambiguo → `AMBIGUOUS_TARGET_ENTRY`. Se mancante campo obbligatorio → BLOCCA.

```javascript
function matchTargetEntry(entries, extracted, scopeType) {
  const byNumber = (entries || []).filter(e => String(e.number) === String(extracted.number));
  if (byNumber.length === 0) return null;
  const candidates = byNumber.filter(e => {
    if (e.page && String(e.page) !== String(extracted.page || '')) return false;
    if (e.type && String(e.type) !== String(scopeType || '')) return false;
    if (e.topic && String(e.topic) !== String(extracted.topicName || '')) return false;
    return true;
  });
  if (candidates.length === 1) return candidates[0];
  if (candidates.length > 1) throw new Error('AMBIGUOUS_TARGET_ENTRY');
  return null;
}
```

---

## Checklist Pre-Output

- ✅ Numero esercizio trovato e valido
- ✅ Tipo esercizio determinato (da config scope/type_map)
- ✅ `target_numbers` coerente con numeri estratti
- ✅ Traccia/contenuto completamente estratto
- ✅ `type_Collect`: `steps`/`points` popolato con ≥ 2 passaggi + risultato
- ✅ `type_Collect` problemi fisico-mat.: DATI & INCOGNITE + `align*` + incognite colorate (→ `rules/latex-rules.md`)
- ✅ `type_Collect`: risultato finale con `\fcolorbox` + `<span class="solution">` (→ `rules/style-rules.md`)
- ✅ `type_Collect` multi-punto: `points[]` con `solution` per-punto
- ✅ Nessun campo figura: `figure_tikz`, `has_figure`, `tikz`, `data`, `unknowns` ASSENTI
- ✅ Formule LaTeX corrette → vedi `rules/latex-rules.md`
- ✅ Metadati associati da config, source completa
- ✅ **Testo in inglese tradotto in italiano** — nessun testo EN residuo
- ✅ Numeri extra fuori target tracciati come `OUT_OF_SCOPE`