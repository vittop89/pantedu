---
tags:
  - documentazione/dominio
  - esercizi
  - editor
date: 2026-05-12
tipo: architecture-overview
status: attivo
aliases: ["editor-arch", "editor-modules"]
---

# Editor Architecture Overview

> [!info] ADR: [[decisions/ADR-016-editor-modular-architecture]]
> Plan: G24-refactor-checkin-split (completato 2026-05-12, in git history)

## Layer overview

```
┌───────────────────────────────────────────────────────────┐
│ Layer 5 — Orchestrator                                    │
│   EditorSession + Item/GroupEditorSession                 │
│   applier registry per kind/field (open/closed extension) │
└───────────────────────────────────────────────────────────┘
           ↑ uses                          ↓ delegates
┌───────────────────────────────────────────────────────────┐
│ Layer 4 — Views (DOM rendering)                           │
│   rm-layout-view, section-builder-full,                   │
│   section-builders, textarea-enhancements,                │
│   preview-scroll, cell-popup-preview,                     │
│   tex-dropdown/{dropdown-view, block-dialogs,             │
│                  crud-dialogs}, find-replace-dialog       │
└───────────────────────────────────────────────────────────┘
           ↑ uses                          ↓ observes
┌───────────────────────────────────────────────────────────┐
│ Layer 3 — Services (cross-cutting singleton)              │
│   tex-workspace-service, conflict-resolver,               │
│   dialog-host, editor-multitab-lock                       │
└───────────────────────────────────────────────────────────┘
           ↑ uses                          ↓ state via
┌───────────────────────────────────────────────────────────┐
│ Layer 2 — Domain models                                   │
│   RmLayoutModel (observer pub/sub)                        │
│   UndoManager (snapshot Ctrl+Z/Y)                         │
│   EditorFieldBuilder (composer mixin DI)                  │
└───────────────────────────────────────────────────────────┘
           ↑ uses
┌───────────────────────────────────────────────────────────┐
│ Layer 1 — Foundations (pure utilities)                    │
│   caret-utils, list-edit-utils, html-text-utils,          │
│   inline-blocks-markers, inline-format, color-utils,      │
│   preview-scroll, undo-manager                            │
└───────────────────────────────────────────────────────────┘
```

## Module catalog

### Layer 1 — Foundations

Funzioni pure, zero stato. Riusabili da qualunque editor.

| Modulo | API | Note |
|--------|-----|------|
| `editor/caret-utils.js` | `ceCaretOffset`, `ceSetCaret`, `placeCaretAtStart/End`, `setRangeAtOffsets`, ... | Selection/Range API |
| `editor/list-edit-utils.js` | `findEnclosingLi`, `indentListItem`, `outdentListItem`, `makeEmptyList`, ... | OL/UL nested |
| `editor/html-text-utils.js` | `escHtml`, `escapeHtml`, `escTexJs`, `nl2br`, `containsInlineHtml`, ... | Escape HTML/TeX |
| `editor/inline-blocks-markers.js` | `collapseTikzBlocks`, `expandTikzMarkers`, `collapseGeoGebraBlocks`, ... | Markers `⟨🔍 TikZ #N⟩` |
| `editor/inline-format.js` | `toggleInlineFormat`, `wrapSnippet`, `insertEditableInlineBox`, `insertLinkDialog`, `normalizeInlineBlockNesting`, ... | B/I/U + dots/AddTextDSA |
| `editor/color-utils.js` | `applyColorToCollexItem`, `applyTopicColorCycle`, `rgbToColorName` | Phase 16 cycle |
| `editor/preview-scroll.js` | `syncPreviewScroll` | Sync scroll ratio |
| `editor/cell-popup-preview.js` | `showCellPopupPreview`, `hideCellPopupPreview`, `updateCellPopupPreview` | RM cell popup |

### Layer 2 — Domain models

State machine + observer pattern.

| Modulo | API | Eventi emessi |
|--------|-----|---------------|
| `editor/rm-layout-model.js` | `RmLayoutModel` class: `setOrientation`, `setTableCount`, `setRows/Cols`, `setColType`, `setCell`, `setFlag`, `setSpecificWidth`, `on(event, fn)`, `toJSON()`, `RmLayoutModel.fromDom(...)` | `change:orientation`, `change:tableCount`, `change:table`, `change` (composite) |
| `editor/undo-manager.js` | `UndoManager.attach(field)`, `.save(field)`, `.undo(field)`, `.redo(field)` | (no events, hook su keydown) |
| `editor/editable-field-factory.js` | `makeEditableField()`, `EditorFieldBuilder` (composer): `.setAttrs()`, `.setInitialValue()`, `.withRichEditing()`, `.withDebouncedInput()`, `.withPopupPreview()`, `.build()` | — |

### Layer 3 — Services

Singleton globali con API esplicita.

