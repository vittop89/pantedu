---
tags:
  - documentazione/adr
  - frontend
  - editor
  - risdoc
  - refactor
date: 2026-05-25
tipo: ADR
status: accettato
aliases: ["fm-pt-document", "G24-unified-doc", "pt-document-component"]
---

# ADR-022 — `<fm-pt-document>`: WebComponent unificato documento PT

> [!info] Decision finale: consolidare TUTTO il path "pagina personalizzata"
> (custom teacher_content) in UN WebComponent Lit pulito `<fm-pt-document>`
> con adapter pluggable e le 3 capacità (JSON/TeX/HTML) + edit inline.
> Supersede ADR-021 (layer JS sparso) con un componente coeso.

## Context

Richiesta utente (2026-05-24, sera): "unificazione totale, refactoring
completo. Pagina personalizzata significa webcomponent con json, tex/pdf e
HTML. In caso di scelte scegli sempre quella che porta a codice pulito,
moderno, no legacy (CSS BEM)."

Stato pre-refactor (ADR-021): le capacità documento PT custom erano
**sparse** in:
- `js/modules/features/pt-inline-editor.js` (330 LOC, event delegation
  globale su document, toggle/save/export json/html)
- `views/partials/_topbar_modern.php` (button data-fm-action sparsi)
- `app/Controllers/ContentStudyController.php::renderCustomTopicHtml`
  (markup .fm-pt-rendered + bottoni inline)

Non era un componente: era logica procedurale distribuita. Difficile da
testare, manutenere, riusare.

### Analisi unificazione con WC risdoc (researcher agent)

Valutata l'ipotesi di UN solo componente per custom E risdoc. **Conclusione:
i due data model sono genuinamente diversi e l'unificazione totale sarebbe
lossy + rischiosa:**

| | custom | risdoc |
|---|---|---|
| Modello | singolo `body_pt` (1 array PT AST) | schema N sezioni + `fields` (per-slug) + `state` (selettori) + extraSections |
| Store | teacher_content.metadata | doppio: localStorage per-slug + DB compilations |
| Render | PtToHtml(body_pt) | schema-driven dispatch per section.type |
| Sezioni | block PT omogenei | eterogenee: pt_unified + dynamic-table + grade-selector + options_source dinamici |
| Scoping | per-content | per-uid + per-combinazione (indirizzo/classe/sezione/disciplina) |

**16 template risdoc classificati:** nessuno è "single body_pt"; ~10 hanno
sezioni pt_unified (multi-section omogeneo), 5 puramente statici/non-PT
(glossario/legislazione/verifiche/cosa-sono/rendicontazione), i complex
(piano-annuale: 11 pt_unified + 8 dynamic-table + 24 checkbox + options_source)
hanno form fields state-dependent.

**Rischi unificazione forzata risdoc:** perdita confini sezione nel body_pt
unificato; options_source state-dependent congelato (perde opzioni dinamiche);
sezioni non-PT round-trip lossless impossibile; re-segmentazione
body_pt→fields[name] critica; merge admin schema dipende da sectionKey.

## Decision

**Principio guida (codice pulito > unificazione forzata):** unificare ciò che
HA SENSO unificare, NON forzare un'astrazione sbagliata su data model diversi.

1. **Creare `<fm-pt-document>`** — WebComponent Lit coeso per documenti a
   **singolo body_pt** (caso custom). Incapsula:
   - view mode (render HTML via window.FM.Pt.ptToHtml) ↔ edit mode
     (`<fm-risdoc-pt-editor>` + toolbar)
   - toolbar BEM moderna: ✎ Modifica/👁 Anteprima · 💾 Salva · { } Export JSON
     · 📥 Import JSON · 📄 TeX/ZIP · ⬇ HTML
   - adapter pluggable (data-source astratto)

