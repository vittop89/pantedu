---
tags:
  - documentazione/adr
  - frontend
  - css
  - performance
  - a11y
date: 2026-05-24
tipo: ADR
status: accettato
aliases: ["css-modernization", "sprint-A-G", "css-legacy-deprecation", "css-phase5", "css-bem-migration"]
---

# ADR-018 — CSS modernization strategy (Sprint A→G + roadmap residuo)

> [!info] Decision finale: monolite CSS legacy (~16.5K LOC, 6 file) decomposto in **architettura @layer + 40+ moduli BEM**. Sprint A→G completati 2026-05-23/24, **-6866 LOC legacy (-96.2%)**. Strategia "rename to `_<area>-legacy.css`" preserva selettori critici durante migration.

## Context

Pre-refactor (2026-Q1): CSS legacy distribuito su 6 monoliti

| File legacy | LOC iniziali | Scope |
|---|---|---|
| `css/layout.css` | 9302 | App shell, sidebar, topbar, content layout |
| `css/layout_es.css` | 3906 | Exercise routes (`/esercizio/*`) |
| `css/layout_editor.css` | 1198 | Editor inline checkin |
| `css/admin.css` | ~750 | Admin dashboard (`/admin/*`) |
| `css/waf.css` | ~600 | WAF dashboard (`/admin/waf/*`) |
| `css/shell.css` | ~370 | Login/admin shell pages |
| **TOTALE** | **~16126** | — |

Problemi:

1. **Specificità ingovernabile**: `.section-form .container input[type="text"].big`
   chain a 5+ livelli scattered su più file
2. **Cascade order fragile**: load order link tag determinava chi vinceva, no
   strategia esplicita (no `@layer`)
3. **Dipendenze nascoste**: classi legacy referenziate da PHP partials + JS
   inline + template editor — refactor "safe" era impossibile senza grep
   manuale cross-stack
4. **Performance mobile**: ~280 KB raw / ~40 KB gzipped solo `layout.css`,
   problematico per scuole rurali su Slow 3G (target docs/plans/roadmap-plan.md)
5. **Dark mode patch-on-patch**: 173 LOC dark mode sparse in admin.css con
   `@media (prefers-color-scheme)` ripetuti
6. **No deprecation policy**: legacy ammessi "per sempre" senza piano
   estinzione

Constraint critico: **integrazioni cross-stack**. Selettori legacy usati da:

- PHP partials (`resources/views/`)
- JS modules (`js/modules/features/*.js`)
- Template editor (contenuto user-generated con classi inline)
- Email templates (`resources/mail/*.blade.php`)
- WAF dashboard widget injection runtime

Rimozione brutale = regressioni grafiche silenti.

## Decision

Refactor incrementale in 7 sprint (A→G), preservando legacy via **rename pattern + @layer overrides**.

### Architettura target (ITCSS-inspired)

```
@layer settings, generic, elements, objects, components, utilities, overrides;

settings  → /css/tokens.css        (design tokens)
generic   → /css/a11y.css          (skip link, sr-only, focus baseline)
elements  → /css/elements.css      (bare HTML: html, body, p, a, h1-h6…)
components → /css/components.css   (aggregator → /css/modules/_*.css BEM)
utilities → /css/utilities.css    (atomic .fm-mt-2 .fm-flex …)
overrides → legacy + _*-legacy.css (deprecazione progressiva)
```

Single entry: `<link rel="stylesheet" href="/css/main.css">`.

### Naming convention

- **BEM moduli**: prefisso `.fm-` (FismaPanted) — `.fm-card`, `.fm-btn--primary`,
  `.fm-modal__header`
- **Legacy file**: `_<area>-legacy.css` (es. `_shell-legacy.css`,
  `_exercise-legacy.css`) per indicare deprecazione + facilità grep
- **Override layer**: tutti legacy in `layer(overrides)` per vincere su components
  durante migration ma rispettare cascade @layer

### Sprint completati

