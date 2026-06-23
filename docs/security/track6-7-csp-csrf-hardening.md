# Track 6 + 7 — CSRF unification & CSP hardening (2026-06-03)

Esecuzione di Track 6 (centralizzazione + sicurezza API/CSRF) e Track 7
(Content-Security-Policy verso strict). Lavoro su working tree, **non
committato** (commit su richiesta). Test eseguiti su XAMPP `pantedu.local`.

## TL;DR — cosa è cambiato (default safe, già attivo)

- **CSRF unificato** su un'unica fonte (`dom-utils.fetchCsrf`, cache 60s +
  invalidate su `fm:navigated`); rimosso l'**XHR sincrono bloccante** da
  `api-jquery.js`.
- **CSP `'unsafe-eval'` RIMOSSO** dal default (`relaxed`) — nessun `eval`/
  `new Function` nel sorgente, verificato anche a runtime su editor/GeoGebra.
- **CSP a fonte unica**: rimosse le 3 definizioni divergenti (2 statiche in
  `.htaccess`, una stale con CDN jQuery già rimossi) → solo il middleware PHP.
- **Infrastruttura CSP strict pronta**: nonce per-request + `strict-dynamic`,
  modalità `report-only` per rollout sicuro. Superficie **pubblico + teacher
  + admin (page-load) = 0 violazioni** sotto strict.
- **CI guard** anti-regressione (`csp:no-inline-handlers`) in `npm run ci`.

## Track 6 — API/CSRF

| File | Modifica |
|------|----------|
| `js/modules/core/api.js` | `getCsrf`/`rotateCsrf` → delega a `dom-utils.fetchCsrf`/`invalidateCsrfCache` (cache unica). |
| `js/modules/core/api-jquery.js` | Rimosso `XMLHttpRequest` **sincrono**; refresh CSRF post-419 ora async (`_refreshCsrf` via dom-utils) nel ramo retry (già in contesto async). `_getCsrfSync` legge solo cache/`<meta>`. |

**Verifica:** E2E 16 test verdi (5.4m) — incl. `window.Api === ApiJQuery`
(alias legacy intenzionale, `sidebar.spec.js:88`), `FM.Api` getJson/postJson,
endpoint sidebar senza errori CSRF.

**Lasciato per scelta:** `api.js` e `api-jquery.js` restano moduli distinti
(verbi generici vs endpoint domain) — non sono doppioni. CSRF sparso in altri
~8 punti (view inline, tikz-render-client, checkin-handlers, ecc.) funziona
correttamente (fetch fresco) → dedup minore, follow-up.

## Track 7 — CSP

### Middleware (`app/Middleware/SecurityHeadersMiddleware.php`)
- Nonce per-request (`base64(random_bytes(16))`).
- `buildCsp($frame, $strict, $nonce)`:
  - `relaxed` (default): `script-src 'self' 'unsafe-inline' blob: <cdn>` — **no `unsafe-eval`**.
  - `strict`/`report-only`: `script-src 'self' 'nonce-…' 'strict-dynamic' blob: <cdn>` — no inline/eval.
- `stampScriptNonce()`: aggiunge `nonce="…"` a ogni `<script>` delle risposte
  HTML quando strict è attivo (relaxed non muta il body → zero overhead).
- `config/security.php`: `csp_mode` (env `CSP_MODE`, default `relaxed`) +
  `csp_report_uri` (env `CSP_REPORT_URI`).

### Fonte unica CSP
Rimosso `Header set Content-Security-Policy` da `public/.htaccess` e
`.htaccess` (root, era pure **stale**: citava `ajax.googleapis.com`/
`code.jquery.com`). Il middleware è ora l'unica fonte (necessario per il nonce).

### Bonifica inline (verso strict)
Il pattern CSS-async `onload=` sui `<link>` (bloccherebbe lo strict → CSS non
caricato) sostituito con script **nonce-ato co-locato** (`currentScript.
previousElementSibling`), timing-safe:
- `app/Support/CriticalCss.php` (main.bundle.css, tutte le pagine)
- `views/partials/head.php` (quill.snow.css)
- `views/partials/_exercise_assets.php` (quill.snow.css)

