# CSS Refactor TODO residue — Roadmap concreta

> Stato: 2026-05-23 dopo Phase Roadmap 5 Sprint 26-43 EXTRACTION COMPLETE.
> 37 moduli in `css/modules/` active. layout.css 9302 → 146 LOC (-98.4%).
> Restano 2 TODO non-trivial + 1 nuova fase (token migration per modulo).

## Status quo

### Done (Phase Roadmap 5 Sprint 26-43 — extraction complete)

**37 moduli** active in `css/modules/`:

Pre-Phase 5 (mass-extraction commits 326d848, ac20454, 50b0483 +
incrementali Sprint 6-25): 20 moduli iniziali — buttons, forms,
modals, alerts, cards, breadcrumb, badges, topbar, admin-toolnav,
sidebar (BEM structural), editor-toolbar (chrome), login-federated,
grid, tables, sidepage, source-editor, admin-tabs, admin-cards,
waf-dashboard, toasts, template-form.

Phase 5 Sprint 26-43 — 17 nuovi moduli VERBATIM byte-equivalent
extraction (hex hardcoded preservati per zero visual drift):
verifica-doc (1426), topbar-modern (2353), exercise-overrides (923),
area-docente (817), dark-mode (677), sidebar-widgets (485),
db-sidepage (439), sidebar-core (386), legacy-modals (335),
upbar-legacy (314), print-info (301), drive-integration (280),
import-bundle (263), control-panel (220), sync-bar (182),
content-layout (101) + editor-toolbar exteso (322).

layout.css stato finale: 146 LOC (`@import tokens` + `:root` width
vars + `body`/`a` reset + 12 removal markers per git blame).

### Done (Phase D.x TODO #4 + #2 partial)

- ✅ `tokens.css` esteso con 12 sidebar section brand colors
  (`--fm-c-sec-mappe`/`-lab`/`-eser`/`-verif`/`-bes`/`-risdoc`
  + hover variants + verif/risdoc border variants)
- ✅ `layout.css` `.fm-sb-sec[data-sidepage="..."]` ora referenzia
  i token via `var(--fm-c-sec-*)`. Visualmente identico, centralizzato.
- ✅ `views/partials/sidebar.php`: `<div class="sidebar">` ora è
  `<nav class="sidebar fm-sidebar" aria-label="...">` — landmark
  semantico per screen reader + start migration BEM.

## TODO residue richiede JS-side refactor

### TODO #1 — `_editor.css` full integration (Quill/Tiptap/CodeMirror)

**Scope**: `layout_editor.css` 1205 LOC + porzione editor in `layout.css`
~ 500 LOC. Totale ~1700 LOC integrazione editor.

**Blocker**: refactor inline-style dei JS builders. Editor builders
(es. `js/modules/editor/editor-system.js`,
`js/modules/render/tikz-render.js`,
`js/modules/risdoc/risdoc-builder.js`) settano `element.style.*`
runtime. La legacy `.fm-editor-toolbar` usa `!important` ovunque
per VINCERE quegli inline styles.

**Path forward (3 step)**:

1. **Audit JS builders** — grep `element.style\.` nei moduli editor:
   ```bash
   grep -rnE '\.style\.[a-zA-Z]+\s*=' js/modules/editor/ js/modules/render/
   ```
   Per ciascuna occorrenza valutare se si può sostituire con
   `element.classList.add/remove` + classe CSS dedicata.

2. **Refactor builders** — rimuovere inline styles, sostituire con
   classi (`.fm-fmtbtn-active`, `.fm-tex-dropdown--open`, ecc.).
   Test E2E che editor funziona ancora (Playwright).

3. **Migration CSS** — quando i builders sono inline-style-free,
   rimuovere `!important` da `layout.css` editor-toolbar block.
   Spostare regole in `_editor.css` token-based (estende
   `_editor-toolbar.css` modulo già attivo).

**Effort stimato**: 5-8 giorni-uomo con test regression Playwright
su 20+ scenari editor (Quill insert formula, Tiptap markdown paste,
CodeMirror diff view, drawio embed, TeX compile pipeline).

**Audit numerico (2026-05-23)**: 16 file JS con `.style.*` assignments,
top contributors:
- `js/modules/editor/editor-system.js` — 42 occurrences
- `js/modules/editor/tex-dropdown/dropdown-view.js` — 37
- `js/modules/editor/rm-layout-view.js` — 34
- `js/modules/risdoc/pt/pm-schema.js` — 19
- `js/modules/editor/section-builder-full.js` — 18
- `js/modules/editor/section-builders.js` — 14
- `js/modules/editor/tex-dropdown/crud-dialogs.js` — 13
- `js/modules/editor/table-manager.js` — 12

