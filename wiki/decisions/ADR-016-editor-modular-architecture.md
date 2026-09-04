---
tags:
  - documentazione/adr
  - frontend
  - editor
date: 2026-05-12
tipo: ADR
status: accettato
aliases: ["editor-arch", "G24-refactor", "G24-checkin-split"]
---

# ADR-016 — Editor modular architecture (G24 refactor)

> [!info] Decision finale: monolite `checkin-handlers.js` decomposto in **31 moduli ES** organizzati per layer architetturale (foundations → domain models → services → views → orchestrator → dialog host).

## Context

Pre-refactor: `js/modules/features/checkin-handlers.js` aveva raggiunto
**9109 LOC** e ~200 funzioni top-level. Tutta la logica dell'editor inline
(item + group) viveva in un singolo file:

- UI building (panel, sections, toolbar)
- Lifecycle (open/close/save/revert)
- Field serialization (capture/apply)
- Inline format (B/I/U toggle)
- List editing (indent/outdent OL/UL)
- TikZ/GeoGebra block dialogs
- Find & Replace dialog
- TeX dropdown subsystem (workspace + CRUD dialogs)
- RM table rendering controls
- Autosave + conflict resolution
- Multi-tab lock (BroadcastChannel)
- Keyboard shortcuts (Ctrl+B/I/M/F/S/Z, Tab)
- Copy/paste sanitization
- Popup preview
- Color cycle Phase 16

Problemi:
1. **Navigazione difficile**: localizzare bug richiede grep, no quick-jump per layer
2. **Test quasi impossibile**: tutto coupled a DOM + globals
3. **Bundle monolitico**: 800 KB main bundle, no code splitting
4. **Cross-cutting concerns**: ogni modifica tocca N punti scattered

## Decision

Refactor incrementale a 5 layer + bundle splitting, completato in **20 commit**.

### Layer 1 — Foundations (pure utilities)

Moduli pure (zero file-state, riusabili ovunque):

| Modulo | LOC | Scope |
|--------|-----|-------|
| `caret-utils.js` | 140 | Selection/Range API helpers |
| `list-edit-utils.js` | 244 | Indent/outdent OL/UL nested |
| `html-text-utils.js` | 60 | Escape HTML/TeX (escHtml/escTexJs) |
| `inline-blocks-markers.js` | 118 | TikZ/GeoGebra collapse/expand markers |
| `inline-format.js` | 767 | B/I/U toggle smart split/unwrap/re-wrap |
| `color-utils.js` | 88 | Phase 16 topic color cycle |
| `preview-scroll.js` | 30 | Sync scroll textarea↔preview |
| `cell-popup-preview.js` | 96 | RM cell popup MathJax |

### Layer 2 — Domain models

Encapsulano state + observers, lifecycle owned dal modello:

| Modulo | Scope |
|--------|-------|
| `rm-layout-model.js` | RmLayoutModel: state RM (orientation, tables[]) + pub/sub change |
| `undo-manager.js` | UndoManager: snapshot-based Ctrl+Z/Y per contenteditable |
| `editable-field-factory.js` | EditorFieldBuilder composer pattern (mixin DI) |

### Layer 3 — Services

Singleton service per cross-cutting concerns:

| Modulo | Scope |
|--------|-------|
| `tex-workspace-service.js` | Fetch chain workspace TikZ + cache + pub/sub |
| `conflict-resolver.js` | Strategy 409 (silent vs interactive) |
| `dialog-host.js` | Register-by-id + dynamic import per dialog rari |
| `editor-multitab-lock.js` | BroadcastChannel cooperative lock |

### Layer 4 — Views

Render UI state-driven (osservano i model del Layer 2):

| Modulo | Scope |
|--------|-------|
| `rm-layout-view.js` | View state-driven da RmLayoutModel |
| `section-builders.js` | 4 builder semplici (metadata, badge, radio) |
| `section-builder-full.js` | buildSection completa (textarea + preview + TikZ btns) |
| `textarea-enhancements.js` | List key handlers + textarea hotkeys (factory) |
| `tex-dropdown/dropdown-view.js` | UI building TeX dropdown toolbar |
| `tex-dropdown/block-dialogs.js` | TikZ modal / GeoGebra / Template filler (lazy) |
| `tex-dropdown/crud-dialogs.js` | 7 CRUD dialogs workspace (lazy) |
| `find-replace-dialog.js` | Find & Replace VS Code-like (lazy) |

