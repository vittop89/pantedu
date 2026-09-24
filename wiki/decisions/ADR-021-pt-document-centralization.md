---
tags:
  - documentazione/adr
  - frontend
  - editor
  - risdoc
date: 2026-05-24
tipo: ADR
status: superseded
superseded_by: ADR-022
aliases: ["pt-document", "G23-centralization", "custom-document"]
---

# ADR-021 — Centralizzazione PT-document (pagina personalizzata = JSON + TeX/PDF + HTML)

> [!info] Decision finale: la pagina personalizzata (teacher_content layout=custom)
> diventa un **documento PT completo** con le 3 capacità export (JSON, TeX/PDF,
> HTML) + edit inline, centralizzate in un layer unico, SENZA riscrivere il
> Web Component `<fm-risdoc-template>` (schema/endpoint-bound a risdoc).

## Context

Richiesta utente: "una pagina custom come quella con WebComponent vorrei che
fosse sia stampabile in tex sia sanitizzabile come html pulito" → poi
"centralizzare il tutto, refactoring completo. Pagina personalizzata significa
webcomponent con json, tex/pdf e HTML".

Stato pre-refactoring (2 architetture separate):

| | Pagina risdoc WC (/risdoc/view/{id}) | Pagina custom PT (/studio/{type}/.../{topic}) |
|---|---|---|
| Render | `<fm-risdoc-template>` (Lit, schema-driven) | `PtToHtml::render` statico server-side |
| Edit | sub-componenti WC | `<fm-risdoc-pt-editor>` inline (pt-inline-editor.js) |
| JSON | exportTemplateJson/importTemplateJson (WC) | ❌ mancante |
| TeX/PDF | `/api/risdoc/templates/{id}/compile-pdf` (TexBuilder) | ZIP via `/export` (PtToTex) |
| HTML | ❌ (solo render) | ❌ → poi `/export-html` |
| Sorgente dati | schema risdoc + body_pt in section.default | `teacher_content.metadata.body_pt` |

### Analisi riuso WC (researcher agent)

Il WC `<fm-risdoc-template>` è **schema-bound** (richiede `schema-url`, render
parte da `_schema.sections[]`) e **endpoint-bound** (`/api/risdoc/templates/{id}/*`
per schema/compilations/tex-files/compile-pdf). Montarlo sulle pagine custom
(teacher_content, `/api/teacher/content/{id}/*`) richiederebbe:
- pseudo-schema generato al volo dal body_pt
- riscrittura del WC con strato di astrazione "data source" per reindirizzare
  TUTTI i fetch (Salva, TeX, JSON) a endpoint diversi

→ **Refactoring del WC troppo rischioso**. Il body_pt (PT AST) è però già il
formato comune; i servizi server (PtToHtml/PtToTex/HtmlSanitizer/texCommon)
sono già condivisi.

## Decision

**Centralizzare le capacità documento PT custom in un layer unico**, riusando
i servizi server condivisi, invece di generalizzare il WC risdoc.

### Architettura centralizzata

```
teacher_content.metadata.body_pt (PT AST — sorgente unica)
        │
        ├── PtToHtml::render()      → vista web (default) + export-html (HTML pulito)
        ├── PtToTex::render()       → ZIP export (TeX/PDF stampabile)
        ├── <fm-risdoc-pt-editor>   → edit mode inline (toggle topbar)
        └── JSON serialize/parse    → export/import body_pt
```

### Layer client centralizzato: `js/modules/features/pt-inline-editor.js`

Controller unico per il PT-document custom. Gestisce (via event delegation su
`document`, binding stabile anche con topbar async):
- **toggle-edit**: ✎ Modifica ↔ 👁 Anteprima (mount/smonta editor inline)
- **export-html**: download HTML standalone sanitizzato
- **export-json**: download body_pt come .json
- **import-json**: file picker → parse → POST update → reload
- **Salva**: POST `/api/teacher/content/{id}/update` (dentro l'editor)

`refreshTopbarToggle()` mostra/nasconde i button runtime in base a
`.fm-pt-rendered[data-layout="custom"]` + `data-fm-can-edit`.

### Toolbar custom (topbar unificata)

```
[💾 TEX/PDF] [⬇ HTML] [{ } Export JSON] [📥 Import JSON] [✎ Modifica] [ZIP] [filtri] [Editor]
```

### Endpoint server (teacher_content)

| Capability | Endpoint | Servizio |
|---|---|---|
| TeX (ZIP) | `POST /api/teacher/content/{id}/export` | PtToTex + texCommon |
| HTML pulito | `GET /api/teacher/content/{id}/export-html` | PtToHtml + HtmlSanitizer + CSS inline |
| Salva | `POST /api/teacher/content/{id}/update` | repo update metadata.body_pt |
| Export JSON | client-side (fetch body_pt + Blob download) | — |
| Import JSON | client parse → POST update | — |

## Consequences

### Positive
- **Sorgente unica** body_pt → 4 output (HTML render, HTML export, TeX, JSON).
- **Zero coupling** col WC risdoc — nessuna riscrittura rischiosa.
- **Servizi server riusati** (PtToHtml/PtToTex/HtmlSanitizer già condivisi).
- **Layer client coeso** (pt-inline-editor.js = unico punto azioni PT-document).
- **5 block types G23** funzionano in TUTTI gli output (HTML + TeX da Sprint 7).
- Edit B/I/U input-aware, Nuova sezione fallback, toggle bidirezionale.

### Negative
- Le pagine custom e risdoc WC restano **2 codebase distinte** (non un unico
  componente). Duplicazione concettuale toolbar (2 set di button con namespace
  diversi: `data-fm-action` vs `data-action`).
- "Sezioni navigator" del WC NON portato alla custom (richiederebbe
  fm-risdoc-section-navigator adattato — out of scope, basso valore per PT
  single-document).
- Import JSON usa `confirm()` nativo (UX migliorabile con modal custom).

### Neutral
- Un'eventuale unificazione totale (1 WebComponent `<fm-pt-document>` con
  data-source pluggable per teacher_content E risdoc) resta possibile come
  refactoring futuro maggiore, ma non giustificato dal valore attuale.

## Implementation (Sprint 8-12)

- Sprint 8: UX unificata (toggle topbar, rimossa radio page_doc separata)
- Sprint 9: toggle bidirezionale ✎ Modifica ↔ 👁 Anteprima
- Sprint 10b: B/I/U sempre attivi + input-aware + Nuova sezione fallback
- Sprint 11: export-html (HTML pulito) + verifica TeX ZIP esistente
- Sprint 12: export-json + import-json (trio JSON/TeX/HTML completo)

## Links

- [[ADR-020-page-doc-block-types]] — 5 block types G23 (base)
- [[ADR-015-xss-sanitization]] — HtmlSanitizer riusato per export-html
- [[ADR-002-lit3-web-components]] — Lit framework (pt-editor)
- Spec: `docs/specs/page-doc-block-types.md`
- Controller: `app/Controllers/TeacherContentController.php` (export/exportHtml/update)
- Layer client: `js/modules/features/pt-inline-editor.js`
