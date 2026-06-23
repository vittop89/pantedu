<?php
/**
 * Phase G14 — RisDoc admin panel partial.
 *
 * Body-only markup (no <html>/<head>/<style>) per essere incluso inline
 * dentro la tab "RisDoc" della pagina /admin/templates moderna.
 * I CSS sono ora in css/layout.css sotto namespace .fm-ar-* (G14.3).
 * Lo script admin-risdoc.js gia' cerca #fm-ar-root e funziona invariato.
 */
?>
<div class="fm-ar-wrap" id="fm-ar-root">
    <div class="fm-d-flex fm-items-center fm-gap-2" style="margin:0 0 12px">
        <strong>Catalogo template RisDoc</strong>
        <button type="button" class="fm-infotip" aria-label="Info catalogo template"><span class="fm-infotip__body" hidden><p>Tabella unica: modifica <strong>posizione</strong>, <strong>argomento</strong> e <strong>partizione</strong> (e rinomina un'intera partizione), consulta le statistiche (visibilità / collaboratori / override / drift) e gestisci visibilità per-docente, immagini e schema — tutto da qui.</p><p>ℹ️ Le <strong>partizioni</strong> organizzano i <em>template istituzionali</em> (questo catalogo) e determinano quali gruppi sono <em>forkabili</em> (vedi <a href="/admin/sidebar-config">sidebar-config</a>). Sono cosa diversa dalle <strong>categorie</strong> con cui ogni <em>docente</em> organizza i propri documenti (Area docente → Categorie): stesso nome di default, scopo diverso.</p></span></button>
    </div>
    <div class="fm-ar-tabs" role="tablist">
        <button class="fm-ar-tab" role="tab" data-tab="templates" aria-selected="true">📋 Template (modifica + visibilità)</button>
        <button class="fm-ar-tab" role="tab" data-tab="pending"   aria-selected="false">🛡 Modifiche in revisione <span id="fm-ar-pending-badge" class="fm-ar-pill fm-d-none" ></span></button>
        <button class="fm-ar-tab" role="tab" data-tab="drift" aria-selected="false">⚠ Source drift</button>
    </div>

    <div class="fm-ar-panel" id="fm-ar-panel">
        <div class="fm-ar-loading">Caricamento…</div>
    </div>
</div>
