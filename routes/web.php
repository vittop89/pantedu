<?php

use App\Controllers\AuthController;
use App\Controllers\FileController;
use App\Controllers\HomeController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// Phase 19 — closure no-op per route legacy_gone. Le route sono
// intercettate dal middleware LegacyGoneMiddleware che emette 410/302
// senza mai invocare l'handler. Placeholder necessario al Router.
$legacyGoneHandler = fn(Request $r, array $p = []) => Response::json(['error' => 'gone'], 410);

// G22.S15.bis Fase 5 — fallback per /favicon.ico: alcuni browser/estensioni
// chiedono ICO indipendentemente dal <link rel="icon"> SVG. Serve favicon.svg
// con Content-Type appropriato per evitare 404 noise nei log.
$router->get('/favicon.ico', function () {
    $svgPath = dirname(__DIR__) . '/public/favicon.svg';
    if (is_file($svgPath)) {
        return new \App\Core\Response(
            (string)file_get_contents($svgPath), 200,
            ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']
        );
    }
    return new \App\Core\Response('', 204);
});

/** @var Router $router */

// ───────────── PUBLIC ─────────────
$router->get ('/',                      [HomeController::class, 'index']);

// Phase G4 — GET /api/maps/dl?t=<token>&s=<sig>
// Pubblico: il signed URL (HMAC + TTL) e' l'auth. Stream del blob decifrato
// con Content-Type corretto. Il caller deve aver gia' ottenuto il token via
// /api/maps/{id}/signed-url (auth-gated).
$router->get('/api/maps/dl',           [\App\Controllers\MapsController::class, 'download']);
// Health & version (pubblici) — deploy-verify via curl + monitoring.
$router->get('/version',                [\App\Controllers\HealthController::class, 'version']);
$router->get('/health',                 [\App\Controllers\HealthController::class, 'health']);
$router->get ('/login',                 [AuthController::class, 'showLogin']);
// Phase 25.B5 — rate-limit tight su login (10/min/IP, brute-force gate).
$router->post('/login',                 [AuthController::class, 'login'])->middleware('csrf', 'rate:login,10');
$router->any ('/logout',                [AuthController::class, 'logout']);
$router->get ('/auth/user-info',        [AuthController::class, 'userInfo']);
$router->get ('/auth/csrf',             [AuthController::class, 'csrf']);

// Phase D.2 — SPID + CIE federated identity (scaffolding stage, 503
// no-op finche' pantedu non e' SP registrato presso AgID).
// Spec: docs/plans/d2-spid-cie-integration.md.
$router->get('/auth/spid/login',    [\App\Controllers\Auth\SpidController::class, 'login']);
$router->get('/auth/spid/callback', [\App\Controllers\Auth\SpidController::class, 'callback']);
$router->get('/auth/spid/metadata', [\App\Controllers\Auth\SpidController::class, 'metadata']);
$router->get('/auth/spid/logout',   [\App\Controllers\Auth\SpidController::class, 'logout']);
$router->get('/auth/cie/login',     [\App\Controllers\Auth\CieController::class, 'login']);
$router->get('/auth/cie/callback',  [\App\Controllers\Auth\CieController::class, 'callback']);
$router->get('/auth/cie/metadata',  [\App\Controllers\Auth\CieController::class, 'metadata']);
$router->get('/auth/cie/logout',    [\App\Controllers\Auth\CieController::class, 'logout']);

// Phase 14 — self-service change password (qualsiasi utente autenticato).
$router->get ('/me/change-password',    [\App\Controllers\UserProfileController::class, 'showChangePassword']);
$router->post('/me/change-password',    [\App\Controllers\UserProfileController::class, 'changePassword'])
       ->middleware('csrf');

// Phase 25.J.4 — 2FA TOTP self-service (RFC 6238).
// Master toggle 'security.totp_enabled' default OFF: infra installata,
// utenti possono enrollarsi, check al login parte quando admin attiva.
$router->get ('/me/2fa',                [\App\Controllers\TotpController::class, 'page']);
$router->post('/me/2fa/setup',          [\App\Controllers\TotpController::class, 'setup'])->middleware('csrf');
$router->post('/me/2fa/enable',         [\App\Controllers\TotpController::class, 'enable'])->middleware('csrf');
$router->post('/me/2fa/disable',        [\App\Controllers\TotpController::class, 'disable'])->middleware('csrf');

// Phase 25.C — self-service GDPR endpoints (Art. 7, 16, 17, 20).
// Tutti richiedono auth (qualsiasi user autenticato), CSRF su mutation.
$router->get ('/me/consents',           [\App\Controllers\SelfServiceController::class, 'consentsList']);
$router->post('/me/consents/grant',     [\App\Controllers\SelfServiceController::class, 'consentGrant'])->middleware('csrf');
$router->post('/me/consents/revoke',    [\App\Controllers\SelfServiceController::class, 'consentRevoke'])->middleware('csrf');

$router->post('/me/request-deletion',   [\App\Controllers\SelfServiceController::class, 'requestDeletion'])->middleware('csrf', 'rate:deletion,5');
$router->get ('/me/confirm-deletion',   [\App\Controllers\SelfServiceController::class, 'confirmDeletion']);
$router->post('/me/cancel-deletion',    [\App\Controllers\SelfServiceController::class, 'cancelDeletion'])->middleware('csrf');
$router->get ('/me/deletion-status',    [\App\Controllers\SelfServiceController::class, 'deletionStatus']);

$router->get ('/me/export-data',        [\App\Controllers\SelfServiceController::class, 'exportData'])->middleware('rate:export,3');
$router->post('/me/profile',            [\App\Controllers\SelfServiceController::class, 'profilePatch'])->middleware('csrf');

// Phase 25.C7 — Parent consent Art. 8 GDPR per minori < 14 anni (Italia D.Lgs.
// 101/2018). Token in URL = identità genitore, no auth richiesto. Single-use TTL 30g.
$router->get ('/parent-consent/{token}', [\App\Controllers\ParentConsentController::class, 'preview']);
$router->post('/parent-consent/{token}', [\App\Controllers\ParentConsentController::class, 'confirm']);

// Phase 25.C13 — DPO contact form pubblico (no auth, antispam: rate-limit
// 3/min/IP + honeypot field).
$router->get ('/dpo-contact',           [\App\Controllers\DpoContactController::class, 'show']);
$router->post('/dpo-contact',           [\App\Controllers\DpoContactController::class, 'submit'])
       ->middleware('csrf', 'rate:dpo,3');

// Phase 25.P — Notice & Takedown form pubblico (no auth, rate-limited).
// Vedi docs/legal/takedown_procedure.md.
$router->get ('/segnalazione-contenuti', [\App\Controllers\Public\PublicTakedownController::class, 'showForm']);
$router->post('/segnalazione-contenuti', [\App\Controllers\Public\PublicTakedownController::class, 'submit'])
       ->middleware('rate:takedown,3');

// Phase 25.P — Admin Takedown queue (super_admin only).
$router->group(['middleware' => ['auth', 'role:admin', 'super_admin_required', 'log']], function (\App\Core\Router $r) {
    $r->get ('/admin/takedown',                 [\App\Controllers\Admin\AdminTakedownController::class, 'index']);
    $r->get ('/admin/takedown/{id}',            [\App\Controllers\Admin\AdminTakedownController::class, 'show']);
    $r->post('/admin/takedown/{id}/action',     [\App\Controllers\Admin\AdminTakedownController::class, 'action'])
       ->middleware('csrf');

    // Phase 25.Q — ToS acceptance log viewer (super_admin only).
    $r->get ('/admin/tos-log',                  [\App\Controllers\Admin\AdminTosLogController::class, 'index']);

    // ADR-027 — Configurazione sidebar dinamica (super_admin): nome/colore/
    // visibilità/ordine + add/remove sezioni custom (template globale).
    $r->get ('/admin/sidebar-config',           [\App\Controllers\Admin\AdminSidebarConfigController::class, 'page']);
    $r->post('/admin/sidebar-config/save',      [\App\Controllers\Admin\AdminSidebarConfigController::class, 'save'])
       ->middleware('csrf');
    $r->post('/admin/sidebar-config/delete',    [\App\Controllers\Admin\AdminSidebarConfigController::class, 'delete'])
       ->middleware('csrf');
    $r->post('/admin/sidebar-config/reorder',   [\App\Controllers\Admin\AdminSidebarConfigController::class, 'reorder'])
       ->middleware('csrf');

    // Phase 25.Q — Onboarding wizard nuovo istituto + admin di istituto
    // (super_admin only). Provisioning atomico institutes + users(role=admin).
    $r->get ('/admin/institutes',               [\App\Controllers\Admin\AdminInstitutesController::class, 'index']);
    $r->get ('/admin/institutes/new',           [\App\Controllers\Admin\AdminInstitutesController::class, 'newForm']);
    $r->post('/admin/institutes/new',           [\App\Controllers\Admin\AdminInstitutesController::class, 'create'])
       ->middleware('csrf');
    // Aggiorna anagrafiche scuole MIUR (download opendata dati.istruzione.it).
    $r->post('/admin/institutes/miur/update',   [\App\Controllers\Admin\AdminInstitutesController::class, 'miurUpdate'])
       ->middleware('csrf');

    // Phase 25.R.4.1 — GDPR governance (super-admin):
    //   /admin/data-requests       DSR log (riusa tabella dpo_requests)
    //   /admin/data-breach         registro incident Art. 33-34
    //   /admin/subprocessors       CRUD lista responsabili esterni (DPA art. 9)
    //
    // Phase 25.R.22 — hub /admin/gdpr → tab Data Requests (default landing).
    // Sub-pagine mantengono URL canonici per back-compat (link pubblici, bookmark, audit).
    $r->get ('/admin/gdpr', static function () {
        return \App\Core\Response::redirect('/admin/data-requests', 302);
    });

    // Phase 25.R.22 — Authority Export Wizard (form firmato + log automatico)
    $r->get ('/admin/gdpr/authority-export',  [\App\Controllers\Admin\AdminGdprController::class, 'authorityExportPage']);
    $r->post('/admin/gdpr/authority-export',  [\App\Controllers\Admin\AdminGdprController::class, 'authorityExportSubmit'])
       ->middleware('csrf');
    // Phase 25.R.24 — Search contenuti docente per popolare content_ids del wizard
    $r->get ('/api/admin/gdpr/teacher-content-search',
        [\App\Controllers\Admin\AdminGdprController::class, 'teacherContentSearch']);

    // Phase 25.R.25 — Pannello unificato log admin
    $r->get ('/admin/logs',                [\App\Controllers\Admin\AdminLogsController::class, 'page']);
    $r->get ('/admin/logs/api/{table}',    [\App\Controllers\Admin\AdminLogsController::class, 'apiQuery']);

    $r->get ('/admin/data-requests',                 [\App\Controllers\Admin\AdminGdprController::class, 'dataRequestsIndex']);
    $r->get ('/admin/data-requests/{id}',        [\App\Controllers\Admin\AdminGdprController::class, 'dataRequestsShow']);
    $r->post('/admin/data-requests/{id}/action', [\App\Controllers\Admin\AdminGdprController::class, 'dataRequestsAction'])
       ->middleware('csrf');

    $r->get ('/admin/data-breach',                   [\App\Controllers\Admin\AdminGdprController::class, 'dataBreachIndex']);
    $r->get ('/admin/data-breach/new',               [\App\Controllers\Admin\AdminGdprController::class, 'dataBreachNewForm']);
    $r->post('/admin/data-breach/new',               [\App\Controllers\Admin\AdminGdprController::class, 'dataBreachCreate'])
       ->middleware('csrf');
    $r->get ('/admin/data-breach/{id}',          [\App\Controllers\Admin\AdminGdprController::class, 'dataBreachShow']);
    $r->post('/admin/data-breach/{id}/action',   [\App\Controllers\Admin\AdminGdprController::class, 'dataBreachAction'])
       ->middleware('csrf');

    $r->get ('/admin/subprocessors',                 [\App\Controllers\Admin\AdminGdprController::class, 'subprocessorsIndex']);
    $r->get ('/admin/subprocessors/new',             [\App\Controllers\Admin\AdminGdprController::class, 'subprocessorsNewForm']);
    $r->get ('/admin/subprocessors/{id}/edit',   [\App\Controllers\Admin\AdminGdprController::class, 'subprocessorsEditForm']);
    $r->post('/admin/subprocessors/save',            [\App\Controllers\Admin\AdminGdprController::class, 'subprocessorsSave'])
       ->middleware('csrf');
    $r->post('/admin/subprocessors/{id}/delete', [\App\Controllers\Admin\AdminGdprController::class, 'subprocessorsDelete'])
       ->middleware('csrf');

    // Phase 25.R.5.3 — Crypto status + log custodia chiavi + cooperazione autorità.
    $r->get ('/admin/crypto-status',           [\App\Controllers\Admin\AdminCryptoStatusController::class, 'index']);
    $r->get ('/admin/crypto-status/export',    [\App\Controllers\Admin\AdminCryptoStatusController::class, 'export']);
    $r->post('/admin/crypto-status/event',     [\App\Controllers\Admin\AdminCryptoStatusController::class, 'recordEvent'])
       ->middleware('csrf');

    // Phase 25.R follow-up — Backup centralizzato (Hetzner snapshot + B2 + cold HDD).
    $r->get ('/admin/backup',                  [\App\Controllers\Admin\AdminBackupController::class, 'index']);
    $r->post('/admin/backup/cold-completed',   [\App\Controllers\Admin\AdminBackupController::class, 'coldCompleted'])
       ->middleware('csrf');
    $r->post('/admin/backup/b2-verified',      [\App\Controllers\Admin\AdminBackupController::class, 'b2Verified'])
       ->middleware('csrf');

    // Phase 25.R follow-up — Monitoring (Grafana embed via auth_request SSO)
    $r->get ('/admin/monitoring',              [\App\Controllers\Admin\AdminMonitoringController::class, 'index']);

    // Phase S2 F3 (ADR-017) — Switch deployment_mode single ↔ institute.
    // Persistenza atomica in storage/config/deployment.json (no restart php-fpm).
    $r->get ('/admin/system/deployment',          [\App\Controllers\Admin\AdminSystemController::class, 'deploymentPage']);
    $r->post('/admin/system/deployment/switch',   [\App\Controllers\Admin\AdminSystemController::class, 'deploymentSwitch'])
       ->middleware('csrf');
    // ADR-028 Fase 1 — classi ammesse alla registrazione (trasversale).
    $r->post('/admin/system/registration-classes/add',    [\App\Controllers\Admin\AdminSystemController::class, 'registrationClassAdd'])
       ->middleware('csrf');
    $r->post('/admin/system/registration-classes/remove', [\App\Controllers\Admin\AdminSystemController::class, 'registrationClassRemove'])
       ->middleware('csrf');
    // WS3 — modalità acquisizione dati registrazione studenti (full/reduced/anonymous).
    $r->post('/admin/system/registration-mode', [\App\Controllers\Admin\AdminSystemController::class, 'registrationModeSet'])
       ->middleware('csrf');
    // ADR-028 Fase 4 — governance capabilities (profili + assegnazione, institute-only).
    $r->post('/admin/system/capability/profile/save',   [\App\Controllers\Admin\AdminSystemController::class, 'capabilityProfileSave'])
       ->middleware('csrf');
    $r->post('/admin/system/capability/profile/delete', [\App\Controllers\Admin\AdminSystemController::class, 'capabilityProfileDelete'])
       ->middleware('csrf');
    $r->post('/admin/system/capability/assign',         [\App\Controllers\Admin\AdminSystemController::class, 'capabilityAssign'])
       ->middleware('csrf');
});

