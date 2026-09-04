---
tags:
  - documentazione/modulo
  - dominio/risdoc
date: 2026-04-23
tipo: modulo
status: finale
aliases: ["tex-pipeline", "pipeline TeX", "pdflatex"]
cssclasses: []
---

# tex-pipeline — Risdoc

> [!abstract] Responsabilità
> Trasforma un form_state (JSON compilato dal docente) in un file .tex valido, lo assembla in uno ZIP con main.tex/risdoc.sty/immagini, e lo serve al client per compilazione locale o Overleaf.

## Componenti chiave

| File | Riga | Ruolo | Dipendenze |
|------|------|-------|------------|
| `ExportController.php` | 1 | Orchestratore: resolve template, processa TeX, assembla ZIP | TemplateResolver, OverrideRepository, TexBuilder |
| `ExportController::processLegacyTex()` | ~180 | Sostituisce marker .tex legacy con valori form_state | Regex PHP |
| `ExportController::applyTextOverrides()` | ~100 | Applica override testuali docente (da `text-overrides.json`) | OverrideRepository |
| `app/Services/Risdoc/TexBuilder.php` | 1 | Fallback schema-driven: genera TeX body da JSON schema | Nessuna esterna |
| `storage/templates/risdoc/texCommon/main.tex` | - | Template wrapper LaTeX: `\documentclass`, `\input{doc.tex}` | pdflatex |
| `storage/templates/risdoc/texCommon/risdoc.sty` | - | Stile LaTeX: font, layout, comandi custom (`\simplefield`, `sectionbox`) | pdflatex |
| `storage/templates/risdoc/texCommon/intestaLAteX_IIS.tex` | - | Intestazione IIS: logo, dati istituto | pdflatex |
| `storage/templates/risdoc/{CAT}/tex/*.tex` | - | File .tex legacy per ogni template (centinaia di righe) | main.tex via `\input` |

## Flusso principale

```mermaid
flowchart TD
    A[POST /api/risdoc/templates/{id}/export] --> B[ExportController::export]
    B --> C[TemplateResolver::findTemplate id]
    C --> D[Permission::canView]
    D --> E[parseFormState form_state JSON]
    E --> F[TemplateResolver::resolveFile tex]
    F --> G{texBody >= 100 chars?}
    G -- Si --> H[processLegacyTex texBody formState]
    G -- No --> I[TexBuilder::build schema-driven fallback]
    H --> J[applyTextOverrides OverrideRepository]
    I --> J
    J --> K[Load main.tex + risdoc.sty + intestaLAteX_IIS.tex]
    K --> L[str_replace %filetex → input{doc.tex}]
    L --> M[Load images from storage/templates/risdoc/images/]
    M --> N[ZipArchive: main.tex + doc.tex + sty + head + images]
    N --> O[storage/risdoc-tmp/doc-{16hex}.zip]
    O --> P{mode?}
    P -- zip --> Q[Response JSON url download]
    P -- overleaf --> R[Response JSON overleaf_url snip_uri]
```

## Marker TeX legacy — processLegacyTex()

| Marker | Trasformazione |
|--------|---------------|
| `[field-nome]` | Sostituito con `escapeTex(state[nome] \|\| fields[nome])` |
| `\simplefield{Label}[field-nome]` | Sostituito con `\simplefield{Label}{VALUE}` |
| `[field]` | Valore posizionale dalla lista `collectLabeledRowValues(fields)` |
| `%[BeginList-hide]...%[EndList-hide]` | Blocco rimosso interamente |
| `%[BeginList-show]...%[EndList-show]` | Solo marker rimossi, contenuto resta |
| `%[BeginTesto]...%[EndTesto]` | Solo marker rimossi |
| `%[BeginOpzione]...%[EndOpzione]` | Solo marker rimossi |
| `%[BeginTextArea]...%[EndTextArea]` | Solo marker rimossi |
| `%[selection]` | Rimosso (TODO: gestione selezioni JSON) |
| `\documentclass`, `\begin{document}` | Rimossi (main.tex li fornisce) |
| `\usepackage{babel, inputenc, ...}` | Rimossi (già in risdoc.sty) |

## TexBuilder (schema-driven fallback)

Usato quando il .tex legacy è assente o < 100 caratteri. Genera un body TeX minimale con `\section*{titolo}` e `\begin{itemize}` per ogni campo del form. Non produce layout formale — solo per debug/PoC.

`TexBuilder::esc()` esegue escape LaTeX base: `&`, `%`, `$`, `#`, `_`, `{`, `}`, `~`, `^`, `\`.

## Input / Output

**Input**: `form_state` JSON con struttura:
```json
{
  "state": { "classe": "5s", "indirizzo": "sc", "disciplina": "MAT", "professore": "..." },
  "fields": { "profilo_classe": "Testo...", "studenti_table": [ { "__label": "TOTALE", "value": "25" } ] }
}
```

**Output ZIP**: `main.tex` + `{argomento}.tex` + `texCommon/risdoc.sty` + `texCommon/intestaLAteX_IIS.tex` + `images/*.png`

**Output JSON (mode=zip)**:
```json
{ "ok": true, "mode": "zip", "url": "https://pantedu.eu/api/risdoc/exports/doc-abc123.zip", "expires": 1714001234 }
```

## Side effects e dipendenze esterne

- Scrive `storage/risdoc-tmp/doc-{hex}.zip` (TTL 1h, cleanup automatico in `cleanupOld()`).
- Legge `storage/templates/risdoc/texCommon/` (immutabile in prod).
- Legge `storage/templates/risdoc/{CAT}/tex/*.tex` via `TemplateResolver::resolveFile()`.
- Legge `CurriculumService` per tradurre codici curriculum in label leggibili.

## Zone protette

> [!warning] Non modificare
> `storage/templates/risdoc/texCommon/risdoc.sty`, `main.tex`, `intestaLAteX_IIS.tex` senza test di compilazione pdflatex. Sono la base di tutti i PDF prodotti.

## Test collegati

- `tests/Unit/TexBuilderTest.php` — unit test TexBuilder
- `tests/e2e/risdoc_tex_production.spec.js` — verifica 7 template producono PDF
- `tests/e2e-results/tex-production/` — output compilati (reference)

Copertura: media (unit) / alta (E2E per i template principali).

## Debito tecnico

- `processLegacyTex()`: ~350 LOC in metodo privato. Candidato a estrazione in classe `LegacyTexProcessor`. Vedi [[technical-debt]] #1.
- `%[selection]` marker non gestito (TODO nel codice).
- `sectionMap` hardcoded (profilo_classe, educazione_civica, programma_svolto) — non estensibile senza codice.

## Link correlati

[[domains/risdoc/risdoc-overview]] · [[decisions/ADR-003-tex-pipeline]] · [[decisions/ADR-005-schema-driven-risdoc]] · [[user-flows#Flusso 3]]