### Layer 5 — Orchestrator

State machine ciclo di vita editor:

| Modulo | Scope |
|--------|-------|
| `editor-session.js` | EditorSession + Item/GroupEditorSession + applier registry |

### Pattern architetturali

1. **Factory + Dependency Injection**: ogni modulo "fat" (es. RmLayoutView,
   section-builder-full) esposto come `createXxxView(deps)`. Le deps sono
   esplicite, modulo standalone.
2. **Observer pub/sub**: model emette `change:*` events → view sottoscrive
   per re-render automatico (no rebuildXxx manuali scattered).
3. **Lazy-init via Promise cache**: dialog rari (CRUD, block, find/replace)
   sono `import()` dynamic, cached al primo use.
4. **Open/closed extension**: `EditorSession.registerApplier(kind, field, fn)`
   per FIELD_APPLIERS pluggable da domain modules.

### Bundle splitting

Lazy chunks separati (NON nel main bundle):

```
bootstrap.js               783 KB (main, gz 217 KB)
├── crud-dialogs.js        18.7 KB (lazy)
├── block-dialogs.js        3.2 KB (lazy)
└── find-replace-dialog.js  5.7 KB (lazy)
```

Caricati al primo open del dialog corrispondente. Total 27.6 KB
deferred.

## Risultati

```
checkin-handlers.js: 9109 → 5220 LOC (-3889 LOC, -42.7%)
31 moduli editor/ creati (~12200 LOC totali)
Main bundle: 803 → 783 KB (-20 KB)
3 chunk lazy: 27.6 KB totali
```

Build vite verificato dopo ogni step (20 commit). Comportamento user-facing
identico (semantic refactor, zero feature change).

## Alternatives considered

1. **Big-bang rewrite** — REJECTED: alto rischio regressione, no commit
   granulari = no rollback parziale.
2. **Class-based OOP**: classe Editor con metodi ovunque — REJECTED:
   non risolve coupling, sposta solo le funzioni dentro classi.
3. **TypeScript migration**: AGGIUNTA tipi al refactor — REJECTED: scope
   creep, TS migration è ortogonale e va in suo refactor dedicato.
4. **MobX/Redux state management**: REJECTED: overkill per state lokal
   editor, observer pattern interno è sufficiente.

## Consequences

### Positive

- **Navigazione**: tutto chiaramente layer-organized. Bug in inline format
  → `editor/inline-format.js` (770 LOC), no più 9000 LOC grep.
- **Test**: ogni layer testabile in isolamento. Layer 2 (models) pure unit
  test possibili. Layer 4 (views) integration via DOM mock.
- **Bundle**: -20 KB main + lazy chunks. TTI prima apertura editor stessa,
  ma dialog rari non penalizzano.
- **Extension**: domain modules (RM, TikZ, metadata) plug-in via
  EditorSession registry senza toccare checkin-handlers.

### Negative / Trade-off

- **More files**: 31 vs 1. Tooling/IDE deve supportare cross-file nav.
  Mitigato da naming convention (`editor/<layer>-<concern>.js`).
- **Indirezione**: monolite call diretta → factory + DI. +1 livello
  cognitivo per leggere il codice. Mitigato da factory naming esplicito
  (`createXxxView`).
- **Maintenance burden**: 31 file vs 1. Aggiungere feature richiede capire
  quale layer toccare. Mitigato dal pattern: foundations rare, layer 4-5
  più frequenti.

### Migration future

Refactor è **complete** per gli obiettivi G24. Possibili evoluzioni:

1. **TypeScript migration**: type annotation incrementale su moduli pure
   (Layer 1-2). Layer 4 più complessi (DOM types).
2. **Unit test coverage**: smoke test per RmLayoutModel/UndoManager/
   ConflictResolver come baseline. E2E via Playwright resta primary.
3. **`editor-system.js` (3003 LOC) split**: altro monolite con stesso
   pattern Phase 9g. Candidate per analogo refactor (deferred).
4. **`ui-comp.js` (3554 LOC) split**: idem.

## References

- Plan: G24-refactor-checkin-split (completato 2026-05-12, in git history)
- Pre-refactor LOC: 9109 (commit `1f7a9a0c`)
- Post-refactor LOC: 5220 (commit `343dff34`)
- 20 commit refactor totali: vedi `git log --oneline --grep "g24"`
- Vite bundle reports: `npm run build` produce manifest.json
