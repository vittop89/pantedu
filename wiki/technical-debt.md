---
tags:
  - documentazione/debt
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["technical-debt", "debito tecnico", "todo"]
cssclasses: []
---

# Technical Debt

## Registro

| # | Area | Descrizione | Rischio | File | Phase |
|---|------|-------------|---------|------|-------|
| 1 | ExportController | `processLegacyTex()` è un metodo privato di ~350 LOC con 8+ step regex. Difficile testare unitariamente. | Alto | `app/Controllers/Risdoc/ExportController.php` | 21 |
| 2 | ~~Vite integration~~ ✅ | RISOLTO: `ViteManifest::script()` integrato in `views/partials/head.php` (emette il bundle quando il manifest esiste, fallback ai moduli diretti in dev). | — | `app/Support/ViteManifest.php`, `views/partials/head.php` | 17 |
| 3 | ~~risdoc.js (Plan A)~~ ✅ | RISOLTO: la IIFE monolitica `storage/templates/risdoc/risdoc.js` è stata **rimossa dal repo** (in git history). Superata da Plan B WC. | — | (rimosso) | - |
| 4 | DB dual-write | `DB_DUAL_WRITE=true` scrive su DB e JSON simultaneamente. Rischio desync. Deve essere disabilitato dopo consolidamento. | Medio | `app/Config/database.php`, `TeacherContentRepository` | 18 |
| 5 | LegacyController serve | PHP serve file statici (JS, CSS, JSON) invece di Apache direttamente. Overhead inutile, potenziale vettore se whitelist non completa. | Basso | `app/Controllers/LegacyController.php`, `routes/web.php` | - |
| 6 | Rate limit store file-based | `RateLimitStore` usa file PHP, non atomic su race conditions. Accettabile per basso traffico, non scalabile. | Basso | `app/Services/RateLimitStore.php` | - |
| 7 | CSRF token non single-use | Il token CSRF rimane valido per tutto il TTL (7200s). Replay attack possibile entro la finestra. | Basso | `app/Core/Csrf.php` | - |
| 8 | Google Apps Script / mappe | Zero test. Dipendenza da token utente Google. Nessuna gestione errori strutturata. | Medio | `js/modules/integrations/google-apps.js`, `scriptGoogle_sync/` | - |
| 9 | `TeacherContentController` | Controller con molti metodi (quesito CRUD, group CRUD, sidebar, manifest, contract). Candidato a split. | Basso | `app/Controllers/TeacherContentController.php` | 16-20 |
| 10 | `ContractAggregate::findItemIndex` | `{itemRef}` è un locator opaco con 3 formati (numeric, `{gid}_q{idx}`, `g{gi}_q{ii}`). Documentato nel codice ma fragile. | Medio | `app/Services/Contract/ContractAggregate.php` | 16 |
| 11 | pdflatex non disponibile Aruba | Su Aruba shared hosting pdflatex non è installabile. Workaround: ZIP+Overleaf. Compilazione server-side non possibile in prod. | Alto (funzionale) | `app/Controllers/Risdoc/ExportController.php` | 21 |
| 12 | Frontend JS non bundle | `js/modules/bootstrap.js` caricato direttamente senza Vite bundle. No tree-shaking, no HMR in prod. | Basso | `views/partials/head.php` | 17 |
| 13 | `_archive_phase18/` `_archive_phase20/` | Directory archivio con codice legacy (verifiche PHP 3000-5000 LOC). Non più usato ma presenti nel repo. | Basso (disk) | `_archive_phase18/`, `_archive_phase20/` | - |
| 14 | Session `is_super_admin` caching | Flag cachato in sessione, aggiornato solo via `refreshCurrentUserClaims()`. Se il flag cambia nel DB, resta stale fino al logout. | Basso | `app/Core/Auth.php::isSuperAdmin()` | 14 |
| 15 | `STORAGE_SIGNING_SECRET` vuoto | Se non configurato, gli URL signed falliscono silenziosamente. Nessuna validazione obbligatoria al bootstrap. | Basso | `app/Config/storage.php`, `app/Controllers/StorageController.php` | 14 |

## Risolti (reference)

| Area | Descrizione | Risolto in |
|------|-------------|-----------|
| Utenti JSON → DB | Migrazione da file JSON a MySQL | Phase 18 |
| Auth legacy `/check_password.php` | Spostato sotto admin csrf+rate | Phase 18 |
| `/mappe/*` legacy | Rimosso con 410 Gone | Phase 18-19 |
| `UserRepository` hard-coded path | Centralizzato in Config | Phase 18 |
| Esercizi create/duplicate via PHP | Sostituiti da ContractRepository API | Phase 18 |
| Shim jQuery `ajax-compat.js` (`$.ajax`-like `done/fail/always`) | Eliminato: 8 moduli migrati a `fetch`/`fetchJson` vanilla (`then/catch/finally`). Zero jQuery nel runtime | 2026-06-05 |
| CSRF token sparso (~8+ helper locali `getCsrf`/`csrf`/`bsCsrf`/`_getCsrf`) | Centralizzato su `dom-utils.fetchCsrf` (unico `/auth/csrf` canonico) | 2026-06-05 |
| CSS-in-JS runtime (`bootstrap-compat.injectSyncStyles`) | Spostato in `css/modules/_sync-status.css` (ADR-023 Fase 5). CI `no-css-in-js` verde | 2026-06-05 |
| WAF challenge HTML rompe le `fetch` JSON | `WafMiddleware` risponde JSON 403 alle XHR/`/api/*` (decisione invariata); client auto-reload via `assertJson` | 2026-06-05 |
