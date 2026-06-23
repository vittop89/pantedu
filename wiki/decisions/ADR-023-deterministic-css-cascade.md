---
tags:
  - documentazione/adr
  - frontend
  - css
  - performance
  - a11y
date: 2026-05-25
tipo: ADR
status: accettato
aliases: ["css-in-js-elimination", "deterministic-cascade", "css-runtime-injection", "dark-mode-modernization", "css-legacy-phase6"]
---

# ADR-023 — Deterministic CSS cascade: elimina CSS-in-JS runtime + modernizza moduli legacy residui

> [!info] Decision: **tutto il CSS vive nel bundle `@layer`**. Vietata l'iniezione di `<style>` a runtime da JS (causa cascade non-deterministica, dipendente dal timing di esecuzione moduli → flip visivi cache-dipendenti). I 5 moduli `_*-legacy.css` residui e `_dark-mode.css` (936 LOC) vengono modernizzati (BEM + token + `@layer`) o splittati. Estende [[ADR-018-css-modernization-strategy]] e [[ADR-019-css-hot-classes-full-bem-migration]].

## Context

Post ADR-018/019 il monolite legacy è decomposto in moduli BEM sotto `@layer`, ma restano due classi di problema che producono **rendering non deterministico**:

### 1. CSS-in-JS runtime (11 file)

> **Stato 2026-06-05 — Fase 5 completata.** Zero CSS-in-JS runtime nel codebase: CI guard `npm run css:no-injection` (`tools/ci/no-css-in-js.mjs`) **verde**. L'ultimo straggler oltre alla lista sotto — `bootstrap-compat.injectSyncStyles()` — è stato spostato in `css/modules/_sync-status.css` (import in `components.css`). La regola "vietato CSS-in-JS runtime" è ora applicata e bloccante in CI.

11 moduli JS iniettano un `<style>` nel `<head>` a runtime via `document.createElement('style')`:

```
js/modules/ui/ver-generation-overlay.js     js/entries/tikz-template-filler.js
js/modules/print/verifiche-print-ui.js       js/entries/tikz-editor-modal.js
js/modules/integrations/google-apps.js        js/entries/tikz-blocks-manager.js
js/modules/features/risdoc-text-editor.js     js/entries/tex-element-editor.js
js/modules/events/event-handler.js            js/entries/geogebra-editor.js
js/modules/core/utilities.js
```

Lo `<style>` iniettato è **unlayered** e la sua posizione nel DOM dipende da *quando* il modulo esegue. Con cache calda i moduli eseguono subito (style iniettato presto), a freddo tardi → **l'ordine in cascade cambia tra un load e l'altro**. Diagnosi incidente 2026-05-25: pagina `/studio/esercizio/*` mostrava stili diversi tra hard-reload e reload normale; l'unica differenza catturata via CDP era la posizione dei `<style>` iniettati relativa a `main.bundle.css`. (Nota: nello specifico caso il "flip" era anche amplificato dal tema dark/light via `localStorage.fm_dark_mode` — ortogonale, ma il pattern CSS-in-JS resta la fragilità sistemica.)

### 2. Moduli legacy residui

| Modulo | LOC | Stato |
|---|---|---|
| `_dark-mode.css` | 936 | 364 regole `body.fm-dark` patch-on-patch, hex hardcoded, no token |
| `_exercise-legacy.css` | ~3900 | estratto VERBATIM, non ancora BEM/token-izzato |
| `_admin-legacy.css` | — | VERBATIM |
| `_upbar-legacy.css` | ~314 | VERBATIM |
| `_legacy-modals.css` | ~335 | VERBATIM |
| `_shell-legacy.css` | — | VERBATIM |

Questi moduli sono `@layer`'d (cascade ok) ma non modernizzati: hex hardcoded invece di `var(--fm-c-*)`, naming non-BEM, dark mode come override separato invece di token semantici.

Problema comune: il CSS-in-JS unlayered **batte** sempre il bundle `@layer`'d quando matcha (le regole unlayered hanno priorità maggiore di qualsiasi layer), e l'ordine d'iniezione è timing-dipendente → bug di stile intermittenti, difficili da riprodurre.

