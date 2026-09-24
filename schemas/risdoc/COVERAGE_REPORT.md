# Risdoc Modernization - Schema Coverage Report

Data: 2026-04-21
Branch: `refactor-risdoc-modernization`

## 1. Obiettivo

Validare la grammatica del meta-schema `template.schema.json` (9 section types) generando uno schema JSON per ciascuno dei 14 template risdoc/strcomp residui. Il pilota `motivazione-voti.json` resta il gold standard.

## 2. Sintesi

| Gruppo | N. |
|-------|----|
| Template completamente esprimibili con i 9 section types attuali | 7 |
| Template parzialmente esprimibili (richiedono nuovi section types) | 6 |
| Template stub (PHP minimale, solo header, da estendere) | 1 |
| **Totale** | **14** |

## 3. Meta-schema attuale

Types dichiarati in `template.schema.json`:

```
header, grade-selector, giudizio-group, giudizio-item,
text-section, checkbox-group, nota-textarea,
footer-signature, dynamic-table
```

## 4. Copertura per template

| ID DB | Slug schema | Righe PHP | Righe JSON | Stato | Note |
|-------|-------------|-----------|------------|-------|------|
| 16 | `piano-annuale-docente` | 703 | 189 | **COPERTO** | Usa intensivamente `dynamic-table` (UDA, civica, orienta, CLIL, progetti, test-ingresso con auto%) + checkbox-group annidati |
| 17 | `scheda-progetto-fis` | 242 | 129 | **COPERTO** | Full dynamic-table pattern + nota-textarea |
| 18 | `rendicontazione-progetto` | 30 | 21 | **STUB** | PHP originale contiene solo header: nessun contenuto da mappare. Schema minimale, da estendere |
| 19 | `relazione-finale-classe-docente` | 264 | 130 | **COPERTO** | checkbox-group multi-livello per obiettivi LG2010/Dipart + dynamic-table interdisciplinari |
| 20 | `scheda-di-recupero` | 141 | 63 | **COPERTO** | checkbox-group + nota-textarea |
| 21 | `relazione-recupero-debiti` | 141 | 116 | **COPERTO** | checkbox-group nidificati (obiettivi, strategie, valutazione) + dynamic-table |
| 22 | `obiettivi-disciplinari-lg2010` | 53 | 30 | **COPERTO** | 3 checkbox-group (competenze, abilità, conoscenze) |
| 23 | `obiettivi-disciplinari-dipart` | 103 | 34 | **COPERTO** | Stesso pattern LG2010 ma con recupero classi precedente/successiva (metadato `description`) |
| 24 | `programma-svolto` | 62 | 25 | **COPERTO** | checkbox-group + nota-textarea |
| 26 | `cosa-sono-strumenti-compensativi` | 292 | 44 | **PARZIALE** | Richiede `static-content` |
| 27 | `legislazione` | 1 (23KB minificata) | 20 | **PARZIALE** | Richiede `static-content` |
| 28 | `glossario` | 597 | 26 | **PARZIALE** | Richiede `glossary-table` (o `static-content` con slot dati) |
| 29 | `verifiche-e-recuperi` | 446 | 54 | **PARZIALE** | Richiede `static-content` annidabile |
| 30 | `autorizzazione` | 795 | 82 | **PARZIALE** | Richiede `info-field`, `form-checkbox`, `privacy-block`, `signature-block` |

Totale righe PHP analizzate: **3.877** (più 292 STRCOMP). Totale righe JSON prodotte: **963**.  
**Compressione media: ~4.2x** (i template a contenuto statico raggiungono 10-30x).

## 5. Nuovi section types proposti

### 5.1 `static-content` (prioritario)

Copre contenuto informativo read-only: pagine di spiegazione, linee guida, normativa.

```json
{
    "type": "static-content",
    "name": "mappa_concettuale",
    "format": "html",
    "title": "1. Mappa Concettuale",
    "body": "<h3>Definizione...</h3><p>...</p>",
    "body_ref": "path/to/source.md",
    "items": []
}
```

- Supporta annidamento via `items` (per strutture PARTE > sezione).
- `body_ref` permette di caricare markdown/HTML esterno (per contenuti lunghi come Legislazione/Glossario).
- Beneficiari: `cosa-sono-strumenti-compensativi`, `legislazione`, `verifiche-e-recuperi`.

