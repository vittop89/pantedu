---
tags:
  - documentazione/architettura
  - dominio/frontend
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["frontend", "javascript", "ui"]
cssclasses: []
---

# Dominio: frontend

> [!abstract] Scopo
> Layer UI: moduli JS vanilla/jQuery per editor esercizi/verifiche, Lit 3 Web Components per risdoc Plan B, routing SPA leggero, Vite build.

## Architettura frontend

Due stack coesistono:

| Stack | Scope | Entry |
|-------|-------|-------|
| **Moduli JS vanilla + jQuery** | Esercizi, verifiche, mappe, admin, sidepage | `js/modules/bootstrap.js` |
| **Lit 3 Web Components** | Risdoc Plan B form editor | `js/components/risdoc/index.js` |

## Struttura js/modules/

```
js/modules/
├── bootstrap.js          ← Entry point: importa e inizializza tutti i moduli
├── bootstrap-compat.js   ← Compat layer per legacy scripts
├── core/
│   ├── api.js            ← Fetch wrapper per API PHP
│   ├── api-jquery.js     ← Wrapper AJAX fetch-based (nome legacy, no jQuery)
│   ├── app-state.js      ← Stato globale app (selectedIIS, selectedCLS, optsel)
│   ├── config.js         ← Config: categorie MAT/GEO/FIS, URL pattern, sidebar
│   ├── data-manager.js   ← Caricamento dati (origini, modelli, checked values)
│   ├── endpoints.js      ← Costanti URL endpoint API
│   ├── store.js          ← StateManager (sessionStorage per stato shared)
│   ├── utilities.js      ← UUID, PathFileVerExtractor, tooltip, helpers
│   └── ...
├── editor/
│   ├── editor-system.js  ← Editor inline collex-item (3003 LOC)
│   ├── content-processor.js ← MathJax render, post-processing
│   ├── table-manager.js  ← Gestione tabelle editor
│   └── latex-render.js   ← Preview LaTeX
├── features/
│   ├── checkin-handlers.js ← Checkbox selection esercizi (3358 LOC)
│   ├── upbar-controls.js ← Controlli barra superiore
│   ├── verifica-builder.js ← Builder verifica
│   ├── sidepage-registry.js ← Phase 24.71: SSoT 6 sidepage (key/panel/loader/type)
│   ├── sidepage-custom-categories.js ← Phase 24.72: storage condiviso custom cat (verif/bes/risdoc)
│   ├── db-sidepage.js    ← Loader sidepage subject-grouped (mappe/lab/eser) + category-grouped (verif)
│   ├── risdoc-sidepage.js ← Loader sidepage category-grouped (bes/risdoc) + multi-instance fork
│   ├── section-edit-mode.js ← Toggle edit + modal create/edit + inline actions ✎🗑👁📥
│   ├── risdoc-editor.js  ← Editor risdoc (integrazione WC)
│   └── ...
├── integrations/
│   ├── google-apps.js    ← Google Apps Script integration
│   ├── google-apps-script.js ← Sync GDrive (1056 LOC)
│   ├── google-drive-latex-saver.js ← Salvataggio LaTeX su GDrive
│   └── overleaf-progress.js ← Progress Overleaf upload
├── print/
│   ├── print-export.js   ← Generazione LaTeX esercizi/verifiche (1865 LOC)
│   ├── print-info.js     ← Gestione print_info.json
│   ├── print-client.js   ← Client stampa
│   └── verifiche-print-ui.js ← UI stampa verifiche
├── state/
│   ├── state-manager.js  ← StateManager sessionStorage
│   └── clone-manager.js  ← Clone esercizi
└── ui/
    ├── ui-comp.js        ← Componenti UI (verificaETitoliQuesito, ecc.) (3528 LOC)
    ├── dom-manager.js    ← Manipolazione DOM, toggle sidebar, edit mode
    ├── batch-delete.js   ← Delete batch esercizi
    ├── selection-manager.js ← Gestione selezione
    ├── toast.js          ← Notifiche toast
    └── ...
```

## Web Components risdoc (Lit 3)

| Componente | File | Funzione |
|-----------|------|---------|
| `fm-risdoc-template` | `js/components/risdoc/fm-risdoc-template.js` | Orchestratore: carica schema, monta sub-WC |
| `fm-risdoc-info-field` | `fm-risdoc-info-field.js` | Campo testo/select info docente |
| `fm-risdoc-checkbox-group` | `fm-risdoc-checkbox-group.js` | Gruppo checkbox (obiettivi, criteri) |
| `fm-risdoc-form-checkbox` | `fm-risdoc-form-checkbox.js` | Checkbox singola |
| `fm-risdoc-dynamic-table` | `fm-risdoc-dynamic-table.js` | Tabella editabile con righe labeled |
| `fm-risdoc-nota-textarea` | `fm-risdoc-nota-textarea.js` | Textarea per note libere |
| `fm-risdoc-grade-selector` | `fm-risdoc-grade-selector.js` | Selettore voti |
| `fm-risdoc-giudizio-group` | `fm-risdoc-giudizio-group.js` | Gruppo giudizi |
| `fm-risdoc-giudizio-item` | `fm-risdoc-giudizio-item.js` | Item giudizio singolo |
| `fm-risdoc-glossary-table` | `fm-risdoc-glossary-table.js` | Tabella glossario |
| `fm-risdoc-signature-block` | `fm-risdoc-signature-block.js` | Blocco firma |
| `fm-risdoc-privacy-block` | `fm-risdoc-privacy-block.js` | Blocco privacy |
| `fm-risdoc-section-header` | `fm-risdoc-section-header.js` | Intestazione sezione |
| `fm-risdoc-static-content` | `fm-risdoc-static-content.js` | Contenuto statico (testo fisso da schema) |
| `fm-risdoc-text-section` | `fm-risdoc-text-section.js` | Sezione testo editabile |
| `fm-risdoc-export` | `fm-risdoc-export.js` | Pulsante/UI export ZIP/Overleaf |