Handler interattivi inline (`on*=`) convertiti su tutta la superficie
**pubblico + teacher**:
- `views/exercises/search.php` — `onsubmit` → `preventDefault` nel listener
- `views/teacher/dashboard.php` — rimosso `onsubmit` ridondante (JS già fa preventDefault)
- `views/risdoc/edit.php` — `onclick` close → script nonce-ato co-locato

**Verifica (probe `tools/dev/_csp_probe.cjs`, CSP report-only, login teacher+admin):**
tutte le pagine testate → **0 violazioni**: home, teacher/dashboard, studio,
exercises, risdoc, profilo, admin/dashboard, admin/logs, admin/backup,
admin/institutes, admin/sidebar-config, admin/waf/{blocks,config,rules,anomalies}.

### CI guard
`tools/ci/no-inline-handlers.mjs` (in `npm run ci`):
- **zero tolleranza** per `on*=` fuori da `views/admin/` (superficie strict protetta);
- **ratchet** dentro `views/admin/` (baseline 69, può solo calare).

### Bonifica inline COMPLETATA su tutte le view `.php` + template editor
Tutte le ~44 inline handler nelle 8 pagine admin convertite a delegation
(`data-act`/`data-*` + un listener delegato per pagina) o script nonce-ato
co-locato:
- `backup`, `institutes_index`, `sidebar-config`, `subprocessors_index`,
  `gdpr_authority_export` (3 statici + 3 dinamici via delegation su `#fm-cs-results`),
  `logs_index`, `system/deployment`, `waf/{config,rules,anomalies}`.
- `waf/blocks.php` (17, il più complesso): handler statici + **dinamici in
  `innerHTML`** + il codice che **parsava l'attributo `onclick`** riscritto sui
  `data-*` (`button[data-act="delItem"][data-list="blacklist"]`).
- `views/admin/Elementi_Riservati.html` (23, template editor caricato via
  `innerHTML`): convertito a `data-fmr`/`data-arg` + nuovo modulo delegation
  `js/modules/editor/reserved-template-actions.js` (importato dal bootstrap;
  gli `<script>` co-locati non eseguono in innerHTML).

**Verifica interazione** (`tools/dev/_csp_interaction_smoke.cjs`, report-only):
click su bottoni admin non distruttivi (waf Refresh, logs) → **0 violazioni CSP,
0 errori JS**. Editor E2E (`g22_s15_editor`) verifica il template editor.

Restano inline handler SOLO in `views/admin/delete_temp.html` (1) — file MORTO
non routato (`/delete_temp.php` → CronController, non la `.html`).

### Toggle admin runtime (no redeploy)
La CSP mode è controllabile da **`/admin/waf/config`** (super-admin): select
`relaxed | report-only | strict`, persistito in `waf_config.csp_mode`.
`SecurityHeadersMiddleware::resolveCspMode()` legge `waf_config` (precede env
`CSP_MODE`, poi `relaxed`), con fallback fail-safe se il DB non risponde.
Endpoint: `POST /admin/waf/api/config` (`csp_mode` validato relaxed/report-only/strict),
audit via `waf_config.updated_by`. Effetto immediato, nessun deploy.

> **Bug pre-esistente corretto strada facendo** (`WafAdminController::body()`):
> `Request::parseHeaders()` legge solo gli header `HTTP_*`, ma PHP espone
> `Content-Type` in `$_SERVER['CONTENT_TYPE']` (senza prefisso) →
> `$req->headers['content-type']` era vuoto e il guard
> `str_contains($ct,'application/json')` falliva sempre, quindi ogni POST JSON
> a questo endpoint dava `no_fields` (salvataggi WAF config rotti). Fix LOCALE
> (non tocca il core `Request`): se `$_POST` è vuoto, `body()` prova il parse
> JSON di `php://input` direttamente.

### Runbook per il flip a strict
1. Da `/admin/waf/config` (o `CSP_MODE=report-only` in `.env`) → `report-only`
   con `CSP_REPORT_URI` → conferma su traffico reale (click admin + editor) per
   qualche giorno.
2. A report puliti → `strict` (stesso toggle). Lo `style-src` resta
   `'unsafe-inline'` (attributi `style=` non copribili da nonce; tightening futuro).
