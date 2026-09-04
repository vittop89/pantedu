# WAF — Web Application Firewall self-hosted

Implementazione del prompt [`docs/todo/waf_security_prompt.md`](../docs/todo/waf_security_prompt.md) adattato a stack pantedu (PHP middleware + admin UI + DB control plane). Phase 25.C.

> **Architettura a 2 layer** (Layer 2 implementato, Layer 1 deferred):
>
> - **Layer 1 (Nginx + GeoIP + Lua)** — DEFERRED. Pre-filter veloce davanti all'app. Richiede accesso VPS root + compile `ngx_http_geoip2_module` + OpenResty. Vedi sezione "Deploy Nginx/Lua" sotto.
> - **Layer 2 (PHP middleware)** — IMPLEMENTATO. Runtime check + scoring + log + admin UI. Funziona stand-alone senza Layer 1, è il livello con il maggior valore (admin-controlled + business logic awareness).

## Quick start

```bash
# 1. DB migration
mysql pantedu_dev < database/migrations/048_waf_tables.sql

# 2. Genera HMAC secret + aggiungi a .env.local
echo "WAF_HMAC_SECRET=$(openssl rand -hex 32)" >> .env.local

# 3. Scarica DB GeoIP locale (formato .mmdb).
#    OPZIONE A — db-ip.com Lite (NO signup, free monthly, raccomandato):
mkdir -p storage/geoip
MONTH=$(date +%Y-%m)
curl -sL "https://download.db-ip.com/free/dbip-country-lite-${MONTH}.mmdb.gz" \
    | gunzip > storage/geoip/dbip-country-lite.mmdb
echo "WAF_GEOIP_DB=$(pwd)/storage/geoip/dbip-country-lite.mmdb" >> .env.local

#    OPZIONE B — MaxMind GeoLite2 (signup richiesto su maxmind.com,
#    leggermente più accurato di db-ip ma più burocratico)

# 4. composer require geoip2/geoip2 (SDK PHP per leggere .mmdb)
composer require geoip2/geoip2

# 5. Setup cron mensile per refresh DB (db-ip rilascia mensilmente):
echo '5 4 1 * * cd /var/www/pantedu && \
  curl -sL "https://download.db-ip.com/free/dbip-country-lite-$(date +%Y-%m).mmdb.gz" \
  | gunzip > storage/geoip/dbip-country-lite.mmdb' | sudo tee /etc/cron.d/waf-geoip

# 6. Apri /admin/waf nel browser (super_admin only) → enable + mode=monitor
#    Osserva 1-2 giorni → calibra soglie → passa a mode=enforce
```