// Phase 25.R follow-up — Grafana auth_request gate (super_admin only, no redirect).
// Endpoint chiamato da nginx via `auth_request` PRIMA del proxy_pass a Grafana.
// Ritorna 200+X-Grafana-User se super_admin loggato, 401 altrimenti.
// NB: NESSUN middleware auth qui (auth_request gestisce diversamente i redirect).
$router->get('/auth/grafana-gate',         [\App\Controllers\GrafanaGateController::class, 'gate']);

// Phase 25.P — ToS acceptance form (richiede auth, esente da TosAcceptanceMiddleware).
// Solo attivo se TOS_ENFORCE=true in .env.
$router->get ('/tos-acceptance',                [\App\Controllers\TosAcceptanceController::class, 'show'])
       ->middleware('auth');
$router->post('/tos-acceptance',                [\App\Controllers\TosAcceptanceController::class, 'submit'])
       ->middleware('auth', 'csrf');

// Phase 25.E8 — trust pages pubbliche (no auth) per trasparenza GDPR.
$router->get('/security',              [\App\Controllers\TrustPagesController::class, 'security']);
$router->get('/privacy/your-data',     [\App\Controllers\TrustPagesController::class, 'yourData']);
$router->get('/privacy/informativa',   [\App\Controllers\TrustPagesController::class, 'informativa']);
// WS4 — endpoint PUBBLICI (no auth): rate-limit per-IP contro abuso/DoS (oltre al
// WAF). 429 JSON al superamento; inerte se RATE_LIMIT_DISABLED=1 (attivabile in .env).
// WS4 — render pubblico (senza login) di una sezione sidebar marcata publish_public.
$router->get('/public/sidebar/{key}',  [\App\Controllers\PublicSidebarController::class, 'section'])
       ->middleware('rate:pub_sidebar,120');
// WS4 — vista pubblica read-only di UN contenuto (super-admin published, sezione pubblica).
// Riusa la stessa shell+sidebar+stile di /studio (ContentStudyController::publicView).
$router->get('/public/studio/{id}',    [\App\Controllers\ContentStudyController::class, 'publicView'])
       ->middleware('rate:pub_view,120');
// WS4 — endpoint PUBBLICI dedicati (no auth): servono ESCLUSIVAMENTE i contenuti
// visibility=published del super-admin docente nelle sezioni publish_public,
// ignorando del tutto la sessione (publicScopedFilters → nessuna escalation di
// privilegio possibile). NON usano viewerContext/auth.
$router->get('/api/public/study/topics.json',  [\App\Controllers\ContentStudyController::class, 'publicTopicsJson'])
       ->middleware('rate:pub_study,120');
$router->get('/api/public/study/content.json', [\App\Controllers\ContentStudyController::class, 'publicContentJson'])
       ->middleware('rate:pub_study,120');

// Phase 25.Q — documenti legali pubblici (ToS, AUP, Takedown, DPA) per
// trasparenza e coerenza con i link in footer + form registrazione.
$router->get('/legal/tos',                  [\App\Controllers\TrustPagesController::class, 'tos']);
$router->get('/legal/aup',                  [\App\Controllers\TrustPagesController::class, 'aup']);
$router->get('/legal/takedown-procedure',   [\App\Controllers\TrustPagesController::class, 'takedownProcedure']);
$router->get('/legal/dpa',                  [\App\Controllers\TrustPagesController::class, 'dpa']);
$router->get('/accessibility',              [\App\Controllers\TrustPagesController::class, 'accessibility']);

// Phase 25.Q — tenant switch (multi-istituto). Validazione access in
// Auth::setCurrentInstitute() (docente: pivot teacher_institutes,
// admin: admin_institute_id, super-admin: qualunque).
$router->group(['middleware' => ['auth']], function (\App\Core\Router $r) {
    $r->post('/api/tenant/switch',   [\App\Controllers\TenantController::class, 'switch'])
        ->middleware('csrf');
    $r->get ('/api/tenant/current',  [\App\Controllers\TenantController::class, 'current']);
});

// Phase 25.E4.2 — Prometheus metrics endpoint (super_admin OR Bearer token).
$router->get('/metrics',               [\App\Controllers\MetricsController::class, 'show']);
$router->post('/analytics/nav',         [\App\Controllers\AnalyticsController::class, 'navBeacon']);
// Phase Roadmap — Web Vitals RUM beacon (no auth, anonymized, rate-limited).
$router->post('/api/vitals',             [\App\Controllers\AnalyticsController::class, 'webVitals'])->middleware('rate:vitals,120');
// /check_password.php è la "conferma password admin" usata da JS legacy prima
// di operazioni distruttive su /verifiche e /eser. Spostato sotto il gruppo
// admin (csrf+rate) — era pubblico per legacy ma è un gap di sicurezza.

// Public self-signup
$router->get ('/register', [\App\Controllers\RegistrationController::class, 'showForm']);
$router->post('/register', [\App\Controllers\RegistrationController::class, 'submit'])->middleware('csrf');

// Public curriculum read (drives sel-wrapper render on every page)
$router->get('/curriculum', [\App\Controllers\CurriculumController::class, 'index'])
       ->middleware('rate:curriculum,180'); // condiviso auth+guest: soglia alta per-IP

// Phase 13 — institutes (lista pubblica per registrazione)
$router->get('/api/institutes', [\App\Controllers\InstituteController::class, 'index']);

// Phase 14 — MIUR schools autocomplete (server-side only, never expose full JSON)
$router->get('/api/scuole', [\App\Controllers\SchoolsController::class, 'search']);

// Phase 14 — serve oggetti storage via signed URL (HMAC-SHA256, TTL breve).
// La signature stessa è autorizzazione → route pubblica (no middleware auth).
$router->get('/storage/signed', [\App\Controllers\StorageController::class, 'signed']);

// Phase 14 — sidepage topics DB-backed (legacy category listing).
// ACL per category dentro controller (mappe pubblico; lab/eser student+;
// verifiche/bes admin+/teacher/collaborator).
$router->get('/api/sidepage/topics', [\App\Controllers\SidepageController::class, 'topics']);

// Phase 13 — student access ai contenuti del docente: prompt
// username/password (teacher_access_credentials). Endpoint pubblico
// (lo studente non è loggato ma ottiene un grant in sessione).
// Phase 18 — rate-limit su studentLogin per ostacolare credential stuffing
$router->post('/api/access/student-login',  [\App\Controllers\TeacherCredentialController::class, 'studentLogin'])
       ->middleware('csrf', 'rate');
$router->post('/api/access/student-logout', [\App\Controllers\TeacherCredentialController::class, 'studentLogout'])
       ->middleware('csrf');
$router->get ('/api/access/status',         [\App\Controllers\TeacherCredentialController::class, 'studentStatus']);

// Public assets + pages (no auth)
$router->group(['prefix' => ''], function (Router $r) {
    // Phase 25.E15 — privacy policy legacy redirect 301 → moderna.
    $r->get('/cookies_privacy-policy.html', static fn () =>
        \App\Core\Response::redirect('/privacy/informativa', 301));
    // Phase 25.E17 — single-file legacy ancora referenziati da JS attivi
    // (tikzjax in viewer TikZ): redirect 301 al path moderno. Apache poi
    // serve il file direttamente (.htaccess con RewriteCond -f).
    // jquery.sticky.js RIMOSSO 2026-06-03: plugin jQuery dead code (non più
    // caricato da head.php dal phase7.2, .sticky() mai chiamato — rimpiazzato
    // da features/verifica-sticky.js vanilla).
    $r->get('/tikzjax.js', static fn () =>
        \App\Core\Response::redirect('/js/vendor/tikzjax.js', 301));
    /* Phase 25.E17 — route morte rimosse (file inesistenti, nessuna referenza
       attiva nel frontend post Phase 9z reorg):
         /UpBar_Es.html, /UpBar_Es_loader.php, /functions.js, /functions-mod.js,
         /script.js, /script_sel-mod.js, /index.js, /copilot-ai.{js,css},
         /copilot-ai-init.js, /head-content.html
       Se mai una di queste URL torna in vita per qualche bookmark esterno,
       Apache risponderà con 404 nativo (passa al front controller che fa
       fallback alla home). */
});

// Phase 18 — /mappe/* legacy → 410 + smart redirect a /studio/mappa/...
$router->get('/mappe/{path*}', $legacyGoneHandler)->middleware('legacy_gone');
/* Phase 25.E17 — Apache native serving per static asset.
   .htaccess è stato aggiornato con RewriteCond %{REQUEST_FILENAME} -f per
   le estensioni statiche (.js/.css/.svg/.png/.json/.html/...). I path
   /js/, /css/, /img/, /wasm/, /tikzjax-develop/, /node_modules/,
   /scriptGoogle_sync/, /build/ vengono serviti direttamente da Apache,
   skippando il front controller PHP. Niente più catch-all qui. */

