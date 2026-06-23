# Phase index

> Generato da `tools/dev/gen_phases_md.php`. **Non editare a mano** — rigenera.
>
> Il codice usa marker `Phase N` (es. `// Phase 14 — ...`) per tracciare le iterazioni di lavoro. Questo indice li raccoglie: snippet rappresentativo (best-effort dal codice), n. occorrenze, file. **Attenzione**: lo stesso numero di phase è talvolta riusato per lavori diversi → lo snippet è solo uno dei contesti; usa la colonna *File* + `git log` + `wiki/changelog/` per la storia completa.

Totale: **192** phase distinte, **1121** occorrenze in `app/` + `js/` + `views/`.

| Phase | Descrizione (dal codice) | # | File principali |
|-------|--------------------------|---|------------------|
| **1** | Modal "Template Filler" per generare TikZ via form. | 6 | `app/Services/Verifica/VerificaDocumentService.php`, `js/entries/tikz-template-filler.js`, `js/modules/editor/tikz-templates/schema-modulare.js` +1 |
| **2** | transazione DB atomica. | 5 | `app/Services/BlockList.php`, `app/Services/Verifica/VerificaDocumentService.php`, `app/Support/ViteManifest.php` |
| **3** | post-commit reap dei blob force-replaced. | 3 | `app/Services/Verifica/VerificaDocumentService.php`, `js/modules/bootstrap.js` |
| **6** | — | 5 | `js/fm-router.js`, `js/fm-url-state.js`, `views/layout/app.php` |
| **8** | Pagina profilo docente: | 4 | `app/Controllers/TeacherProfileController.php`, `js/modules/bootstrap.js`, `js/modules/print/print-client.js` +1 |
| **9** | Admin Files API per editor template verifiche. | 32 | `app/Controllers/Admin/VerificaFilesAdminController.php`, `app/Services/TikzService.php`, `js/modules/bootstrap.js` +29 |
| **12** | Check endpoints (check_password, check_file_protection). | 4 | `app/Controllers/CheckController.php`, `app/Controllers/VerificheController.php`, `app/Services/PrintInfoService.php` |
| **13** | il pulsante ATTIVA viene iniettato dentro OGNI .sidepage | 23 | `app/Controllers/AdminController.php`, `app/Controllers/AdminToolsController.php`, `app/Controllers/ContentStudyController.php` +18 |
| **13.5** | studente eredita scope sezione (per ExerciseAccessPolicy) | 6 | `app/Controllers/AdminAnalyticsController.php`, `app/Controllers/RegistrationController.php`, `app/Services/AdminAnalyticsService.php` +1 |
| **14** | repository per storage_objects (metadati provider-agnostici). | 24 | `app/Config/monitoring.php`, `app/Config/retention.php`, `app/Config/storage.php` +16 |
| **15** | passa teacher_id + institute_id al renderer per source registry lookup | 24 | `app/Controllers/ContentStudyController.php`, `app/Controllers/ExerciseStudyController.php`, `app/Controllers/TeacherContentController.php` +13 |
| **16** | Aggrega le fonti attive dai `.fm-collection__item .origin` della pagina. | 86 | `app/Controllers/ContentStudyController.php`, `app/Controllers/HomeController.php`, `app/Controllers/TeacherContentController.php` +21 |
| **17** | storage put con retry esponenziale (3 tentativi: 0ms, 50ms, 200ms). | 38 | `app/Controllers/ContentStudyController.php`, `app/Controllers/TeacherContentController.php`, `app/Core/Container.php` +17 |
| **18** | formato 'map': NO exercise-context (evita padding-top:95px | 59 | `app/Config/filesystem.php`, `app/Controllers/ContentStudyController.php`, `app/Controllers/ExerciseController.php` +19 |
| **19** | probe endpoint no-op per health check + test e2e CSRF/rate. | 20 | `app/Controllers/ContentStudyController.php`, `app/Controllers/CsrfProbeController.php`, `app/Controllers/TeacherContentController.php` +15 |
| **20** | href con ?ids=<id> (server carica solo quel content). class=linkref | 61 | `app/Controllers/ContentStudyController.php`, `app/Controllers/TeacherContentController.php`, `app/Controllers/TeacherController.php` +23 |
| **21** | `fm-has-upbar` identifica route CON upbar (esercizio/verifica), | 21 | `app/Controllers/Admin/RisdocAdminController.php`, `app/Controllers/ContentStudyController.php`, `app/Controllers/Risdoc/ExportController.php` +15 |
| **22** | Factory unica per i field contenteditable di TUTTI gli editor: | 3 | `js/modules/features/checkin-handlers.js`, `views/layout/app.php` |
| **22.1** | — | 2 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/modules/risdoc/pt/pt-to-html.js` |
| **22.2** | — | 1 | `app/Services/Risdoc/Pt/PtValidator.php` |
| **22.3** | — | 1 | `js/modules/risdoc/pt/pm-pt-converter.js` |
| **22.3b** | — | 3 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/entries/risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **22.3c** | eliminate alert/prompt, sostituite con modali inline | 1 | `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **22.3d** | listener per NodeView edit events (click su chip | 2 | `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **22.4** | — | 1 | `app/Services/Risdoc/Pt/TexBlockExtractor.php` |
| **22.4b** | — | 1 | `app/Services/Risdoc/Pt/SchemaSeeder.php` |
| **22.4c** | PT-aware variant: attivo se schema.field.default è PT AST | 2 | `js/components/risdoc/fm-risdoc-nota-pt-rich.js`, `js/components/risdoc/index.js` |
| **22.5** | Converte un field value in TeX: | 1 | `app/Services/Risdoc/TexBuilder.php` |
| **22.6** | — | 1 | `app/Services/Risdoc/Pt/TexSourceAutoDetector.php` |
| **22.7** | toggle mode rich/source. | 5 | `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **23** | tutti i colors via CSS custom properties (risdoc-tokens.css). | 3 | `js/components/risdoc/fm-risdoc-nota-pt-rich.js`, `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **23.3** | normalize value a PT AST. Casi: | 1 | `js/components/risdoc/fm-risdoc-nota-pt-rich.js` |
| **23.4** | — | 3 | `js/components/risdoc/fm-risdoc-checkbox-group.js`, `js/components/risdoc/_pt-loader.js` |
| **24.1** | Block atom: table con cells editabili inline + toolbar add/remove. | 4 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` +1 |
| **24.2** | Block atom: select. NodeView con native <select> interattivo. | 4 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` +1 |
| **24.3** | textField: `{label}: {value}` plain (kind text/number/date | 3 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/modules/risdoc/pt/pm-schema.js`, `js/modules/risdoc/pt/pt-to-html.js` |
| **24.4** | formCheckbox: singolo `\xcheckbox{}` / `\checkbox{}` con label. | 3 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/modules/risdoc/pt/pm-schema.js`, `js/modules/risdoc/pt/pt-to-html.js` |
| **24.5** | sectionHeader: `\section{}` / `\subsection{}` in base a level. | 4 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` +1 |
| **24.6** | section unificata PT (opt-in via section.pt_unified) | 3 | `js/components/risdoc/fm-risdoc-pt-section.js`, `js/components/risdoc/index.js`, `js/modules/risdoc/pt/section-to-pt.js` |
| **24.9** | se il field dichiara options_source e abbiamo options | 3 | `js/components/risdoc/fm-risdoc-pt-section.js`, `js/components/risdoc/_options-fetcher.js`, `js/modules/risdoc/pt/section-to-pt.js` |
| **24.10** | inserisce un block atomico alla posizione corrente | 2 | `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **24.10b** | toolbar globale sticky (attiva se schema ha ≥1 pt_unified) | 8 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/components/risdoc/fm-risdoc-pt-section.js`, `js/components/risdoc/fm-risdoc-pt-toolbar.js` +2 |
| **24.10c** | Helper per input interattivi dentro NodeView atom. | 2 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.11** | Normalizza cell (string legacy o object) a forma uniforme | 4 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.11b** | select può avere options_source come checkbox-group | 1 | `js/modules/risdoc/pt/section-to-pt.js` |
| **24.12** | include anche risdoc/strcomp items che usano | 1 | `js/modules/features/sidepage-highlight.js` |
| **24.13** | Tiptap 3.x StarterKit include già Underline. Import | 3 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.15** | log render solo su cambio significativo (dedup spam console) | 1 | `js/components/risdoc/fm-risdoc-pt-section.js` |
| **24.17** | risdoc/strcomp sidepage render emette evento diverso. | 2 | `js/modules/features/sidepage-highlight.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.18** | renderPopContent: ricostruisce popover body con cell aggiornata. | 7 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/components/risdoc/fm-risdoc-pt-section.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.19** | Sorgente opzioni (inline / file / folder) come ptSelect popover | 15 | `app/Controllers/Risdoc/TemplateController.php`, `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js` +1 |
| **24.20** | inference basata su presenza chiave (non truthy) così | 3 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.21** | fetch options_source runtime per table cell select. | 3 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.22** | Posiziona il popover (position:fixed) ancorandolo al ⚙ in | 7 | `js/components/risdoc/fm-risdoc-pt-toolbar.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.24** | include TUTTI i block types PT (aggiunti in 24.1-5). | 1 | `app/Services/Risdoc/TexBuilder.php` |
| **24.25** | Detecta PT AST: array list con primo block che ha _type | 2 | `app/Controllers/Risdoc/ExportController.php` |
| **24.26** | Schema-driven TeX builder modernizzato. | 1 | `app/Services/Risdoc/TexBuilder.php` |
| **24.27** | usa sectionbox (definito in risdoc.sty come tcolorbox) | 1 | `app/Services/Risdoc/TexBuilder.php` |
| **24.28** | picker fisso 3 file texCommon (main.tex/risdoc.sty/intestaLAteX_IIS.tex) | 8 | `app/Controllers/Risdoc/TemplateController.php`, `js/components/risdoc/fm-risdoc-pt-toolbar.js`, `js/modules/features/risdoc-editor.js` |
| **24.29** | skip header vuoto (era duplicato col title del parent) | 10 | `app/Services/Risdoc/Pt/PtToTex.php`, `app/Services/Risdoc/TexBuilder.php` |
| **24.30** | Style overrides via toolbar\n" . implode("\n", $overrideLines) . "\n"; | 11 | `app/Controllers/Risdoc/ExportController.php`, `js/components/risdoc/fm-risdoc-images-manager.js`, `js/components/risdoc/fm-risdoc-pt-toolbar.js` +2 |
| **24.31** | items con title matching sectionbox whitelist | 3 | `app/Services/Risdoc/Pt/PtToTex.php`, `app/Services/Risdoc/TexBuilder.php` |
| **24.32** | fill <select> con <optgroup> raggruppando per opt.group. | 12 | `app/Services/Risdoc/Pt/PtToTex.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/risdoc/pt/pm-schema.js` |
| **24.33** | title + selectors override-aware (per-combination state) | 5 | `app/Services/Risdoc/TexBuilder.php`, `js/components/risdoc/fm-risdoc-pt-toolbar.js`, `js/components/risdoc/fm-risdoc-section-header.js` |
| **24.34** | alias generico cross-domain. Stesso codice del risdoc | 1 | `js/components/risdoc/fm-risdoc-pt-editor.js` |
| **24.35** | Estrai PT AST da row.metadata.body_pt. La query base | 3 | `app/Controllers/ContentStudyController.php`, `app/Services/Risdoc/Pt/PtToHtml.php` |
| **24.36** | accept anche content-{id}-{hex}.zip da TeacherContentController | 2 | `app/Controllers/Risdoc/ExportController.php`, `app/Controllers/TeacherContentController.php` |
| **24.37** | export ZIP TeX da metadata.body_pt | 1 | `js/modules/features/sidepage-inline-actions.js` |
| **24.38** | ProseMirror richiede white-space: pre-wrap per gestire | 2 | `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/modules/features/section-edit-mode.js` |
| **24.41** | Default labels (override per-teacher via localStorage) | 2 | `js/modules/features/risdoc-sidepage.js` |
| **24.43** | Categorie effettivamente presenti nelle sidepage (DOM scan). | 2 | `js/modules/features/risdoc-sidepage.js` |
| **24.44** | seed body_pt per layout="exercises". | 1 | `js/modules/features/sidepage-modal-content.js` |
| **24.45** | legge metadata.layout (exercises\|custom) con full lookup fallback. | 3 | `app/Controllers/ContentStudyController.php` |
| **24.47** | uniformazione: ogni .fm-db-block ha data-section/data-section-kind | 6 | `js/modules/features/db-sidepage.js`, `js/modules/features/risdoc-sidepage.js`, `js/modules/features/section-edit-mode.js` +1 |
| **24.48** | Custom categories: estratto in sidepage-custom-categories.js | 5 | `js/modules/features/risdoc-sidepage.js` |
| **24.49** | opt-in proiezione completa con metadata_json. | 2 | `app/Controllers/TeacherContentController.php`, `app/Repositories/TeacherContentRepository.php` |
| **24.50** | POST /api/risdoc/templates/{id}/body-pt (super-admin only). | 4 | `app/Controllers/Risdoc/TemplateController.php`, `app/Services/Risdoc/TemplateResolver.php`, `js/modules/features/sidepage-modal-content.js` |
| **24.51** | overlay editor PT seed per template istituzionale (DEPRECATED 2026-05-28). | 3 | `js/modules/features/admin-risdoc.js`, `views/admin/risdoc.php` |
| **24.54** | sezioni categoria + bottone link view | 1 | `views/admin/risdoc.php` |
| **24.55** | Institutional override (admin-edited baseline) ─────── | 5 | `app/Controllers/Risdoc/TemplateController.php`, `app/Services/Risdoc/InstitutionalOverrideRepository.php`, `app/Services/Risdoc/TemplateResolver.php` +1 |
| **24.56** | no cache: invalidato istantaneamente dopo edit admin. | 5 | `app/Controllers/Risdoc/TemplateController.php`, `app/Services/Risdoc/TemplateResolver.php`, `views/admin/risdoc.php` |
| **24.57** | crea un nuovo template. POST /api/admin/risdoc/templates/create. | 7 | `app/Controllers/Admin/RisdocAdminController.php`, `js/modules/features/admin-risdoc.js`, `views/admin/templates.php` |
| **24.58** | rinomina la partizione (category) from→to. Niente più origin: | 25 | `app/Controllers/Admin/AdminSidebarConfigController.php`, `app/Controllers/Admin/RisdocAdminController.php`, `app/Controllers/Risdoc/TemplateController.php` +5 |
| **24.62** | Merge teacher_content body_pt (documenti personali | 3 | `js/modules/features/risdoc-sidepage.js` |
| **24.63** | docenti non super_admin vedono SOLO le proprie | 2 | `app/Controllers/AuthController.php`, `js/modules/features/risdoc-sidepage.js` |
| **24.64** | override teacher è proprietà del docente; richiede | 3 | `app/Controllers/Risdoc/TemplateController.php` |
| **24.67** | parse numarg da instance_label se ha pattern | 2 | `js/modules/features/risdoc-sidepage.js` |
| **24.68** | handlers per <li[data-instance-key]> istanze fork. | 2 | `js/modules/features/sidepage-inline-actions.js` |
| **24.69** | chiave per-utente (la rinomina dblclick è una scelta | 1 | `js/modules/features/risdoc-sidepage.js` |
| **24.70** | handler per super_admin shortcut sui template istituzionali. | 1 | `js/modules/features/sidepage-inline-actions.js` |
| **24.71** | sidepage-registry caricato per primo: tutti i feature | 2 | `js/modules/bootstrap.js`, `js/modules/features/sidepage-registry.js` |
| **24.72** | dispatch a category-grouped (verif) o subject-grouped (default). | 10 | `js/modules/bootstrap.js`, `js/modules/features/db-sidepage.js`, `js/modules/features/risdoc-sidepage.js` +2 |
| **24.73** | override etichetta PER-DOCENTE trasversale (store condiviso), | 8 | `js/modules/features/db-sidepage.js`, `js/modules/features/risdoc-sidepage.js`, `js/modules/features/section-edit-mode.js` +3 |
| **24.74** | salva la META di un TEMPLATE istituzionale (super-admin) dallo | 9 | `app/Controllers/TeacherContentController.php`, `js/modules/features/sidepage-inline-actions.js`, `js/modules/features/sidepage-modal-content.js` |
| **24.75** | override globale di window.alert con il popup custom. alert() | 1 | `js/modules/ui/fm-dialog.js` |
| **24.76** | quando le etichette categoria sono idratate dal DB (e diverse | 2 | `app/Controllers/TeacherCategoryLabelController.php`, `js/modules/features/section-edit-mode.js` |
| **24.77** | Stepper custom ▲▼ (BEM .fm-stepper__btn) generico per input | 13 | `app/Controllers/ContentStudyController.php`, `app/Services/ContractRenderer.php`, `js/modules/core/utilities.js` +2 |
| **24.78** | valore-soluzione celle N/text: persiste su change (blur). | 12 | `app/Services/TexBuilder/Sanitizer.php`, `js/modules/core/dom-block-extractor.js`, `js/modules/features/checkin-handlers.js` +2 |
| **24.x** | prima erano \xcheckbox{}/\checkbox{} di | 1 | `app/Services/Risdoc/Pt/PtToTex.php` |
| **25** | editor categorie predefinite per sezione (dinamico su group_mode). | 34 | `app/Controllers/Admin/AdminSidebarConfigController.php`, `app/Controllers/LatexShortcutsController.php`, `app/Controllers/TeacherContentController.php` +15 |
| **25.A** | nonce + strict-dynamic: gli script iniziali (incl. il | 3 | `app/Middleware/SecurityHeadersMiddleware.php` |
| **25.A1** | escapeHtml importata da core/dom-utils.js (alias di escHtml). | 9 | `js/modules/bootstrap.js`, `js/modules/core/dom-utils.js`, `js/modules/features/db-sidepage.js` +1 |
| **25.A3** | Estratto da section-edit-mode.js (1003 LOC → 4 moduli). | 4 | `js/modules/features/section-edit-mode.js`, `js/modules/features/sidepage-edit-toggle.js`, `js/modules/features/sidepage-inline-actions.js` +1 |
| **25.A4** | ETag client cache per /api/teacher/content?type=… | 6 | `js/modules/features/db-sidepage.js`, `js/modules/features/sidepage-modal-content.js` |
| **25.B1** | PasswordSidepage:true (legacy gate per studenti) | 1 | `js/modules/core/config.js` |
| **25.B2** | race-safe: usa INSERT ... ON DUPLICATE KEY UPDATE per | 3 | `app/Controllers/Risdoc/TemplateController.php`, `app/Services/Crypto/TeacherCryptoService.php`, `app/Services/Risdoc/OverrideRepository.php` |
| **25.B3** | risolve lo scope di visibilità di un template istituzionale. | 2 | `app/Controllers/Admin/RisdocAdminController.php`, `app/Services/Risdoc/Permission.php` |
| **25.B4** | Middleware "audit reason required" per operazioni admin | 2 | `app/Core/Kernel.php`, `app/Middleware/RequiresAuditReasonMiddleware.php` |
| **25.B5** | bucket scoped: bucketKey distingue endpoint sensibili | 3 | `app/Middleware/RateLimitMiddleware.php` |
| **25.B6** | Security headers obbligatori per tutte le response HTML/JSON. | 3 | `app/Core/Kernel.php`, `app/Middleware/SecurityHeadersMiddleware.php` |
| **25.B7** | `polyfill.io` rimosso (CDN compromise 2024, ora | 2 | `app/Middleware/SecurityHeadersMiddleware.php` |
| **25.C** | Self-service GDPR endpoints per data subjects (Art. 7, 16, 17, 20). | 6 | `app/Config/waf.php`, `app/Controllers/SelfServiceController.php`, `app/Core/Kernel.php` +1 |
| **25.C.2** | — | 1 | `app/Config/waf.php` |
| **25.C10** | — | 1 | `app/Services/Gdpr/ConsentService.php` |
| **25.C11** | sync con /me/consents API se l'utente è loggato. | 2 | `js/modules/core/cookie-consent.js` |
| **25.C12** | — | 1 | `app/Controllers/TrustPagesController.php` |
| **25.C13** | DPO contact form pubblico (no auth richiesto). | 1 | `app/Controllers/DpoContactController.php` |
| **25.C2** | TOS + privacy disclosure obbligatoria (Art. 7 + 13). | 11 | `app/Controllers/RegistrationController.php`, `app/Services/RegistrationService.php`, `views/auth/register.php` |
| **25.C3** | Service per gestione consensi GDPR (Art. 6, 7, 9). | 1 | `app/Services/Gdpr/ConsentService.php` |
| **25.C4** | Self-service oblio Art. 17 GDPR con crypto-shredding O(1). | 1 | `app/Services/Gdpr/DeletionRequestService.php` |
| **25.C7** | Endpoint pubblici per parent consent Art. 8 GDPR. | 3 | `app/Controllers/ParentConsentController.php`, `app/Services/Gdpr/ParentConsentService.php`, `views/auth/register.php` |
| **25.C7.fix** | — | 2 | `app/Controllers/ParentConsentController.php`, `app/Services/Gdpr/ParentConsentService.php` |
| **25.C8** | Wrapper Mailer per workflow parent consent (Art. 8 GDPR). | 4 | `app/Jobs/SendMailJob.php`, `app/Services/ParentConsentMailer.php`, `app/Services/RegistrationService.php` |
| **25.D** | CSS estratto in /css/admin.css (auto-load da layout/shell). */ ?> | 10 | `app/bootstrap.php`, `app/Controllers/MetricsController.php`, `app/Controllers/TrustPagesController.php` +6 |
| **25.D3** | true se le read devono usare i ciphertext (post-backfill). | 9 | `app/Repositories/TeacherContentRepository.php` |
| **25.D5** | — | 1 | `app/Services/Maps/MapBlobStore.php` |
| **25.D6** | Envelope encryption per published_content (decoupled da | 1 | `app/Services/Crypto/ClasseKeyService.php` |
| **25.E** | — | 1 | `app/Controllers/SelfServiceController.php` |
| **25.E10** | — | 2 | `js/modules/bootstrap.js`, `js/modules/core/logout-cleanup.js` |
| **25.E11** | logout-time cleanup di localStorage user-scoped (mitiga | 2 | `js/modules/bootstrap.js`, `js/modules/core/logout-cleanup.js` |
| **25.E12** | section-navigator dropdown (HTML scheletro popolato | 3 | `app/Controllers/Risdoc/TemplateViewController.php`, `js/components/risdoc/fm-risdoc-pt-editor.js`, `js/components/risdoc/fm-risdoc-section-navigator.js` |
| **25.E13** | bindAutoHide() rimossa (vedi init()). Mantenuta come no-op. | 7 | `js/components/risdoc/fm-risdoc-pt-section.js`, `js/components/risdoc/fm-risdoc-pt-toolbar.js`, `js/components/risdoc/fm-risdoc-section-navigator.js` |
| **25.E14** | rimosso 'margin-left: auto' che spingeva i bottoni | 4 | `js/components/risdoc/fm-risdoc-pt-section.js`, `js/components/risdoc/fm-risdoc-pt-toolbar.js` |
| **25.E15** | legacy /cookies_privacy-policy.html → /privacy/informativa | 1 | `js/modules/ui/dom-manager.js` |
| **25.E17** | Cron / localhost-only entrypoints. | 4 | `app/Controllers/AdminPartialController.php`, `app/Controllers/CronController.php`, `app/Controllers/LogServeController.php` +1 |
| **25.E18** | skip init per non-admin (no polling, no rumore console). | 2 | `js/modules/features/admin-banner-badge.js` |
| **25.E19** | il titolo del button è dinamico (current section | 3 | `app/Controllers/Risdoc/TemplateViewController.php`, `js/components/risdoc/fm-risdoc-section-navigator.js` |
| **25.E21** | sticky bar #fm-current-section rimossa. | 1 | `js/components/risdoc/fm-risdoc-section-navigator.js` |
| **25.E3** | Rilascia il lock advisory (sempre, anche on exception). | 5 | `app/Core/Migrator.php` |
| **25.E4** | request_id correlation (set da RequestIdMiddleware). | 6 | `app/Core/Kernel.php`, `app/Core/Logger/JsonLogger.php`, `app/Core/Telemetry.php` +1 |
| **25.E4.2** | Endpoint /metrics Prometheus-compatible. | 2 | `app/Controllers/MetricsController.php` |
| **25.E4.3** | Lightweight tracing helper (no full OpenTelemetry SDK). | 1 | `app/Core/Telemetry.php` |
| **25.E6** | i 3 link trust (Privacy/Sicurezza/DPO) sono nel | 2 | `views/partials/sidebar.php` |
| **25.E8** | Trust pages pubbliche per trasparenza GDPR + sicurezza. | 2 | `app/Controllers/TrustPagesController.php` |
| **25.F** | — | 2 | `app/Controllers/SecurityAdminController.php`, `app/Services/Waf/WafSecurityRepository.php` |
| **25.G** | persistere in `waf_anomalies` table. | 3 | `app/Controllers/Admin/WafAdminController.php`, `views/admin/waf/_layout_head.php`, `views/admin/_partials/page_head.php` |
| **25.H** | migrato a layout/shell.php uniforme (era layout/app.php | 8 | `app/Controllers/Admin/TemplatesAdminController.php`, `app/Controllers/Admin/WafAdminController.php`, `app/Middleware/WafMiddleware.php` +4 |
| **25.H.1** | semplificato: solo URL param. Era doppia guardia | 2 | `app/Controllers/Admin/WafAdminController.php`, `app/Middleware/WafMiddleware.php` |
| **25.I** | match against threat-intel ASN category table. | 4 | `app/Controllers/Admin/WafAdminController.php`, `app/Middleware/WafMiddleware.php`, `app/Services/Waf/WafRulesService.php` +1 |
| **25.J** | bouncer free (no CrowdSec Service API a pagamento). | 9 | `app/Config/security.php`, `app/Config/waf.php`, `app/Controllers/UserProfileController.php` +2 |
| **25.J.2** | la request attende JSON (fetch/XHR del SPA o path /api/). | 1 | `app/Middleware/WafMiddleware.php` |
| **25.J.3** | alternativa free al CrowdSec Service API ($29/mo). | 1 | `app/Services/Waf/WafCrowdSecBouncerService.php` |
| **25.J.4** | 2FA TOTP self-service. | 1 | `app/Controllers/TotpController.php` |
| **25.P** | Middleware ToS+AUP enforcement per multi-tenancy (Scenario B/C). | 7 | `app/Controllers/Admin/AdminTakedownController.php`, `app/Controllers/Public/PublicTakedownController.php`, `app/Controllers/TosAcceptanceController.php` +4 |
| **25.P.1** | Verifica che un contenuto sia ELIGIBLE alla condivisione | 3 | `app/Services/Sharing/SharedContentPolicy.php` |
| **25.P.3** | sincronizza anche `teacher_content_data.source_type` (cache | 6 | `app/Controllers/TeacherContentController.php`, `app/Services/Contract/ContractAggregate.php`, `app/Services/Contract/ContractRepository.php` +1 |
| **25.Q** | RisDoc riservato a teacher/admin; nascosto a student/guest. */ ?> | 20 | `app/Controllers/Admin/AdminInstitutesController.php`, `app/Controllers/Admin/AdminTosLogController.php`, `app/Controllers/Public/PublicTakedownController.php` +11 |
| **25.Q.4** | self-hosted in /vendor/quill/1.3.6/ (FND-VPS-002 conforme). | 2 | `views/partials/head.php`, `views/partials/_exercise_assets.php` |
| **25.Q.5** | risolve institute_id da codice MIUR (institutes.code). | 4 | `app/Controllers/CurriculumController.php`, `views/auth/register.php` |
| **25.Q.6** | passa username generato per visualizzazione | 1 | `app/Controllers/RegistrationController.php` |
| **25.Q.7** | reset claim cached del precedente utente: | 1 | `app/Core/Auth.php` |
| **25.Q.8** | studente non vede checkmod (GIUST/SOL toggle + edit/move). | 9 | `app/Controllers/ContentStudyController.php`, `app/Services/ContractRenderer.php` |
| **25.Q.9** | nascosto per QUALUNQUE utente autenticato (staff o | 3 | `js/modules/features/student-resource-auth.js`, `views/partials/sidebar.php` |
| **25.Q.10** | student loggato: stesso Logout grosso | 1 | `views/partials/sidebar.php` |
| **25.Q.11** | studente: vede l'offerta formativa del suo istituto | 2 | `views/layout/app.php` |
| **25.Q.12** | skip injection se utente non ha edit scope (student/guest). | 3 | `js/modules/features/dsa-marks.js`, `js/modules/ui/checkmod.js`, `views/layout/app.php` |
| **25.Q.13** | studente/guest: skip TOTALE upbar legacy. I filtri | 1 | `views/partials/_upbar_loader.php` |
| **25.Q.14** | riflette l'altezza reale della topbar (che varia | 1 | `js/modules/features/topbar-modern.js` |
| **25.Q.15** | lista verifiche shared con pool, visibili a studenti | 3 | `app/Controllers/VerificaController.php`, `app/Repositories/VerificaDocumentRepository.php`, `js/modules/features/verifica-documents-sidepage.js` |
| **25.Q.16** | GET /api/study/header-page.json — endpoint read-only | 3 | `app/Controllers/ContentStudyController.php`, `js/modules/features/upbar-controls.js`, `js/modules/features/verifica-documents-sidepage.js` |
| **25.R** | — | 8 | `app/Controllers/Admin/AdminBackupController.php`, `app/Controllers/Admin/AdminMonitoringController.php`, `app/Controllers/GrafanaGateController.php` +5 |
| **25.R.1.1** | validazione stretta su code/name/city/region per | 1 | `app/Repositories/InstituteRepository.php` |
| **25.R.1.2** | super_admin sempre esente (operatore tecnico | 1 | `app/Middleware/TosAcceptanceMiddleware.php` |
| **25.R.1.3** | post-logout: redirect a /login (anziché home guest | 1 | `app/Controllers/AuthController.php` |
| **25.R.2.1** | guest sidebar: nascondi sel-wrapper (selettori istituto | 1 | `views/partials/sidebar.php` |
| **25.R.2.2** | anti-FOUC dark mode: applica body.fm-dark in modo | 1 | `views/layout/app.php` |
| **25.R.2.3** | — | 1 | `app/Controllers/Public/PublicTakedownController.php` |
| **25.R.2.4** | wrap trust-page wrapper inside layout/app.php so direct | 4 | `app/Controllers/DpoContactController.php`, `app/Controllers/TrustPagesController.php`, `app/Support/StandalonePageRenderer.php` |
| **25.R.3.1** | Takedown queue index (refactor da standalone HTML hardcoded | 3 | `app/Controllers/Admin/AdminTakedownController.php`, `views/admin/takedown_index.php`, `views/admin/takedown_show.php` |
| **25.R.4.1** | Repository per `subprocessors` (DPA art. 9, GDPR Art. 28). | 4 | `app/Controllers/Admin/AdminGdprController.php`, `app/Repositories/Gdpr/DataBreachRepository.php`, `app/Repositories/Gdpr/SubprocessorRepository.php` +1 |
| **25.R.4.3** | invia 2 email (ack al richiedente + notify al DPO). | 2 | `app/Controllers/DpoContactController.php` |
| **25.R.5.3** | Crypto status dashboard + log custodia/cooperazione autorità. | 2 | `app/Controllers/Admin/AdminCryptoStatusController.php`, `views/admin/crypto_status.php` |
| **25.R.19** | back-compat: redirect 301 ex /admin/waf/credentials → /blocks#credentials | 6 | `app/Controllers/Admin/WafAdminController.php`, `views/admin/tools.php`, `views/admin/waf/blocks.php` |
| **25.R.22** | diag data injected per accordion (ex /admin/waf/diag merged here) | 15 | `app/Controllers/Admin/AdminCryptoStatusController.php`, `app/Controllers/Admin/AdminGdprController.php`, `app/Controllers/Admin/WafAdminController.php` +6 |
| **25.R.23** | Bundle ZIP esteso con contenuti decifrati via UserDataExportService. | 20 | `app/Controllers/Admin/AdminCryptoStatusController.php`, `app/Controllers/Admin/AdminGdprController.php`, `app/Controllers/SelfServiceController.php` +15 |
| **25.R.23.1** | STREAMING via X-Serve-File header (Response::serveFile | 1 | `app/Controllers/Admin/AdminCryptoStatusController.php` |
| **25.R.23.2** | include anche risdoc_templates creati/forkati dal docente | 5 | `app/Services/Gdpr/Export/Exporters/ClasseKeysExporter.php`, `app/Services/Gdpr/Export/Exporters/TeacherContentExporter.php`, `app/Services/Gdpr/Export/Exporters/TemplatesExporter.php` |
| **25.R.24** | Scope contenuti unificato dentro Step ② (GDPR minimizzazione perimetro) | 7 | `app/Controllers/Admin/AdminCryptoStatusController.php`, `app/Services/Crypto/ShamirSecretSharing.php`, `app/Services/Gdpr/Export/Exporters/TeacherContentExporter.php` +1 |
| **25.R.25** | Pre-fetch state attuale per detection visibility/share transition | 15 | `app/Controllers/Admin/AdminLogsController.php`, `app/Controllers/AdminController.php`, `app/Repositories/TeacherContentRepository.php` +6 |
| **25.R.30** | toggle menu a tendina nav admin (1 aperto alla volta). | 2 | `views/admin/_partials/page_head.php` |
| **25.R.31** | — | 1 | `app/Middleware/RateLimitMiddleware.php` |
| **26** | — | 3 | `app/Controllers/ExerciseController.php`, `app/Controllers/LogServeController.php`, `js/modules/core/endpoints.js` |
