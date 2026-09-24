---
tags:
  - documentazione/architettura
  - dominio/verifiche
date: 2026-09-04
tipo: architettura
status: finale
aliases: ["verifiche"]
cssclasses: []
---

# Dominio: verifiche

> [!abstract] Scopo
> Documenti verifica: dalla selezione degli esercizi nel browser al TeX multi-file, alla compilazione PDF sul microservizio, agli archivi ZIP, alla sincronizzazione su Drive e in locale. Blob TEX/PDF cifrati a busta.

## Confini del dominio

- **In**: selezione di esercizi (payload dal DOM, `Selection`), template per istituto, configurazioni di stampa
- **Out**: `.tex` (flat o multi-file), PDF, ZIP, bundle locali, file su Drive

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| VerificaController | `app/Controllers/VerificaController.php` | `saveTex`, `saveTexBatch`, `uploadPdf`, `updateTex`, `geogebraAttach`, `getTexFiles`, `updateTexFiles`, `delete`, `sharePool`, `listForTeacher`, `listForStudent`, `downloadTex`, `viewPdf`, `zipExport` |
| VerificaCompileController | `app/Controllers/VerificaCompileController.php` | `compilePdf` (con cache content-addressed per docente), `compileAsync` + `getJob` (coda), `synctexEdit` |
| VerificaBatchController, VerificaSyncController | `app/Controllers/` | ZIP e file delle 8 varianti; bundle locale, manifest firmato, `syncAll` su Drive |
| TeacherVerificaFilesController, Admin/VerificaFilesAdminController, Admin/VerificaPreambleAdminController | `app/Controllers/` | editor dei template (`texCommon`, `versioni`, `griglie`) per docente e per istituto; preamble legacy |
| VerificheController, PrintInfoController, AdminPrintController, TeacherPrintController, TexAdhocCompileController, TexFormatController | `app/Controllers/` | print_info e scelte (legacy `{success,…}`), stampa batch admin, stampa docente, compile ad hoc per l'anteprima TikZ, formattazione via VPS |
| VerificaSharedHelpersTrait | `app/Controllers/VerificaSharedHelpersTrait.php` | `teacherId()`, `corpoJson()` (il corpo da `Request::jsonObbligatorio()`, 2 MB; fino al 23/9/2026 una copia di `readJsonBody()`), `statusFor()` condivisi da 5 controller |
| VerificaDocumentService | `app/Services/Verifica/VerificaDocumentService.php` | `saveTex/saveBatch` transazionali con cleanup dei blob, `applyTemplate`, `requireOwn`, cache PDF (`tex_sha256`), `attachPdf`; 1.372 righe |
| VerificaCompileJobService, VerificaTemplateStandard, TemplateFileStore | `app/Services/Verifica/` | coda compile, template standard, cascata `_default` → istituto su filesystem |
| TexBuilder + `TexBuilder/*` | `app/Services/TexBuilder.php`, `app/Services/TexBuilder/{Selection,BuildResult,EserciziBodyRenderer,TableRenderer,Sanitizer,BadgeRenderer,BadgeStyle*,PlaceholderResolver,VersionPicker}.php` | Selection → BuildResult multi-file → `.tex` flat; HTML → LaTeX in `Sanitizer` (1.173 righe) |
| TexCompileClient, TexFormatClient, SvgToPdfClient, TikzRenderClient | `app/Services/TexCompile/` | client HMAC del microservizio: `/compile`, `/compile-bundle`, `/format-tex`, `/render-tikz` |
| Drive/VerificaSyncService | `app/Services/Drive/VerificaSyncService.php` | mirror su Drive; gli orfani (`.tex` su Drive che il database non conosce) si cancellano solo nelle cartelle `verifiche/` del docente, secondo `teacher_drive_folder_cache` (dal 23/9/2026) |
| VerificaDocumentRepository, VerificaCompileJobRepository | `app/Repositories/` | `verifica_documents` (vista su `_data`), `verifica_compile_jobs` |
| PrintInfoService, VerificheService | `app/Services/` | print_info (DB con fallback JSON), scelte |

## JS

| Modulo | File | Funzione |
|--------|------|---------|
| topbar-modern | `js/modules/features/topbar-modern.js` | SalvaTEX: `buildSelectionFromDOM` → `POST /api/verifica/save-tex(-batch)` |
| dom-block-extractor | `js/modules/core/dom-block-extractor.js` | estrazione dei blocchi dal DOM (fonte unica, condivisa con la stampa) |
| verifica-builder, verifica-scelte, verifica-sticky | `js/modules/features/` | selezione e ordine degli esercizi, scelte con auto-save, header sticky |
| verifica-documents-sidepage, verifica-detail-modal, verifica-pdf-modal, verifica-genera-modal, verifica-templates-modal, verifica-vscode-launch | `js/modules/features/` | pannello verifiche, dettaglio, PDF, generazione varianti, template, apertura in VS Code |
| verifica-preview-editor (entry) | `js/entries/verifica-preview-editor.js` | anteprima multi-file con CodeMirror, pdf.js e SyncTeX |
| verifiche-print-ui, print-info | `js/modules/print/` | pannello stampa admin, configurazioni |

## Flusso

Vedi [[tex-pipeline]] per il dettaglio contract → HTML → TeX → PDF.

```mermaid
flowchart LR
    A[Selezione nel DOM] -->|buildSelectionFromDOM| B[POST /api/verifica/save-tex-batch]
    B --> C[Selection::fromArray → TexBuilder::buildFlat per variante]
    C --> D[VerificaDocumentService::saveBatch: 8 righe + blob cifrati, transazione]
    D --> E[POST /api/verifica/{id}/compile o compile-async]
    E --> F[TexCompileClient → microservizio → PDF]
    F --> G[attachPdf: blob cifrato; cache per tex_sha256]
    G --> H[viewPdf · zipExport · batch zip · sync Drive/locale]
```

## Tabelle

| Tabella | Scopo |
|---------|-------|
| `verifica_documents` (vista su `verifica_documents_data`) | documenti: variante, `tex_sha256`, `tex_files` JSON, blob path, `shared_with_pool`, versione |
| `verifica_compile_jobs` | coda per `compile-async`, lavorata da `tools/cron/process_compile_jobs.php` |
| `print_info` (vista) | configurazioni di stampa |
| `teacher_content` con `content_type = verifica` | metadati per la sidebar e la visibilità |

I template (`verifica.sty`, `intestazione.tex`, `ulteriori_misure.tex`,
`griglie/`, `versioni/`, `badge_styles/`) vivono su filesystem in
`storage/templates/verifiche/{_default,<istituto>}` (`TemplateFileStore`);
le tabelle dei template packs sono state droppate (migration 032).

## Test

`tests/Unit/Services/Verifica/VerificaDocumentServiceCacheTest.php`,
`VerificaDocumentServiceSaveTexTest.php`, `tests/Unit/TexBuilderTest.php`,
`tests/Unit/Services/RmTablePipelineAuditTest.php` (i due casi `TEST_ROT`
sono stati decisi il 2026-09-04), `tests/Integration/TeacherPrintControllerTest.php`,
`AdminPrintControllerTest.php`; E2E `g21_1_*`, `g19_49_tex_variants`,
`verifica_*` (ferme a maggio).

## Link correlati

[[domains/esercizi/esercizi-overview]] · [[tex-pipeline]] · [[routing-and-api]] · [[technical-debt]] · [[decisions/ADR-010-modern-topbar]] · [[decisions/ADR-011-verifiche-multifile]] · [[decisions/ADR-012-tex-compile-vps]]