3. Rollback istantaneo: rimetti `relaxed` dal toggle.

## Note
- Probe riusabili: `tools/dev/_csp_probe.cjs` (violazioni passive per pagina) +
  `tools/dev/_csp_interaction_smoke.cjs` (click su bottoni admin non distruttivi).
  Richiedono `CSP_MODE=report-only` attivo + login.
- `superadmin` ha ruolo admin → utile per E2E delle pagine admin.
- **Test pre-esistente rotto (NON regressione di questo lavoro):**
  `tests/e2e/g22_s15_editor_smoke.spec.js` fallisce perché naviga su `/`
  (home, `fm-no-edit`) e aspetta `window.FM.LatexRender`, che lì non carica
  (l'editor è lazy, solo in exercise-context); inoltre conta i 401 anonimi su
  `/api/sidebar/config` + `/api/teacher/category-labels` (endpoint auth-gated)
  come errori. Verificato con `git stash`: fallisce IDENTICO sul codice
  originale. Andrebbe puntato a una pagina editor reale o filtrare i 401.

---

## Addendum 2026-06-05 — follow-up CSRF completato + WAF JSON-per-XHR

Il "dedup minore, follow-up" citato in Track 6 è stato **completato e committato**
(commit `c33a07a`, `e4dc9fd`, `29aa5ea`, `0a6c326`):

- **CSRF 100% centralizzato.** Tutti i punti residui (view inline,
  `tikz-render-client`, `checkin-handlers`, adapter pt-document, sidepage,
  verifica-*, admin-*, ecc.) usano ora `dom-utils.fetchCsrf` (o l'alias
  `window.FM.DomUtils.fetchCsrf` negli script classici). **L'unico** `fetch('/auth/csrf')`
  rimasto nel codebase è quello canonico in `dom-utils.js`.
- **WAF JSON-per-XHR** (commit `c33a07a`, ADR/WAF). Il `WafMiddleware` serviva la
  pagina di challenge **HTML** (fingerprint/PoW) anche alle `fetch`/XHR → il
  client crashava con `Uncaught SyntaxError: Unexpected token '<' … is not valid
  JSON`. Ora, per richieste che attendono JSON (`Accept: application/json` **o**
  path `/api/*`), challenge e block ritornano **JSON 403** (`{code:'waf_challenge',
  reload:true}` / `{error:'request_blocked'}`) **senza cambiare la decisione di
  sicurezza** (sempre 403, fail-closed). Il choke-point client `dom-utils.assertJson`
  riconosce `waf_challenge` (forma JSON e HTML difensiva) e fa **auto-reload one-shot**
  (debounce 30s) per rinnovare il cookie `waf_session` via navigazione full-page.
  Test: `tests/Unit/WafMiddlewareJsonResponseTest.php`. Vedi `wiki/waf.md` §
  "Risposte JSON per richieste XHR/API".
- **De-shim jQuery completo.** Eliminato `js/modules/core/ajax-compat.js`
  (`$.ajax`-like `.done/.fail/.always`): 8 moduli migrati a `fetch`/`fetchJson`
  vanilla con `then/catch/finally`. Zero jQuery nel runtime (libreria non
  caricata; `$` = alias `querySelector`).
- **CSS-in-JS azzerato** (ADR-023 Fase 5): l'ultima iniezione runtime
  (`bootstrap-compat.injectSyncStyles`) spostata in `css/modules/_sync-status.css`.
  CI `no-css-in-js` verde.
- **Audit `/admin`**: routing dietro `auth + role:admin`; mutazioni in gruppi
  `csrf+rate`; operazioni sensibili `super_admin_required` (+ `audit_reason`);
  gruppi senza CSRF contengono solo GET. SQLi coperto (prepared + whitelist
  `/admin/logs/api/{table}`), XSS coperto (`esc`/`escAttr`; nessun `<?= $var ?>`
  grezzo). `/api/admin/*` in `shouldBypass` del WAF.

**Gate finali verdi:** `no-css-in-js`, `csp:no-inline-handlers`, `eslint --quiet`
(0 errori), `vite build`.
