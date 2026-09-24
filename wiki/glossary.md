---
tags:
  - documentazione/glossario
date: 2026-09-04
tipo: glossario
status: finale
aliases: ["glossario", "glossary", "termini"]
cssclasses: []
---

# Glossary

| Termine | Significato | Dove appare |
|---------|-------------|------------|
| **risdoc** | Risorse docente: modelli documentali formali (piani annuali, relazioni, programmi svolti, schede) | `app/Controllers/Risdoc/`, `schemas/risdoc/`, `storage/templates/risdoc/` |
| **Plan A / Plan B** | risdoc legacy (IIFE jQuery, rimosso) / risdoc moderno (Web Component Lit + API) | storico; `js/components/risdoc/` |
| **PT (Portable Text)** | albero JSON dei documenti risdoc e dei documenti personalizzati, editato con Tiptap e convertito in HTML e TeX | `js/modules/risdoc/pt/`, `app/Services/Risdoc/Pt/` |
| **terna** | indirizzo / classe / materia; «valori per terna» = un documento con campi diversi per ogni terna (ADR-030) | `TernaBinding`, campi 🔗/📌 |
| **formula** | espressione stile foglio di calcolo in una cella di tabella PT (ADR-031) | `FormulaEngine` (JS e PHP) |
| **compilation** | istanza valorizzata di un modello risdoc per un docente, cifrata | `risdoc_compilations`, `CompilationRepository` |
| **override** | personalizzazione di un file di modello (tex/json/html) per docente o per istituto | `risdoc_teacher_overrides`, `risdoc_institutional_overrides` |
| **istanza** | fork di un modello per un docente (`instance_key`), es. «Piano annuale 3A» | `TemplateController::instances*` |
| **drift** | divergenza fra file sorgente del modello e override del docente | `TemplateController::driftStatus()` |
| **scenario** | modalità di esercizio dell'istanza: `personal` (1), `colleagues` (2), `institute` (3); decide iscrizioni, account studente e documenti legali (ADR-032) | `App\Support\DeploymentScenario`, `/admin/system/deployment` |
| **credenziale di classe** | credenziale creata dal docente con cui gli studenti senza account entrano nei contenuti pubblicati per la loro classe | `ClassAccessGrant`, `/accesso-classe` |
| **sezione / incarico** | assegnazione docente → classe decisa dall'amministratore; lo studente ha una classe; il filtro dei contenuti le rispetta | `teacher_sections`, `/admin/sections` Dal 14/9/2026 l'incarico decide anche se il docente può usare la sezione, quando l'istituto è «solo incaricati» (ADR-041, `institutes.sezioni_docenti`) |
| **curriculum** | vocabolario di indirizzi, classi e materie fissato dalla scuola, importato dal dataset MIUR con alias | `curriculum_entries`, `CurriculumLookup`, `MiurCurriculumAlias` |
| **istituto attivo** | la scuola in cui il docente sta lavorando: quella scelta nel selettore, o l'unica che ha. Lì nascono i contenuti nuovi (sezione della barra, indirizzo, classe, materia, contratto), lì si legge il curriculum e lì sta l'intestazione di studio. La scuola in sessione conta solo se il docente le appartiene: altrimenti vale la sua prima (confine di ADR-037) | `TeacherContextResolver::activeInstituteId`, `CurriculumLookup::instituteForTeacher` |
| **casa dei file privati** | il primo istituto del docente per id, preferendo quelli senza codice `MIUR-%`; non cambia con il selettore. Ci stanno le sue preferenze (registro delle fonti, fonti spuntate, stile dei badge, modelli degli esercizi), che si scrivono e si leggono da lì in qualunque scuola lavori. Fino al 23/9/2026 il metodo si chiamava `firstInstituteId` (A-7) | `TeacherContextResolver::privateFilesInstituteId`, `institutes/{casa}/private/{docente}/` |
| **intestazione di studio** | il testo in cima alle pagine di studio (avviso sui libri citati e sulle credenziali) con la scelta delle citazioni automatiche. Una per docente e per scuola: il docente la scrive con la scuola attiva, gli studenti e gli ospiti di quella scuola la leggono. Scelta del 23/9/2026 (A-7), perché parla agli studenti di una scuola (i libri «in dotazione» sono i suoi), perché chi la scrive vede quello che vedono loro, e perché nessuno legge un file sotto la cartella di un'altra scuola. Per averla uguale in due scuole la si salva in tutte e due | `StudyHeaderController`, `#header_page`, `institutes/{scuola}/private/{docente}/header_page.json` |
| **contract** | JSON secondo `schemas/pantedu.content.v1.json` che descrive un contenuto (gruppi, item, blocchi) | `app/Services/Contract/` |
| **quesito** | item singolo (esercizio) dentro un contract; `{itemRef}` è un locator opaco a tre formati | `QuesitoController`, `ContractAggregate::findItemIndex` |
| **collex-item / collex / problem / testo / sol / giustsol** | classi HTML protette del markup esercizi | `ContractRenderer`, `js/modules/editor/` |
| **verifica** | documento di valutazione: 8 varianti (A/B × SOL/NOR/DSA/DIS), TeX e PDF cifrati | `VerificaController`, `verifica_documents` |
| **BES/DSA** | bisogni educativi speciali / disturbi specifici dell'apprendimento: varianti di stampa, marcatori F/GF | `VersionPicker::DSA`, `fm-dsa-*` |
| **texCommon / versioni / griglie** | file di modello delle verifiche per istituto con cascata `_default` | `TemplateFileStore`, `storage/templates/verifiche/` |
| **microservizio TeX** | servizio Python separato che compila, formatta e rende TikZ, chiamato via HMAC | `tools/tex-compile-vps/`, `app/Services/TexCompile/` |
| **TikZ** | grafica LaTeX; resa server-side in SVG con cache (ADR-013) | `TikzRenderController`, `TikzRenderClient` |
| **busta crittografica** | chiave master → KEK per docente (HKDF) → body e blob AES-256-GCM; crypto-shredding cancellando `teacher_keys` | `TeacherCryptoService` |
| **chiave di classe** | chiave che avrebbe reso leggibili le copie pubblicate agli studenti anche dopo lo shredding del docente: mai usata (zero righe), il servizio è uscito con la fase 4a di ADR-037 e le tabelle con la 120; resta un'opzione futura (ADR-037, decisione 6) | — |
| **recovery key** | chiave di recupero generata dal docente; firma il manifest del pacchetto esportato, e l'import verifica la firma con il codice che digita chi importa: prova che il pacchetto non l'ha cambiato chi non conosce la chiave, non chi l'ha esportato ([sync-strategy](../docs/architecture/sync-strategy.md)); ogni generazione, revoca o uso finisce in `teacher_recovery_audit` | `TeacherRecoveryService`, `ImportBundleController` |
| **custodia** | eventi di accesso amministrativo alla chiave o ai contenuti, mostrati al docente | `crypto_custody_events`, `/me/custody-events` |
| **registro append-only** | tabella di audit protetta da trigger contro update e senza DELETE per l'utente applicativo | `audit_activity_log`, `content_action_log`, `privileged_access_log`, `teacher_recovery_audit` |
| **catena di impronte** | hash giornaliero concatenato dei registri, esportato col backup | `AuditChain`, `tools/audit/export_audit_chain.php` |
| **audit reason** | motivazione obbligatoria (10-255 caratteri) sulle mutazioni amministrative | `RequiresAuditReasonMiddleware`, header `X-Audit-Reason` |
| **super-admin** | flag `is_super_admin` ortogonale al ruolo; governo dell'istanza, letture a registro | `Auth::isSuperAdmin()`, `super_admin_required` |
| **publish_scope / visibilità** | chi vede un contenuto: bozza, pubblicato, pool, sezione, pubblico | `ContentVisibilityPolicy`, `teacher_content.publish_scope` |
| **pool** | contenuti condivisi con i colleghi dello stesso istituto, recuperabili nel proprio account | `PoolController`, `shared_with_pool` |
| **sidebar section** | sezione della sidebar definita nel DB con ruoli, docenti, tipi ammessi (ADR-027) | `sidebar_sections`, `/admin/sidebar-config` |
| **capability** | permessi per docente in scenario istituto (creare sezioni, tipi di documento, visibilità massima) (ADR-028) | `TeacherCapabilityPolicy` |
| **WAF** | firewall applicativo nel Kernel: geo, threat-intel, regole, proof-of-work, sessione firmata | `WafMiddleware`, `app/Services/Waf/` |
| **EdgeContext** | risoluzione dell'IP reale e del paese a prova di spoofing dietro il CDN | `app/Services/Waf/EdgeContext.php` |
| **TEST_ROT** | costante (31 agosto → 4 settembre 2026) che elencava nei test i casi sospesi con motivo; tolta dopo la decisione dei nove casi | `tests/Unit/Risdoc/Pt/*`, [[testing]] |
| **legacy_gone** | middleware che risponde 410 o redirect sulle rotte dismesse | `LegacyGoneMiddleware` |
| **form_state** | valori compilati di un modello (`state`, `fields`, `body_pt`), da cui l'export genera il corpo TeX | `ExportController::buildFiles()` |
| **ULID** | identificatore ordinabile usato per blob e oggetti | `App\Support\Ulid` |
| **PhaseN / GNN** | fasi di sviluppo citate nei commenti (`Phase 25.C`, `G22.S15`) | `docs/PHASES.md` |