// ───────────── STUDENT + ─────────────
$router->group(['middleware' => ['auth', 'role:student', 'log']], function (Router $r) use ($legacyGoneHandler) {
    // Phase 18 — Hard cutover: /eser/, /didattica/, /lab/ filesystem
    // routes sostituiti da /studio/{type}/... DB-backed. LegacyGoneMiddleware
    // tenta redirect 302 se URL parsabile + content esistente in DB,
    // altrimenti 410 Gone.
    $r->any('/eser/{path*}',      $legacyGoneHandler)->middleware('legacy_gone');
    $r->any('/didattica/{path*}', $legacyGoneHandler)->middleware('legacy_gone');
    $r->any('/lab/{path*}',       $legacyGoneHandler)->middleware('legacy_gone');

    // Template lista sidebar: serve tutti gli utenti autenticati
    // (Mappe/Lab/Eser sono student+, solo Verif richiede admin).
    // Phase 25.E17 — migrato da LegacyController a AdminPartialController.
    $r->any('/modello_pag_listSidebar.php', [\App\Controllers\AdminPartialController::class, 'show']);

    // G22.S15 — TikZ → SVG render server-side (VPS pdflatex+dvisvgm).
    // Aperto a student+ perche' studenti devono poter vedere TikZ in
    // pagine esercizi e verifiche; docenti devono poter compilare nei
    // loro contenuti via editor. Authorization fine-grained gestita dal
    // controller (admin-only per scope=public WRITE; teacher-owner per
    // scope=teacher). Vedi ADR-013.
    $r->get('/tikz/render', [\App\Controllers\TikzRenderController::class, 'lookup']);
    // G27 PERF — bucket dedicato 'tikz' con limite alto (200/min): il rendering
    // di una pagina ricca di grafici è bursty ma legittimo + cache-ato. La vera
    // protezione è il semaforo del microservizio VPS (4 concorrenti + 503), che
    // il client gestisce con backoff/retry (anche su 429). Senza questo, una
    // verifica con molti TikZ sforava il limite teacher 60/min → TikZ vuoti.
    $r->group(['middleware' => ['csrf', 'rate:tikz,200']], function (Router $rr) {
        $rr->post('/tikz/render', [\App\Controllers\TikzRenderController::class, 'render']);
    });

    // G22.S15 — Format LaTeX via VPS latexindent.pl. Ctrl+S in editor.
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/tex/format', [\App\Controllers\TexFormatController::class, 'format']);
    });

    // G22.S15.bis — Override personali docente sui template TikZ/LaTeX (legacy).
    // GET effective = admin defaults merged con override del docente loggato.
    // Save/reset gestiscono CRUD per (groupKey, label) con scope per utente.
    $r->get('/tikz/effective-templates', [\App\Controllers\TeacherTemplateController::class, 'effective']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/tikz/teacher-templates/save',  [\App\Controllers\TeacherTemplateController::class, 'save']);
        $rr->post('/tikz/teacher-templates/reset', [\App\Controllers\TeacherTemplateController::class, 'reset']);
    });

    // Phase 25 — Modello "Scorciatoie LaTeX da tastiera" forkabile.
    // GET effective = riferimento super-admin merged con override del docente.
    // Admin (super) edita il riferimento; il docente forka per-scorciatoia.
    $r->get('/api/latex-shortcuts/effective', [\App\Controllers\LatexShortcutsController::class, 'effective']);
    $r->get('/api/admin/latex-shortcuts',     [\App\Controllers\LatexShortcutsController::class, 'adminList']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/api/latex-shortcuts/save',      [\App\Controllers\LatexShortcutsController::class, 'save']);
        $rr->post('/api/latex-shortcuts/reset',     [\App\Controllers\LatexShortcutsController::class, 'reset']);
        $rr->post('/api/latex-shortcuts/reset-all', [\App\Controllers\LatexShortcutsController::class, 'resetAll']);
        $rr->post('/api/admin/latex-shortcuts',     [\App\Controllers\LatexShortcutsController::class, 'adminSave']);
    });

    // G22.S15.bis Fase 4 — Catalogo personale GeoGebra del docente.
    // Il docente salva i suoi grafici (file .ggb + svg cached) per riusarli.
    $r->get('/geogebra/catalog',          [\App\Controllers\GeoGebraCatalogController::class, 'list']);
    $r->get('/geogebra/catalog/{id}',     [\App\Controllers\GeoGebraCatalogController::class, 'get']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/geogebra/catalog/save',   [\App\Controllers\GeoGebraCatalogController::class, 'save']);
        $rr->post('/geogebra/catalog/delete', [\App\Controllers\GeoGebraCatalogController::class, 'delete']);
    });

    // G22.S15.bis Fase 3 — Workspace personale del docente (sostituisce override layer).
    // Ogni docente ha la sua copia COMPLETA dei gruppi/items, modificabile a piacere.
    // Migration: al primo GET /tikz/workspace, se esiste tikz-overrides.json viene
    // applicato sopra i defaults admin per costruire il workspace iniziale.
    $r->get('/tikz/workspace',     [\App\Controllers\TeacherWorkspaceController::class, 'getWorkspace']);
    $r->get('/tikz/admin-library', [\App\Controllers\TeacherWorkspaceController::class, 'getAdminLibrary']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/tikz/workspace/element/save',   [\App\Controllers\TeacherWorkspaceController::class, 'saveElement']);
        $rr->post('/tikz/workspace/element/delete', [\App\Controllers\TeacherWorkspaceController::class, 'deleteElement']);
        $rr->post('/tikz/workspace/group/rename',   [\App\Controllers\TeacherWorkspaceController::class, 'renameGroup']);
        $rr->post('/tikz/workspace/group/delete',   [\App\Controllers\TeacherWorkspaceController::class, 'deleteGroup']);
        $rr->post('/tikz/workspace/group/reorder',  [\App\Controllers\TeacherWorkspaceController::class, 'reorderGroups']);
        $rr->post('/tikz/workspace/reset-all',      [\App\Controllers\TeacherWorkspaceController::class, 'resetAll']);
        $rr->post('/tikz/workspace/import',         [\App\Controllers\TeacherWorkspaceController::class, 'importFromAdmin']);
    });

    // Phase 13/15 — Studio multi-tipo (mappe/esercizi/lab/verifiche).
    // IMPORTANTE: registrate PRIMA delle rotte M11 legacy perché i pattern
    // 5-seg coincidono nel conteggio parametri: /studio/{type}/{ind}/{cls}/{subj}
    // (4 params) vs /studio/{ind}/{cls}/{materia}/{topic} (4 params). Il router
    // matcha la prima registrata → Phase 13 vince. Se il primo segmento non è
    // un type valido (mappa|esercizio|lab|verifica), ContentStudyController
    // fa forward a ExerciseStudyController per retro-compat.
    $r->get('/studio/{type}/{ind}/{cls}/{subj}',
            [\App\Controllers\ContentStudyController::class, 'topicsPage']);
    $r->get('/studio/{type}/{ind}/{cls}/{subj}/{topic}',
            [\App\Controllers\ContentStudyController::class, 'topicPage']);

    // M11 — Studio DB-backed legacy (backed by `exercises` table).
    // Mantenuto per URL /studio/{ind}/{cls}/{materia} (3-seg, non-conflict).
    // La variante 5-seg è gestita dal forward in ContentStudyController.
    $r->get('/studio/{indirizzo}/{classe}/{materia}',
            [\App\Controllers\ExerciseStudyController::class, 'topicsPage']);
    $r->get('/studio/{indirizzo}/{classe}/{materia}/{topic}',
            [\App\Controllers\ExerciseStudyController::class, 'topicPage']);
    $r->get('/api/studio/topics.json',
            [\App\Controllers\ExerciseStudyController::class, 'topicsJson']);
    $r->get('/api/studio/exercises.json',
            [\App\Controllers\ExerciseStudyController::class, 'exercisesJson']);
    $r->get('/api/studio/exercise/{id}.json',
            [\App\Controllers\ExerciseStudyController::class, 'exerciseJson']);
    // ADR-027 Step 4 — config sidebar dinamica (idratazione registry client).
    // Ruolo+istituto scoped; solo sezioni visibili al chiamante.
    $r->get('/api/sidebar/config',
            [\App\Controllers\SidebarConfigController::class, 'config']);

    $r->get('/api/study/topics.json',
            [\App\Controllers\ContentStudyController::class, 'topicsJson']);
    $r->get('/api/study/content.json',
            [\App\Controllers\ContentStudyController::class, 'contentJson']);
    $r->get('/api/study/content/{id}.json',
            [\App\Controllers\ContentStudyController::class, 'contentSingleJson']);
    // Phase 25.Q.15 — lista verifiche shared con pool (studente vede
    // verifiche dei docenti del proprio istituto filtrate per sezione).
    // Auth required (no role:teacher) — solo metadati, no binari.
    $r->get('/api/study/verifica/list',
            [\App\Controllers\VerificaController::class, 'listForStudent']);
    // Phase 25.Q.16 — header_page del docente di riferimento per studente
    // (legge il template "Il contenuto di questa pagina presenta..." del
    // docente che condivide contenuti con la sua sezione). Auth required,
    // validato vs teacher_institutes per evitare leak cross-istituto.
    $r->get('/api/study/header-page.json',
            [\App\Controllers\StudyHeaderController::class, 'headerPageStudentJson']);
    // Phase 15 — HTML delle verifiche correlate a un esercizio (match subject+title).
    // Usato dall'auto-attivazione di verifica-mode (Phase 21) su /studio/esercizio/...
    // per iniettare #type_verAll.
    $r->get('/api/study/related-verifiche.html',
            [\App\Controllers\ContentStudyController::class, 'relatedVerificaHtml']);
    // Phase 16 / G22.S15.bis — registry fonti del teacher corrente (canonico).
    // Formato registry array: `{sources:[{key,book,volume,authors},...]}`.
    // GET letto da fonti.php per editor + da rebuildBadgeForOrigin (fallback).
    // PUT scritto dall'editor /area-docente/fonti.
    $r->get('/api/teacher/sources.registry.json',
            [\App\Controllers\StudySourcesController::class, 'sourcesRegistryJson']);
    $r->put('/api/teacher/sources.registry.json',
            [\App\Controllers\StudySourcesController::class, 'sourcesRegistrySave']);
    // G27.badge.style — preferenza stile badge teacher (preset+overrides).
    // Risolto via cascade preset admin (istituto → _default) + override
    // docente; emesso in fonti_SOL.tex come \fmsetfonte+\fmsetbadge.
    $r->get('/api/teacher/badge-style',
            [\App\Controllers\BadgeStyleController::class, 'teacherGet']);
    $r->put('/api/teacher/badge-style',
            [\App\Controllers\BadgeStyleController::class, 'teacherPut']);
    // G27.badge.style — gestione preset admin (lista/get/put/delete).
    // I preset risiedono in storage/templates/verifiche/{scope}/badge_styles/{name}.json
    // e sono cascade _default → istituto. Richiede Auth::hasAccess('admin').
    $r->get('/api/admin/badge-style-presets',
            [\App\Controllers\BadgeStyleController::class, 'adminList']);
    $r->get('/api/admin/badge-style-presets/{name}',
            [\App\Controllers\BadgeStyleController::class, 'adminGet']);
    $r->put('/api/admin/badge-style-presets/{name}',
            [\App\Controllers\BadgeStyleController::class, 'adminPut']);
    $r->delete('/api/admin/badge-style-presets/{name}',
            [\App\Controllers\BadgeStyleController::class, 'adminDelete']);
    // Phase 16 / G22.S15.bis — fonti personali del docente autenticato in
    // formato dict legacy (per editor inline + populate `<select.origin>`).
    // GET trasforma il registry runtime; PUT scrive sul registry canonico
    // (il file `sources.json` legacy NON è più scritto, mantenuto solo come
    // fallback read-only per docenti mai migrati). NON condivise tra docenti:
    // ognuno vede/modifica solo il proprio file (path da Auth::user()).
    $r->get('/api/teacher/sources.json',
            [\App\Controllers\StudySourcesController::class, 'sourcesCommonJson']);
    $r->put('/api/teacher/sources.json',
            [\App\Controllers\StudySourcesController::class, 'sourcesSave']);
    // Lista code origine derivata da sources.json del docente (sostituisce il
    // file statico /origins/origins.json, che non era per-teacher).
    $r->get('/api/teacher/origins.json',
            [\App\Controllers\StudySourcesController::class, 'originsJson']);
    // Phase 20 — preferenze per-page dei filtri origin (ex file globale
    // /origins/checked_origins.json). Per-teacher, storage-backed.
    $r->get('/api/teacher/checked-origins.json',
            [\App\Controllers\StudySourcesController::class, 'checkedOriginsJson']);
    $r->put('/api/teacher/checked-origins.json',
            [\App\Controllers\StudySourcesController::class, 'checkedOriginsSave']);
    // Phase 16 — header_page personalizzabile dal docente (html + flag
    // auto_citations per abilitare/disabilitare l'aggregazione automatica
    // delle fonti dagli esercizi presenti in pagina).
    $r->get('/api/teacher/header-page.json',
            [\App\Controllers\StudyHeaderController::class, 'headerPageJson']);
    $r->put('/api/teacher/header-page.json',
            [\App\Controllers\StudyHeaderController::class, 'headerPageSave']);
    // Alias retro-compat con il path vecchio (il controller ora serve il file
    // personale del teacher, non più il SOURCES_COMMON globale).
    $r->get('/api/sources/common',
            [\App\Controllers\StudySourcesController::class, 'sourcesCommonJson']);

    // Phase 19 — /api/probe endpoint no-op per test CSRF/rate (sostituisce
    // il legacy /links/check-variation). /files/list-php + /links/*
    // rimossi: sidebar e content CRUD 100% DB via /api/teacher/* + /api/study/*.
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/api/probe', [\App\Controllers\CsrfProbeController::class, 'probe']);
    });
});