| Sprint | Data | Target | Risultato |
|---|---|---|---|
| A1-A5 | 2026-05-22/23 | layout.css dedup → moduli BEM | -758 LOC |
| B1-B2 | 2026-05-23 | admin.css → `_admin-cards.css` + `_dark-mode.css` | -276 LOC |
| C1-C3 | 2026-05-23 | waf.css → `_waf-dashboard.css` (tables, forms, badges) | -589 LOC |
| D | 2026-05-23 | layout_editor.css → `_editor-builder.css` | -1198 LOC |
| E | 2026-05-23 | layout_es.css → `_exercise-legacy.css` (VERBATIM) | -3906 LOC |
| F | 2026-05-24 | shell.css → `_shell-legacy.css` (VERBATIM) | -282 LOC |
| G | 2026-05-24 | admin.css residue → `_admin-legacy.css` | -353 LOC |
| H-DEAD | 2026-05-24 | 51 classi DEAD removal (KG-driven, batch 3×) | -221 LOC + cleanup -95 LOC |
| K | 2026-05-24 | 5 HOT classes → BEM (vedi [[ADR-019]]) | 1007 refs cross-stack |
| L | 2026-05-24 | 13 WARM classes prefix .fm-* | 936 refs in 95 files |
| M | 2026-05-24 | 68 COLD classes prefix .fm-* | 1190 refs in 91 files |
| N | 2026-05-24 | 146 FROZEN classes prefix .fm-* | 1005 refs in 48 files |
| **TOT** | | | **5145 refs migrated, ~232 classi BEM** |

### Sprint H-DEAD execution (2026-05-24)

KG-driven scan v2 (regex stricter + ghost-ref verify):
- 392 classi legacy mappate cross-stack (1128 file scansionati)
- 52 DEAD confermate via paranoid runtime-injection check
- 1 esclusa manualmente (`.tab` troppo generica)
- **51 classi rimosse safely** in 3 batch atomici (commit bf2eeba/e03f05d/7755727)

Toolchain Sprint H:
- `docs/analysis/kg-scan-v2.py` — stricter class extraction da CSS selectors
- `docs/analysis/kg-verify-dead.py` — paranoid runtime-injection detection
- `docs/analysis/kg-ghost-ref.py` — comment-only refs detection
- `scripts/css-prune-dead.mjs` — regex-based CSS pruner (no postcss dep)
- `tests/e2e/css_modernization_baseline.spec.js` — visual regression spec
- `tests/e2e/screenshots/compare-baseline.mjs` — pixelmatch diff

Bilancio Sprint H-DEAD:
- 54 rules CSS rimossi
- 78 selettori trimmed (mixed-selector rules)
- 221 LOC saved + 95 LOC whitespace cleanup
- Build Vite verificato (✓ 209ms)
- E2E sidebar suite ✓ green

### Strategia VERBATIM (Sprint E, F, G)

File legacy mantenuti **byte-per-byte** rinominati `_<area>-legacy.css` + spostati
in `css/modules/`. Vantaggi:

1. Zero rischio regressione (selettori identici)
2. Permette grep cross-stack per identificare uso reale
3. Permette deprecation incrementale **selector-by-selector**
4. Cascade preserved tramite `layer(overrides)`

Strategia BEM-extract (Sprint A-D) usata solo per moduli dove dedup era
ovvia (cards, buttons, forms, badges). Verbatim usata per scope grandi/
intricati (exercise route 3906 LOC, shell 282 LOC).

### Phase 5 dismantle progress

`layout.css`: 9302 → 146 LOC (-9156 LOC, -98.4%) via Phase 5 → 17 nuovi
moduli VERBATIM (vedi memory `project_css_phase5_complete`).

## Alternatives considered

1. **Big-bang rewrite Tailwind/UnoCSS** — REJECTED:
   - Mismatch progetto (PHP server-rendered, no JIT compilation pipeline)
   - User-generated content nei template editor usa classi legacy "vive"
   - Effort ~80 giorni-uomo vs 35-50 stimati attuale

2. **CSS Modules + PostCSS bundle per route** — REJECTED:
   - Richiede tooling Vite esteso a tutti i partials PHP
   - Code-splitting per route già fattibile con conditional `<link>` (vedi
     roadmap Phase 6) senza CSS Modules

3. **Inline critical CSS via Critters/Beasties** — DEFERRED a Phase 7:
   - Prerequisite: moduli BEM stabili (Sprint A-G era prereq)
   - Critical extraction richiede LCP path stabile

4. **Cancellazione brutale legacy** — REJECTED:
   - Selettori usati cross-stack (PHP + JS + template user-content)
   - Senza KG (Fase 2 prossima) impossibile validare safety

