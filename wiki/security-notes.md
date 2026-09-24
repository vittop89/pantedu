---
tags:
  - documentazione/security
date: 2026-09-23
tipo: security
status: finale
aliases: ["security", "sicurezza", "auth"]
cssclasses: []
---

# Security Notes

Meccanismi di sicurezza implementati, con il file che li realizza. Per il WAF
vedi [[waf]]; per gli audit assistiti [[_security]]; per le decisioni
[[decisions/ADR-006-envelope-encryption]], [[decisions/ADR-008-audit-reason]],
[[decisions/ADR-015-xss-sanitization]], [[decisions/ADR-032-deployment-scenarios]].

## Stato degli audit

| Audit | Esito | Dove |
|---|---|---|
| Pentest whitebox 2026-04-29 (24 finding) | remediation completata in Phase 25.R; i finding SECRET-* riguardavano cartelle e file oggi rimossi dal repository | `docs/security/pentest/2026-04-29/` (escluso dal repo pubblico) |
| Quick scan Burp 2026-05-18 (FND-001…004) | tutti chiusi (polyfill.io, `unsafe-eval`, X-Powered-By, cookie Secure) | [[_security]] |
| Audit 2026-06-14 (FND-008…011) | tutti chiusi: CSRF sul form DPO, verb tampering, SVG sanitizer su `saveImage`, `\input` arbitrari nel compile TeX | [[changelog/2026-06]] |
| Revisione architetturale 2026-09 | cinque rilievi di sicurezza chiusi (CI morta, avvisi sulle dipendenze, versione dell'informativa nei consensi, librerie da CDN, legacy `log/` servito con `require`) | [[technical-debt]] voci 16 e 22, tabella dei risolti |

Cadenza: audit annuale (prossimo 2027-04) o anticipato a un rilascio maggiore.

## Auth overview

```
POST /login → Auth::attempt(establishSession: false) → password ok?
   → se il ruolo richiede 2FA o l'utente l'ha attivata: Session pending_2fa → GET/POST /login/2fa
       → TotpService (app, codice monouso) oppure EmailSecondFactor (codice via email) → Auth::establishSession()
   → altrimenti Auth::establishSession() subito
establishSession: session_regenerate_id(true), claim in sessione, reset dei claim del login precedente
AuthMiddleware: claims riletti dal database al più ogni 60 s → account disattivato o cancellato, ruolo o super-admin cambiati: sessione chiusa, 401 / login
               must_change_password → confinato a /me/change-password; must_enrol_2fa → confinato a /me/2fa (anche sulle /me/*)
Landing: docente → /area-docente/dashboard, amministratore → /admin/dashboard
```

## Meccanismi

### Sessione e login

**Implementazione**: `app/Core/Auth.php`, `app/Core/Session.php`, `app/Controllers/AuthController.php`
**Chiavi di sessione**: `autenticato`, `username`, `user_id`, `user_role`, `is_super_admin`, `active`, `claims_at`, `login_time`, `authenticated_section`, `current_institute_id`, `pending_2fa`, `must_change_password`, `must_enrol_2fa`
**Claims della sessione** (2026-09-23): `user_role`, `is_super_admin` e `active` si rileggono dal database al più ogni `Auth::CLAIMS_TTL_SECONDS` (60 s, e non di più: lo fissa `AccountRevocatoFuoriDallaSessioneTest`), contati da `claims_at`. Li rilegge `Auth::chiudiSeRevocata()`, che chiamano `AuthMiddleware`, `AclPolicy::isSuperAdmin()` e `/auth/user-info` prima di rispondere; una domanda che vi torna mentre la verifica è in corso (il registro chiede chi è l'attore) riceve no e non la ripete. La sessione si chiude se l'account non c'è più, se è disattivato, e se ruolo o flag di super-admin non sono più quelli della sessione: alla prima richiesta dopo il minuto, con la risposta di chi non ne ha una (401 JSON alle API, il login alle pagine) e una riga `session_revoked` in `audit_activity_log` con il motivo (`account_assente`, `account_disattivato`, `ruolo_cambiato`, `super_admin_cambiato`). Un cambio non si prende in corsa: al login dopo ripassano tutti i controlli del login, e per una promozione ad amministratore sono il secondo fattore obbligatorio. Il cambio che un amministratore fa sul proprio account dal pannello passa invece da `Auth::refreshCurrentUserClaims()`, che accetta i valori nuovi nella stessa richiesta. Se il database non risponde non si chiude niente e si riprova alla richiesta dopo. Costo misurato: una SELECT per sessione al minuto (`SessioneRilettaDalDatabaseTest`, contatore `Com_select`). Prima di quel giorno si leggevano solo al login: un account disattivato restava dentro fino a dodici ore, con il ruolo di prima
**Id di sessione** (2026-09-14): cambia a login e secondo fattore (`Auth::establishSession`) e quando un amministratore cambia il proprio ruolo dal pannello (`Session::regenerate`), mai a tempo: la rotazione ogni cinque minuti disconnetteva chi aveva richieste in parallelo (voce 101 del debito). Un ruolo cambiato da un altro amministratore chiude le sessioni aperte di quell'account alla rilettura dei claims, entro un minuto. Scadenze: inattività `SESSION_LIFETIME` (1800 s) e durata massima dal login `SESSION_ABSOLUTE_LIFETIME` (43200 s)
**Dove stanno le sessioni** (2026-09-14, ADR-039): lo dice `SESSION_DRIVER`, mai l'esistenza di una tabella. A file in `storage/sessions` dei dati d'istanza (0700, di chi serve le pagine: i nomi dei file sono gli id), che sopravvive allo scambio dei container ed è fuori dai salvataggi. Modalità stretta e pulizia le mette `Session::impostazioni`, uguali in ogni ambiente. Un posto che non funziona è un 500 servendo pagine, e ferma il container all'avvio (`docker/verifica-avvio.php`)
**Rate limit**: `RateLimiter` 5 tentativi / 300 s per sessione (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS`) + `rate:login,10` sulla rotta
**Blocchi**: `BlockList` (file JSON legacy) e `WafBruteforceGuard` (lockout per username e ban IP su credential stuffing, NAT-safe, tabella `waf_blocked_credentials`)
**Lacune**: nessun binding IP/device; la rilettura dei claims sta in `AuthMiddleware` (e in `studio`, che lo usa), in `AclPolicy::isSuperAdmin()` e in `/auth/user-info`, che dal 23/9/2026 chiude la sessione revocata prima di rispondere, quindi un'altra rotta senza `auth` la chiude solo se chiede il flag. Lo chiedono il cancello di Grafana e `/metrics` (401), il gate globale dei Termini quando è acceso e il layout delle pagine (`views/layout/app.php`, letto nel codice). Lo chiede anche ogni riga del registro delle operazioni scritta con una sessione aperta (`ActivityLogger` → `Auth::actorRole()`). Le rotte senza `auth` che non lo chiedono lavorano ancora con la sessione di un account revocato, finché una richiesta che lo chiede non la chiude (il 23/9/2026 erano 78 rotte su 561 senza `auth` né `studio`)

### Secondo fattore (2026-09-01)

**Implementazione**: `app/Services/Security/TotpService.php` (RFC 6238),
`EmailSecondFactor.php` (codice via email, tabella `two_factor_email_codes`),
`TwoFactorPolicy.php`, `app/Support/TwoFactorEnforcement.php`,
`app/Controllers/TotpController.php` (`/me/2fa/*`), `app/Services/Security/QrCode.php` (QR generato in locale, mai verso servizi esterni)
**Obbligo per ruolo**: `SECURITY_TOTP_REQUIRED_ROLES` con override runtime dal pannello (`storage/config`); chi deve iscriversi è confinato a `/me/2fa` da `AuthMiddleware`
**Un codice dell'app vale una volta** (2026-09-23, RFC 6238 §5.2): la finestra accetta il passo corrente e i due vicini, e `users.totp_last_counter` (migrazione 140) ricorda l'ultimo passo accettato. `TwoFactorPolicy::verifyTotp` passa solo con un passo strettamente maggiore, con un UPDATE condizionato che confronta e scrive insieme (due richieste parallele con lo stesso codice non passano entrambe); se la scrittura fallisce il codice non vale. Anche il codice dell'iscrizione (`TotpController::enable`) è consumato. Prima lo stesso codice apriva l'accesso per circa novanta secondi, quante volte si voleva. Prove: `TwoFactorPolicyTest`, `SecondoFattoreDalProfiloTest`
**Confinamento sulle `/me/*`** (2026-09-23): le rotte `/me/*` che lavorano sulla sessione portano `auth`, quindi con `must_change_password` o `must_enrol_2fa` esportazione, consensi, cancellazione e profilo si fermano alla porta. Restano senza `auth` le conferme via email (`/me/account/email/conferma`, `/me/confirm-deletion`: le apre il gettone) e le destinazioni del confinamento (`/me/change-password`, l'iscrizione a `/me/2fa`), che chiedono la sessione nel controller. Regola sulla tabella delle rotte: `ConfinamentoSulleRotteMeTest`
**Errori**: se il database non scrive, `/me/2fa` lo dice (la disattivazione fallita non è più «2FA disabilitato»), e il messaggio del database resta nel registro del server, non sulla pagina
**Rientro**: codici di backup monouso; recupero password self-service (`PasswordResetController`, `PasswordResetService`, tabella `password_resets`, IP conservato come hash)
**Password**: `PasswordPolicy`, `HibpService` (controllo password compromesse, fail-open), `must_change_password` per gli account one-time

### Credenziale di classe (scenari 1 e 2)

**Implementazione**: `app/Support/ClassAccessGrant.php`, `AuthController::showClassAccess`, `TeacherCredentialController::studentLogin` (`POST /api/access/student-login`, csrf + rate)
**Flusso**: il docente crea una credenziale per classe (`teacher_access_credentials`); lo studente la inserisce su `/accesso-classe` e ottiene un grant in sessione che apre solo i contenuti pubblicati da quel docente per quella classe. Nessun account studente esiste negli scenari 1 e 2 ([[decisions/ADR-032-deployment-scenarios]]).
**Sola lettura**: chi entra con la credenziale non ha un account, quindi le API di scrittura del docente gli rispondono 401 (vedi «Ruoli sulle scritture del docente» qui sotto); la barra non gli mostra comandi di modifica.

### Ruoli sulle scritture del docente

**Regola**: ogni rotta che scrive (POST, PUT, PATCH, DELETE) sotto `/api/teacher/`, `/api/maps` e `/api/risdoc/` porta `auth` e un middleware di ruolo con le sole zone `teacher` o `admin`. Chi non ha un account (visitatore, ospite con la credenziale di classe) riceve 401; lo studente con account 403 da `RoleMiddleware`.
**Verificata da**: `tests/Unit/Core/CoperturaRuoliTest.php`, sulla tabella delle rotte (106 controllate il 19/9/2026, nessuna eccezione) e sulla catena dei middleware di quattro rotte campione, nei due versi. Il primo giro ha trovato cinque `PUT` delle preferenze del docente (`/api/teacher/{sources,sources.registry,checked-origins,header-page}.json`, `/api/teacher/badge-style`) nel gruppo degli studenti: le fermava solo il controller, perché uno studente non ha scuole in `teacher_institutes`. Spostate nel gruppo del docente.
**API dell'amministratore** (dal 23/9/2026): ogni rotta sotto `/api/admin/`, **anche in lettura**, porta `auth` e la sola zona `admin`; i controlli dentro i controller (super-admin, `hasAccess('admin')`) restano in più, non al posto. Stessa prova, nei due versi, con la catena dei middleware delle rotte spostate. Il primo giro ha trovato sei rotte nel gruppo degli studenti (i preset dello stile dei badge e il riferimento delle scorciatoie LaTeX, A-78): spostate con gli stessi middleware locali, e la prova verifica anche che la porta nuova lasci passare il super-admin con ruolo di docente o di studente.
**Interfaccia**: i comandi di modifica della barra (✎, «+», azioni sulle voci) si danno solo con il segno di permesso `.js-edit-section` e con `body.fm-can-edit`, che il server scrive per docenti e amministratori (`risdoc-sidepage.js`, `section-edit-mode.js`, `sidepage-inline-actions.js`). Prova: `tests/js-unit/risdoc-sidepage-permessi.test.js`.

### CSRF — token TTL-based

**Implementazione**: `app/Core/Csrf.php`, `app/Middleware/CsrfMiddleware.php`
**Copertura**: rotte POST/PUT/PATCH/DELETE con il middleware `csrf`; token in `_csrf` o header `X-CSRF-Token`; 403 JSON (`csrf_invalid`) se `wantsJson()`
**Verificata da**: `tests/Unit/Core/CoperturaCsrfTest.php`, che legge la tabella delle rotte — al 9/9/2026: **244 protette, 15 esentate con motivo scritto** (segnalazioni del browser che non possono portare un gettone, `sendBeacon`, rotte autenticate dal collegamento firmato, percorsi legacy che rispondono 410 senza toccare niente). Il test fallisce anche se un'esenzione non corrisponde più a nessuna rotta. Dal 23/9/2026 guarda anche le letture che arrivano a un gestore protetto dal gettone, che il middleware in GET e HEAD non controlla: ogni rotta del gruppo `csrf` che accetta GET o HEAD (una `get()` come una `any()`), e ogni GET, dentro o fuori dal gruppo, che porta allo stesso gestore di una scrittura col gettone. Una rotta così si dichiara col solo verbo di scrittura, oppure si esenta con il motivo per cui la sua GET non fa danni (sei il 23/9, fra cui `/api/verifica/{id}/tex-files`, che ha lettura e scrittura in due dichiarazioni con due gestori). Il raggruppamento per percorso non si usa: più di quaranta percorsi hanno la pagina in GET e l'invio in POST con due gestori, e sarebbero eccezioni senza contenuto. Era il caso di `/files/clear-temp`, che svuotava i temporanei anche in GET (A-77 della revisione del 23/9): adesso è solo POST, e una prova dice per nome che GET e HEAD non trovano rotta.
**Client**: fonte unica `dom-utils.fetchCsrf` (cache 60 s). `tools/ci/check-meta-letti.mjs` impedisce di tornare a leggerlo da un `<meta>` che nessuna vista emette.
**Diagnostica**: il middleware distingue gettone **assente** / **vuoto** / **sbagliato** e scrive nel registro delle anomalie. Un gettone vuoto non lo manda nessun attaccante: è sempre codice nostro che non lo trova ([[../docs/ops/diagnostica|diagnostica]]). Dal 24/9/2026 la pagina di provenienza (Referer) si scrive senza query string né frammento e la rotta con i gettoni del percorso mascherati (`PercorsoSenzaGettoni`): prima il gettone ancora valido di `/me/confirm-deletion?token=…` finiva nel registro. Prova: `CsrfRegistroSenzaGettoniTest`
**Lacune**: non single-use entro il TTL di 7200 s ([[technical-debt]] voce 7)

> Il 9/9/2026 sette rotte che scrivono non verificavano il gettone e quattro funzioni ne mandavano uno vuoto, da mesi. La regola semgrep che avrebbe dovuto accorgersene era malformata e non è **mai** girata; peggio, il passo di CI restava verde. La copertura CSRF non si legge dai controller — il middleware si applica alla **rotta** — ed è per questo che ora la verifica un test sulla tabella delle rotte. Voci 88-90 del [[technical-debt]].

### Pagine con un gettone nell'indirizzo (2026-09-24)

**Regola**: una pagina che ha un gettone nella query string — i collegamenti delle email: `/me/confirm-deletion?token=…`, `/me/account/email/conferma?token=…`, `/password/reset?token=…` — si rende con `App\Support\PaginaConGettone`: intestazione `Referrer-Policy: no-referrer` (che `SecurityHeadersMiddleware` lascia com'è; ogni altro valore lo sostituisce con `strict-origin-when-cross-origin`) e `<meta name="referrer" content="no-referrer">` in testa, prima di fogli di stile e script. Senza, il browser manda l'indirizzo intero, gettone compreso, come Referer al POST del pulsante e al caricamento di fogli di stile e script.
**Perché anche il meta**: il vhost di nginx sull'host aggiunge la sua `Referrer-Policy` dopo quella dell'applicazione, e con due intestazioni il browser applica l'ultima. Misurato con Chromium il 24/9/2026 dietro un proxy che fa lo stesso: con la sola intestazione 9 richieste su 10 portavano il gettone, con il meta nessuna. `rel="noreferrer"` sul modulo non basta: Chromium lo ignora sui moduli.
**Verificata da**: `SecurityHeadersReferrerPolicyTest` (il meccanismo, nei due versi) e `ReferrerPolicyDellePagineConGettoneTest` (le tre pagine vere, e le altre con la politica di sempre).

### Rate limiting

**Implementazione**: `app/Middleware/RateLimitMiddleware.php`, `app/Services/RateLimitStore.php` (backend `db` quando disponibile, `session` altrimenti), bucket `rate:<bucket>,<N>[,<finestra in secondi>]` per rotta
**IP**: risolto da `EdgeContext` (IP reale dietro il CDN)
**Finestra** (2026-09-22): senza il terzo parametro vale un minuto, come è sempre stato. Lo dichiarano solo `rate:dpo,3,3600` e `rate:takedown,3,3600`, i due moduli pubblici che mandano posta a ogni invio: erano descritti come «3 all'ora per IP» in tre posti — fra cui la pagina pubblica `/legal/takedown-procedure` — e operavano a tre al minuto, cioè centottanta all'ora. Prova: `tests/Unit/FinestraDelLimitatoreTest.php`, che confronta la dichiarazione delle rotte con quella dei documenti e verifica il conteggio seminando colpi vecchi di due minuti
**Nelle prove il limitatore è spento**: una prova che si aspetta «la richiesta passa» è verde senza aver misurato niente. Si forza con l'intestazione `X-Pantedu-Rate-Limit: enforce`, che opera solo in senso restrittivo
**Dove il limitatore è spento, e il `.env` tracciato che non lo spegne** (2026-09-23): [[environment-variables]], «Dove `RATE_LIMIT_DISABLED` vale 1»

### WAF applicativo e difesa di bordo

Applicato globalmente da `Kernel::handle()`; toggle in tabella `waf_config`.
Catena: enabled/mode → bypass path → honeypot → whitelist → blacklist →
threat-intel → CrowdSec → geo → regole → sessione HMAC + challenge
proof-of-work. Fail-closed verso la challenge; JSON 403 per XHR e `/api/*`.
Dettaglio in [[waf]]. Bordo raccomandato: CDN + firewall origin + nginx
`real_ip`/`limit_req` (`infra/nginx/`).

**L'indirizzo del client ha una regola sola** (2026-09-23):
`EdgeContext::clientIp($server)`, che crede agli header di forwarding
(`CF-Connecting-IP`, `X-Forwarded-For`) solo se la connessione arriva da un
proxy fidato (range del CDN, `TRUSTED_PROXIES`, marcatore `WAF_EDGE_TRUSTED`).
La usano limitatore, brute force, WAF, login (blocco dell'IP per sezione),
consensi e cancellazioni, hash dei registri di audit (`RequestFingerprint`),
`access_log.json` e il pannello del WAF. Fino a quel giorno, tranne i primi tre,
leggevano da sé `Client-IP` e `X-Forwarded-For`, che sceglie il client: il
blocco per sezione si aggirava con un indirizzo inventato, e gli hash nei
registri erano falsificabili (A-63 della revisione del 23/9). La guardia è
`tests/Unit/SoloEdgeContextLeggeGliHeaderDeiProxyTest.php`: nei file PHP di
`app/`, `public/`, `routes/` e `views/`, tranne EdgeContext, nessuna stringa
(i commenti no) è il nome di `Client-IP`, `X-Client-IP`, `X-Forwarded-For`,
`X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP` o `Forwarded` nella forma di
`$_SERVER` e `Request::$server` (`HTTP_X_FORWARDED_FOR`) o in quella di
`Request::$headers` e `getallheaders()` (`x-forwarded-for`), senza distinzione
di maiuscole. Una stringa che contiene uno di quei nomi senza esserlo
(`Forwarded` escluso, che sta anche dentro `X-Forwarded-Proto`) fa fallire la
prova finché non è elencata in lei, col perché, fra le menzioni che non sono
letture: oggi solo `'X-Forwarded-For: 127.0.0.1'` in `ControlloTex`, un header
in uscita verso il nginx dell'host. Anche un'eccezione elencata che il codice
non contiene più la fa fallire. Non vede un nome composto da più pezzi
(`'HTTP_' . $nome`) né gli script di `tools/`. Le letture dirette
di `REMOTE_ADDR` (una decina, fra cui i Termini e il recupero delle chiavi)
non sono falsificabili; dietro i due nginx del repository
(`infra/nginx/pantedu.eu.container.conf`, `docker/nginx.conf`), che
riscrivono l'indirizzo con `real_ip`, coincidono con EdgeContext.

### Ruoli e super-admin

**Implementazione**: `app/Config/roles.php`, `Auth::hasAccess()`, `RoleMiddleware`, `SuperAdminRequiredMiddleware`, `AclPolicy`, `TeacherCapabilityPolicy` (ADR-028)
**Gerarchia**: guest < student < teacher < administrator (il collaboratore non esiste più dal 2026-09-15: non apriva niente in più del docente, migrazione 128); `is_super_admin` è un flag ortogonale che apre la zona admin e le operazioni di governo.
**Chi è super-admin** (2026-09-23): lo dice solo `AclPolicy::isSuperAdmin()`; `Auth::isSuperAdmin()` delega. Il valore è uno dei claims della sessione, con il loro TTL di 60 s (vedi «Sessione e login»), e si concede solo a una sessione che, riletta, è ancora quella del login: un super-admin disattivato non passa più nemmeno le rotte senza `auth`, come il cancello di Grafana. Se i claims sono scaduti e il database non risponde, la risposta è no. Prima c'erano due implementazioni: quella che `role:admin` guardava teneva il valore fino al logout, l'altra cinque minuti. Fuori dalla gerarchia l'amministratore di istituto (`institute_admin`): sta solo nella zona `istituto`, e solo nello scenario 3 ([[decisions/ADR-040-amministratore-di-istituto]])
**Un ruolo non si confronta come stringa** (2026-09-14): i nomi stanno in `app/Config/roles.php` e in `App\Domain\Role`. L'alias storico `admin` non esiste più (ADR-040): dava i poteri dell'amministratore a un valore che nessuna zona conosceva, e dalla migrazione 125 il database non lo accetta. Sul server si chiede la zona (`Auth::hasAccess()`) o la capacità (`Role::canTeach()`); nel client la zona, da `data-fm-zones` sul body (`Auth::zone()`, `js/modules/core/zone-di-accesso.js`), mai `data-fm-role`. Tre confronti con `'admin'` lasciavano fuori l'amministratore vero, che in database è `administrator`
**Funzioni nuove per l'amministratore di istituto** (2026-09-14): si dichiarano nella zona `istituto` di `routes/web.php`, con l'ambito verificato da `Auth::isAdminOfInstitute($iid)` e una prova d'integrazione nei due versi (il proprio istituto sì, un altro no, lo scenario 2 no). Mai togliendo una rotta dalla zona `admin` o aggiungendo lui a un'altra zona
**Letture privilegiate**: `sadmin_audit:<azione>,<risorsa>` registra in `privileged_access_log` le letture del super-admin (log altrui, richieste GDPR, contenuti di altri docenti)

### Motivazione obbligatoria (ADR-008)

**Implementazione**: `app/Middleware/RequiresAuditReasonMiddleware.php`
**Regola**: header `X-Audit-Reason` o campo `_audit_reason`, da 10 a 255 caratteri; modo `AUDIT_REASON_MODE=enforce` in `.env.example` e in produzione dal 2026-09-02, e predefinito nel codice dal 2026-09-23, anche per un valore sconosciuto (prima era `warn`: rilievo A-37 della revisione del 23/9); `warn` e `disabled` vanno scritti per nome
**Copertura** (2026-09-23): ogni mutazione amministrativa — POST, PUT, PATCH, DELETE sotto `/admin` e `/api/admin`, con `super_admin_required` o con la sola zona `admin` (`/files/*`, `/tikz/*`) — porta `audit_reason:<azione>,<risorsa>`, salvo le esenzioni scritte in `tests/Unit/Core/CoperturaMotivazioneTest.php` (rotte che non scrivono o che toccano solo i temporanei). Prima era scritto «mutazioni admin cross-docente, WAF, risdoc admin, sezioni, toggle di sistema», e 50 mutazioni su 81 sotto `/admin` e `/api/admin` non la chiedevano (A-69)
**Chiamanti**: le fetch mandano `X-Audit-Reason` con `auditReason()` (`js/modules/core/audit-reason.js`), i moduli un campo `_audit_reason`; lo guarda `tests/Unit/Core/ChiamantiMandanoLaMotivazioneTest.php`. Niente IP né nomi utente nel motivo. Un'intestazione con le accentate arriva in ISO-8859-1 e il middleware la converte (prima il registro perdeva la riga)
**Solo super-admin**: il middleware salta tutti gli altri; l'amministratore di istituto non ha mutazioni, e alla prima si decide se estenderlo a lui ([[decisions/ADR-008-audit-reason]])

### Registri di audit append-only (2026-09-02)

| Registro | Contenuto | Scrittore |
|---|---|---|
| `audit_activity_log` | ogni scrittura HTTP e ogni tentativo negato, anche di studenti (filtro `ActivityLogger::shouldLogRequest`, applicato dal Kernel) | `App\Services\Audit\ActivityLogger` |
| `content_action_log` | azioni sui contenuti | `ContentActionLogger` |
| `privileged_access_log` | letture e mutazioni del super-admin con motivazione | `PrivilegedAccessLogger` |
| `teacher_recovery_audit` | generazione e revoca delle chiavi di recupero | `TeacherRecoveryService` |

Trigger `trg_append_only_*_update` (migration 098) creati dall'utente delle
migrazioni; l'utente applicativo non ha DELETE su questi registri; la purga
gira con l'utente di manutenzione (`Database::maintenanceConnection()`,
timer `pantedu-gdpr-retention`). IP e User-Agent solo come impronta
(`RequestFingerprint`, migration 100); l'IP è quello deciso da `EdgeContext`
dal 2026-09-23, e le righe di prima possono avere l'hash di un indirizzo scelto
dal client.

**L'impronta di un IP la calcola solo `App\Support\ImprontaIp`** (2026-09-24):
un HMAC-SHA256 con una chiave derivata con HKDF da `waf.hmac_secret`, con
un'etichetta sua (lo schema dell'impronta di sessione di `AccessLogger`). Vale
per tutti i registri che tengono un indirizzo come impronta: i quattro qui
sopra, `consents`, `consent_audit`, `parent_consents`, `deletion_requests`,
`dpo_requests`, `password_resets` ed `email_change_requests` (in 64 cifre
esadecimali), e le segnalazioni CSP (`ImprontaIp::delGiorno`, che cambia ogni
giorno). Fino a quel giorno era `hash('sha256', $ip)` senza chiave: un IPv4 si
ritrova provando i circa 4,3 miliardi di valori. Anche con la chiave resta un
dato pseudonimo, non anonimo. Senza segreto l'impronta è null e si registra
l'anomalia `impronta_ip_senza_segreto`: niente ripiego senza chiave. Senza
`WAF_HMAC_SECRET` nell'ambiente, però, `app/Config/waf.php` un segreto lo ha
comunque: lo genera e lo scrive in `storage/keys/waf_hmac.key` della cartella
dati. L'impronta si calcola (la chiave è segreta), ma vale solo per quella
cartella dati; in sviluppo e in CI è lo stato normale, in produzione
(`APP_ENV=production`) la stessa anomalia lo segnala con la causa
`chiave_generata_sul_posto` nei dettagli: il segreto in uso non è
`WAF_HMAC_SECRET` dell'ambiente (lo dice `waf.hmac_secret_dall_ambiente`,
calcolato in `app/Config/waf.php` accanto al segreto). Le righe
scritte prima restano con lo SHA-256 fino al termine della loro tabella; non
si ricalcolano (non c'è l'indirizzo, e nei registri con la catena di impronte
riscriverle la romperebbe). Un indirizzo noto si cerca nei registri con
`php tools/audit/impronta_ip.php <indirizzo>`, dove gira l'applicazione: stampa
l'impronta con chiave e lo SHA-256 per le righe di prima. Se
`WAF_HMAC_SECRET` dell'ambiente manca, è più corto di 32 byte o non è quello
della configurazione esce con 1, senza impronte e senza scrivere niente: legge
l'ambiente prima di caricare la configurazione, così `waf.php` non genera la
sua chiave (fino al 24/9 stampava l'impronta di una chiave nuova, diversa per
ogni cartella dati, con esito 0). Lo User-Agent resta
SHA-256 senza chiave (le stringhe comuni si ritrovano per confronto: pseudonimo
anche lui). La guardia è `tests/Unit/ImpronteIpSoloConChiaveTest.php`. Legge i
token PHP di `app/`, `public/`, `routes/`, `views/`, `tools/` e `bin/` e
fallisce su: `hash()`, `md5()`, `sha1()` o `crc32()` con fra gli argomenti
qualcosa che porta un indirizzo; `hash_hmac()` o `sodium_crypto_generichash()`
con un indirizzo nel dato e la chiave assente o scritta nel codice (stringhe,
anche vuote, numeri, costanti e loro concatenazioni: niente variabili né
chiamate), per posizione o per nome. «Porta un indirizzo»: un nome di
variabile, proprietà, costante o funzione con `ip`, `ips`, `ipv4`, `ipv6`,
`addr`, `address` o `forwarded` fra le parti (spezzato su `_` e sulle
maiuscole), o una stringa che è una chiave con le stesse parti
(`'REMOTE_ADDR'`, `'HTTP_CF_CONNECTING_IP'`, `'HTTP_X_FORWARDED_FOR'`,
`'HTTP_X_REAL_IP'`, `'ip'`). Le eccezioni sono nel file col perché, e una che
il codice non contiene più la fa fallire. Non vede: un indirizzo sotto un nome
che non lo dice, una chiave costante prodotta da una chiamata
(`str_repeat('0', 32)`), `hash_init()` con `HASH_HMAC` e le altre funzioni, le
formule in SQL. Il percorso si scrive senza query string e con i gettoni mascherati
(`<omesso>`) dal 2026-09-23: prima restava l'URI intero, col gettone permanente
del QR di classe o quello del consenso del genitore dentro (A-81). La regola è
`App\Services\Audit\PercorsoSenzaGettoni`, applicata in `ActivityLogger::write`,
dove passano tutte le righe; la usano anche `access_log.json` e, tenendo la
query, `waf_logs`. I prefissi valgono senza distinzione di maiuscole: il
router le distingue, ma una POST a `/Parent-Consent/{token}` risponde 404 e si
registra lo stesso, col gettone vero. Una rotta nuova con un gettone nel
percorso va in `PercorsoSenzaGettoni::PREFISSI_CON_GETTONE`: lo verifica
`tests/Unit/Services/Audit/PercorsiSenzaGettoniTest.php` sulla tabella delle
rotte. Catena di impronte giornaliera:
`App\Services\Audit\AuditChain` + `tools/audit/export_audit_chain.php`
(timer `pantedu-audit-chain`), impronta esportata col backup cifrato: chi
amministra il database non può alterare una riga senza che la verifica lo dica.

**Nell'export per l'autorità** (sezione `audit_log`, `AuditLogExporter`,
2026-09-23): di `privileged_access_log` le righe in cui il soggetto ha agito
(`user_id`) o in cui il percorso della risorsa lo nomina
(`AuditLogExporter::PERCORSI_DEL_SOGGETTO`: una rotta nuova che nomina un
utente va aggiunta lì); di `crypto_access_log` quelle sulla sua chiave
(`teacher_id`) o fatte da lui (`accessor_id`). Ogni riga porta `relation`
(`actor`, `target`). Il riepilogo dà conteggio e totale di ogni registro,
i criteri (`criteria`) e gli errori (`errors`): un registro che non si legge
ha conteggio null, mai zero.

**Il registro degli accessi** (`access_log.json`, `AccessLogger`, le ultime
mille voci di navigazione) non è un registro di audit: conserva IP e
User-Agent in chiaro, come dichiara il Registro dei trattamenti (B.6). Dal
2026-09-23 non conserva l'id di sessione: al suo posto `session_fingerprint`,
un HMAC-SHA256 dell'id troncato a 16 cifre esadecimali, con la chiave derivata
per HKDF da `waf.hmac_secret` (`AccessLogger::improntaDellaSessione`).
Raggruppa le richieste della stessa sessione, anche la riga di uscita in
`debug.log`, e non si torna all'id. Prima chi leggeva il registro — il
super-admin dal pannello `/admin`, o chi ha i file dei dati — poteva adottare
la sessione di un docente (A-64). Le voci vecchie perdono l'id alla prima
scrittura, e in lettura il pannello non lo mostra.

### Tre utenti di database

`pantedu_app` (DML, niente DDL né DELETE sui registri), utente migrazioni
(`DB_MIGRATOR_*`, solo `tools/migrate.php`), utente di manutenzione
(`DB_MAINT_*`, solo job pianificati). Senza le variabili si ricade sulla
connessione ordinaria: un'installazione non separata continua a funzionare.

**Negli script di shell** (dal 23/9/2026): una password non si passa a
`mysql`/`mysqldump` con `-p…` o `--password=…`, che la mettono fra gli
argomenti del processo, leggibili con `ps`. Si usa `con_credenziali_mysql` di
`tools/security/opzioni-mysql.sh`: file di opzioni temporaneo 0600 passato con
`--defaults-file` (non `--defaults-extra-file`, dopo il quale il client legge
anche `~/.my.cnf` e da root prende le sue credenziali), cancellato all'uscita
anche in errore. Lo usano il salvataggio delle chiavi dei docenti e i due
script che creano le utenze; prova in `tests/ops/credenziali-mysql.test.sh`
(A-86). Restano fuori, per ora, due script sotto `tools/dev/`: la migrazione
una tantum del WAF della fase 25 (`waf_migrate_vps.sh`, migrazione 048 già
applicata) e il database di sviluppo in WSL (`wsl/database.sh`, password del
contenitore locale).

### Cifratura a busta (ADR-006)

**Implementazione**: `app/Services/Crypto/TeacherCryptoService.php`, `EncryptedBlobStore.php`, `TeacherRecoveryService.php`, `ShamirSecretSharing.php`, `app/Repositories/Risdoc/CompilationRepository.php` (compilazioni risdoc cifrate dal 2026-09-04, migration 101)
**Chiave master**: `KMS_MASTER_KEY` solo in `.env.local`; custodia e drill semestrale in `/admin/crypto-status`; runbook in `docs/security/operations/` (esclusi dal repo pubblico). Si legge e si giudica in un posto solo, `app/Services/Crypto/ChiaveMadre.php` (dal 23/9/2026, A-36): valida = 64 caratteri esadecimali; assente = sviluppo, dove le compilazioni restano in chiaro; presente ma malformata = chi scrive si ferma (le compilazioni non si salvano, l'export per l'autorità esce senza firma), e l'errore si registra senza il valore
**Crypto-shredding**: cancellare la riga in `teacher_keys` rende illeggibili tutti i body del docente nel database in servizio; le copie di sicurezza fatte prima contengono ancora la chiave, fino alla loro scadenza (`docs/security/operations/restore-reerasure.md`). Dal 2026-09-24 l'art. 17 e l'anonimizzazione a 730 giorni passano dalla stessa routine, `App\Services\Gdpr\CancellazioneDellAccount`: chiave distrutta, contenuti in chiaro e file del docente cancellati, riga di `users` ridotta a segnaposto `anon-<id>`
**Accessi amministrativi**: tracciati in `crypto_custody_events` e mostrati al docente in `/me/custody-events`

### Sanitizzazione (ADR-015)

`HtmlSanitizer` (HTMLPurifier) a render time, `SvgSanitizer` su upload,
`TikzScriptValidator` sui sorgenti TikZ, `SsrfGuard` sugli host Ollama,
`SafePath` sui path. Dettaglio in [[security/xss-policy]].

### Security header e CSP

**Implementazione**: `app/Middleware/SecurityHeadersMiddleware.php`, applicato dal Kernel a ogni risposta
**Modi**: `relaxed` (default, `'unsafe-inline'` ammesso, niente `'unsafe-eval'`), `report-only`, `strict` (nonce + `strict-dynamic`); `CSP_MODE` o override in `waf_config` (il pannello `/admin/waf/config`), che vince. In produzione è `strict` dal pannello ([[technical-debt]] voce 19)
**Il nonce (dal 2026-09-23, A-16)**: è di `App\Support\Csp`, nasce in `Kernel::handle` prima della pipeline, e lo scrive chi scrive lo script, subito dopo `<script`: `<script<?= \App\Support\Csp::attributo() ?> …>` nelle viste, `'<script' . \App\Support\Csp::attributo() . '…'` o `$csp = Csp::attributo()` e `<script{$csp}>` nelle classi. `ViteManifest::script()` lo mette anche sui `modulepreload`. Il middleware lo mette solo nell'intestazione e non tocca il corpo: uno `<script>` arrivato dal contenuto non lo ha e con `strict` non parte. Fino al 23/9 il middleware lo timbrava con una regex su ogni `<script>` della risposta, contenuto compreso
**Isole di dati**: `<script type="application/json">` (e `application/ld+json`, `text/tikz`) senza nonce: il browser non le esegue ([[security/xss-policy]], «Dati del server dentro uno `<script>`»)
**Router SPA**: `js/fm-router.js` ricrea gli script della pagina caricata solo se portano il nonce della sua risposta (con `strict`); con `relaxed` e `report-only` li ricrea tutti, come prima
**Gestori in linea**: nessun `on*=` né `setAttribute("on…")` in `views/`, `app/` e `js/` (la CSP rigorosa non li esegue); i comportamenti di una riga passano da attributi `data-fm-*` (`js/modules/core/declarative.js`). Guardia `npm run csp:no-inline-handlers`, eccezioni riga per riga con il perché, provata da `tests/ops/gestori-in-linea.test.sh` (dal 2026-09-23, A-19; prima guardava solo `views/`)
**Prove**: `tests/Unit/Middleware/NonceSoloAgliScriptDellAppTest.php` (lo script del contenuto senza nonce, lo stesso nonce in pagina e nell'intestazione), `NonceDavantiAlTipoTest.php`, `OgniScriptDellAppHaIlNonceTest.php` (la guardia statica, nei due versi), `tests/js-unit/router-e-nonce.test.js`
**Come si verifica in produzione**: i rapporti delle violazioni arrivano a `CspReportController` se `CSP_REPORT_URI` è impostato (`security.csp_report_uri`); il passaggio sicuro è `report-only` per qualche giorno, rapporti letti, poi di nuovo `strict`

### Gettoni nei collegamenti per email (2026-09-23)

Quattro flussi confermano un'azione con un gettone: recupero password
(`password_resets.token_hash`), cambio email
(`email_change_requests.token_hash`) e consenso del genitore, art. 8
(`parent_consents.confirm_token`), che lo mandano per email in un
collegamento; e la cancellazione dell'account, art. 17
(`deletion_requests.confirm_token`), che oggi **non** manda email: il
gettone torna a chi ha chiesto la cancellazione, nella risposta.

**Regola**: nel database va solo `hash('sha256', $gettone)` in esadecimale, e
si cerca per hash; il gettone in chiaro sta solo nel collegamento (o nella
risposta). Nei registri il gettone non va mai: né in error_log né in
`mail_audit.log`, dove il registro della posta lo copre con
`[gettone omesso]`. `mail_audit.log` conserva invece il destinatario
(`TO=`), anche quando è l'indirizzo del genitore: è il registro degli invii;
nei messaggi d'errore l'indirizzo non va. Un gettone perso non si ricostruisce: serve una
richiesta nuova, che ne genera un altro. Per il consenso del genitore oggi
nessuna interfaccia la rifà: se l'email non parte, la richiesta resta ferma
fino alla scadenza (30 giorni).
**Perché**: fino al 23/9/2026 cancellazione e consenso tenevano il gettone
in chiaro, e `RegistrationService` lo scriveva in error_log con l'indirizzo
del genitore. Chi leggeva un dump, un backup o un registro poteva
confermare una cancellazione (che porta al crypto-shredding) o dare il
consenso al posto del genitore (A-67 della revisione del 23/9).
**Righe vecchie**: la migrazione 139 ha fatto scadere le richieste in attesa
e messo `ritirato-<id>` al posto di ogni gettone scritto prima.
**Prove**: `tests/Integration/Gdpr/GettoniComeHashTest.php` (il valore letto
dal database, usato come gettone, non conferma), `GettoniComeHashMigrazioneTest.php`,
`tests/Unit/ParentConsentMailerTest.php`.

### Collegamenti assoluti e caselle dell'istanza (2026-09-23)

La radice di ogni collegamento che esce dall'applicazione (email, QR, ritorno
di Drive) è `APP_URL`, e solo lei: niente ripiego sul dominio di produzione né
sull'intestazione `Host`, che è la via dell'avvelenamento dei collegamenti di
reset. La regola, e che cosa fa ogni flusso quando manca, sta in
`app/Support/IndirizzoPubblico.php`; le caselle in `app/Config/mail.php`. La
guardia `tests/Unit/DominioNonScrittoNelCodiceTest.php` fallisce se il dominio
torna in `app/` o `views/` (rilievo A-13 della revisione del 23/9).

### URL firmati e storage

`StorageController::signed` e `MapSignedUrlService`: HMAC-SHA256 con
`STORAGE_SIGNING_SECRET` e TTL breve; vuoto = URL non funzionanti (voce 15).

### Correlazione e telemetria

`RequestIdMiddleware::ensure()` (chiamato per primo dal Kernel) imposta
`X-Request-ID`; `Telemetry` e i registri lo riportano. `/metrics`
Prometheus con Bearer token o sessione super-admin.

## Superfici di attacco note

| Superficie | Rischio | Mitigazione |
|-----------|---------|------------|
| Sessione di un account disattivato, cancellato o con ruolo o flag di super-admin cambiati | resta valida fino a 60 s; sulle rotte senza `auth` che non chiedono il flag, fino alla prima richiesta che lo chiede | claims riletti al più ogni `Auth::CLAIMS_TTL_SECONDS` e sessione chiusa al primo scarto (vedi «Sessione e login», claims e **Lacune**) |
| CSRF non single-use | replay entro 7200 s | TTL, SameSite=Lax, `CsrfMiddleware` |
| Chiave master in `.env.local` | furto della chiave → tutto decifrabile | custodia con copie fuori sede e Shamir k-su-n ([[decisions/ADR-014-kms-strategy]] differito) |
| CSP `relaxed` (solo ripiego se `waf_config`/`CSP_MODE` non risolvono) | XSS residuo con inline, nel solo ripiego | in produzione è `strict` dal pannello WAF ([[technical-debt]] voce 19) |
| Rate limit `session` senza DB | non condiviso fra processi | backend `db` in produzione |

## Production deployment checklist

- [ ] `APP_DEBUG=false`, `SESSION_COOKIE_SECURE=true`
- [ ] `APP_URL` e le caselle dell'istanza (`CONTACT_EMAIL`, `DPO_EMAIL`, `ABUSE_EMAIL`, `SECURITY_EMAIL`), e il `Contact` di `public/.well-known/security.txt`
- [ ] `AUDIT_REASON_MODE=enforce`, `RATE_LIMIT_DISABLED` diverso da `1` (il `.env` tracciato dice `0`): dal 2026-09-23 il container di produzione non parte altrimenti, con le altre guardie in [[environment-variables]]
- [ ] `SECURITY_TOTP_ENABLED=true` e ruoli obbligati dal pannello
- [ ] `KMS_MASTER_KEY`, `STORAGE_SIGNING_SECRET`, `WAF_HMAC_SECRET`, `TEX_COMPILE_SECRET`, `RESEND_API_KEY` solo in `.env.local`
- [ ] `DB_MIGRATOR_*` e `DB_MAINT_*` configurati (tre utenti)
- [ ] `CSP_MODE=strict` solo dopo `report-only` pulito
- [ ] Timer systemd installati (`tools/systemd/`): audit chain, backup cifrato, cancellazioni e retention GDPR, threat-intel, pulizia temporanei
- [ ] Branch protection su `main` con i job di `ci.yml` richiesti (la pipeline ascolta `main` dal 2026-09-04)
- [ ] Documenti legali: `node tools/ci/check-legal-versions.mjs` verde e `php tools/legal/sync_versions.php --apply` eseguito dal deploy