// ───────────── TEACHER + ─────────────
$router->group(['middleware' => ['auth', 'role:teacher', 'log']], function (Router $r) {
    // G22.S25 — Elementi_Riservati template legacy (verifica builder UI):
    // serve a TUTTI i teacher (precedente: solo admin) per popolare
    // .selector-eser nel topbar quando si apre /studio/esercizio/...
    // Il template è statico read-only; nessun rischio di leak admin.
    $r->any('/Elementi_Riservati.html', [\App\Controllers\AdminPartialController::class, 'show']);
    // Merge namespace pagine docente sotto /area-docente/* (vedi blocco più sotto).
    // I vecchi /teacher/* restano come redirect 302 per non rompere i bookmark.
    $r->get('/teacher',           fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/dashboard']));
    $r->get('/teacher/dashboard', fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/dashboard']));
    // G22.S15.bis Fase 5+ — RIMOSSA /admin/curriculum view (refuso legacy).
    // Curriculum integrato in /area-docente/profilo come sezione dedicata,
    // scope per istituto attivo del docente.
    // G22.S20 v2.C2 — redirect compat per bookmark legacy admin dashboard.
    $r->get('/admin/curriculum', function () {
        return new \App\Core\Response('', 302, ['Location' => '/area-docente/profilo#fm-curr-tabs']);
    });
    $r->get('/teacher/resources', fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/resources']));
    // Phase 20 — editor modelli esercizi (VF/RM/Collect) per-docente.
    // Contenuti personalizzabili (testi, opzioni, items preset); struttura
    // e layout restano invariati (gestiti da ContractRenderer). PUT non è
    // sotto csrf middleware (stesso pattern di sources.json / header-page.json).
    // G20.1 — /teacher/templates redirect a /area-docente/templates?tab=esercizi
    $r->get('/teacher/templates', function () {
        return new \App\Core\Response('', 302, ['Location' => '/area-docente/templates?tab=esercizi']);
    });
    $r->get('/api/teacher/templates.json',
            [\App\Controllers\ContentTemplateController::class, 'templatesJson']);

    // ───────────── PDF-Import (estrazione esercizi da PDF via LLM) ─────────────
    // Tool teacher-only su pagina dedicata. Reimplementazione PHP-nativa del
    // tool Python pdf-scraping-tools (fismapant), hardenata (LLM-PY-001).
    // GET read-only fuori CSRF; le mutazioni nel gruppo csrf+rate qui sotto.
    // Pagine PDF-Import migrate a /area-docente/pdf-import[/models] (redirect compat).
    $r->get('/teacher/pdf-import',
            fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/pdf-import']));
    $r->get('/teacher/pdf-import/models',
            fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/pdf-import/models']));
    $r->get('/api/teacher/pdf-import/sessions',
            [\App\Controllers\Teacher\PdfImportController::class, 'listSessions']);
    $r->get('/api/teacher/pdf-import/session/{id}',
            [\App\Controllers\Teacher\PdfImportController::class, 'status']);
    $r->get('/api/teacher/pdf-import/session/{id}/page/{n}',
            [\App\Controllers\Teacher\PdfImportController::class, 'pageImage']);
    $r->get('/api/teacher/pdf-import/session/{id}/preview',
            [\App\Controllers\Teacher\PdfImportController::class, 'previewRow']);
    // Gestione chiavi provider LLM (admin only, controllato nel controller).
    $r->get('/api/teacher/pdf-import/provider-keys',
            [\App\Controllers\Teacher\PdfImportController::class, 'providerKeysStatus']);
    $r->get('/api/teacher/pdf-import/provider-models',
            [\App\Controllers\Teacher\PdfImportController::class, 'providerModels']);
    $r->get('/api/teacher/pdf-import/provider-operations',
            [\App\Controllers\Teacher\PdfImportController::class, 'providerOperations']);

    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        // Audit 25.R.31 — PUT mutante: era fuori dal gruppo CSRF (solo barriera
        // Content-Type json). Spostata dentro csrf+rate (CsrfMiddleware copre PUT).
        $rr->put('/api/teacher/templates.json',
                 [\App\Controllers\ContentTemplateController::class, 'templatesSave']);
        $rr->post('/teacher/print', [\App\Controllers\TeacherPrintController::class, 'generate']);

        // Phase G8 — verifica_documents (TEX/PDF cifrato envelope ADR-006).
        // SalvaTEX flow: client posta Selection → server build TEX (TexBuilder)
        // → save cifrato (VerificaDocumentService). PDF upload + view in G8.8.
        $rr->post('/api/verifica/save-tex',          [\App\Controllers\VerificaController::class, 'saveTex']);
        $rr->post('/api/verifica/save-tex-batch',    [\App\Controllers\VerificaController::class, 'saveTexBatch']);
        $rr->post('/api/verifica/{id}/pdf',          [\App\Controllers\VerificaController::class, 'uploadPdf']);
        // G21 — server-side compile: legge .tex già salvato, invia a tex-compile-vps,
        // riceve PDF, lo salva via attachPdf. Vedi app/Config/tex_compile.php
        // G21.1: query `?with_artifacts=1` per preview modal con SyncTeX.
        // G22.S15.bis Fase 5+ — compile/synctex split in VerificaCompileController.
        $rr->post('/api/verifica/{id}/compile',      [\App\Controllers\VerificaCompileController::class, 'compilePdf']);
        // G22.S5 — compile async (enqueue + worker cron):
        $rr->post('/api/verifica/{id}/compile-async', [\App\Controllers\VerificaCompileController::class, 'compileAsync']);
        $rr->get('/api/verifica/jobs/{jobId}',       [\App\Controllers\VerificaCompileController::class, 'getJob']);
        // G21.1: aggiornamento SOLO TEX (senza ricompila), usato da preview modal "Salva".
        $rr->post('/api/verifica/{id}/tex',          [\App\Controllers\VerificaController::class, 'updateTex']);
        // G22.S15.bis Fase 4 — Attach SVG GeoGebra come PDF nel bundle
        $rr->post('/api/verifica/{id}/geogebra-attach', [\App\Controllers\VerificaController::class, 'geogebraAttach']);
        // G22.S10 — multi-file manifest GET/POST per preview modal con file-tree.
        $rr->get('/api/verifica/{id}/tex-files',     [\App\Controllers\VerificaController::class, 'getTexFiles']);
        $rr->post('/api/verifica/{id}/tex-files',    [\App\Controllers\VerificaController::class, 'updateTexFiles']);
        // G21.2: reverse SyncTeX via binario CLI nativo (`synctex edit`) sul VPS,
        // usato da preview modal Ctrl+click PDF → riga TeX (precisione VSCode-grade).
        $rr->post('/api/verifica/{id}/synctex/edit', [\App\Controllers\VerificaCompileController::class, 'synctexEdit']);
        $rr->post('/api/verifica/{id}/delete',       [\App\Controllers\VerificaController::class, 'delete']);
        // G22.S23 — Toggle shared_with_pool su verifica_documents
        $rr->post('/api/verifica/{id}/share-pool',   [\App\Controllers\VerificaController::class, 'sharePool']);
        // Ad-hoc TeX → PDF compile (no doc id) per fm-tikz-modal preview.
        // Unifica la pipeline preview con quella della verifica (PDF.js render).
        $rr->post('/api/tex/compile-adhoc-pdf',      [\App\Controllers\TexAdhocCompileController::class, 'compileTikzPdf']);

        // PDF-Import — mutazioni (upload, edit, soluzioni, insert).
        // Bucket rate dedicati: `pdf_import` (generico) e `pdf_import_llm` (LLM).
        $rr->post('/api/teacher/pdf-import/session',
                  [\App\Controllers\Teacher\PdfImportController::class, 'createSession'])
           ->middleware('rate:pdf_import_llm,12');
        $rr->post('/api/teacher/pdf-import/session/{id}/cell',
                  [\App\Controllers\Teacher\PdfImportController::class, 'editCell'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/session/{id}/bulk',
                  [\App\Controllers\Teacher\PdfImportController::class, 'bulkEdit'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/session/{id}/solutions',
                  [\App\Controllers\Teacher\PdfImportController::class, 'generateSolutions'])
           ->middleware('rate:pdf_import_llm,12');
        $rr->post('/api/teacher/pdf-import/session/{id}/topics',
                  [\App\Controllers\Teacher\PdfImportController::class, 'generateTopics'])
           ->middleware('rate:pdf_import_llm,12');
        $rr->post('/api/teacher/pdf-import/session/{id}/difficulty',
                  [\App\Controllers\Teacher\PdfImportController::class, 'refineDifficulty'])
           ->middleware('rate:pdf_import_llm,12');
        $rr->post('/api/teacher/pdf-import/session/{id}/stop',
                  [\App\Controllers\Teacher\PdfImportController::class, 'stopSession'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/session/{id}/translate',
                  [\App\Controllers\Teacher\PdfImportController::class, 'translate'])
           ->middleware('rate:pdf_import_llm,30');
        $rr->post('/api/teacher/pdf-import/session/{id}/insert',
                  [\App\Controllers\Teacher\PdfImportController::class, 'insert'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/provider-keys',
                  [\App\Controllers\Teacher\PdfImportController::class, 'saveProviderKey'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/provider-operations',
                  [\App\Controllers\Teacher\PdfImportController::class, 'saveProviderOperation'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/provider-prompt',
                  [\App\Controllers\Teacher\PdfImportController::class, 'saveProviderPrompt'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/provider-cache',
                  [\App\Controllers\Teacher\PdfImportController::class, 'toggleCache'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/setting',
                  [\App\Controllers\Teacher\PdfImportController::class, 'toggleSetting'])
           ->middleware('rate:pdf_import,30');
        $rr->post('/api/teacher/pdf-import/provider-keys/clear',
                  [\App\Controllers\Teacher\PdfImportController::class, 'clearProviderKey'])
           ->middleware('rate:pdf_import,30');

        // G22.S4.B.4 — rotte /api/verifica/templates/* RIMOSSE.
        // Sostituite dal sistema TemplateFileStore (cascade institute → _default
        // su filesystem in storage/templates/verifiche/) integrato direttamente
        // in TexBuilder::build() / buildFlat(). L'editor templates UI (iframe
        // /area-docente/templates) gestisce ora i template esercizi (Collect/RM/VF)
        // tramite TeacherProfileController; i template verifica si modificano
        // direttamente nei file storage/templates/verifiche/_default/...

        // G19.4 — Print Info / Scelte spostati dal gruppo role:admin al
        // gruppo role:teacher. Causa: l'admin group registrava prima il
        // wildcard `/verifiche/{path*}` legacy_gone (410) → catturava
        // `/verifiche/print-info` e `/verifiche/scelte` rendendoli
        // inaccessibili. Inoltre questi endpoint sono dati teacher-scoped
        // (`Auth::user()['username']` → key `print_info.json` /
        // `verifiche/scelte/`) → la loro home naturale è il gruppo teacher.
        // Modern (G9, response shape `{ok, data, error}`):
        $rr->post('/api/teacher/print-info',         [\App\Controllers\PrintInfoController::class, 'save']);
        $rr->post('/api/teacher/print-info/delete',  [\App\Controllers\PrintInfoController::class, 'delete']);
        // Legacy (response shape `{success, data, message}`) — finché
        // non si refactorizza il client legacy che la usa.
        $rr->post('/verifiche/print-info',  [\App\Controllers\VerificheController::class, 'managePrintInfo']);
        $rr->post('/verifiche/scelte',      [\App\Controllers\VerificheController::class, 'saveLoadScelte']);
    });
    // Read-only endpoints — fuori CSRF (idempotent).
    $r->get('/api/verifica/list',         [\App\Controllers\VerificaController::class, 'listForTeacher']);
    $r->get('/api/verifica/{id}/tex',     [\App\Controllers\VerificaController::class, 'downloadTex']);
    $r->get('/api/verifica/{id}/pdf',     [\App\Controllers\VerificaController::class, 'viewPdf']);
    $r->get('/api/verifica/{id}/zip',     [\App\Controllers\VerificaController::class, 'zipExport']);
    // G16 — batch zip download (tutte le 8 varianti A/B × {SOL,NOR,DSA,DIS})
    $r->get('/api/verifica/batch/{batchId}/zip', [\App\Controllers\VerificaBatchController::class, 'batchZip']);
    // G19.36 — batch files JSON (per FS Access API client-side write,
    // alternativa a ZIP che richiede estrazione manuale dell'utente).
    $r->get('/api/verifica/batch/{batchId}/files', [\App\Controllers\VerificaBatchController::class, 'batchFiles']);
    // G19.47 — local bundle (mappe + verifiche) per "Sync locale" button
    $r->get('/api/teacher/sync-local-bundle', [\App\Controllers\VerificaSyncController::class, 'localBundle']);
    // G22.S20 — manifest signed (HMAC Recovery Key) per export bundle
    $r->get('/api/teacher/sync-bundle/manifest', [\App\Controllers\VerificaSyncController::class, 'manifestSigned']);
    // G22.S20 — Recovery Key status (idempotent, no CSRF)
    $r->get('/api/teacher/recovery-key/status',  [\App\Controllers\TeacherRecoveryController::class, 'status']);
    // G22.S4.B.4 — rotte GET /api/verifica/templates/* RIMOSSE
    // (controller eliminato; templates ora gestiti via TemplateFileStore
    // filesystem con cascade institute -> _default).

    // Phase G9 — read-only print_info (idempotent, fuori CSRF)
    $r->get('/api/teacher/print-info',         [\App\Controllers\PrintInfoController::class, 'show']);
    $r->get('/api/teacher/print-info/list',    [\App\Controllers\PrintInfoController::class, 'index']);

    // M6+: ricerca DB-backed esercizi (teacher+admin)
    $r->get('/exercises',             [\App\Controllers\ExerciseController::class, 'searchPage']);
    $r->get('/exercises/search.json', [\App\Controllers\ExerciseController::class, 'searchJson']);

    // Phase G1.a — Google Drive OAuth flow + status (mappe/risdoc sync).
    // - connect/callback: 302 redirect; state nonce in sessione previene CSRF.
    // - disconnect/status: dietro CSRF middleware (mutator + JSON read).
    // Vedi ADR-009-drive-integration.md per il flow completo.
    $r->get('/teacher/drive/connect',           [\App\Controllers\DriveController::class, 'connect']);
    $r->get('/teacher/drive/connect-migration', [\App\Controllers\DriveController::class, 'connectMigration']);
    $r->get('/teacher/drive/callback',          [\App\Controllers\DriveController::class, 'callback']);
    $r->get('/teacher/drive/status.json',       [\App\Controllers\DriveController::class, 'status']);
    // G22.S21 Fase C — Pool browse (catalog ownership refactor):
    // lista contenuti condivisi da colleghi dello stesso istituto.
    $r->get('/api/teacher/pool/materials',
        [\App\Controllers\PoolController::class, 'materials']);
    // G22.S24 — Lista MIEI contenuti condivisi (per controllo + revoca).
    $r->get('/api/teacher/pool/my-shares',
        [\App\Controllers\PoolController::class, 'myShares']);
    // G22.S25 — Granularità share: grants per-content (institute/teacher/group)
    $r->get('/api/teacher/share/grants/{source}/{id}',
        [\App\Controllers\ShareGrantsController::class, 'listGrants']);
    $r->get('/api/teacher/share/groups',
        [\App\Controllers\ShareGrantsController::class, 'listGroups']);
    $r->get('/api/teacher/share/colleagues',
        [\App\Controllers\ShareGrantsController::class, 'listColleagues']);
    $r->get('/api/teacher/share/groups/{id}/members',
        [\App\Controllers\ShareGrantsController::class, 'listMembers']);
    // G22.S15.bis Fase 5 — GitHub sync per docente (PAT-based).
    $r->get('/api/teacher/github/status', [\App\Controllers\TeacherGitHubController::class, 'status']);
    // G22.S15.bis Fase 5 — Drawio libraries: list + read (no CSRF needed)
    $r->get('/api/teacher/drawio/libraries',
        [\App\Controllers\TeacherDrawioLibraryController::class, 'list']);
    $r->get('/api/teacher/drawio/libraries/read/{name}',
        [\App\Controllers\TeacherDrawioLibraryController::class, 'read']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        // G22.S20 — Recovery Key: generate (one-time) + revoke
        $rr->post('/api/teacher/recovery-key/generate',
            [\App\Controllers\TeacherRecoveryController::class, 'generate']);
        $rr->post('/api/teacher/recovery-key/revoke',
            [\App\Controllers\TeacherRecoveryController::class, 'revoke']);
        // G22.S20 — Import bundle (preview + apply) rate-limited 1/15min
        $rr->post('/api/teacher/import-bundle/preview',
            [\App\Controllers\ImportBundleController::class, 'preview'])
           ->middleware('rate:import,4');
        $rr->post('/api/teacher/import-bundle/apply',
            [\App\Controllers\ImportBundleController::class, 'apply'])
           ->middleware('rate:import,4');
        // G22.S21 Fase C — Pool recover: clona contenuto di un collega nel
        // proprio account (re-cifra blob con la mia KEK).
        $rr->post('/api/teacher/pool/recover/{id}',
            [\App\Controllers\PoolController::class, 'recover'])
           ->middleware('rate:pool_recover,30');
        // G22.S24 — Bulk unshare: rimuove condivisione su lista items.
        $rr->post('/api/teacher/pool/unshare',
            [\App\Controllers\PoolController::class, 'unshare']);
        // G22.S25 — Granular share grants + groups CRUD
        $rr->post('/api/teacher/share/grants/{source}/{id}',
            [\App\Controllers\ShareGrantsController::class, 'setGrants']);
        $rr->post('/api/teacher/share/groups',
            [\App\Controllers\ShareGrantsController::class, 'createGroup']);
        $rr->post('/api/teacher/share/groups/{id}/members',
            [\App\Controllers\ShareGrantsController::class, 'setMembers']);
        $rr->post('/api/teacher/share/groups/{id}/delete',
            [\App\Controllers\ShareGrantsController::class, 'deleteGroup']);
        $rr->post('/api/teacher/github/configure',  [\App\Controllers\TeacherGitHubController::class, 'configure']);
        $rr->post('/api/teacher/github/disconnect', [\App\Controllers\TeacherGitHubController::class, 'disconnect']);
        $rr->post('/api/teacher/github/sync-test',  [\App\Controllers\TeacherGitHubController::class, 'syncTest']);
        $rr->post('/api/teacher/github/sync-all',   [\App\Controllers\TeacherGitHubController::class, 'syncAll']);
        $rr->post('/api/teacher/github/push-file',  [\App\Controllers\TeacherGitHubController::class, 'pushFile']);
        // G22.S15.bis Fase 5 — cleanup orphan rows (DB ↔ blob mismatch)
        $rr->post('/api/teacher/sync/cleanup-orphans',
            [\App\Controllers\TeacherSyncCleanupController::class, 'cleanupOrphans']);
        // G22.S15.bis Fase 5 — librerie drawio docente
        $rr->post('/api/teacher/drawio/libraries/upload',
            [\App\Controllers\TeacherDrawioLibraryController::class, 'upload']);
        $rr->post('/api/teacher/drawio/libraries/delete',
            [\App\Controllers\TeacherDrawioLibraryController::class, 'delete']);
        // G22.S15.bis Fase 5 — save XML libreria via JSON (chiamato dal
        // plugin drawio dopo edit interno della libreria).
        $rr->post('/api/teacher/drawio/libraries/save-content',
            [\App\Controllers\TeacherDrawioLibraryController::class, 'saveContent']);
        $rr->post('/teacher/drive/disconnect', [\App\Controllers\DriveController::class, 'disconnect']);

        // Phase G3.b — POST /api/maps: crea mappa con storage locale cifrato.
        // Forme: multipart/form-data (mode=upload + file) oppure
        // application/x-www-form-urlencoded (mode=drawio_native + xml).
        // Rate-limit identico al teacher/content store (60/min/teacher).
        $rr->post('/api/maps', [\App\Controllers\MapsController::class, 'create'])
           ->middleware('rate:content,60');

        // Phase G4 — POST /api/maps/{id}/update: save da editor drawio
        // (modifica originale, owner only). Optimistic concurrency con
        // map_version client. Mismatch → 409.
        $rr->post('/api/maps/{id}/update', [\App\Controllers\MapsController::class, 'update'])
           ->middleware('rate:content,60');

        // Phase G5 — sync su Drive del docente.
        $rr->post('/api/maps/{id}/sync', [\App\Controllers\MapsController::class, 'sync'])
           ->middleware('rate:content,60');
        $rr->post('/api/maps/sync-all', [\App\Controllers\MapsController::class, 'syncAll'])
           ->middleware('rate:content,30');
        // G19.47 — verifiche sync (mirror /api/maps/sync-all)
        $rr->post('/api/verifica/sync-all', [\App\Controllers\VerificaSyncController::class, 'syncAll'])
           ->middleware('rate:content,30');
    });

    // Phase G4 — GET /api/maps/{id}/signed-url?mode=view|copy
    // Mint signed URL DOPO permission check (auth+teacher). Il client (modal
    // edit/view) usa l'URL per fetchare il blob decifrato.
    $r->get('/api/maps/{id}/signed-url',
            [\App\Controllers\MapsController::class, 'signedUrl']);

    // Teacher workspace endpoints (M7)
    // G22.S15.bis Fase 5+ — RIMOSSI tutti i 4 endpoint M11
    // (verifiche/download/clone + cloneExercise). Sostituiti da
    // /api/teacher/content + /api/verifica/{id}/tex.

    // G22.S15.bis Fase 5+ — RIMOSSI endpoint M11 /api/verifiche/* (dead path).
    // Sostituiti da /api/verifica/save-tex (modern, vedi VerificaController).
    // VerificaBuilderController + js/modules/features/verifica-builder.js
    // eliminati. Tabelle teacher_verifiche/teacher_exercises in drop via
    // migration successiva (vedi 038_drop_m11_legacy_tables.sql).

    // ─── Phase 13: institutes + access credentials per docente ───
    $r->get('/api/teacher/institutes',
            [\App\Controllers\InstituteController::class, 'listForTeacher']);
    $r->get('/api/teacher/credentials',
            [\App\Controllers\TeacherCredentialController::class, 'index']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/api/teacher/institutes/link',
                  [\App\Controllers\InstituteController::class, 'link']);
        $rr->post('/api/teacher/institutes/{id}/unlink',
                  [\App\Controllers\InstituteController::class, 'unlink']);
        $rr->post('/api/teacher/credentials',
                  [\App\Controllers\TeacherCredentialController::class, 'create']);
        $rr->post('/api/teacher/credentials/{id}/delete',
                  [\App\Controllers\TeacherCredentialController::class, 'delete']);
        $rr->post('/api/teacher/credentials/{id}/toggle',
                  [\App\Controllers\TeacherCredentialController::class, 'toggle']);
        // G20.1 — Editor template personali docente (write/delete/copy)
        $rr->post('/api/teacher/verifica/files/write',
                  [\App\Controllers\TeacherVerificaFilesController::class, 'writeFile']);
        $rr->post('/api/teacher/verifica/files/delete',
                  [\App\Controllers\TeacherVerificaFilesController::class, 'deleteFile']);
        $rr->post('/api/teacher/verifica/files/copy-from-base',
                  [\App\Controllers\TeacherVerificaFilesController::class, 'copyFromBase']);
        // G21.3 — preview PDF di un template (frammento o file completo).
        // Wrappa con preambolo esteso se necessario, compila via VPS, ritorna PDF.
        $rr->post('/api/teacher/verifica/files/preview-pdf',
                  [\App\Controllers\TeacherVerificaFilesController::class, 'previewPdf']);
    });

    // ─── Phase 13: multi-materia, multi-tipo content (mappe/esercizi/lab/verifiche) ───
    // Subjects: docente registra/elenca le proprie materie (qualsiasi codice)
    $r->get('/api/teacher/subjects',
            [\App\Controllers\TeacherSubjectController::class, 'listMine']);

    // G22.S15.bis Fase 5+ — Curriculum scope per-istituto
    $r->get('/api/teacher/curriculum',
            [\App\Controllers\CurriculumController::class, 'index']);
    $r->get('/api/teacher/curriculum/pivot',
            [\App\Controllers\TeacherCurriculumPivotController::class, 'listMine']);
    // ADR-028 — capability effettive del docente (UI: limita le opzioni del
    // dropdown "Chi può vederlo" a max_visibility, coerente con l'enforcement
    // server-side in store()).
    $r->get('/api/teacher/capabilities',
            [\App\Controllers\TeacherContentController::class, 'capabilities']);
    // Phase 15 — manifest JSON preconfezionato (storage/manifests/teacher_{id}/*)
    $r->get('/api/teacher/manifest/{type}',
            [\App\Controllers\ContentExportController::class, 'manifest']);
    // Phase 15 — JSON content contract v1 (storage_objects)
    $r->get('/api/teacher/content/{id}/contract',
            [\App\Controllers\ContentExportController::class, 'contract']);
    // ADR-027 Step 9 — sidebar CRUD per-docente DEPRECATO: rimpiazzato dal
    // modello sidebar_sections (template istituto + override) e dall'UI
    // /admin/sidebar-config. Endpoint /api/teacher/sidebar* rimossi (erano
    // orfani: nessun consumer JS). Tabella teacher_sidebar_sections droppata
    // dalla migration 074.
    // Migration 069 — coppie (indirizzo, classe) del docente per il fan-out scope.
    $r->get('/api/teacher/my-classes',
            [\App\Controllers\TeacherContentController::class, 'myClasses']);
    // Content: CRUD su teacher_content, scoping forzato a teacher_id
    $r->get('/api/teacher/content',
            [\App\Controllers\TeacherContentController::class, 'index']);
    $r->get('/api/teacher/content/{id}',
            [\App\Controllers\TeacherContentController::class, 'show']);
    // G23 Sprint 11 — export HTML pulito standalone (download, no CSRF perché GET).
    $r->get('/api/teacher/content/{id}/export-html',
            [\App\Controllers\ContentExportController::class, 'exportHtml']);
    // Phase 24.76 — rinomine categoria per-docente persistite su DB.
    $r->get('/api/teacher/category-labels',
            [\App\Controllers\TeacherCategoryLabelController::class, 'list']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/api/teacher/category-labels',
                  [\App\Controllers\TeacherCategoryLabelController::class, 'save']);
        $rr->post('/api/teacher/subjects',
                  [\App\Controllers\TeacherSubjectController::class, 'create']);
        $rr->post('/api/teacher/subjects/{id}/delete',
                  [\App\Controllers\TeacherSubjectController::class, 'unlink']);
        // G22.S15.bis Fase 5+ — Curriculum mutators (auth via canModifyInstitute)
        $rr->post('/api/teacher/curriculum/{kind}',
                  [\App\Controllers\CurriculumController::class, 'add']);
        $rr->post('/api/teacher/curriculum/{id}/update',
                  [\App\Controllers\CurriculumController::class, 'update']);
        $rr->post('/api/teacher/curriculum/{id}/remove',
                  [\App\Controllers\CurriculumController::class, 'remove']);
        // Pivot toggle: associa entry a docente (kind=indirizzi/classi/materie)
        $rr->post('/api/teacher/curriculum/pivot/toggle',
                  [\App\Controllers\TeacherCurriculumPivotController::class, 'toggle']);
        // Phase 25.B5 — rate-limit tight 60/min/teacher (bucket=content)
        $rr->post('/api/teacher/content',
                  [\App\Controllers\TeacherContentController::class, 'store'])
                  ->middleware('rate:content,60');
        $rr->post('/api/teacher/content/{id}/update',
                  [\App\Controllers\TeacherContentController::class, 'update'])
                  ->middleware('rate:content,60');
        $rr->post('/api/teacher/content/{id}/delete',
                  [\App\Controllers\TeacherContentController::class, 'destroy'])
                  ->middleware('rate:content,60');
        // Phase 25 — ri-categorizzazione sicura (JSON_SET su metadata.category +
        // eventuale section_id): NON tocca body_pt (storage separato dual-write).
        // Usata dal "migra documenti" della pagina /area-docente/categorie.
        $rr->post('/api/teacher/content/{id}/recategorize',
                  [\App\Controllers\TeacherContentController::class, 'recategorize'])
                  ->middleware('rate:content,60');
        $rr->post('/api/teacher/content/{id}/publish',
                  [\App\Controllers\ContentPublishController::class, 'publish']);
        $rr->post('/api/teacher/content/{id}/unpublish',
                  [\App\Controllers\ContentPublishController::class, 'unpublish']);
        // Phase 24.36 — export ZIP TeX da metadata.body_pt
        $rr->post('/api/teacher/content/{id}/export',
                  [\App\Controllers\ContentExportController::class, 'export']);
        // ADR-024 — modal TeX/PDF (preview + edit + compile) uniforme a risdoc
        $rr->post('/api/teacher/content/{id}/tex-files',
                  [\App\Controllers\ContentExportController::class, 'texFiles']);
        $rr->post('/api/teacher/content/{id}/tex-files/save',
                  [\App\Controllers\ContentExportController::class, 'saveTexFiles']);
        // Audit 25.R.31 — compile-pdf costoso (LaTeX VPS esterno): bucket
        // dedicato per evitare DoS/saturazione condivisa col rate generico.
        $rr->post('/api/teacher/content/{id}/compile-pdf',
                  [\App\Controllers\ContentExportController::class, 'compilePdf'])
                  ->middleware('rate:compile,15');
        // Phase 18 — share pool + audit provenance
        $rr->post('/api/teacher/content/{id}/share-pool',
                  [\App\Controllers\ContentPublishController::class, 'sharePool']);
        $rr->get('/api/teacher/content/{id}/provenance',
                 [\App\Controllers\ContentExportController::class, 'provenance']);
        // Phase 16 — patch/delete/move di un item INTERNO al contract.
        // `{itemRef}` è un locator opaco (numeric id, "<gid>_q<idx>", "g<gi>_q<ii>")
        // risolto da ContractAggregate::findItemIndex. Optimistic locking via
        // header `If-Match: "v<N>"` o body `_version`.
        $rr->post('/api/teacher/content/{id}/quesito/{itemRef}/patch',
                  [\App\Controllers\QuesitoController::class, 'quesitoPatch']);
        $rr->post('/api/teacher/content/{id}/quesito/{itemRef}/delete',
                  [\App\Controllers\QuesitoController::class, 'quesitoDelete']);
        $rr->post('/api/teacher/content/{id}/quesito/{itemRef}/move',
                  [\App\Controllers\QuesitoController::class, 'quesitoMove']);
        $rr->post('/api/teacher/content/{id}/quesito/{itemRef}/duplicate',
                  [\App\Controllers\QuesitoController::class, 'quesitoDuplicate']);
        // Cross-file clone (verifica → esercizio corrispondente per subject+topic).
        $rr->post('/api/teacher/content/{id}/quesito/{itemRef}/clone-to-eser',
                  [\App\Controllers\QuesitoController::class, 'quesitoCloneToEser']);
        // Riordino gruppi nel contract (drag-drop su `.moveBtn`).
        $rr->post('/api/teacher/content/{id}/group/{groupRef}/move',
                  [\App\Controllers\GroupController::class, 'groupMove']);
        // Phase 20 — Add group (nuovo .problem via tipoEsercizio select)
        $rr->post('/api/teacher/content/{id}/group/add',
                  [\App\Controllers\GroupController::class, 'groupAdd']);
        // Phase 20 — Patch group meta (title/intro) in un contract.
        $rr->post('/api/teacher/content/{id}/group/{groupRef}/patch',
                  [\App\Controllers\GroupController::class, 'groupPatch']);
        // Phase 20 — Delete gruppo intero (tutti i suoi items) dal contract.
        $rr->post('/api/teacher/content/{id}/group/{groupRef}/delete',
                  [\App\Controllers\GroupController::class, 'groupDelete']);

        // Phase 21 — risdoc/bes per-teacher overrides
        $rr->post('/api/risdoc/templates/{id}/override',
                  [\App\Controllers\Risdoc\TemplateController::class, 'overrideSave']);
        $rr->post('/api/risdoc/templates/{id}/override/del',
                  [\App\Controllers\Risdoc\TemplateController::class, 'overrideDelete']);
        $rr->post('/api/risdoc/templates/{id}/export',
                  [\App\Controllers\Risdoc\ExportController::class, 'export']);
        // G22.S11 — multi-file modal preview (GET-style POST con form_state),
        // save overrides e compile-bundle al VPS tex-compile.
        $rr->post('/api/risdoc/templates/{id}/tex-files',
                  [\App\Controllers\Risdoc\TexFilesController::class, 'getFiles']);
        $rr->post('/api/risdoc/templates/{id}/tex-files/save',
                  [\App\Controllers\Risdoc\TexFilesController::class, 'saveFiles']);
        $rr->post('/api/risdoc/templates/{id}/compile-pdf',
                  [\App\Controllers\Risdoc\TexFilesController::class, 'compilePdf']);
        // G22.S13 — editor 3 file texCommon condivisi (modal in /area-docente/templates?tab=risdoc)
        $rr->post('/api/teacher/risdoc/templates/files/save',
                  [\App\Controllers\Risdoc\TeacherTexCommonController::class, 'saveFiles']);
        // G22.S15.bis Fase 5 — preview PDF dei 3 file texCommon
        $rr->post('/api/teacher/risdoc/templates/files/preview-pdf',
                  [\App\Controllers\Risdoc\TeacherTexCommonController::class, 'previewPdf']);
        // Phase 24.50 — super-admin: salva PT AST seed di un template
        // (usato dai teacher come base per layout=exercises).
        $rr->post('/api/risdoc/templates/{id}/body-pt',
                  [\App\Controllers\Risdoc\TemplateController::class, 'saveBodyPt']);
        // Phase 24.55 — super-admin: institutional override (baseline editabile).
        $rr->post('/api/risdoc/templates/{id}/institutional-override',
                  [\App\Controllers\Risdoc\TemplateController::class, 'institutionalOverrideSave']);
        $rr->post('/api/risdoc/templates/{id}/institutional-override/del',
                  [\App\Controllers\Risdoc\TemplateController::class, 'institutionalOverrideDelete']);
        // ADR-025 (B) — dati curriculari (obiettivi/competenze/…) come override
        // istituzionali dinamici (admin-CRUD). Gating super-admin nel controller.
        $rr->post('/api/risdoc/curriculum-options',
                  [\App\Controllers\Risdoc\CurriculumOptionsController::class, 'save']);
        $rr->post('/api/risdoc/curriculum-options/delete',
                  [\App\Controllers\Risdoc\CurriculumOptionsController::class, 'delete']);

        // Phase 24.58 — multi-instance teacher overrides
        // Phase 25.B5 — rate-limit tight 30/min (bucket=instances, anti-flood).
        $rr->post('/api/risdoc/templates/{id}/instances',
                  [\App\Controllers\Risdoc\TemplateController::class, 'instancesCreate'])
                  ->middleware('rate:instances,60');
        $rr->post('/api/risdoc/templates/{id}/instances/{key}/delete',
                  [\App\Controllers\Risdoc\TemplateController::class, 'instancesDelete'])
                  ->middleware('rate:instances,60');
        $rr->post('/api/risdoc/templates/{id}/instances/{key}/rename',
                  [\App\Controllers\Risdoc\TemplateController::class, 'instancesRename'])
                  ->middleware('rate:instances,60');

        // Risdoc per-teacher compilations (istanze valorizzate del form).
        $rr->post('/api/risdoc/templates/{id}/compilations',
                  [\App\Controllers\Risdoc\CompilationController::class, 'save']);
        $rr->post('/api/risdoc/compilations/{id}/delete',
                  [\App\Controllers\Risdoc\CompilationController::class, 'delete']);
    });

    // Phase 21 — read-only endpoints (no CSRF, rate-limit comunque tramite 'log')
    $r->get('/risdoc/view/{id}',
            [\App\Controllers\Risdoc\TemplateViewController::class, 'show']);
    $r->get('/risdoc/edit/{id}',
            [\App\Controllers\Risdoc\TemplateEditorController::class, 'show']);
    // Alias legacy: history.replaceState porta a questa URL; renderizza
    // lo stesso template via lookup code = category+filename.
    $r->get('/risdoc/{category}/php/{filename}',
            [\App\Controllers\Risdoc\TemplateViewController::class, 'showByLegacyPath']);
    $r->get('/api/risdoc/templates',
            [\App\Controllers\Risdoc\TemplateController::class, 'index']);
    $r->get('/api/risdoc/templates/{id}',
            [\App\Controllers\Risdoc\TemplateController::class, 'show']);
    $r->get('/api/risdoc/templates/{id}/file',
            [\App\Controllers\Risdoc\TemplateController::class, 'file']);
    $r->get('/api/risdoc/templates/{id}/schema',
            [\App\Controllers\Risdoc\TemplateController::class, 'schema']);
    // ADR-025 (B) — risolutore opzioni curriculari: override istituto → globale
    // (DB) → fallback file statico. Ritorna array JSON (formato file legacy).
    $r->get('/api/risdoc/curriculum-options',
            [\App\Controllers\Risdoc\CurriculumOptionsController::class, 'options']);
    $r->get('/api/risdoc/templates/{id}/tex',
            [\App\Controllers\Risdoc\TemplateController::class, 'tex']);
    $r->get('/api/risdoc/templates/{id}/overrides',
            [\App\Controllers\Risdoc\TemplateController::class, 'overridesList']);
    // Phase 24.55 — institutional overrides list (super-admin gating nel controller)
    $r->get('/api/risdoc/templates/{id}/institutional-overrides',
            [\App\Controllers\Risdoc\TemplateController::class, 'institutionalOverridesList']);
    // Phase 24.58 — instances list per teacher
    $r->get('/api/risdoc/templates/{id}/instances',
            [\App\Controllers\Risdoc\TemplateController::class, 'instancesList']);
    // Phase 24.58 — tutte istanze del docente cross-template (sidepage merge)
    $r->get('/api/risdoc/teacher/instances',
            [\App\Controllers\Risdoc\TemplateController::class, 'teacherAllInstances']);
    $r->get('/api/risdoc/templates/{id}/json-files',
            [\App\Controllers\Risdoc\TemplateController::class, 'jsonFiles']);
    $r->get('/api/risdoc/templates/{id}/drift',
            [\App\Controllers\Risdoc\TemplateController::class, 'driftStatus']);
    $r->get('/api/risdoc/exports/{file}',
            [\App\Controllers\Risdoc\ExportController::class, 'serve']);
    // Phase 24.19 — catalogo JSON paths + folders per popover select UI
    $r->get('/api/risdoc/options-sources',
            [\App\Controllers\Risdoc\TemplateController::class, 'optionsSources']);
    $r->get('/api/risdoc/shared/{file}',
            [\App\Controllers\Risdoc\TemplateController::class, 'sharedAsset']);
    // Risdoc per-teacher compilations — read endpoints.
    $r->get('/api/risdoc/templates/{id}/compilations',
            [\App\Controllers\Risdoc\CompilationController::class, 'index']);
    $r->get('/api/risdoc/compilations/{id}',
            [\App\Controllers\Risdoc\CompilationController::class, 'show']);
    // Catch-all per path legacy usati da risdoc.js (tex, json, css, js, images).
    // Registrato DOPO le route specifiche (view/edit/api) per evitare shadow.
    $r->get('/risdoc/{path*}',
            [\App\Controllers\Risdoc\TemplateController::class, 'legacyPath']);

    // G22.S20 v2.C2 — Profilo docente + template editor + fonti + verifica files.
    // Spostati dal group role:admin (bug pre-esistente: marco.rossi
    // teacher non super_admin prendeva 403). Logicamente teacher routes.
    // Merge namespace: pagine docente unificate sotto /area-docente/* (i vecchi
    // /teacher/* pagine reindirizzano qui; l'API resta /api/teacher/*).
    $r->get('/area-docente',                   fn () => new \App\Core\Response('', 302, ['Location' => '/area-docente/dashboard']));
    $r->get('/area-docente/dashboard',         [\App\Controllers\TeacherController::class, 'dashboard']);
    $r->get('/area-docente/resources',         [\App\Controllers\TeacherController::class, 'resources']);
    $r->get('/area-docente/pdf-import',        [\App\Controllers\Teacher\PdfImportPageController::class, 'page']);
    $r->get('/area-docente/pdf-import/models', [\App\Controllers\Teacher\PdfImportPageController::class, 'modelsPage']);
    $r->get('/area-docente/profilo',           [\App\Controllers\TeacherProfileController::class, 'page']);
    $r->get('/area-docente/templates',         [\App\Controllers\TeacherProfileController::class, 'templatesPage']);
    $r->get('/area-docente/categorie',         [\App\Controllers\TeacherProfileController::class, 'categoriePage']);
    $r->get('/area-docente/fonti',             [\App\Controllers\TeacherProfileController::class, 'fontiPage']);
    $r->get('/api/teacher/verifica/files',     [\App\Controllers\TeacherVerificaFilesController::class, 'listFiles']);
    $r->get('/api/teacher/risdoc/templates/files',
                                                [\App\Controllers\Risdoc\TeacherTexCommonController::class, 'getFiles']);
    $r->get('/api/teacher/verifica/files/read',[\App\Controllers\TeacherVerificaFilesController::class, 'readFile']);
});

// ───────────── COLLABORATOR + ─────────────
$router->group(['middleware' => ['auth', 'role:collaborator', 'log']], function (Router $r) use ($legacyGoneHandler) {
    // Phase 18 — Hard cutover: 410 Gone con redirect smart al modern studio.
    $r->any('/risdoc/{path*}',            $legacyGoneHandler)->middleware('legacy_gone');
    $r->any('/strcomp_bes_altro/{path*}', $legacyGoneHandler)->middleware('legacy_gone');
    $r->any('/drafts/{path*}',            $legacyGoneHandler)->middleware('legacy_gone');
});

// ───────────── ADMIN ONLY ─────────────
$router->group(['middleware' => ['auth', 'role:admin', 'log']], function (Router $r) use ($legacyGoneHandler) {
    // Phase 18 — Hard cutover: /verifiche/* legacy → /studio/verifica/... .
    $r->any('/verifiche/{path*}',           $legacyGoneHandler)->middleware('legacy_gone');
    // Phase 25.E17 — JSON structured templates per dropdown TeX dell'editor,
    // migrato da LegacyController a TikzDataController (con cache headers).
    $r->get('/modelli_tikz.json',          [\App\Controllers\TikzDataController::class, 'show']);
    $r->get('/modelli_tikz_elements.json', [\App\Controllers\TikzDataController::class, 'show']);
    $r->get('/modelli_tikz_traccia.json',  [\App\Controllers\TikzDataController::class, 'show']);

    // AdminPrint — TeX batch print (admin)
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/admin/print',       [\App\Controllers\AdminPrintController::class, 'generate']);
        $rr->post('/admin/print/batch', [\App\Controllers\AdminPrintController::class, 'batch']);
        // G22.S7 — DB migration runner (super_admin gated nel controller).
        $rr->post('/admin/migrate/run', [\App\Controllers\AdminMigrateController::class, 'run']);
    });

    // Ported FileService endpoints (new, type-safe, path-validated)
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/files/save-tex',      [FileController::class, 'saveTex']);
        $rr->post('/files/save-latex',    [FileController::class, 'saveLatex']);
        $rr->post('/files/delete',        [FileController::class, 'deleteFile']);
        $rr->post('/files/delete-folder', [FileController::class, 'deleteFolder']);
        // /files/list-php promosso a student+ (usato dalla sidebar Mappe/Eser/Lab)
        $rr->any ('/files/clear-temp',    [FileController::class, 'clearTemp']);
    });
    $r->get('/files/list', [FileController::class, 'list']);

    // Phase 18 — /exercises/save-new, duplicate-collex, count-collex
    // rimossi: ContractRepository copre create/duplicate/count via
    // /api/teacher/content/* endpoints. ensureCollexIds/cloneCollex
    // spostati anche loro al nuovo flow quesito-based.

    // Ported TikzService endpoints
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/tikz/save-svg',   [\App\Controllers\TikzController::class, 'saveSvg']);
        $rr->post('/tikz/delete-svg', [\App\Controllers\TikzController::class, 'deleteSvg']);
    });
    $r->get('/tikz/content',     [\App\Controllers\TikzController::class, 'content']);
    $r->get('/tikz/ensure-json', [\App\Controllers\TikzController::class, 'ensureJson']);

    // AdminController — new JSON endpoints replacing ad-hoc log/admin PHP pages
    $r->get ('/admin',              [\App\Controllers\AdminController::class, 'dashboard']);
    $r->get ('/admin/dashboard',    [\App\Controllers\AdminController::class, 'dashboard']);
    $r->get ('/admin/tools/hash',   [\App\Controllers\AdminController::class, 'hashToolPage']);
    $r->get ('/admin/tools',        [\App\Controllers\AdminToolsController::class, 'page']);

    // AdminAnalytics — dashboard system-wide cross-istituto (top_institutes,
    // top_authors, crossTeacherSearch). super_admin only: gate inner-group
    // coerente con risdoc/WAF; gli admin per-istituto non-super sono bloccati.
    $r->group(['middleware' => ['super_admin_required']], function (Router $rr) {
        $rr->get('/admin/analytics',                 [\App\Controllers\AdminAnalyticsController::class, 'page']);
        $rr->get('/api/admin/analytics',             [\App\Controllers\AdminAnalyticsController::class, 'snapshot']);
        $rr->get('/api/admin/analytics/teacher/{id}',[\App\Controllers\AdminAnalyticsController::class, 'forTeacher']);
        $rr->get('/api/admin/analytics/cross-search',[\App\Controllers\AdminAnalyticsController::class, 'crossSearch']);
    });

    // G22.S7 — DB migration via web (super-admin only; check in controller).
    // Necessario su Aruba shared hosting senza accesso SSH per `php tools/migrate.php`.
    $r->get ('/admin/migrate',         [\App\Controllers\AdminMigrateController::class, 'page']);
    $r->get ('/admin/migrate/status',  [\App\Controllers\AdminMigrateController::class, 'status']);

    // Phase 14 — dashboard infrastrutturale (super-admin only; check in controller)
    $r->get ('/admin/infrastructure',
             [\App\Controllers\AdminInfrastructureController::class, 'page']);
    $r->get ('/api/admin/infrastructure.json',
             [\App\Controllers\AdminInfrastructureController::class, 'snapshotJson']);
    // AdminAnalytics GET API spostato nel sotto-gruppo super_admin_required
    // sopra (vicino a /admin/analytics page) per gating uniforme.

    // Phase 13 — Admin tools API: users + security CRUD
    $r->get ('/api/admin/users',                          [\App\Controllers\UsersAdminController::class, 'index']);
    $r->get ('/api/admin/security/blocked-credentials',   [\App\Controllers\SecurityAdminController::class, 'listBlockedCredentials']);
    $r->get ('/api/admin/security/blocked-ips',           [\App\Controllers\SecurityAdminController::class, 'listBlockedIps']);
    $r->get ('/api/admin/security/anomalies',             [\App\Controllers\SecurityAdminController::class, 'anomalies']);
    $r->get ('/api/admin/security/live-blocks',           [\App\Controllers\SecurityAdminController::class, 'liveBlocks']);
    $r->get ('/api/admin/security/config',                 [\App\Controllers\SecurityAdminController::class, 'getConfig']);

    // Phase 13 — admin gestisce istituti + users + security
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/api/institutes',                                  [\App\Controllers\InstituteController::class, 'create']);
        $rr->post('/api/admin/users/{id}/active',                     [\App\Controllers\UsersAdminController::class, 'setActive']);
        $rr->post('/api/admin/users/{id}/role',                       [\App\Controllers\UsersAdminController::class, 'setRole']);
        $rr->post('/api/admin/users/{id}/delete',                     [\App\Controllers\UsersAdminController::class, 'delete']);
        $rr->post('/api/admin/security/credentials/block',            [\App\Controllers\SecurityAdminController::class, 'blockCredential']);
        $rr->post('/api/admin/security/credentials/unblock',          [\App\Controllers\SecurityAdminController::class, 'unblockCredential']);
        $rr->post('/api/admin/security/ips/block',                    [\App\Controllers\SecurityAdminController::class, 'blockIp']);
        $rr->post('/api/admin/security/ips/unblock',                  [\App\Controllers\SecurityAdminController::class, 'unblockIp']);
        $rr->post('/api/admin/security/config',                       [\App\Controllers\SecurityAdminController::class, 'setConfig']);
        // /admin/registrations/{id}/approve|reject già registrate sotto (line 280-281)
    });
    // Audit Phase 25.R.31 — log diagnostici (access/debug) + approvazione
    // registrazioni: SUPER-ADMIN only. Prima erano sotto role:admin → un admin
    // d'istituto leggeva log/PII/stack cross-tenant (MEDIUM) e poteva enumerare/
    // approvare/rifiutare registrazioni di ALTRI istituti (IDOR HIGH).
    // whoami/notifications restano admin (già tenant-scoped nel controller).
    $r->group(['middleware' => ['super_admin_required']], function (Router $rr) {
        $rr->get('/admin/access-log',    [\App\Controllers\AdminController::class, 'accessLog']);
        $rr->get('/admin/access-stats',  [\App\Controllers\AdminController::class, 'accessStats']);
        $rr->get('/admin/debug-log',     [\App\Controllers\AdminController::class, 'debugLog']);
        $rr->get('/admin/registrations', [\App\Controllers\RegistrationController::class, 'listPending']);
        $rr->group(['middleware' => ['csrf', 'rate']], function (Router $rrr) {
            $rrr->post('/admin/registrations/{id}/approve', [\App\Controllers\RegistrationController::class, 'approve']);
            $rrr->post('/admin/registrations/{id}/reject',  [\App\Controllers\RegistrationController::class, 'reject']);
        });
    });
    $r->get ('/admin/whoami',          [\App\Controllers\AdminController::class, 'whoAmI']);
    $r->get ('/api/admin/notifications', [\App\Controllers\AdminController::class, 'notifications']);
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/admin/generate-hash', [\App\Controllers\AdminController::class, 'generateHash']);
    });

    // Write operations: CSRF + per-role rate limit (admin 60/min, teacher 30/min, others 15/min)
    //
    // Ogni endpoint è esposto su DUE URL:
    //   - moderno REST-like (`/categoria/azione`) — preferito, usare in codice nuovo
    //   - legacy `.php` — alias di compat finché resta JS che lo referenzia
    //
    // Quando tutto il frontend usa Endpoints.* con URL moderni, rimuovere il
    // blocco legacy sotto.
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        // ─── MODERN URLs (prefer these) ──────────────────────────────
        // Files
        $rr->post('/files/save-image',    [FileController::class, 'saveImage']);
        $rr->post('/files/save-pdf',      [FileController::class, 'savePdf']);
        // /files/save-tex, save-latex, delete, delete-folder, list-php, clear-temp già registrati sopra

        // Phase 18 — Exercises/Editor/Create/Update/Verifiche folders/Links
        // save|check|update-origins RIMOSSI: sostituiti da ContractRepository
        // endpoints (/api/teacher/content/*/quesito/*/patch|delete|move|
        // duplicate|clone-to-eser + group/*/move + POST /api/teacher/content
        // per create empty eser + PUT /api/teacher/sources.json).
        //
        // Route che restano: verifiche/print-info + scelte (print flow),
        // tikz/* (element CRUD).

        // Tikz
        $rr->post('/tikz/save-new-element', [\App\Controllers\TikzController::class, 'saveNewElement']);
        $rr->post('/tikz/edit-element',     [\App\Controllers\TikzController::class, 'editElement']);
        $rr->post('/tikz/delete-element',   [\App\Controllers\TikzController::class, 'deleteElement']);
        $rr->any('/tikz/generate-json',    [\App\Controllers\TikzController::class, 'generateJson']);

        // G19.4 — `/verifiche/print-info` + `/verifiche/scelte` +
        // `/api/teacher/print-info[/delete]` MIGRATI a role:teacher group
        // (vedi line ~280) per teacher-scoped access + per evitare lo
        // shadow del wildcard `/verifiche/{path*}` legacy_gone qui sotto.

        // Check
        $rr->any('/check/password',        [\App\Controllers\CheckController::class, 'password']);
        $rr->any('/check/file-protection', [\App\Controllers\CheckController::class, 'fileProtection']);

        // /delete_temp.php resta (cron CLI/localhost-only, chiamato da
        // Google Apps Script e cron esterni). Phase 25.E17 — migrato da
        // LegacyController a CronController (guard CLI/localhost-only).
        $rr->any ('/delete_temp.php', [\App\Controllers\CronController::class, 'deleteTemp']);
    });

    // Admin log panels — Phase 25.E17 migrato a LogServeController.
    $r->any('/log/admin/{path*}',   [\App\Controllers\LogServeController::class, 'show']);
    $r->any('/log/logging/{path*}', [\App\Controllers\LogServeController::class, 'show']);
    $r->any('/log/security/{path*}',[\App\Controllers\LogServeController::class, 'show']);

    // G22.S20 v2.C2 — Rotte /area-docente/* spostate al group role:teacher
    // (vedi sopra). Erano per errore nel group role:admin → marco.rossi
    // (teacher non super_admin) prendeva 403 sul profilo. docente1 super_admin
    // non vedeva il bug perché passava entrambe le policy.

    // Phase 21 — Risdoc per-teacher admin panel (U8)
    // G13.5 — Admin Templates entrypoint (super_admin) con tabs RisDoc/Verifiche
    $r->get('/admin/templates',                          [\App\Controllers\Admin\TemplatesAdminController::class, 'page']);
    // G22.S26 — gate super-admin centralizzato (sostituisce check inline nei
    // controller). Il middleware applica un 403 coerente JSON/HTML.
    $r->group(['middleware' => ['super_admin_required']], function (Router $rr) {
        $rr->get('/admin/risdoc',                             [\App\Controllers\Admin\RisdocAdminController::class, 'page']);
        $rr->get('/api/admin/risdoc/templates',               [\App\Controllers\Admin\RisdocAdminController::class, 'templatesList']);
        $rr->get('/api/admin/risdoc/templates/{id}',          [\App\Controllers\Admin\RisdocAdminController::class, 'templateDetail']);
        $rr->get('/api/admin/risdoc/teachers',                [\App\Controllers\Admin\RisdocAdminController::class, 'teachersList']);
        $rr->get('/api/admin/risdoc/drift',                   [\App\Controllers\Admin\RisdocAdminController::class, 'driftList']);
        // G22.S26 — Pending review queue (super-admin only).
        $rr->get('/api/admin/risdoc/pending',                 [\App\Controllers\Admin\RisdocAdminController::class, 'pendingList']);
        $rr->get('/api/admin/risdoc/pending/{id}/content',    [\App\Controllers\Admin\RisdocAdminController::class, 'pendingContent']);
        // G22.S26 — schema parsed dal pending content per render WC inline.
        $rr->get('/api/admin/risdoc/pending/{id}/schema',     [\App\Controllers\Admin\RisdocAdminController::class, 'pendingSchema']);
        // G22.S26 — pagina HTML standalone con WC pre-configurato (iframe-friendly).
        $rr->get('/admin/risdoc/pending/{id}/preview',        [\App\Controllers\Admin\RisdocAdminController::class, 'pendingPreviewPage']);
        // Editor file options-source JSON (tab "Sorgenti JSON" in /admin/templates).
        $rr->get('/api/admin/risdoc/options-sources',         [\App\Controllers\Admin\RisdocAdminController::class, 'optionsSourcesList']);
        $rr->get('/api/admin/risdoc/options-source',          [\App\Controllers\Admin\RisdocAdminController::class, 'optionsSourceRead']);
    });
    // G22.S26 — risdoc admin POST: super-admin + csrf/rate/audit_reason.
    $r->group(['middleware' => ['super_admin_required', 'csrf', 'rate', 'audit_reason']], function (Router $rr) {
        $rr->post('/api/admin/risdoc/templates/{id}/visibility',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'visibilityBulk']);
        // G22.S26 — endpoint setOwner rimosso (col owner_id droppata).
        $rr->post('/api/admin/risdoc/templates/{id}/collaborators',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'collaboratorsEdit']);
        // G22.S26 — Approve/reject pending review.
        $rr->post('/api/admin/risdoc/pending/{id}/approve',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'pendingApprove']);
        $rr->post('/api/admin/risdoc/pending/{id}/reject',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'pendingReject']);
        // Phase 25.B3 — visibility_scope per template istituzionali
        $rr->post('/api/admin/risdoc/templates/{id}/visibility-scope',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'setVisibilityScope']);
        // ADR-027 — edit nome/posizione/categoria del template + rinomina gruppo.
        $rr->post('/api/admin/risdoc/templates/{id}/meta',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'updateMeta']);
        $rr->post('/api/admin/risdoc/templates/rename-group',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'renameGroup']);
        // Phase 24.57 — crea nuovo template (e partizione se category nuova).
        $rr->post('/api/admin/risdoc/templates/create',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'createTemplate']);
        // Salva un file options-source JSON (tab "Sorgenti JSON" in /admin/templates).
        $rr->post('/api/admin/risdoc/options-source',
                  [\App\Controllers\Admin\RisdocAdminController::class, 'optionsSourceSave']);
    });
    // Altri admin POST (verifica*): mantieni gating preesistente (auth interno controller).
    $r->group(['middleware' => ['csrf', 'rate', 'audit_reason']], function (Router $rr) {
        // G19.49l — preamble verifiche (deprecated G20.0, mantenuto per back-compat)
        $rr->post('/api/admin/verifica/preamble',
                  [\App\Controllers\Admin\VerificaPreambleAdminController::class, 'save']);
        $rr->post('/api/admin/verifica/preamble/reset',
                  [\App\Controllers\Admin\VerificaPreambleAdminController::class, 'reset']);
        // G20.0 Phase 9 — Admin files API (texCommon/versioni/griglie editor).
        // Path passato come ?path=texCommon/verifica.sty (no in url segments —
        // il router non supporta wildcard regex .+ in placeholder).
        $rr->post('/api/admin/verifica/files/write',
                  [\App\Controllers\Admin\VerificaFilesAdminController::class, 'writeFile']);
        $rr->post('/api/admin/verifica/files/delete',
                  [\App\Controllers\Admin\VerificaFilesAdminController::class, 'deleteFile']);
        $rr->post('/api/admin/verifica/files/copy-from-default',
                  [\App\Controllers\Admin\VerificaFilesAdminController::class, 'copyFromDefault']);
    });
    // G19.49l — preamble verifiche read endpoint (no CSRF, GET)
    $r->get('/api/admin/verifica/preamble',
            [\App\Controllers\Admin\VerificaPreambleAdminController::class, 'get']);
    // G20.0 Phase 9 — Admin files read endpoints
    $r->get('/api/admin/verifica/scopes',
            [\App\Controllers\Admin\VerificaFilesAdminController::class, 'listScopes']);
    $r->get('/api/admin/verifica/files',
            [\App\Controllers\Admin\VerificaFilesAdminController::class, 'listFiles']);
    // Path passato come ?path=... query string
    $r->get('/api/admin/verifica/files/read',
            [\App\Controllers\Admin\VerificaFilesAdminController::class, 'readFile']);
});

