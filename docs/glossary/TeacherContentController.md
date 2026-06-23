# Glossario — `TeacherContentController` (1886 LOC)

`app/Controllers/TeacherContentController.php` — God-object lato **docente** (write/manage dei contenuti). 30 metodi pubblici. Questa mappa serve per trovare "quale metodo gestisce X" senza leggere tutto il file. Endpoint: `docs/ROUTES.md` (gruppo `/api/teacher`). Decomposizione proposta: **ADR-029**.

> Repository condiviso: `App\Repositories\TeacherContentRepository`. Lo `ContentStudyController` è il gemello read-side (vedi `docs/glossary/ContentStudyController.md`).

## Metodi per area (seam proposto in ADR-029)

### CRUD core → `TeacherContentController` (snello)
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `index` | 36 | Lista contenuti del docente |
| `capabilities` | 85 | Capabilities docente per la UI (ADR-028) |
| `store` | 95 | Crea contenuto |
| `show` | 186 | Dettaglio singolo contenuto |
| `update` | 211 | Aggiorna contenuto |
| `destroy` | 256 | Elimina contenuto |
| `recategorize` | 280 | Sposta di partizione/category |
| `myClasses` | 1875 | Classi del docente (target/scope) |

### Publish & sharing → `ContentPublishController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `publish` | 349 | Pubblica (publish_scope) |
| `unpublish` | 354 | Ritira dalla pubblicazione |
| `sharePool` | 367 | Condivisione su pool |

### Export / compile (TeX·PDF·HTML) → `ContentExportController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `export` | 415 | Export contenuto |
| `texFiles` | 583 | Elenco/lettura file `.tex` |
| `compilePdf` | 632 | Compila PDF (servizio TeX) |
| `saveTexFiles` | 735 | Salva file `.tex` |
| `exportHtml` | 772 | Export HTML |
| `provenance` | 842 | Provenance/tracciabilità del build |
| `contract` | 1698 | Contract JSON del contenuto |
| `manifest` | 1752 | Manifest del contenuto |

### Editing quesiti → `QuesitoController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `quesitoPatch` | 918 | Modifica quesito |
| `quesitoDelete` | 922 | Elimina quesito |
| `quesitoMove` | 926 | Sposta quesito |
| `quesitoDuplicate` | 932 | Duplica quesito |
| `quesitoCloneToEser` | 1522 | Clona quesito → esercizio |

### Editing gruppi → `GroupController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `groupMove` | 948 | Sposta gruppo |
| `groupAdd` | 993 | Aggiunge gruppo |
| `groupDelete` | 1414 | Elimina gruppo |
| `groupPatch` | 1453 | Modifica gruppo |

### Template & default → `ContentTemplateController` / service
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `templatesJson` | 1270 | Lista template |
| `templatesSave` | 1328 | Salva template |
| `defaultIntroForType` | 1090 | Intro di default per tipo (helper, non-route) |
| `defaultTitleForType` | 1113 | Titolo di default per tipo (helper, non-route) |

Line# riferiti allo stato 2026-06-11; rigenerare con `grep -nE 'public function ' app/Controllers/TeacherContentController.php`.
