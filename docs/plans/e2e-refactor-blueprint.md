# Blueprint: refactoring della suite Playwright

Stato: **eseguito**. Approvato il 6 settembre 2026 con una modifica (la fetta 1 limitata a ciò che le spec pilota della fetta 2 usano davvero) e portato a termine il 7 settembre. Le nove fette sono chiuse; novantuno file di spec su 142 sono riscritti, cinquantuno restano quelli storici — è la voce 51 del debito, e il piano per riprenderli è lo stesso di qui.

Il consuntivo sta in `wiki/testing.md` (tabella prima/dopo, che cosa il lavoro ha trovato), le voci di debito aperte in `wiki/technical-debt.md` (40-51), la storia fetta per fetta nel changelog di settembre, i prerequisiti per eseguire la suite in `docs/ops/e2e-runner-prerequisiti.md`.

*Il testo che segue è quello approvato il 6 settembre, lasciato com'era: serve a capire perché le cose sono state fatte così, e le sue misure sono quelle di partenza.*
Ramo: `feat/e2e-refactor` da `main` a `45f126f9`. Commit del passo 0: `3fd00024`.
Riferimenti: addendum «Playwright refactoring» (prevale sul prompt generale), `wiki/testing.md`, `wiki/changelog/2026-09.md` (5–6 settembre), `wiki/technical-debt.md` (voci 32–35), `playwright.config.js`, `tests/e2e/helpers.js`, `tests/e2e/global-setup.js`, `tests/e2e/studio-eser-helpers.js`.

Ogni affermazione è marcata **FACT** (misurata sul repository al commit `45f126f9`, comando riportato dove serve), **INFERENCE** (dedotta con ragionevole certezza) o **ASSUMPTION** (da confermare prima di agire).

---

## 0. Passo 0: cosa è stato fatto e cosa è emerso

| Passo | Esito |
|-------|-------|
| Ramo | `feat/e2e-refactor` esisteva già, allineato a `main` e a `origin/main` (`45f126f9`). Nessun commit su `main`. |
| Ambiente | `php tools/dev/render_page.php --route=/health --user=docente.uno` → 200; Apache (80), MariaDB (3306) e servizio TeX (127.0.0.1:8001, `netstat`) attivi. Nessun riavvio necessario. |
| Pulizia | `git clean -n` elencava 4 PNG e la cartella `teacher_content_db/`: cancellati. 28 PNG tolti dall'indice (`git rm -r --cached`), `.gitignore` ora ignora `tests/e2e/screenshots/**` tenendo tracciato `compare-baseline.mjs` (codice citato da ADR-018 e da `css_modernization_baseline.spec.js`). Le immagini di riferimento stanno in `tests/e2e/*.spec.js-snapshots/` (66 file), fuori dalla cartella ignorata. Commit `3fd00024`. |
| Contesto | Letti i sette file indicati, più `tests/e2e/README.md`, i tre script `tools/dev/e2e_*.php`, `tools/dev/gen_proof_contract.cjs`, `tools/dev/setup_studio_eser_e2e.sh`, `tools/dev/seed_e2e_fixtures.php`, `eslint.config.mjs`, `.github/workflows/a11y.yml`, `docs/api/api-index.md`. |
| Baseline | Giro completo dalle 14:52 alle 15:29 con `PLAYWRIGHT_JSON_OUTPUT_NAME` su `storage/_tmp/e2e-baseline/baseline-2026-09-06.json` (cartella ignorata da git, fuori da `tests/e2e-results/`): **617 test su 617 passati, 0 saltati, 37,9 minuti**, nessun retry CSRF. Dettagli in Appendice A. |

### 0.1 FACT dell'addendum che il codice contraddice

