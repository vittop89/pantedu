---
tags:
  - documentazione/database
date: 2026-09-23
tipo: database
status: finale
aliases: ["database", "schema", "db"]
cssclasses: []
---

# Database Schema

MariaDB (10.11 in sviluppo/E2E, 11.8 in produzione), charset
`utf8mb4_unicode_ci`; le migrazioni usano sintassi MariaDB, non
intercambiabile con MySQL (`ADD COLUMN IF NOT EXISTS`, voce 69 del debito).
Base in `database/schema.sql` (`grep -c '^CREATE TABLE' database/schema.sql`
per contare le tabelle) più le migrazioni in
`database/migrations/NNN_nome.sql` (`ls database/migrations | wc -l`)
applicate da `tools/migrate.php`
(`App\Core\Migrator`: tracking in `schema_migrations`, advisory lock,
statement letti davvero, prefissi doppi rifiutati). Tre utenti DB:
applicativo, migrazioni (DDL, trigger), manutenzione (purga).

Questa pagina è a granularità di tabella; le colonne si leggono in
`schema.sql` e nella migrazione che le introduce (numero fra parentesi).

## Viste sulle tabelle `*_data`

Sei entità sono tabelle `<nome>_data` con una vista `<nome>` sopra
(migrazioni 036-041, «codici canonici»): la vista risolve gli id di
indirizzo/classe/materia nei codici testuali e i trigger `trg_*_sync_codes_bi/bu`
tengono sincronizzate le colonne di codice a insert/update. Il codice
applicativo legge e scrive la vista. Erano otto: `published_content_data` e
`classe_keys_data`, con le viste `published_content` e `classe_keys`, sono
state tolte dalla migrazione 120 (ADR-037, fasi 4a-4b — nessun codice le usa
più, sorvegliato da `tests/Unit/TabelleInPensioneTest.php`).