| Modulo | API singleton | Note |
|--------|---------------|------|
| `editor/tex-workspace-service.js` | `texWorkspace`: `.load()`, `.refresh()`, `.invalidate()`, `.getCached()`, `.setCache(data)`, `.onChange(fn)` | Fetch chain: `/tikz/workspace` → `/tikz/effective-templates` → `/modelli_tikz_elements.json` → `/modelli_tikz.json` |
| `editor/conflict-resolver.js` | `silentRetryStrategy`, `interactivePromptStrategy({promptFn?, message?})`, `resolveByMode(silent, ctx)` | Strategy pattern 409 |
| `editor/dialog-host.js` | `dialogHost`: `.register(id, loader)`, `.open(id, opts)`, `.prefetch(id)`, `.list()` | Dynamic import dialog |
| `editor/editor-multitab-lock.js` | `acquireLock(key, callbacks)`, `releaseLock(key)`, `isLockedByOther(key)` | BroadcastChannel cooperative |

### Layer 4 — Views

Render DOM. Tutte factory `createXxxView(deps)` con dependency injection.

| Modulo | Factory | Note |
|--------|---------|------|
| `editor/rm-layout-view.js` | `createRmLayoutView({createEditableField, rebuildRmTables, extractCellContent, ...})` | Subscriber su RmLayoutModel events |
| `editor/section-builders.js` | `buildMetadataSection`, `buildMetaInput`, `buildBadgeColorField`, `buildRadioSection` (exports diretti) | 4 builder semplici |
| `editor/section-builder-full.js` | `createBuildSectionView({makeEditableField, collapseTikzBlocks, expandedValue, bindPreview, updatePreview, ..., getBlockDialogs})` | Textarea + Preview + TikZ btns |
| `editor/textarea-enhancements.js` | `createListKeyHandlers(deps).attach(field)`, `createEnhanceTextarea(deps).attach(ta)` | copy/paste/keydown handlers |
| `editor/tex-dropdown/dropdown-view.js` | `createDropdownView({texWorkspace, makeSectionLabel, ..., getCrud})` | UI TeX ▾ toolbar |
| `editor/tex-dropdown/block-dialogs.js` | `createBlockDialogs({toast, updatePreview})` (lazy chunk) | TikZ modal + GeoGebra + Template filler |
| `editor/tex-dropdown/crud-dialogs.js` | `createCrudDialogs({toast, confirmDialog, escapeHtml, apiPost, ...})` (lazy chunk) | 7 dialog workspace CRUD |
| `editor/find-replace-dialog.js` | `openFindReplaceDialog(panel, opts)` (lazy chunk) | Find/replace VS Code-like |

### Layer 5 — Orchestrator

Lifecycle state machine.

| Modulo | API | Note |
|--------|-----|------|
| `editor/editor-session.js` | `EditorSession` class: `mount()`, `capture()`, `save()`, `applyToDOM()`, `revert()`, `unmount()`. `ItemEditorSession`, `GroupEditorSession` subclass. `EditorSession.registerApplier(kind, field, fn)`, `.for(target)`, `.listAppliers()` | Per-target state, applier registry |

## Dependency rules

```
Layer N può usare moduli Layer ≤ N.
Layer N NON può importare moduli Layer > N.
```

Esempi validi:
- `inline-format` (Layer 1) usa `caret-utils` (Layer 1) ✓
- `rm-layout-view` (Layer 4) usa `rm-layout-model` (Layer 2) ✓
- `editor-session` (Layer 5) usa registry interno, no Layer 4 view imports ✓

Esempi VIETATI:
- `caret-utils` (Layer 1) NON può importare `editor-session` (Layer 5) ✗
- `rm-layout-model` (Layer 2) NON può importare `rm-layout-view` (Layer 4) ✗

`checkin-handlers.js` è l'**unico** consumer cross-layer: orchestra
il wiring tra tutti i layer con factory + DI.

## Lazy-load pattern

I 3 dialog rari (find-replace, block, crud) sono lazy-loaded via dynamic
import:

```js
// checkin-handlers.js
let _crudDialogsPromise = null;
function _ensureCrudDialogs() {
    if (!_crudDialogsPromise) {
        _crudDialogsPromise = import("../editor/tex-dropdown/crud-dialogs.js")
            .then((mod) => mod.createCrudDialogs({...deps}));
    }
    return _crudDialogsPromise;
}
async function openGroupRenameDialog(...args) {
    const d = await _ensureCrudDialogs();
    return d.openGroupRenameDialog(...args);
}
```

Pattern conseguenze:
- Tutti i wrapper sono `async function` → call-site devono `await` o handler
  click già async
- Wrapper esistenti in `dropdown-view.js` ricevono `getCrud` (async getter)
  invece di `crud` (oggetto) → `(await getCrud()).method(...)` nei click
- Build vite genera chunk separati nel `public/build/assets/`:
  `crud-dialogs.HASH.js`, `block-dialogs.HASH.js`, `find-replace-dialog.HASH.js`

## DialogHost registry

Discovery centralizzata per E2E test/debug:

