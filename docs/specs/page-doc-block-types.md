# Spec — Page-doc block types per editor PT (Phase G23)

> **Status:** DRAFT proposto — 2026-05-24
> **Owner:** Operatore
> **Related ADR:** ADR-020 (proposed) — `wiki/decisions/ADR-020-page-doc-block-types.md`
> **Triggered by:** richiesta "aggiungere modello documento per costruire pagine web tipo FismaPant senza scrivere HTML"
> **Scope:** estendere PT editor (Tiptap + Lit) con 5 nuovi block types per consentire a docenti di comporre pagine informative gerarchiche (legislazione/glossario/linee-guida) senza markup manuale.

## 1 — Context

### 1.1 Problema

Pagine come:

- https://www.fismapant.com/strcomp_bes_altro/ALTRO/0.0_SBA-Legislazione-ALTRO.php
- https://www.fismapant.com/strcomp_bes_altro/ALTRO/0.1_SBA-Glossario-ALTRO.php
- https://www.fismapant.com/strcomp_bes_altro/ALTRO/1.0_SBA-Verifiche_e_Recuperi-ALTRO.php

oggi sono **file PHP/HTML standalone** in `storage/templates/strcomp/ALTRO/` (su repo fismapant master), scritti a mano con CSS embedded. Per produrre nuove pagine simili docenti devono conoscere HTML/CSS. Inaccettabile su Pantedu (target: docenti scuole italiane, anche rurali).

### 1.2 Modelli stub esistenti

Nel repo Pantedu esistono già **4 schemi JSON stub** in `schemas/risdoc/*.json` che dichiarano `_requires_new_section_types`:

| Stub | Section type richiesto | Mappato a pagina FismaPant |
|---|---|---|
| `glossario.json` | `glossary-table` | `0.1_SBA-Glossario-ALTRO.php` |
| `legislazione.json` | `static-content` | `0.0_SBA-Legislazione-ALTRO.php` |
| `verifiche-e-recuperi.json` | `static-content` nested | `1.0_SBA-Verifiche_e_Recuperi-ALTRO.php` |
| `cosa-sono-strumenti-compensativi.json` | `static-content` multi | (multipli BES/strcomp) |

Il **meta-schema** (`schemas/risdoc/template.schema.json`) include già `static-content` e `glossary-table` nell'enum `section.type`. Non implementati nel PT runtime.

### 1.3 Architettura PT — riepilogo

- **Editor:** custom element Lit `<fm-risdoc-pt-editor>` wrappa Tiptap 3.x.
- **AST:** Portable Text (Sanity.io spec), JSON array di block.
- **Renderer client:** `js/modules/risdoc/pt/pt-to-html.js` (`switch` su `_type`, default skip).
- **Renderer server:** `app/Services/Risdoc/Pt/PtToHtml.php` + `PtToTex.php` (`match` su `_type`).
- **Converter PM↔PT:** `js/modules/risdoc/pt/pm-pt-converter.js` (2 `switch` hardcoded, default `return null`).
- **Schema JSON Draft-2020:** `schemas/risdoc/_pt/portable-text.schema.json`.
- **Toolbar:** `js/components/risdoc/fm-risdoc-pt-toolbar.js` (delegate generico `_call(method, args)`).
- **Pattern estensione:** **additivo** su 7 file. Risk basso. Default safe.

### 1.4 Decisione di alto livello

- **Estendere PT editor** (vs nuovo editor EditorJS) — coerenza UX docenti, perf mobile/3G, riuso pipeline `PtToHtml`+30 E2E, zero nuove dep esterne (oltre sanitizer).
- **5 block types separati** (vs 2 core) — user preference: granularità + UX dedicata per ogni caso d'uso.
- **Sanitizzazione doppia:** DOMPurify client + HTML Purifier server.
- **Seed da repo fismapant master** (branch master, file in `storage/templates/strcomp/ALTRO/`).

## 2 — Block type specifications

Tutti i block sono inseriti come elemento del root array PT AST. Ogni block ha:
- `_type: string` (discriminator)
- `_key?: string` (uuid stabile per round-trip Tiptap)

### 2.1 `static-content`

