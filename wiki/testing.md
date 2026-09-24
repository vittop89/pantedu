---
tags:
  - documentazione/testing
date: 2026-09-23
tipo: testing
status: finale
aliases: ["testing", "test"]
cssclasses: []
---

# Testing

## Strategia

| Suite | Tool | Scope | Posizione | Stato |
|-------|------|-------|-----------|-------|
| Unit | PHPUnit 11 | classi singole; SQLite in memoria dove serve | `tests/Unit/` | verde. Nel controllo obbligatorio gira senza database e alcune prove si saltano (contale con `--display-skipped`): le fa girare il lavoro con il database, qui sotto |
| Integration | PHPUnit 11 | controller + servizi + MariaDB `pantedu_test` | `tests/Integration/` | verde con DB attivo; si saltano senza. **In CI dal 14/9/2026**: il lavoro «PHP: prove d'integrazione (MariaDB)» di `ci.yml` fa girare tutte e due le suite su MariaDB 11.8 seminato, e **un salto è un fallimento**. Non ancora obbligatorio (`wiki/dev-workflow.md`, «Il cancello su `main`») |
| JS unit | Vitest 4 (happy-dom) | moduli puri dell'editor e della sidebar | `tests/js-unit/` | verde |
| E2E | Playwright 1.59 (Chromium) | browser contro il server di sviluppo in WSL, `http://127.0.0.1:8765` | `tests/e2e/`, più l'infrastruttura in `support/` TypeScript | refactoring chiuso il 2026-09-07; **in CI gira intera** (senza `@tex`, `@pdflatex`, `@istanza`) dopo ogni unione e la domenica, su database seminato e **contro l'immagine del rilascio** (dal 2026-09-14, `docs/dev/ci-cd.md`) |

Il numero di test di ogni suite invecchia a ogni PR: si conta con
`vendor/bin/phpunit --list-tests-xml php://stdout`, `npx vitest list` o
`npx playwright test --list`, non a mano qui. Con il database seminato dalla
CI, il lavoro «PHP: prove d'integrazione (MariaDB)» fa girare tutte e due le
suite PHPUnit con `--fail-on-skipped`: quanti salti restano (per ambiente,
non per assenza di database) lo dice `--display-skipped` sullo stesso giro,
non un numero fissato qui.

## Comandi

```bash
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit                      # tutto
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --testsuite unit
composer test:unit                                                               # la stessa suite; zero prove trovate è un errore
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --testsuite integration
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --display-skipped     # elenca i saltati con motivo
APP_ENV=testing php tools/migrate.php                                            # migra pantedu_test
npm test                                                                          # Vitest
node tools/dev/tex-service-local.mjs                                            # microservizio TeX locale (serve alle spec TeX/PDF)
npm run e2e                                                                       # Playwright, tutte (in WSL: docs/dev/sviluppo-in-wsl.md)
npx playwright test tests/e2e/sicurezza/percorsi-e-limiti.spec.js --reporter=line # una spec
npm run e2e:a11y                                                                  # gate WCAG in CI
npm run e2e:typecheck                                                             # tsc sull'infrastruttura tests/e2e/support e sulle spec con @ts-check
```

`pdo_sqlite` deve esserci: senza, i test SQLite chiamano `markTestSkipped()` e
la suite resta verde senza aver verificato nulla. In WSL la installa
`tools/dev/wsl/pacchetti.sh` (`php8.3-sqlite3`); sulla vecchia configurazione
Windows andava caricata a mano, da qui il `-d extension=pdo_sqlite` nei comandi
qui sopra, che in WSL è innocuo (al più un avviso «già caricato»). Con il
database spento i test di integrazione si saltano invece di fallire; in CI il
lavoro con il database li lancia con `--fail-on-skipped`, e lì un salto è rosso.

