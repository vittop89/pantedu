---
tags:
  - documentazione/api
date: 2026-09-23
tipo: api
status: finale
aliases: ["routing", "api", "routes"]
cssclasses: []
---

# Routing & API

L'inventario completo delle rotte (controller, metodo, middleware locale e
riga — il totale è nella prima riga del file, non qui) è generato:
`docs/ROUTES.md`, da
`php tools/dev/gen_routes_md.php > docs/ROUTES.md`. Quando una sessione di
Claude Code modifica `routes/web.php`, un hook
(`tools/dev/hooks/rigenera-routes.sh`) lo rigenera **nel repository del file
modificato**. Le sessioni girano dentro WSL, e il hook usa il php di lì; se una
sessione è aperta da Windows sulla cartella `\\wsl.localhost\…`, il hook entra in
WSL con `wsl.exe` (`docs/dev/sviluppo-in-wsl.md`, «Che cosa si fa ancora da
Windows»). Se il generatore fallisce, ROUTES.md resta com'era e
il messaggio lo dice. Fino al 15 settembre 2026 lo rigenerava nella cartella
della sessione, e nella copia Windows compariva un ROUTES.md modificato che
nessuno aveva scritto. Questa pagina spiega come leggerlo: i gruppi, i
middleware ereditati e le convenzioni.

## Come è organizzato `routes/web.php`

