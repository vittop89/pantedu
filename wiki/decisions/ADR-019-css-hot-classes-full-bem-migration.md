---
tags:
  - documentazione/adr
  - frontend
  - css
  - domain-classes
date: 2026-05-24
tipo: ADR
status: accettato
aliases: ["css-hot-classes-rename", "sprint-K-execution", "core-domain-bem"]
---

# ADR-019 — CSS HOT classes full BEM migration (Sprint K)

> [!info] Decision finale: le 5 classi HOT identificate post Sprint H-DEAD sono state **migrate completamente a BEM** in 4 sprint atomici. Naming semanticamente revisionato (es. `.problem` ≠ singolo problema ma "gruppo di esercizi" → `.fm-groupcollex`).

## Context

Post Sprint H-DEAD (51 classi DEAD rimosse), 5 classi rimanevano marcate HOT (>10 file refs cross-stack):

| Class | Source refs | Note |
|---|---|---|
| `.problem` | 42 | Wrap di gruppo esercizi (NOT singolo problema) |
| `.collex-item` | 32 | Item dentro `.collex` |
| `.collex` | 25 | Collezione/raccolta esercizi |
| `.rm-table` | 23 | RM widget table |
| `.DraggableContainer` | 15 | Drag-drop container editor |

Versione iniziale di questo ADR proponeva "keep-as-is" per ragioni di
costo-beneficio + visual regression. **REJECTED dal maintainer** (user
intent: `legacy completamente deprecato`, memoria `feedback_legacy_css`).

## Decision

**Full BEM migration cross-stack** in 4 sprint atomici. Granular commit per
classe, build verification post ogni sprint, zero alias backward-compat
(sostituzione completa).

### Sprint K execution

| Sprint | Class | BEM target | Files | Replacements |
|---|---|---|---|---|
| K-1 | `.DraggableContainer` | `.fm-draggable-container` | 27 | 78 |
| K-2 | `.rm-table` | `.fm-rm-table` | 21 | 76 |
| K-3a | `.collex-item` | `.fm-collection__item` | 59 | 303 |
| K-3b | `.collex` | `.fm-collection` | 40 | 158 |
| K-4 | `.problem` | `.fm-groupcollex` | 65 | 392 |
| **TOT** | 5 classi | 5 BEM | ~150 unique | **1007 replacements** |

### Semantic naming insights

- `.problem` → `.fm-groupcollex`: pantedu's `.problem` codifica
  "gruppo di esercizi" (collezione contenitore di `.fm-collection__item`),
  NOT singolo problema esercizio. Rename chiarisce intent.
- `.collex` → `.fm-collection`: nome inglese standard. BEM root.
- `.collex-item` → `.fm-collection__item`: BEM child convention `__`.
- `.rm-table` → `.fm-rm-table`: preserva semantica RM domain
  (Risposta Multipla), allinea a prefisso `.fm-*`.
- `.DraggableContainer` → `.fm-draggable-container`: camel-case →
  BEM kebab-case. `_ver` variant intentionally preserved (separate class).

### Tooling sviluppato

`scripts/css-rename-v2.py` (string-aware regex):

- Match SOLO in: CSS `.selector` declarations, quoted strings
  (`"name"`, `'name'`, `` `name` ``), `class=` / `className=` attributes
  (single + multi-class), `classList.add/remove/toggle/contains/replace` args
- NON tocca: JS variable names (`const collex = ...`), function names,
  object property names non-stringified, PHP variable names (`$problemId`),
  literal IDs (`problem-12` con `(?![\w-])` excludes hyphen continuation)

Bug fix v2: precedente `scripts/css-rename.py` aveva regex troppo larga
(`\bcollex\b`), rinominava `const collex = ...` rompendo JS syntax. v2 è
string-aware: cerca pattern espliciti per uso-classe.

### Verification gate

- Build Vite verificato dopo OGNI sprint atomico (✓ 190-207ms each)
- Granular commit con file list precise (NO `git add -A` per evitare
  inclusione storage/.env/logs)
- Riferimenti commit:
  - K-1: `2769297`
  - K-2: `291d9b8`
  - K-3: (commit hash)
  - K-4: `e286f2d`

## Alternatives considered

1. **Keep-as-is (versione precedente di questo ADR)** — REJECTED da user:
   - Inconsistenza naming codebase contraria alla memory `feedback_legacy_css`
   - Domain expressiveness recuperabile in BEM con prefisso `.fm-*`

2. **Backward-compat alias `(legacy + BEM)`** — REJECTED:
   - Raddoppia size CSS senza valore aggiunto
   - Sostituzione totale evita confusione "quale wins?"
   - Cascade @layer overrides già preserva ordine

3. **Codemod jscodeshift AST-aware** — REJECTED:
   - Overkill per rename text-based
   - Python regex string-aware sufficiente per garantire safety
   - Build Vite verifica syntax dopo ogni rename

4. **Rename solo CSS, leave source refs** — REJECTED:
   - Avrebbe rotto runtime (selettori CSS non match più HTML class attribute)
   - Half-migration peggio di no-migration

## Consequences

### Positive

- **5 classi HOT modernizzate** a BEM (`fm-*` prefix uniform)
- **Naming semanticamente accurato**: `.fm-groupcollex` esplicita "gruppo
  collezione" vs `.problem` ambiguo
- **Codebase consistency**: zero classi `.X` legacy nei top-ref counts
- **1007 source refs** aggiornati cross-stack
- **No regression breaking**: build Vite passa, struttura DOM preservata
  (solo rename)
- **Tooling riusabile**: `css-rename-v2.py` per future migrations

### Negative / Trade-off

- **Diff size large**: 4 commit con ~78 / 76 / 461 / 392 replacements ognuno.
  Mitigato da granularità atomica + commit message descrittivi
- **Visual regression non gated**: baseline non catturato (richiede E2E
  runtime). Build + smoke check come safety net. Risk residuo: regressioni
  visive sottili (es. specificità cascade change) non rilevate
- **User-generated content (template editor)**: se docenti hanno salvato
  esercizi HTML con classi `.problem`/`.collex*` legacy, contenuti non
  re-renderizzano correttamente. Mitigazione: ContractRenderer + parsing
  PHP usa già nuove classi, contenuto vecchio richiede migration script
  (deferred to Sprint K-USER-CONTENT se necessario)

### Migration future

1. **Sprint K-USER-CONTENT** (potential): scansiona `storage/objects/teachers/*`
   per esercizi con classi legacy, applica migration via PHP script
2. **Sprint L** (WARM classes 10-29 ref): applicare stesso pattern v2 a
   classi rimanenti — `.checkboxAin`, `.titolo`, `.upbar`, `.admin-access`,
   `.content`, etc.
3. **Sprint M** (COLD classes 3-9 ref): batch finale 104 classi
4. **Sprint N** (FROZEN 1-2 ref): cleanup massivo 193 classi
5. **Goal**: ZERO classi `.X` non-BEM in `_*-legacy.css` finali

## References

- Predecessore: [[ADR-018-css-modernization-strategy]]
- Memory (slug): `project_css_sprint_h_complete`, `feedback_legacy_css`
- Tooling: `scripts/css-rename-v2.py`
- KG scan output: `docs/analysis/legacy-class-usage-v2.tsv`
- Sprint K commit range: `2769297..e286f2d` (4 commit atomici)
- Files lists per sprint:
  - `docs/analysis/drag-files.txt` (K-1)
  - `docs/analysis/rmtable-files.txt` (K-2)
  - `docs/analysis/collex-files.txt` (K-3)
  - `docs/analysis/problem-files.txt` (K-4)
