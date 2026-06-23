---
tags:
  - documentazione/security
date: 2026-04-23
tipo: security
status: finale
aliases: ["security", "sicurezza", "auth"]
cssclasses: []
---

# Security Notes

## Stato remediation post-audit pentest-2026-04-29

L'audit del 2026-04-29 ha prodotto 24 finding aperti, distribuiti su 5 sprint di intervento. Lo stato corrente di remediation è **pending** — il piano operativo è documentato in [docs/security/pentest/2026-04-29/remediation-tracker.md](../docs/security/pentest/2026-04-29/remediation-tracker.md).

### Finding HIGH aperti (P0, entro 24h)

- **SECRET-001** (CVSS 7.5) — credenziali FTP Aruba hardcoded in `scriptGoogle_sync/upload-webhook.php:32-35`. Rotation password Aruba + spostamento in env var richiesti immediatamente.
- **SECRET-003** (CVSS 7.0) — DB backups, debug log e file PII utenti tracciati in git per policy `.gitignore` Phase 20 backup-friendly. Aggiornamento `.gitignore` + `git rm --cached` + history rewrite con `git filter-repo` richiesti.

### Finding MEDIUM aperti (P1, entro 30g)

- **SECRET-002** (CVSS 6.0) — `pdf-scraping-tools/.env` tracciato con chiavi API reali Anthropic + OpenAI. Revoke + rotation immediata raccomandati.
- **SECRET-004** (CVSS 5.3) — Linear client secret in commit storico `d6e6bca` (history-only). Revoke immediato + history rewrite.
- **CONFIG-002** (CVSS 5.5) — `polyfill.io` referenziato in CSP whitelist e in 15 file HTML legacy. Rimozione raccomandata (CDN compromesso 2024, attualmente safe-redirect Cloudflare).
- **XSS-001** (CVSS 4.3, plausible) — 33 occorrenze `innerHTML` con dati API in 9 file JS admin/risdoc. Refactor con DOMPurify o helper `escHtml()`.
- **RCE-001** (CVSS 5.5, plausible) — `bin/worker.php:77` istanzia classi via `new $handler()` con FQN da DB. Allowlist namespace `App\\Jobs\\` raccomandato.

### Punti di forza confermati dall'audit

L'audit conferma che il **runtime applicativo principale è solido**:

- Crypto envelope (`TeacherCryptoService` + `ClasseKeyService`) AES-256-GCM + HKDF-SHA256 con crypto-shredding O(1)
- Permission risdoc fail-safe DENY by default con 4 layer di controllo
- bcrypt cost 12 + RateLimiter 5/300s + session regen + SameSite/HttpOnly cookie
- PDO prepared statements ovunque (3 hit `query()` con var sono code-controlled, no SQLi runtime)
- `.htaccess` rewrite root protegge tutti i path sensibili (12 path testati → tutti 404)
- Zero vulnerabilità note nelle dipendenze runtime (`composer audit` + `npm audit` clean)

### Documenti firmati

- [docs/security/pentest/2026-04-29/report-final-signed.pdf](../docs/security/pentest/2026-04-29/report-final-signed.pdf) — 100 pagine, PAdES BES + marca temporale TSA AgID Aruba
- Hash SHA-256: `sha256:364409f1b8b8a100c3d6b323bd5ef90f2923238ee46b1f6e2238da48de7bd855`
- Tag git riproducibilità: `audit-2026-04-29-baseline` su `af3e011`

### Prossimo audit

Cadenza annuale (2027-04) o anticipato a major release (Phase 26 modernization closure).

## DPA con sub-processor Aruba (Art. 28 GDPR)

L'obbligo di nomina del Responsabile del trattamento ai sensi dell'Art. 28 GDPR per il sub-processor Aruba (hosting + FTP + email) è soddisfatto dalle **Condizioni di fornitura servizi hosting Aruba versione 4.4** (in vigore dal 24 Marzo 2026), Articolo 23 — "Nomina a Responsabile del Trattamento".

Documento di riferimento: <https://hosting.aruba.it/documents/tc-files/it/1_condizionifornituraservizihostingaruba.pdf>

