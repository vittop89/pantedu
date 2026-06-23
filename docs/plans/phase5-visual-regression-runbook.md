# Phase 5 Visual Regression Runbook

Procedura collaborativa per chiudere Phase 5 layout.css mass-removal con
safety net via Playwright visual regression.

## Workflow generale

```
[BASELINE] su pantedu.eu live  →  [DIFF] dopo ogni layout.css cleanup
        ↓                                    ↓
   salva snapshot                    diff vs baseline
   (commit gitignored)               se = 0 diff → safe, commit
                                     se diff > 2% → rollback o accetta
```

## Comandi essenziali

### Setup iniziale (una tantum)

```powershell
# 1) Install Playwright + Chromium
npm run e2e:install

# 2) Verifica config base URL
$env:FM_E2E_BASE_URL = "https://pantedu.eu"
```

### Step 1 — Baseline (PUBLIC pages only, ~30s)

```powershell
$env:FM_E2E_BASE_URL = "https://pantedu.eu"
npm run e2e:p5:update
```

Output:
- `tests/e2e/visual_regression_phase5.spec.js-snapshots/phase5-*-{desktop,tablet,mobile}.png`
- 6 pagine × 3 viewport = 18 screenshot

### Step 2 — Baseline (incluse pagine AUTH, opzionale)

```powershell
$env:FM_E2E_BASE_URL = "https://pantedu.eu"
$env:FM_E2E_AUTH_USER = "tuoutente"
$env:FM_E2E_AUTH_PASS = "tuapassword"
npm run e2e:p5:update
```

Output addizionale:
- 13 pagine auth × 3 viewport = 39 screenshot
- Totale: 57 screenshot baseline

### Step 3 — DIFF dopo ogni layout.css cleanup

```powershell
# Esempio: io ho rimosso .fm-sync-btn-* da layout.css (-100 LOC).
# Tu lanci il diff per verificare nessuna regressione visiva:

$env:FM_E2E_BASE_URL = "https://pantedu.eu"
npm run e2e:p5
```

Se exit code = 0 → safe, posso committare.
Se exit code ≠ 0 → Playwright apre HTML report con diff visivo per ogni
    pagina che differisce. Tu mi mandi screenshot dei diff piu' grossi.

### Step 4 — Accetta nuova baseline (se diff atteso)

Caso: il diff è VOLUTO (es. ho intenzionalmente rifattorizzato un colore).
```powershell
npm run e2e:p5:update
git add tests/e2e/visual_regression_phase5.spec.js-snapshots/
git commit -m "test(visual): accept Phase 5 diff for sync-btn token migration"
```

## Cosa testare PRIMA di committare cleanup

Per ogni Sprint Phase 5 cleanup che propongo, devi:

1. **Pull** il mio branch/commit con la modifica layout.css.
2. **Run** `npm run e2e:p5` (~30s public, ~2min con AUTH).
3. **Verifica** exit code:
   - 0 → safe, ti dico che commit-id pushare.
   - != 0 → mi mandi `playwright-report/index.html` o screenshot diff.

## Rollback rapido

Se un commit Phase 5 ha causato regressione visibile in produzione:

```powershell
# Revert dell'ultimo commit Phase 5 (preserva audit-baseline)
git revert <commit-id>
git push origin main
```

## Note

- **Throttling**: i test non simulano rete lenta — usa Lighthouse CI per
  metriche Slow 3G (`npm run lh:ci`).
- **Cookies/CSRF**: lo spec gestisce login state ma dipende dal cookie
  `session` standard. Se hai 2FA attiva sul user di test, lo skip funziona.
- **DB state**: alcune pagine (es. /admin/logs) mostrano dati dinamici.
  Lo spec hide `[data-timestamp]` e `<time>` ma se vedi diff false-positive
  su contenuto database (es. ultimo accesso), aggiungi `data-timestamp`
  attribute alla view oppure aggiungi mask al test.

## Output report

Dopo ogni `npm run e2e:p5` failed:
- `playwright-report/index.html` — HTML report con diff visivo, expected
  vs actual side-by-side
- `test-results/*/` — screenshot raw diff, trace JSON, video (se attivo)

## Sequenza Phase 5 prevista

Una volta che hai la baseline acquisita:

| Sprint | Cleanup | Stima LOC eliminate da layout.css |
|---|---|---|
| 26 | `.fm-source-editor .fm-se-*` (già fatto Sprint 6) | −86 ✅ |
| 27 | `.fm-sync-btn-*` (color drift: Google green) | ~100 |
| 28 | `.fm-vd-*` (verifica documents) | ~80 |
| 29 | `.fm-pi-card-*` (print info) | ~150 |
| 30 | `.fm-import-*` (bundle import modal) | ~120 |
| 31 | `.fm-topbar__*` modern (extract module) | ~200 |
| 32 | Mass duplicate detection + removal | ~500-1000 |

**Target**: layout.css 9216 → ~5000 LOC (-45%)

Procedi quando hai eseguito Step 1+2 baseline.
