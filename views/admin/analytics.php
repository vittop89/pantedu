<?php
/** @var string $csrf */
$page_title    = '📊 Admin Analytics';
$page_subtitle = 'Statistiche aggregate su utenti, content, accessi. Cross-teacher inspection per audit/security/copyright.';
$breadcrumb    = [['label' => 'Analytics']];
include __DIR__ . '/_partials/page_head.php';
?>
    <input type="hidden" id="fm-an-csrf" value="<?= e($csrf) ?>">

    <div class="fm-tabs" role="tablist">
        <button class="fm-tab fm-tab--active" data-tab="overview">📈 Overview</button>
        <button class="fm-tab" data-tab="teachers">👨‍🏫 Drill-down per docente</button>
        <button class="fm-tab" data-tab="search">🔍 Cross-teacher search</button>
    </div>

    <div class="fm-tab-panels">
        <section class="fm-tab-panel fm-tab-panel--active" data-panel="overview">
            <h2>Overview</h2>
            <div id="fm-an-overview"><p class="fm-muted">Caricamento…</p></div>
        </section>
        <section class="fm-tab-panel" data-panel="teachers">
            <h2>Drill-down per docente</h2>
            <div class="fm-toolbar">
                <input type="number" id="fm-an-tid" class="fm-input fm-max-w-140" placeholder="teacher ID" >
                <button class="fm-btn fm-btn--primary" id="fm-an-tid-load">Carica</button>
                <small class="fm-muted">ID lo trovi nel tab Utenti di /admin.</small>
            </div>
            <div id="fm-an-teacher"></div>
        </section>
        <section class="fm-tab-panel" data-panel="search">
            <h2>Cross-teacher content search <button type="button" class="fm-infotip" aria-label="Info ricerca cross-docente"><span class="fm-infotip__body" hidden>Permette all'admin di ispezionare il content di tutti i docenti (anche draft) per audit, security review, copyright check. Flag euristici: <code>copyright_marker</code>, <code>external_links</code>, <code>publisher_brand_mention</code>.</span></button></h2>
            <div class="fm-toolbar">
                <input type="search" id="fm-an-q" class="fm-input fm-max-w-280" placeholder="Cerca in title/topic/body…" >
                <select id="fm-an-type" class="fm-input fm-max-w-160" >
                    <option value="">Tutti i tipi</option>
                    <option value="mappa">Mappa</option>
                    <option value="esercizio">Esercizio</option>
                    <option value="lab">Lab</option>
                    <option value="verifica">Verifica</option>
                </select>
                <button class="fm-btn fm-btn--primary" id="fm-an-search">Cerca</button>
            </div>
            <div id="fm-an-search-result"></div>
        </section>
    </div>
</div>

<?php /* Phase 25.D — CSS estratto in /css/admin.css (auto-load da layout/shell). */ ?>

<?= \App\Support\ViteManifest::script('js/entries/admin-analytics.js') ?>

</div><!-- /.fm-card -->
