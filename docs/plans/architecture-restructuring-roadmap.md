# Ristrutturazione architetturale — roadmap & audit

> Generato dal workflow `content-visibility-policy-audit-impl` (17 agenti, audit 3-dimensioni +
> implementazione pilota + verify avversariale), 2026-05-30. Strategia: **strangler-fig**, mai big-bang.

## I 3 debiti (misurati)

| # | Debito | Misura | Rischio |
|---|---|---|---|
| 1 | Visibilità/accesso "per convenzione" | 21 punti decisionali sparsi | Leak bozze/contenuti altrui (GDPR minori) |
| 2 | Multitenancy "per convenzione" | **47** query con scoping `institute_id` (2 HIGH, 10 MEDIUM) | Leak cross-istituto |
| 3 | God table `teacher_content_data` | 38 colonne (12 crypto + 7 map + …) | Bloat hot-path su 3G |

---

## #1 ContentVisibilityPolicy — ✅ IMPLEMENTATO (pilota, in questo commit)

Gate unico `App\Domain\ContentVisibilityPolicy` + VO `App\Domain\ViewerContext` (immutabili, niente
SESSION/Auth/DB dentro: contesto esplicito). Compone `App\Domain\ContentVisibility` (stati) e `Role`.
**Behavior-preserving**: i 21 check audìti mappati a metodi della policy verbatim.

- Wiring student-facing (i percorsi che servono BYTE) → policy: `ContentStudyController` (study.json,
  single json, related-verifiche, scopedFilters, section gate), `TeacherContentController` (export gate
  + `findOwnedRow` ora unificato), `MapPermissionService`, `TeacherContentExporter` (GDPR: gate
  INTENZIONALMENTE non applicato — Art.15 esporta anche le bozze del titolare), `SharedContentPolicy`
  (adapter `aclReader`). `PoolController`: eligibility SQL lasciata inline per performance (O(n)
  round-trip altrimenti), parità documentata.
- Test: 20 unit policy / 76 assert PASS; `PublishScopeVisibilityTest` 3/3 (fixture allineata post-078).

### Divergenze EMERSE dall'audit (valore della centralizzazione)
- **DIV1 — FIX APPLICATO (sicurezza):** `relatedVerificaHtml` non applicava l'esclusione delle SEZIONI
  NASCOSTE → una verifica *published* in sezione con `visible_roles` senza `student` era raggiungibile
  via "correlate" = leak di contenuto di sezione nascosta a uno studente. Aggiunto il section-gate
  (owner/all-scopes bypassano, come altrove). Unico cambio di comportamento deliberato (tightening).
- **DIV2 — DA DECIDERE:** asimmetria owner-archived (`relatedVerificaHtml` nega all'owner le proprie
  archiviate; `contentSingleJson` gliele mostra). Preservata verbatim; probabilmente non intenzionale.
- **DIV3 — DA DECIDERE:** `AclPolicy::canReadMaterialOfTeacher` (legacy) ignora i grant `content_shares`
  che `SharedContentPolicy::canReadContent` onora. Confermare che nessun path che serve byte usi il legacy.
- **DIV4 — DA DECIDERE:** `collaborator` ottiene see-all sul single fetch (role-string) ma nelle liste
  passa per `isTeacher()`: due derivazioni diverse di "see all", possibile drift. Probabilmente coerente.
- Note non-bloccanti: alias ruolo `admin`→`administrator` (0 utenti `admin` nel DB, coerente col
  contratto di `Role`); normalizzazioni `tryFromString` (lowercase/trim) wideniscono solo su dati
  malformati che il DB non produce.

### Prossimo su #1 (follow-up)
Decidere DIV2/3/4; assorbire i siti adiacenti non in scope (`VerificaController::listForStudent` +
`VerificaDocumentRepository` per la tabella `verifica_documents`; owner-equality in `MapPermissionService`).

---

## #2 Multitenancy — pilota gated FATTO (parziale) + resto da pianificare