> **MaxMind alternative (db-ip.com Lite)**: il formato `.mmdb` di db-ip è
> compatibile con `GeoIp2\Database\Reader` (stessa libreria MaxMind). Free,
> aggiornato mensilmente, no signup. Coverage IPv4+IPv6, accuracy ~95% per
> country-level (sufficiente per WAF). [download](https://db-ip.com/db/download/ip-to-country-lite)

## Pannello admin

Tutto controllabile da `/admin/waf/*` (super_admin gate).

| Tab | Funzione |
|-----|----------|
| `/admin/waf/dashboard` | Counter real-time + ultime 50 request logged (auto-refresh 10s) |
| `/admin/waf/config` | Master switch enabled/off + mode + soglie score + GeoIP + retention |
| `/admin/waf/rules` | Custom rules builder (Cloudflare-style operatori IP/Country/UA/URL/...) |
| `/admin/waf/blocks` | **Tab unificato (Phase 25.R.19)**: Whitelist + Blacklist pre-route + IP auth-flow per-section + Credenziali bloccate brute-force. Sticky TOC con 4 sezioni. Sostituisce ex `/lists` + `/credentials` (redirect 301 back-compat) |
| `/admin/waf/anomalies` | Soglie excessive_access + credential_sharing + lista alert real-time |
| `/admin/waf/reports` | KPI cross-layer (WAF+auth+anomalies) + Top countries + Top 20 IP 7gg + score distribution + RPM per outcome |
| `/admin/waf/threat-intel` | 5 source import bulk (ASN cloud + Spamhaus DROP + X4B VPN + CrowdSec + Tor) + cron config |

### Modi operativi

| Mode | Comportamento |
|------|---------------|
| `off` | Bypass totale (anche con `enabled=1` override) |
| `monitor` | **Solo log**, nessuna azione di blocco (test fase iniziale) |
| `soft` | Blocco solo per score alto; soft challenge inviata |
| `enforce` | Tutte le azioni applicate — **default produzione** |
| `under_attack` | Ogni request senza cookie valido → interstitial obbligatorio |

### Soglie score di default

| Score | Challenge | Azione |
|-------|-----------|--------|
| 0..40 | `pass` | accesso diretto |
| 41..70 | `soft` | challenge invisibile o interstitial |
| 71..100 | `block` | 403 o checkbox umano |

Personalizzabili in `/admin/waf/config`.

### Risposte JSON per richieste XHR/API (2026-06-05)

La pagina di challenge (fingerprint/PoW/interstitial) e il block sono **HTML**:
funzionano solo per **navigazioni full-page**. Una `fetch`/XHR non esegue lo
`<script>` di challenge → il client faceva `.json()` su `<!doctype …>` e
crashava (`Uncaught SyntaxError: Unexpected token '<' … is not valid JSON`).

Fix (`WafMiddleware`): un flag centralizzato `$expectsJson`, settato **una
volta** in `handle()` =
`$req->wantsJson() || str_starts_with($req->path, '/api/')`. Quando attivo:

| Decisione | Risposta navigazione | Risposta XHR/API |
|-----------|----------------------|------------------|
| challenge | HTML 200 (fingerprint/PoW) | **JSON 403** `{ok:false, error:"security_check_required", code:"waf_challenge", reload:true}` |
| block     | HTML 403 | **JSON 403** `{ok:false, error:"request_blocked", reason:…}` |

> **La decisione di sicurezza NON cambia** (challenge resta challenge, block
> resta block, sempre 403, fail-closed): cambia solo il **formato** per i
> client che attendono JSON. I bot che colpiscono `/api/*` ricevono comunque
> 403; non possono nemmeno "risolvere" la challenge.

**Recupero lato client** (`js/modules/core/dom-utils.js` → `assertJson`):
riconosce `waf_challenge` (forma JSON **e** HTML difensiva via regex
`data-waf-(mode|pow)`) e fa **auto-reload one-shot** (debounce 30s,
`sessionStorage fm_waf_reload_at`): la navigazione full-page risolve la
challenge invisibile e rinnova il cookie `waf_session`, poi l'azione riesce.
Convenzione: ogni endpoint `/api/*` JSON va consumato con `fetchJson`/`fetchCsrf`
(non `fetch`+`r.json()` grezzo). `shouldBypass` esenta già `/api/admin/`,
`/auth/csrf`, `/auth/user-info`, `/waf/`.

Test: `tests/Unit/WafMiddlewareJsonResponseTest.php` (challenge/block JSON per
XHR, HTML per navigazione).

## Componenti

```
┌─────────────────────────────────────────────────────────────┐
│  Browser                                                     │
│  1. GET / → no waf_session cookie                            │
│  2. Risposta inject /js/waf/fingerprint.js                   │
│  3. JS raccoglie ~30 parametri (canvas, webgl, audio, mouse) │
│  4. POST /waf/fingerprint con JSON                           │
│  5. Server risponde con Set-Cookie waf_session HMAC          │
│  6. JS reload pagina → ora con cookie → bypass middleware    │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Nginx (hosting legacy) + Apache + PHP 8.3                            │
│                                                              │
│  Kernel.php applica WafMiddleware globalmente:               │
│   1. enabled=0 → bypass                                      │
│   2. Path bypass (/waf/*, /admin/waf*, /js/*, /healthz)      │
│   3. Whitelist IP → pass + log                               │
│   4. Blacklist IP → 403 + log                                │
│   5. GeoIP check (geo_allowed) → 403 se enforce              │
│   6. Custom rules engine (waf_rules) → action prima match    │
│   7. Cookie waf_session HMAC verify:                         │
│       - valido + pass → bypass                               │
│       - valido + soft → interstitial                         │
│       - valido + block → 403                                 │
│       - assente → inject fingerprint page                    │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Applicazione pantedu (controller normale)                 │
└─────────────────────────────────────────────────────────────┘
```

## File implementation

| Layer | File |
|-------|------|
| DB schema | `database/migrations/048_waf_tables.sql` |
| Middleware | `app/Middleware/WafMiddleware.php` |
| Scoring engine | `app/Services/Waf/WafScoringService.php` (porting Lua → PHP) |
| Session HMAC | `app/Services/Waf/WafSessionService.php` |
| GeoIP lookup | `app/Services/Waf/GeoIpService.php` (MaxMind + CF header) |
| Config repo | `app/Services/Waf/WafConfigRepository.php` |
| Rules engine | `app/Services/Waf/WafRulesService.php` |
| Log service | `app/Services/Waf/WafLogService.php` |
| API controller | `app/Controllers/WafApiController.php` (POST /waf/fingerprint) |
| Admin controller | `app/Controllers/Admin/WafAdminController.php` |
| Admin views | `views/admin/waf/*.php` (dashboard, config, rules, lists, reports) |
| JS fingerprinter | `public/js/waf/fingerprint.js` |
| Config | `app/Config/waf.php` |
| Routes | `routes/web.php` block "WAF Phase 25.C" |
| Kernel hook | `app/Core/Kernel.php` handle() — WafMiddleware globale |

## Custom rules — esempi JSON

### Block User-Agent che contiene "AhrefsBot"

```json
{
  "logic": "AND",
  "conditions": [
    {"field": "user_agent", "operator": "contains", "value": "AhrefsBot"}
  ]
}
```
Action: `block`

### Allow Googlebot verificato (anti-spoofing via reverse DNS = TODO)

```json
{
  "logic": "AND",
  "conditions": [
    {"field": "user_agent", "operator": "contains", "value": "Googlebot"},
    {"field": "ip", "operator": "ip_in_cidr", "value": "66.249.64.0/19"}
  ]
}
```
Action: `allow`

### Challenge ogni request a /admin con UA mobile (probabile bot)

```json
{
  "logic": "AND",
  "conditions": [
    {"field": "url", "operator": "starts_with", "value": "/admin"},
    {"field": "user_agent", "operator": "matches_regex", "value": "(?i)mobile|android|iphone"}
  ]
}
```
Action: `challenge`

### Block country in lista deny

```json
{
  "logic": "OR",
  "conditions": [
    {"field": "country", "operator": "is_in_list", "value": ["CN", "RU", "KP", "IR"]}
  ]
}
```
Action: `block`

## Cleanup log retention (cron)

Aggiungi cron giornaliero per pulire log > 7 giorni:

```bash
0 3 * * * cd /var/www/pantedu && php tools/waf/purge_old_logs.php
```

Script semplice (TODO: creare `tools/waf/purge_old_logs.php`):

```php
<?php
require __DIR__.'/../../app/bootstrap.php';
$days = (int) (new \App\Services\Waf\WafConfigRepository())->get('log_retention_days', '7');
$purged = (new \App\Services\Waf\WafLogService())->purgeOlderThan($days);
echo "Purged $purged log entries older than $days days.\n";
```

## Deploy Nginx/Lua (Layer 1 deferred)

Per implementare il **Layer 1** (pre-filter veloce con GeoIP nativo + Lua scoring), serve accesso VPS root.

### Prerequisiti VPS

```bash
# OpenResty (Nginx + LuaJIT integrato)
sudo apt install openresty
# oppure compilare Nginx con ngx_http_lua_module + ngx_http_geoip2_module
```

### Layer 1 GeoIP fast-path (nginx.conf)

```nginx
load_module modules/ngx_http_geoip2_module.so;

http {
    geoip2 /etc/GeoIP/GeoLite2-Country.mmdb {
        $geoip2_country_code country iso_code;
    }
    map $geoip2_country_code $allowed_country {
        default 0;
        IT 1;
        SM 1;
        VA 1;
    }
}

server {
    listen 443 ssl http2;
    server_name beta.pantedu.eu;

    # Pass country code al backend PHP (per WafMiddleware logging)
    proxy_set_header X-GeoIP-Country $geoip2_country_code;

    # Pre-filter GeoIP fast-path (skip app PHP per traffico non-IT)
    if ($allowed_country = 0) {
        return 403 "Geographic restriction";
    }

    # Tutto il resto: passa al backend PHP normalmente
    location / {
        proxy_pass http://127.0.0.1:80;
        # ... resto config esistente
    }
}
```

### GeoIP database auto-update settimanale

```bash
# Installa geoipupdate (hosting legacy VPS Ubuntu)
sudo apt install geoipupdate

# Config /etc/GeoIP.conf
AccountID  <YOUR_MAXMIND_ID>
LicenseKey <YOUR_LICENSE_KEY>
EditionIDs GeoLite2-Country

# Cron settimanale
echo '0 3 * * 0 /usr/bin/geoipupdate && systemctl reload nginx' | sudo tee /etc/cron.d/geoipupdate
```

### Layer 1 Lua scoring (opzionale)

Vedi `docs/todo/waf_security_prompt.md` Parte 2 per implementazione OpenResty Lua completa. **Non necessaria** se Layer 2 PHP è sufficiente — il Layer 2 fa già tutto il lavoro, il Layer 1 è solo ottimizzazione di performance per traffico ad alto volume.

## Limiti noti / TODO

1. **HMAC key rotation**: attualmente single-key. Implementare dual-key con grace period per evitare logout massivi al rollover. [Phase 25.C.2]
2. **Verifica reverse DNS Googlebot/Bingbot**: rules `allow` per crawler verificati richiedono `gethostbyaddr()` + match dominio noto. [Phase 25.C.3]
3. **A/B testing soglie**: track false positive rate (sessioni "soft" che poi completano checkout) → suggest threshold tuning. [Phase 25.C.4]
4. **Layer 1 Nginx/Lua**: vedi sezione deploy sopra.
5. **WafMiddleware fail-safe**: in caso di errore middleware (DB down, config corruption), Kernel `try/catch` bypassa WAF — non bloccare la request. Verifica con kill DB durante test.
6. **Tabler.io**: l'admin UI usa design tokens pantedu (`fm-card`, `fm-btn`) invece di Tabler.io come da prompt — coerente con resto del progetto.

## Sicurezza del WAF stesso

- Cookie `waf_session`: HMAC-SHA256, IP-bound (anti-hijacking), TTL configurabile (default 1h)
- HMAC key: env `WAF_HMAC_SECRET` >= 32 byte. Genera con `openssl rand -hex 32`. Mai committare il valore reale (in `.env.local` gitignored).
- Admin UI: gate `super_admin_required` (matcha `users.is_super_admin=1` in DB).
- Write API: CSRF token obbligatorio + rate-limit + audit_reason (logging audit trail).
- Body size limit `/waf/fingerprint`: 16 KB (vedi controller). Prevent payload bombing.

## Integration con security stack esistente

| Layer | Componente |
|-------|------------|
| HTTPS + HSTS | nginx + `SecurityHeadersMiddleware` |
| CSP + sec headers | `SecurityHeadersMiddleware` |
| CSRF | `CsrfMiddleware` |
| Rate-limit (per-user) | `RateLimitMiddleware` (in-app) |
| **WAF (geo+bot)** | **`WafMiddleware` (questo modulo)** |
| Auth + RBAC | `AuthMiddleware` + `RoleMiddleware` |
| Audit | `AccessLogMiddleware` + `SuperAdminAuditMiddleware` |

Il WAF si applica **prima** del routing (Kernel global) ma **dopo** Request parsing — early-exit per `enabled=0` ha overhead trascurabile (1 DB query con cache static).