## Decision

1. **Vietato CSS-in-JS runtime.** Nessun modulo JS deve iniettare `<style>` o regole CSS a runtime. Tutto il CSS statico va in un modulo `css/modules/_*.css` importato nel bundle sotto `@layer`. Stato dinamico (es. altezze calcolate) va via CSS custom properties (`element.style.setProperty('--x', …)`), non via regole CSS iniettate.

2. **Cascade deterministica via `@layer`.** L'ordine dei layer (dichiarato in `main.css`) è l'unica fonte di verità della precedenza. Aggiungere un layer `legacy` come **più basso** per i moduli `_*-legacy.css` durante la transizione, così il moderno vince sempre indipendentemente dal timing.

3. **Modernizzazione moduli legacy.** I 5 moduli `_*-legacy.css` + `_dark-mode.css` vengono riscritti/splittati: naming BEM `fm-`, colori via `var(--fm-c-*)` token semantici, dark mode tramite token (un set di variabili `[data-theme="dark"]`) invece di 364 override `body.fm-dark`.

4. **Gate visual-regression.** Ogni fase passa solo se `npm run e2e:visual` (Playwright snapshot) non mostra drift non intenzionale.

5. **Lint enforcement.** Regola ESLint custom o grep in CI che fallisce su `createElement('style')` / `<style>` in JS (esclusi i moduli già migrati con deroga esplicita).

## Piano a fasi

| Fase | Scope | Rete di sicurezza |
|---|---|---|
| **0** | Layer `legacy` lowest + snapshot baseline visual (`e2e:visual:update`) | baseline |
| **1** | **Dark mode**: `_dark-mode.css` 936 LOC → token semantici `[data-theme=dark]`; audit WCAG 2.2 AA contrasti; fix flip light/dark (default + transizione) | visual + axe (`e2e:a11y`) |
| **2** | Migra i 11 `<style>` runtime → moduli `_*.css` `@layer`; rimuovi `createElement('style')` | visual per pagina toccata |
| **3** | `_exercise-legacy.css` → BEM + token, split per sotto-area | visual `/esercizio/*` |
| **4** | `_admin-legacy.css`, `_upbar-legacy.css`, `_legacy-modals.css`, `_shell-legacy.css` → moderni | visual admin/shell |
| **5** | Rimuovi layer `legacy` (vuoto) + lint rule attiva in CI | full visual + lighthouse |

## Alternatives considered

- **Lasciare il CSS-in-JS ma wrapparlo in `@layer`**: riduce il flip ma mantiene CSS sparso in JS, non grep-abile, contro l'obiettivo di single-source-of-truth. Scartato.
- **CSS Modules / scoped styles per componente**: richiederebbe build pipeline per-componente e riscrittura massiva; sproporzionato per un'app server-rendered con bundle unico. Scartato.
- **Non fare nulla**: i flip intermittenti restano, dark mode resta patch-on-patch. Scartato.

## Consequences

### Positive
- Cascade deterministica: zero flip cache/timing-dipendenti.
- Single source of truth CSS (bundle), grep-abile, manutenibile.
- Dark mode via token: meno LOC, contrasto WCAG garantito, nessun override sparso.
- Perf: meno lavoro main-thread (no iniezione style runtime), un solo file CSS.

### Negative / Trade-off
- Refactor ampio (6 fasi): rischio drift visivo → mitigato dal gate visual-regression per fase.
- Stato dinamico deve passare per custom properties, non regole: piccolo cambio di pattern per gli autori.

## References
- [[ADR-018-css-modernization-strategy]] — decomposizione monolite → moduli `@layer`
- [[ADR-019-css-hot-classes-full-bem-migration]] — migrazione hot classes a BEM
- Incident 2026-05-25: flip stile `/studio/esercizio/*` (diagnosi CDP: posizione `<style>` iniettati + tema dark/light)
- `docs/plans/css-refactor-residue-todo.md` — residuo roadmap
