<?php
/**
 * App layout — unifica la cornice (head + sidebar + modals) e il
 * content slot per ogni pagina autenticabile del sito.
 *
 * Modalità:
 *   - full   (default): sidebar + modals + main content
 *   - embed  (query ?embed=1): solo il content, nessuna cornice.
 *              Usata durante la transizione mentre l'iframe è
 *              ancora presente (Phase 6c). Sparirà con la 6e.
 *   - partial (header X-Partial: 1): solo il content, header-only.
 *              Usato dal SPA router in Phase 6d.
 *
 * Variabili in input:
 *   $pageTitle     (string, opzionale)
 *   $pageHead      (string, opzionale HTML extra <head>)
 *   $pageContent   (string, HTML del content slot)
 *   $pageScripts   (string, opzionale script footer)
 *   $currentRoute  (string, opzionale — esposto a data-route)
 */

use App\Core\Auth;
use App\Core\Config as AppConfig;
use App\Services\CurriculumService;

$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
if (!class_exists(\App\Core\Config::class)) {
    require_once $_pantedu_base . '/app/bootstrap.php';
}

// G22.S15.bis Fase 5+ — curriculum scope per istituto attivo del docente
// + filtrato pivot user (mostra solo entries selezionate dal docente).
$_currInstituteId = null;
$_currUserId = (int)(\App\Core\Auth::user()['id'] ?? 0);
$_currUserRole = (string)(\App\Core\Auth::user()['role'] ?? '');
if ($_currUserId > 0 && \App\Core\Database::isAvailable()) {
    try {
        $pdo = \App\Core\Database::connection();
        // Phase 25.Q.11 — lookup institute_id differente per ruolo:
        //   - student: users.institute_id (1:1 fisso da registrazione)
        //   - teacher/admin: teacher_institutes pivot (M:N)
        if ($_currUserRole === 'student') {
            $stmt = $pdo->prepare('SELECT institute_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_currUserId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT institute_id FROM teacher_institutes
                 WHERE user_id = ? ORDER BY created_at, institute_id LIMIT 1'
            );
            $stmt->execute([$_currUserId]);
        }
        $_iid = (int)$stmt->fetchColumn();
        if ($_iid > 0) $_currInstituteId = $_iid;
    } catch (\Throwable $e) { /* skip */ }
}
// G22.S22 — Catalog ownership refactor full: tutti i kind sono per-docente.
// La sidebar mostra TUTTE le entries del docente cross-institute (owner=
// teacher in qualsiasi istituto). Il pivot curriculum_users è stato
// rimosso (migration 044): ogni docente possiede direttamente le sue righe.
$_curriculumSvc = new CurriculumService(
    jsonPath:  AppConfig::get('app.paths.storage') . '/data/curriculum.json',
    backupDir: AppConfig::get('app.paths.storage') . '/backups',
);
if ($_currUserRole === 'student' && $_currInstituteId !== null) {
    // Phase 25.Q.11 — studente: vede l'offerta formativa del suo istituto
    // (indirizzi/classi/materie ATTIVE create da QUALUNQUE docente). Nel modello
    // G22.S22 le entries sono per-docente (owner_user_id != NULL), quindi
    // all($iid, null) — che filtra owner IS NULL — restituiva vuoto e i select
    // sidebar risultavano non popolati. allActiveForInstitute() è owner-agnostico
    // e deduplica per code.
    $curriculum = $_curriculumSvc->allActiveForInstitute($_currInstituteId);
} elseif ($_currUserId > 0) {
    // Teacher/admin: SOLO le entries del docente nell'istituto ATTIVO
    // (quello selezionato nel dropdown #sel-istituto), non cross-institute.
    // Bug pre-fix: all(null, userId) restituiva tutte le classi/materie di
    // ogni istituto collegato → il selettore Classe mostrava "Classe I..V"
    // di Esempio + "Classe I..III" dell'altro istituto insieme.
    // L'istituto attivo è la session current_institute_id (settata da
    // /api/tenant/switch); fallback al primo istituto collegato.
    $_activeIid = null;
    try { $_activeIid = Auth::currentInstitute(); } catch (\Throwable $_) {}
    $_activeIid = $_activeIid ?: $_currInstituteId;
    $curriculum = $_activeIid
        ? $_curriculumSvc->all($_activeIid, $_currUserId)
        : $_curriculumSvc->all(null, $_currUserId);
} else {
    // Utente non loggato (login/register pages): catalog anchor di un
    // istituto random per popolare i select demo (non funzionali).
    $curriculum = $_curriculumSvc->all($_currInstituteId);
}

$isAdmin      = Auth::check() && Auth::hasAccess('admin');
$isTeacher    = Auth::check() && Auth::role() === 'teacher';
$isSuperAdmin = Auth::check() && Auth::isSuperAdmin();
$authedUser   = Auth::user();

$isEmbed   = isset($_GET['embed']) && $_GET['embed'] === '1';
$isPartial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';

