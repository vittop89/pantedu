---
tags:
  - documentazione/workflow
date: 2026-04-23
tipo: workflow
status: finale
aliases: ["dev-workflow", "setup", "sviluppo"]
cssclasses: []
---

# Dev Workflow

## Prerequisiti

| Tool | Versione | Note |
|------|---------|------|
| PHP | ^8.3 | Con estensioni: pdo_mysql, mbstring, zip, fileinfo |
| Composer | 2.x | Gestione dipendenze PHP |
| MySQL / MariaDB | 5.7+ / 10.2+ | O XAMPP con MariaDB |
| Node.js | 18+ | Per Vite e Playwright |
| npm | 9+ | |
| MiKTeX / TexLive | Recente | Per pdflatex (test E2E TeX) |
| XAMPP | 8.x | Alternativa ad Apache + MySQL separati |

## Setup iniziale

```bash
# 1. Clona e installa dipendenze PHP (incl. dev: phpstan, phpcs, phpunit)
composer install

# 1b. Installa pre-commit hooks (Phase 25.E1.8)
bash tools/git/hooks/install.sh

# 2. Installa dipendenze JS
npm install

# 3. Copia env di sviluppo
cp .env.example .env
# Edita .env: APP_ENV=development, APP_DEBUG=true, DB_ENABLED=true, DB_*

# 4. Setup XAMPP
# a. Aggiungi vhost in httpd-vhosts.conf:
#    DocumentRoot "C:/percorso/pantedu/public"
#    ServerName pantedu.local
# b. Aggiungi in hosts: 127.0.0.1 pantedu.local
# c. Punta .htaccess al public/ come document root

# 5. Crea database
mysql -u root -p -e "CREATE DATABASE pantedu_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Esegui schema base
mysql -u root -p pantedu_dev < database/schema.sql

# 7. Esegui migrations
php bin/migrate
# oppure manualmente:
mysql -u root -p pantedu_dev < database/migrations/001_add_users_institute_id.sql
# ... fino a 008

# 8. Crea utente admin iniziale
php tools/generate_password_hash.php "tuapassword"
# Inserisci in DB manualmente:
mysql -u root -p pantedu_dev -e "
INSERT INTO users (username, role, email, password_hash, active, is_super_admin)
VALUES ('admin', 'administrator', 'admin@local.dev', '[HASH]', 1, 1);"
```

## Build frontend

```bash
# Development (HMR su porta 5173)
npm run dev

# Build produzione (output in public/build/)
npm run build

# Preview build locale
npm run preview
```

> [!warning] Integrazione Vite parziale
> I template PHP caricano ancora `js/modules/bootstrap.js` direttamente senza passare dal manifest Vite. Il bundle in `public/build/` è generato ma non usato automaticamente. Integrare `App\Support\ViteManifest` in `views/partials/head.php`.

## Test

```bash
# Unit test
php vendor/bin/phpunit tests/Unit/

# Integration test (richiede DB attivo)
php vendor/bin/phpunit tests/Integration/

# All tests
php vendor/bin/phpunit

# Installa Playwright (prima volta)
npm run e2e:install

# E2E (richiede XAMPP attivo su pantedu.local)
npm run e2e

# E2E con UI
npm run e2e:ui
```

## Workflow feature standard

```bash
# 1. Crea branch dal branch corrente
git checkout -b feat/nome-feature

# 2. Sviluppa e testa
php vendor/bin/phpunit tests/Unit/
npm run e2e

# 3. Verifica route (dopo modifica routes/web.php)
php -r "require 'app/bootstrap.php'; $r = new App\Core\Router(); require 'routes/web.php'; echo count((new ReflectionClass($r))->getProperty('routes')->getValue($r)) . ' routes OK';"

# 4. Commit atomico
git add app/ views/ js/ routes/ schemas/
git commit -m "feat(dominio): descrizione"

# 5. PR verso master (mai push diretto su master)
gh pr create --base master --title "feat: descrizione"
```

## Compilazione TeX locale (test pipeline)

```bash
# Richiede MiKTeX o TexLive installato e pdflatex in PATH

# Compila singolo file
pdflatex -synctex=1 -interaction=nonstopmode main.tex

# Test pipeline E2E completa (7 template)
npm run e2e -- tests/e2e/risdoc_tex_production.spec.js
```