**Purpose.** Contenuto HTML sanitizzato (paragrafi/heading/liste/blockquote/code/link). Supporta annidamento via `items` per gerarchie documentali (es. PARTE I → A → A.1).

**Schema:**

```json
{
  "_type": "static-content",
  "_key": "abc123",
  "title": "PARTE I. NORME GENERALI",
  "level": 2,
  "format": "html",
  "body": "<p>Testo sanitizzato...</p><ul><li>...</li></ul>",
  "items": [
    { "_type": "static-content", "title": "A. Registrazione...", "level": 3, "body": "..." }
  ]
}
```

**Constraints:**
- `format: "html" | "markdown"` (markdown supportato via Parsedown server-side, sanitizzato dopo render).
- `body` sanitizzato whitelist: `h2|h3|h4|p|ul|ol|li|a[href,title,rel,target]|strong|em|blockquote|code|pre|br|hr`.
- `level: 1-4` (heading semantico).
- `items`: ricorsivo `static-content` (max depth 4 per leggibilità).
- `title` opzionale (se assente, no heading rendered).

**Editor UX:**
- Slash command `/static` o button toolbar.
- Body editato in textarea con live preview (DOMPurify in real-time).
- Nesting via "+ sotto-sezione" che aggiunge a `items[]`.

**Render HTML:**

```html
<section class="pt-static-content" data-level="2">
  <h2>PARTE I. NORME GENERALI</h2>
  <div class="pt-static-content__body">
    <p>Testo sanitizzato...</p>
    <ul><li>...</li></ul>
  </div>
  <section class="pt-static-content pt-static-content--nested" data-level="3">
    <h3>A. Registrazione...</h3>
    <div class="pt-static-content__body">...</div>
  </section>
</section>
```

**Render TeX:** skip (`static-content` non finisce in TeX PDF — è web-only).

### 2.2 `glossary-table`

**Purpose.** Tabella lemmi/definizioni con colonne fisse. Sortable + searchable client-side. Match `0.1_SBA-Glossario-ALTRO.php`.

**Schema:**

```json
{
  "_type": "glossary-table",
  "_key": "def456",
  "name": "glossario_lemmi",
  "columns": ["N.", "Lemma", "Definizione", "Fonte"],
  "entries": [
    { "n": 1, "lemma": "Abilità", "definizione": "Capacità di applicare...", "fonte": "Racc. UE 2008/C 111/01" },
    { "n": 2, "lemma": "Apprendimento formale", "definizione": "...", "fonte": "COM 2001/678" }
  ],
  "sortable": true,
  "searchable": true
}
```

**Constraints:**
- `columns`: array string, length 2-6. Convenzione raccomandata: `["N.", "Lemma", "Definizione", "Fonte"]`.
- `entries[]`: array oggetti con keys `n` (int), `lemma`, `definizione`, `fonte` (tutti `string`).
- `sortable`, `searchable`: boolean default `true`.

**Editor UX:**
- Inline grid editor (tipo Excel-light): aggiungi riga/colonna, drag reorder righe.
- Import CSV opzionale (parsing client, validazione schema prima di insert).
- Bulk paste da clipboard supportato.

**Render HTML:**

```html
<div class="pt-glossary-table" data-name="glossario_lemmi">
  <input type="search" class="pt-glossary-table__search" placeholder="Cerca lemma..." aria-label="Cerca nel glossario">
  <table>
    <caption class="visually-hidden">Glossario lemmi</caption>
    <thead><tr><th scope="col">N.</th><th scope="col">Lemma</th>...</tr></thead>
    <tbody>
      <tr><td>1</td><td>Abilità</td><td>Capacità...</td><td>Racc. UE 2008/C 111/01</td></tr>
      ...
    </tbody>
  </table>
</div>
```

Sort/search via vanilla JS inline script (~1KB), no jQuery.

**WCAG 2.2 AA:** `<th scope="col">`, `<caption>` per screen reader, `aria-sort` su click.

### 2.3 `accordion`

**Purpose.** Sezioni collapsible per documenti lunghi (es. `verifiche-e-recuperi`). Sostituisce jQuery accordion del file legacy.