Pattern dominanti: `.style.cssText` (107 — bulk inline styles), `.style.display`
(48 — show/hide toggles), `.style.height` (18 — resize sync), `.style.backgroundColor`
(6). Il pattern cssText è il più invasivo (bulk override su singolo statement).

**Trigger per avviare**: ufficio sviluppo dedicato (es. Sprint
"Editor refactor 2026-Q3").

---

### TODO #3 — `.fm-editor-toolbar` !important removal

**Sub-task di TODO #1**. Senza il refactor JS builders, rimuovere
`!important` rompe il rendering visivo del toolbar (gli inline
styles dei builder vincerebbero le regole CSS).

**Workaround interim** disponibile: usare `_editor-toolbar.css`
nuovo modulo (già attivo) per QUALSIASI NUOVO toolbar editor.
Lo zero-!important pattern è disponibile, basta che i nuovi
builders non settino inline styles.

---

### TODO #2 — sidebar BEM full migration

**Scope**: `views/partials/sidebar.php` + tutti i JS che
referenziano `.fm-sb-*` classes.

**Status corrente** (allineamento 2026-06-18): HTML wrapper migrato a
`<nav class="sidebar fm-sidebar" aria-label="...">`. **Step 2 (CSS
aliasing/extension) ✅ completato** in commit `b8f10ab` — `_sidebar.css`
estesa con le variants `.fm-sidebar__section[data-sidepage="..."]`
(mappe/lab/eser/verif/bes/risdoc) sticky + token-driven via
`var(--fm-c-sec-*)`. Restano da fare gli step 3 (view migration
classi inner `.fm-sb-*` → `.fm-sidebar__*`) e 4 (cleanup legacy). Le
inner classes (`.fm-sb-sec`, `.fm-sb-panel`, `.fm-sb-tip`, ecc.)
restano ancora legacy nel markup/JS.

**Path forward (4 step incrementali)**:

1. **JS audit** — quali file referenziano `.fm-sb-*`?
   ```bash
   grep -rn '\.fm-sb-' js/ | head -30
   ```
   Pianifica refactor mapping vecchio→nuovo BEM.

2. ✅ **CSS extension/variants** (FATTO — commit `b8f10ab`) — in
   `_sidebar.css` aggiunte le variants
   `.fm-sidebar__section[data-sidepage="..."]` branded token-driven,
   risolvendo il blocker "colori branded" descritto sotto. Permette il
   refactor incrementale view-by-view degli step 3-4.

3. **View migration** — partial-by-partial change
   `class="fm-sb-sec"` → `class="fm-sidebar__section"`. Test
   visual + screen reader.

4. **Cleanup legacy** — quando 100% migrato, rimuovere `.fm-sb-*`
   da layout.css. Mantenere `.fm-sidebar__*` puro.

**Effort stimato**: 2-3 giorni con visual regression test.

**Audit numerico (2026-05-23)**: `fm-sb-*` referenziata in **12 file JS +
1 view PHP** = **55 occorrenze totali**.
- JS: `bootstrap-compat.js`, `bootstrap.js`, `db-sidepage.js`,
  `risdoc-sidepage.js`, `sidepage-edit-toggle.js`, `sidepage-highlight.js`,
  `sidepage-inline-actions.js`, `sidepage-registry.js`,
  `student-resource-auth.js`, `verifica-documents-sidepage.js`,
  `google-apps.js`, `dom-manager.js`.
- View PHP: `views/partials/sidebar.php`.

**Blocker step 2 (CSS aliasing)**: `_sidebar.css` BEM module ha
palette token-driven neutra; le `.fm-sb-sec[data-sidepage="..."]`
legacy usano i branded sidepage colors (`--fm-c-sec-mappe`,
`--fm-c-sec-lab`, etc. in `tokens.css`). Aliasing `.fm-sidebar__section,
.fm-sb-sec` causerebbe regressione visiva sui colori branded. Richiede
prima estensione `_sidebar.css` con variants `.fm-sidebar__section--{mappe,
lab,eser,verif,bes,risdoc}` che ereditano i token sidepage.

**Trigger**: quando si fa una page refresh estesa che già tocca
queste view (avoid pure-refactor PR che è hard to review).

---

---

### TODO #5 — Token migration per modulo (Phase Roadmap 6)

**Scope**: 17 moduli estratti VERBATIM in Sprint 26-43 contengono
hex hardcoded (es. `#34a853` Google green in `_sync-bar.css`).
Per uniformare al design system → swap hex → `var(--fm-c-*)`.

**Status (2026-05-23 audit + 2 safe swap eseguiti)**: Phase 5 extraction
completata garantisce zero visual drift via hex hardcoded. Token migration
è la fase successiva (opt-in per modulo).