## Utility tools

| Comando | Scopo |
|---------|-------|
| `php tools/generate_password_hash.php "pass"` | Hash bcrypt per utenti |
| `php tools/import_legacy_users_to_db.php` | Migrazione JSON → MySQL (one-shot) |
| `php bin/migrate` | Esegue migrations pending |
| `php bin/seed-risdoc` | Seed template risdoc in DB (se esiste) |

## Configurazione Apache vhost (XAMPP Windows)

```apache
# httpd-vhosts.conf
<VirtualHost *:80>
    DocumentRoot "C:/percorso/pantedu/public"
    ServerName pantedu.local
    <Directory "C:/percorso/pantedu/public">
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "logs/pantedu-error.log"
    CustomLog "logs/pantedu-access.log" common
</VirtualHost>
```

## Variabili .env per sviluppo

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://pantedu.local
DB_ENABLED=true
DB_HOST=127.0.0.1
DB_NAME=pantedu_dev
DB_USER=root
DB_PASS=
DB_DUAL_WRITE=false
SESSION_COOKIE_SECURE=false
LOG_LEVEL=debug

# Phase 25.B5 — disabilita rate limit in sviluppo/CI (mai in produzione)
RATE_LIMIT_DISABLED=1

# Phase 25.B4 — audit reason mode (warn|enforce|disabled)
AUDIT_REASON_MODE=warn

# Phase 25.D — Crypto envelope (gen via tools/crypto/generate_kms_key.php)
# In .env.local (gitignored), NON in .env committato
KMS_MASTER_KEY=
CRYPTO_DUAL_WRITE=0
CRYPTO_READ_FROM=plaintext

# Phase 25.E4.2 — /metrics auth (Bearer)
METRICS_BEARER_TOKEN=

# Phase 25.E4.3 — Telemetry span emission (off by default)
TELEMETRY_ENABLED=0

# Phase 25.C8 — Email parent_consent + breach
APP_MAIL_FROM=
APP_MAIL_FROM_NAME=Pantedu
```

## Quality gates (CI mirror locale)

```bash
# Statica PHP (level 6 con baseline grandfather, vedi phpstan-baseline.neon)
composer stan

# Style PHP (PSR-12 strict, 0 errors required dopo Phase 25.A7)
composer cs
# Auto-fix:
composer cs:fix

# Lint JS (max-warnings=47 hard ratchet, vedi package.json)
npm run lint
# Auto-fix:
npm run lint:fix
# Strict mode (0 warnings):
npm run lint:strict

# Build prod
npm run build

# Bundle budget (warning > 600 kB per chunk)
npm run bundle:budget

# Audit dipendenze npm
npm run audit:js

# Tutto in sequenza (npm-side, mirror del CI)
npm run ci
```

## Migrations

```bash
# Esegui migrations pending (con advisory lock multi-server, Phase 25.E3)
php tools/migrate.php

# Status (lista applicate vs pending)
php tools/migrate.php --status

# Dry run (mostra SQL senza apply)
php tools/migrate.php --dry-run
```

## Crypto (Phase 25.D)

```bash
# Genera KMS_MASTER_KEY (one-shot setup; salva in .env.local)
php tools/crypto/generate_kms_key.php

# Backfill body plaintext → ciphertext (idempotent + resumable)
php tools/crypto/backfill_teacher_content.php --dry-run
php tools/crypto/backfill_teacher_content.php --batch=100

# Rotation KEK (annuale)
php tools/crypto/rotate_kek.php --reencrypt --prune-old-kv

# Audit report (cross-teacher access, alert NO REASON, top 10 accessors)
php tools/crypto/audit_report.php
php tools/crypto/audit_report.php --json  # per webhook SOC

# Benchmark roundtrip
php tools/crypto/benchmark.php
```

## GDPR (Phase 25.C)

```bash
# Cron giornaliero — esegue cancellazioni in cooling-off scaduto (Art. 17)
php tools/gdpr/execute_pending_deletions.php

# Cron giornaliero — cleanup parent_consent expired (Art. 8)
php tools/gdpr/cleanup_expired_consents.php

# Drill semestrale — verify breach response readiness
php tools/gdpr/breach_drill.php
```