**Schema:**

```json
{
  "_type": "accordion",
  "_key": "ghi789",
  "items": [
    {
      "title": "A. Registrazione e datazione dei voti",
      "body_pt": [ { "_type": "static-content", "body": "..." } ],
      "default_open": false
    }
  ],
  "allow_multiple": true
}
```

**Constraints:**
- `items[].body_pt` è nested PT AST (array di altri block — può contenere `static-content`, `link-list-pdf`, anche `glossary-table`).
- `allow_multiple`: se `false`, comportamento "uno solo aperto" (radio).
- `default_open`: boolean per stato iniziale.

**Editor UX:**
- Item collapsible nell'editor stesso (preview).
- Inner body editato come sub-document PT (editor inline).

**Render HTML:** uses native `<details>`/`<summary>` (zero JS, accessibile by default):

```html
<div class="pt-accordion" data-multiple="true">
  <details class="pt-accordion__item">
    <summary>A. Registrazione e datazione dei voti</summary>
    <div class="pt-accordion__body">...</div>
  </details>
</div>
```

Se `allow_multiple=false` → JS minimo (~500 byte) che chiude altri item on `toggle`.

### 2.4 `link-list-pdf`

**Purpose.** Lista link normativi (PDF/URL) con metadati (titolo, descrizione, source). Match `0.0_SBA-Legislazione-ALTRO.php`.

**Schema:**

```json
{
  "_type": "link-list-pdf",
  "_key": "jkl012",
  "title": "Scuola dell'infanzia e primo ciclo",
  "items": [
    {
      "label": "Indicazioni nazionali (DM 16 nov 2012, n. 254)",
      "href": "/strcomp_bes_altro/ALTRO/linee_guida/primo_ciclo/509_indicazioni-nazionali-2012.pdf",
      "external": false,
      "description": "Curricolo scuola infanzia/primaria/secondaria I grado",
      "sub_items": [
        { "label": "Allegato A", "href": "..." }
      ]
    }
  ]
}
```

**Constraints:**
- `items[].href`: URL o path relativo. Validato `http(s)?://` o `/`.
- `items[].external`: se `true`, render con `target="_blank" rel="noopener noreferrer"` + icona esterna.
- `sub_items`: ricorsivo (1 livello).

**Editor UX:**
- Form repetitivo "Aggiungi link" con campi label/href/desc.
- Auto-detect `external` via regex su href.

**Render HTML:**

```html
<section class="pt-link-list-pdf">
  <h3>Scuola dell'infanzia e primo ciclo</h3>
  <ul class="pt-link-list-pdf__list">
    <li>
      <a href="..." class="pt-link-list-pdf__link" target="_blank" rel="noopener noreferrer">
        Indicazioni nazionali (DM 16 nov 2012, n. 254)
        <span class="pt-link-list-pdf__icon" aria-hidden="true">↗</span>
      </a>
      <p class="pt-link-list-pdf__desc">Curricolo scuola infanzia/primaria/secondaria I grado</p>
      <ul class="pt-link-list-pdf__sublist">
        <li><a href="...">Allegato A</a></li>
      </ul>
    </li>
  </ul>
</section>
```

### 2.5 `citation-norma`

**Purpose.** Blocco citazione legge/decreto con metadata strutturati (tipo, numero, anno, articolo). Permette future feature (search by norma, link cross-document).

**Schema:**

```json
{
  "_type": "citation-norma",
  "_key": "mno345",
  "tipo": "DM",
  "numero": "5669",
  "anno": 2011,
  "data": "2011-07-12",
  "articolo": "Art. 4 c. 2",
  "title": "Linee Guida DSA",
  "href": "/strcomp_bes_altro/ALTRO/linee_guida/BES/prot5669_11.pdf",
  "quote": "Gli strumenti compensativi devono essere riconosciuti..."
}
```

**Constraints:**
- `tipo`: enum `["L", "DL", "DLgs", "DPR", "DM", "DI", "CM", "DDG", "OM", "Racc", "COM", "altro"]`.
- `numero`, `anno`, `articolo`: stringhe (numero può avere "/").
- `quote`: opzionale, max 500 char (citazione testuale).
- `href`: opzionale, link al PDF/sorgente ufficiale.