```js
window.FM.dialogHost.list()
// → ["find-replace", "tex.template-filler", "tex.tikz-modal",
//    "tex.geogebra-editor", "tex.group-rename", "tex.element-editor",
//    "tex.insert-editor", "tex.reset-workspace", "tex.new-or-import",
//    "tex.new-element", "tex.filler-row"]

window.FM.dialogHost.open("tex.group-rename", { groupKey: "gruppo-x" });
```

## EditorSession orchestrator

```js
// In openItemEditor:
const session = new ItemEditorSession(item, {
    lockKey: `item-${item.dataset.id}`,
    capture: () => _captureEditorFields(panel),
    save: async () => _saveItemEditorInPlace(item, panel),
});
session.mount(panel);

// In closeItemEditor:
EditorSession.for(item)?.unmount();
```

Applier registry per kind+field (open/closed extension):

```js
// Domain module può registrare nuovo field handler
EditorSession.registerApplier("item", "myField", (target, value, session) => {
    // ... mutate target DOM
});

// applyEditsToDom delega a session se attiva:
const session = EditorSession.for(item);
if (session) session.applyToDOM(fields);
else /* fallback FIELD_APPLIERS legacy */;
```

## Bundle layout

```
public/build/assets/
├── bootstrap.HASH.js              783 KB  (main bundle)
├── crud-dialogs.HASH.js            18.7 KB (lazy)
├── block-dialogs.HASH.js            3.2 KB (lazy)
├── find-replace-dialog.HASH.js      5.7 KB (lazy)
├── caret-utils.HASH.js              1.9 KB (chunked by vite auto)
└── ... (altri chunk: tex-element-editor, tikz-editor-modal, ecc.)
```

Lazy chunks caricati on-demand al primo open del dialog corrispondente.

## How to add a new feature

Esempi pratici per estendere l'editor:

### Aggiungere nuovo field type (item kind)

1. Implementa applier (puro DOM mutation):
   ```js
   function applyMyFieldEdit(item, value) {
       const el = item.querySelector(".my-field");
       if (el) el.textContent = value;
   }
   ```
2. Registra in `EditorSession`:
   ```js
   EditorSession.registerApplier("item", "myField", applyMyFieldEdit);
   ```
3. `applyEditsToDom(item, {myField: "..."})` ora lo gestisce.

### Aggiungere nuovo dialog

1. Crea modulo factory (lazy-load candidate):
   ```js
   // editor/tex-dropdown/my-dialog.js
   export function createMyDialog(deps) {
       return { open: (opts) => { /* ... */ } };
   }
   ```
2. Lazy wrapper in `checkin-handlers.js`:
   ```js
   let _myDialogPromise = null;
   function _ensureMyDialog() {
       if (!_myDialogPromise) {
           _myDialogPromise = import("...my-dialog.js")
               .then((m) => m.createMyDialog({toast, ...}));
       }
       return _myDialogPromise;
   }
   ```
3. Registra in DialogHost:
   ```js
   dialogHost.register("my.dialog", async (opts) => {
       const d = await _ensureMyDialog();
       return d.open(opts);
   });
   ```

### Aggiungere nuovo control RM layout

1. Estendi `RmLayoutModel` con nuovo setter:
   ```js
   setMyOption(idx, value) {
       const t = this._table(idx);
       if (t.myOption === value) return;
       t.myOption = value;
       this._emit("change:table", { idx, field: "myOption" });
   }
   ```
2. UI in `rm-layout-view.js` collega input → `model.setMyOption(idx, ...)`.
3. Subscriber `model.on("change:table", triggerRebuild)` rebuilda auto.

## Performance considerations

- **Bundle**: lazy chunks evitano 27 KB nel main per chi non apre dialog.
- **Observer**: model setter checkano `if (current === new) return` per
  evitare emit no-op.
- **Debounce**: `EditorFieldBuilder.withDebouncedInput(cb, ms)` evita
  thrashing typing.
- **Idle prefetch**: dialog-host.js fa `requestIdleCallback(() => dialogHost.prefetch("find-replace"))` post-page-load per pre-warm cache.

## Testing strategy

- **Layer 1**: pure unit (Jest/Vitest) — input → output deterministico.
- **Layer 2**: state machine unit — set..., on...(spy), verify events.
- **Layer 3**: services — mock fetch/document, verify state transitions.
- **Layer 4**: integration con JSDOM — verify DOM rendering.
- **Layer 5**: full E2E (Playwright) — open editor, edit, save, verify.

Stato attuale: solo Playwright E2E. Unit test futures.

## Migration notes

I 4 sotto-sistemi residui (deferred):

1. `editor-system.js` (3003 LOC) — phase 9g extraction, candidate analogo refactor
2. `ui-comp.js` (3554 LOC) — idem
3. `updatePreview` (200 LOC in checkin-handlers) — chain MathJax/TikZ, deps deep
4. `bindCheckinHandlers` + onCheckin* (root delegation) — orchestrator, va in
   EditorSession dispatcher futures

## See also

- ADR: [ADR-016-editor-modular-architecture](../../decisions/ADR-016-editor-modular-architecture.md)
- Plan completo: G24-refactor-checkin-split (completato 2026-05-12, in git history)
