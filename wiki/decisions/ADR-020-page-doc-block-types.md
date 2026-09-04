---
tags:
  - documentazione/adr
  - frontend
  - editor
  - risdoc
date: 2026-05-24
tipo: ADR
status: proposto
aliases: ["page-doc", "G23-blocks", "static-content", "glossary-table"]
---

# ADR-020 — Page-doc block types per editor PT (G23)

> [!info] Decision finale: estendere l'editor PT con **5 nuovi block types** (additivi) per consentire ai docenti di comporre pagine informative (legislazione/glossario/linee-guida) senza scrivere HTML manualmente. Riusa `HtmlSanitizer` di ADR-015.

## Context

Pantedu permette ai docenti di compilare documenti istituzionali via editor PT
basato su Tiptap 3.x + Lit custom element (`<fm-risdoc-pt-editor>`), con AST
[Portable Text](https://www.sanity.io/guide/what-is-portable-text) e renderer
PHP/JS (`PtToHtml`, `pt-to-html.js`).

Ad oggi i docenti possono creare 9 tipologie di block (block, checkboxGroup,
rawTex, table, select, textField, formCheckbox, sectionHeader, fieldRef). Per
produrre **pagine informative gerarchiche** stile legislazione/glossario —
esempi vivi su FismaPant:

- `0.0_SBA-Legislazione-ALTRO.php` (link normativi gerarchici)
- `0.1_SBA-Glossario-ALTRO.php` (tabella lemmi/definizioni/fonti)
- `1.0_SBA-Verifiche_e_Recuperi-ALTRO.php` (linee guida con accordion + nesting)

i docenti dovrebbero scrivere HTML/CSS manualmente. Inaccettabile per il target
utente (docenti scuole italiane, anche rurali — cfr. perf constraints e
[[ADR-002]] su accessibilità).

Inoltre **4 schemi stub** in `schemas/risdoc/*.json` dichiarano già il debt:

```json
"_requires_new_section_types": ["static-content", "glossary-table"]
```

Il meta-schema `schemas/risdoc/template.schema.json` include già
`static-content` e `glossary-table` nell'enum `section.type` — mancante è solo
l'implementazione lato PT runtime.

### Audit architettura (researcher agent, 2026-05-24)

- Editor Tiptap wrappa AST PT, schema in `js/modules/risdoc/pt/pm-schema.js`.
- Converter PM↔PT (`pm-pt-converter.js`) usa 2 switch hardcoded con default safe (`return null`).
- Renderer client/server: dispatch additivo con default skip silenzioso.
- Toolbar (`fm-risdoc-pt-toolbar.js`) usa delegate generico `_call(method, args)`.
- Pattern di estensione: **additivo** su 7 file per block type, no refactor centrale.
- Risk: **basso**. Effort stimato 5 block types = **7 dev days**.

### Alternative considerate

| Opzione | Bundle | Licenza | Decisione |
|---|---|---|---|
| **A — Estendere PT (questa)** | +5-15 KB | EUPL-1.2 puro + sanitizer DOMPurify MIT | ✓ adottata |
| B — EditorJS | +130 KB | Apache-2.0 | ✗ duplica UX docenti, 2 editor da imparare, viola perf mobile/3G |
| C — GrapesJS | +500 KB | BSD-3 | ✗ output HTML libero non semantico, overkill |
| D — Markdown + shortcodes | +20 KB | MIT | ✗ barriera UX per docenti non-tech |

## Decision

Estendere l'editor PT con **5 nuovi block types**, ognuno additivo:

| Block | Purpose | Mappato a |
|---|---|---|
| `static-content` | HTML sanitizzato con nesting `items` | legislazione, verifiche-e-recuperi, cosa-sono-strumenti-compensativi |
| `glossary-table` | Tabella sortable lemmi/definizioni/fonti | glossario |
| `accordion` | Sezioni collapsible via `<details>` nativo | verifiche-e-recuperi parti |
| `link-list-pdf` | Lista link normativi gerarchici con metadata | legislazione |
| `citation-norma` | Citazione legge/decreto strutturata | tutti i tre |

### Architettura

- **Schema:** ognuno definito in `schemas/risdoc/_pt/portable-text.schema.json` come `$ref` in `$defs/block.oneOf`.
- **Editor:** Tiptap Node + NodeView per ogni tipo, registrato in `pm-schema.js` + array `extensions` di `fm-risdoc-pt-editor.js`.
- **Converter:** case aggiunto in `pm-pt-converter.js` (`blockToPm` + `pmBlockToPt`).
- **Render:** case aggiunto in `pt-to-html.js` (client) + `match` di `PtToHtml::renderBlock` (server PHP).
- **Toolbar:** `<button>` per ogni tipo con `_call("insertQuick", [...])`.
- **CSS:** modulo BEM dedicato `css/modules/_pt-page-doc.css`.

### Sanitizzazione

- **Server-side (authoritative):** riusa `App\Services\Security\HtmlSanitizer` introdotto da [[ADR-015]]. Stessa whitelist (h2-h4/p/ul/ol/li/a/strong/em/blockquote/code/pre/br/hr).
- **Client-side (defense-in-depth + live preview):** nuovo `js/modules/risdoc/pt/html-sanitizer.js` wrapper DOMPurify v3.x (MIT, ~22KB) configurato per matchare la whitelist server.

### Modal UI

Nuova radio in `sidepage-modal-content.js:277-282`:

```
○ 📄 Pagina informativa — accordion, glossario, link normativi, citazioni (no HTML)
```

`doc_mode="page_doc"` → attiva sub-set toolbar PT con i 5 block types.

### Seed content

Estrazione one-shot da repo `fismapant` (branch master, path `storage/templates/strcomp/ALTRO/*.php`) via script `tools/scripts/extract-seed-from-fismapant.php`. Output: 4 file `schemas/risdoc/_pt/seeds/*.pt.json`. Review manuale obbligatoria prima merge.

### Versioning

Schema PT bumpato da "Phase 22" → "Phase 22 + G23 page-doc". No breaking change. Block types esistenti immutati. Template DB cifrati (`risdoc_templates.body_pt`) restano validi.

## Consequences

### Positive

- **Zero nuove dep editor**: continua su Tiptap esistente, +5-15 KB bundle (block types Lit).
- **Riusa sanitizer server**: `HtmlSanitizer` di [[ADR-015]] già deployato e testato.
- **Riusa pipeline server**: `PtToHtml` esteso, no nuovi servizi.
- **Riusa template stub**: 4 schemi `_requires_new_section_types` finalmente implementati.
- **UX coerente per docenti**: un solo editor da imparare, già rodato su 30+ E2E Playwright.
- **Perf mobile/3G preservata**: bundle marginale, lazy load DOMPurify possibile.
- **WCAG 2.2 AA out-of-box**: `<details>` nativo accordion, `<th scope>` glossary, semantica heading static-content.
- **EUPL-1.2 puro**: DOMPurify MIT compat, nessuna licenza problematica.
- **Pattern additivo testato**: nessun refactor centrale, default safe garantito.

### Negative

- **7 dev days** di sviluppo (3 sprint di 2-3 giorni).
- **Nuova dep client** DOMPurify (~22 KB minified, MIT) — accettabile vs alternative.
- **Refactor toolbar lieve**: 5 nuovi button + categoria visiva nel layout toolbar.
- **Estrazione seed semi-automatica**: parser DOM PHP può perdere semantica, richiede review manuale.
- **Maintenance burden marginale**: 7 file × 5 block types = 35 punti di estensione, ma pattern routinario dopo il primo.

### Neutral

- I 4 schemi stub `_requires_new_section_types` vengono aggiornati (rimosso debt, sostituito `body_ref` con `body_pt_seed_ref`).
- Meta-schema `template.schema.json` enum esteso da 15 a 18 section types (accordion, link-list-pdf, citation-norma aggiunti).
- TeX renderer (`PtToTex`) skip dei 5 nuovi block: sono web-only, non finiscono in PDF.

## Implementation plan

| Sprint | Durata | Deliverable |
|---|---|---|
| 1 | 3 gg | Sanitizer wrapper client + `glossary-table` end-to-end + 1 E2E |
| 2 | 2 gg | `static-content` (con nesting) + `accordion` (native `<details>`) + E2E |
| 3 | 2 gg | `link-list-pdf` + `citation-norma` + seed extraction + roundtrip E2E + merge |

POC sprint 1 valida pattern reale prima sprint 2-3.

## Migration

I 4 file `schemas/risdoc/{glossario,legislazione,verifiche-e-recuperi,cosa-sono-strumenti-compensativi}.json`:
- Rimuovere `_requires_new_section_types` e `_coverage_note` (debt risolto).
- Sostituire `body_ref` con `body_pt_seed_ref` puntante a `schemas/risdoc/_pt/seeds/<name>.pt.json`.

Template DB esistenti (cifrati AES-256-GCM) restano validi: nessun nuovo `_type` introdotto in template non-aggiornati.

## Open questions

1. **Markdown vs HTML in static-content**: default `html`, markdown opt-in via `format` field?
2. **Accordion `allow_multiple=false`**: richiede JS (~500 byte). Restare zero-JS sempre?
3. **CSV import glossary**: MVP o follow-up?
4. **TOC sticky per pagine lunghe**: in scope G23 o ADR successiva?
5. **Print stylesheet** per page-doc: in scope o follow-up?

## Links

- [[ADR-005-schema-driven-risdoc]] — schema-driven approach base
- [[ADR-015-xss-sanitization]] — HtmlSanitizer riusato server-side
- [[ADR-016-editor-modular-architecture]] — pattern modular editor (precedente)
- [[ADR-002-lit3-web-components]] — Lit framework usato per PT editor
- Spec dettagliato: `docs/specs/page-doc-block-types.md`
- Source pages: https://www.fismapant.com/strcomp_bes_altro/ALTRO/
- Portable Text spec: https://www.sanity.io/guide/what-is-portable-text
- Tiptap 3.x: https://tiptap.dev/docs
- DOMPurify: https://github.com/cure53/DOMPurify (MIT)
