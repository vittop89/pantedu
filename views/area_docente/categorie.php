<?php
/** Phase 25 — Pagina dedicata gestione categorie (Risorse docente + BES/DSA). */
$pageTitle    = 'PANTEDU — Categorie';
$pageContent  = ob_get_clean();
$bodyClass    = 'fm-area-docente-categorie';
$currentRoute = '/area-docente/categorie';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>🗂️ Categorie <button type="button" class="fm-infotip" aria-label="Info categorie"><span class="fm-infotip__body" hidden><p>Gestisci qui le categorie con cui organizzi i <strong>tuoi documenti</strong> (Risorse docente e BES/DSA): crea, rinomina ed elimina. Le tue categorie sono cosa diversa dalle <em>partizioni</em> dei template istituzionali (quelle le gestisce l'admin in /admin/templates).</p><p>Le categorie <em>predefinite</em> (modelli, risorse, bes, altro) sono impostate dall'amministratore: puoi rinominarle solo se l'admin lo consente, e non si eliminano (sono strutturali). Per le tue categorie personali puoi rinominare, eliminare (con i contenuti) o, eliminandole, <strong>migrare</strong> i documenti in un'altra categoria senza perderli.</p></span></button></h1>
    </header>

    <div id="fm-cat-manager" class="fm-cat-manager">
        <p class="fm-muted fm-text-center fm-p-5">Caricamento categorie…</p>
    </div>
</main>

<script type="module">
    function _mount() {
        const el = document.getElementById("fm-cat-manager");
        if (el && window.FM?.CategoryManager) { window.FM.CategoryManager.mount(el); return true; }
        return false;
    }
    if (!_mount()) { let n = 0; const t = setInterval(() => { if (_mount() || ++n > 40) clearInterval(t); }, 100); }
</script>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
