---
tags:
  - documentazione/architettura
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["entrypoints"]
cssclasses: []
---

# Entrypoints

## HTTP entrypoints

| File | Trigger | Responsabilità | Moduli coinvolti |
|------|---------|----------------|-----------------|
| `public/index.php` | Ogni richiesta HTTP (Apache rewrite) | Bootstrap app, istanzia Router+Kernel, chiama `handle()` → `send()` | bootstrap.php, Router, Kernel |
| `app/bootstrap.php` | Chiamato da `public/index.php` | Carica autoloader, Dotenv, Config, Session::start() | vendor/autoload, Dotenv, Config, Session |
| `routes/web.php` | Incluso da `public/index.php` | Registra tutte le route nell'istanza Router | Tutti i Controller |

## CLI / Tool entrypoints

| File | Trigger | Responsabilità |
|------|---------|----------------|
| `tools/import_legacy_users_to_db.php` | `php tools/...` manuale | Migrazione utenti JSON → MySQL (one-shot) |
| `tools/generate_password_hash.php` | `php tools/... "password"` | Genera bcrypt hash per utenti |
| `app/Core/Migrator.php` | `php bin/migrate` | Esegue migrations SQL in `database/migrations/` |

## Vite / Frontend entrypoints

| File | Trigger | Output |
|------|---------|--------|
| `js/modules/bootstrap.js` | `npm run build` | `public/build/assets/bootstrap.[hash].js` |
| `js/fm-router.js` | `npm run build` | `public/build/assets/fm-router.[hash].js` |

> [!warning] Integrazione Vite incompleta
> `App\Support\ViteManifest` esiste ma `views/partials/head.php` non la usa ancora. I bundle Vite sono generati ma non serviti automaticamente. I template PHP caricano ancora `js/modules/bootstrap.js` direttamente.

## Sessione e auth entrypoints

| Azione | Route | File |
|--------|-------|------|
| Login form | `GET /login` | `AuthController::showLogin` |
| Login submit | `POST /login` + csrf | `AuthController::login` |
| Logout | `ANY /logout` | `AuthController::logout` |
| User info JSON | `GET /auth/user-info` | `AuthController::userInfo` |
| CSRF token | `GET /auth/csrf` | `AuthController::csrf` |
| Student login | `POST /api/access/student-login` + csrf + rate | `TeacherCredentialController::studentLogin` |

## Risdoc entrypoints (Plan B)

| Azione | Route | File |
|--------|-------|------|
| Lista template | `GET /api/risdoc/templates` | `TemplateController::index` |
| Editor template | `GET /risdoc/templates/{id}/edit` | `TemplateEditorController::show` |
| Vista template | `GET /risdoc/templates/{id}` | `TemplateViewController::show` |
| Salva compilazione | `POST /api/risdoc/templates/{id}/compilations` + csrf | `CompilationController::save` |
| Export ZIP/Overleaf | `POST /api/risdoc/templates/{id}/export` + csrf | `ExportController::export` |
| Serve ZIP | `GET /api/risdoc/exports/{file}` | `ExportController::serve` |