**Editor UX:**
- Form con campi tipo/numero/anno + WYSIWYG per `quote` + link picker per `href`.
- Auto-generate `title` da tipo+numero+anno se vuoto.

**Render HTML:**

```html
<aside class="pt-citation-norma" data-tipo="DM">
  <div class="pt-citation-norma__header">
    <strong>DM 5669/2011</strong>
    <span class="pt-citation-norma__articolo">Art. 4 c. 2</span>
  </div>
  <blockquote class="pt-citation-norma__quote">
    "Gli strumenti compensativi devono essere riconosciuti..."
  </blockquote>
  <p class="pt-citation-norma__title">
    <a href="/strcomp_bes_altro/...">Linee Guida DSA</a>
  </p>
</aside>
```

## 3 — Sanitization architecture

### 3.1 Client (real-time preview)

- **DOMPurify** v3.x (MIT, ~22KB minified).
- Configurazione: profile `pantedu-static-content`:
  ```js
  ALLOWED_TAGS: ['h2','h3','h4','p','ul','ol','li','a','strong','em','blockquote','code','pre','br','hr'],
  ALLOWED_ATTR: ['href','title','rel','target'],
  ALLOW_DATA_ATTR: false,
  USE_PROFILES: { html: true },
  RETURN_DOM: false
  ```
- Hook `afterSanitizeAttributes` per forzare `rel="noopener noreferrer"` su `<a target="_blank">`.

### 3.2 Server (persist + render)

- **Riuso `App\Services\Security\HtmlSanitizer`** introdotto da ADR-015 (wrapper HTMLPurifier già deployato, già testato, già configurato per Pantedu).
- Whitelist server mirror del client (h2-h4, p, ul/ol/li, a[href|title|rel|target], strong, em, blockquote, code, pre, br, hr).
- Cache config in `storage/cache/htmlpurifier/` (già esistente da ADR-015).
- Pipeline persist: `body` user-input → `HtmlSanitizer::clean()` → DB save. Mai render direct.
- **Niente nuova install composer** — dipendenza HTMLPurifier già in `composer.json`.

### 3.3 XSS test battery

File: `tests/Unit/Security/StaticContentXssTest.php` (PHPUnit).

Payload da testare:
```
<script>alert(1)</script>
<img src=x onerror=alert(1)>
<a href="javascript:alert(1)">click</a>
<svg onload=alert(1)>
<iframe src="data:text/html,..."></iframe>
<style>body{...}</style>
```

Tutti devono produrre output sanitizzato (tag rimossi o attributo strippato).

## 4 — Seed extraction from fismapant master

### 4.1 Source files

Path repo: `C:/Users/vitto/progetti_vscode/fismapant/`, branch master, file:

- `storage/templates/strcomp/ALTRO/0.0_SBA-Legislazione-ALTRO.php`
- `storage/templates/strcomp/ALTRO/0.1_SBA-Glossario-ALTRO.php`
- `storage/templates/strcomp/ALTRO/1.0_SBA-Verifiche_e_Recuperi-ALTRO.php`

### 4.2 Extraction script

`tools/scripts/extract-seed-from-fismapant.php` (one-shot, da git-ignored):

1. Legge file PHP.
2. Strip `<head>`, `<style>`, `<script>` (CSS+jQuery inline non servono — il render Pantedu usa proprio CSS).
3. Estrae solo contenuto `<body>` → `<div class="page-wrapper">`.
4. Parse DOM via `DOMDocument`.
5. Mapping:
   - `<h2.section-title>` → block `static-content` con `level=2`.
   - `<ul.link-list>` contenente `<a target="_blank">` PDF → block `link-list-pdf`.
   - Tabella glossario (TR rows) → block `glossary-table`.
   - Riferimenti `<a href="...DPR_88-2010...">` → estratti come `citation-norma` con tipo/numero/anno parsati da label.
   - Sezioni jQuery `.collapsible` → block `accordion`.