| Addendum | Misura sul repository | Comando |
|----------|----------------------|---------|
| `page.waitForTimeout` 933 volte in 185 file | **469 occorrenze in 93 file** (91 spec + `studio-eser-helpers.js`; `helpers.js` non ne ha). La copia della suite nel worktree `.claude/worktrees/classi-scenari/tests/e2e` (221 spec, versione precedente) ne ha 629: i numeri dell'addendum vengono da una ricerca che includeva anche quella cartella. | `grep -n waitForTimeout tests/e2e/*.js` |
| 29 eventi custom `fm:*` | **34 nomi distinti** citati nel codice dell'app, di cui **33 emessi** con `new CustomEvent("fm:…")` in **71 punti** di 35 file. Elenco in §6.6. | `grep -rnoE "new (CustomEvent\|Event)\(\s*['\"]fm:[a-z0-9:_-]+" js/ views/` |
| fine riga miste LF/CRLF in molte spec | Nell'**indice** git tutti i file testuali di `tests/e2e` sono LF. Nel **worktree** 53 sono CRLF e 4 misti, perché `core.autocrlf=true` e `.gitattributes` fissa `eol=lf` solo per `*.php` e pochi altri. Il repo è pulito; è la copia di lavoro a essere mista. | `git ls-files --eol tests/e2e` |
| `sessionAlive` deve chiedere `/auth/user-info` | Già fatto (`helpers.js:52-62`). Resta vecchio il commento in testa al file (`helpers.js:5-9`) e la docblock di `global-setup.js` (righe 11-12: dice di svuotare una cache che sta in un altro percorso, vedi §3.2 n. 12). | lettura |
| le spec non compilano il modulo di login da sé (wiki) | **114 spec su 183** compilano `input[name="username"]` con una funzione di login locale (76 la definiscono con nome `login*`); 45 usano `helpers.js`, 8 `studio-eser-helpers.js`, 20 non fanno login (pagine pubbliche). La cache delle sessioni serve quindi meno di un terzo della suite. | `grep -l 'input\[name="username"\]' tests/e2e/*.spec.js` |

### 0.2 Sonda di fattibilità (analisi, cancellata a fine lavoro)

**FACT.** Con Playwright 1.59.1 e Node 24 una spec JavaScript CommonJS può fare `require("./support/test")` e ottenere un file `test.ts` che estende `test` con fixture tipizzate; il teardown della fixture gira dopo il test. Provato con una configurazione usa-e-getta in `storage/_tmp/pw-ts-probe/` (1 test, verde, nessun browser aperto, nessun contatto con l'app). Non serve `tsconfig.json` per l'esecuzione; serve per il controllo dei tipi (`tsc --noEmit`), che oggi non c'è: `typescript` non è tra le dipendenze (FACT: `node_modules/typescript` assente; `@types/node` presente come dipendenza transitiva).

---

## 1. PHASE 0: mappa del repository

### 1.1 Struttura della suite (FACT)

```
tests/e2e/                       183 *.spec.js, tutti nella stessa cartella (nessuna sottocartella di spec)
├── helpers.js                   loginAs/loginTeacher/loginAdmin (cache sessioni), getCsrf, logout, registerTeacher, approvePendingByEmail
├── studio-eser-helpers.js       pagina studio/esercizio: gotoEser, openQuesitoEditor, closeQuesitoEditor, typeInField, trackJsErrors
├── global-setup.js              ToS dei 3 utenti (e2e_prepare_users.php), scoperta terne (e2e_urls.php), coppia condivisibile (e2e_prepare_content.php)
├── README.md                    fermo alla «Phase 9» (2 spec citate, variabili d'ambiente vecchie): da riscrivere
├── .auth/                       cache sessioni (ignorata): admin.json, docente.due.json, docente.uno.json
├── screenshots/                 artefatti (ignorata dal passo 0) + compare-baseline.mjs (tracciato)
├── lighthouse-reports/          ignorata
├── visual_regression_a11y.spec.js-snapshots/      immagini di riferimento (tracciate)
└── visual_regression_phase5.spec.js-snapshots/
tests/e2e-results/               outputDir Playwright (svuotata a ogni giro); 22 spec ci scrivono screenshot propri
playwright.config.js             JS; workers 1, retries 0, timeout 30 s, expect 5 s, storageState col consenso cookie, header X-Audit-Reason
tools/dev/                       e2e_urls.php, e2e_prepare_users.php, e2e_prepare_content.php, gen_proof_contract.cjs, setup_studio_eser_e2e.sh, seed_e2e_fixtures.php, tex-service-local.mjs, render_page.php
```

Non esistono cartelle `pages/`, `components/`, `fixtures/`, `api/`, `factories/`, `types/`: ogni spec porta con sé i propri helper (§1.3).

### 1.2 Stack (FACT)

| Voce | Valore |
|------|--------|
| Playwright | `@playwright/test` 1.59.1, progetto unico `chromium` (Desktop Chrome) |
| Linguaggio | JavaScript: 175 spec CommonJS, 8 ESM (`import`); Playwright transpila entrambi. Nessun TypeScript, nessun `tsconfig.json`, nessun `// @ts-check` |
| Node / npm | Node 24.14.0, npm; `typescript` assente, `@types/node` presente solo come transitiva |
| Lint | ESLint 9 flat config: blocco `tests/e2e/**/*.js` con `sourceType: module`, `no-undef` errore, `no-unused-vars` avviso |
| Reporter | `list` + `json` (`tests/e2e-results/results.json`) + `html`; da CLI `line,json` con `PLAYWRIGHT_JSON_OUTPUT_NAME` |
| CI | Nessun workflow esegue la suite intera; `a11y.yml` lancia `playwright test a11y_wcag_aa` contro `php -S` senza DB (pagine pubbliche) |
| App | PHP 8 senza framework, viste `views/*.php` (83), moduli ES `js/modules` (148), `js/components` (27), `js/entries` (31), bundle Vite in `public/build`, CSS BEM `fm-*`, 446 rotte in `routes/web.php`, 114 controller |
| Autenticazione | Modulo `/login` (cookie di sessione `PANTEDU_SID`), CSRF da `GET /auth/csrf` accettato come header `X-CSRF-Token` o campo `_csrf`, `GET /auth/user-info` per sapere chi è loggato; header `X-Audit-Reason` obbligatorio sulle mutazioni admin (già nel config) |
| Dati | Un solo DB MariaDB di sviluppo, tre utenti reali (docente `docente.uno`, secondo docente `docente.due`, `admin`), credenziali solo in `.env.local`; nessun reset del DB dai test; servizio TeX locale su 8001 |
| WAF | Fail-closed: solo il browser (con cookie di fingerprint) e `page.request` con la sua sessione passano; `curl` prende 403 |
| API doc | `docs/api/openapi.full.yaml` (207 path) generato da `tools/api/generate_openapi.php` con annotazioni manuali su ~30 endpoint; **non contiene le rotte definite nei gruppi** (`$rr->post`), quindi mancano `save-tex-batch`, `content/{id}/delete`, `share-pool`, `verifica/{id}/delete`, `risdoc …/instances`. Non è una fonte utilizzabile per i tipi dei client (§6.8) |

### 1.3 Inventario architetturale (FACT salvo dove indicato)

| Elemento | Stato |
|----------|-------|
| Fixture | Nessuna: `test.extend` non compare. Ogni spec usa `{ page }` o `{ browser }` nudi |
| Page Object / Component Object | Nessuno. `studio-eser-helpers.js` è un abbozzo procedurale per una sola pagina |
| Client API | Nessuno: 433 chiamate `page.request.*` in 98 spec con URL e payload scritti a mano; `/auth/csrf` chiamato 177 volte; 27 spec definiscono una propria `getCsrf/csrf/fetchCsrf` oltre a quella di `helpers.js` |
| Factory dati | Nessuna. Creazione via API in 60 spec (`teacher/content`, `save-tex-batch`, `print-info`, `risdoc …/instances`); **26 spec creano senza cancellare** (tabella in §3.3) |
| Global setup | Sì (ToS, scoperta terne, coppia condivisibile). Espone `FM_E2E_*` via `process.env` |
| storageState | Solo per il consenso cookie (localStorage), non per le sessioni; le sessioni sono cookie salvati in `tests/e2e/.auth/` da `helpers.js` |
| Mock | Nessuno: `page.route` non compare |
| Visual | 2 spec con `toMatchSnapshot` (3 asserzioni); `toHaveScreenshot` non usato; `css_modernization_baseline` cattura solo screenshot |
| Matcher custom | Nessuno |
| Tag | Nessun `tag:`; nessun `--grep` documentato |
| Sharding / CI | Non applicabile (decisione B.2) |
| Lifecycle | 31 `beforeEach`, 3 `beforeAll`, 1 `afterEach`, 2 `afterAll`: la pulizia è quasi tutta inline nel corpo del test |
| Ordine | 2 `describe.serial` (`rate_limit`, `tikz_render_smoke_multi`) + 5 file con `describe.configure({ mode: "serial" })` (`a11y_authenticated`, `b7_concurrent_isolation`, `gdpr_dpo_contact`, `gdpr_self_service`, `teacher_content_db`) |
| Salti | 21 `test.skip` in 18 file, tutti condizionati a variabili d'ambiente; 0 saltati nell'ultimo giro perché le variabili ci sono |
| Timeout | 188 `test.setTimeout` in 99 spec: 91 a 60 s, 24 a 120 s, 8 a 180 s, 7 a 600 s |
| Attese | 469 `waitForTimeout`, 100 `networkidle`, 119 `waitForLoadState`, 68 `waitForFunction`, 53 `waitForSelector`, 98 `waitForURL`, 17 `waitForResponse`, 2 `waitForEvent` |
| Locator | 0 `getByRole/getByLabel/getByText/getByTestId`; 779 `.locator(` CSS; 223 `.first()`; 18 `.nth(`; 644 `page.evaluate`; 104 `dispatchEvent` (click sintetici) |
| Errori ingoiati | 109 `.catch(() => {})` + 17 `.catch(() => null/false)` in 40 spec |
| Log | 550 `console.log` in 88 spec; 58 `page.screenshot` in 32 spec |
| Processi esterni | `pdflatex` (4 spec), `pdftoppm` (7), `unzip`/`Expand-Archive` (2), `mysql.exe -u root` (1: `student_modes_and_public_sidebar`), `node tools/dev/gen_proof_contract.cjs` (4 spec studio) |
| Codice morto | 73 spec con `addInitScript` per il consenso cookie (69 con le chiavi `user_cookie_consent_v2` o `cookieConsent`), reso inutile dallo `storageState` del config dal 5/9 |

### 1.4 Grafo delle dipendenze e accoppiamenti

Oggi: `Spec → (helpers.js | login locale) → page.request / page.locator → App`. Non esistono strati intermedi. Accoppiamenti da sciogliere:

- **Utility onnipotenti duplicate**: 76 funzioni `login*` locali, 27 `csrf` locali, decine di `jsonPost/postForm/getJson` con firme diverse.
- **Stato nascosto condiviso**: la coppia esercizio/verifica scoperta al setup (`FM_E2E_SHARE_ESER_ID`/`VERIF_ID`) è mutata da 6 spec (`share-pool` acceso/spento, grant, gruppi) che «ripristinano» a fine test senza `finally`; `verifica_tex_production` vuole cancellare **tutte** le verifiche del docente in `beforeEach` (`deleteAllVerifiche`, righe 80-99; oggi non cancella nulla perché legge `docs` da una risposta che ha `items`, vedi §3.2 n. 2); `student_modes_and_public_sidebar` scrive `storage/config/student_registration.json` ed esegue SQL come root; 4 spec studio riscrivono da file il contratto della copia di lavoro 1293 (`gen_proof_contract.cjs` → `storage/objects/institutes/106/private/77/esercizi/MAT/7-proofcopy.contract.json`).
- **Id scritti a mano**: `DOCENTE2_ID = 140`, ripieghi `58`/`105`, contenuti `1291`/`1293`, percorso `institutes/106/private/77`.
- **Setup condiviso per file**: 5 file `serial` passano id fra test (`teacher_content_db` tiene `id` in chiusura).
- **Dipendenza dall'ordine alfabetico** (INFERENCE, oggi inerte): `verifica_tex_production` gira dopo quasi tutte le spec `g19_*/g20_*/g27_*` che creano verifiche e prima di `verifiche_studio_smoke`; se la sua bonifica funzionasse (§3.2 n. 2), le spec che leggono `/api/verifica/list` troverebbero un elenco vuoto.

### 1.5 Aree e spec (FACT: 183 file, 617 test; test e minuti dal JSON della baseline, Appendice A)

| Area | Spec | Test | Minuti | File (esempi) |
|------|-----:|-----:|-------:|---------------|
| Studio esercizio: pagina, sidebar, sidepage, upbar/topbar | 32 | 145 | 11,8 | `studio_eser_*` (7), `sidepage_*` (3), `sidebar`, `buttons_smoke`, `all_buttons_coverage`, `g19_12_exercise_wizard`, `teacher_content_db`, `studio_verifica_db`, `smoke`, `full_flow_docente1` |
| Editor inline dei quesiti (liste, RM, anteprima) | 28 | 136 | 5,1 | `editor_*` (16), `edit_*` (2), `g23_*` (4), `nested_lists_e2e`, `list_button_html_render`, `sanitizer_list_tikz_fix`, `salvatex_lavatrice_real_dom`, `g19_15_editor_enhancements`, `g20_07_editor_modal_iframe` |
| Verifiche: costruzione, stampa, TeX/PDF, TikZ | 48 | 93 | 10,2 | `verifica_tex_production`, `verifiche_studio_smoke`, `g16`, `g18`, `g19_*` (9), `g20_0[2-7]_*` (10), `g21_1_*` (2), `g27_*` (15), `g22_s15_*` (6), `tikz_*` (2) |
| Risdoc: documenti PT, modelli, istanze | 26 | 48 | 2,6 | `risdoc_*` (18), `pt_document_*` (3), `page_doc_*` (3), `g20_07_templates_institute_scope`, `g20_10_area_docente_templates` |
| Condivisione e pool | 9 | 17 | 0,9 | `g22_s2[0-5]_*` |
| Area docente: profilo, modelli, sincronizzazione | 10 | 24 | 3,4 | `g20_08_profilo`, `g20_09_profilo_curriculum_tabs`, `g20_11_institute_link_feedback`, `g22_s15bis_fase5_*` (7) |
| Admin | 10 | 48 | 0,6 | `admin_*` (3), `analytics_and_classes`, `b3_visibility_scope`, `b4_audit_reason`, `b7_concurrent_isolation`, `g20_09_admin_files`, `institutes_credentials`, `full_audit` |
| Pubblico, registrazione, GDPR, sicurezza, osservabilità | 14 | 51 | 0,7 | `gdpr_*` (5), `registration*` (2), `student_modes_and_public_sidebar`, `security`, `rate_limit`, `observability_*` (2), `g24_xss_sanitization`, `pdf_import` |
| Qualità: a11y, visual, Lighthouse, baseline CSS | 6 | 55 | 2,4 | `a11y_*` (2), `lighthouse_a11y`, `visual_regression_*` (2), `css_modernization_baseline` |

I nomi dei file seguono i codici delle fasi di sviluppo (`g19_49l`, `g22_s15bis_fase5`), non la funzione: in §6.2 la regola per i file nuovi o riscritti.

---

## 2. PHASE 1: scorecard di partenza

| Area | Voto | Motivazione (FACT salvo indicazione) |
|------|:----:|--------------------------------------|
| Isolamento dei test | 1/5 | Tre utenti e un DB condivisi; 26 spec lasciano dati; `deleteAllVerifiche` cancella dati altrui; 7 file dipendono dall'ordine; ripristini senza `finally` |
| Resilienza dei locator | 2/5 | Le classi BEM `fm-*` e gli id sono stabili, ma 0 locator semantici, 223 `.first()`, 748 fra `evaluate` e `dispatchEvent` che scavalcano le verifiche di attivabilità di Playwright |
| Architettura delle fixture | 0/5 | Nessuna fixture; 76 login locali; ogni spec ricostruisce lo stesso setup |
| POM / COM | 0/5 | Nessuno; `studio-eser-helpers.js` è procedurale e usa `waitForTimeout` (5 volte) |
| Gestione dei dati | 1/5 | Scoperta delle terne al setup (buono); creazione senza pulizia in 26 spec; id fissi; fixture su file e SQL diretto |
| Autenticazione | 2/5 | Cache delle sessioni in `helpers.js` con controllo `/auth/user-info` e retry sul 403 CSRF; usata da 53 spec, 114 rifanno il login dal modulo |
| Integrazione API | 2/5 | Le API sono usate molto (433 chiamate) ma senza client, senza tipi, con 27 `csrf` duplicate e formati di payload diversi per lo stesso endpoint (`form`, `URLSearchParams`, JSON) |
| Parallelismo | n/a | `workers: 1` per decisione B.2 (un DB, tre utenti). Non si valuta |
| Resistenza alla flakiness | 1/5 | 469 attese fisse (11 minuti di sonno letterale), 100 `networkidle` su pagine che fanno polling, 126 errori ingoiati, 188 timeout alzati a mano |
| Sicurezza dei tipi | 0/5 | Nessun TypeScript, nessun `@ts-check`, nessun JSDoc di tipo |
| CI/CD | 1/5 | Solo il sottoinsieme a11y in CI; nessun documento dei prerequisiti per un runner completo |
| Osservabilità | 2/5 | `screenshot: only-on-failure` e `video: retain-on-failure` funzionano; `trace: "on-first-retry"` con `retries: 0` **non produce mai una trace** (config righe 65 e 75); 550 `console.log` e 58 screenshot manuali fanno rumore |
| Sicurezza | 3/5 | Credenziali solo da `.env.local`; nessun segreto nelle spec. Restano: `mysql -u root` da una spec; password admin di default in chiaro in `tools/dev/seed_e2e_fixtures.php` (strumento locale, non usato dalla suite) |
| Prestazioni | 2/5 | 36,6 minuti per 617 test; 11 minuti di attese letterali, 114 login dal modulo, 58 screenshot, 20 s × 4 tentativi di polling in 5 spec `g27` |
| Manutenibilità | 1/5 | 183 file piatti con nomi di fase; 5 file oltre 400 righe (`verifiche_studio_smoke` 950); due «smoke» da 50 attese l'uno; README fermo alla Phase 9 |

---

## 3. PHASE 2: registro della flakiness

### 3.1 Le 469 attese fisse, per causa

Classificate con uno script di analisi (istruzione che precede ogni `waitForTimeout`, saltando righe vuote, commenti e chiusure di blocco). Solo 30 su 469 hanno un commento che spiega cosa aspettano.

| Classe | Occorrenze | Cosa aspettano davvero | Sostituzione (scala B.4) |
|--------|-----------:|------------------------|--------------------------|
| A. dopo `goto`/`reload`/`waitForLoadState` | 75 | bootstrap JS, MathJax, binding dei gestori | asserzione web-first sul punto di riferimento della pagina; `fm:mathjax-ready`; attributi `data-fm-*-bound` (§6.6) |
| B. dopo click o tasto | 190 | render, modale, animazione, richiesta di salvataggio | `expect(locator).toBeVisible/toHaveClass/toHaveCount`; `waitForResponse` sull'endpoint reale; evento `fm:*` |
| C. dopo `fill`/`type`/`selectOption`/`check` | 34 | debounce dell'autosave, cascata dei select della sidebar | `waitForResponse` su `…/patch` o `…/update`; `fm:db-sidepage-rendered` |
| D. dopo `page.evaluate` | 10 | effetto di una funzione `window.FM.*` chiamata a mano | come B; in seguito sostituire la chiamata diretta con l'interazione reale |
| F. dopo un'altra attesa (`waitForSelector/Function/Response`) | 34 | «margine» dopo un'attesa già soddisfatta | rimozione; se l'attesa precedente non bastava, il segnale giusto è un altro |
| G. dentro un ciclo (polling artigianale, 20 s × 4 nelle `g27_*`) | 10 | compilazione PDF sul servizio TeX | `expect.poll` sull'endpoint (`/api/verifica/{id}/pdf` o `jobs/{jobId}`) con timeout dichiarato |
| H. dopo un `expect` | 10 | «assestamento» dopo una verifica riuscita | rimozione |
| I. dopo un helper (`gotoEser`, `openQuesitoEditor`) | 3 | l'helper non aspetta abbastanza | l'attesa va nell'helper/page object |
| Z. senza azione riconoscibile prima | 103 | soprattutto i cicli «clicca tutto» di `buttons_smoke` e `all_buttons_coverage` (103 attese in due file) | riscrittura dei due file su componenti con asserzione dell'effetto reale (§8, fetta 5) |

Durate: 62 × 200 ms, 59 × 300, 57 × 400, 43 × 500, 53 × 800, 42 × 1 500, 36 × 2 500, 10 × 20 000. Somma 661 s = 11,0 minuti a giro, tutti spesi anche quando il segnale arriverebbe in pochi millisecondi.

File più carichi: `all_buttons_coverage` 52, `buttons_smoke` 51, `verifiche_studio_smoke` 27, `g22_s15bis_fase5_templates_full` 23, `studio_eser_editor_toolbar` 12, `g19_12_exercise_wizard` 11, `sticky_diagnostics` 10.

Attese che potranno sopravvivere (previsione, da confermare fetta per fetta): nessuna delle classi A–I ha un motivo che l'app non copra con un segnale esistente o con un attributo di pronto ai sensi di B.3. Ogni sopravvissuta avrà commento e voce nel registro del debito.

### 3.2 Altri modi di fallire

| # | Root cause | Rischio | Impatto | Fix |
|---|-----------|---------|---------|-----|
| 1 | **Stato condiviso mutato senza `finally`**: `share-pool`, grant e gruppi sulla coppia scoperta al setup (`g22_s23_*`, `g22_s24`, `g22_s25_*`) | un fallimento a metà lascia la coppia non condivisa | le spec successive della catena falliscono a cascata; il DB resta nello stato sbagliato fino al giro dopo | factory `share` che registra il ripristino nel teardown; meglio ancora, coppia esercizio/verifica **creata dalla spec** (esercizio con `title = X` + verifica con `topic = X`, stessa materia: è la regola con cui `e2e_urls.php:163-172` abbina la coppia) |
| 2 | **`deleteAllVerifiche` in `beforeEach`** (`verifica_tex_production.spec.js:80-99`) vuole cancellare tutte le verifiche del docente, ma legge `body.docs` mentre `GET /api/verifica/list` risponde `{ ok, items, materie }` (`app/Controllers/VerificaController.php`, `listForTeacher`): **non cancella nulla** (FACT, scoperto il 6/9 leggendo il controller) | l'intenzione è distruttiva; se qualcuno «corregge» la spec, cancella verifiche di altre spec e dell'utente | i residui `G22*` si accumulano davvero a ogni giro (§3.3); la spec crede di partire pulita | factory `verifica` con titoli unici e cancellazione solo di ciò che ha creato; la bonifica del pregresso è lo strumento del n. 3 |
| 3 | **Residui**: 26 spec creano verifiche (`save-tex-batch`), print-info, contenuti e istanze senza cancellarli (§3.3) | il DB cresce di decine di righe a giro (titoli `G22*`, `G20ZipTest`, `PROOF-COPY*`) | `e2e_urls.php` sceglie la terna con più righe e scarta solo i topic `prova|zz|test`: i residui possono orientare la scoperta; `verifica/list` rallenta | registro di pulizia nelle factory; nomi con prefisso `e2e-` e timestamp; strumento una tantum `tools/dev/e2e_cleanup_residues.php` per il pregresso (nuovo, solo locale, con guardia anti-produzione come `seed_e2e_fixtures.php`) |
| 4 | **114 login dal modulo** | ogni POST `/login` può incappare nel 403 CSRF intermittente (debito 35); ~1-2 s l'uno | minuti persi; fallimenti casuali fuori dalla cache di `helpers.js` | fixture di autenticazione per ruolo con la cache in `.auth/` (§4.1); il retry sul 403 resta, contato nel report |
| 5 | **Errori ingoiati**: 109 `.catch(() => {})`, 17 `.catch(() => null/false)`, `click` con `force: true` in `try` (`buttons_smoke.spec.js:56-67`) | il test passa anche se la funzione è rotta | copertura apparente | rimozione; ogni azione asserisce il suo effetto |
| 6 | **`networkidle`** (100 usi) su pagine con polling (`admin badge`, `sync log`: commento «le pagine non sono mai idle») | attesa fino al timeout o scaduta subito | lentezza e falsi rossi | mai `networkidle`; segnali dell'app |
| 7 | **Timeout alzati a mano** (188, fino a 600 s) | un fallimento vero può costare 10 minuti | giri rossi lunghissimi | timeout nel config per tipo di spec (tag `@tex`, `@pdf`), non per test |
| 8 | **Ordine fra test dello stesso file** (7 file `serial`; `teacher_content_db` passa `id` fra test) | un test non gira da solo | `--grep` su un titolo fallisce | ogni test crea i suoi dati; `serial` solo dove il dominio lo impone (`rate_limit`: finestra di 60 s) |
| 9 | **Processi esterni** non dichiarati: `pdflatex`, `pdftoppm`, `unzip`/`Expand-Archive`, `mysql.exe`, `node gen_proof_contract.cjs` | assenti su un'altra macchina | fallimenti d'ambiente scambiati per bug | fixture `strumentiEsterni` che verifica il binario e fallisce con messaggio chiaro (niente `skip`, regola della wiki); elenco nei prerequisiti (§8, fetta 9) |
| 10 | **Fixture su file e SQL diretto**: contratto della copia 1293 riscritto su disco; `student_registration.json`; `UPDATE sidebar_sections` come root | scavalcano cache, validazioni e permessi dell'app; il file JSON resta se il test muore prima di `afterAll` | stato invisibile fra giri | dati via API (`content.factory` con `group/add` e `quesito/{ref}/duplicate`, entrambe rotte esistenti); la modalità di registrazione via endpoint admin se esiste (ASSUMPTION da verificare nella fetta 8), altrimenti fixture con teardown garantito |
| 11 | **Screenshot e log manuali**: 58 `page.screenshot` (22 spec dentro `tests/e2e-results`, che Playwright svuota), 550 `console.log` | lentezza (fullPage), report illeggibile | diagnosi difficile | rimozione; trace e screenshot solo su fallimento dal config; fixture `diagnostica` che allega errori JS e richieste fallite al report |
| 12 | **`global-setup.js:45` cancella `tests/e2e-results/.auth`**, percorso che non esiste più (la cache sta in `tests/e2e/.auth`) | nessun effetto oggi | docblock e wiki descrivono un comportamento che non c'è | togliere la riga; dichiarare che la cache sopravvive ai giri ed è validata a ogni uso |
| 13 | **Consenso cookie via `addInitScript`** in 73 spec, con due chiavi diverse | nessuno (lo `storageState` del config copre già) | codice morto che confonde | rimozione |
| 14 | **Id e percorsi fissi** (`DOCENTE2_ID=140`, `58/105`, `1291/1293`, `institutes/106/private/77`) | legati al dump locale | la suite non gira su un altro DB | id risolti via API (`/api/teacher/share/colleagues` per il collega) o dati creati dalla spec |
| 15 | **`trace: "on-first-retry"` con `retries: 0`** | mai una trace | un fallimento si diagnostica solo da screenshot e video | `trace: "retain-on-failure"` (unica modifica al config nella fetta 1) |
| 16 | **403 CSRF intermittente sul primo POST `/login`** (debito 35, non riprodotto) | login fallito una volta su qualche centinaio | retry in `helpers.js` | resta nel modulo di login della fixture, con conteggio nel report per misurarlo; la causa è dell'app, fuori perimetro |
| 17 | **`.first()` (223) e `.nth()` (18)** | il primo elemento che corrisponde può non essere quello inteso | asserzioni su elementi sbagliati | locator per ruolo/nome e `filter({ hasText })` |

### 3.3 Spec che creano senza cancellare (FACT: grep su `request.post` verso endpoint di creazione contro `/delete|unshare|reset`)

`g19_30_debug_modal_centering`, `g19_49_tex_variants`, `g19_print_info_scelte`, `g20_02_zip_compile`, `g20_03_vsc_layout`, `g20_06_compensa_griglia_compact`, `g22_s15bis_fase5_delete_batch`, `g27_batch_sync_propagation`, `g27_dis_compensa`, `g27_dsa_sci_firma`, `g27_filetree_dedup_ui`, `g27_ggb_binary_preserve`, `g27_griglia_baseline`, `g27_math_br_isolation`, `g27_math_newline_protect`, `g27_tikz_preamble_unified`, `g27_vf_dis_xelatex`, `g27_vf_giust_rows_visual`, `g27_vf_giustifica_modulation`, `g27_vf_table_visual`, `g27_vf_user_flow_real`, `nested_lists_e2e`, `salvatex_lavatrice_real_dom`, `sanitizer_list_tikz_fix`, `studio_eser_wizard` (26 con `g19_18`, che ne cancella una su tre). Quasi tutte creano verifiche con `save-tex-batch`: è l'area 4 delle fette.

### 3.4 Registro dei test sospetti (PHASE 24, stato iniziale)

Nessun test è fallito negli ultimi tre giri completi (FACT: changelog 5–6 settembre e giro di baseline). Il registro parte vuoto e si popola con i fallimenti osservati durante le fette; un test che passa dopo un retry non conta come stabile (`retries: 0` resta).

---

## 4. PHASE 3: dati e stato

### 4.1 Utenti, ruoli, sessioni

- Tre utenti reali, invariati: docente (`E2E_TEACHER_USER/PASS`), secondo docente `docente.due` (oggi con la **stessa** password del primo: FACT, `g22_s25_granular_share.spec.js:18`), admin (`FM_E2E_ADMIN_USERNAME/PASSWORD`). Nessuna creazione di utenti dai test (la registrazione è chiusa in modalità `single`).
- Un solo modulo `support/env.ts` legge e valida le variabili all'avvio (nomi, mai valori nei log). Variabili nuove, con ripiego che conserva il comportamento attuale: `E2E_TEACHER2_USER` (default `docente.due`) e `E2E_TEACHER2_PASS` (default `E2E_TEACHER_PASS`). Nessuna azione richiesta sull'`.env.local`.
- Fixture per ruolo, tutte costruite sulla stessa cache `tests/e2e/.auth/<utente>.json` che `helpers.js` usa oggi (stesso formato: cookie + landing), così le spec vecchie e nuove convivono durante la migrazione:
  - `teacherPage`, `adminPage`, `teacher2Page`: pagina già autenticata (sessione dalla cache, validata con `/auth/user-info`; login dal modulo solo se scaduta, con il retry documentato sul 403 CSRF).
  - `teacherApi`, `adminApi`, `teacher2Api`: client API sulla stessa sessione (`page.request`), con CSRF risolto una volta e rinnovato su 403.
  - `freshLogin`: per le spec che verificano proprio il modulo di login (`fresh: true` di oggi).
- Nessuno `storageState` per utente al posto della cache attuale: la cache già funziona, è validata a ogni uso e sopravvive ai giri; cambiarla non risolve nessun problema misurato (regola 2 del prompt).

### 4.2 Dati: scoperti e creati

| Tipo | Origine | Regola |
|------|---------|--------|
| Contenuti «di scenario» (terne con contenuti, verifica RM, esercizio con verifica correlata, mappa condivisibile) | `global-setup` via `tools/dev/e2e_urls.php`, esposti da `support/env.ts` come `scoperti.eserUrl`, `scoperti.verifRmUrl`, … | **sola lettura**. Nessuna spec li modifica, li condivide o li cancella. Servono alle spec di lettura/render |
| Dati «di prova» (esercizi, verifiche, print-info, istanze risdoc, gruppi e grant di condivisione) | factory in `support/factories/*`, via API con il client del ruolo | creati dal test che li usa, nome unico `e2e-<spec>-<timestamp36>`, cancellati dal registro di pulizia nel teardown della fixture **anche se il test fallisce** |
| Coppia esercizio/verifica per la condivisione | oggi scoperta e mutata; domani creata dalla factory `share` (esercizio con `title = X`, verifica con `topic = X`, stessa materia) | la coppia scoperta resta disponibile in sola lettura per le spec di lettura |
| Copia di lavoro 1293 (studio esercizio) | oggi `setup_studio_eser_e2e.sh` + `gen_proof_contract.cjs` su file | le spec di editing passano a un esercizio creato via API (`content.factory`: `POST /api/teacher/content` → `group/add` × 2 → `quesito/{ref}/duplicate`, rotte esistenti; **ASSUMPTION** che `group/add` produca almeno un quesito, come `teacher_content_db.spec.js:73-80` lascia intendere). Il contratto «ricco» (liste annidate, TikZ, GeoGebra) resta necessario per `studio_eser_mathjax` e `studio_eser_editor_toolbar`: la fetta 5 decide se generarlo via API o tenere lo script con teardown |

### 4.3 Isolamento (B.2)

Obiettivo: ogni test gira da solo (`--grep` sul titolo), è ripetibile (due giri di fila) e regge l'ordine casuale entro il worker. Parallelismo, sharding e CI shardable: **non applicabili, documentati** (un DB, tre utenti). Il futuro parallelismo richiede prima istituti e docenti per worker ed è fuori perimetro.

Verifica per ogni spec riscritta (checklist della fetta): (a) `npx playwright test <spec> --grep "<titolo>"` verde da solo; (b) `--repeat-each 2` verde; (c) nessuna riga nuova in `teacher_content`, `verifica_documents`, `print_info`, istanze risdoc, gruppi e grant a fine giro (confronto dei conteggi prima/dopo con `/api/*/list` dal client admin o docente).

### 4.4 Pulizia

- `support/factories/cleanup-registry.ts`: ogni factory registra `{ descrizione, azione }`; la fixture `factories` esegue le azioni in ordine inverso nel teardown, anche in caso di fallimento, e allega al report ciò che non è riuscita a cancellare (mai in silenzio).
- Ripristini di stato (flag `shared_with_pool`, soglie di sicurezza in `admin_users_security_api`, modalità di registrazione) passano dallo stesso registro: si legge lo stato prima, si registra il ripristino, poi si muta.
- Il pregresso (residui degli ultimi mesi) si toglie una volta con lo strumento del §3.2 n. 3, eseguito a mano dall'utente, non dalla suite.

### 4.5 Strumenti esterni

`pdflatex` (MiKTeX), `pdftoppm` (poppler), `unzip`/`Expand-Archive`, servizio TeX su 8001. Fixture `strumenti` che verifica la presenza del binario richiesto dal test (tag `@pdflatex`, `@pdftoppm`, `@tex`) e fallisce subito con un messaggio esplicito. `mysql.exe` come root da una spec: da sostituire (§3.2 n. 10).

---

## 5. PHASE 4: blueprint delle directory

Adattato al vincolo B.1: spec in JavaScript, infrastruttura nuova in TypeScript sotto `tests/e2e/support/`. Le spec importano **solo** `./support/test` (e, in casi dichiarati, `./support/env`).

```
tests/e2e/
├── support/                        TypeScript strict; nessun `any`, nessun cast inutile
│   ├── test.ts                     `test` esteso con tutte le fixture + `expect`; unico ingresso per le spec
│   ├── env.ts                      variabili d'ambiente (credenziali, URL scoperte) lette e validate una volta; nessun valore nei log
│   ├── fixtures/
│   │   ├── auth.fixture.ts         teacherPage/adminPage/teacher2Page, teacherApi/adminApi/teacher2Api, freshLogin
│   │   ├── factories.fixture.ts    factory legate al client del ruolo + registro di pulizia con teardown garantito
│   │   ├── diagnostics.fixture.ts  raccolta errori JS/console e richieste 4xx/5xx; allegate al report solo su fallimento
│   │   └── tools.fixture.ts        presenza di pdflatex/pdftoppm/servizio TeX per i test taggati
│   ├── auth/
│   │   ├── session-store.ts        lettura/scrittura di tests/e2e/.auth/<utente>.json (stesso formato di helpers.js)
│   │   └── login.ts                login dal modulo, controllo /auth/user-info, retry documentato sul 403 CSRF
│   ├── api/
│   │   ├── http.ts                 wrapper su APIRequestContext: CSRF, JSON tipizzato, errori con metodo+URL+stato+corpo
│   │   ├── types.ts                contratti minimi delle risposte usate (derivati dalle risposte reali, §6.8)
│   │   ├── teacher-content.api.ts  /api/teacher/content*: crea, leggi, pubblica, group/add, quesito/*, share-pool, cancella
│   │   ├── verifica.api.ts         /api/verifica/*: save-tex, save-tex-batch, list, tex-files, pdf, compile, delete, share-pool
│   │   ├── share.api.ts            /api/teacher/share/*, /api/teacher/pool/*
│   │   ├── risdoc.api.ts           /api/risdoc/templates*, istanze, override, /api/admin/risdoc/*
│   │   ├── print-info.api.ts       /api/teacher/print-info*
│   │   ├── tikz.api.ts             /tikz/render, save-new-element, edit-element, delete-element
│   │   └── admin.api.ts            /api/admin/users, security, notifications, registrations
│   ├── factories/
│   │   ├── cleanup-registry.ts
│   │   ├── content.factory.ts      esercizio/documento con contratto minimo; nome unico
│   │   ├── verifica.factory.ts     batch di varianti o singola; titolo unico
│   │   ├── share.factory.ts        coppia esercizio/verifica condivisibile, gruppi, grant, ripristino flag
│   │   ├── risdoc.factory.ts       istanze e override di un modello
│   │   └── print-info.factory.ts
│   ├── pages/                      Page Object solo per pagine con flussi stabili; nascono con la fetta che li usa
│   │   ├── StudioEsercizioPage.ts  apertura + pronto (segnali §6.6), gruppi, quesiti, editor
│   │   ├── StudioVerificaPage.ts
│   │   ├── HomeSidebarPage.ts      terna, sidepage, blocchi DB
│   │   ├── AreaDocenteTemplatesPage.ts
│   │   └── AdminDashboardPage.ts
│   ├── components/                 componenti riusati da più pagine
│   │   ├── Upbar.ts
│   │   ├── QuesitoEditor.ts
│   │   ├── Sidepage.ts
│   │   ├── Topbar.ts
│   │   └── Modal.ts
│   ├── sync/
│   │   └── signals.ts              registratore degli eventi fm:* (addInitScript), waitForFmEvent, attese sugli attributi *-bound
│   └── tsconfig.json               strict, noImplicitAny, noUncheckedIndexedAccess, types node
├── <area>/*.spec.js                le spec migrano nella sottocartella della loro area quando vengono riscritte (git mv)
├── *.spec.js                       le spec non ancora riscritte restano dove sono
├── helpers.js, studio-eser-helpers.js   restano finché hanno chiamanti; si cancellano nella fetta che migra l'ultimo
├── global-setup.js                 resta JS con // @ts-check; via la riga morta (§3.2 n. 12)
└── README.md                       riscritto nella fetta 1
```

Responsabilità e confini di ogni cartella:

| Cartella | Responsabilità | Può importare | Non può importare |
|----------|----------------|---------------|-------------------|
| spec | comportamento sotto test: prepara con le factory, agisce con pagine/componenti, asserisce | `support/test`, `support/env` | altre spec, `support/api`, `support/auth`, `helpers.js` (dopo la migrazione dell'area) |
| `support/test.ts` | composizione delle fixture | `fixtures/*` | pagine, api |
| `fixtures` | ciclo di vita: sessione, client, factory, diagnostica | `auth`, `api`, `factories`, `pages`, `components`, `sync`, `env` | spec, `test.ts` |
| `auth` | sessioni e login | `env`, `sync` | api di dominio, pagine |
| `api` | chiamate HTTP tipizzate, nessuna logica di test | `http.ts`, `types.ts`, `env` | pagine, fixture, factory |
| `factories` | dati di prova con proprietà e pulizia | `api`, `cleanup-registry` | pagine, fixture |
| `pages` | una pagina: navigazione, pronto, azioni, locator | `components`, `sync` | api, factories, fixture, spec |
| `components` | un componente riusato | `sync` | tutto il resto |
| `sync` | segnali dell'app | solo tipi Playwright | tutto il resto |
| `env` | ambiente | niente | tutto |

---

## 6. PHASE 5: regole architetturali

### 6.1 Direzione delle importazioni

`Spec → test.ts → fixtures → (pages/components) → sync` e `fixtures → factories → api → app`. Vietati: spec → spec, page → spec, component → fixture, api → page, utility → page. Controllo: regola ESLint `no-restricted-imports` nel blocco `tests/e2e/**/*.spec.js` (vieta `./support/api/*`, `./support/auth/*` e, area per area, `./helpers`), più `tsc --noEmit` sul `support/`.

### 6.2 Nomi

- File nuovi o riscritti: `tests/e2e/<area>/<funzione>.spec.js`, nome per funzione (`condivisione/grant-diretto.spec.js`), mai per fase di sviluppo. Il vecchio nome resta citato nel commento di testa per un giro di storia.
- Titoli: `test.describe("<pagina o API>")` + `test("<soggetto> <verbo> <effetto>")` in italiano; nessun codice di fase nel titolo.
- Tag Playwright: `@tex` (servizio TeX), `@pdflatex`, `@pdftoppm`, `@admin`, `@visual`, `@lento` (oltre 60 s). Servono a `--grep`/`--grep-invert` e alla fixture `tools`.
- Support: `*.api.ts`, `*.factory.ts`, `*.fixture.ts`, `XxxPage.ts`, `Xxx.ts` per i componenti.
- Dati creati: prefisso `e2e-` + nome della spec + timestamp in base 36; lo strumento di pulizia del pregresso riconosce il prefisso.

### 6.3 Confini delle fixture

Ogni fixture ha input espliciti (opzioni via `test.use`), output tipizzato, ciclo di vita dichiarato (`test` o `worker`) e teardown prevedibile. Nessuna fixture fa operazioni non richieste: `teacherPage` non naviga da sola (oggi `loginAs` fa `goto` sulla landing salvata: la fixture apre `/` solo se la spec lo chiede). Nessuna «God fixture»: le fixture di ruolo, di dati e di diagnostica sono separate e componibili.

### 6.4 Proprietà dei dati

Chi crea cancella, tramite il registro. I dati scoperti al setup sono di sola lettura. I ripristini di stato (flag, soglie, modalità) si registrano prima della mutazione. A fine giro il conteggio dei dati con prefisso `e2e-` deve essere zero: è un controllo della fetta, non un test.

### 6.5 Locator (B.5)

Ordine: `getByRole` con `name` dal testo, dall'`aria-label` o dal `title`; `getByLabel`; `getByText`; `getByTestId` (attributi aggiunti ai sensi di B.3); infine `#id` per gli id che l'app espone come contratto (`#sel-iis`, `#sel-cls`, `#sel-mater`, `#fm-content`, `#infoVer`, `#type_verAll`), **solo dentro pagine e componenti**. Classi `fm-*` ammesse nei componenti, mai nelle spec. Vietati: CSS strutturali, `.nth()` come scorciatoia, `.first()` senza `filter`, `dispatchEvent("click")` e `page.evaluate` per interagire (ammessi in un componente solo con commento che nomina l'ostacolo e voce nel debito). Molti controlli hanno già un nome accessibile (FACT): i bottoni dei quesiti sono `div role="button" aria-label="Modifica quesito|Elimina quesito|Aggiungi quesito"` (`app/Services/ContractRenderer.php:419-423`), quelli dei gruppi `aria-label="Modifica tipologia|Elimina tipologia"` (righe 497-499), i bottoni della sidebar hanno testo (`views/partials/sidebar.php:409-432`), `js-edit-section` ha `aria-label="Modifica sezione"` (`sidebar.php:366`).

### 6.6 Attese (B.4) e segnali disponibili nell'app

Scala obbligatoria: asserzione web-first → `waitForURL` → `waitForResponse` sull'endpoint reale → evento `fm:*` o attributo di pronto → `waitForTimeout` solo con commento e voce nel debito. Mai `networkidle`.

`support/sync/signals.ts` installa via `addInitScript` (lato test, nessuna modifica all'app) un registratore che conta gli eventi `fm:*` su `window` e `document`; `waitForFmEvent(page, nome, { dopo })` aspetta con `waitForFunction` senza corse fra emissione e ascolto. Per gli attributi di binding basta `expect(locator).toHaveAttribute(...)`.

| Segnale | Emesso in (FACT) | Uso |
|---------|------------------|-----|
| `fm:verifica-ui-loaded` (window) | `js/modules/features/verifica-builder.js:208`, dopo i passi 1-5 di `ensureVerificaMode` (checkIN, selection, posizioni, origini, colori) | studio esercizio/verifica pronto per la selezione A/R |
| `fm:mathjax-ready` (window) | `views/partials/_exercise_assets.php:117` (typeset iniziale), `js/fm-router.js:127` (dopo uno swap) | attese «lascia tipesettare MathJax» |
| `fm:db-sidepage-rendered` (document) | `js/modules/features/db-sidepage.js:194, 309, 598` | sidepage Esercizi/Verifiche/Mappe popolato dal DB dopo cambio terna o click |
| `fm:risdoc-sidepage-rendered` | `js/modules/features/risdoc-sidepage.js:266` | sidepage risdoc (le 2 500 ms di `risdoc_pt_teacher_smoke.spec.js:102`) |
| `fm:sidebar-config-hydrated` | `js/modules/features/sidepage-registry.js:126` | sidebar configurata |
| `fm:category-labels-hydrated` | `js/modules/features/sidepage-category-labels.js:103` | etichette di categoria |
| `fm:editor:ready`, `fm:risdoc:ready`, `fm:drive:ready` | `js/modules/bootstrap.js:301, 346, 322` | moduli caricati in modo pigro |
| `fm:collapsible-expanded` | `js/modules/features/collapsible.js:40` | sezione espansa (i TikZ di quella sezione partono ora) |
| `fm:new-exercise-added` | `js/modules/features/exercise-wizard.js:330`, `upbar-controls.js:422` | wizard «Crea esercizio» concluso |
| `fm:verifica-saved` | `js/modules/features/topbar-modern.js:473, 570, 579, 648, 675`; `section-edit-mode.js:130`; `verifica-detail-modal.js:424`; `verifica-pdf-modal.js:90` | salvataggio verifica dalla topbar/modali |
| `fm:verifica-pdf-batch` | `topbar-modern.js:851` | batch PDF avviato |
| `fm:navigated` | `js/fm-router.js:115`, `js/modules/ui/dom-manager.js:601, 697`, `js/components/pt-document/fm-pt-document.js:166` | navigazione SPA completata |
| `fm:user-changed`, `fm:reg-changed`, `fm:sec-changed` | `js/modules/features/admin-tools.js:165-181, 220-230, 391-422` | mutazioni admin (utenti, registrazioni, sicurezza) |
| `fm:value-change` | 17 componenti risdoc in `js/components/risdoc/*` | campi del documento PT |
| `fm:origin-selected`, `fm:active-institute-changed`, `fm:resource-grant-changed`, `fm:sync-log-updated`, eventi `*-pt-section` | `upbar-controls.js:246`, `core/app-state.js:174`, `student-resource-auth.js:86-173`, `ui/sync-log-store.js:33-49`, `fm-risdoc-pt-*.js` | casi specifici |
| `data-fm-checkin-bound="1"` | `js/modules/features/checkin-handlers.js:1288-1289` | gestori dei quesiti collegati (oggi `gotoEser` li richiama a mano) |
| `data-fm-header-edit-bound`, `data-fm-origin-gen-bound`, `data-fm-tipo-es-bound`, `data-fm-dropdown-bound` su `<html>`; `data-fmbound="1"` sui singoli controlli | `js/modules/features/upbar-controls.js:129-130, 205-206, 299-300, 535-536, 625-626, 647` | upbar pronta: la upbar è renderizzata dal server (`app/Services/Study/StudyPageRenderer.php:824-829` include `_upbar_loader.php`), il commento «caricata dinamicamente» delle spec è vecchio |
| `data-fm-edit-bound="1"` sul bottone `js-edit-section` | usato da `risdoc_pt_teacher_smoke.spec.js:111` (FACT nella spec; punto di emissione da citare nella fetta 7) | modalità modifica del sidepage |

Dove un segnale manca davvero (previsione: chiusura dell'editor con autosave, fine di un'animazione di modale), la scala prevede prima `waitForResponse` sull'endpoint; l'attributo `data-fm-ready` si aggiunge solo se nemmeno quello basta, e si registra in `wiki/testing.md` (B.3).

### 6.7 Asserzioni, mock, visual, timeout, log

- Asserzioni web-first che descrivono comportamento (`toBeVisible`, `toHaveText`, `toHaveCount`, `toHaveURL`); `expect.poll` per lo stato letto via API; `expect.soft` solo per verifiche indipendenti, mai su precondizioni. Vietato `expect(await page.evaluate(...))` quando esiste un locator.
- Mock: nessuno (B.6). Un mock di stato d'errore, se mai servirà, si dichiara in testa al file e non tocca mai `/auth/csrf`.
- Visual: restano le 2 spec con `toMatchSnapshot`; nessun visual nuovo; `css_modernization_baseline` produce solo screenshot e resta come strumento (`FM_BASELINE`), spostata nell'area qualità.
- Timeout: `timeout: 30_000` ed `expect: 5_000` dal config restano; le spec `@tex`/`@pdf` usano `test.describe.configure({ timeout: 120_000 })` in testa al file con commento; niente `test.setTimeout` sparsi; niente `retries` in locale.
- Log: nessun `console.log`; la fixture `diagnostics` allega al report (`testInfo.attach`) errori JS, errori console filtrati e risposte 4xx/5xx solo quando il test fallisce.
- Screenshot manuali: eliminati; `screenshot: only-on-failure`, `video: retain-on-failure`, `trace: retain-on-failure`.

### 6.8 Tipi (B.1, PHASE 14)

- `support/**` in TypeScript strict; `typescript` e `@types/node` entrano nelle `devDependencies`; script `npm run e2e:typecheck` = `tsc -p tests/e2e/support --noEmit`, eseguito a fine fetta insieme a `npm run lint`. ESLint per i file `.ts` (typescript-eslint) non entra in questo lavoro: `tsc` in modalità strict copre i controlli che servono; se ne riparla nel registro del debito.
- Spec JavaScript toccate: `// @ts-check` + JSDoc; il tipo di `test` arriva da `support/test.ts` e propaga i tipi delle fixture.
- Contratti API: `support/api/types.ts` scritto a mano dalle **risposte reali** osservate nelle spec verdi (es. `POST /api/teacher/content` → `{ ok, id }`; `POST /api/verifica/save-tex-batch` → `{ ok, docs: [{ id, variant, tex_url }], batch_id }`; `GET /api/verifica/list` → `{ docs }`; `POST /api/teacher/share/groups` → `{ id }`; `POST …/grants/{source}/{id}` → `{ count }`; `GET /auth/csrf` → `{ token }`; `GET /auth/user-info` → `{ authenticated, username }`). OpenAPI non è utilizzabile come fonte finché il generatore salta le rotte nei gruppi (FACT §1.2): si registra come debito della documentazione API, fuori da questo lavoro. Nessun `any`, nessun `as unknown as`.

### 6.9 Configurazione (PHASE 15)

`playwright.config.js` resta JavaScript con `// @ts-check` (tipi da `defineConfig`). Unica modifica nella fetta 1: `trace: "retain-on-failure"`. `workers: 1`, `retries: 0`, timeout, `storageState` del consenso, `X-Audit-Reason`, reporter e `outputDir` invariati. Il progetto resta uno (Chromium): Firefox/WebKit/mobile non risolvono un problema misurato.

---

## 7. Modifiche all'app richieste (B.3)

Ammesse solo se servono ai test e non cambiano il comportamento. Elenco per le fette 1-2; ogni fetta successiva aggiunge le sue voci a `wiki/testing.md` prima del commit.

| Dove | Cosa | Perché | Spec che ne ha bisogno |
|------|------|--------|------------------------|
| `views/partials/upbar.html:69-113` | `for="…"` sulle sei `<label class="fm-label-btn-drop">` (HideAll Eser, HideAll Soluz, ShowChecked-A/R, CheckAll-A/R) | le caselle non hanno nome accessibile: oggi la spec le attiva con `page.evaluate` + `dispatchEvent("change")` (`studio_eser_upbar.spec.js:10`); con l'etichetta diventano `getByLabel("CheckAll-A").check()`. Solo `upbar-toggle` ha già la sua `<label for>` (riga 19) | studio esercizio (pilota e fetta 5) |
| `js/modules/features/db-sidepage.js:739-741` | `aria-label` uguale al `title` sul bottone `fm-section-add` («➕») | bottone di sola emoji; `getByRole("button", { name: /Crea esercizio/ })` | risdoc/sidepage (fetta 7) |
| `js/components/**` e `views/**`: bottoni di sola emoji con `title` senza `aria-label` (35 in `views/`, altri nei template JS: `fm-item-edit` «✎», `fm-item-reset` «⟲», chiusure «×») | `aria-label` = `title` | stesso motivo | area per area, elencati nella fetta che li usa |
| nessuno per le attese delle spec pilota | | i segnali del §6.6 bastano; un `data-fm-ready` si propone solo con l'evidenza di un'attesa che non ha segnale | |

Nessun `data-testid` è richiesto dalle spec pilota: i controlli chiave hanno ruolo e nome. Ogni bug dell'app emerso durante le fette va nel registro del debito, non nel refactoring.

---

## 8. Fette

Ogni fetta: spec toccate verdi da sole e con `--repeat-each 2`, **giro mirato** verde (vedi sotto), `npm run lint` e `npm run e2e:typecheck` puliti, zero dati `e2e-*` residui, `wiki/testing.md` e `wiki/changelog/2026-09.md` aggiornati, un commit sul ramo, riepilogo di dieci righe e fermata (`FETTA N CHIUSA — ASPETTO ISTRUZIONI`; per la 1: `FETTA 1 CHIUSA — CAMBIA MODELLO E DAMMI IL VIA`). Il merge su `main` lo decide l'utente.

**Quando gira la suite intera** (deciso il 6/9/2026, dopo le fette 1-4a). Il giro completo costa 40 minuti; nelle prime quattro fette ne sono serviti quattro (2 ore e 45 minuti) e hanno trovato un solo problema, per giunta non una regressione del refactoring ma un test storico intermittente (voce 39 del debito). Da qui in avanti:

- a fine fetta, **giro mirato**: le cartelle di area già riscritte (`tests/e2e/{studio,verifiche,risdoc,condivisione,admin,area-docente}`, che condividono factory e dati) più le spec storiche della stessa area funzionale. Sono 5-10 minuti;
- **giro completo prima di ogni merge su `main`**, che è il momento che conta: ogni push su `main` va in produzione. Il totale atteso e la durata si annotano nel changelog come per le fette precedenti;
- se un giro mirato è verde ma quello completo trova una regressione, risalire è immediato: un commit per fetta.

| # | Contenuto | Spec toccate | Criterio di chiusura specifico |
|---|-----------|-------------:|-------------------------------|
| 1 | **Infrastruttura minima** in `tests/e2e/support/` (§5), limitata a ciò che le cinque spec pilota della fetta 2 usano davvero (decisione dell'utente del 6/9/2026): `test.ts`, `env.ts`, fixture di sessione per ruolo sulla cache `.auth/`, `http.ts`, registro di pulizia, `signals.ts`, `diagnostics`; client API `teacher-content`, `verifica`, `share` e `admin` (solo `notifications`) e factory `content`, `verifica`, `share`. **Non** entrano qui: `risdoc.api`, `print-info.api`, `tikz.api`, le factory risdoc e print-info, `tools.fixture`, pagine e componenti: nascono nella fetta che li usa. Contorno necessario: `tsconfig.json`, `typescript` e `@types/node` in `package.json` + script `e2e:typecheck`, `.gitattributes` `tests/e2e/**/*.{js,ts} text eol=lf`, `playwright.config.js` con `trace: retain-on-failure`, `global-setup.js` con `// @ts-check` e senza la riga morta, `README.md` della suite riscritto, regola ESLint che vieta alle spec di importare `support/api|auth|factories`. La prova vera dell'infrastruttura è la fetta 2; qui entra una sola spec nuova, `tests/e2e/infrastruttura.spec.js`, con 3 test (sessione docente riusata, client admin con CSRF, factory che crea e cancella un esercizio), da cancellare a fine lavoro | 0 esistenti, 1 nuova | `tsc` pulito; giro completo 617 + 3 |
| 2 | **Cinque spec pilota**, una per area, riscritte sulle fixture e spostate nella cartella dell'area: `studio/gruppo-e-quesito.spec.js` (da `studio_eser_group_quesito`: esercizio creato via API al posto della copia 1293), `verifiche/produzione-tex.spec.js` (da `verifica_tex_production`: via `deleteAllVerifiche`, factory con pulizia), `risdoc/sidepage-e-documento.spec.js` (da `risdoc_pt_teacher_smoke`: `fm:risdoc-sidepage-rendered` al posto dei 2 500 ms), `condivisione/grant-diretto-e-gruppo.spec.js` (da `g22_s25_granular_share`: due ruoli via fixture, coppia creata dalla factory), `admin/dashboard-notifiche.spec.js` (da `admin_dashboard_notifications`). Modifica all'app: le sei `label for` della upbar. **Misura del guadagno** per ogni pilota, prima/dopo: righe, `waitForTimeout`, login dal modulo, `evaluate`/`dispatchEvent`, errori ingoiati, durata dal JSON, righe residue nel DB | 5 | giro completo invariato nel totale; tabella del guadagno nel changelog |
| 3 | **Condivisione e pool** (le 8 rimaste dopo il pilota): coppia creata dalla factory, niente più mutazioni sulla coppia scoperta; `DOCENTE2_ID` risolto via `/api/teacher/share/colleagues`; ESM → CommonJS con `@ts-check` dove si riscrive | 8 | zero mutazioni sui contenuti scoperti (controllo dei flag prima/dopo) |
| 4a | **Verifiche via API e TeX** (`g27_*` 15, `g20_02/03/06_compensa`, `g19_49_local/tex/l`, `g19_5/6/7`, `g21_1_*`, `tikz_*`, `g16`, `g18`): factory `verifica`, `expect.poll` al posto dei cicli da 20 s, fixture `tools` per `pdflatex`/`pdftoppm`, tag `@tex`/`@pdf`, timeout per file; residui a zero | 30 | `verifica/list` del docente identico prima e dopo il giro |
| 4b | **Verifiche in pagina: stampa, topbar, scelte, editor TikZ** (`verifiche_studio_smoke`, `g19_18`, `g19_30`, `g19_print_info_scelte`, `g20_05`, `g20_06_topbar`, `g20_07_auto_restore/print_info_*/sidepage_verifica_delete/topbar_logos`, `g22_s15_*` 6): `StudioVerificaPage`, `Topbar`, factory `print-info`, `fm:verifica-saved`/`fm:verifica-ui-loaded` | 17 | `waitForTimeout` a zero nell'area |
| 5 | **Studio esercizio** (31): `StudioEsercizioPage`, `Upbar`, `QuesitoEditor`, `HomeSidebarPage`, `Sidepage`; `buttons_smoke` e `all_buttons_coverage` (103 attese, errori ingoiati) riscritti in una spec di fumo per componente che asserisce l'effetto reale di ogni controllo, o eliminati se le `studio_eser_*` coprono già gli stessi endpoint (decisione con l'elenco degli endpoint esercitati); via `studio-eser-helpers.js`; `gen_proof_contract.cjs` sostituito o incapsulato con teardown | 31 | `waitForTimeout` a zero nell'area; helper legacy cancellato |
| 6 | **Editor inline** (28): login dal modulo → fixture; `QuesitoEditor`; attese sui `patch` | 28 | idem |
| 7 | **Risdoc** (25): `AreaDocenteTemplatesPage`, factory istanze/override, `fm:risdoc-sidepage-rendered`, `fm:value-change` | 25 | idem; `aria-label` sui bottoni emoji dell'area |
| 8 | **Area docente + admin** (10 + 9): `AdminDashboardPage`, `admin.api.ts`; `admin_users_security_api` con ripristini registrati; `student_modes_and_public_sidebar` senza `mysql -u root` | 19 | idem |
| 9 | **Pubblico, GDPR, sicurezza, osservabilità + qualità** (14 + 6) e **chiusura**: spostamento delle ultime spec nelle aree, cancellazione di `helpers.js`, documento dei prerequisiti per un'esecuzione notturna su runner self-hosted (`docs/ops/e2e-runner-prerequisiti.md`: XAMPP/MariaDB con dump, PHP 8.3, Node 24, MiKTeX, poppler, servizio TeX, chiavi in `.env.local`, budget 40 minuti, un worker), scorecard finale (PHASE 25), registro del debito residuo (PHASE 23), review BEFORE/AFTER (PHASE 22) in `wiki/testing.md` e nel changelog | 20 | suite intera senza `waitForTimeout` (salvo voci nel debito), senza `helpers.js`, senza `console.log` |