5. **Lit web components + shadow DOM** — REJECTED (per ora):
   - Style isolation rompe integrazione con `prefers-color-scheme` token
   - ADR-002 Lit usato solo per `<verifica-doc>` web component, non
     general-purpose
   - Considerare per editor toolbar futuro (deferred ADR)

## Consequences

### Positive

- **-6866 LOC legacy** in 7 sprint sequenziali (~2 giorni totali)
- **Cascade esplicito**: `@layer` rende deterministica priorità, zero
  `!important` aggiunti durante refactor
- **Mobile bundle**: `layout.css` 9302→146 LOC riduce download Slow 3G
- **Dark mode unified**: 173 LOC → `_dark-mode.css` singolo modulo
- **Build verificata** dopo ogni sprint via XAMPP locale + push VPS
- **Test E2E Playwright** screenshot baseline (vedi `tests/e2e/screenshots/`)
  cattura regressioni grafiche per ogni sprint

### Negative / Trade-off

- **Più file**: ~40 moduli `_*.css` vs 6 monoliti iniziali. Mitigato da
  naming convention `_<area>-<purpose>.css` + aggregator `components.css`
- **Legacy ancora presente**: `_*-legacy.css` totale ~3500 LOC. Deprecazione
  full richiede Fase 2-5 (KG-based, sprint H+)
- **Override layer cresciuto**: 5 import in layer(overrides). Long-term
  target = 0 import. Tracked via roadmap Phase 7
- **Vendor CSS**: Quill 1.3.6 + jQuery dependencies non ancora migrate
  (ADR separato pending)

### Migration future (post-ADR-018)

1. ~~**Fase 2 — KG dependency map**~~ ✅ DONE (Sprint H-DEAD)
2. ~~**Fase 3 — Risk scoring**~~ ✅ DONE (docs/analysis/risk-scoring.md)
3. **Fase 4 — Visual regression baseline** (infrastructure ready):
   - Spec + comparator pronti (vedi `tests/e2e/css_modernization_baseline.spec.js`)
   - Baseline run pending: `FM_BASELINE=1 npx playwright test css_modernization_baseline`
4. ~~**Fase 5 — SPARC cycle**~~ ✅ Plan ready (sprint-H-DEAD-sparc (completato, in git history))
5. **Sprint I-FROZEN-RENAME** (deferred — design decisions needed):
   - 193 classi FROZEN (1-2 ref) richiedono BEM rename + source migration
   - Italianismi → English: `.titolo`→`.fm-title`, `.checkIN`→`.fm-checkin`,
     `.upbar`→`.fm-topbar`
   - Strategy: ADD alias BEM in CSS, MIGRATE source refs, REMOVE legacy
   - Non eseguibile autonomo (cross-stack edits, visual regression critical)
6. **Sprint J-COLD** (104 classi 3-9 ref) — batch BEM equivalent
7. **Sprint K-WARM** (28 classi 10-29 ref) — ADR dedicato per ogni classe critica
   (`.problem`, `.collex-item`, `.rm-table`, `.DraggableContainer`)
5. **Phase 6 (roadmap-plan)** — Code-splitting CSS per route
6. **Phase 7 (roadmap-plan)** — Critical CSS inline (FOUC zero)
7. **Phase 8 (roadmap-plan)** — Image pipeline WebP/AVIF
8. **Phase 9 (roadmap-plan)** — Service Worker offline-first
9. **Vendor CSS migration**: Quill 1.3.6 → ProseMirror (già installato),
   jQuery → vanilla incrementale

## References

- Plan strategico: `docs/plans/roadmap-plan.md`
- Memory persistente (slug `.claude/.../memory/`): `project_css_phase5_complete`,
  `project_css_architecture`, `feedback_legacy_css`
- Sprint A-G commit range: `dc782b9..05a6fa2` (2026-05-22 → 2026-05-24)
- Bilancio finale: `05a6fa2 docs(css): plan finale Sprint A→G — bilancio -6866 LOC legacy (-96.2%)`
- Screenshot baseline E2E: `tests/e2e/screenshots/g*-*.png`
- Browser support target: 2026 baseline (Chrome 99+, FF 97+, Safari 15.4+)
- WCAG 2.2 AA target: vedi `css/a11y.css` + roadmap section 2
- Performance budget: < 100KB gzipped totale, TTI < 5s Slow 3G (roadmap §2)
- Related ADR: [[ADR-002-lit3-web-components]] (web components scope limitato),
  [[ADR-010-modern-topbar]] (esempio modernizzazione modulo)
