# Service directory

Mappa di `app/Services/` per rispondere a "**quale service gestisce la feature X?**". Le descrizioni vengono dal docblock di ogni classe. I service sono chiamati dai Controller (`app/Controllers/`, vedi `docs/ROUTES.md`) e contengono la business logic (no ORM).

> Aggiornare a mano quando si aggiunge/rinomina un service: non c'è un
> generatore, a differenza di `docs/ROUTES.md`. I summary one-line riflettono
> il docblock al 2026-06-11, integrati il 2026-09-04 e il 2026-09-23 con i
> servizi nati dopo (audit, curriculum e sezioni, studente, poi Contenuti/,
> Institutes/, Ops/, Study/ e altri tre file alla radice).

## Sottodomini (`app/Services/<Dir>/`)

| Cartella | Cosa fa | File chiave |
|----------|---------|-------------|
| **Crypto/** | Envelope encryption per docente (KEK/DEK), recovery, Shamir | `TeacherCryptoService`, `EncryptedBlobStore`, `TeacherRecoveryService`, `ShamirSecretSharing` |
| **Waf/** | Stack WAF applicativo: edge context, GeoIP, brute-force, PoW, regole, CrowdSec, log; `WafConfigRepository`/`WafSecurityRepository` sono repository e stanno in `app/Repositories/Waf/`, non qui | `WafRulesService`, `WafBruteforceGuard`, `WafProofOfWork`, `GeoIpService`, `EdgeContext`, `WafCrowdSecBouncerService`, `WafLogService`, `WafScoringService`, `WafSessionService` |
| **Security/** | Sanitizzazione e auth aggiuntiva: HTML/SVG sanitizer, TikZ script validator, HIBP, TOTP | `HtmlSanitizer`, `SvgSanitizer`, `TikzScriptValidator`, `HibpService`, `TotpService` |
| **Gdpr/** | Consensi, cancellazione, takedown, ToS, consenso genitori (Art. 8) | `ConsentService`, `DeletionRequestService`, `TakedownRequestService`, `TosAcceptanceService`, `ParentConsentService` |
| **Risdoc/** | Documenti risdoc: permessi, risoluzione dei modelli, scadenza delle bozze, review (+ sottocartella `Pt/`); i repository (`CompilationRepository`, `OverrideRepository`, `InstitutionalOverrideRepository`, `CurriculumDataRepository`, `RisdocTemplateRepository`) stanno in `app/Repositories/Risdoc/`, non qui | `Permission`, `TemplateResolver`, `ReviewFlow`, `ScadenzaDelleBozze`, `SpazzataDelleBozze`, `CompilationScrubber` |
| **TexBuilder/** | Assemblaggio multi-file TeX: badge, placeholder, body esercizi (`Formatter.php`, senza chiamanti, tolto il 23/9/2026) | `BuildResult`, `PlaceholderResolver`, `BadgeRenderer`, `BadgeStyle*`, `EserciziBodyRenderer` |
| **TexCompile/** | Client verso il servizio di compilazione TeX/SVG (VPS separato) | `TexCompileClient`, `TexFormatClient`, `SvgToPdfClient`, `TikzRenderClient` |
| **Tex/** | Helper escaping TeX | `TexEscape` |
| **Tikz/** | Render TikZ + workspace/override template docente | `TikzRenderService`, `TeacherTemplateOverridesService`, `TeacherTemplateWorkspaceService` |
| **GeoGebra/** | Catalogo GeoGebra + preprocessing TeX | `GeoGebraCatalogService`, `GeoGebraTexPreProcessor` |
| **PdfImport/** | Pipeline estrazione esercizi da PDF via LLM vision (multi-provider) | `ExtractionPipeline`, `FigureExtractor`, `ContractMapper`, `ExerciseInserter`, `DifficultyRefiner`, `LlmCache`, `LlmAuditLog`, `JsonConfigStore` |
| **Verifica/** | Documenti "verifica": store template, compile job, documento, standard | `VerificaDocumentService`, `VerificaCompileJobService`, `VerificaTemplateStandard`, `TemplateFileStore` |
| **Contract/** | Aggregato "contract" (schema contenuti): validazione schema, resa testuale; `ContractRepository`/`ContentVersionRepository` sono repository e stanno in `app/Repositories/Contract/`, non qui | `ContractAggregate`, `ContractSchemaValidator`, `TestoDiPaginaResa` |
| **Maps/** | Mappe cifrate: blob store, permessi, URL firmati | `MapBlobStore`, `MapPermissionService`, `MapSignedUrlService` |
| **Drive/** | Integrazione Google Drive: client, alberatura, sync mappe/verifiche | `DriveClient`, `FolderTreeBuilder`, `MapSyncService`, `VerificaSyncService` |
| **Rendering/** | Helper di rendering (tipi colonna) | `RmColumnTypes` |
| **Sharing/** | Policy contenuti condivisi | `SharedContentPolicy` |
| **Shortcuts/** | Scorciatoie LaTeX (modello forkabile) | `LatexShortcutsService` |
| **GitHub/** | Sync verso GitHub | `GitHubSyncService` |
| **Audit/** | Registri append-only: operazioni HTTP (anche studenti), azioni sui contenuti, impronte di IP (con chiave, `App\Support\ImprontaIp`) e UA, catena di impronte | `ActivityLogger`, `ContentActionLogger`, `RequestFingerprint`, `AuditChain` |
| **Student/** | Profilo dello studente | `StudentProfileService` |
| **Contenuti/** | Dove vale un contenuto o una verifica (ADR-037), copie indipendenti, spostamento di classe | `DoveVale`, `DoveValeVerifica`, `CopiaIndipendente`, `CopiaVerifica`, `SpostamentoDiClasse`, `MaterialiSuSezioniNonAmmesse` |
| **Institutes/** | Ruolo di amministratore di istituto, solo scenario 3 (ADR-040) | `AmministratoreDiIstituto` |
| **Ops/** | Diagnostica operativa: unità systemd installate, contenuti raggiungibili, conservazione dichiarata, segnalazioni di violazione | `UnitaInstallate`, `ContenutiRaggiungibili`, `ConservazioneDichiarata`, `SegnalazioniDiViolazione` |
| **Study/** | Rendering delle pagine di studio pubbliche/guest: elenco topic, pagina del topic, regola di visibilità pubblica | `StudyPageRenderer`, `PublicContentPolicy`, `ChiStudia`, `MaterieConMateriali` |

## Service root (`app/Services/*.php`)

| File | Cosa fa (docblock) |
|------|--------------------|
| `AclPolicy.php` | Policy centralizzata ACL teacher / super-admin / pool |
| `AdminAnalyticsService.php` | Admin analytics |
| `AdminNotificationsService.php` | Aggrega counters e badge per la dashboard admin |
| `AnomalyDetectionService.php` | Anomaly detection (porting modernizzato) |
| `AvvisoIncarichiTolti.php` | Chi perde l'accesso quando un incarico su una classe viene tolto: avviso all'amministratore prima di confermare, email al docente dopo (ADR-041, ADR-043) |
| `BlockList.php` | Accesso read-only alle block list legacy |
| `CheckService.php` | Endpoint "check" (password admin, file protection pattern) |
| `ContractRenderer.php` | Renderer JSON contract → HTML moderno |
| `CurriculumService.php` | Catalog curriculum per istituto |
| `FileService.php` | Operazioni filesystem con check path/estensione/size |
| `HashGenerator.php` | Genera hash bcrypt per `admin_users.json` |
| `InfrastructureMonitorService.php` | Metriche infrastrutturali per dashboard super-admin |
| `InstituteMergeService.php` | Deduplicazione/merge istituti (boundary tenant) |
| `LogRotator.php` | Log rotation size-based |
| `LogTailer.php` | Streaming ultime N righe di un log |
| `Mailer.php` | Invio email via Resend API (transactional) |
| `MiurAdozioniImporter.php` | Importa indirizzi, sezioni e materie di un istituto dal dataset MIUR delle adozioni (2026-09) |
| `MiurSchoolsService.php` | Ricerca scuole MIUR (server-side) |
| `OwnershipService.php` | Mappa i path contenuto (mappe/eser/lab/verifiche) al docente proprietario |
| `ParentConsentMailer.php` | Wrapper Mailer per workflow consenso genitori (Art. 8 GDPR) |
| `PhpContentParser.php` | Parser PHP content legacy → JSON contract moderno |
| `PrintInfoService.php` | Service modernizzato per print_info |
| `RateLimitStore.php` | Storage-agnostic rate limit state |
| `RateLimiter.php` | Rate limiter session-backed (semantica legacy) |
| `RegistrationMailer.php` | Wrapper Mailer per eventi del registration flow |
| `RegistrationPolicy.php` | Classi ammesse all'iscrizione (ADR-028 Fase 1) |
| `RegistrationService.php` | Pipeline self-signup |
| `SenzaScuola.php` | Il docente che non ha indicato nessuna scuola (ADR-047: la scuola è facoltativa) |
| `SezioniDeiDocenti.php` | Chi dei docenti di un istituto può legarsi a una classe, secondo la modalità dell'istituto: tutti, solo incaricati, nessuno (ADR-041, ADR-043) |
| `TeacherCapabilityPolicy.php` | Capabilities per-docente (ADR-028 Fase 2/3) |
| `TeacherSectionService.php` | Incarichi docente → sezione decisi dall'amministratore e classe degli studenti (migration 099, 2026-09) |
| `TeacherSubjectService.php` | Materie del docente: assegnate dall'admin o dichiarate al primo accesso (2026-09) |
| `TexBuilder.php` | Produce un BuildResult TeX multi-file |
| `TikzElementsService.php` | CRUD dei modelli TikZ/LaTeX predefiniti sul JSON d'istanza `storage/data/modelli_tikz_elements.json` |
| `TikzService.php` | Storage elementi TikZ/LaTeX (sostituisce 3 endpoint legacy) |
| `VerificheService.php` | Endpoint verifiche |

## Vedi anche

- `app/Services/README.md` — guida nella cartella codice (questo doc + convenzioni).
- `app/Services/Waf/README.md`, `app/Services/Crypto/README.md` — guide dei sottodomini security-critical.
- `docs/ROUTES.md` — quale endpoint chiama quale controller (→ service).
- `ARCHITECTURE.md` — quadro generale.