Totale: 5 piloti + 8 + 30 + 17 + 31 + 28 + 25 + 19 + 20 = 183 spec. Stima: ogni fetta costa almeno un giro completo (36 minuti) più i micro-giri; le fette 4a e 5 sono le più lunghe e possono essere spezzate in due commit se il giro completo intermedio è verde.

### 8.1 Spec candidate all'eliminazione (ridondanti con PHPUnit; da verificare nella fetta dell'area, esito nel changelog)

| Spec | Copertura esistente (FACT: file presenti in `tests/Unit`) | Decisione |
|------|------------------------------------------------------------|-----------|
| `g24_xss_sanitization` | `Services/Security/HtmlSanitizerTest.php`, `SvgSanitizerTest.php` | tenere solo il test che passa dal browser (rendering), togliere le asserzioni sull'output del sanitizer |
| `sanitizer_list_tikz_fix`, `tikz_post_normalize` | `TikzScriptValidatorTest.php`, `tests/Unit/Services/Verifica/*` | verificare caso per caso |
| `risdoc_pt_content_export`, `risdoc_usertype_export` | `tests/Unit/Risdoc/**` (PtToTex) | tenere il solo download reale |
| `b4_audit_reason` | `Services/Audit/ActivityLoggerTest.php` | il comportamento del middleware (400 senza motivo) è integrazione: resta |
| `observability_metrics`, `observability_request_id`, `security` | nessun equivalente E2E | restano |

