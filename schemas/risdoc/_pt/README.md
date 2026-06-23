# Portable Text — Risdoc subset (Phase 22 POC)

Questa cartella contiene la **specifica del dialetto Portable Text** usato da risdoc per i contenuti ricchi editabili (default schema + compilation dei docenti).

## Perché Portable Text

- JSON-based, versionable, round-trippable
- Spec ufficiale Sanity.io: https://www.sanity.io/guide/what-is-portable-text
- Ecosistema: `@portabletext/to-html`, `@portabletext/react`, pacchetti PHP disponibili
- Separa *struttura* (AST) da *presentazione* (serializer HTML / TeX / Word)

## Scope POC

Questo POC implementa un **subset minimo** per validare la pipeline PT → TeX e PT → HTML:

- `block` standard (`style: "normal"`) con inline `span` + marks
- Marks standard: `strong`, `em`, `underline`, `code`
- Custom inline mark: `fieldRef` (riferimento a campo compilazione)
- Custom block: `checkboxGroup` (gruppo di checkbox LaTeX-style)
- Custom block: `rawTex` (escape hatch per TeX raw, ignorato in HTML)

Non ancora implementati (Phase 22.2+):
- `sectionbox`, `vspace`, `choice:radio`, liste nested, header style (h1-h3), tabelle

## Token reference

### Block standard
```json
{
  "_type": "block",
  "style": "normal",
  "children": [ ... span ... ],
  "markDefs": [ ... mark definitions ... ]
}
```

- `style`: `normal` | `h2` | `h3` | `blockquote` (POC: solo `normal`)
- `children`: array di `span`

### Span
```json
{ "_type": "span", "text": "testo", "marks": ["strong", "f1"] }
```

- `marks`: riferimenti a `markDefs._key` (per marks parametrizzati) o nomi predefiniti (`strong`, `em`, `underline`, `code`)

### Custom inline object: `fieldRef`
Convenzione PT per placeholders/mentions: inline object, NON mark.
Appare direttamente dentro `children` di un block:

```json
{
  "_type": "block",
  "children": [
    { "_type": "span", "text": "Classe ", "marks": [] },
    { "_type": "fieldRef", "name": "classe" },
    { "_type": "span", "text": " — Sezione ", "marks": [] },
    { "_type": "fieldRef", "name": "sezione" }
  ]
}
```

- HTML render: `<span class="pt-field-ref" data-field="classe">[classe]</span>`
- TeX render: `[field-classe]` (risolto poi in TexBuilder con valore compilation)

### Custom block: `checkboxGroup`
```json
{
  "_type": "checkboxGroup",
  "items": [
    { "state": "x", "label": "corretto" },
    { "state": "_", "label": "adeguato" }
  ]
}
```

- `state`: `"x"` checked | `"_"` unchecked
- HTML render: `<div class="pt-checkbox-group">` con `<label><input type="checkbox" [checked] disabled> label</label>`
- TeX render: `\xcheckbox{label}` o `\checkbox{label}` per ogni item, uno per riga

### Custom block: `rawTex`
```json
{ "_type": "rawTex", "content": "\\textbf{bold} raw" }
```

- HTML render: `<div class="pt-raw-tex" aria-label="Raw TeX">...escaped content...</div>` (non eseguito, solo visualizzato come preview)
- TeX render: content injected as-is

## File in questa cartella

- `README.md` — spec (questo file)
- `fixture-profilo.pt.json` — fixture esempio "profilo della classe" (replica del campo TeX legacy)
- `portable-text.schema.json` — JSON Schema formale per validazione (Phase 22.2)

## Renderer

- **PHP**: `App\Services\Risdoc\Pt\PtToTex` — walker AST → LaTeX string
- **JS**: `js/modules/risdoc/pt/pt-to-html.js` — walker AST → HTML string

## Phase roadmap

- 22.1 (POC, questa): subset minimo + test round-trip
- 22.2: schema formale JSON Schema + validation pre-save
- 22.3: ProseMirror schema + converter PM↔PT + `<fm-risdoc-pt-editor>` Lit+Tiptap
- 22.4: migration 16 schema risdoc esistenti a PT
- 22.5: Source mode (CodeMirror 6) + live HTML preview