6. Output: 4 file JSON in `schemas/risdoc/_pt/seeds/`:
   - `glossario.pt.json`
   - `legislazione.pt.json`
   - `verifiche-e-recuperi.pt.json`
   - `cosa-sono-strumenti-compensativi.pt.json`
7. Validate ogni output contro `portable-text.schema.json` (esteso).

### 4.3 Manual review

Output script richiede review user prima del commit (estrazione automatica può perdere semantica). Genera anche `docs/specs/seed-extraction-report.md` con:
- Numero block estratti per tipo
- Eventuali `<a>` che non hanno match con regex `citation-norma`
- HTML strippato che non rientra in nessun block (segnalato come "loss")

## 5 — File-by-file change list

### 5.1 Per ogni nuovo block type (×5)

| File | Operazione |
|---|---|
| `schemas/risdoc/_pt/portable-text.schema.json` | Aggiungere `$ref` in `$defs/block.oneOf` + nuova `$defs/<blockName>` |
| `js/modules/risdoc/pt/pm-schema.js` | `export const <BlockName> = Node.create({...})` con `addAttributes`, `addNodeView`, `addCommands` |
| `js/modules/risdoc/pt/pm-pt-converter.js` | Aggiungere `case` in `blockToPm` (PT→PM) e in `pmBlockToPt` (PM→PT) |
| `js/components/risdoc/fm-risdoc-pt-editor.js` | Import extension + push in array `extensions` + ramo in `validatePtShape` |
| `js/components/risdoc/fm-risdoc-pt-toolbar.js` | Aggiungere `<button>` con `_call("insertQuick", ["<blockName>", [...]])` |
| `js/modules/risdoc/pt/pt-to-html.js` | `case "<blockName>": return render<BlockName>(block);` + funzione render |
| `app/Services/Risdoc/Pt/PtToHtml.php` | Aggiungere riga in `match` di `renderBlock` + metodo `render<BlockName>` |

### 5.2 Una tantum (sanitizer)

| File | Operazione |
|---|---|
| `package.json` | `+ "dompurify": "^3.x"` |
| `js/modules/risdoc/pt/html-sanitizer.js` | NEW — wrapper DOMPurify con config Pantedu (mirror della whitelist server) |
| **Server-side** | Riuso `App\Services\Security\HtmlSanitizer` (ADR-015), zero install |

### 5.3 Meta-schema update

| File | Operazione |
|---|---|
| `schemas/risdoc/template.schema.json` | Estendere enum `section.type` con `accordion`, `link-list-pdf`, `citation-norma` (sono solo `static-content` e `glossary-table` attualmente). |

### 5.4 Modal UI

| File | Operazione |
|---|---|
| `js/modules/features/sidepage-modal-content.js` (linee 277-282) | Aggiungere radio `doc_mode="page_doc"` nel fieldset `Modello documento` con descrizione "📄 Pagina informativa — accordion, glossario, link normativi, citazioni leggi (no HTML)" |
| `js/modules/features/sidepage-modal-content.js` | Conditional: quando `doc_mode=page_doc`, attivare sub-set toolbar PT con i 5 block types |

### 5.5 Seed e fixture

| File | Operazione |
|---|---|
| `schemas/risdoc/_pt/seeds/glossario.pt.json` | NEW (estratto da fismapant) |
| `schemas/risdoc/_pt/seeds/legislazione.pt.json` | NEW |
| `schemas/risdoc/_pt/seeds/verifiche-e-recuperi.pt.json` | NEW |
| `schemas/risdoc/_pt/seeds/cosa-sono-strumenti-compensativi.pt.json` | NEW |
| `tools/scripts/extract-seed-from-fismapant.php` | NEW (one-shot, gitignored o `tools/scripts/` committato) |

### 5.6 CSS

| File | Operazione |
|---|---|
| `css/modules/_pt-page-doc.css` | NEW (modulo BEM dedicato: `.pt-static-content`, `.pt-glossary-table`, `.pt-accordion`, `.pt-link-list-pdf`, `.pt-citation-norma`) |
| `css/main.css` | `@import` del nuovo modulo nel layer `modules` |

### 5.7 E2E

