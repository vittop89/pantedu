---
tags:
  - documentazione/architettura
  - dominio/risdoc
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["risdoc", "risorse docente"]
cssclasses: []
---

# Dominio: risdoc

> [!abstract] Scopo
> Risorse Docente — gestione, compilazione e export in PDF di documenti formali scolastici (piani annuali, relazioni finali, programmi svolti, schede recupero, ecc.). Implementazione Plan B: schema-driven + Lit 3 Web Components + REST API PHP.

## Confini del dominio

- **In**: docente autenticato, schema JSON, compilazioni form, file .tex legacy
- **Out**: form compilabile nel browser, ZIP/PDF, URL Overleaf

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| TemplateController | `app/Controllers/Risdoc/TemplateController.php` | CRUD template, file resolve, override CRUD, drift check, legacy path |
| TemplateEditorController | `app/Controllers/Risdoc/TemplateEditorController.php` | Serve pagina edit (`/risdoc/edit/{id}`) |
| TemplateViewController | `app/Controllers/Risdoc/TemplateViewController.php` | Serve pagina view, legacy path |
| CompilationController | `app/Controllers/Risdoc/CompilationController.php` | CRUD compilazioni per-docente |
| ExportController | `app/Controllers/Risdoc/ExportController.php` | Export ZIP/Overleaf + serve ZIP |
| RisdocAdminController | `app/Controllers/Admin/RisdocAdminController.php` | Admin panel: lista template, visibilità, owner, collaboratori, drift |
| TemplateResolver | `app/Services/Risdoc/TemplateResolver.php` | Risolve override vs source per (teacher, template, kind, path) |
| CompilationRepository | `app/Services/Risdoc/CompilationRepository.php` | CRUD `risdoc_compilations` |
| OverrideRepository | `app/Services/Risdoc/OverrideRepository.php` | CRUD `risdoc_teacher_overrides` (Phase 24.58: multi-instance via `instance_key`) |
| InstitutionalOverrideRepository | `app/Services/Risdoc/InstitutionalOverrideRepository.php` | Phase 24.55: admin baseline overrides sopra ai source file disk |
| TexBuilder | `app/Services/Risdoc/TexBuilder.php` | Schema-driven TeX body generation (fallback quando .tex legacy manca) |
| Permission | `app/Services/Risdoc/Permission.php` | `currentTeacherId()`, `canView()`, `isSuperAdmin()` |
| FormRenderer | `app/Services/Risdoc/FormRenderer.php` | Renderizza form HTML da schema JSON (server-side SSR) |
| Schemas | `schemas/risdoc/*.json` | 17 schemi documento + template.schema.json meta-schema |
| TeX templates | `storage/templates/risdoc/` | File .tex per categoria, texCommon/main.tex, risdoc.sty |
| Web Components | `js/components/risdoc/fm-risdoc-*.js` | Lit 3 WC: form-checkbox, checkbox-group, dynamic-table, info-field, nota-textarea, grade-selector, giudizio-group, giudizio-item, glossary-table, signature-block, privacy-block, section-header, static-content, text-section, template (orchestratore), export |
| Views | `views/risdoc/` | Shell PHP che monta i WC |

## Flusso compilazione e export

Vedi [[user-flows#Flusso 3]] e [[domains/risdoc/tex-pipeline]].

## API pubblica verso altri domini

- `GET /api/risdoc/templates` — lista template visibili per docente (`?with_body_pt=1` opt-in PT seed)
- `GET /api/risdoc/templates/{id}/schema` — schema JSON (resolver 3-layer: teacher > institutional > disk)
- `GET /risdoc/view/{id}` — view docente (`?instance=KEY` per istanza fork; `?admin_edit=1` per admin schema editor)
- `GET /risdoc/edit/{id}` — editor avanzato (override raw)
- `POST /api/risdoc/templates/{id}/compilations` — salva compilazione
- `POST /api/risdoc/templates/{id}/export` — genera ZIP/Overleaf
- **Phase 24.55**: `POST /api/risdoc/templates/{id}/institutional-override[/del]` — admin baseline (super-admin only)
- **Phase 24.58**: `GET|POST /api/risdoc/templates/{id}/instances[/{key}/{delete|rename}]` — multi-instance fork docente
- **Phase 24.58**: `GET /api/risdoc/teacher/instances` — tutte le istanze del docente cross-template

## DB Tables

| Tabella | Scopo |
|---------|-------|
| `risdoc_templates` | Catalogo template (1 record per documento). Phase 24.50: `body_pt` LONGTEXT opzionale (PT seed editor). |
| `risdoc_template_collaborators` | Docenti collaboratori per template |
| `risdoc_template_visibility` | Visibilità per-docente |
| `risdoc_teacher_overrides` | File override per-docente. Phase 24.58: `instance_key`+`instance_label` per multi-instance fork. |
| `risdoc_institutional_overrides` | Phase 24.55: admin baseline editabile sopra al source disk |
| `risdoc_compilations` | Compilazioni valorizzate per-docente |

## Resolver 3-layer (Phase 24.55-24.58)

`TemplateResolver::resolveFile($teacherId, $templateId, $kind, $path, $instanceKey='')` cerca in ordine:
1. **Teacher override** per `(teacher, template, instance_key, kind, path)` — privato all'istanza specifica del docente.
2. **Institutional override** per `(template, kind, path)` — admin baseline editabile via UI.
3. **Source file su disco** — fallback legacy `storage/templates/...`.

## Dipendenze

- **core**: Auth, Database, Response, Config
- **auth**: Permission usa `Auth::isSuperAdmin()`, teacher_id da sessione
- **nessuna dipendenza** da esercizi/verifiche/mappe

## Link correlati

[[domains/risdoc/tex-pipeline]] · [[decisions/ADR-002-lit3-web-components]] · [[decisions/ADR-003-tex-pipeline]] · [[decisions/ADR-005-schema-driven-risdoc]] · [[architecture]] · [[technical-debt]]