| Blocco | Middleware del gruppo | Contenuto |
|---|---|---|
| Pubblico | nessuno (singole rotte con `csrf`, `rate:*`) | `/`, `/health`, `/version`, `/login` + `/login/2fa`, `/logout`, `/password/*`, `/register`, `/accesso-classe`, `/auth/csrf`, `/auth/user-info`, `/auth/spid|cie/*` (503), trust page `/legal/*`, `/privacy/*`, `/security`, `/accessibility`, `/dpo-contact`, `/segnalazione-contenuti`, `/parent-consent/{token}`, `/curriculum`, `/api/institutes`, `/api/scuole`, `/storage/signed`, `/api/sidepage/topics`, `/api/access/*`, `/public/sidebar/{key}`, `/public/studio/{id}`, `/api/public/study/*`, `/metrics`, `/api/vitals`, `/waf/fingerprint` |
| Autenticato generico | `auth` (+ `csrf` sulle POST) | `/me/*` (account, consensi, cancellazione, export dati, eventi di custodia, disattivazione della 2FA), `/tos-acceptance`, `/api/tenant/*`. Dal 23/9/2026 `auth` sta su ogni rotta `/me/*` tranne le conferme via email (`/me/account/email/conferma`, `/me/confirm-deletion`: le apre il gettone) e le destinazioni del confinamento (`/me/change-password` e l'iscrizione a `/me/2fa`), che chiedono la sessione nel controller; la regola la verifica `ConfinamentoSulleRotteMeTest` ([[security-notes]]) |
| Governo (super-admin) | `auth`, `role:admin`, `super_admin_required`, `log`, `sadmin_audit:admin_read,governance` (+ `csrf` e `audit_reason:<azione>,<risorsa>` su ogni scrittura) | `/admin/takedown`, `/admin/tos-log`, `/admin/sidebar-config`, `/admin/sections`, `/admin/institutes`, `/admin/gdpr`, `/admin/data-requests`, `/admin/data-breach`, `/admin/subprocessors`, `/admin/logs`, `/admin/crypto-status`, `/admin/backup`, `/admin/monitoring`, `/admin/system/*` |
| Studio | `studio`, `log` | in sola lettura, per lo studente con account e per l'ospite con la credenziale di classe: `/studio/{type}/{ind}/{cls}/{subj}[/{topic}]`, `/api/study/*` (topics, content, content/{id}, verifica/list, header-page, related-verifiche), `/api/sidebar/config`, `GET /tikz/render` |
| Studente e oltre | `auth`, `role:student`, `log` | `/api/studio/*` e `/studio/{ind}/{cls}/{materia}` (legacy su `exercises`), `POST /tikz/render`, `/tex/format`, workspace TikZ, scorciatoie LaTeX, catalogo GeoGebra, fonti e header del docente, stili badge, `/api/probe`; legacy `/eser|/didattica|/lab/{path*}` → `legacy_gone` |
| Docente e oltre | `auth`, `role:teacher`, `teacher_subjects`, `log` | `/area-docente/*` (dashboard, profilo, templates, categorie, fonti, materie, pdf-import, da-categorizzare), `/api/teacher/*` (contenuti, quesiti, gruppi, pubblicazione, export, curriculum, credenziali, istituti, pool, condivisioni, GitHub, drawio, recovery key, import bundle, PDF-Import), `/api/verifica/*`, `/api/risdoc/*`, `/risdoc/view|edit/{id}`, `/teacher/drive/*`, `/api/maps/*` |
| Amministratore | `auth`, `role:admin`, `log` (+ `csrf`, `rate` e `audit_reason:<azione>,<risorsa>` sulle scritture, salvo le esenzioni scritte in `CoperturaMotivazioneTest`) | `legacy_gone` su `/verifiche`, `/risdoc`, `/strcomp_bes_altro`, `/drafts` (le ultime tre erano della zona del collaboratore, tolta il 2026-09-15), `/admin` (strumenti), `/admin/dashboard`, `/admin/tools`, `/admin/migrate`, `/admin/infrastructure`, `/admin/templates`, `/files/*`, `/tikz/*` (CRUD elementi), `/check/*`, `/api/admin/users`, `/api/admin/security/*`, `/api/admin/verifica/*`; sottogruppi `super_admin_required` per analytics, log di accesso, registrazioni, risdoc admin |
| WAF admin | `auth`, `role:admin`, `log`, `super_admin_required` (+ `csrf`, `rate`, `audit_reason` sulle scritture) | `/admin/waf/*` |
| Legacy serviti | nessuno | nessuna dal 2026-09-05: `/log/*` e `LogServeController` rimossi, nessun file PHP eseguito fuori dal router ([[technical-debt]] voce 22 risolta) |

Le rotte in scrittura stanno in sottogruppi `['csrf', 'rate']`; le rotte
costose hanno bucket dedicati (`rate:compile,15`, `rate:content,60`,
`rate:pdf_import_llm,12`, `rate:tikz,200`).

## Middleware

| Alias | Classe | Funzione |
|---|---|---|
| `auth` | `AuthMiddleware` | sessione autenticata, con ruolo, account attivo e super-admin riletti dal database al più ogni 60 s (account disattivato o cancellato, ruolo o super-admin cambiati: sessione chiusa, 401 JSON o login, dal 23/9/2026); confinamento a cambio password o iscrizione 2FA quando dovuti |
| `role:<zona>` | `RoleMiddleware` | `Auth::hasAccess(zona)` da `app/Config/roles.php`; 403 JSON o pagina |
| `studio` | `StudioMiddleware` | con un account come `auth` + `role:student`; senza, solo l'ospite con almeno una credenziale di classe valida (riverificata sul database a ogni richiesta); 401 JSON o rinvio al login |
| `csrf` | `CsrfMiddleware` | token su POST/PUT/PATCH/DELETE |
| `rate[:bucket,N]` | `RateLimitMiddleware` | finestra scorrevole per IP reale (`EdgeContext`) |
| `log` | `AccessLogMiddleware` | `access_log.json` (storico; il registro vero è `audit_activity_log`, scritto dal Kernel) |
| `legacy_gone` | `LegacyGoneMiddleware` | 410 o redirect ai path moderni |
| `sadmin_audit:<azione>,<risorsa>` | `SuperAdminAuditMiddleware` | registra le letture del super-admin in `privileged_access_log` |
| `audit_reason` | `RequiresAuditReasonMiddleware` | motivazione 10-255 caratteri obbligatoria |
| `super_admin_required` | `SuperAdminRequiredMiddleware` | 403 coerente JSON/HTML se non super-admin |
| `teacher_subjects` | `TeacherSubjectsMiddleware` | manda a `/area-docente/materie` il docente senza materie nella scuola attiva, quando naviga fra le pagine; le richieste di dati (`/api/*`, `.json`, `Accept: application/json`, XHR) passano, dal 14/9/2026: un rinvio a una pagina HTML non porta da nessuna parte e rompeva profilo e barra |

Gli alias stanno in `Kernel::MIDDLEWARE`. Un alias che non c'è fa fallire la
rotta: 500 e la riga `[KERNEL] LogicException: Middleware di rotta sconosciuto
«<nome>»` in `error_log`, senza che il controller giri (dal 23/9/2026, A-40;
prima il Kernel lo saltava, e un refuso avrebbe tolto la porta a un gruppo di
rotte in silenzio). Prima dell'unione lo ferma
`tests/Unit/Core/AliasDeiMiddlewareTest.php`, che legge `routes/web.php` con
il Router vero e risolve ogni voce con la stessa regola del Kernel; la stessa
prova vuole che ogni alias registrato serva ad almeno una rotta.

Applicati dal Kernel senza alias: `RequestIdMiddleware::ensure()`,
`WafMiddleware`, `TosAcceptanceMiddleware`, `ActivityLogger` (registro delle
operazioni), `SecurityHeadersMiddleware`. L'alias `waf`, registrato e mai
usato, è stato tolto il 23/9/2026: una rotta che lo avesse usato avrebbe fatto
girare il WAF due volte.

**Un'eccezione, un'esecuzione.** Il Kernel lascia passare la richiesta senza
filtro solo se il WAF si guasta prima di decidere (fail-open, con la riga
`[KERNEL] WAF guasto prima della decisione` in `error_log`). Un'eccezione
della pipeline — gate ToS, middleware di rotta, controller, o il WAF stesso
dopo aver lasciato passare — arriva al catch globale una volta sola: pagina
500 all'utente, e in `error_log` una riga dell'unica esecuzione,
`[KERNEL] <classe>: <messaggio> @ <file>:<riga> | traccia: #0 <file>(<riga>): <funzione>() ; #1 …`.
La traccia si ferma a quaranta passi e non porta gli argomenti delle
funzioni: nel container `zend.exception_ignore_args` è Off, e
`getTraceAsString()` scriverebbe nel registro password e testi passati alle
funzioni (troncati a quindici caratteri). Per chi scrive un middleware: il try avvolge solo i suoi
controlli, e `$next` si chiama fuori. Un catch che richiama `$next` riesegue
il controller e ne ripete gli effetti; fino al 23/9/2026 lo facevano il
Kernel, il gate ToS, il ripiego interno del WAF e `teacher_subjects`. Le
prove: `tests/Unit/Core/KernelEccezioniTest.php` per il Kernel e i cancelli
globali, e `tests/Unit/Core/NextFuoriDaiTryTest.php`, che legge
`app/Middleware/` e fallisce su ogni `$next(...)`, o chiamata di un suo alias
diretto (`$avanti = $next;`), dentro un try con catch o dentro un catch.
Quel controllo legge la forma, non il comportamento: non vede
`call_user_func($next, …)`, `$next` passato a un aiutante o messo in una
proprietà, né una closure che lo cattura e si chiama nel try. Quelle forme
si guardano in revisione (l'elenco completo è nel commento della prova).

## Convenzioni delle risposte

- JSON: convenzione dopo [[decisions/ADR-034-helper-condivisi-controller]]
  (2026-09-04) è `Response::ok($data)` → `{ok: true, ...}` e
  `Response::fail($error, $status)` → `{ok: false, error: '<codice>'}`;
  `Response::json([...])` resta il primitivo di basso livello che i due usano.
  Alcune rotte legacy rispondono `success` o solo `error` senza `ok`
  ([[technical-debt]] voce 21, in parte: la migrazione a `ok`/`fail` non è
  finita).
- Errori di autenticazione: 401 `unauthenticated` (JSON) o redirect a
  `/login?redirect=`; 403 `forbidden` con il ruolo; 403 `csrf_invalid`;
  400 `audit_reason_required`; 429 dal rate limiter; 403 JSON
  `waf_challenge`/`request_blocked` dal WAF.
- Body: lo legge solo `Request` (ADR-034, ADR-049; dal 23/9/2026 nessun
  controller legge `php://input`): `json()` tollerante (`[]` se vuoto o non
  JSON), `jsonObbligatorio()` rigoroso, `body()` per JSON o form,
  `rawBody()` per i corpi binari; tutti con un limite di byte (predefinito
  quello di nginx, 128 MB). Un corpo troppo grande è **413**
  `payload_too_large`, uno vuoto o non JSON dove serve **400**
  (`empty_payload`, `invalid_json`): lo porta `CorpoNonValido`, e se il
  controller non la prende risponde il Kernel. Un `catch (Throwable)` intorno
  alla lettura usa `CorpoNonValido::statoPer($e, …)`. `Request::input()`
  copre solo POST e query.
- Condizionali: `Response::withETag()` (304) e `withNoCache()`.

## Rotte con semantica particolare

| Rotta | Nota |
|---|---|
| `POST /login` → `GET/POST /login/2fa` | la sessione si apre solo dopo il secondo fattore, se richiesto |
| `POST /api/access/student-login` | grant in sessione senza account (`ClassAccessGrant`) |
| `GET /api/maps/dl?t=&s=` | pubblica: la firma HMAC è l'autorizzazione |
| `GET /storage/signed` | idem, per gli oggetti di storage |
| `GET /auth/grafana-gate` | usata da nginx `auth_request`, nessun middleware |
| `POST /admin/migrate/run` | migrazioni via web per installazioni senza SSH; super-admin nel controller, motivazione obbligatoria (`audit_reason`) |
| `GET /risdoc/{path*}` | catch-all per asset legacy dei modelli, registrato per ultimo |
| `/api/teacher/subjects` | rimossa il 2026-09-02: le materie si governano da `/area-docente/materie` e `/admin/sections` |

## Come cambiare una rotta

1. Modifica `routes/web.php` nel gruppo giusto (il middleware del gruppo si eredita).
   `Router::match` prende la prima rotta registrata che combacia: una rotta
   jolly (`{path*}`) va dopo le rotte vere che copre, altrimenti le cattura con
   le sue porte. `OrdineDelleRotteJollyTest` lo controlla (dal 2026-09-23).
2. Rigenera `docs/ROUTES.md` e, se l'API è pubblica, la spec OpenAPI
   (`composer openapi:build`, poi `openapi:validate`).
3. Se la rotta è amministrativa e scrive (sotto `/admin` o `/api/admin`, con
   `super_admin_required` o con la sola zona `admin`), aggiungi
   `audit_reason:<azione>,<risorsa>` e fai mandare la motivazione al suo
   chiamante: lo pretendono `tests/Unit/Core/CoperturaMotivazioneTest.php` e
   `ChiamantiMandanoLaMotivazioneTest.php`. Se legge dati altrui da
   super-admin, `sadmin_audit`.
4. Aggiorna la voce del mese in [[changelog]].
