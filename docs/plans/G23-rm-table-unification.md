# Piano G23 — Unificazione renderer RM/VF/Collect (client+server+TeX)

**Branch**: `master_vps` (working) → `feat/g23-rm-unification` (proposto)
**Owner**: Vittorio
**Stato**: 📋 Bozza — in attesa approvazione
**Aggiornato**: 2026-05-12

---

## 🎯 Obiettivo

Centralizzare il rendering dei quesiti VF / RM / Collect in un **unico modulo client** (`js/modules/render/`) che produca markup identico al server `ContractRenderer`. Eliminare divergenze visive post-save vs post-reload. Estendere RM con tipi colonna funzionanti (X/V/B/T/N). Garantire parità HTML ↔ TeX.

## 🐛 Bug correnti (RM tabella)

| # | Sintomo | Causa |
|---|---------|-------|
| 1 | `a./b./c./d.` davanti checkbox in preview post-save | `rebuildRmTables()` in [`js/modules/features/checkin-handlers.js:7905`](../../js/modules/features/checkin-handlers.js#L7905) emette `<span class="rm-letter">a.</span>` che il server NON emette → divergenza |
| 2 | Contenuto cella non persistito al reload | `rmLayout` patch non include `rows/cols/typecell` → server chunka 1×N invece di mantenere 2×2 + `applyEditsToDom` non aggiorna RM in-place (manca applier `options`/`rmLayout`) |
| 3 | Checkbox dentro cella in edit-mode dopo reload | `extractCellContent()` ([`checkin-handlers.js:7547`](../../js/modules/features/checkin-handlers.js#L7547)) cerca `.rm-letter, .rm-pick-choice` (markup client) ma server emette `.wrapCheckCell > input + label.collex` → strip fallisce |
| 4 | Tipi B/T/N (Button/Text/Number) non rendono nulla visibile né in TeX | `rebuildRmTables` mappa solo X→checkbox, V→radio. ContractRenderer e Sanitizer ignorano altri tipi |

## 📐 Architettura proposta

### Single Source of Truth — JS

Nuovo modulo `js/modules/render/rm-table-view.js`:

```js
// Esporta API pure (no side-effects, no DOM globali)
export function renderRmTable(state)         // → HTMLElement (.fm-rm-tables-wrap)
export function syncCellsShape(t)            // riallinea matrix rows×cols
export function extractCellContent(td)       // rimuove markup decorativo client+server
export function colTypeToInput(type)         // X→checkbox, V→radio, B→button, T→text, N→number
export function colTypeToTex(type)           // X→\square, V→\bigcirc, B→\fbox{btn}, T→\underline{ }, N→\boxed{\#}
```

**Markup unificato** (identico tra `renderRmTable()` JS e `ContractRenderer::renderRmTable()` PHP):

```html
<table class="rm-table"
       data-typecell="|X|V|"
       data-rows="2" data-cols="2"
       data-mixtr="0" data-mixcol="0"
       data-mpagew="1" data-width="">
  <tbody>
    <tr>
      <td class="rm-option" data-row="0" data-col="0">
        <div class="wrapCheckCell" style="display:flex">
          <!-- input dinamico per tipo colonna -->
          <input type="checkbox" class="checkbox checkboxRM">  <!-- X -->
          <input type="radio"    class="checkbox checkboxRM">  <!-- V -->
          <button class="rm-btn">btn</button>                  <!-- B -->
          <input type="text"   class="rm-text">                <!-- T -->
          <input type="number" class="rm-num">                 <!-- N -->
          <label class="collex"><div class="cellContent">{blocks}</div></label>
        </div>
      </td>
      …
    </tr>
  </tbody>
</table>
```

**No `.rm-letter`** lato client/server: le lettere sono **derivate** dall'indice cella `r*cols+c → chr(97+idx)` solo dove servono (Sanitizer TeX, label `\textbf{a.}`).

### Server PHP

Refactor `ContractRenderer.php`:

- Estrai `renderRmTable(array $opts, array $rmLayout): string` come metodo dedicato (rimuove logica inline da [`renderItem():239-319`](../../app/Services/ContractRenderer.php#L239)).
- Usa `rmLayout.rows` e `rmLayout.cols` per `array_chunk($opts, $cols)` (default 2×2 se assenti, no più auto-detect heuristic).
- Emette stesso markup del JS (`data-rows`/`data-cols`/`data-typecell` espliciti).
- Mappa `typecell` char → HTML input via `RmColumnTypes::toHtml($type)` helper.

Nuova class `app/Services/Rendering/RmColumnTypes.php`:

```php
final class RmColumnTypes
{
    public const TYPES = ['X','V','B','T','N'];

    public static function toHtml(string $type, bool $checked=false): string;
    public static function toTex(string $type): string;
    public static function inputName(string $type): string; // "checkbox"|"radio"|"button"|"text"|"number"
}
```

Usato da:
- `ContractRenderer::renderRmTable()` (HTML)
- `Sanitizer::convertRmTable()` (TeX) — [`Sanitizer.php:210`](../../app/Services/TexBuilder/Sanitizer.php#L210)

### Save flow — uniformato

Estendi `FIELD_APPLIERS` ([`checkin-handlers.js:8090`](../../js/modules/features/checkin-handlers.js#L8090)):

```js
const FIELD_APPLIERS = {
    quesito: …,
    soluzione: …,
    giustificazione: …,
    metadata: …,
    badge: …,
    // NUOVI
    options: (item, val, allFields) => applyRmTableEdits(item, val, allFields.rmLayout),
    rmLayout: (item, val, allFields) => applyRmTableEdits(item, allFields.options, val),
    // VF — già coperto da giustificazione (block) ma serve anche per "answer"
    answer: (item, val) => applyVfAnswer(item, val),
    // Collect — già coperto da soluzione
};
```

`applyRmTableEdits(item, options, rmLayout)`:
- Costruisce state RM dal patch
- Chiama `renderRmTable(state)` (modulo nuovo)
- Sostituisce `.fm-rm-tables-wrap` esistente
- Tipeset MathJax + TikZ

Tutti gli `FIELD_APPLIERS` ricevono `(item, val, allFields)` come signature (estensione backward-compat: valore default `allFields = {}` se non passato, comportamento esistente preservato).

### Capture flow — uniformato

`_captureEditorFields()` ([`checkin-handlers.js:7938`](../../js/modules/features/checkin-handlers.js#L7938)):

- Aggiungi `rows`, `cols`, `typecell`, `mixtr`, `mixcol`, `mpagew`, `specificWidth` come `.fm-editor-rmlayout` (oggi solo `table_count` e `orientation` sono marcati così → server non riceve dimensioni).
- Centralizza in helper `extractRmLayoutFromPanel(panel)` riusabile.
- `options[]` derivati dalle celle `.fm-editor-field[data-field^="rm-cell-"]` (già esistente, mantieni).

### Allowlist server

`TeacherContentController::readQuesitoPatchBody()` ([`TeacherContentController.php:1143`](../../app/Controllers/TeacherContentController.php#L1143)):

- Allowlist già contiene `options`, `metadata`, `rmLayout` ✅
- Validazione: aggiungi schema check su `rmLayout.rows ∈ [1,12]`, `cols ∈ [1,6]`, `typecell ~= /^\|[XVBTN](\|[XVBTN])*\|$/`.

### Contract schema bump

`docs/spec/contract.schema.json` (se esiste) → aggiungi `rmLayout.rows`, `rmLayout.cols`. Backward-compat: assenti = default 2×2.

---

## 📋 Roadmap (fase per fase)

### Phase 1 — Estrazione modulo JS (no behavior change)
- [ ] Crea `js/modules/render/rm-table-view.js` con `renderRmTable`, `syncCellsShape`, `extractCellContent`, `colTypeToInput`
- [ ] Sposta `syncCellsShape` + `extractCellContent` da `checkin-handlers.js` (mantieni re-export per call sites esistenti, segna `@deprecated`)
- [ ] `rebuildRmTables` (legacy) → wrapper che chiama `renderRmTable()` + replace DOM
- [ ] Markup unificato: `.wrapCheckCell > input + label.collex > div`, NO `.rm-letter`
- [ ] Test unit JS (Vitest? Jest? — verifica setup esistente): roundtrip state↔DOM↔state

### Phase 2 — Allineamento server PHP
- [ ] Estrai `ContractRenderer::renderRmTable()` come metodo privato
- [ ] Crea `app/Services/Rendering/RmColumnTypes.php` (helper centralizzato HTML/TeX/inputName)
- [ ] `ContractRenderer::renderRmTable()` usa `rmLayout.rows/cols` per chunking (no auto-detect)
- [ ] Backward-compat: se `rmLayout` assente → fallback 2×2 deterministico
- [ ] Test PHPUnit: `tests/Unit/ContractRendererRmTest.php` — match snapshot HTML per tipi X/V/B/T/N

### Phase 3 — Save/Apply flow
- [ ] `_captureEditorFields`: marca `rows/cols/typecell/mixtr/mixcol/mpagew/specificWidth` come `.fm-editor-rmlayout`
- [ ] Helper `extractRmLayoutFromPanel(panel)`
- [ ] Estendi `FIELD_APPLIERS` con `options` + `rmLayout` (handler `applyRmTableEdits`)
- [ ] Aggiorna signature `applier(item, val, allFields)` — preserva backward-compat
- [ ] `_captureEditorFields`: derive `rmLayout.rows/cols` da matrice celle (single source from grid editor)

### Phase 4 — TeX builder
- [ ] `Sanitizer::convertRmTable()` usa `RmColumnTypes::toTex()` per simbolo cella
- [ ] Letter prefix: deriva da `r*cols+c` (già fallback esistente alla riga 237 — fai diventare path primario)
- [ ] Test PHPUnit `tests/Unit/TexBuilderTest.php` — aggiungi case X/V/B/T/N misti, mix righe/colonne

### Phase 5 — Tipi colonna B/T/N
- [ ] `RmColumnTypes::toHtml()`: B → `<button>`, T → `<input text>`, N → `<input number>`
- [ ] `RmColumnTypes::toTex()`: B → `\fbox{btn}`, T → `\underline{\ \ \ \ }`, N → `\boxed{\#}`
- [ ] CSS: `.rm-btn`, `.rm-text`, `.rm-num` styling minimal in `css/layout_es.css`

### Phase 6 — VF/Collect cleanup
- [ ] Verifica `applyEditsToDom` flow per VF: `answer` patch deve flip `.sol .V/.F` class (non c'è applier oggi)
- [ ] Verifica Collect: `soluzione` applier OK (esiste)
- [ ] Test PHPUnit: snapshot VF/Collect (no behavior change ma copertura)

### Phase 7 — E2E Playwright
- [ ] Test `tests/e2e/rm-table.spec.js`:
  - Edit cella RM (lista nested) → save+close → reload → contenuto preservato
  - Cambio colonna X→V→B→T→N → preview live coerente
  - Mix righe/colonne attivo → output PDF coerente
  - Screenshot diff: pre-save / post-save / post-reload (3 stati uguali)
- [ ] Test `tests/e2e/vf-collect-save.spec.js`: regressione VF/Collect save flow

### Phase 8 — Documentazione + Wiki
- [ ] `wiki/domains/esercizi/rm-table-rendering.md` — nuovo
- [ ] `wiki/changelog/2026-05.md` — entry G23
- [ ] `docs/api/openapi.yaml` — no change (allowlist già OK, schema esteso opzionale)
- [ ] Aggiorna `wiki/_llm-primer.md` map con G23

---

## ⚠️ Rischi & trade-off

| Rischio | Mitigazione |
|---------|-------------|
| Contract legacy senza `rmLayout.rows/cols` | Fallback deterministico 2×2 nel renderer server |
| Snapshot test HTML cambiano (no `.rm-letter`) | Aggiorna fixtures in 1 commit dedicato, conferma visual diff zero |
| Markup `.rm-letter` referenziato da CSS legacy | Grep `.rm-letter` selector → rimuovi rule o sposta a `::before` dinamico CSS counter |
| Editor JS legacy `js/modules/editor/table-manager.js` (Phase 9g) tocca `.wrapCheckCell` | Verifica compatibilità: il modulo legacy lavora a livello DOM su istanze già renderizzate, parità markup mantiene contratto |
| Sub-renderer TikZ/MathJax in celle | `renderRmTable()` chiama `processTikzScripts(wrap)` + `MathJax.typesetPromise([wrap])` come oggi |

## 🔗 Impact graph

```
[Phase 1: js/render module]
       ↓
[Phase 2: PHP server] ←→ [Phase 4: TeX Sanitizer]
       ↓                         ↓
[Phase 3: save/apply flow] ─┐    │
       ↓                    │    │
[Phase 5: B/T/N types] ─────┼────┘
       ↓                    │
[Phase 6: VF/Collect] ──────┘
       ↓
[Phase 7: E2E test]
       ↓
[Phase 8: docs]
```

## 📊 File toccati (preview)

| File | Phase | Tipo modifica |
|------|-------|---------------|
| `js/modules/render/rm-table-view.js` (NEW) | 1 | +250 LOC |
| `js/modules/features/checkin-handlers.js` | 1,3 | -100 / +50 LOC (estrae logica) |
| `app/Services/ContractRenderer.php` | 2 | refactor `renderItem` RM branch (estrae metodo) |
| `app/Services/Rendering/RmColumnTypes.php` (NEW) | 2,5 | +80 LOC |
| `app/Services/TexBuilder/Sanitizer.php` | 4 | usa `RmColumnTypes::toTex` |
| `tests/Unit/ContractRendererRmTest.php` (NEW) | 2 | +6 test |
| `tests/Unit/TexBuilderTest.php` | 4 | +3 test |
| `tests/e2e/rm-table.spec.js` (NEW) | 7 | +4 test |
| `tests/e2e/vf-collect-save.spec.js` (NEW) | 7 | +2 test |
| `css/layout_es.css` | 5 | +20 LOC stili B/T/N |
| `wiki/domains/esercizi/rm-table-rendering.md` (NEW) | 8 | docs |

**Diff stimato**: ~600 LOC aggiunte, ~150 LOC rimosse/refactored, ~50 LOC modificate.

## ✅ Definition of Done

1. Salvataggio editor RM → reload pagina → markup identico (snapshot diff = 0)
2. Tutti i 5 tipi colonna X/V/B/T/N funzionano in HTML editor, HTML view, TeX/PDF
3. VF e Collect save flow regressione test passa
4. Test E2E Playwright verde con screenshot allegati
5. Wiki + changelog aggiornati
6. `php tools/wiki/strip_code_links.php --check` passa
7. `git diff master_vps...` non tocca file di `master`

---

**Estimate**: 3-4 giorni full-time (8 phase × ~4-6h ciascuna).