**FATTO (commit successivo):** verificati i 2 HIGH e DIV2/3/4 con workflow gated `tenant-pilot-and-divergences`.
- **`AdminAnalytics` endpoints → FIX leak-close:** le 4 route (`/admin/analytics` + `/api/admin/analytics[/teacher/{id}|/cross-search]`) erano sotto `role:admin` ma servono aggregati **cross-istituto by-design** (top_institutes, top_authors, crossTeacherSearch su TUTTI i docenti, inclusi draft). Un `administrator` non-super le raggiungeva → leak. Avvolte in inner-group `super_admin_required` (pattern canonico risdoc/WAF), **query invariate** (vista globale intenzionale per super-admin). L'unico `administrator` nel DB è `super_admin=1` → zero impatto oggi, chiude il leak per futuri admin-istituto.
- **`MetricsController:130` → intentional-global** (solo super_admin): lasciato.
- **`AdminNotificationsService:164` → lasciato documentato:** marcato HIGH dall'audit ma intent ambiguo + rischio regressione (l'endpoint serve legittimamente anche admin non-super). NON toccato in autonomia: richiede decisione prodotto.

**Divergenze visibilità DIV2/3/4 → tutte `leave-documented` (nessun bug reale):**
- DIV2 asimmetria owner-archived: **intenzionale**, lockata nei test `ContentVisibilityPolicyTest`.
- DIV3 `AclPolicy::canReadMaterialOfTeacher`: era **codice morto** → **RIMOSSO** (commit `bb7375c`) insieme all'helper orfano `shareInstitute` + 3 test morti + 2 commenti stantii. Nessun cambio comportamento.
- DIV4 derivazione see-all collaborator: **semanticamente equivalente** (`Auth::role()` ≡ `Auth::user()['role']`), code-smell non-bug.

**Resto (da pianificare):** il grande retrofit `TenantAwareRepository` sui ~45 siti residui (MEDIUM + non-HIGH).

---

## #2b TenantAwareRepository (retrofit completo) — 📋 AUDITATO (da pianificare)

**47 siti** con scoping `institute_id`. **2 HIGH** (leak di aggregati cross-istituto, non riga-singola):
- `MetricsController.php:130-131` — `SELECT content_type, COUNT(*) FROM teacher_content GROUP BY …` senza filtro istituto/teacher.
- `AdminNotificationsService.php:164-165` — `SELECT COUNT(*) FROM teacher_content WHERE created_at > …` globale.

10 MEDIUM = boundary fragili via JOIN + mancate re-check sulle mutation.

**Raccomandazione (strangler):** `TenantAwareRepository` base che **auto-inietta** il filtro
`institute_id` in ogni query → isolamento STRUTTURALE, non developer-dependent. Fase 1: base class.
Fase 2: retrofit dei 47 siti. Fase 3: vincoli/indici DB su `(teacher_id, institute_id)`.
Pilota suggerito: i 2 HIGH (sono aggregati read-only → basso rischio, alto valore).

---

## #2c AdminNotifications — ✅ FATTO (scope tenant)
`AdminNotificationsService::newTeacherContent24h(?int $instituteId)` + `summary(?int)`: il count
24h è scopato all'istituto del viewer via `Auth::currentInstitute()` (null per super-admin → globale;
`admin_institute_id` per admin non-super → solo il proprio istituto). Idiomatico (stesso contratto di
`search()`/pool). Niente nuovo helper. `AdminController::notifications()` + dashboard passano lo scope.
Edge non-regressione: un super-admin "entrato" in un istituto vede quel count (contratto `currentInstitute()`
esistente). L'audit ha inoltre confermato che il **resto dei ~47 siti tenant è già scopato correttamente**
(PoolController/search per pivot) o **intentional-global super_admin-gated** (RisdocAdmin, AdminGdpr) →
**nessun `TenantAwareRepository` necessario ora** (foundation non costruita: sarebbe stata over-engineering).

## #3 Decomposizione God table — ⏸️ DEFER (decisione confermata dall'audit = ADR-028)

**Verdetto: NON farla ora.** Due audit indipendenti concordano:
- **Beneficio reale negligibile (<1% I/O):** `search()` (99% delle letture) **proietta già lean** — esclude
  tutte le 12 colonne crypto (`*_ct/_iv/_tag/_kv`) e le 7 `map_*`. I byte sul filo (3G) sono già minimi;
  resterebbe solo un guadagno di scan DB marginale.
