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

// ── Da qui in poi solo la cornice intera ──────────────────────────────────
//
// 23/9/2026 (revisione architetturale A-33) — istituto attivo, vocabolario e
// servizi della barra si calcolano qui, dopo i due rami che rendono il solo
// contenuto. Prima si calcolavano in cima, anche per la navigazione SPA
// (partial) e per l'embed, che poi scartavano il risultato: a ogni click una
// lettura del catalogo e dell'istituto attivo, e per chi studia il calcolo
// delle materie con materiali, per niente.
//
// Chi rende il layout può passare `$fmDatiDellaBarra`: lo fa una prova
// (tests/Integration/LayoutModesTest.php), per contare quante volte il
// calcolo parte in ciascuna modalità. Di norma è questa funzione.
$fmDatiDellaBarra = $fmDatiDellaBarra ?? static function (): array {
    // G22.S15.bis Fase 5+ — curriculum scope per istituto attivo dell'utente.
    // Revisione 2026-09 (P6): niente query nella vista. L'istituto attivo e'
    // Auth::currentInstitute(), con cache di sessione: studente = istituto di
    // registrazione (users.institute_id), docente = quello scelto nel selettore
    // o il primo del pivot teacher_institutes, admin = quello del suo scope.
    $_currInstituteId = null;
    $_currUserId = (int)(\App\Core\Auth::user()['id'] ?? 0);
    if ($_currUserId > 0) {
        try {
            $_currInstituteId = \App\Core\Auth::currentInstitute();
        } catch (\Throwable $e) { /* skip */ }
    }
    // G22.S22 — Catalog ownership refactor full: tutti i kind sono per-docente.
    // La sidebar mostra TUTTE le entries del docente cross-institute (owner=
    // teacher in qualsiasi istituto). Il pivot curriculum_users è stato
    // rimosso (migration 044): ogni docente possiede direttamente le sue righe.
    $_curriculumSvc = new CurriculumService(
        jsonPath:  AppConfig::get('app.paths.storage') . '/data/curriculum.json',
    );
    // Chi studia — lo studente con account e l'ospite con la credenziale di classe
    // (ADR-032) — vede il vocabolario attivo della sua scuola (ADR-035) per
    // indirizzi e classi, ma nel selettore delle materie solo quelle in cui c'è
    // almeno un materiale che può vedere (19/9/2026). Prima c'era tutto il
    // vocabolario: in produzione diciassette materie, quindici vuote. Il calcolo
    // usa lo stesso gate dell'elenco dei contenuti (MaterieConMateriali), così
    // selettore ed elenco non possono dire cose diverse. Al cambio di classe
    // (anni frequentati) il selettore si ricalcola da
    // js/modules/features/materie-di-chi-studia.js.
    $_fmMaterieDiChiStudia = false;
    $_fmScuolaDiChiStudia = \App\Services\Study\ChiStudia::scuolaDelVocabolario();
    if ($_fmScuolaDiChiStudia !== null) {
        // Per l'ospite con credenziale e' la scuola della credenziale (o del
        // docente che l'ha creata): la usano anche le sezioni della barra.
        $_currInstituteId = $_fmScuolaDiChiStudia;
        $curriculum = $_curriculumSvc->allActiveForInstitute($_fmScuolaDiChiStudia);
        // Se il calcolo non riesce (DB giu' a meta' pagina, colonna cambiata)
        // resta il vocabolario intero, ma non in silenzio: perLaBarra() scrive
        // l'anomalia, che la diagnostica legge a timer.
        $_fmEsitoMaterie = \App\Services\Study\MaterieConMateriali::perLaBarra($curriculum['materie'] ?? []);
        $curriculum['materie'] = $_fmEsitoMaterie['materie'];
        $_fmMaterieDiChiStudia = $_fmEsitoMaterie['filtrate'];
    } elseif ($_currUserId > 0) {
        // Teacher/admin: SOLO le entries del docente nell'istituto ATTIVO
        // (quello selezionato nel dropdown #sel-istituto), non cross-institute.
        // Bug pre-fix: all(null, userId) restituiva tutte le classi/materie di
        // ogni istituto collegato → il selettore Classe mostrava "Classe I..V"
        // di Esempio + "Classe I..III" dell'altro istituto insieme.
        // L'istituto attivo è la session current_institute_id (settata da
        // /api/tenant/switch), già risolta sopra in $_currInstituteId.
        $_activeIid = $_currInstituteId;
        $curriculum = $_activeIid
            ? $_curriculumSvc->all($_activeIid, $_currUserId)
            : $_curriculumSvc->all(null, $_currUserId);
    } else {
        // Utente non loggato e senza credenziale (login/register pages): catalog
        // anchor di un istituto random per popolare i select demo (non funzionali).
        // L'ospite con la credenziale di classe e' nel primo ramo, con lo studente.
        $curriculum = $_curriculumSvc->all($_currInstituteId);
    }

    return [
        'istituto'           => $_currInstituteId,
        'utente'             => $_currUserId,
        'curriculum'         => $curriculum,
        'materieDiChiStudia' => $_fmMaterieDiChiStudia,
    ];
};
$_fmDatiDellaBarra = $fmDatiDellaBarra();
$_currInstituteId      = $_fmDatiDellaBarra['istituto'];
$_currUserId           = $_fmDatiDellaBarra['utente'];
$curriculum            = $_fmDatiDellaBarra['curriculum'];
$_fmMaterieDiChiStudia = $_fmDatiDellaBarra['materieDiChiStudia'];

