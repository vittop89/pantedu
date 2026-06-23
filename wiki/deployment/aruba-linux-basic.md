---
tags:
  - documentazione/deployment
date: 2026-04-24
tipo: deployment
status: draft
aliases: ["deploy aruba", "aruba hosting"]
cssclasses: []
---

# Deployment — Aruba Linux basic hosting

> [!info] Target: shared hosting Aruba linux basic (tier più economico,
> FTP-only, Apache + PHP + MySQL, no SSH, no shell). Compatibile per
> analogia con hosting shared simili (Netsons, Siteground entry, Keliweb, …).

## Vincoli ambiente

| Aspetto | Aruba basic | Impatto |
|---------|-------------|---------|
| Shell / SSH | ❌ No (solo FTP/SFTP) | Tutti build/CLI vanno fatti sulla dev machine |
| Composer sul server | ❌ No | `vendor/` pre-buildata + uploadata |
| Node.js / npm sul server | ❌ No | `public/build/` pre-buildata + uploadata |
| Apache | ✓ Sì | `.htaccess` + mod_rewrite OK |
| PHP | ✓ 8.x (check pannello Aruba) | Serve PHP ≥ 8.3 (composer.json requirement) |
| MySQL / MariaDB | ✓ Sì (Sql*) | Standard PDO, nessuna config custom |
| pdflatex | ❌ No | Export PDF via [Overleaf redirect](#pdf-export) |
| Cron job | ✓ Sì (pannello) | Log rotation / cleanup opzionale |
| Symlink / DocumentRoot custom | ⚠ Limitato | Layout deploy con `httpdocs/` + `private/` sibling |

## Architettura layout server

```
/home/USER/
├── httpdocs/           ← webroot (public-facing)
│   ├── index.php       ← entry point (detect layout via ../private/)
│   ├── .htaccess       ← mod_rewrite passthrough + security headers
│   ├── build/          ← bundle Vite (bootstrap, risdoc-pt-editor, …)
│   ├── assets/         ← immagini, CSS (copiati da public/)
│   └── *.html          ← demo pages se presenti
└── private/            ← sibling dir (NO web-accessible)
    ├── app/            ← controller, middleware, services, bootstrap.php
    ├── vendor/         ← composer deps (pre-installate locally)
    ├── routes/web.php
    ├── schemas/        ← JSON schemas risdoc (con default PT migrati)
    ├── views/          ← templates PHP
    ├── bin/            ← CLI scripts (risdoc-pt-seed.php ecc., non eseguibili)
    ├── storage/
    │   ├── logs/       ← 775 writable
    │   ├── sessions/   ← 775 writable (se session file-based)
    │   ├── risdoc-tmp/ ← 775 writable
    │   ├── templates/
    │   ├── data/
    │   └── overrides/
    ├── .env            ← credenziali DB Aruba + config prod
    ├── composer.json
    └── composer.lock
```

Detection layout: `public/index.php` verifica
l'esistenza di `../private/app/bootstrap.php`. Se presente → usa `private/`
come app root; altrimenti fallback dev layout (`../` come root).

Vantaggi del layout "private sibling":
- `vendor/`, `app/`, `schemas/`, `storage/` FUORI dalla webroot → no leak
  accidentale (es. `https://dominio.it/app/Controllers/AdminController.php`)
- `.env` con credenziali DB fuori webroot → no esposizione
- Logs/sessions scrivibili senza esporre in webroot

## Build pipeline (dev machine)

### Automatico

```bash
# Linux/macOS
bash scripts/build-for-aruba.sh

# Windows
.\scripts\build-for-aruba.ps1
```

Flag opzionale `--with-seed` / `-WithSeed` esegue `bin/risdoc-pt-seed.php
--all --auto-annotate --apply` prima del build → schemi con default PT AST
pre-popolati.

Output: `dist/httpdocs/` + `dist/private/` pronti per FTP upload.

### Manuale (step-by-step)

```bash
# 1. Composer production install
composer install --no-dev --optimize-autoloader

# 2. Vite build
npm ci
npm run build

# 3. (Opt) seed PT defaults
php bin/risdoc-pt-seed.php --all --auto-annotate --apply

# 4. Dump DB locale
mysqldump -u root pantedu_dev > /tmp/pantedu_dump.sql
```

## FTP upload

### Primo deploy

1. **Webroot** (`public/` → `httpdocs/`):
   - Upload `dist/httpdocs/*` nella cartella webroot Aruba (tipicamente
     `/home/USER/httpdocs/` o `~/www/`).
2. **Private payload** (`app/`, `vendor/`, ecc. → `private/`):
   - Crea `/home/USER/private/` via FTP.
   - Upload `dist/private/*` dentro.
3. **Permessi** (via pannello Aruba o client FTP che supporta CHMOD):
   ```
   private/storage/logs/       775
   private/storage/sessions/   775
   private/storage/risdoc-tmp/ 775
   ```
4. **.env** (solo la prima volta):
   - Copia `private/.env.example` → `private/.env` via FTP.
   - Edita in-place con credenziali DB Aruba:
     ```
     DB_HOST=sql.tuohost.aruba.it
     DB_NAME=Sqltuonome
     DB_USER=Sqltuonome
     DB_PASS=<password dal pannello Aruba>
     CSRF_SECRET=<32 char random, generato in locale>
     ```
5. **Database import**:
   - Apri phpMyAdmin Aruba (pannello → Database).
   - Import del dump `database/schema.sql` (o dump completo se migration serve).

### Deploy incrementale (solo codice)

Cambiamenti tipici:
- Solo source PHP → upload `private/app/` + `private/routes/` + `private/schemas/`
- Solo frontend → `npm run build` locale + upload `httpdocs/build/`
- Solo schema PT → re-run `bin/risdoc-pt-seed.php --apply` locale, upload
  `private/schemas/risdoc/*.json`

## Verifica post-deploy

```
1. https://TUO-DOMINIO.it/                    → home carica senza 500
2. https://TUO-DOMINIO.it/login               → login page accessibile
3. Login admin → https://TUO-DOMINIO.it/risdoc/view/1
   → template risdoc carica + <fm-risdoc-pt-editor> bundle lazy-load
4. https://TUO-DOMINIO.it/api/probe           → 200 OK (no 500)
5. https://TUO-DOMINIO.it/build/manifest.json → JSON con 3 chiavi
```

Debug:
- 500 error senza dettagli → check `private/storage/logs/php_errors.log`
- Check permessi: `private/storage/*/` 775 + owner corretto
- Check `.env` caricato: `APP_ENV=production` (altrimenti debug mode)
- mod_rewrite: se `.htaccess` ignorato → contatta Aruba support per
  `AllowOverride All` nella webroot

## PDF export

`pdflatex` NON è disponibile su Aruba shared. Pipeline TeX → PDF:

| Strategia | Latency | Qualità | Note |
|-----------|---------|---------|------|
| **Overleaf redirect** (attiva) | 3-5s | ⭐⭐⭐⭐⭐ | User clicca "Compila" → browser apre Overleaf con `.tex` pre-caricato |
| latex.js client-side | 10-20s primo load | ⭐⭐⭐ | No server, bundle ~3MB, meno accurate su TikZ |
| API esterna (latexonline.cc) | 2-4s | ⭐⭐⭐⭐ | Dipende da uptime third-party |
| VPS dedicato (es. Contabo 4€/mese) | <1s | ⭐⭐⭐⭐⭐ | Proxy `/api/compile-tex` da Aruba a VPS |

Default POC Phase 22: **Overleaf redirect** (già implementato, commit
`bdde26f` Phase 20). L'export via TemplateController::tex genera `.tex`,
il frontend lo POSTa a Overleaf in nuova tab.

## Checklist pre-deploy

- [ ] `composer install --no-dev --optimize-autoloader` eseguito
- [ ] `npm run build` eseguito, `public/build/manifest.json` presente
- [ ] `php bin/risdoc-pt-seed.php --all --auto-annotate --apply` (se serve)
- [ ] DB schema applicato su Aruba via phpMyAdmin
- [ ] `.env` sul server con credenziali corrette
- [ ] `chmod 775` su `storage/logs`, `storage/sessions`, `storage/risdoc-tmp`
- [ ] PHP version ≥ 8.3 (pannello Aruba)
- [ ] Test home + login + /risdoc/view/1 + /api/probe

## Troubleshooting noti

**"Class App\\Core\\Kernel not found"** → `vendor/` non uploadato o
autoload stale. Re-run `composer install` + upload `vendor/`.

**"Uncaught Error: Call to undefined function Dotenv\\..."** → stesso
motivo sopra, dep composer mancanti.

**Editor risdoc non carica / 404 `/build/manifest.json`** → bundle Vite
non uploadato o webroot wrong. Verifica `https://DOMAIN/build/manifest.json`
ritorna JSON.

**500 Internal Server Error generico** → check `storage/logs/php_errors.log`
(richiede `APP_DEBUG=false` + path scrivibile).

**Session non persiste** → `SESSION_DRIVER=file` ma `storage/sessions/`
non scrivibile. chmod 775 o usa DB sessions (modifica `bootstrap.php`).

## Alternative hosting considerate

| Hosting | Pro | Contro |
|---------|-----|--------|
| Aruba Linux basic | Cheap (~15€/anno), affidabile | No SSH, no pdflatex |
| Netsons shared | Simile Aruba | Simile |
| Siteground entry | Più veloce | ~4x costo |
| DigitalOcean droplet (5€/mese) | pdflatex + SSH + root | Richiede admin skills |
| Railway / Fly.io | Modern, SSH, deploy git | Pricing model variabile |

**Raccomandazione**: Aruba basic per POC/pilot (docente singolo), poi
migrazione a VPS quando docenti >20 o export PDF in-house diventa critico.