## Sidebar / Sidepage architecture (Phase 24.71)

6 button sidebar (`.fm-sb-sec[data-sidepage="<key>"]`) → 6 pannelli (`#fm-sp-<key>`) popolati da 2 loader.

### Single source of truth

`js/modules/features/sidepage-registry.js` esporta `SIDEPAGES` con un entry per ogni pulsante:

| key | loader | type | group | customCategories | origin (risdoc) | supportsFork |
|-----|--------|------|-------|------------------|-----------------|--------------|
| `mappe`  | `db`     | `mappa`     | subject  | ❌ | —         | false |
| `lab`    | `db`     | `lab`       | subject  | ❌ | —         | false |
| `eser`   | `db`     | `esercizio` | subject  | ❌ | —         | false |
| `verif`  | `db`     | `verifica`  | category | ✅ | —         | false |
| `bes`    | `risdoc` | `bes`       | category | ✅ | `strcomp` | true  |
| `risdoc` | `risdoc` | `risdoc`    | category | ✅ | `risdoc`  | true  |

API: `byKey(k)`, `byType(t)`, `byPanelId(id)`, `fromPanelEl(el)`, `supportsFork(k)`, `dbLoaderDefs()`, `risdocLoaderDefs()`. Esposto su `window.FM.SidepageRegistry`.

### Custom categories (Phase 24.72)

Le sidepage con `customCategories: true` permettono al docente di creare categorie utente-definite via "✨ Nuova categoria" (in cima al panel). Storage condiviso: `js/modules/features/sidepage-custom-categories.js`.

- Chiave localStorage: `fm.sidepage.customCategories.<username>` (per-utente, migrazione automatica dalla chiave legacy `fm.risdoc.customCategories.<username>`).
- Bucket: `origin` per loader=risdoc (es. `strcomp`/`risdoc`), `type` per loader=db (es. `verifica`).
- Scope opzionale: sempre / indirizzo / indirizzo+classe / indirizzo+classe+materia (chiesto al momento della creazione).

### Pipeline render

```
sidebar.php (button + panel placeholder)
  ↓ click .fm-sb-sec[data-sidepage]
  ↓ resolve def via SidepageRegistry.byKey(key)
  ├── def.loader === "db"     → db-sidepage.loadDbContent(key, type)
  │     └── fetch /api/study/content.json?type=…&ind&cls&subject
  │     └── render <ul.fm-db-block> per materia
  └── def.loader === "risdoc" → risdoc-sidepage.loadSidepage(key, spec)
        ├── fetch /api/risdoc/templates?origin=…    (template istituzionali)
        ├── fetch /api/risdoc/teacher/instances     (istanze fork Phase 24.58)
        └── fetch /api/teacher/content?type=…       (doc personali liberi Phase 24.62)
```

### "+ Nuovo" branching

`section-edit-mode.bindSectionAddButtons` decide via `supportsFork(def.key)`:
- `true`  → `openInstanceModal` (radio fork-istanza vs documento personale libero, select gerarchico categoria→template)
- `false` → `openModal` (modal teacher_content classico per esercizi/verifiche/lab/mappe)

### Inline actions

`section-edit-mode.addInlineItemActions` distingue 3 tipi item via attributi DOM:
- `<li[data-content-id]>`   → teacher_content (✎ Modifica · 🗑 Elimina · 👁 Visibilità · 📥 Export ZIP)
- `<li[data-instance-key]>` → istanze fork risdoc/bes (✎ Rinomina · 🗑 Elimina · ⟲ Reset al template)
- `<li[data-template-id]>`  → template istituzionali, **solo super_admin** (✎ admin_edit · 📥 export ZIP TeX)

## Routing SPA leggero

| File | Funzione |
|------|---------|
| `js/fm-router.js` | Router client-side hash-based per navigazione senza reload |
| `js/fm-url-state.js` | Gestione stato URL (query params persistenti) |
| `js/fm-compat.js` | Compatibilità con codice legacy |

## Vite build

Entry: `js/modules/bootstrap.js` + `js/fm-router.js` → `public/build/assets/[name].[hash].js`.

> [!info] Integrazione Vite ✅
> `App\Support\ViteManifest::script()` è integrata in `views/partials/head.php`: emette il bundle hashato quando `public/build/manifest.json` esiste, con fallback ai moduli ESM diretti in dev (`APP_VITE_DEV`).

## Debito tecnico rilevante

- `ui-comp.js` (3528 LOC), `checkin-handlers.js` (3358 LOC), `editor-system.js` (3003 LOC): God Files JS. Candidati a split per modulo.
- Nessun test unitario per i moduli JS (solo E2E Playwright).
- jQuery come dipendenza core **rimosso**: `api-jquery.js` è stato migrato da `$.ajax` a `fetch` (G26.phase3), conserva solo l'API surface (`window.Api`/`ApiJQuery`) per i caller legacy. Il plugin `js/vendor/jquery.sticky.js` (ultimo file jQuery reale) è stato **rimosso** il 2026-06-03 (dead code: sticky stacking ora vanilla via `features/verifica-sticky.js`). Il bundle è ora 100% privo di jQuery.

## Link correlati

[[architecture]] · [[domains/risdoc/risdoc-overview]] · [[domains/esercizi/esercizi-overview]] · [[technical-debt]]
