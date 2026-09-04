---
tags:
  - documentazione/architettura
  - dominio/admin
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["admin", "amministrazione"]
cssclasses: []
---

# Dominio: admin

> [!abstract] Scopo
> Dashboard amministrativa: gestione utenti, approvazione registrazioni, analytics accessi, monitoraggio infrastruttura, sicurezza (blocklist, anomalie), tools (hash, curriculum), admin risdoc.

## Confini del dominio

- **In**: admin autenticato (role=administrator o is_super_admin)
- **Out**: JSON stats, HTML dashboard, azioni CRUD su utenti/sicurezza/curriculum

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| AdminController | `app/Controllers/AdminController.php` | Dashboard, log viewer (access/debug), notifications, whoAmI, generateHash |
| AdminAnalyticsController | `app/Controllers/AdminAnalyticsController.php` | `snapshot`, `forTeacher`, `crossSearch` |
| AdminInfrastructureController | `app/Controllers/AdminInfrastructureController.php` | Monitoring infra (super-admin only nel controller) |
| AdminPrintController | `app/Controllers/AdminPrintController.php` | `generate`, `batch` — TeX print admin |
| AdminToolsController | `app/Controllers/AdminToolsController.php` | Pagina tools admin |
| UsersAdminController | `app/Controllers/UsersAdminController.php` | CRUD utenti: `index`, `setActive`, `setRole`, `delete` |
| SecurityAdminController | `app/Controllers/SecurityAdminController.php` | Blocklist CRUD, anomalie, config sicurezza |
| RegistrationController | `app/Controllers/RegistrationController.php` | `listPending`, `approve`, `reject` |
| CurriculumController | `app/Controllers/CurriculumController.php` | CRUD curriculum (indirizzi, classi, materie) |
| RisdocAdminController | `app/Controllers/Admin/RisdocAdminController.php` | Admin panel risdoc: template, visibilità, owner, collaboratori, drift |
| AdminAnalyticsService | `app/Services/AdminAnalyticsService.php` | Aggregazione metriche accessi |
| InfrastructureMonitorService | `app/Services/InfrastructureMonitorService.php` | Health check: DB, storage, log, PHP extensions |
| AnomalyDetectionService | `app/Services/AnomalyDetectionService.php` | Rilevamento anomalie nei log accessi |
| AdminNotificationsService | `app/Services/AdminNotificationsService.php` | Notifiche pending registrations, alerts |
| LogRotator | `app/Services/LogRotator.php` | Rotazione log automatica (throttled 1h) |
| LogTailer | `app/Services/LogTailer.php` | Tail log files per dashboard |

## Viste admin

| View | URL |
|------|-----|
| `views/admin/dashboard.php` | `/admin` |
| `views/admin/analytics.php` | `/admin/analytics` |
| `views/admin/infrastructure.php` | `/admin/infrastructure` |
| `views/admin/tools.php` | `/admin/tools` |
| `views/admin/curriculum.php` | `/admin/curriculum` |
| `views/admin/risdoc.php` | `/admin/risdoc` |

## JS modules (admin)

| Modulo | File | Funzione |
|--------|------|---------|
| admin-tools | `js/modules/features/admin-tools.php` | UI tools admin |
| admin-risdoc | `js/modules/features/admin-risdoc.js` | UI admin risdoc: lista template, visibilità, drift |
| admin-banner-badge | `js/modules/features/admin-banner-badge.js` | Badge notifiche admin in header |

## Controllo accesso

Middleware `role:admin` su tutti gli endpoint. Super-admin (`is_super_admin=1`) bypass la zona admin anche con role `teacher`. Il `AdminInfrastructureController` ha un controllo interno aggiuntivo per super-admin.

## API chiave

| Method + Path | Funzione |
|--------------|---------|
| `GET /api/admin/users` | Lista utenti con filtri |
| `POST /api/admin/users/{id}/role` | Cambia ruolo + csrf + rate |
| `POST /api/admin/users/{id}/active` | Attiva/disattiva utente + csrf + rate |
| `POST /admin/registrations/{id}/approve` | Approva registrazione + csrf + rate |
| `GET /api/admin/analytics` | Snapshot analytics accessi |
| `GET /api/admin/infrastructure.json` | Health check infrastruttura |
| `GET /api/admin/security/anomalies` | Anomalie rilevate |
| `POST /api/admin/security/credentials/block` | Blocca credential + csrf + rate |
| `GET /api/admin/risdoc/drift` | Drift template per tutti i docenti |

## Link correlati

[[routing-and-api]] · [[security-notes]] · [[domains/auth/auth-overview]] · [[domains/risdoc/risdoc-overview]]
