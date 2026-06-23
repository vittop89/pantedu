# TODO #1 — Editor refactor: deep audit + design proposal

> Stato: 2026-05-23 audit completo.
> Scope: rimozione `.style.cssText` + `.style.*` assignments dai 16 file
> JS editor + render + risdoc, sostituzione con classi CSS dedicate per
> permettere a `_editor-toolbar.css` (modulo token-based) di vincere senza
> `!important` (vedi TODO #1 + #3 nel `css-refactor-residue-todo.md`).

## Audit numerico

### File coinvolti e occorrenze (totale `.style.*` assignments)

| File | `.style.*` total | di cui `.style.cssText` |
|---|---|---|
| editor-system.js | 42 | 0 |
| dropdown-view.js | 37 | 22 |
| rm-layout-view.js | 34 | 34 |
| pm-schema.js | 19 | 0 |
| section-builder-full.js | 18 | 15 |
| section-builders.js | 14 | 14 |
| crud-dialogs.js | 13 | 5 |
| table-manager.js | 12 | 7 |
| resize-sync.js | 8 | 0 |
| rm-table-view.js | 5 | 5 |
| cell-popup-preview.js | 5 | 0 |
| tex-dropdown-helpers.js | 2 | 2 |
| editable-field-factory.js | 2 | 2 |
| color-utils.js | 2 | 0 |
| tikz-render-client.js | 1 | 1 |
| inline-format.js | 1 | 1 |

**Totale**: ~215 `.style.*` assignments, di cui **108 `.style.cssText`**
(bulk inline styling).

### Pattern proprietà CSS (top 20 da cssText)

| Property | Occurrences |
|---|---|
| padding | 46 |
| display | 45 |
| font | 40 |
| color | 31 |
| gap | 30 |
| border | 28 |
| background | 28 |
| border-radius | 26 |
| cursor | 19 |
| align-items | 19 |
| margin-bottom | 11 |
| flex-direction | 10 |
| flex | 10 |
| font-weight | 9 |
| flex-wrap | 7 |
| z-index | 6 |
| position | 6 |
| min-width | 6 |
| justify-content | 5 |

### Categorie di pattern (75 unique cssText strings)

1. **Flex layout primitives** (~25 patterns): `display:flex;flex-direction:column;gap:Xpx;`
2. **Text styling** (~15 patterns): `font-weight:600;color:#333;font-size:Xpx`
3. **Input styling** (~10 patterns): `padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui`
4. **Card box** (~10 patterns): `margin:10px 0;padding:10px;background:#eef4ff;border:1px solid #b0c8e8;border-radius:4px`
5. **Dialog overlay** (~5 patterns): `position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100050;display:flex;align-items:center;justify-content:center`
6. **Menu / dropdown items** (~10 patterns): `display:flex;align-items:center;gap:8px;padding:6px 12px;border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer`

## Refactor strategy proposta

### Approach: extract → classify → swap

Per ciascuno dei 75 unique cssText patterns:

1. **Identifica pattern usage** (count + scope: editor/dropdown/dialog/table)
2. **Mappa a classe CSS dedicata** in nuovo modulo `_editor-rm-layout.css`:
   - `.fm-rm-flex-col` per `display:flex;flex-direction:column;gap:2px`
   - `.fm-rm-card` per `padding:10px;background:#eef4ff;border:1px solid #b0c8e8;border-radius:4px`
   - `.fm-rm-input` per `padding:4px 6px;border:1px solid #ccc;border-radius:3px;font:13px/1 system-ui`
   - `.fm-rm-label` per `font-weight:600;color:#333`
   - `.fm-rm-dialog-overlay` per overlay fixed inset:0
3. **Refactor JS**: sostituire `el.style.cssText = "..."` con `el.className = "fm-rm-card"` (multi-class via `el.classList.add(...)`).
4. **Token migration**: nelle nuove classi CSS, hex hardcoded → var(--fm-c-*) dove possibile.
5. **Test E2E**: Playwright check su 20+ scenari editor.

### Effort estimation

| Step | Effort |
|---|---|
| 1. Inventariare 75 patterns + bucketing | 4-6h |
| 2. Creare modulo `_editor-rm-layout.css` (15-25 classi) | 4-8h |
| 3. Refactor JS file-by-file (16 file × ~30min/file) | 8-12h |
| 4. Token migration nelle nuove classi | 4-6h |
| 5. Test E2E Playwright (20 scenari) | 8-12h |
| 6. Rimozione `!important` da `_editor-toolbar.css` (TODO #3) | 2-3h |
| 7. Visual regression diff + fixes | 4-6h |

**Totale**: 5-8 giorni-uomo (matches roadmap originale).

### Risk

- **Editor regression**: refactor pesante in codice critico (Quill, Tiptap, CodeMirror, drawio). Richiede manual testing scenari complessi (insert formula, paste markdown, etc.).
- **Tikz/MathJax interaction**: cssText spesso applicato post-render via MutationObserver. Refactor a class non deve rompere render pipeline.
- **drawio embed**: `.style.cssText` su iframe wrapper può interagire con SVG generato da drawio. Test specifico.
- **Multi-instance per-page**: rm-layout-view + section-builder applicano stili su DOM dinamico. Multi-section page può richiedere namespacing classi.

### Trigger per avviare

Sprint dedicato Q3 2026 ("Editor refactor sprint"). Pre-requisiti:
- ✅ Phase 5 EXTRACTION completa (DONE)
- ✅ Visual regression baseline acquisita (DONE — 57 snapshots)
- ⏳ Design review approval per modulo `_editor-rm-layout.css` BEM naming
- ⏳ Test scenarios documentati (20+ editor workflows critici)

## Connessione con altri TODO

- **TODO #3** (`.fm-editor-toolbar` `!important` removal): sub-task. Bloccato finché JS builder usano `.style.*`.
- **TODO #5** (Phase 6 token migration): le nuove classi `_editor-rm-layout.css` saranno already token-based (NON c'è ereditarietà da hex hardcoded).
- **TODO #2** (sidebar BEM): orthogonale, ma stesso pattern refactor JS+CSS+view.

## Comandi audit veloci (rerun)

```bash
# .style.* totals per file
grep -rcE "\.style\.[a-zA-Z]+\s*=" js/modules/editor/ js/modules/render/ js/modules/risdoc/ \
  | sort -t: -k2 -rn | head -20

# cssText patterns unici
grep -rohE "style\.cssText\s*=\s*[\"'][^\"']{20,160}[\"']" \
  js/modules/editor/ js/modules/render/ js/modules/risdoc/ \
  | sed "s/.*=\s*[\"']//" | sed "s/[\"']\$//" | sort -u

# CSS properties top
grep -rohE "style\.cssText\s*=\s*[\"'][^\"']*[\"']" \
  js/modules/editor/ js/modules/render/ js/modules/risdoc/ \
  | tr ';' '\n' | awk -F: '{print $1}' | sort | uniq -c | sort -rn
```
