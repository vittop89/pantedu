---
tags:
  - documentazione/environment
date: 2026-04-23
tipo: environment
status: finale
aliases: ["env", "environment", "variabili"]
cssclasses: []
---

# Environment Variables

File: `.env` (root progetto). Template: `.env.example`. Caricato da `Dotenv::createImmutable()` in `app/bootstrap.php`.

## Tabella completa

| Variabile | Obbligatoria | Valore esempio | Modulo | Effetto | Secret? |
|-----------|:-----------:|---------------|--------|---------|:-------:|
| `APP_ENV` | No | `production` | `app/Config/app.php` | `production\|development\|testing` | No |
| `APP_DEBUG` | No | `false` | `app/Config/app.php` | `true` → display_errors=1 + stack trace in HTML | No |
| `APP_URL` | No | `https://www.pantedu.eu` | `app/Config/app.php` | URL base; usato in export/redirect | No |
| `APP_TIMEZONE` | No | `Europe/Rome` | `app/Config/app.php` | `date_default_timezone_set()` | No |
| `DB_ENABLED` | **Si** | `true` | `app/Config/database.php` | `false` → fallback JSON files | No |
| `DB_DRIVER` | No | `mysql` | `app/Config/database.php` | Driver PDO | No |
| `DB_HOST` | Cond. | `127.0.0.1` | `app/Config/database.php` | Host MySQL | No |
| `DB_PORT` | No | `3306` | `app/Config/database.php` | Porta MySQL | No |
| `DB_NAME` | Cond. | `pantedu_dev` | `app/Config/database.php` | Nome database | No |
| `DB_USER` | Cond. | `root` | `app/Config/database.php` | Utente MySQL | No |
| `DB_PASS` | Cond. | `` | `app/Config/database.php` | Password MySQL | **Si** |
| `DB_CHARSET` | No | `utf8mb4` | `app/Config/database.php` | Charset connessione | No |
| `DB_DUAL_WRITE` | No | `true` | `app/Config/database.php` | Scrive DB + JSON legacy simultaneamente | No |
| `SESSION_LIFETIME` | No | `1800` | `app/Config/session.php` | Durata sessione in secondi | No |
| `SESSION_REGENERATE_INTERVAL` | No | `300` | `app/Config/session.php` | Intervallo rigenerazione SID | No |
| `SESSION_COOKIE_NAME` | No | `PANTEDU_SID` | `app/Config/session.php` | Nome cookie sessione | No |
| `SESSION_COOKIE_SECURE` | No | `true` | `app/Config/session.php` | Cookie HTTPS-only; auto-detect se non presente | No |
| `SESSION_COOKIE_SAMESITE` | No | `Lax` | `app/Config/session.php` | SameSite policy | No |
| `CSRF_TOKEN_LIFETIME` | No | `7200` | `app/Core/Csrf.php` | TTL token CSRF in secondi | No |
| `LOGIN_MAX_ATTEMPTS` | No | `5` | `app/Config/auth.php` | Max tentativi login prima lockout | No |
| `LOGIN_LOCKOUT_SECONDS` | No | `300` | `app/Config/auth.php` | Durata lockout in secondi | No |
| `LOG_LEVEL` | No | `info` | `app/Config/monitoring.php` | Livello log: `debug\|info\|warning\|error` | No |
| `LOG_MAX_ENTRIES` | No | `1000` | `app/Config/monitoring.php` | Max entries per file log JSON | No |
| `LOG_RETENTION_DAYS` | No | `30` | `app/Config/retention.php` | Giorni retention log prima di rotate | No |
| `STORAGE_PROVIDER` | No | `local` | `app/Config/storage.php` | `local\|s3` | No |
| `STORAGE_SIGNING_SECRET` | No | `` | `app/Config/storage.php` | Segreto HMAC per signed URL | **Si** |
| `STORAGE_S3_ENDPOINT` | Cond. | `` | `app/Config/storage.php` | Endpoint S3-compatible (es. Cloudflare R2) | No |
| `STORAGE_S3_BUCKET` | Cond. | `` | `app/Config/storage.php` | Nome bucket S3 | No |
| `STORAGE_S3_REGION` | No | `auto` | `app/Config/storage.php` | Regione S3 | No |
| `STORAGE_S3_ACCESS_KEY` | Cond. | `` | `app/Config/storage.php` | Access key S3 | **Si** |
| `STORAGE_S3_SECRET` | Cond. | `` | `app/Config/storage.php` | Secret key S3 | **Si** |
| `FM_E2E_BASE_URL` | No | `http://pantedu.local` | `playwright.config.js` | URL base per test E2E | No |

## Note configurazione

### DB_ENABLED=false (modalità JSON)
In assenza di MySQL (es. prima attivazione hosting legacy), il sistema usa file JSON legacy in `log/data/`:
- `log/data/admin_users.json` — utenti admin
- `log/data/collaborators.json` — collaboratori
- `eser/{folder}/eser_{code}/users/users.json` — utenti per classe

Importazione verso MySQL: `php tools/import_legacy_users_to_db.php`.

### STORAGE_SIGNING_SECRET
Usato da `StorageController::signed()` per generare URL temporanei HMAC-SHA256. Se vuoto, gli URL signed non funzionano.

### SESSION_COOKIE_SECURE auto-detect
Se la variabile non è nel `.env`, il sistema la imposta `true` automaticamente se `$_SERVER['HTTPS']` è attivo o porta 443. In dev XAMPP HTTP locale sarà `false` automaticamente.