### 5.2 `glossary-table` (specializzazione)

Tabella read-only di lemmi/definizioni con colonne fisse.

```json
{
    "type": "glossary-table",
    "name": "glossario_lemmi",
    "columns": ["N.", "Lemma", "Definizione", "Fonte"],
    "entries_ref": "path/to/entries.json"
}
```

- Potrebbe essere implementato come `static-content` + template Lit dedicato.
- Beneficiario: `glossario`.

### 5.3 `info-field` (form primitives)

Input generico per form anagrafici (testo, email, tel, data, number, select).

```json
{
    "type": "info-field",
    "name": "parentEmail",
    "label": "Email",
    "input_type": "email",
    "required": true,
    "placeholder": "..."
}
```

- Supporta `input_type`: `text | email | tel | date | number | select`.
- Quando `input_type=select`, usa `options[]` esistenti dallo schema.
- Beneficiario: `autorizzazione` (e ogni futuro modulo di iscrizione/richiesta).

### 5.4 `form-checkbox`

Checkbox singola con label (distinta da `checkbox-group` che è una lista di opzioni).

```json
{
    "type": "form-checkbox",
    "name": "privacyConsent",
    "label": "Acconsento al trattamento dei dati",
    "required": true
}
```

### 5.5 `privacy-block`

Informativa GDPR renderizzata in modo standard (richiamabile).

```json
{
    "type": "privacy-block",
    "name": "informativa_privacy",
    "title": "Informativa Privacy (Art. 13 GDPR)",
    "body_ref": "path/to/informativa.md"
}
```

### 5.6 `signature-block`

Blocco firma + pulsanti di azione (submit/reset/generate-pdf).

```json
{
    "type": "signature-block",
    "name": "firma_generazione",
    "actions": [
        { "id": "submit", "label": "Genera Modulo PDF", "type": "submit" },
        { "id": "reset",  "label": "Pulisci Modulo",  "type": "reset" }
    ]
}
```

## 6. Osservazioni sulla grammatica

### 6.1 Cosa regge bene

- La gerarchia `checkbox-group` > `items` > `checkbox-group` è espressiva: copre benissimo obiettivi disciplinari multilivello (LG2010 + Dipartimento con recupero classi).
- `dynamic-table` è versatile: basta aggiungere campi opzionali `default_rows`, `totals`, `row_group_size`, `auto_percentage` (tutti permessi dal meta-schema).
- `nota-textarea` copre tutti i `<textarea placeholder="...">` del legacy.

### 6.2 Estensioni minori consigliate al meta-schema

Anche se i 9 types attuali sono sufficienti per i 9 template MODELLI/RISORSE, servono proprietà aggiuntive sul `$defs.section`:

- `allow_custom: boolean` - per checkbox-group con "Specificare Altro"
- `custom_placeholder: string`
- `columns: string[]` - per dynamic-table
- `default_rows: array`
- `totals: object` - mappa colonna -> id field totale
- `row_group_size: number` - per UDA table con righe raggruppate
- `auto_percentage: boolean` - per test ingresso
- `input_type: string` - per info-field (futuro)
- `body: string`, `body_ref: string`, `format: string` - per static-content (futuro)

Queste sono retrocompatibili (tutte opzionali).

### 6.3 Cosa NON regge

- Contenuto informativo statico (STRCOMP/ALTRO): 4 template su 14.
- Form classico con anagrafica (Autorizzazione): 1 template su 14.

Rimedio: aggiungere 6 section types sopra proposti. A quel punto la grammatica coprirebbe **14/14** template.

## 7. Raccomandazione roadmap

1. Validare Plan A/B su Motivazione_voti (già fatto).
2. Estendere meta-schema con proprietà opzionali 6.2 (retrocompatibile, nessuno schema da migrare).
3. Implementare renderer dei 9 MODELLI/RISORSE coperti.
4. Progettare e aggiungere `static-content` + `glossary-table` (copre altri 4 template).
5. Progettare `info-field` + `form-checkbox` + `privacy-block` + `signature-block` (copre Autorizzazione e abilita futuri moduli anagrafici/consensi).
6. Estendere `rendicontazione-progetto` quando il legacy sarà completato.
