# Service directory

Mappa di `app/Services/` per rispondere a "**quale service gestisce la feature X?**". Le descrizioni vengono dal docblock di ogni classe. I service sono chiamati dai Controller (`app/Controllers/`, vedi `docs/ROUTES.md`) e contengono la business logic (no ORM).

> Aggiornare a mano quando si aggiunge/rinomina un service. I summary one-line riflettono il docblock al 2026-06-11.

## Sottodomini (`app/Services/<Dir>/`)

| Cartella | Cosa fa | File chiave |
|----------|---------|-------------|
| **Crypto/** | Envelope encryption per docente (KEK/DEK), recovery, Shamir | `TeacherCryptoService`, `ClasseKeyService`, `EncryptedBlobStore`, `TeacherRecoveryService`, `ShamirSecretSharing` |
| **Waf/** | Stack WAF applicativo: edge context, GeoIP, brute-force, PoW, regole, CrowdSec, log | `WafConfigRepository`, `WafRulesService`, `WafBruteforceGuard`, `WafProofOfWork`, `GeoIpService`, `EdgeContext`, `WafCrowdSecBouncerService`, `WafLogService` |
| **Security/** | Sanitizzazione e auth aggiuntiva: HTML/SVG sanitizer, TikZ script validator, HIBP, TOTP | `HtmlSanitizer`, `SvgSanitizer`, `TikzScriptValidator`, `HibpService`, `TotpService` |
| **Gdpr/** | Consensi, cancellazione, takedown, ToS, consenso genitori (Art. 8) | `ConsentService`, `DeletionRequestService`, `TakedownRequestService`, `TosAcceptanceService`, `ParentConsentService` |
| **Risdoc/** | Documenti risdoc: permessi, compilazioni, override istituzionali/docente, curriculum, review (+ sottocartella `Pt/`) | `Permission`, `CompilationRepository`, `TemplateResolver`, `OverrideRepository`, `InstitutionalOverrideRepository`, `CurriculumDataRepository`, `FormRenderer`, `ReviewFlow` |
| **TexBuilder/** | Assemblaggio multi-file TeX: badge, formatter, placeholder, body esercizi | `BuildResult`, `Formatter`, `PlaceholderResolver`, `BadgeRenderer`, `BadgeStyle*`, `EserciziBodyRenderer` |
| **TexCompile/** | Client verso il servizio di compilazione TeX/SVG (VPS separato) | `TexCompileClient`, `TexFormatClient`, `SvgToPdfClient`, `TikzRenderClient` |
| **Tex/** | Helper escaping TeX | `TexEscape` |
| **Tikz/** | Render TikZ + workspace/override template docente | `TikzRenderService`, `TeacherTemplateOverridesService`, `TeacherTemplateWorkspaceService` |
| **GeoGebra/** | Catalogo GeoGebra + preprocessing TeX | `GeoGebraCatalogService`, `GeoGebraTexPreProcessor` |
| **PdfImport/** | Pipeline estrazione esercizi da PDF via LLM vision (multi-provider) | `ExtractionPipeline`, `FigureExtractor`, `ContractMapper`, `ExerciseInserter`, `DifficultyRefiner`, `LlmCache`, `LlmAuditLog`, `JsonConfigStore` |
| **Verifica/** | Documenti "verifica": store template, compile job, documento, standard | `VerificaDocumentService`, `VerificaCompileJobService`, `VerificaTemplateStandard`, `TemplateFileStore` |
| **Contract/** | Aggregato "contract" (schema contenuti): repository, validazione schema, versioning | `ContractAggregate`, `ContractRepository`, `ContractSchemaValidator`, `ContentVersionRepository` |
| **Maps/** | Mappe cifrate: blob store, permessi, URL firmati | `MapBlobStore`, `MapPermissionService`, `MapSignedUrlService` |
| **Drive/** | Integrazione Google Drive: client, alberatura, sync mappe/verifiche | `DriveClient`, `FolderTreeBuilder`, `MapSyncService`, `VerificaSyncService` |
| **Rendering/** | Helper di rendering (tipi colonna) | `RmColumnTypes` |
| **Sharing/** | Policy contenuti condivisi | `SharedContentPolicy` |
| **Shortcuts/** | Scorciatoie LaTeX (modello forkabile) | `LatexShortcutsService` |
| **GitHub/** | Sync verso GitHub | `GitHubSyncService` |
| **Audit/** | Logger azioni sui contenuti | `ContentActionLogger` |

## Service root (`app/Services/*.php`)

| File | Cosa fa (docblock) |
|------|--------------------|
| `AclPolicy.php` | Policy centralizzata ACL teacher / super-admin / pool |
| `AdminAnalyticsService.php` | Admin analytics |
| `AdminNotificationsService.php` | Aggrega counters e badge per la dashboard admin |
| `AnomalyDetectionService.php` | Anomaly detection (porting modernizzato) |
| `BlockList.php` | Accesso read-only alle block list legacy |
| `CheckService.php` | Endpoint "check" (password admin, file protection pattern) |
| `ContractRenderer.php` | Renderer JSON contract → HTML moderno |
| `CurriculumService.php` | Catalog curriculum per istituto |
| `DsaService.php` | Manipolazione checkbox/attributi DSA su verifiche & esercizi HTML |
| `FileService.php` | Operazioni filesystem con check path/estensione/size |
| `HashGenerator.php` | Genera hash bcrypt per `admin_users.json` |
| `InfrastructureMonitorService.php` | Metriche infrastrutturali per dashboard super-admin |
| `InstituteMergeService.php` | Deduplicazione/merge istituti (boundary tenant) |
| `LogRotator.php` | Log rotation size-based |
| `LogTailer.php` | Streaming ultime N righe di un log |
| `Mailer.php` | Invio email via Resend API (transactional) |
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
| `TableUpdateService.php` | Mutazioni DOM tabelle su esercizi/verifiche HTML |
| `TeacherCapabilityPolicy.php` | Capabilities per-docente (ADR-028 Fase 2/3) |
| `TexBuilder.php` | Produce un BuildResult TeX multi-file |
| `TikzElementsService.php` | Indici JSON per-gruppo per il picker TikZ |
| `TikzService.php` | Storage elementi TikZ/LaTeX (sostituisce 3 endpoint legacy) |
| `VerificheService.php` | Endpoint verifiche |

## Vedi anche

- `app/Services/README.md` — guida nella cartella codice (questo doc + convenzioni).
- `app/Services/Waf/README.md`, `app/Services/Crypto/README.md` — guide dei sottodomini security-critical.
- `docs/ROUTES.md` — quale endpoint chiama quale controller (→ service).
- `ARCHITECTURE.md` — quadro generale.
