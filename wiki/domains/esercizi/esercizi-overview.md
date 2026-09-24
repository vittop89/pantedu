---
tags:
  - documentazione/architettura
  - dominio/esercizi
date: 2026-09-04
tipo: architettura
status: finale
aliases: ["esercizi", "contenuti", "studio", "contract"]
cssclasses: []
---

# Dominio: esercizi e contenuti

> [!abstract] Scopo
> Contenuti del docente (esercizi, laboratori, documenti PT, e i metadati di mappe e verifiche) come contract JSON cifrato in `teacher_content`; pagine di studio per studenti e ospiti; editor inline; pubblicazione e visibilità; TikZ, GeoGebra, scorciatoie LaTeX.

## Confini del dominio

- **In**: docente autenticato, studente o ospite con credenziale di classe, contract JSON, selettori indirizzo/classe/materia
- **Out**: HTML delle pagine di studio, JSON per la sidebar, TeX/PDF via [[tex-pipeline]]

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| ContentStudyController | `app/Controllers/ContentStudyController.php` | pagine e JSON di studio (`/studio/{type}/...`, `/api/study/*`), vista pubblica (`/public/studio/{id}`, `/api/public/study/*`), verifiche correlate; 1.852 righe, cinque renderer HTML dentro ([[technical-debt]] voce 18) |
| ExerciseStudyController, ExerciseController | `app/Controllers/ExerciseStudyController.php`, `ExerciseController.php` | rotte legacy `/studio/{ind}/{cls}/{materia}` e ricerca sulla tabella `exercises` (sola lettura) |
| TeacherContentController | `app/Controllers/TeacherContentController.php` | CRUD `/api/teacher/content*`: `index`, `store`, `show`, `update`, `destroy`, `recategorize`, `capabilities`, `myClasses` |
| QuesitoController, GroupController | `app/Controllers/QuesitoController.php`, `GroupController.php` | patch/delete/move/duplicate/clone di un quesito; add/patch/move/delete di un gruppo (ADR-029) |
| ContentPublishController, ContentExportController, ContentTemplateController | `app/Controllers/` | publish/unpublish/share-pool; export ZIP, tex-files, compile-pdf, export-html, provenance, contract, manifest; template per tipo |
| StudyHeaderController, StudySourcesController | `app/Controllers/` | header di pagina del docente; registro fonti, origini, filtri |
| TeacherUncategorizedController, TeacherCategoryLabelController | `app/Controllers/` | pagina «da categorizzare» (contenuti senza indirizzo); etichette categoria per docente |
| PoolController, ShareGrantsController | `app/Controllers/PoolController.php`, `ShareGrantsController.php` | pool dell'istituto, recupero di contenuti dei colleghi, grant granulari e gruppi |
| ContractAggregate, ContractRepository, ContractSchemaValidator, ContentVersionRepository | `app/Services/Contract/` | aggregate root, persistenza con optimistic locking (`_version`), validazione contro `schemas/pantedu.content.v1.json` (al caricamento la registra; `save()` rifiuta, con `ContractSchemaException` e 422 `contract_schema` da `group/*` e da `quesito/*`, gli errori che il contratto di prima non aveva, dal 23/9/2026), snapshot di versione |
| ContractRenderer, Rendering/RmColumnTypes | `app/Services/ContractRenderer.php` | contract → HTML (blocchi text/latex/tikz/geogebra/list, tabelle RM, pulsanti F/GF) |
| TeacherContentRepository | `app/Repositories/TeacherContentRepository.php` | ricerca con scoping istituto/sezione, cifratura dei body, doppia scrittura legacy, `daCategorizzare()`; `create()` scrive contenuto, posto principale e audit in una transazione (sua, se chi chiama non ne ha una; dal 23/9/2026) |
| ContentVisibilityPolicy, ViewerContext, ContentVisibility | `app/Domain/` | chi vede cosa: bozze, pubblicato, pool, sezioni nascoste, ospite |
| SharedContentPolicy, AclPolicy, TeacherCapabilityPolicy | `app/Services/Sharing/SharedContentPolicy.php`, `AclPolicy.php`, `TeacherCapabilityPolicy.php` | grant e capability |
| TemplateDefaults, TemplateResolver (risdoc) | `app/Services/Risdoc/TemplateDefaults.php` | contenuti di default per tipo |
| TikzController, TikzRenderController, TikzDataController, TeacherWorkspaceController, TeacherTemplateController | `app/Controllers/` | elementi TikZ (CRUD admin), render server-side (ADR-013), workspace personale del docente |
| TikzService, TikzElementsService, Tikz/*, TexCompile/TikzRenderClient | `app/Services/` | storage elementi, indici, render con cache |
| LatexShortcutsController, GeoGebraCatalogController | `app/Controllers/` | scorciatoie LaTeX forkabili; catalogo GeoGebra personale |
| PhpContentParser | `app/Services/` | parser dei contenuti PHP legacy (usato da tool e dal Sanitizer) |
| SidebarSectionRepository, SidebarConfigController, PublicSidebarController | `app/Repositories/SidebarSectionRepository.php`, `app/Controllers/` | sezioni della sidebar data-driven (ADR-027), render pubblico |

## JS modules

| Modulo | File | Funzione |
|--------|------|---------|
| editor-system e livelli (ADR-016) | `js/modules/editor/*` | editor inline dei collex-item: vedi [[domains/esercizi/editor-architecture]] |
| checkin-handlers | `js/modules/features/checkin-handlers.js` | selezione e azioni sugli esercizi (5.554 righe, 335 funzioni: [[technical-debt]] voce 18) |
| ui-comp, dom-manager, list-manager | `js/modules/ui/` | componenti UI legacy |
| db-sidepage, risdoc-sidepage, sidepage-registry, section-edit-mode | `js/modules/features/` | pannelli laterali: caricano `/api/study/content.json` e `/api/risdoc/*` |
| rm-table-view | `js/modules/render/rm-table-view.js` | anteprima tabelle RM: [[domains/esercizi/rm-table-rendering]] |
| tikz-render-client | `js/modules/editor/tikz-render-client.js` | render lazy dei TikZ via VPS |
| exercise-wizard, import-bundle-flow, share-grants-popup, category-manager | `js/modules/features/` | creazione, import bundle, condivisione, categorie |
| print-info, print-client, verifiche-print-ui | `js/modules/print/` | stampa (il vecchio `print-export.js` non esiste più: la pipeline è server-side) |

## Classi HTML protette (non rinominare)

| Classe | Scopo |
|--------|-------|
| `collex-item` | contenitore singolo esercizio |
| `collex` | raccolta esercizi |
| `problem` | gruppo problemi |
| `testo` | testo esercizio |
| `collexTab` | tab raccolta |
| `titolo_quesito` | titolo quesito |
| `sol` | soluzione |
| `giustsol` | giustificazione della soluzione |
| `dsa-checkbox-container`, `dsa-checkbox`, `AddTextDSA` | adattamenti DSA |
| `tex-group`, `element-tex`, `label_tikz`, `label_latex`, `group-options`, `group-btn` | elementi TikZ |
| `fm-dsa-li-list`, `fm-text`, `fm-latex`, `fm-badge`, `fm-geogebra-wrap` | markup emesso da `ContractRenderer`, letto da `dom-block-extractor.js` |

ID protetti: `#infoVer`, `#header_page`, `#verTitle` (usati dalla generazione LaTeX).

## API pubblica

- `GET /api/study/topics.json`, `/api/study/content.json`, `/api/study/content/{id}.json` (student+)
- `GET /api/public/study/topics.json`, `/api/public/study/content.json`, `/public/studio/{id}` (ospite, solo pubblicato dal super-admin)
- `GET|POST /api/teacher/content[/{id}[/update|delete|recategorize|publish|unpublish|share-pool|export|tex-files|compile-pdf]]`
- `POST /api/teacher/content/{id}/quesito/{itemRef}/{patch|delete|move|duplicate|clone-to-eser}`
- `POST /api/teacher/content/{id}/group/{add|{groupRef}/patch|move|delete}`
- `GET /api/teacher/pool/materials`, `POST /api/teacher/pool/recover/{id}`, `/api/teacher/share/*`
- `GET|POST /tikz/render`, `/tikz/workspace/*`, `/api/latex-shortcuts/*`, `/geogebra/catalog/*`

## Link correlati

[[architecture]] · [[routing-and-api]] · [[technical-debt]] · [[tex-pipeline]] · [[domains/verifiche/verifiche-overview]] · [[decisions/ADR-016-editor-modular-architecture]] · [[decisions/ADR-027-dynamic-sidebar-config]] · [[decisions/ADR-029-decomposizione-god-controller]]