Nessuna spec si elimina prima di aver scritto nel changelog quale test unitario o d'integrazione copre lo stesso comportamento.

---

## 9. Fuori perimetro (D) e domande

Non si fa: conversione in blocco a TypeScript; `workers` > 1, `retries`, timeout alzati per far passare un test; modifiche all'app oltre a B.3; dati creati dalla UI dove c'è un'API; correzione dei bug dell'app trovati dai test (vanno nel registro); report in inglese; CI con sharding.

Da confermare prima della prima riga di codice:

1. Le tre decisioni della sezione B dell'addendum (linguaggio, parallelismo, modifiche all'app): questo blueprint le applica così come sono scritte.
2. PC sveglio per tutta la sessione (impostazioni di alimentazione).
3. Approvazione di questo documento, in particolare: struttura di §5, ordine delle fette di §8, la sostituzione della copia 1293 con dati creati via API (§4.2), la coppia condivisibile creata dalla factory (§3.2 n. 1), le due modifiche all'app di §7.

---

## Appendice A: baseline del 6 settembre 2026

Comando: `npx playwright test --reporter=line,json` con `PLAYWRIGHT_JSON_OUTPUT_NAME=storage/_tmp/e2e-baseline/baseline-2026-09-06.json` (cartella ignorata da git, fuori da `tests/e2e-results/`). Log del reporter `line` accanto al JSON. È il metro di ogni fetta.