**Attenzione**: NON è l'Articolo 22 (che dichiara Aruba "Titolare autonomo" per i dati di fatturazione/anagrafica del cliente). Il DPA vero è l'**Articolo 23**, che nomina Aruba come Responsabile per i dati che il Cliente immette/trasmette tramite il servizio.

L'Articolo 23 copre tutti i 10 obblighi richiesti dall'Art. 28 §3 GDPR:

- trattamento solo su istruzione del titolare (Manuali + Specifiche Tecniche del servizio = istruzioni accettate)
- riservatezza del personale autorizzato
- misure di sicurezza Art. 32 con riferimento esplicito a ISO 27001
- autorizzazione generale all'uso di sub-responsabili + lista aggiornata
- assistenza per esercizio diritti interessato (Art. 12-23)
- cooperazione su data breach (Art. 33-34) e DPIA (Art. 35)
- cancellazione o restituzione dati a fine servizio (a scelta del Cliente)
- documentazione audit (preavviso 20 giorni, max 1/anno, costi a carico Cliente)
- riferimento esplicito Art. 28 Regolamento UE 2016/679

**Auto-attivazione**: la nomina si attiva automaticamente con la sottoscrizione del servizio Aruba e l'accettazione delle Condizioni di fornitura. Non è richiesta firma di un documento separato. Per accountability tenere agli atti la data di sottoscrizione del servizio + l'archivio della versione T&C in vigore al momento del trattamento.

**Archivio accountability**: i documenti di prova (T&C v4.4, Informativa Privacy v2.9, conferme ordine + pagamento, storico fatture) sono conservati in `docs/privacy/aruba-accountability/` con hash SHA-256 verificabili. Solo `README.md` e `SHA256SUMS.txt` sono in git (i PDF/XLSX contengono PII billing — backup off-line cifrato). Vedi [`docs/privacy/aruba-accountability/aruba-archive-index.md`](../docs/privacy/aruba-accountability/aruba-archive-index.md).

Verifica: 2026-04-29 (post audit pentest-2026-04-29). Status: **risolto** (sostituisce P0.1 della baseline pentest-2026-04 "DPA Aruba firmato").

## Auth overview

```
utente → POST /login → Auth::attempt() → session PHP → cookie SID (httponly, samesite=Lax)
                         ↓
                    Rate limit (5 tentativi, lockout 300s)
                    BlockList check (credential + IP×section)
                    password_verify() bcrypt
                    session_regenerate_id(true) su successo
```

## Meccanismi

### Auth — Sessione PHP

**Implementazione**: `app/Core/Auth.php`, `app/Core/Session.php`
**Copertura**: tutte le route con middleware `auth`
**Chiavi sessione**: `autenticato`, `username`, `user_role`, `is_super_admin`, `login_time`, `authenticated_section`
**Lacune**: nessun IP binding, nessun device fingerprint, `is_super_admin` cachato in sessione (refresh via `Auth::refreshCurrentUserClaims()`)

### CSRF — Token TTL-based

**Implementazione**: `app/Core/Csrf.php`
**Copertura**: tutte le route POST/PUT/DELETE con middleware `csrf`

```php
// Generazione (app/Core/Csrf.php)
$_SESSION['_csrf']    = bin2hex(random_bytes(32));
$_SESSION['_csrf_at'] = time();
// Verifica
hash_equals($stored, $token) && (time() - $issuedAt) <= $ttl
```

**TTL**: `CSRF_TOKEN_LIFETIME` (default 7200s). Token non viene ruotato automaticamente ad ogni request — rimane valido per TTL. `Csrf::rotate()` forza reset.
**Trasmissione**: campo `_csrf` nel form POST o header `X-CSRF-Token` (verificare in `CsrfMiddleware`).
**Recupero token lato client (centralizzato, 2026-06-05)**: fonte unica `dom-utils.fetchCsrf` (cache 60s, invalidate su `fm:navigated`). Tutti gli ex helper locali (`getCsrf`/`csrf`/`bsCsrf`/`_getCsrf`/`csrfToken` in view inline, `tikz-*`, `checkin-handlers`, adapter pt-document, sidepage, verifica-*, admin-*) sono stati rimossi o ridotti ad alias → **l'unico** `fetch('/auth/csrf')` nel codebase è quello canonico in `dom-utils.js`. `CsrfMiddleware` su token invalido risponde JSON 403 `{error:'csrf_invalid'}` se `wantsJson()`, altrimenti pagina HTML 403.
**Lacune**: nessuna rotazione automatica post-uso (single-use non implementato).