| Vista | Tabella | Contenuto |
|---|---|---|
| `teacher_content` | `teacher_content_data` | contenuti del docente (mappe, esercizi, lab, verifiche, documenti PT): body cifrato, `content_type` + `content_subtype` + `content_format` (078/079), `publish_scope` (069), `section_id` (071), `source_type` (058); la tabella ha ~38 colonne (roadmap: god table). Dalla 121 `indirizzo_id`, `classe_id`, `subject_id` e le sigle vengono dalla pubblicazione principale; dalla 123 la tabella non ha più quelle colonne (ADR-037, fase 4c) |
| `verifica_documents` | `verifica_documents_data` | documenti verifica: TEX/PDF cifrati, `tex_sha256` (030), `tex_files` (031), `shared_with_pool` (045). Dalla 121 `materia_id`, `indirizzo_id`, `classe_id` e le sigle vengono dalla principale; dalla 123 la tabella tiene solo `materia_id` (chiave dell'indice unico con docente, titolo, variante e versione) |
| `risdoc_compilations` | `risdoc_compilations_data` | compilazioni dei modelli risdoc, cifrate dal 2026-09-04 (101) |
| `exercises` | `exercises_data` | esercizi legacy pre-Phase 18, sola lettura ([[technical-debt]]) |
| `print_info` | `print_info_data` | configurazioni di stampa delle verifiche |
| `teacher_access_credentials` | `teacher_access_credentials_data` | credenziali di classe create dal docente |

## Tabelle per area

| Area | Tabelle | Note |
|---|---|---|
| Identità | `users`, `institutes`, `teacher_institutes`, `registration_allowed_classes` (087), `teacher_sections` (099), `spid_cie_identities` (066), `sessions` | `users`: `role`, `is_super_admin`, `institute_id`, `admin_institute_id` (059), `deleted_at` (016), `birth_date` (017), TOTP (055), `must_change_password` (085), scope studente (091). Le domande di iscrizione in attesa non sono in una tabella: stanno nel file `auth.paths.registrations` ([[domains/auth/auth-overview]], «Registrazione»). La tabella `registrations` di `database/schema.sql` non la scrive e non la legge nessun codice (misurato il 24/9/2026) |
| Secondo fattore e password | `two_factor_email_codes` (096), `password_resets` (095), `email_change_requests` (131) | metodo 2FA per utente (097); il cambio email vuole la password, un link monouso al nuovo indirizzo e un avviso al vecchio (`app/Services/Security/CambioEmail.php`) |
| Documenti legali | `user_tos_acceptance` (056), `legal_document_versions`, `legal_version_notifications` (094) | allineate a `docs/legal/versions.json` da `tools/legal/sync_versions.php` |
| Curriculum | `curriculum_entries` (kind `indirizzi`/`classi`/`materie`, scope istituto 036, `owner_user_id` 042-044 — le copie per docente, tolte dalla 116: ADR-035 —, `indirizzo_id` sulle classi 100, `origine` 113, unica su `(kind, code, institute_id)` dalla 116), `curriculum_teacher` (113: la spunta del docente su una voce della scuola, con `active`, `shared_with_pool`, `label_override`), `adozioni_libri` (115: i libri in adozione per istituto, ADR-036), `risdoc_curriculum_data` (067) | vocabolario fissato dalla scuola, importato dal dataset MIUR |
| Contenuti | `teacher_content` (vista), `content_publications` (117), `content_versions`, `content_shares`, `share_groups`, `share_group_members` (046), `teacher_category_labels` (082), `storage_objects`, `ownership`, `institute_pool_policy` | `content_versions` esisteva solo in `schema.sql` fino alla 098. `content_publications` (ADR-037, fase 1): un contenuto in una scuola, in una terna, con uno stato; una principale per contenuto (`primary_of_tc`, chiave unica); `origine` dice se è derivata dalla riga (`riga`, la principale), dai bersagli di «per più classi» (`bersaglio`, fino alla 118; la tabella `content_target_classes` l'ha tolta la 120) o scelta dal docente (`docente`, che nessun ricalcolo tocca). Dalla 118 i trigger ricalcolano solo la principale (i bersagli sono diventati pubblicazioni del docente, che gestisce da «Dove vale»: `App\Services\Contenuti\DoveVale`). Le derivate le scrivono i trigger `trg_pub_tc_ai`/`trg_pub_tc_au` (riga) (e fino alla 120 `trg_pub_ctc_ai`/`_au`/`_ad` sui bersagli) tramite la procedura `pub_ricalcola_contenuto`; `pub_verifica_allineamento` fallisce se una riga non coincide (la usano la migrazione e `tools/ops/diagnostica.php`). Chi decide chi vede un contenuto legge da qui (`App\Support\Pubblicazioni`), non dalle sigle della riga. Dalla 121 (ADR-037, fase 4c) la principale la scrive l'applicazione (`App\Support\PostoPrincipale`), con le stesse regole; dalla 122 solo lei: i trigger e le procedure di ricalcolo non ci sono più, le colonne della riga non si scrivono, e la 123 le ha tolte (della verifica resta la materia), e `pub_verifica_allineamento` controlla lo stato di ogni principale di contenuto, le pubblicazioni dai bersagli e la scuola di ogni pubblicazione rispetto alle sue voci. La guardia `trg_curriculum_no_orphan` conta anche le pubblicazioni. Dalla 119 (fase 3) ci sono anche le verifiche (`verifica_document_id`, principale unica con `primary_of_vd`): la principale la scrivono `trg_pub_vd_ai`/`trg_pub_vd_au` con `pub_ricalcola_verifica`, lo stato lo sceglie il docente (`App\Services\Contenuti\DoveValeVerifica`) e nessun trigger lo tocca |
| Sidebar | `sidebar_sections` (070), `sidebar_section_overrides`, `sidebar_section_teachers` (092-093) | ADR-027; blocchi delle categorie default/custom (083-084) |
| Verifiche | `verifica_documents` (vista), `verifica_compile_jobs` (033) | template packs droppati (032): i template vivono su filesystem (`TemplateFileStore`) |
| Risdoc | `risdoc_templates`, `risdoc_template_collaborators`, `risdoc_template_visibility` (013 `visibility_scope`), `risdoc_teacher_overrides` (006, 011 multi-istanza, 068 `texCommon`), `risdoc_institutional_overrides` (010), `risdoc_template_pending_changes` (047), `risdoc_compilations` (vista) | `owner_id` dei template droppata (G22.S26) |
| Mappe e sync | `map_shares` (020), `teacher_drive_oauth`, `teacher_drive_folder_cache` (019; stato del collegamento 124), `teacher_github_sync` (034) | refresh token cifrati a busta; il collegamento è attivo o da ricollegare, con motivo e data (ADR-038) |
| Crittografia | `teacher_keys` (012), `teacher_recovery_keys` (035), `crypto_access_log`, `crypto_custody_events` (061) | `KMS_MASTER_KEY` → KEK per docente |
| GDPR | `consents`, `consent_audit` (015), `parent_consents`, `deletion_requests`, `dpo_requests` (018), `takedown_requests` (057), `data_breach_incidents`, `subprocessors` (060, 062-063) | |
| Audit | `audit_activity_log` (098), `content_action_log` (065), `privileged_access_log`, `teacher_recovery_audit` (droppata in 037, ricreata in 098) | append-only via trigger `trg_append_only_*_update`; IP e UA come hash (100) |
| WAF | `waf_config`, `waf_logs`, `waf_rules`, `waf_blocked_ips`, `waf_whitelisted_ips`, `waf_blocked_credentials` (049), `waf_login_failures` (086), `waf_threat_ips`, `waf_threat_cidrs`, `waf_threat_sync_log` (052), `waf_asn_categories` (053) | seed regole bot (051), honeypot (054) |
| Capability | `teacher_capability_profiles`, `teacher_capability_overrides` (088) | ADR-028 |
| PDF-Import | `pdf_import_sessions` (089, stato `cancelled` 090) | file di sessione su disco, chiavi provider cifrate |
| Infrastruttura | `jobs`, `rate_limits`, `schema_migrations` | `rate_limits`: backend `db` del rate limiter, con l'IP in chiaro; `pantedu-rate-limit-cleanup.timer` toglie ogni giorno le righe più vecchie di un'ora |

Tabelle droppate nel tempo (per storia): `template_cache`, `teacher_exercises`,
`teacher_verifiche`, `curriculum_users` (044), `teacher_sidebar_sections`
(074), `verifica_templates`, `verifica_template_packs` (032).

## Migrazioni: mappa per tema

| Range | Tema |
|---|---|
| 001-011 | istituti, super-admin, pool, override risdoc, compilazioni, `schema_path`, `body_pt`, override istituzionali, multi-istanza |
| 012-020 | crittografia docente, visibility scope, chiavi di classe, consensi, cancellazione, minori, DPO, Drive, mappe |
| 021-035 | verifiche: documenti, template packs, varianti, versioni, sync Drive, sha256, tex_files, compile jobs; GitHub sync; recovery key |
| 036-044 | curriculum: scope istituto, FK canoniche, trigger dei codici, viste `*_data`, pulizia globali legacy, pivot per docente |
| 045-047 | pool e condivisioni granulari, review flow risdoc |
| 048-054, 086 | WAF (tabelle, credenziali bloccate, rDNS/ASN, regole bot, threat-intel, honeypot, login failures e proof-of-work) |
| 055-066 | TOTP, ToS/AUP, takedown, source_type, admin di istituto, governance GDPR, custodia crypto, subprocessor, backup, content_action_log, SPID/CIE |
| 067-084 | dati curricolari risdoc, sidebar dinamica (ADR-027), publish scope, section_id, collasso content_type (078-079), etichette categorie, blocchi categorie |
| 085-093 | cambio password forzato, classi ammesse, capability (ADR-028), PDF-Import, scope studente, sidebar docenti/pubblica |
| 094-103 | versioni legali, recupero password, 2FA email, copertura audit, sezioni docenti, hash IP/UA, classi con indirizzo, compilazioni cifrate, rimozione Modulo di autorizzazione, storage compilazioni per istituto |
| 104-116 | storico delle classi degli studenti e archivio (104), portachiavi delle credenziali di classe (105), extra JSON di print_info (106), cache CTI del WAF (107), ultimo accesso degli utenti (108), catalogo dell'istituto: relazione docente↔voce e `origine` (113), ri-puntamento dei contenuti alla voce della scuola (114), libri in adozione (115), rimozione delle copie per docente e di `owner_user_id`/`owner_key` (116) |
| 117-123 | pubblicazioni (ADR-037): via tabella propria, poi del docente, poi delle verifiche, tabelle in pensione (`published_content_data`, `classe_keys_data`), sigle dalla principale, trigger delle etichette, etichette dalla riga |
| 124-131 | stato del collegamento Drive (ADR-038, 124), amministratore di istituto (ADR-040, 125), sezioni dei docenti e scadenza materiali (126-127), rimozione del collaboratore (128), chi pubblica in rete (129), consensi mai presentati (130), cambio email con conferma (131) |
| 132-140 | anni per indirizzo e anni con incarico (ADR-042, 132-133), credenziali con username unico ed etichetta (134), esito WAF più largo (135), registro e segnalazioni degli incidenti (136-137), bozze a scadenza (ADR-046, 138), gettoni GDPR come hash (139), ultimo contatore TOTP (140) |

Questa mappa si ferma all'ultima migrazione che qualcuno si è preso la briga
di riassumere: il numero vero, sempre giusto, è `ls database/migrations |
wc -l`; una migrazione più recente di quelle elencate qui si legge dal suo
file.

Prefissi doppi storici: 036, 037, 038, 100 (due file ciascuno, entrambi
applicati; il Migrator li conosce e rifiuta ogni altro doppione).

## Repository layer

Tutti i repository stanno sotto `app/Repositories/<Dominio>/` (i pochi alla
radice sono di dominio unico); l'elenco completo, sempre aggiornato:
`find app/Repositories -name '*.php'`. Un'eccezione dichiarata,
[[decisions/ADR-049-confini-degli-strati]]: `JobRepository` sta in
`app/Jobs/JobRepository.php`, accanto al resto della coda.

| Repository | Cartella | Tabelle |
|---|---|---|
| `UserRepository` (+ `UserRepositoryInterface`) | `app/Repositories/` | `users` |
| `InstituteRepository` | `app/Repositories/` | `institutes`, `teacher_institutes` |
| `TeacherContentRepository` | `app/Repositories/` | `teacher_content` (cifra/decifra i body) |
| `TeacherCredentialRepository` | `app/Repositories/` | `teacher_access_credentials` |
| `SidebarSectionRepository` | `app/Repositories/` | `sidebar_sections*` |
| `VerificaDocumentRepository`, `VerificaCompileJobRepository` | `app/Repositories/` | `verifica_documents`, `verifica_compile_jobs` |
| `PdfImportSessionRepository` | `app/Repositories/` | `pdf_import_sessions` |
| `MapShareRepository`, `DriveOAuthRepository` | `app/Repositories/` | `map_shares`, `teacher_drive_oauth` |
| `StorageObjectRepository`, `ExerciseRepository` | `app/Repositories/` | `storage_objects`, `exercises` |
| `ContractRepository`, `ContentVersionRepository` | `app/Repositories/Contract/` | contract in `teacher_content`, `content_versions` |
| `AdozioniRepository` | `app/Repositories/Curriculum/` | `adozioni_libri` |
| `CurriculumTeacherRepository` | `app/Repositories/Curriculum/` | `curriculum_teacher` |
| `DataBreachRepository` | `app/Repositories/Gdpr/` | `data_breach_incidents` |
| `SubprocessorRepository` | `app/Repositories/Gdpr/` | `subprocessors` |
| `CompilationRepository`, `OverrideRepository`, `InstitutionalOverrideRepository`, `CurriculumDataRepository`, `RisdocTemplateRepository` | `app/Repositories/Risdoc/` | risdoc: compilazioni, override, dati curricolari, template |
| `PoolRepository`, `ShareGrantRepository` | `app/Repositories/Sharing/` | `content_shares`, `share_group_members` |
| `BadgeStyleRepository` | `app/Repositories/TexBuilder/` | file JSON, non DB |
| `WafConfigRepository`, `WafSecurityRepository` | `app/Repositories/Waf/` | `waf_*` |
| `JobRepository` | `app/Jobs/JobRepository.php` | `jobs` |

Molte tabelle non hanno un repository e sono interrogate direttamente da
controller e servizi: elenco fisso, sorvegliato da
[[decisions/ADR-049-confini-degli-strati]] ([[technical-debt]] voce 17).