| Misura | Valore |
|--------|--------|
| Test | 617 su 617 passati, 0 falliti, 0 saltati, 0 flaky |
| Durata | 37,9 minuti (14:52:01 → 15:29:54), `workers: 1` |
| File di spec | 183 |
| Retry sul 403 CSRF del login (`[loginAs]` nel log) | 0 in questo giro (debito 35: intermittente) |
| Anomalie nel log | 2 righe `[SyncTeX] CLI call failed, fallback parser: Unexpected token '<'` (`g21_1_synctex_click`): il client SyncTeX riceve HTML da un endpoint e ripiega sul parser interno; il test passa grazie al ripiego. Da verificare nella fetta 4a e, se è un difetto dell'app, da registrare nel debito, non da correggere qui |

Test e minuti per area (stessa classificazione di §1.5):

| Area | Spec | Test | Minuti |
|------|-----:|-----:|-------:|
| Studio esercizio | 32 | 145 | 11,8 |
| Editor inline | 28 | 136 | 5,1 |
| Verifiche | 48 | 93 | 10,2 |
| Qualità | 6 | 55 | 2,4 |
| Pubblico, GDPR, sicurezza | 14 | 51 | 0,7 |
| Admin | 10 | 48 | 0,6 |
| Risdoc | 26 | 48 | 2,6 |
| Area docente | 10 | 24 | 3,4 |
| Condivisione e pool | 9 | 17 | 0,9 |

