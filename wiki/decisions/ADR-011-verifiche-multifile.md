---
tags:
  - documentazione/decisione
date: 2026-05-04
tipo: architectural-decision
status: accettato
phase: G20.0
---

# ADR-011 — Verifiche multi-file con override per istituto

## Status

✅ **Accepted** — implementato in Phase G20.0 (2026-05-04).

## Contesto

Prima di G20.0, la generazione delle verifiche produceva un singolo `.tex`
monolitico per ciascuna variant (NOR/SOL/DSA/DIS), salvato come blob
crittografato in `verifica_documents.tex_blob_path`. Tutto il preambolo,
intestazione, BES/DSA, griglia di valutazione, problemi erano inline nel
file. Modifiche a una qualsiasi sezione richiedevano regenerazione di
tutte le verifiche.

Limitazioni:
- **No reuso**: ogni verifica replica preambolo + griglia + intestazione
  → modifica griglia richiede edit N file
- **Rigid template**: l'admin non puo' editare facilmente i pacchetti
  LaTeX o gli include
- **No multi-tenant**: stesso preambolo system-wide, no override per
  istituto (necessario per logo/firma docente/criteri valutazione
  delibera collegio docenti)
- **VSC ZIP confusion**: scaricare uno ZIP del .tex con allegati per
  Overleaf richiedeva dataset misto in 1 archive

## Decisione

Adottare un'architettura **multi-file modulare** con cascade override
per istituto:

```
storage/templates/verifiche/
├── _default/                       ← system fallback
│   ├── texCommon/
│   │   ├── verifica.sty            (preambolo+pacchetti+comandi custom)
│   │   ├── intestazione.tex
│   │   ├── ulteriori_misure.tex
│   │   └── BES_DSA/
│   │       ├── misure_dispensative.tex
│   │       └── compensazione_orale.tex
│   ├── versioni/
│   │   └── main_{NOR,SOL,DSA,DIS}.tex
│   └── griglie/
│       └── {indirizzo}_{materia}.tex
└── {institute_code}/               ← override per istituto (cascade)
    └── ... (solo i file diversi dal default)
```

Il `TexBuilder` produce un `BuildResult` multi-file invece di stringa
monolitica. Due modalità:
- **ZIP**: layout flat (`\input{../texCommon/...}`)
- **VSC/Drive**: distribuito (`\input{../../../../../../texCommon/...}`)

I main_*.tex usano placeholder (`{{TEXCOMMON_DIR}}`, `{{INDIRIZZO_CODE}}`,
`{{TITOLO_VERIFICA}}`, ecc.) sostituiti runtime dal `PlaceholderResolver`.

## Consequenze

### Positive

- **DRY**: 1 modifica al `verifica.sty` → tutte le verifiche
  ricompilano coerentemente
- **Multi-istituto**: ogni istituto puo' override `griglie/`,
  `intestazione.tex`, ecc. con cascade su `_default`
- **Editabile online**: file-tree editor in `/admin/templates#verifiche`
  con scope switcher
- **Overleaf-friendly**: ZIP estratto = progetto LaTeX completo,
  studente apre `versioni/main_NOR.tex` in Overleaf
- **Mirror filesystem ↔ Drive**: identico layout su locale e Drive
- **Backwards compatible**: `selection_json` snapshot in
  `verifica_documents` permette rigenerazione on-the-fly del bundle
  da qualsiasi verifica salvata

### Negative

- **Storage duplicato**: ogni verifica salvata duplica un blob
  monolitico (legacy) + i file template servono da _default. Phase G21+
  potra' migrare a storage multi-blob.
- **Path resolution complesso**: i `\input` con `../../../...` richiedono
  attenzione se la profondita' della cartella cambia. Mitigato da test
  E2E `g20_03_vsc_layout` che compila pdflatex su filesystem reale.
- **Override discovery**: l'admin deve sapere quale file editare;
  mitigato da file-tree UI con badge 🔵 (override) / ⚪ (default).

## Alternative valutate

### A) `\graphicspath` + `\input@path` configurabili

**Scartato**: meno trasparente per chi apre il file in Overleaf.
I path relativi espliciti `\input{../texCommon/...}` sono autoesplicativi.

### B) Symlink/copia di `texCommon` in ogni version folder

**Scartato**: triplica lo storage e Drive sync deve mirror i symlink
(non supportato direttamente). 

### C) Tutto in DB con TexBuilder che decompone al volo

**Scartato**: storage opaco, no edit Overleaf. La direzione e' verso
file-system come single source of truth.

## Implementazione

Vedi G20 (completato 2026-05-04, piano in git history) per il piano dettagliato delle
12 phase.

Componenti chiave:
- `App\Services\Verifica\TemplateFileStore` — cascade lookup + allowlist
- `App\Services\TexBuilder\BuildResult` — DTO multi-file
- `App\Services\TexBuilder\PlaceholderResolver` — sostituzione `{{KEY}}`
- `App\Services\TexBuilder\ProblemiBodyRenderer` — corpo standalone
- `App\Controllers\Admin\VerificaFilesAdminController` — CRUD admin
- `views/partials/sidebar.php` — selettore istituto attivo
- `views/area_docente/profilo.php` — CRUD link teacher↔institute

## Riferimenti

- Issue/discussione: piano in G20 (completato 2026-05-04, piano in git history)
- Migrazioni: `database/migrations/028_verifica_selection_json.sql`,
  `029_institutes_identity.sql`
- Test: `tests/e2e/g20_*.spec.js` (5 test, tutti passing)
