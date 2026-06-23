---
date: 2026-05-24
tipo: SPRINT-recommendations
sprint: I-J (manual execution required)
status: pending
---

# Sprint I/J — Rename + Dedup Recommendations (manual execution)

**Authored**: 2026-05-24 (post Sprint H-DEAD completion)
**Why manual**: Cross-stack edits richiedono visual regression baseline runtime, design decisions per BEM naming, e effort 10+ min/classe (~20h totali per 127 FROZEN single-ref).

## Pre-requisiti

1. **Visual regression baseline**: `FM_BASELINE=1 npx playwright test css_modernization_baseline`
2. **XAMPP attivo** su `pantedu.local`
3. **Backup branch**: `git checkout -b sprint-I-rename-bem` per safe iteration

## Strategy — Backward-compat alias pattern

Per ogni rename, **NON** rimuovere classe legacy immediatamente. Pattern:

```
1. CSS: ADD nuova classe BEM con stessi rules (alias). Keep legacy.
2. SOURCE: UPDATE refs gradualmente (PHP/JS/HTML). Test ogni batch.
3. VISUAL REGRESSION: gate <1% diff dopo ogni batch source.
4. CSS: REMOVE classe legacy quando tutti refs migrati.
5. CSS: REMOVE alias (alias diventa classe canonical BEM).
```

## Bucket A — Italianismi → English BEM

Classi con nome italiano che hanno BEM equivalent ovvio in inglese.

| Legacy class | Refs | Files | BEM target | Risk | Notes |
|---|---|---|---|---|---|
| `.titolo` | 11 | mix PHP/JS | `.fm-exercise-title` | HIGH | Compound selectors complex (`.titolo h1`, `.titolo.fm-related-header`). Renaming = riscrivere ~15 rules. Better: keep nome, document scope. |
| `.checkIN` | 1+ | js/modules/features/checkin-handlers.js | `.fm-checkin` | MEDIUM | Domain class. Verificare se nome è in user-content templates. |
| `.giust` / `.giustifica` / `.giustificazioni` | n/a | n/a | `.fm-justify*` | LOW | Verificare se sono in DEAD list o ancora in uso |
| `.caricaGiust` | 1+ | n/a | `.fm-load-justify` | LOW | Camel case + italianismo. Likely safe rename. |
| `.Selezioni` | 0 | (DEAD) | — | — | Già rimosso Sprint H |
| `.Editor_wrapper` | n/a | n/a | `.fm-editor-wrapper` | LOW | Camel case anomaly |

## Bucket B — Duplicati Legacy / BEM

Classi che hanno BEM equivalent ATTIVO in moduli. La versione legacy è candidata rimozione.

| Legacy class | Modern equivalent | Action |
|---|---|---|
| `.upbar` (11 refs) | `.fm-topbar` (modulo `_topbar-modern.css`) | Migrate refs source → `.fm-topbar`, then remove legacy. **NB**: `.upbar` ha compound selectors complex (body.fm-topbar-active .upbar). Verificare equivalenza visuale. |
| `.btn-UpBar` | `.fm-btn` modifier | Define `.fm-btn--topbar` modifier, migrate |
| `.tabelle` (modTable) | `.fm-table` o `.fm-rm-table` | Migrate refs, remove legacy |
| `.active` (11 refs) | `.fm-X--active` BEM modifier pattern | Existing pattern, just align refs |

## Bucket C — Compound selector dead-trim opportunities

Classi DEAD (zero ref production) ma con compound rules ancora in CSS.
Mio prune script gestisce questo correttamente.

Esempio già fatto: `.sync-quesiti_ver-btn` — solo test, gestisce null graceful, CSS rules trimmed (compound) o keep (rule full dead requires `.selector-eser` alive).

| Candidate | Status |
|---|---|
| `.sync-quesiti_ver-btn` | ✅ DONE (Sprint I micro-batch) |
| `.scelta-versione-checkbox` | ⚠️ HOLD: test g19_print_info_scelte espone con `expect(isChecked).toBe(true)`. Removing CSS breaks test. Decidere: rimuovi test O reimplementa feature. |

## Bucket D — Structural cleanup

Classi legacy con scope domain-specific che probabilmente vanno tenute.

| Class | Reason | Recommendation |
|---|---|---|
| `.problem` (42 refs) | Core exercise rendering | Keep, considerare `.fm-exercise-problem` rename con alias period |
| `.collex` / `.collex-item` (25/32 refs) | Collapsible exercise items | Keep, BEM-ify a `.fm-collex` / `.fm-collex__item` |
| `.rm-table` (14 refs) | RM table widget | ADR dedicato. Domain class. |
| `.DraggableContainer` (11 refs) | Editor drag-drop | Camel case anomaly. Migrate to `.fm-draggable-container` |

## Bucket E — Test-only classes

Classi referenziate solo da test files. Test code può essere updated insieme a CSS.

| Class | Test file | Action |
|---|---|---|
| `.sync-quesiti_ver-btn` | g19_5_selector_eser_visual | DONE (handled null graceful) |
| `.scelta-versione-checkbox` | g19_print_info_scelte | HOLD (test relies on it for assertion) |

## Execution plan suggerito

### Sprint I-1 — Bucket B easy wins (low risk)
1. Visual baseline capture
2. `.active` BEM alignment (likely no source change, only CSS dedup)
3. `.btn-UpBar` → `.fm-btn--topbar` (1 file CSS + grep replacements)
4. Visual gate + commit

### Sprint I-2 — `.upbar` → `.fm-topbar` migration
1. Verify `.fm-topbar` rules cover all `.upbar` styling
2. ADD `.upbar` alias in `_topbar-modern.css` (temporary)
3. Migrate source refs: `git grep -l 'class="[^"]*upbar' | xargs sed -i 's/\bupbar\b/fm-topbar/g'`
4. Visual gate
5. Remove `.upbar` rules from `_topbar-modern.css` + `_exercise-legacy.css`
6. Commit each step

### Sprint I-3 — Italianismi safer subset
1. `.Editor_wrapper` → `.fm-editor-wrapper` (camel→kebab)
2. `.caricaGiust` → `.fm-load-justify`
3. Per ognuna: source migrate → visual gate → CSS rule rename

### Sprint J — COLD bucket (104 classi 3-9 ref)
Batch da 5-10 classi per sprint, stesso pattern.

## Tooling refresher

Per ogni rename eseguire:

```bash
# 1. Find all refs
grep -rln "classname" --include='*.{php,js,mjs,html,twig}' .

# 2. Replace via sed (verify pattern first with --dry-run via PowerShell)
git ls-files | xargs sed -i 's/\boldname\b/newname/g'

# 3. Run E2E smoke
npx playwright test sidebar.spec --timeout=20000

# 4. Visual regression
npx playwright test css_modernization_baseline
node tests/e2e/screenshots/compare-baseline.mjs

# 5. Commit
git commit -m "refactor(css): rename .oldname → .newname (BEM)"
```

## Estimated effort

- Sprint I-1: 2h (CSS-only changes)
- Sprint I-2 (`.upbar`): 4-6h (complex compound selectors)
- Sprint I-3 italianismi: 1-2h each (~5 classi)
- Sprint J COLD: 1-2 giorni per batch da 10 classi

Totale Sprint I+J: ~3-5 giorni-uomo con visual regression hardware running.