### Rate Limiting

**Implementazione**: `app/Services/RateLimiter.php`, `app/Services/RateLimitStore.php`, `app/Middleware/RateLimitMiddleware.php`
**Copertura**: middleware `rate:<bucket>,<N>` su endpoint write + `student-login` + `/waf/fingerprint`
**Algoritmo**: sliding window, store DB/file-based
**IP key**: risolto via `EdgeContext` (IP client reale, non quello del CDN) → dietro Cloudflare il bucket login NON è globale ma per-utente
**Ban persistente (Phase audit 2026-06)**: `WafBruteforceGuard` promuove i fallimenti di login da rate-limit effimero a **ban duraturo**: lockout temporaneo per-username (`waf_blocked_credentials`, consultato da `Auth::attempt`, expiry-aware) + ban IP su credential-stuffing (molti username distinti → `waf_blocked_ips`). **NAT-safe**: tanti tentativi sullo stesso account NON bannano l'IP (conta gli username distinti) → non penalizza i NAT scolastici.
**Config**: `LOGIN_MAX_ATTEMPTS=5`, `LOGIN_LOCKOUT_SECONDS=300`; soglie ban in `waf_config` (`bf_*`)
**Lacune**: store file-based non atomic su race conditions (accettabile per carico basso)

### WAF applicativo + Difesa di bordo (Phase 25.C → hardening audit 2026-06)

**Implementazione**: `app/Middleware/WafMiddleware.php`, `app/Services/Waf/*`
**Copertura**: globale (Kernel), early-exit se `waf_config.enabled=0`. Toggle operativi in tabella `waf_config` (controllabili da `/admin/waf` senza redeploy).

Difesa **a strati** (defence-in-depth):

