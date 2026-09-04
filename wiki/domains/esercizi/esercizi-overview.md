---
tags:
  - documentazione/architettura
  - dominio/esercizi
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["esercizi"]
cssclasses: []
---

# Dominio: esercizi

> [!abstract] Scopo
> Gestione esercizi e problemi LaTeX: CRUD, editor inline, TikZ elements, print export, contract-based storage, BES/DSA mode.

## Confini del dominio

- **In**: docente autenticato, testo esercizi, TikZ SVG, selezione checkbox
- **Out**: LaTeX esercizi, PDF (via admin print), HTML esercizi per studenti

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| ExerciseController | `app/Controllers/ExerciseController.php` | `searchPage`, `searchJson` — ricerca DB-backed esercizi |
| ExerciseStudyController | `app/Controllers/ExerciseStudyController.php` | Accesso studente a contenuti studio |
| FileController | `app/Controllers/FileController.php` | `saveTex`, `saveLatex`, `saveImage`, `savePdf`, `deleteFile`, `deleteFolder`, `list` |
| TikzController | `app/Controllers/TikzController.php` | CRUD elementi TikZ SVG, `generateJson`, `ensureJson`, `content` |
| TeacherContentController | `app/Controllers/TeacherContentController.php` | CRUD contract, quesito CRUD, group CRUD, sidebar, manifest, provenance |
| VerificaBuilderController | `app/Controllers/VerificaBuilderController.php` | `listMine`, `show`, `build`, `delete` verifiche da selezione esercizi |
| ExerciseRepository | `app/Repositories/ExerciseRepository.php` | Accesso tabella `exercises` |
| TeacherContentRepository | `app/Repositories/TeacherContentRepository.php` | Accesso `teacher_content` con dual-write |
| ContractAggregate | `app/Services/Contract/ContractAggregate.php` | Aggregate root: CRUD items/gruppi in contract JSON |
| ContractRepository | `app/Services/Contract/ContractRepository.php` | Persistenza contract con optimistic locking (`_version`) |
| ContractSchemaValidator | `app/Services/Contract/ContractSchemaValidator.php` | Valida contract contro `schemas/pantedu.content.v1.json` |
| TexBuilder (esercizi) | `app/Services/TexBuilder.php`, `app/Services/TexBuilder/` | Generazione LaTeX esercizi: Sanitizer, Selection, TableRenderer, VersionPicker |
| TikzService | `app/Services/TikzService.php` | Logica CRUD TikZ SVG |
| TikzElementsService | `app/Services/TikzElementsService.php` | Gestione elementi TikZ riutilizzabili |
| ExerciseAccessPolicy | `app/Policies/ExerciseAccessPolicy.php` | Policy accesso esercizi |
| DsaService | `app/Services/DsaService.php` | Adattamenti BES/DSA per esercizi/verifiche |

## JS modules (editor)

| Modulo | File | Funzione |
|--------|------|---------|
| editor-system | `js/modules/editor/editor-system.js` | Editor inline WYSIWYG per collex-item |
| content-processor | `js/modules/editor/content-processor.js` | MathJax rendering, DOM post-processing |
| table-manager | `js/modules/editor/table-manager.js` | Gestione tabelle nell'editor |
| latex-render | `js/modules/editor/latex-render.js` | Render LaTeX preview |
| print-export | `js/modules/print/print-export.js` | Generazione LaTeX esercizi/verifiche lato client |
| print-info | `js/modules/print/print-info.js` | Gestione `print_info.json` per configurazioni stampa |
| checkin-handlers | `js/modules/features/checkin-handlers.js` | Checkbox selection per esercizi |
| ui-comp | `js/modules/ui/ui-comp.js` | Componenti UI: verificaETitoliQuesito, CheckSolSel |

## Classi HTML protette (non rinominare)

| Classe | Scopo |
|--------|-------|
| `collex-item` | Contenitore singolo esercizio |
| `collex` | Raccolta esercizi |
| `problem` | Gruppo problemi |
| `testo` | Testo esercizio |
| `collexTab` | Tab raccolta |
| `titolo_quesito` | Titolo quesito |
| `sol` | Soluzione |
| `giustsol` | Soluzione corretta |
| `dsa-checkbox-container` | Container checkbox DSA |
| `dsa-checkbox` | Checkbox DSA |
| `AddTextDSA` | Aggiunta testo DSA |
| `tex-group`, `element-tex`, `label_tikz`, `label_latex`, `group-options`, `group-btn` | Elementi TikZ |

## ID protetti (non rinominare)

`#infoVer`, `#header_page`, `#verTitle` — referenziati da script LaTeX generation.

## API pubblica

- `GET /exercises/search.json` — ricerca esercizi DB
- `GET /api/teacher/content` — lista content per docente
- `POST /api/teacher/content` — crea nuovo content
- `POST /api/teacher/content/{id}/quesito/{ref}/patch` — modifica item
- `POST /api/teacher/content/{id}/quesito/{ref}/delete` — elimina item
- `POST /api/teacher/content/{id}/quesito/{ref}/move` — sposta item
- `POST /api/teacher/content/{id}/quesito/{ref}/duplicate` — duplica item
- `POST /api/teacher/content/{id}/quesito/{ref}/clone-to-eser` — clone verifica→esercizio
- `POST /api/teacher/content/{id}/group/{ref}/move` — riordina gruppo
- `POST /api/teacher/content/{id}/group/add` — aggiungi gruppo
- `POST /files/save-tex` — salva file .tex

## Link correlati

[[architecture]] · [[routing-and-api]] · [[technical-debt]] · [[domains/verifiche/verifiche-overview]]