// ───────────── WAF (Phase 25.C) ─────────────
// Endpoint pubblico raccolta fingerprint. NO auth required (deve essere
// accessibile da utenti non ancora con cookie waf_session valido).
// Rate-limit per-IP (audit 2026-06-01): impedisce il minting di massa di
// cookie waf_session da un singolo client (l'IP è risolto via EdgeContext).
$router->post('/waf/fingerprint', [\App\Controllers\WafApiController::class, 'collect'])
    ->middleware('rate:waf_fp,40');

// ───────────── Admin WAF (super_admin only) ─────────────
$router->group(['middleware' => ['auth', 'role:admin', 'log']], function (Router $r) {
    // Read-only pages + GET API: super_admin gate
    $r->group(['middleware' => ['super_admin_required']], function (Router $rr) {
        $rr->get('/admin/waf',             [\App\Controllers\Admin\WafAdminController::class, 'index']);
        $rr->get('/admin/waf/dashboard',   [\App\Controllers\Admin\WafAdminController::class, 'dashboard']);
        $rr->get('/admin/waf/config',      [\App\Controllers\Admin\WafAdminController::class, 'configPage']);
        $rr->get('/admin/waf/rules',       [\App\Controllers\Admin\WafAdminController::class, 'rulesPage']);
        // Phase 25.R.19 — tab unificato (merge ex lists + credentials).
        $rr->get('/admin/waf/blocks',      [\App\Controllers\Admin\WafAdminController::class, 'blocksPage']);
        // Back-compat: redirect 301 vecchie route → /admin/waf/blocks
        $rr->get('/admin/waf/lists',       [\App\Controllers\Admin\WafAdminController::class, 'listsPage']);
        $rr->get('/admin/waf/credentials', [\App\Controllers\Admin\WafAdminController::class, 'credentialsPage']);
        $rr->get('/admin/waf/anomalies',   [\App\Controllers\Admin\WafAdminController::class, 'anomaliesPage']);
        $rr->get('/admin/waf/reports',     [\App\Controllers\Admin\WafAdminController::class, 'reportsPage']);
        $rr->get('/admin/waf/threat-intel',[\App\Controllers\Admin\WafAdminController::class, 'threatIntelPage']);
        $rr->get('/admin/waf/diag',        [\App\Controllers\Admin\WafAdminController::class, 'diagPage']);
        $rr->get('/admin/waf/api/logs',     [\App\Controllers\Admin\WafAdminController::class, 'apiLogs']);
        $rr->get('/admin/waf/api/counters', [\App\Controllers\Admin\WafAdminController::class, 'apiCounters']);
    });
    // Write API: super_admin + CSRF + rate + audit_reason
    $r->group(['middleware' => ['super_admin_required', 'csrf', 'rate', 'audit_reason']], function (Router $rr) {
        $rr->post  ('/admin/waf/api/config',            [\App\Controllers\Admin\WafAdminController::class, 'apiUpdateConfig']);
        $rr->post  ('/admin/waf/api/threat-intel/sync', [\App\Controllers\Admin\WafAdminController::class, 'apiThreatIntelSync']);
        $rr->post  ('/admin/waf/api/rules',             [\App\Controllers\Admin\WafAdminController::class, 'apiCreateRule']);
        $rr->put   ('/admin/waf/api/rules/{id}',        [\App\Controllers\Admin\WafAdminController::class, 'apiUpdateRule']);
        $rr->delete('/admin/waf/api/rules/{id}',        [\App\Controllers\Admin\WafAdminController::class, 'apiDeleteRule']);
        $rr->post  ('/admin/waf/api/rules/{id}/toggle', [\App\Controllers\Admin\WafAdminController::class, 'apiToggleRule']);
        $rr->post  ('/admin/waf/api/blacklist',         [\App\Controllers\Admin\WafAdminController::class, 'apiAddBlacklist']);
        $rr->delete('/admin/waf/api/blacklist/{id}',    [\App\Controllers\Admin\WafAdminController::class, 'apiDeleteBlacklist']);
        $rr->post  ('/admin/waf/api/whitelist',         [\App\Controllers\Admin\WafAdminController::class, 'apiAddWhitelist']);
        $rr->delete('/admin/waf/api/whitelist/{id}',    [\App\Controllers\Admin\WafAdminController::class, 'apiDeleteWhitelist']);
    });
});