1. **Bordo (deployment raccomandato)** — CDN/proxy (Cloudflare) assorbe volumetrico/DDoS e fornisce `Cf-IPCountry`/`CF-Connecting-IP`.
2. **Origin firewall** — 80/443 raggiungibili **solo dai range del CDN** (UFW/cloud firewall). Senza, un attaccante colpisce l'origin diretto e falsifica gli header CF. Runbook: [docs/ops/waf-hardening-2026-06.md](../docs/ops/waf-hardening-2026-06.md).
3. **nginx** — `real_ip` (riscrive l'IP reale dal CDN) + `limit_req` (login/anti-flood) + CSP/HSTS + ModSecurity/CRS opzionale.
4. **WAF applicativo (PHP)** — vedi sotto.

**Catena decisionale del WafMiddleware** (early-exit alla prima azione):
`enabled/mode` → bypass path/asset → honeypot (auto-blacklist 30g) → whitelist → blacklist manuale → threat-intel (Spamhaus/Tor/ASN, exact+CIDR) → CrowdSec LAPI bouncer → **geo-filtering** (es. solo `IT`, enforce) → rule engine custom → **session HMAC + challenge**.

Meccanismi di hardening (audit 2026-06-01):

- **`EdgeContext`** (`app/Services/Waf/EdgeContext.php`) — risolve IP client reale + edge fidato + country **a prova di spoofing** `X-Forwarded-For`/`Cf-IPCountry`. Gli header di forwarding sono fidati SOLO da range Cloudflare o col marker server `WAF_EDGE_TRUSTED` (fastcgi_param, non un header client). → la geo-restrizione "solo IT" non è aggirabile inviando `Cf-IPCountry: IT`.
- **Proof-of-Work** (`WafProofOfWork`) — la challenge è lavoro computazionale (hashcash HMAC-firmato stateless), non un JS-eval banale. Difficoltà `pow_bits` configurabile (~1s, 3G-safe). Il client risolve via Web Crypto (`js/waf/fingerprint.js`); parità sha256 PHP↔JS. Roll-out: `pow_required=0` (PoW assente = lenient).
- **Scoring** (`WafScoringService`) — `calculateScore` sul fingerprint browser + `serverSignals` (UA reale vs dichiarato, UA di automazione/CLI, `Accept-Language`/`Accept` mancanti) non auto-dichiarabili. curl → ~100/block; browser reale → ~0/pass.
- **Cookie sessione WAF** (`WafSessionService`) — HMAC-firmato, legato a **IP + UA** (+nonce) → anti session-replay cross-client.
- **Fail-CLOSED** — errore interno del middleware → challenge invisibile (il Kernel altrimenti farebbe fail-OPEN).
- **JSON-per-XHR (2026-06-05)** — per richieste che attendono JSON (`Accept: application/json` o path `/api/*`) challenge e block ritornano **JSON 403** (`{code:'waf_challenge',reload:true}` / `{error:'request_blocked'}`) invece della pagina HTML: stessa decisione di sicurezza (sempre 403, fail-closed), formato adatto al client. Evita il crash `Unexpected token '<'` sulle `fetch`; il client (`dom-utils.assertJson`) auto-ricarica per rinnovare il cookie. Dettaglio: [wiki/waf.md](waf.md#risposte-json-per-richieste-xhrapi-2026-06-05). Test: `tests/Unit/WafMiddlewareJsonResponseTest.php`.
- **Anti-ReDoS** (`WafRulesService::safeRegexMatch` + `isRegexConditionSafe`) — subject capped + backtrack limit + validazione dei pattern al salvataggio.
- **Secret** — `config/waf.php` ricava `WAF_HMAC_SECRET` da env o da key-file persistente auto-generato (mai vuoto → il layer session/scoring non si disattiva silenziosamente).

**Lacune / note operative**:
- Il marker `WAF_EDGE_TRUSTED` è load-bearing con `real_ip`: è sicuro SOLO perché l'origin firewall locka l'accesso al CDN. Non disabilitare il firewall senza togliere il marker.
- CrowdSec bouncer oggi è una query LAPI per-IP in PHP (fail-open): candidato a spostamento sul firewall-bouncer nginx (vedi runbook).

### Role-based Access Control

**Implementazione**: `app/Config/roles.php`, `app/Core/Auth.php::hasAccess()`
**Gerarchia**: guest(0) < student(10) < teacher(40) < collaborator(50) < administrator(100)

| Zona | Roles ammessi |
|------|--------------|
| `public` | tutti |
| `student` | student, teacher, collaborator, administrator |
| `teacher` | teacher, collaborator, administrator |
| `collaborator` | collaborator, administrator |
| `admin` | administrator (+ super_admin bypass) |

**Super-admin**: flag `is_super_admin` ortogonale al role. Accede a zona `admin` anche con role `teacher`. Logging separato via `SuperAdminAuditMiddleware`.

### BlockList

**Implementazione**: `app/Services/BlockList.php`
**Copertura**: `Auth::attempt()` — solo utenti non-admin
**File**: `log/data/blocked_credentials.json`, `log/data/blocked_ips.json`
**Gestione**: `SecurityAdminController` via `/api/admin/security/credentials/block`

### Path Traversal Prevention

**Implementazione**: `app/Support/SafePath.php`
**Copertura**: `FileController`, `FileService`, `TikzController`
**Pattern**: normalizza path, verifica che il path risolto stia dentro la directory permessa.

### Student Credential Auth (access grant)

**Implementazione**: `app/Controllers/TeacherCredentialController.php`
**Flusso**: docente crea credenziali per studente → studente POST `/api/access/student-login` → grant in sessione `student_grant` → accesso limitato ai contenuti del docente.
**Rate limit**: 5 tentativi / 300s su `student-login`.

### Storage Signed URL

**Implementazione**: `app/Controllers/StorageController.php`
**Meccanismo**: HMAC-SHA256 (`STORAGE_SIGNING_SECRET`) + TTL breve. La signature è l'autorizzazione → route pubblica.
**Lacune**: se `STORAGE_SIGNING_SECRET` è vuoto, URL signed non funziona (fallisce in controller).

### Access Logging + Anomaly Detection

**Implementazione**: `app/Core/AccessLogger.php`, `app/Core/PrivilegedAccessLogger.php`, `app/Services/AnomalyDetectionService.php`
**Storage**: `storage/logs/access_log.json`, `storage/logs/access_stats.json`
**Rotazione**: `LogRotator::maybeRotateAll()` throttled 1h, chiamato dal Kernel ad ogni request.

### Audit Reason Middleware (Phase 25.B4)

**Implementazione**: `app/Middleware/RequiresAuditReasonMiddleware.php`
**Copertura**: gruppo admin POST/DELETE (mutazioni cross-teacher)
**Header obbligatorio**: `X-Audit-Reason: <free-text 8-255 char>`
**Modi**: `disabled` (skip), `warn` (log + procedi), `enforce` (403 se assente)
**Config**: env `AUDIT_REASON_MODE=warn|enforce|disabled` (default `warn`)
**Decisione**: vedi [ADR-008-audit-reason](decisions/ADR-008-audit-reason.md).

### Security Headers Middleware (Phase 25.B6)

**Implementazione**: `app/Middleware/SecurityHeadersMiddleware.php`
**Copertura**: globale (Kernel::applySecurityHeaders)
**Headers emessi**: CSP, HSTS, X-Frame-Options, X-Content-Type-Options,
Referrer-Policy, Permissions-Policy, COOP.
**CSP modes** (Track 6/7, 2026-06-03): `relaxed` (default — inline ammesso ma
**niente più `'unsafe-eval'`**), `report-only` (emette la policy strict come
Report-Only, raccoglie violazioni), `strict` (nonce per-request +
`'strict-dynamic'`, blocca script iniettati). Default da `app/Config/security.php`
(env `CSP_MODE`), **override runtime da `waf_config.csp_mode`** via UI
`/admin/waf/config` (no redeploy). Nonce stampato a runtime su ogni `<script>`.
**Single-source**: le CSP statiche in `.htaccess`/`public/.htaccess` sono state
RIMOSSE — il middleware è l'unica fonte (necessario per il nonce per-request).
**Bonifica inline**: tutte le view `.php` (admin+teacher+pubblico) + il template
editor `Elementi_Riservati.html` sono prive di handler `on*=` inline (delegation/
data-*); CI guard `tools/ci/no-inline-handlers.mjs`. Rollout: `report-only` →
`strict`. Dettagli: [docs/security/track6-7-csp-csrf-hardening.md](../docs/security/track6-7-csp-csrf-hardening.md).

### Envelope Encryption (Phase 25.D)

**Implementazione**: `app/Services/Crypto/TeacherCryptoService.php`,
`app/Services/Crypto/ClasseKeyService.php`
**Algoritmo**: AES-256-GCM + HKDF-SHA256, per-teacher KEK derivata da KMS_master.
**Crypto-shredding O(1)**: `shred(userId)` cancella la riga in `teacher_keys`,
rendendo illeggibili tutti i body cifrati del docente (Art. 17 GDPR self-service).
**Class keys decoupled**: pubblicazioni studenti restano leggibili dopo shred docente.
**Rotation**: `tools/crypto/rotate_kek.php` annual + `--prune-old-kv`.
**Recovery runbook**: [docs/security/kms-recovery.md](../../docs/security/operations/kms-recovery.md).
**Decisione**: vedi [ADR-006-envelope-encryption](decisions/ADR-006-envelope-encryption.md).

### Request ID Correlation (Phase 25.E4)

**Implementazione**: `app/Middleware/RequestIdMiddleware.php` + `app/Core/Logger/JsonLogger.php`
**Header in/out**: `X-Request-ID` (UUID v4 auto-gen, echo del client se valido).
**Uso**: trace correlation cross-log + Telemetry::span trace_id.
**Endpoint metrics**: `/metrics` Prometheus exposition (auth Bearer token o super_admin session). Dettagli in [observability.md](observability.md).

## Superfici di attacco note

| Superficie | Rischio | Mitigazione |
|-----------|---------|------------|
| `Auth::isSuperAdmin()` caching sessione | Privilege escalation se sessione compromessa | `refreshCurrentUserClaims()` dopo privilege change |
| File-based rate limit store | Race condition | Accettato (low traffic); atomicità non critica |
| CSRF token non single-use | Replay attack entro TTL | TTL breve (7200s); cambio accettato |
| `DB_DUAL_WRITE` desync | Dati inconsistenti DB vs JSON | Disabilitare dopo consolidamento DB |
| pdflatex server-side | RCE se input non sanitizzato | `TexBuilder::esc()` escapa tutti i valori; wrapper fisso |
| Storage `risdoc-tmp/` | ZIP accessibili per TTL 1h | `cleanupOld()` 1h; filename random 16hex |
| `LegacyController::serve()` | Serve file system direttamente | Path limitati a whitelist esplicita in routes |
| KMS_master in env | Furto chiave → decrypt tutti body | Phase 25.E10 Hashicorp Vault (post-pentest). Per ora: backup off-line (server env + Yubikey GPG + BIP-39). |
| Audit reason in modalità `warn` | Mutazioni cross-teacher senza giustificazione | Switch `AUDIT_REASON_MODE=enforce` in produzione (oggi `warn` durante rollout). |
| CSP `relaxed` di default (inline `<script>` ammessi via `'unsafe-inline'`) | XSS surface | Cleanup handler inline COMPLETO (Track 7); `'unsafe-eval'` già rimosso. Flip a `strict` (nonce + strict-dynamic) da `/admin/waf/config` o `CSP_MODE=strict` dopo conferma `report-only` su VPS. |
| Migration runner concorrente | Race su rolling deploy multi-server | Phase 25.E3 advisory lock `GET_LOCK('pantedu.migrator')` ✅ |

## Production deployment checklist

Pre-flight da verificare prima del rilascio prod (vedi [.env.example](../.env.example)
per i valori raccomandati):

- [ ] `APP_DEBUG=false`
- [ ] `SESSION_COOKIE_SECURE=true` (HTTPS only)
- [ ] `AUDIT_REASON_MODE=enforce` (post grace 30g warn)
- [ ] `RATE_LIMIT_DISABLED=` unset (default 0)
- [ ] `CSP_MODE=strict` (o toggle `/admin/waf/config`) dopo conferma `report-only` pulito su VPS — cleanup inline handler già completato (Track 7)
- [ ] `KMS_MASTER_KEY` in `.env.local` (NON in `.env` committato)
  + backup off-line (server env + Yubikey GPG + BIP-39 paper) — vedi
  [docs/security/kms-recovery.md](../../docs/security/operations/kms-recovery.md)
- [ ] `CRYPTO_DUAL_WRITE=1` durante backfill, poi `0`
- [ ] `CRYPTO_READ_FROM=ciphertext` post-backfill verificato
- [ ] `METRICS_BEARER_TOKEN` random 32+ hex (per scraper Prometheus)
- [ ] `APP_MAIL_FROM` configurata (parent_consent + breach notification)
- [ ] Branch protection rule su `master` (GitHub Settings → Branches),
  require status checks: lint-js, static-php, security, build, test-php,
  secret-scan + require PR review.
- [ ] Pre-commit hooks installati su tutte le workstation dev:
  `bash tools/git/hooks/install.sh`
- [ ] Cron jobs schedulati:
  - `tools/gdpr/execute_pending_deletions.php` daily (Art. 17 cooling-off)
  - `tools/gdpr/cleanup_expired_consents.php` daily (Art. 8 minori)
  - `tools/gdpr/breach_drill.php` semestrale
  - `tools/crypto/audit_report.php --json` daily → SOC webhook
- [ ] Pentest esterno completato (BLOCKER PROD — Phase 25.E7)
- [x] DPA Aruba ✅ — Art. 23 Condizioni di Fornitura v4.4 (24/03/2026)
  costituisce DPA standard ex Art. 28 GDPR. Archive in
  [docs/privacy/contracts/](../docs/privacy/contracts/). 10/10 requisiti
  Art. 28 §3 coperti. Auto-attivo all'accettazione Modulo d'ordine.