| File | Operazione |
|---|---|
| `tests/e2e/page_doc_glossary_table.spec.js` | NEW — POC test |
| `tests/e2e/page_doc_static_content.spec.js` | NEW |
| `tests/e2e/page_doc_accordion.spec.js` | NEW |
| `tests/e2e/page_doc_link_list_pdf.spec.js` | NEW |
| `tests/e2e/page_doc_citation_norma.spec.js` | NEW |
| `tests/e2e/page_doc_seed_roundtrip.spec.js` | NEW — verifica che i 4 seed renderizzano output HTML equivalente alle pagine FismaPant target |

## 6 — Migration & back-compat

### 6.1 Block types esistenti

I 9 block types attuali (block/checkboxGroup/rawTex/table/select/textField/formCheckbox/sectionHeader/inline+fieldRef) **non subiscono alcuna modifica**. I 5 nuovi sono **additivi** al `oneOf` dello schema JSON e ai `switch`/`match` dei renderer.

Template DB esistenti (cifrati AES-256-GCM in `risdoc_templates.body_pt`) restano validi: nessun nuovo `_type` introdotto in template non aggiornati.

### 6.2 Stub schemas

I 4 file `schemas/risdoc/{glossario,legislazione,verifiche-e-recuperi,cosa-sono-strumenti-compensativi}.json` vengono **aggiornati**:
- Rimuovere campo `_requires_new_section_types` (debt risolto).
- Sostituire `body_ref` con `body_pt_seed_ref` puntante a `schemas/risdoc/_pt/seeds/<name>.pt.json`.
- Rimuovere `_coverage_note` (risolto).

### 6.3 Versioning schema PT

Bumpare `portable-text.schema.json#title` da "Risdoc subset (Phase 22)" → "Risdoc subset (Phase 22 + G23 page-doc)". No breaking change — schema additivo.

## 7 — Implementation plan

### Sprint 1 (3 giorni) — Foundation + glossary-table

1. **Day 1:** sanitizer setup (DOMPurify + HTMLPurifier) + XSS test battery.
2. **Day 2:** `glossary-table` end-to-end (schema + Tiptap node + converter + editor + toolbar + render client + render server + CSS).
3. **Day 3:** E2E test `glossary-table` + seed extraction script (solo glossario) + review.

### Sprint 2 (2 giorni) — static-content + accordion

1. **Day 4:** `static-content` (più complesso: HTML editor + sanitizer integration + nesting).
2. **Day 5:** `accordion` (riusa `<details>` native) + E2E entrambi.

### Sprint 3 (2 giorni) — link-list-pdf + citation-norma + seed finalize

1. **Day 6:** `link-list-pdf` + `citation-norma` (più meccanici, pattern già rodato).
2. **Day 7:** estrazione seed completi (3 pagine FismaPant) + roundtrip E2E + ADR finale + merge.

**Totale: 7 dev days** (≈1.5 sprint settimanali). Stima conservativa.

## 8 — Open questions

1. **Markdown vs HTML in `static-content`:** spec dice "format: html|markdown". Default? → Proposta: `html` di default per UX immediato, markdown opt-in.
2. **Accordion native `<details>` vs JS controlled:** se `allow_multiple=false` serve JS. Vogliamo tenere zero-JS sempre (cioè non supportare `allow_multiple=false`)?
3. **Glossary CSV import:** prioritario in MVP o post-MVP?
4. **`citation-norma` lookup database:** future feature di "link cross-document a citazioni stesse legge"? Per ora out of scope.
5. **Sidebar TOC auto-generato:** pagine lunghe (verifiche-e-recuperi) trarrebbero beneficio da TOC sticky. In scope o follow-up?
6. **Print stylesheet:** docenti possono voler stampare. CSS `@media print` per i 5 nuovi block types.

## 9 — References

- ADR-G23 — TBD (page-doc block types decision)
- Portable Text spec: https://www.sanity.io/guide/what-is-portable-text
- Tiptap docs: https://tiptap.dev/docs
- DOMPurify: https://github.com/cure53/DOMPurify (MIT)
- HTML Purifier: http://htmlpurifier.org/ (LGPL-2.1)
- Existing PT editor audit: dialog conversation 2026-05-24 (researcher agent output)