// ───────────── API authenticated ─────────────
$router->group(['prefix' => '/api', 'middleware' => ['auth', 'role:collaborator']], function (Router $r) {
    /* Phase 25.E17 — /copilot-ai.{js,css} e /copilot-ai-init.js: file
       inesistenti sul filesystem (verifica fs: nessun copilot-ai* presente
       dopo Phase 24 cleanup). Route rimosse. Se mai un asset moderno
       riemerge, registrarlo come static via /js/ /css/ (Apache native). */

    // AI write endpoints — CSRF + rate limit (gap U3 chiuso).
    // Moderno: /api/copilot/chat → CopilotController. I path legacy
    // /copilot.php e /copilot_proxy.php mantenuti come alias per i
    // file eser/**/*.php che li chiamano inline; entrambi instradati
    // al nuovo controller. api/copilot*.php fisici cancellati.
    $r->group(['middleware' => ['csrf', 'rate']], function (Router $rr) {
        $rr->post('/copilot/chat',      [\App\Controllers\CopilotController::class, 'chat']);
        $rr->post('/copilot.php',       [\App\Controllers\CopilotController::class, 'chat']);
        $rr->post('/copilot_proxy.php', [\App\Controllers\CopilotController::class, 'chat']);
    });
});

// Legacy auth endpoints (login.php, AuthCode.php) kept during migration.
// /log/auth/* deve restare pubblico — è la pagina di login (login.php è
// un bridge a /login, AuthCode.php gestisce la validazione code).
// Phase 25.E17 — migrato da LegacyController a LogServeController.
$router->any('/log/auth/{path*}',    [\App\Controllers\LogServeController::class, 'show']);
// /log/logout/* contiene asset pubblici (logout_widget.php, logout_button.js).
$router->any('/log/logout/{path*}',  [\App\Controllers\LogServeController::class, 'show']);