$isAdmin      = Auth::check() && Auth::hasAccess('admin');
$isTeacher    = Auth::check() && Auth::role() === 'teacher';
$isSuperAdmin = Auth::check() && Auth::isSuperAdmin();
$authedUser   = Auth::user();

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
      data-fm-role="<?= htmlspecialchars((string)Auth::role(), ENT_QUOTES) ?>"
      <?php /* 2026-09-14 — le zone d'accesso (Auth::zone): il client chiede la zona, non il ruolo. */ ?>
      data-fm-zones="<?= htmlspecialchars(implode(' ', Auth::zone()), ENT_QUOTES) ?>">
<!--email_off--><?php /* Cloudflare Email Address Obfuscation OFF a livello pagina:
        evita l'iniezione di /cdn-cgi/.../email-decode.min.js, bloccata dalla CSP
        strict-dynamic (same-origin ma host-allowlist disabilitata) → le email
        restano testo leggibile (anche per screen reader) invece di "[email protected]". */ ?>
<?php /* Phase 25.R.2.2 — anti-FOUC dark mode: applica body.fm-dark in modo
        sincrono appena il browser parsa il tag <body>, PRIMA che dipinga
        i bambini. Non decide: legge la decisione che js/tema-iniziale.js ha
        scritto in html[data-theme] nel <head>. Fino al 23/9/2026 qui c'era
        una politica sua (nessuna scelta salvata = scuro), e alla prima visita
        con il sistema in chiaro la pagina restava scura (A-50). */ ?>
<script<?= \App\Support\Csp::attributo() ?>>document.body.classList.toggle("fm-dark",document.documentElement.getAttribute("data-theme")==="dark");</script>

<a href="#fm-content" class="fm-skip-link">Salta al contenuto principale</a>

<?php /* Preavviso aggiornamento ToS/AUP (ToS §8, AUP §6). Fuori da <main>
         di proposito: il router SPA rimpiazza #fm-content, e un avviso
         legale non deve sparire alla prima navigazione interna. */ ?>
<?php include __DIR__ . '/../partials/_legal_notice_banner.php'; ?>

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
<script<?= \App\Support\Csp::attributo() ?> src="/js/fm-router.js"   defer></script>
<script<?= \App\Support\Csp::attributo() ?> src="/js/fm-url-state.js" defer></script>

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
