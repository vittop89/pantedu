---
tags:
  - documentazione/modulo
  - dominio/risdoc
date: 2026-09-23
tipo: modulo
status: finale
aliases: ["tex-pipeline", "pipeline TeX", "pdflatex"]
cssclasses: []
---

# tex-pipeline — Risdoc

> [!abstract] Responsabilità
> Trasforma un form_state (JSON compilato dal docente) in un file .tex valido, lo assembla in uno ZIP con main.tex/risdoc.sty/immagini, e lo consegna nel corpo della risposta per la compilazione in locale.

## Componenti chiave

| File | Ruolo | Dipendenze |
|------|-------|------------|
| `app/Controllers/Risdoc/ExportController.php` | `export()`: permesso, `form_state`, pacchetto ZIP nel corpo della risposta. `buildFiles()`: il corpo `.tex` e i tre file di `texCommon` con gli override del docente, riusato dal modal multi-file | TemplateResolver, OverrideRepository, PtToTex, TexBuilder |
| `app/Services/Risdoc/Pt/PtToTex.php` | Il corpo `.tex` dal `body_pt` dell'editor, quando c'è (ADR-026: è la fonte di verità) | — |
| `app/Services/Risdoc/TexBuilder.php` | Il corpo `.tex` dallo schema JSON (`schema_path`), per i modelli senza `body_pt` | — |
| `storage/templates/risdoc/texCommon/main.tex` | Involucro LaTeX: `\documentclass`, poi `%[filetex]` sostituito con `\input{<argomento>.tex}` | pdflatex |
| `storage/templates/risdoc/texCommon/risdoc.sty` | Stile: font, layout, comandi (`\simplefield`, `sectionbox`); `%[landscape]` e i colori della barra vi si applicano | pdflatex |
| `storage/templates/risdoc/texCommon/intestaLAteX_IIS.tex` | Intestazione; si commenta se il docente spegne `includeHeader` | pdflatex |

Il 23/9/2026 è stato tolto `ExportController::processLegacyTex()`, con
`substituteFields()` e i tre helper che chiamava solo lui: sostituiva i
marker dei `.tex` legacy per categoria (`[field-nome]`, `[field]`,
`%[BeginList-hide]`…), ma dal 24/4/2026 (Phase 24.26) nessuno lo chiamava più
(revisione architetturale del 23/9/2026, A-22).

## Flusso principale

```mermaid
flowchart TD
    A[POST /api/risdoc/templates/{id}/export] --> B[ExportController::export]
    B --> C[Permission::canView]
    C --> D[TemplateResolver::findTemplate id]
    D --> E[parseFormState form_state JSON]
    E --> F[buildFiles]
    F --> G{form_state.body_pt non vuoto?}
    G -- Si --> H[PtToTex::render body_pt]
    G -- No --> I{schema_path esiste?}
    I -- Si --> J[TexBuilder::build schema]
    I -- No --> X[500 texbuilder_failed: schema_not_set]
    H --> K[main.tex + risdoc.sty + intestaLAteX_IIS.tex con override]
    J --> K
    K --> L[risdoc-istituto.tex: override, istanza o profilo]
    L --> M[ZipArchive: main.tex + doc + texCommon + images]
    M --> N[PacchettoZip::byte file temporaneo letto e cancellato]
    N --> O[Response 200 application/zip attachment]
```

## TexBuilder (schema)

Usato quando il `form_state` non porta un `body_pt`. Se lo schema dichiara
`tex.wrapper`, carica quel file e sostituisce i segnaposto `{{campo}}`;
altrimenti genera un corpo minimo con `\section*{titolo}` e un elenco dei
campi, senza layout formale.

## Input / Output

**Input**: `form_state` JSON con struttura:
```json
{
  "state": { "classe": "5s", "indirizzo": "sc", "disciplina": "MAT", "professore": "..." },
  "fields": { "profilo_classe": "Testo...", "studenti_table": [ { "__label": "TOTALE", "value": "25" } ] },
  "body_pt": [ { "_type": "block", "children": [ ... ] } ]
}
```

**Output ZIP**: `main.tex` + `{argomento}.tex` + `texCommon/risdoc.sty` + `texCommon/intestaLAteX_IIS.tex` + `texCommon/risdoc-istituto.tex` + `images/*.png`

**Output**: l'archivio nel corpo della risposta (`application/zip`,
`Content-Disposition: attachment`, `Cache-Control: private, no-store`). In caso
di errore risponde invece JSON, e il client distingue i due dal tipo del
contenuto (`js/modules/core/pacchetto.js`).

## Side effects e dipendenze esterne

- **Non scrive niente di permanente**: l'archivio nasce in un file temporaneo privato della richiesta, viene letto e cancellato subito, anche se la costruzione fallisce a metà (`App\Support\PacchettoZip`, 21/9/2026). Fino a quella data stava in `storage/risdoc-tmp/` con TTL di un'ora.
- Legge `storage/templates/risdoc/texCommon/` (immutabile in prod).
- Legge lo schema JSON del modello (`schema_path`) quando manca il `body_pt`.
- Legge gli override del docente (`OverrideRepository`, `kind` `texCommon`).

## Zone protette

> [!warning] Non modificare
> `storage/templates/risdoc/texCommon/risdoc.sty`, `main.tex`, `intestaLAteX_IIS.tex` senza test di compilazione pdflatex. Sono la base di tutti i PDF prodotti.

## Test collegati

- `tests/Unit/TexBuilderTest.php` — unit test TexBuilder
- `tests/e2e/risdoc/il-bottone-del-pacchetto.spec.js` — il bottone manda il documento
- `tests/e2e/risdoc/documento-personalizzabile-tex.spec.js` — i file TeX del docente

## Debito tecnico

- `TexBuilder::buildFallbackTex()` produce un corpo di prova, non un documento: un modello senza `body_pt` e senza `tex.wrapper` esce senza layout.

## Link correlati

[[domains/risdoc/risdoc-overview]] · [[decisions/ADR-003-tex-pipeline]] · [[decisions/ADR-005-schema-driven-risdoc]] · [[user-flows#Flusso 3]]
