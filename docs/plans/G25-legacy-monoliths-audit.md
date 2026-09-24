# G25 — Audit legacy monoliths (editor-system + ui-comp)

> Stato: **audit only**, refactor DEFERRED per costo/rischio.
> Branch reference: `master_vps`.

## Context

Post G24 refactor (checkin-handlers split, 31 moduli), restano 2 monoliti
JS major:

| File | LOC | Methods | jQuery `$()` calls |
|------|-----|---------|---------------------|
| `js/modules/editor/editor-system.js` | 3003 | 51 | 96 |
| `js/modules/ui/ui-comp.js` | 3554 | 53 | 374 |

Entrambi sono estrazioni Phase 9g da `functions-mod.js` (legacy). Pattern
diverso da checkin-handlers: **object-literal** invece di plain functions.

```js
// editor-system.js
export const EditorSystem = {
    _state: {...},
    _undoStack: new Map(),
    _saveUndoState: function(editorId) { ... },
    _performUndo: function(editorId) { ... },
    // ...
};
```

## Caratteristiche

### editor-system.js (3003 LOC, 51 methods)

State condiviso:
- `_state.focusedEditorId`
- `_undoStack` / `_redoStack` (Map per editorId)
- `_inputTimers` (debounce save)
- `_isPerformingUndoRedo` flag

Categorie metodi:
1. **Undo/Redo legacy** (~250 LOC, 8 methods):
   `_saveUndoState`, `_performUndo`, `_performRedo`
   → duplicato funzionalmente con `UndoManager` (editor/undo-manager.js)
2. **Editor lifecycle** (~600 LOC, 12 methods):
   `inizializzaEditor`, `_bindEditorCoreEvents`, `loadEditor`,
   `_ensureEditorNotEmpty`, `_setHeightEditor`, `_minimizeEditor`,
   `_formatBorderType_wrapper`, `_onEditorFocus`, ...
3. **Content insert** (~400 LOC, 7 methods):
   `execCmd`, `insertSOLSpan`, `_getCurrentEditorState`, ...
4. **Backup** (~300 LOC, 4 methods):
   `loadBackup`, `saveBackup`, ...
5. **TikZ cursor check** (~150 LOC, 2 methods):
   `_checkCursorPositionInTikz`, `highlightLatexComments`
6. **Editor ID generation** (~100 LOC, 3 methods):
   `genEditorID`, `generatePositionalEditorId`, `_getEditorKey`
7. **Type/display** (~80 LOC, 2 methods):
   `getEditorType`, `getEditorTypeDisplayName`

### ui-comp.js (3554 LOC, 53 methods)

Categorie metodi:
1. **MathJax typeset** (~150 LOC):
   `safeTypeset`, `safeTypesetBatch`
2. **Lista templates UI** (~800 LOC, 12 methods):
   `updateListTracciaSelector`, `preloadTracciaJSON`, `_buildTracciaUI`,
   `updateTikzElementGroups`, `preloadTikzJSON`, `_buildTikzUI`, ...
3. **CRUD form handlers** (~600 LOC, ~10 methods):
   `_createNewElementForm`, `_createDeleteElementForm`, `_createEditElementForm`,
   `_initNewElementFormHandlers`, `_initDeleteElementFormHandlers`, ...
   → **DUPLICATA** funzionalità con `tex-dropdown/crud-dialogs.js` (modular)
4. **DSA / Help tooltip** (~150 LOC):
   `_dsaTooltipManager`, `_helpMessManager`
5. **Elementi riservati** (~600 LOC, 10 methods):
   `_caricaDivRiservati`, `_caricaCheckboxABin`, `_applicaStiliColore`,
   `verificaETitoliQuesito`, `_caricaColori`, `_ensureCollexItemIds`,
   `_enforceTopicColorCycle`, `_CaricaSel_EserOr`, `_checkOriginCheckboxes`,
   `_caricaElemRiservati`, ...
6. **DSA editing** (~250 LOC):
   `_saveDSAChanges`, `_rimuoviElementiRiservati`
7. **Problem/quesito UI** (~400 LOC, 8 methods):
   `caricaGiust`, `caricaSol_VF`, `caricaModHeaderBtn`,
   `InsertCheckPos`, `CheckSolSel`, `setupProblemElements`,
   `SetHeightProblem`, `printVisitedLinks`