- **Rischio alto e irreversibile:** ~130 righe **cifrate** live. Lo split richiede DELETE+re-encrypt →
  rischio di perdita dati. Le colonne crypto sono read-only (nessun write-path attivo) → esposizione bassa.
- **Interim (regola da mantenere):** ogni NUOVA query di lista/search deve continuare a **escludere**
  `*_ct/_iv/_tag/_kv` e `map_*` dalla projection. Rivalutare in Fase 4 (post backfill/rotation crypto validati).

## #2b TenantAwareRepository (retrofit completo) — ❌ NON necessario (vedi #2c)

`teacher_content_data` (38 col) → **3 tabelle** (1:1), per sgonfiare gli hot-path lista/3G:
1. `teacher_content_meta` — ~14 col core (id, teacher_id, subject/indirizzo/classe/section_id, topic,
   title, visibility, publish_scope, shared_with_pool, source_content_id, timestamps). <500B/riga → una
   page-read per le liste.
2. `teacher_content_body` — body_html + i 12 campi crypto + metadata. Letta SOLO sul detail (FK 1:1).
3. `teacher_content_map` — i 7 campi `map_*`, solo per le mappe.

La VIEW `teacher_content` ricompone via JOIN (retro-compat). Le liste selezionano solo `*_meta`
(niente più crypto/map trascinati). `content_format` (generata) resta su `_meta` per il filtro.
**Rischio alto** (tocca crypto/dual-write) → ultimo, dietro contract-test + golden GDPR.

---

## Abilitatori (Fase 4) — stato