- ✅ **Safe swaps eseguiti**: `_content-layout.css` (#fff → --fm-c-surface
  per .fm-external-iframe), `_admin-tabs.css` (.fm-tab-badge color #ffffff
  → --fm-c-text-inverse). Light identico, dark theme-aware (improvement).
- ⚠️ **CORREZIONE post-audit**: i token sono **theme-aware** (cambiano
  valore via media query light/dark). Esempio `--fm-c-text` = `#1f2937`
  light, `#e5e7eb` dark. Quindi swap hex hardcoded → token NON è
  byte-equivalent in dark mode. Rende il modulo "theme-driven" (corretto
  da design system POV ma cambia comportamento dark mode).
- 🚧 **Risk caso-per-caso**: moduli con palette dark mode hardcoded
  duplicata (es. `body.fm-dark .fm-tile--alert { background: #3a3020; }`)
  richiedono decisione: (a) swap + eliminare regole dark hardcoded
  ridondanti (accetta drift se palette diverge), (b) aggiungere token
  ad-hoc per match exact, (c) lasciare hex hardcoded.

**Risk**: alcuni hex non hanno match esatto in `tokens.css` (es.
Google Drive green `#34a853` vs `--fm-c-success #2a7a3d`). Swap
diretto cambierebbe il colore. Richiede decisione per ciascun
caso: (a) usare token esistente + accettare drift, (b) aggiungere
nuovo token per match exact, (c) lasciare hex hardcoded.

**Path forward (per ogni modulo)**:

1. Identificare hex hardcoded vs token match
2. Per ogni hex senza exact-match: decidere via design review
3. Eseguire swap + commit
4. Verificare visual diff post-deploy via Playwright (`npm run e2e:p5`)

**Ordine consigliato** (small first per de-risk):
content-layout (no colors!) → sync-bar → control-panel →
import-bundle → drive-integration → print-info → upbar-legacy →
editor-toolbar → legacy-modals → sidebar-core → db-sidepage →
sidebar-widgets → dark-mode → area-docente → exercise-overrides →
verifica-doc → topbar-modern.

**Hex-token exact-match già identificati** (safe swaps zero-drift):

| Hex | Token | Occorrenze modules |
|---|---|---|
| `#0b5fd1` | `--fm-c-primary` | 8 |
| `#0947a6` | `--fm-c-primary-d` | 1 |
| `#d97706` | `--fm-c-warning` | 15 |
| `#2563eb` | `--fm-c-focus-ring` | 17 |
| `#6b21a8` | `--fm-c-link-visited` | 2 |
| `#1f2937` | `--fm-c-text` | 2 |
| `#6b7280` | `--fm-c-muted` | 1 |

Totale 46 swap safe (byte-equivalent al computed style runtime).

**Effort stimato**: 1-2 settimane (15-30 min/modulo) con Playwright
diff dopo ogni modulo.

**Trigger**: quando design review autorizza la migrazione token.

## Decision matrix

| TODO | Effort | Risk | Value | Priorità |
|---|---|---|---|---|
| #1 editor.css full | 5-8gg | Alta (editor regression) | Media (a11y editor) | Q3 2026 sprint dedicato |
| #2 sidebar BEM full | 2-3gg | Media | Bassa (cosmetic) | Step 1-2 ✅ (b8f10ab); restano step 3-4 quando si tocca sidebar |
| #3 toolbar !important | (sub di #1) | Alta | Bassa (technical debt) | Con #1 |
| #4 sec color tokens | 1h | Nulla | Media | ✅ Fatto |
| #5 token migration moduli | 1-2 sett | Bassa-Media | Alta (uniformità design system) | Phase 6 dedicata |

## Note finali

Il design system **è già completo per nuovi componenti**: tutti
i 37 moduli sono organizzati BEM, WCAG-compliant, `:focus-visible`
su tutti gli interactive, `prefers-reduced-motion` safe.

I 17 moduli da Sprint 26-43 sono `var(--fm-*)` parziale: i layout
sono già token-based, ma le palette colori sono ancora hex hardcoded
per garantire zero visual drift durante extraction. Token migration
(TODO #5) è la fase successiva.

I TODO #1-#3 residue sono **legacy migration**, non blocker. Il codice
funziona, accessibility WCAG 2.1 AA dopo Fase C.1-C.4 è ~88%.

**Strategia consigliata**: focus su Fase D.2 (SPID/CIE) + Fase D.3
(audit esterno) che hanno maggiore valore per il catalogo
Developers Italia. I CSS TODO restano gestibili in PR mirate
quando si tocca un'area per altri motivi. Token migration TODO #5
è opportunità side-by-side a qualsiasi feature work che tocchi
moduli singoli.
## Phase Roadmap 5 — closing 2026-05-23