Le dieci spec più lente: `buttons_smoke` 2,1 min per 2 test; `verifiche_studio_smoke` 2,0 (17 test); `tikz_render_smoke_multi` 2,0 (5); `g22_s15bis_fase5_templates_full` 1,8 (11); `all_buttons_coverage` 1,4 (6); `tikz_post_normalize` 1,1 (3); `editor_inline_format_roundtrip` 1,0 (33); `exercise-fixes` 1,0 (9); `studio_eser_editor_toolbar` 0,9 (8); `lighthouse_a11y` 0,8 (5). Le due spec «clicca tutto» costano 3,5 minuti per 8 test: sono le prime candidate della fetta 5.

## Appendice B: comandi (PowerShell 5.1, un comando per blocco)

Giro completo con JSON conservato fuori da `tests/e2e-results/`:

```powershell
$env:PLAYWRIGHT_JSON_OUTPUT_NAME = "storage\_tmp\e2e-baseline\giro-<data>.json"
```

```powershell
npx playwright test --reporter=line,json
```

Una spec da sola, ripetuta due volte:

```powershell
npx playwright test tests/e2e/<area>/<spec>.spec.js --repeat-each 2 --reporter=line
```

Controllo dei tipi dell'infrastruttura (dalla fetta 1):

```powershell
npm run e2e:typecheck
```