- **B ✅ FATTO (commit 2efeaf9):** migration tracciate di default (`.gitignore` whitelist `!database/migrations/*.sql`). Rimosso il footgun del force-add manuale.
- **C ✅ FATTO (commit 2efeaf9 + 4bf70af):** endpoint pubblici `/version` (git sha; legge `storage/version.txt` scritto dal deploy perché www-data non legge `.git/refs` pantedu:pantedu 0660) + `/health` (DB + migration applied/pending, 200/503). Verificati in prod.
- **A1 ✅ FATTO (commit 75f62cc + PublishScope):** sistemate le fixture di test rotte dalla migration 079 (`content_type`→`content_subtype` nelle INSERT SQL dirette: InstituteMergeServiceTest, PublishScopeVisibilityTest).
- **A ⚠️ RIVALUTATO — è un PROGETTO, non un fix rapido.** La suite COMPLETA è **743 test, ~79 rossi** (la "baseline 15" era solo il sottoinsieme filtrato). Cause radice diverse, tutte di *setup/isolamento test*, NON regressioni di prod:
  - **Crypto (32):** girano sul DB dev condiviso → `kek_regen_guard`, `classe_keys` è una VIEW non-updatable, KMS non configurato. Servono **DB di test isolato** + seed crypto. (Il flag `ALLOW_CRYPTO_REGENERATE` sul DB condiviso è PERICOLOSO → scartato.)
  - **TeX/Print (33):** `template_missing:versioni/main_NOR.tex` ecc. → fixture template di test + skip-se-pdflatex-assente.
  - **Registration/Curriculum/Risdoc (14):** `institutes_required` ecc. → seed istituti/curriculum nel DB di test.
  - **Keystone — ✅ FATTO (DB di test isolato):** `app/Config/database.php` in `APP_ENV=testing` (solo phpunit, rilevato via `getenv()` che sopravvive ai reload `.env` dei setUp) seleziona **`pantedu_test`** invece di `pantedu_dev` → la suite **non tocca mai i dati dev/prod** (verificato: in contesto normale resta `pantedu_dev`). `tools/setup_test_db.php` provisiona il DB (CREATE + migrazioni, idempotente). Effetto: **81 → 68 rossi, 36 skip**, e — soprattutto — **fine del rischio** che i test crypto cancellino/rigenerino chiavi sul DB dev (prima `kek_regen_guard` su 130 righe dev; ora DB pulito).
  - **Onda 1 — Crypto ✅ (25 test verdi):** `TeacherContentDualWrite` (7), `EncryptionFullFlow` (3), `KekRotation` (5), `TeacherCryptoIntegration` (10) ora **verdi** (prima: skip/errore). Fix: (a) seed baseline (`superadmin`/`marco.rossi`) in `setup_test_db.php`; (b) **cleanup per-test** del contenuto cifrato del teacher condiviso (azzera righe/blob → il guard `kek_regen` passa naturalmente, niente flag — il flag `ALLOW_CRYPTO_REGENERATE` è inutile perché il reload `.env` dei setUp lo riporta a 0); (c) **DELETE/UPDATE su base table** invece che sulle VISTE non-aggiornabili (`teacher_content`→`teacher_content_data`).
  - **ClasseKeyServiceTest (5 residui):** quirk PIÙ PROFONDO, non fixture: `ClasseKeyService::getOrCreateActiveKey` chiama `idFromCode(..., institute=null)` che cerca `curriculum_entries.institute_id IS NULL`, ma la colonna è **NOT NULL** → il path null-institute non risolve mai → `indirizzo_id` NULL → non-idempotente. Possibile **bug latente del servizio** (null-institute morto post-migrazione), da valutare a parte (service/schema, non test).
  - **Onda 2 — Template TeX ✅ (~19 verdi):** i template LaTeX `storage/templates/verifiche/_default/` (main_*.tex, texCommon, griglie) **mancavano nel repo** (esistevano solo sul VPS, dati di deployment) → `TexBuilder` lanciava `template_missing`. Recuperati dal VPS (23 file, 180K, no PII) e **committati** (commit a1ff47e). Override per-docente `t_*/` gitignored. TexBuilder/RmTable/LayoutModes/FullCycle da ~24 rossi a ~6.
  - **Onda 3 — Fixture/seed ✅:** `setup_test_db.php` ricetta funzionante (schema-copy dev + reference data: institutes/curriculum/sidebar/risdoc + seed users) → RisdocSeed/SidebarSection verdi. `RegistrationServiceTest` fixture (institute_ids+accept_tos Phase 13) → 10/10. Ownership verde (in isolamento).

  **STATO: 81 → 30 rossi (−63%).** Crypto protetto, template committati, suite molto più affidabile.

  **30 RESIDUI classificati (richiedono refactor/giudizio, NON quick-win):**
  - **Pollution-flaky (~6: Ownership, PublishScope):** passano in isolamento, falliscono nella suite (ordine random + stato DB condiviso). Fix vero = **isolamento per-test transazionale** (wrap ogni test in transaction+rollback) — framework change.
  - **Test stale (16: CurriculumService 7, RisdocResolver 3, TeX-assertion 6):** testano API/output non più attuali — `CurriculumService` ha cambiato firma (`add` richiede institute_id; `update`/`remove`→`updateById`/`removeById`); le assertion TeX si aspettano larghezze tabularx/“Vero/Falso” diverse dai template attuali. Serve **riscrittura assertion** (rischio: mascherare bug → da fare con giudizio).
  - **Quirk service (6: ClasseKeyServiceTest):** bug latente reale — `getOrCreateActiveKey` usa `idFromCode(institute=null)` che cerca `curriculum_entries.institute_id IS NULL` su colonna NOT NULL → non-idempotente. Fix = **service/schema** (delicato, crypto classi).
  - **Misc (1: AdminPrint).**

  **Onda 4 — skip dei casi non-fixabili-in-sicurezza:**
  - `CurriculumServiceTest` (7) → **SKIP**: obsoleto, testa l'API file-based legacy (read/write JSON, update/remove by-code) sostituita da quella DB (all() legge DB, add() richiede institute_id, updateById/removeById). Codice prod OK, test superato → da riscrivere sulla API DB.
  - `ClasseKeyServiceTest` (8) → **SKIP con BUG NOTO**: `getOrCreateActiveKey` passa SEMPRE `institute=null` a `idFromCode`, che cerca `curriculum_entries.institute_id IS NULL` su colonna **NOT NULL** → indirizzo non risolto → `indirizzo_id` NULL → chiavi non-idempotenti. **Bug latente di PRODUZIONE** (non solo test), latente perché `published_content` (cifrata con classe_key) è vuota. ⚠️ Da valutare: la crypto delle classi non è idempotente. Fix service/schema delicato — NON toccato in autonomia.

  **FLAKINESS (scoperta):** la suite è instabile (rossi rimbalzano ~26-31 tra run, 26 con `--order-by=default` deterministico) per **pollution + `executionOrder=random`**: test come `OwnershipServiceTest` passano in isolamento ma falliscono nella suite (un test precedente lascia stato che un altro legge).

  **Keystone-2 (isolamento transazionale) — TENTATO e REVERTITO (con rete di sicurezza):** creato un trait `IsolatesDbState` (`#[Before]` beginTransaction / `#[After]` rollback) e applicato a 7 test mutanti + measure. Risultato: **26 → 31 rossi (+5)** → revert. **Gotcha documentati per il prossimo tentativo:**
  - `InstituteMergeServiceTest`/`SidebarSectionRepositoryTest` **auto-gestiscono già** la transazione (`beginTransaction` in setUp) → il trait causa nesting (PDO esplode). Vanno ESCLUSI.
  - ~5 test non-DML-puri (commit interni / `Database::reset()` / stato atteso cross-boundary) si rompono col wrapping.
  - Lezione: NON applicabile a tappeto. Serve **analisi per-test** (solo DML-puri, niente self-managing, niente DDL/commit) + forse un `Database::reset()`-aware begin. Keystone dedicato e misurato, non veloce.

  **Rossi genuini residui (~15, documentati, NON skippati per non nascondere problemi reali):** RisdocResolver (3, conflitto reference-data: 15 template globalmente visibili vs test che si aspetta 0), TeX residuo (6, rendering VF/tabularx non come da assertion — investigare se bug builder o drift template), PublishScope (3, il contenuto creato non matcha lo scope per la reference-curriculum), AdminPrint (1), + flaky Ownership/PublishScope.

  **Onda 5 — i 3 "prossimi passi" affrontati:**
  - **fix ClasseKey ✅ (commit 5e2c072):** corretti **2 bug latenti di PRODUZIONE** nella crypto delle classi — (1) non-idempotenza (`idFromCode(null)` su path legacy vuoto → `resolveCurriculumId`), (2) `class_key_unwrap_failed` da CKEK case-inconsistente (wrap raw vs unwrap canonico → canonicalizzazione in wrap/rotate). 8 test verdi.
  - **riscrittura test stale ✅:** `CurriculumServiceTest` riscritto da file-based (morto) a API DB con istituto di test isolato → 10/10 verde.
  - **keystone-2 ✅ (vera causa trovata):** NON era isolamento transazionale (tentato, rotto, revertito). La flakiness di `OwnershipServiceTest` (verde in isolamento, rosso in suite) è **stato globale di Config**: `Config::load` NON ha guard + cache statica `self::$items`; un test che carica `.env (DB_ENABLED=true) + Config::load` lascia `database.enabled=true` globalmente → `OwnershipService::useDb()` passa in DB mode ignorando il file del test. Fix: il test forza file mode (`DB_ENABLED=false` + `Config::load`) in setUp. **Pattern generale per i test mode-dipendenti.**

  **Bilancio aggiornato: 81 → 14 rossi (0 errori), deterministico stabile.** I 14 residui sono ora SOLO: RisdocResolver (3, reference-data: 15 template visibili vs test che vuole 0), PublishScope (3, risoluzione reference-curriculum del teacher seed), TeX residuo (6, rendering VF/tabularx vs assertion), Print (2, pdflatex). Conflitti reference-data / drift template — investigazione caso-per-caso. Suite mai sui dati dev/prod; ogni rosso è un problema reale tracciato.

## Cosa NON fare
Rewrite della crypto envelope; cambio engine/framework/frontend; ORM pesante. Solo strangler
incrementale, un context alla volta, con golden/contract-test prima e rimozione del vecchio dopo.