2. **TeacherContentAdapter** — load/save/export via `/api/teacher/content/*`.
   Migra COMPLETAMENTE le pagine custom a `<fm-pt-document>` (deprecando
   pt-inline-editor.js + button sparsi).

3. **RisdocTemplateAdapter (gated)** — adapter che carica template risdoc
   **pt_unified-only** come vista body_pt unificata. Wiring CONDIZIONATO al
   pass della suite E2E risdoc esistente (30+ test). Se i test passano →
   unificazione estesa a quei template. Se falliscono → risdoc resta sul WC
   esistente (NO rottura produzione).

4. **Template risdoc complex + statici**: RESTANO sul WC `<fm-risdoc-template>`.
   Il loro data model (schema multi-field + options_source dinamici) non è
   "un documento PT singolo" — forzarlo sarebbe lossy. Documentato come scelta
   architetturale corretta, non debito.

### Architettura componente

```
<fm-pt-document
    doc-id="123"
    source="teacher-content"     // adapter discriminator
    can-edit="1"
    title="..."
    initial-body-pt='[...]'>      // hydration SSR (evita fetch iniziale)
</fm-pt-document>
```

```
fm-pt-document (Lit)
  ├── _mode: 'view' | 'edit'
  ├── _bodyPt: PT AST
  ├── _adapter: { load, save, exportHtmlUrl, exportTexEndpoint }
  ├── renderToolbar()  → BEM .ptdoc__toolbar (azioni)
  ├── renderView()     → window.FM.Pt.ptToHtml(_bodyPt) (sanitized)
  └── renderEdit()     → <fm-risdoc-pt-editor> + <fm-risdoc-pt-toolbar>
```

### CSS BEM (modern, no legacy)

Nuovo modulo `css/modules/_pt-document.css`:
`.ptdoc` `.ptdoc__toolbar` `.ptdoc__btn` `.ptdoc__btn--primary`
`.ptdoc__body` `.ptdoc__body--editing` `.ptdoc__status`. CSS variables per
theming/dark-mode. Zero classi legacy.

## Consequences

### Positive
- **Componente coeso testabile** vs logica procedurale sparsa (pt-inline-editor).
- **Pagina personalizzata = vero WebComponent** con json/tex/html (richiesta).
- **Adapter pattern** → estensibile (risdoc pt_unified, futuri source).
- **CSS BEM moderno** isolato, no legacy.
- **Produzione protetta**: risdoc complex intatti, migrazione gated da E2E.
- Encapsulation Lit (shadow DOM) → no conflitti CSS globali.

### Negative
- `<fm-pt-document>` e `<fm-risdoc-template>` coesistono (2 componenti). Ma
  servono 2 data model legittimamente diversi → non è duplicazione errata.
- Migrazione richiede deprecare pt-inline-editor.js (rimozione controllata).
- Render view client-side dipende da window.FM.Pt.ptToHtml (bundle PT).

### Neutral
- Unificazione "totale" (1 componente per tutto) esplicitamente SCARTATA come
  anti-pattern (forzare schema-model su single-doc è lossy). Questa è la
  scelta di codice pulito richiesta dall'utente.

## Implementation plan (Fasi)

1. ✅ Design + ADR-022 (questo doc) + research data model
2. `<fm-pt-document>` + TeacherContentAdapter + CSS BEM
3. Migra ContentStudyController::renderCustomTopicHtml → emette il WC
4. Test E2E custom + screenshot (view/edit/export trio)
5. RisdocTemplateAdapter + wiring gated (E2E risdoc suite come gate)
6. Cleanup legacy (pt-inline-editor.js deprecato) + bundle + lint

## Links

- [[ADR-021-pt-document-centralization]] — superseded (layer JS → componente)
- [[ADR-020-page-doc-block-types]] — 5 block types G23
- [[ADR-002-lit3-web-components]] — Lit framework
- Component: `js/components/pt-document/fm-pt-document.js`
- Adapters: `js/components/pt-document/adapters/`