8. **Buttons navigation** (~200 LOC):
   `BtnInOut`, `_processLink`
9. **Upbar/scrollbar toggle** (~150 LOC, 6 methods)

## Dipendenze critiche

### jQuery
- editor-system.js: 96 `$()` calls
- ui-comp.js: 374 `$()` calls
- Pattern: `$.ajax({success, error})`, `$el.find()`, `$el.on()`, ecc.
- Refactor full = eliminare jQuery → vanilla JS DOM API (massivo).

### Cross-references EditorSystem ↔ UIComp
- `UIComp._caricaElemRiservati` triggers MathJax via `safeTypeset`
- `EditorSystem.loadEditor` chiamato da UIComp._caricaDivRiservati flow
- `EditorSystem._saveUndoState` chiamato da legacy editor binding (event-handler.js)

### Window globals
- `window.EditorSystem` exposed
- `window.UIComp` exposed
- Bridge "consumer legacy" mantenuto

## Strategia di refactor proposta

Date la dimensione + jQuery dep + cross-deps, **full split = 50-80 ore**.

### Approccio incrementale (cinque step indipendenti)

**Step A (basso rischio, 4-6h)**: Estrai utility puri
- `getEditorType`, `getEditorTypeDisplayName`, `genEditorID`,
  `generatePositionalEditorId`, `_getEditorKey`
- Modulo: `editor/legacy/editor-id-utils.js`
- Test: pure unit (no DOM/jQuery)

**Step B (medio, 8-10h)**: Unificare Undo/Redo
- Migrare `EditorSystem._saveUndoState/_performUndo/_performRedo`
  → delega al nuovo `UndoManager` (G24 module già scritto)
- Rimuovere `_undoStack`/`_redoStack` legacy Map
- Test: regression Playwright + unit UndoManager

**Step C (medio-alto, 10-15h)**: Deduplica CRUD dialogs UI
- `ui-comp.js _createNewElementForm + handlers` (~600 LOC) duplicano
  funzionalità di `tex-dropdown/crud-dialogs.js` (G24 module)
- Sostituire jQuery-based dialog con DialogHost registrations
- Test: E2E delete/edit/new element

**Step D (alto, 15-20h)**: Extract DSA + Elementi riservati subsystem
- `ui-comp.js` blocco "Elementi riservati" (~600 LOC) + DSA editing (~250 LOC)
- Modulo: `editor/dsa-elementi-riservati.js`
- Sostituire jQuery `.on()` / `.find()` con vanilla `addEventListener` /
  `querySelector`
- Test: E2E DSA mode (annotations)

**Step E (alto, 15-25h)**: jQuery removal completo
- 470+ `$()` calls in 2 file → vanilla JS
- Richiede ogni `.ajax()` → `fetch()`, ogni `.on()` → `addEventListener`
- Test: full E2E suite

### Decisione

**Refactor DEFERRED** per ora. Motivi:

1. **Risk vs reward**: 50-80h effort per migliorare manutenibilità di codice
   già funzionante. ROI basso vs altri lavori (features, bug fixing).
2. **jQuery removal richiede effort separato**: il refactor full include
   migrazione jQuery → vanilla, che è un progetto a sé stante.
3. **Functional duplication già known**: CRUD UI duplicata in ui-comp +
   crud-dialogs è documentata; nessun bug user-facing attivo.
4. **Test coverage assente**: refactor senza unit test sui 2 file = alto
   rischio regressione silente.

### Pre-requisiti per future refactor

Prima di iniziare il refactor di editor-system/ui-comp:

1. **Unit tests baseline**: Vitest + happy-dom per i 2 file (50+ test).
2. **E2E coverage**: Playwright test per DSA, elementi riservati, backup,
   undo/redo, MathJax typeset.
3. **jQuery decision**: keep jQuery o migrate vanilla? Decisione strategica.
4. **Storyboard refactor**: piano dettagliato per ognuno degli step A-E
   con commit boundaries chiari.

## Riferimenti

- editor-system.js: `js/modules/editor/editor-system.js`
- ui-comp.js: `js/modules/ui/ui-comp.js`
- UndoManager modulare (G24): `js/modules/editor/undo-manager.js`
- CRUD dialogs modulari (G24): `js/modules/editor/tex-dropdown/crud-dialogs.js`
- DialogHost: `js/modules/editor/dialog-host.js`
