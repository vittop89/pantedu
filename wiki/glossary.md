---
tags:
  - documentazione/glossario
date: 2026-04-23
tipo: glossario
status: finale
aliases: ["glossario", "glossary", "termini"]
cssclasses: []
---

# Glossary

| Termine | Significato | Dove appare |
|---------|-------------|------------|
| **risdoc** | Risorse docente — documenti formali (piani annuali, relazioni, programmi svolti) | `app/Controllers/Risdoc/`, `schemas/risdoc/`, `storage/templates/risdoc/` |
| **Plan A** | Architettura risdoc legacy: IIFE jQuery (`risdoc.js`) + PHP serve file — file rimosso dal repo (in git history) | (storico) `storage/templates/risdoc/risdoc.js`, 4931 LOC |
| **Plan B** | Architettura risdoc moderna: Lit 3 Web Components + REST API PHP | `js/components/risdoc/`, `app/Controllers/Risdoc/` |
| **collex-item** | Elemento di raccolta esercizi (contenitore singolo problema/quesito) | CSS class protetta, `js/modules/`, views esercizi |
| **collex** | Contenitore di più collex-item (raccolta esercizi) | CSS class protetta |
| **problema / problem** | Gruppo di esercizi LaTeX (classe CSS protetta) | Views, JS legacy |
| **testo** | Testo di un esercizio (classe CSS protetta) | Views, JS |
| **sol / giustsol** | Soluzione / soluzione corretta (classi CSS protette) | Views, JS |
| **verifica** | Documento di valutazione scolastica (test, compito in classe) | `app/Controllers/VerificheController.php` |
| **verifica builder** | Tool per costruire verifiche selezionando esercizi | `app/Controllers/VerificaBuilderController.php` |
| **BES/DSA** | Bisogni Educativi Speciali / Disturbi Specifici Apprendimento | `app/Services/DsaService.php`, classi CSS `dsa-*` |
| **compilation** | Istanza valorizzata di un template risdoc per un docente/classe | `risdoc_compilations` DB, `CompilationController` |
| **override** | Personalizzazione di un file template (tex/json/html) per-docente | `risdoc_teacher_overrides` DB, `OverrideRepository` |
| **TikZ** | Linguaggio grafico LaTeX per diagrammi matematici/fisici | `app/Controllers/TikzController.php`, `js/vendor/tikzjax.js` |
| **pdflatex** | Compilatore LaTeX per generare PDF | `ExportController`, `storage/templates/risdoc/texCommon/` |
| **dual-write** | Scrittura simultanea su DB MySQL e file JSON legacy durante transizione | `app/Config/database.php`, `TeacherContentRepository` |
| **contract** | JSON strutturato secondo `schemas/pantedu.content.v1.json` che definisce un documento (esercizi, verifiche) | `app/Services/Contract/` |
| **ContractAggregate** | Aggregate root per operazioni su items/gruppi di un contract | `app/Services/Contract/ContractAggregate.php` |
| **quesito** | Item singolo (esercizio) all'interno di un contract | API `/api/teacher/content/{id}/quesito/{itemRef}/*` |
| **form_state** | JSON inviato dal client contenente i valori compilati di un form risdoc | `ExportController`, `CompilationController` |
| **marker TeX** | Placeholder nel .tex legacy (es. `[field-classe]`, `%[BeginList-hide]`) | `ExportController::processLegacyTex()` |
| **legacy_gone** | Middleware che emette 410 Gone su route deprecate | `app/Middleware/LegacyGoneMiddleware.php` |
| **super-admin** | Flag tecnico `is_super_admin` ortogonale al role; accesso tracciato a metriche/log | `app/Core/Auth.php::isSuperAdmin()` |
| **student grant** | Grant sessione per studente che accede con credenziali del docente | `TeacherCredentialController::studentLogin()` |
| **ULID** | Universally Unique Lexicographically Sortable Identifier | `app/Support/Ulid.php` |
| **sidepage** | Pannello laterale di navigazione (categorie: Mappe, Eser, Verif, DidLab, RisDoc) | `app/Controllers/SidepageController.php`, `js/modules/features/` |
| **IIS** | Istituto di Istruzione Superiore (es. IIS di Esempio Comune Esempio) | Config, URL pattern |
| **indirizzo** | Indirizzo scolastico (es. `sc`=Scientifico, `ar`=Artistico) | Sessione, URL pattern |
| **classe** | Anno di corso + sezione (es. `5s`, `2b`) | Sessione, teacher content |
| **num_arg** | Numero argomento — ordine nella categoria risdoc | `risdoc_templates.num_arg` |
| **drift** | Discrepanza tra template sorgente e override docente | `TemplateController::driftStatus()`, `admin/risdoc/drift` |
| **snip_uri** | URL del ZIP passato a Overleaf per import diretto | `ExportController::export()` mode=overleaf |
| **PhaseN** | Fase di sviluppo numerata (commenti nel codice: `Phase 13`, `Phase 21`, ecc.) | Commenti routes/web.php, controller |