// Embed/partial: skip chrome, emit content only.
// PROBLEM-sidebar: NON chiamare header() qui — l'app.php è incluso dentro
// un ob_start() del controller, e Response::send() chiamera' http_response_code
// + header() dopo ob_get_clean. Il doppio set causava warning "headers already
// sent by app.php:91/92". Il Content-Type viene impostato dal Response wrapper.
if ($isPartial) {
    $content = $pageContent ?? '';
    if (trim($content) === '') {
        echo '<div data-fm-full-reload="1" hidden></div>';
        return;
    }
    echo $content;
    return;
}
if ($isEmbed) {
    // Minimal standalone HTML with head + content (no sidebar/modals).
    // Keeps the iframe-served pages self-contained during migration.
    ?><!doctype html>
<html lang="it">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="fm-embed">
<!--email_off-->
    <a href="#fm-content" class="fm-skip-link">Salta al contenuto principale</a>
    <main id="fm-content" data-route="<?= htmlspecialchars($currentRoute ?? '', ENT_QUOTES) ?>" tabindex="-1">
        <?= $pageContent ?? '' ?>
    </main>
    <?= $pageScripts ?? '' ?>
<!--/email_off-->
</body>
</html><?php
    return;
}
?><!doctype html>
<html lang="it">
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php
/* Phase 22 — teacher id esposto lato client via data-fm-uid.
 * Lo usa risdoc.js per taggare la pending queue delle compilations e
 * prevenire leak cross-user su browser condivisi (docente A logout,
 * docente B login → B NON vede/flusha i pending di A). */
$fmUid = \App\Services\Risdoc\Permission::currentTeacherId();
// Phase 25.Q.12 — flag scope server-side per gating injection client-side
// (es. dsa-marks.js, ui-comp.js inietta UI di edit). HTML markup mai
// generato per studente — defense-in-depth (no CSS-only hide).
$_canEdit = (Auth::role() === 'teacher' || Auth::hasAccess('admin'));
?>
<body class="<?= htmlspecialchars($bodyClass ?? '', ENT_QUOTES) ?><?= $_canEdit ? ' fm-can-edit' : ' fm-no-edit' ?>"
      data-fm-uid="<?= $fmUid > 0 ? (int)$fmUid : '' ?>"
      data-fm-can-edit="<?= $_canEdit ? '1' : '0' ?>"
      data-fm-role="<?= htmlspecialchars((string)Auth::role(), ENT_QUOTES) ?>">
<!--email_off--><?php /* Cloudflare Email Address Obfuscation OFF a livello pagina:
        evita l'iniezione di /cdn-cgi/.../email-decode.min.js, bloccata dalla CSP
        strict-dynamic (same-origin ma host-allowlist disabilitata) → le email
        restano testo leggibile (anche per screen reader) invece di "[email protected]". */ ?>
<?php /* Phase 25.R.2.2 — anti-FOUC dark mode: applica body.fm-dark in modo
        sincrono appena il browser parsa il tag <body>, PRIMA che dipinga
        i bambini. head.php imposta html.fm-dark-pre per la pittura prima
        del body; qui aggiungiamo body.fm-dark in tempo per il primo
        paint dei contenuti. */ ?>
<script>(function(){try{var s=localStorage.getItem("fm_dark_mode");if(s===null||s==="1")document.body.classList.add("fm-dark");}catch(e){}})();</script>

<a href="#fm-content" class="fm-skip-link">Salta al contenuto principale</a>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<?php include __DIR__ . '/../partials/modals.php'; ?>

<main id="fm-content" data-route="<?= htmlspecialchars($currentRoute ?? '', ENT_QUOTES) ?>" tabindex="-1">
    <?= $pageContent ?? '' ?>
</main>

<!-- iframe rimossa in Phase 6e — la navigazione SPA usa fm-router.
     Lasciamo questi placeholder perché script.js tardo ha selettori
     #myframe / #iframe-specific-warning: restituiscono elementi
     inesistenti dal DOM se non presenti, rompendo catene jQuery. -->
<div id="iframe-specific-warning" class="warning-message" hidden></div>
<div id="myframe" hidden></div>

<?php /* Phase 18 — sel-admin (quick-add inline) dismessoo: usa
         /admin/curriculum per CRUD completo indirizzi/classi/materie. */ ?>

<!-- G26.phase8a — fm-compat.js rimosso: era shim jQuery per intercettare
     $('#myframe').attr('src', url) (mai usato in modern code dopo Phase 16,
     SPA fm-router gestisce navigation direttamente). script.js +
     functions-mod.js legacy non sono piu' caricati. -->
<script src="/js/fm-router.js"   defer></script>
<script src="/js/fm-url-state.js" defer></script>

<?= $pageScripts ?? '' ?>

<?php
// Phase S2 F4 (ADR-017) — Footer watermark institute mode.
// Mostra "Gestito da {INSTITUTE_LEGAL_NAME}" solo se deployment_mode=institute.
// In single mode il footer è assente (zero overhead).
$_fmInstituteName = \App\Support\DeploymentMode::instituteLegalName();
if ($_fmInstituteName !== null):
?>
<footer class="fm-institute-footer fm-footer-fixed" role="contentinfo"
        >
    Gestito da <strong><?= htmlspecialchars($_fmInstituteName, ENT_QUOTES) ?></strong>
    · <a href="/privacy/informativa" class="fm-text-inherit fm-underline">Privacy</a>
    · <a href="/legal/dpa" class="fm-text-inherit fm-underline">DPA</a>
</footer>
<style>body { padding-bottom: 2.4em; }</style>
<?php endif; ?>
<!--/email_off-->
</body>
</html>