**Una prova che ha bisogno del database si crea le sue righe.** Non cerca un
modello, un docente o un catalogo che esistono solo nei database nati da una
copia della produzione: sul database pulito della CI non ci sono. Il 14/9/2026
`RisdocResolverTest` cercava il modello `risdoc/MODELLI/0.0_Piano_annuale_(docente)`
e finiva in errore; `RisdocSeedTest` controllava le righe lasciate da
`bin/fm-risdoc-seed.php`, che dalla migrazione 080 non girava più (`Unknown
column 'origin'`), ed è stata tolta con lo script. Per riprodurre il lavoro in
locale: un contenitore `mariadb:11.8` usa e getta, e i passi del lavoro
`integrazione` di `ci.yml` in ordine.

## Configurazione

- `phpunit.xml`: suite `unit` e `integration` (minuscole), `APP_ENV=testing`
  forzato (→ `DB_NAME_TEST`), ordine casuale, `failOnWarning/Risky/Deprecation`.
- `tests/bootstrap.php`: autoload + `Config::load`, nessun `.env` (i test
  che ne hanno bisogno lo ricaricano nel `setUp`).
- **Le prove che cifrano creano la chiave del docente prima delle sue righe.**
  `TeacherCryptoService` rifiuta di creare la chiave di un docente che ha già
  righe con un blob (`kek_regen_guard`). In sviluppo `.env.local` può avere
  `ALLOW_CRYPTO_REGENERATE`, che lo nasconde: la prova passa in locale e cade in
  CI (#120, 15/9/2026). Per provarla come in CI:
  `Config::set('crypto.allow_regenerate', false)` nel `setUp`.
- `vitest.config.js`: ambiente happy-dom, `tests/js-unit/**/*.test.js`.
- `playwright.config.js`: `baseURL` da `FM_E2E_BASE_URL` (default
  `http://127.0.0.1:8765`, il server di sviluppo in WSL; in CI è il container dell'immagine del rilascio, sulla porta scelta dal job), credenziali da `.env.local` (`E2E_TEACHER_*`,
  `FM_E2E_ADMIN_*`), nessun parallelismo, retry 0. Niente `X-Audit-Reason`
  globale dal 23/9/2026: lo mette il client delle API
  (`tests/e2e/support/api/http.ts`) sulle scritture, mentre i pulsanti delle
  pagine devono mandarlo da sé, come in produzione (prima la configurazione lo
  aggiungeva a ogni richiesta del browser, e un pulsante senza motivazione
  passava).

## Test sospesi (`TEST_ROT`) — chiusi il 2026-09-04

Dal 31 agosto al 4 settembre 2026 nove test della pipeline TeX risdoc
erano saltati per costante `TEST_ROT` (nome del test → motivo), con le
asserzioni intatte, in attesa di decidere caso per caso se il comportamento
attuale fosse voluto. La decisione è stata presa nella fase 1 della
revisione architetturale (intervento P9): **nessuna regressione**. Il «corpo
vuoto» dei due test schema-driven era una fixture pre-ADR-026 senza
sezioni `pt_unified`; `\section*`, la didascalia in `\begin{center}`, le
checkbox come `\item[\xcheckbox]`, le colonne `V`/`F` e `\linewidth` nelle
minipage sono scelte documentate nel renderer. Attese e fixture sono state
riallineate e l'impalcatura `TEST_ROT` è stata tolta dai sei file. Esito
completo in `docs/analysis/test-rot-tex-2026-08-31.md` (in testa).

Con il database attivo la suite unit+integration è verde; i pochi skip che
restano sono per ambiente (variabile del processo o servizio esterno
assente), non per test rossi — l'elenco esatto è quello che
`--display-skipped` stampa sul giro di oggi, non un numero fissato qui.

## Copertura per dominio

| Dominio | Unit | Integration | E2E | File chiave |
|---------|:----:|:----------:|:---:|-----------|
| core/Router, Kernel | alta | — | — | `tests/Unit/Core/RouterTest.php`, `ResponseTest.php` |
| core/Auth, 2FA | alta | — | media | `tests/Unit/Core/AuthTest.php`, `AuthActorRoleTest.php`, `AuthSessionRotationTest.php`, `tests/Unit/Services/Security/TwoFactorPolicyTest.php`, `EmailSecondFactorTest.php`, `QrCodeTest.php`, `PasswordResetServiceTest.php` |
| core/Migrator | alta | — | — | `tests/Unit/Core/MigratorTest.php`, `MigratorSplitStatementsTest.php` |
| csrf, rate, waf | alta | media | media | `CsrfMiddlewareTest.php`, `RateLimiterTest.php`, `RateLimitStoreTest.php`, `WafMiddlewareJsonResponseTest.php`, `tests/Unit/Services/Waf/EdgeContextTest.php` (IP del client e proxy fidati), `RegexSicureTest.php` (pattern che esplodono), `tests/Integration/Waf/ProtezioneDalBruteForceTest.php`, `CacheDeiBlocchiTest.php`, `tests/e2e/sicurezza/percorsi-e-limiti.spec.js` (riscrittura di `security.spec.js` e `rate_limit.spec.js`); il retry sul 403 CSRF intermittente (voce 35 del debito) è nell'infrastruttura (`support/auth/login.ts`), non in una spec dedicata |
| scenari, deploy mode, ToS | alta | — | — | `tests/Unit/Support/DeploymentScenarioTest.php`, `DeploymentModeTest.php`, `TosEnforcementTest.php`, `TosAcceptanceMiddlewareTest.php` |
| contenuti, contract, visibilità | alta | media | alta (stale) | `tests/Unit/Contract/*`, `tests/Unit/Domain/ContentVisibilityPolicyTest.php`, `tests/Integration/PublishScopeVisibilityTest.php`, `StudentSectionFilterTest.php`, `ContentStudyTopicIdsFilterTest.php` |
| curriculum, sezioni, istituti | alta | alta | — | `CurriculumServiceTest.php`, `tests/Unit/Support/IndirizzoCodeDeriverTest.php`, `MiurCurriculumAliasTest.php`, `ClsNormalizerSezioniTest.php`, `tests/Unit/Services/TeacherSection*Test.php`, `tests/Integration/MiurAdozioniImporterTest.php`, `InstituteMergeServiceTest.php`, `InstituteMiurCodeGuardTest.php`, `TeacherSubjectServiceTest.php`, `IstitutoAttivoECasaDeiFileTest.php` (istituto attivo e casa dei file privati, 23/9/2026) |
| crypto | alta | alta | — | `tests/Unit/Crypto/TeacherCryptoServiceTest.php`, `tests/Unit/Services/Crypto/ShamirSecretSharingTest.php`, `TeacherRecoveryServiceTest.php` (codice di recupero e firma del manifesto), `tests/Integration/Crypto/*` (full flow, KEK rotation, dual write, classe keys) |
| gdpr | alta | media | media | `tests/Unit/Services/Gdpr/**`, `InformativaVersionTest.php`, `tests/Integration/Gdpr/ParentConsentServiceTest.php`, `GettoniComeHashTest.php` e `GettoniComeHashMigrazioneTest.php` (gettoni come hash, 23/9/2026), `CancellazioniDovuteTest.php` (esecuzione dell'art. 17), `tests/Unit/ParentConsentMailerTest.php`, `tests/e2e/gdpr_*.spec.js` |
| audit | alta | — | — | `tests/Unit/Services/Audit/ActivityLoggerTest.php`, `AuditChainTest.php` |
| risdoc | media | — | alta (stale) | `tests/Unit/Risdoc/**`, `RisdocResolverTest.php` (richiede MariaDB), `CompilationScrubberTest.php`, `CompilationStoragePolicyTest.php` |
| verifiche | media | media | alta (stale) | `tests/Unit/Services/Verifica/*`, `TexBuilderTest.php`, `tests/Integration/TeacherPrintControllerTest.php`, `AdminPrintControllerTest.php` |
| mappe | media | — | media | `tests/Unit/Maps/*` |
| pdf-import | media | — | — | `tests/Unit/PdfImport/*` |
| sicurezza (sanitizer) | alta | — | alta | `tests/Unit/Services/Security/HtmlSanitizerTest.php`, `SvgSanitizerTest.php`, `TikzScriptValidatorTest.php`, `tests/e2e/sicurezza/*.spec.js` |
| frontend | bassa | — | alta (stale) | `tests/js-unit/*.test.js` (rm-layout-model, sidebar-cascade, conflict-resolver, html-text-utils, audit-reason, tikz-svg-id-isolation, sidepage-category-labels) |
| a11y | — | — | alta | `tests/e2e/qualita/accessibilita-pubblica.spec.js`, `accessibilita-riservata.spec.js`, `punteggio-di-accessibilita.spec.js` (gate CI) |

## Stato della suite E2E

Il numero di spec e test cresce a ogni PR (contali con
`npx playwright test --list`); alla fine della riscrittura, il 2026-09-07,
erano 122 file e 588 test. **Tutti** sull'infrastruttura in
`tests/e2e/support/**`, riscritta
fra il 6 e il 7 settembre 2026. Nella cartella
`tests/e2e/` non resta nessuna spec fuori dalle aree, e i due moduli di accesso
scritti a mano (`helpers.js`, `studio-eser-helpers.js`) sono stati eliminati.

Il piano è `docs/plans/e2e-refactor-blueprint.md`, l'esito con la scorecard e
le venti domande di chiusura è in [[e2e-refactoring-esito]], il racconto fetta
per fetta nel [[changelog/2026-09]], i prerequisiti per eseguirla in
`docs/ops/e2e-runner-prerequisiti.md`.

### Che cosa è cambiato

| Misura | Prima | Dopo |
|--------|------:|-----:|
| File di spec | 183 | 122 |
| Test | 617 | 588 |
| Righe di codice | 26.856 | 14.506 |
| `page.waitForTimeout` | 469 | **0** |
| `networkidle` | 100 | **0** |
| `console.log` | 555 | **0** |
| Schermate salvate a mano | 58 | **3** |
| Errori ingoiati (`catch(() => {})`) | 126 | **1**, commentata |
| Spec che aprivano la sessione dal modulo | 114 | **0** |
| Spec che leggevano una password o un segreto | 121 | **0** |
| Spec che si saltavano da sole | 18 | **0** |
| Intercettazioni di `/auth/csrf` | 7 | **0** |

Le spec stanno in cartelle per area: `studio/`, `verifiche/`, `risdoc/`,
`condivisione/`, `admin/`, `area-docente/`, `editor/`, `pubblico/`,
`sicurezza/`, `osservabilita/`, `qualita/`. Due sottocartelle `moduli/`
(`editor/moduli/`, `risdoc/moduli/`) raccolgono le prove del modulo dentro al
browser: non sono percorsi dell'utente, e il README della suite spiega quando
scriverne una lì invece che nell'area.

### L'infrastruttura

`tests/e2e/support/**`, 40 file TypeScript strict (4.026 righe), controllati da
`npm run e2e:typecheck`. Le spec importano solo `./support/test`: lo impone una
regola ESLint.

- **Sessioni per ruolo**: `teacherPage`, `teacher2Page`, `adminPage` e i client
  `teacherApi`, `teacher2Api`, `adminApi`. Il login dal modulo avviene una volta
  per utente e per giro, sulla cache `tests/e2e/.auth/`; le password non
  arrivano mai alla spec.
- **Client di dominio**: `content`, `verifica`, `share`, `curriculum`, `maps`,
  `bundle`, `print-info`, `risdoc`, `sources`, `tikz`, `access`,
  `observability`, `admin`. Ognuno parla dalla sessione del browser, perché il
  WAF è chiuso a tutto il resto.
- **Factory con registro di pulizia**: quel che una spec crea viene cancellato
  nel teardown del ruolo, anche quando il test fallisce.
- **Segnali dell'app** (`sync/signals.ts`): i 33 eventi `fm:*` che
  l'applicazione emette sono il modo in cui i test aspettano; lì sta anche
  `attendiAnimazioniFerme`, per quando bisogna misurare o premere qualcosa che
  si sta ancora muovendo.
- **Diagnostica**: errori JavaScript, errori di console e risposte 4xx/5xx sono
  raccolti sempre e allegati al rapporto quando un test fallisce.
- **Pagine e componenti**: `StudioEsercizioPage`, `HomeSidebarPage`,
  `BancoEditor`, `Topbar`, `Upbar`, `GruppoQuesiti`, `GenerazioneVerifica`.

### Il giro di chiusura

**589 test su 589 passati in 12,7 minuti**, nessuno saltato (7 settembre 2026,
con il controllo automatico degli errori JavaScript attivo). I quattro test in
più rispetto alla chiusura del refactoring — 585 — sono la copertura dei
difetti corretti nella stessa giornata.

Il giro precedente si era fermato a 587 su 588: l'unico fallimento era
`verifiche/scelte-della-verifica`, che scriveva nel campo del titolo mentre la
pagina, seicento millisecondi dopo essere pronta, lo riscriveva da sola con il
ripristino automatico delle scelte. Passava sempre in isolamento e falliva
sotto carico. Corretto aspettando quella richiesta prima di scrivere: nove
esecuzioni su nove verdi. I tre test in meno sono le ultime schermate salvate a
mano, tolte a chiusura.

### Le quattro reti della suite

Dopo la riscrittura, quattro cambiamenti valgono per tutti i test invece che
per quelli che se ne ricordavano (7 settembre 2026).

**1. Il tempo massimo sta in un posto solo.** Trecentodiciassette `test.setTimeout`
sparsi nelle spec — quasi tutti copiati, alcuni a 120 secondi su test che
durano due — sono spariti; ne restano ventiquattro dove il tempo dipende
davvero dal caso (compilazioni TeX, pacchetti ZIP). Il config è passato da 30 a
90 secondi, quasi cinque volte il test più lento misurato (18,5 s).

**2. Cinque regole ESLint sulle spec.** `page.waitForTimeout`, `networkidle`,
`screenshot({ path })`, `dispatchEvent("click")` e `console.log` non sono più
questioni di disciplina: sono errori del linter. Le regole stanno in
`eslint.config.mjs` sotto `tests/e2e/**/*.spec.js`.

**3. Gli errori JavaScript fanno fallire il test.** L'asserzione
`expect(diagnostics.jsErrors).toEqual([])` era scritta in sessantasette test su
cinquecentottantanove; negli altri cinquecento un errore in pagina passava
inosservato. Ora il controllo è nella fixture della diagnostica e vale per
tutti, **senza modo di zittirlo per un singolo test**: una tolleranza per test
è la stessa cosa di un errore ingoiato, scritta meglio. L'unico posto dove si
dichiara «questo non è un errore dell'applicazione» è il filtro `CONSOLE_NOISE`
in `support/fixtures/diagnostics.fixture.ts`, che sta in un posto solo e si
legge tutto insieme. Quando un errore salta fuori: o si corregge
l'applicazione, o — se è di una libreria di terzi — si allarga il filtro
scrivendo lì la ragione, o si apre una voce nel registro del debito.

**4. Il pacchetto vecchio ferma il giro.** `public/build/` e
`css/main.bundle.css` sono generati e non versionati, e possono restare
indietro rispetto a `js/` e `css/` senza che nulla lo dica: il 7 settembre 2026
lo erano di quattro commit, e la suite girava verde contro JavaScript vecchio.
Ora `global-setup.js` confronta le date prima di cominciare e ferma il giro
dicendo quale file è più recente, di quanti minuti, e quale comando ricostruisce
(voce 62 del debito).

### Quando gira tutta la suite

A fine fetta basta il giro mirato sulle aree toccate (5-10 minuti). Il giro
completo si fa prima di unire su `main`, perché ogni push su `main` va in
produzione.

### Attributi aggiunti all'applicazione per i test

Uno solo, il 2026-09-07: `for` sulle sei etichette delle caselle della barra dei
filtri (`views/partials/upbar.html`), che erano accanto alle caselle ma non
collegate. Non cambia il comportamento, migliora l'accessibilità e permette ai
test di premere il comando invece di forzare `checked` da codice. Per tutto il
resto non è servito nulla: i comandi dei quesiti e dei gruppi hanno già
`role="button"` e `aria-label` (`app/Services/ContractRenderer.php`), le caselle
del gruppo hanno `aria-label`, la finestra di conferma ha `role="dialog"`
(`js/modules/ui/fm-dialog.js`). Nel pannello laterale i bottoni si individuano
con l'attributo `data-sidepage`, non con l'etichetta, che è configurabile per
Istituto.

### Che cosa il refactoring ha trovato

Non regressioni introdotte, ma cose che c'erano già:

- **Sei spec senza una sola asserzione** (`g19_49_sidepage_diag`,
  `infover_debug`, `g22_s15bis_fase5_templates_open` e le tre diagnostiche
  cancellate il 5 settembre): aprivano una pagina, stampavano quel che
  vedevano, passavano sempre.
- **Test che non potevano fallire**: `expect([200, 403]).toContain(status)` su
  tre chiamate (`g20_09_admin_files`), `expect([200, 403, 404])` sulle
  iscrizioni in cinque spec diverse, un controllo di stili in linea su
  selettori che l'applicazione non genera più, un «Ulid genera stringhe
  distinte» che non guardava nessun identificativo.
- **Pulizie promesse e mai fatte**: l'intestazione di `gdpr_dpo_contact`
  dichiarava di chiudere le richieste create dai test; non c'era una riga che
  lo facesse.
- **Un difetto dell'applicazione**: l'editor salva la bozza automatica sotto la
  chiave `item-<id>` ma la cerca e la cancella sotto `<id>` (voce 42 del
  debito).
- **Un difetto del caricatore dell'ambiente della suite**: leggeva le righe di
  `.env.local` commento in linea compreso.

E, dopo la riscrittura, quello che hanno trovato le reti qui sopra:

- **Un gruppo aperto subito dopo il caricamento si richiudeva da solo** (voce 63):
  `collapsible.js` riapplicava lo stato di default a 300 e a 1200 millisecondi
  senza guardare se nel frattempo l'utente avesse aperto qualcosa. È venuto
  fuori inseguendo un test dell'ancoraggio che falliva una volta su sei.
- **La verifica WCAG del testo ingrandito al 200% non ingrandiva niente**
  (voce 64): lo script che alzava il carattere girava prima che il documento
  esistesse, moriva, e le tre immagini di riferimento erano state registrate a
  carattere normale. L'errore era rimasto per mesi nella console della pagina,
  dove nessuno lo guardava. Rifacendo le immagini davvero al doppio è emerso
  che l'etichetta «CHIUDI» della barra laterale si taglia (voce 65).
- **Un test che verificava un incidente** (voce 66): l'ancoraggio
  dell'intestazione passava perché il modulo aveva fatto in tempo a fissarla
  prima che il gruppo entrasse in modifica, e il `position: fixed` rimasto lì
  era tutto ciò che il test vedeva.

### Sessioni

`tests/e2e/support/auth/login.ts` tiene la cache `tests/e2e/.auth/<utente>.json`:
il login dal modulo avviene solo se la sessione salvata non risponde a
`/auth/user-info`; il 403 CSRF intermittente sul primo POST `/login` (voce 35
del debito) viene ritentato una volta e contato nella diagnostica.

### Un worker solo, dal config

`playwright.config.js` fissa `workers: 1`: la suite condivide un DB e tre utenti
di test, e con più worker i file girano in parallelo (Playwright ne apre metà
dei core) e si pestano lo stato: flag di condivisione, verifiche cancellate da
una spec mentre un'altra le usa, rate limit e CSRF sotto carico. Non passare
`--workers` più alti a mano. Per la stessa ragione non ha senso dividere la
suite fra più macchine.

### Setup globale

`tests/e2e/global-setup.js` accetta i ToS degli utenti di test
(`tools/dev/e2e_prepare_users.php`), scopre nel DB le terne con contenuti del
docente (`tools/dev/e2e_urls.php`) e le espone come `FM_E2E_ESER_URL`,
`FM_E2E_ESER_IDS_URL`, `FM_E2E_VERIF_LIST_URL`, `FM_E2E_VERIF_URL`,
`FM_E2E_VERIF_RM_URL`, `FM_E2E_ESER_RELATED_URL`,
`FM_E2E_SHARE_ESER_ID`/`VERIF_ID`/`IND`/`CLS`/`SUBJECT`, `FM_E2E_MAPPA_URL`;
rende condivisibile la coppia esercizio/verifica
(`tools/dev/e2e_prepare_content.php` aggiunge un quesito personale se il
contratto è vuoto). Le spec riscritte leggono la terna da `env.terna`; quelle
storiche leggono le variabili con la URL storica come ripiego.

### Ambiente

Consenso cookie pre-impostato via `storageState`; rate limit spento
(`RATE_LIMIT_DISABLED=1`: dove e da chi, [[environment-variables]]) e
riattivabile per una richiesta con `X-Pantedu-Rate-Limit: enforce`; il microservizio TeX gira in locale con
`node tools/dev/tex-service-local.mjs` (uvicorn e il TeX Live di WSL,
installato da `tools/dev/wsl/pacchetti.sh`;
`TEX_COMPILE_ENDPOINT=http://127.0.0.1:8001` in `.env.local`); Lighthouse via
`playwright-lighthouse`. L'elenco completo è in
`docs/ops/e2e-runner-prerequisiti.md`.

### Regole

**Una prova non eredita la richiesta di quella prima** (14/9/2026). PHPUnit gira
in ordine casuale, e una dozzina di prove scrive `$_SESSION`, `$_SERVER` o `$_GET`
senza rimetterli: la prova dopo falliva solo con certi semi. L'estensione
`tests/Support/RichiestaPulitaFraLeProve.php` (registrata in `phpunit.xml`), finita
ogni prova, svuota la sessione e rimette le superglobali della richiesta come
all'avvio. Una prova che dipende da chi è collegato lo dice comunque nel suo
corpo. Per riprodurre un rosso che dipende dall'ordine: `--random-order-seed`
con il seme del giro, poi una configurazione con `executionOrder="default"` e
le sole classi sospette, in fila.

Nessun `test.skip` condizionato ai dati: se una fixture manca è un errore, e
la prova lo dice con un `expect` («il docente di prova è collegato ad almeno
due scuole»); il dato che manca si semina in `tools/ci/seed_e2e_database.php`.
Dal 23/9/2026 lo controlla la CI: dopo la suite, `tools/ci/salti-e2e.mjs`
legge `tests/e2e-results/results.json` e fa fallire il giro se una prova è
finita saltata (`test.skip`, `test.fixme`, o rimasta dietro una
`describe.serial` caduta) senza essere dichiarata nel suo elenco, oggi vuoto;
fallisce anche se il file non c'è. Fino a quel giorno cinque prove si
saltavano in base ai dati, e la CI passava `--reporter=line`, che sostituisce
i reporter della configurazione: il JSON non si scriveva e i salti non si
contavano. In CI `forbidOnly` è acceso (`playwright.config.js`): un `test.only`
dimenticato fa fallire il giro invece di ridurlo a poche prove verdi. Lo fissa
`tests/js-unit/playwright-config-in-ci.test.js`, che valuta la configurazione
con e senza `CI` (senza leggere `.env.local`) e confronta il file del reporter
JSON con quello che legge il passo dei salti di `e2e.yml`. Le
immagini attese del confronto pixel (`qualita/regressione-visiva.spec.js`) sono
Linux/Chromium, generate in CI su un database seminato da zero, e stanno in
`tests/e2e/qualita/regressione-visiva.spec.js-snapshots/`. Quando la UI cambia
di proposito si rigenerano dal workflow `e2e.yml` con `aggiorna_immagini`,
**dopo** aver guardato ogni immagine di differenza (`docs/dev/ci-cd.md`, «Quando
è rosso»). Fuori da Linux il confronto si salta dicendolo — il database di
sviluppo cambia sotto i piedi e il rosso direbbe solo quello; chi vuole farlo
comunque, per una pulizia dei fogli di stile, esporta `FM_E2E_VISUAL=1` e
genera una serie sua, che non si versiona. Le regole per le spec nuove o
riscritte — locator, attese, dati, diagnostica, e la guardia `apriPaginaVera`
che impedisce di fotografare una pagina d'errore — stanno in
`tests/e2e/README.md`. Le spec cancellate restano in git.

In CI la suite gira a ogni spinta su `main` e la domenica alle 2 (`e2e.yml`),
su un database seminato da zero da `tools/ci/seed_e2e_database.php`: restano
fuori le spec `@tex` e `@pdflatex`, che compilano LaTeX, e le `@istanza`, che
guardano il contenuto di questa installazione. Il confronto delle immagini
invece **gira e confronta davvero** dall'8 settembre 2026: prima le immagini
erano generate su Windows e il giro passava `--ignore-snapshots`, cioè
cinquantanove verdi che non guardavano niente. Su
ogni pull request gira invece il sottoinsieme di accessibilità
(`npm run e2e:a11y`, `a11y.yml`), che non ha bisogno di database. I dettagli
in `docs/ops/e2e-runner-prerequisiti.md`.

**Chi tocca una pagina dell'amministrazione o dell'area docente lancia in
locale `qualita/accessibilita-riservata.spec.js`** prima di spingere. Sulle pull
request gira solo la parte pubblica (`accessibilita-pubblica`), perché quella
riservata vuole il database e una sessione, e quindi l'altra la si vede solo
dopo l'unione. Il 14/9/2026 un collegamento nel testo di `/admin/institutes`,
distinto solo dal colore (`link-in-text-block`, WCAG 1.4.1), è arrivato così
alla suite dopo l'unione (#104). Nel testo si usa `fm-link-inline`, sottolineato.

## Fixtures

`tests/Fixtures/` — JSON, HTML e schemi per i test unitari.
`tests/e2e-results/` — output Playwright (ignorato da git).

## Lacune

- Nessun test unitario sul WAF oltre alla forma delle risposte JSON.
- Nessun test sui controller admin più grandi (`WafAdminController`,
  `RisdocAdminController`) né sui renderer HTML di `ContentStudyController`.
- Ramo `DB_ENABLED=false` non coperto; `DB_DUAL_WRITE` non esiste più
  (tolta il 2026-09-05, [[technical-debt]] voce 4) — resta scoperto il ramo
  di lettura di `PrintInfoService`, che fa ancora prevalere il JSON quando
  il DB è disponibile (stessa voce, riaperta in lettura).
- `S3CompatibleStorageProvider` non coperto.
