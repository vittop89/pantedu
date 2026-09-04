<?php
/**
 * Phase G8 — Modern unified topbar (esercizi+verifiche).
 *
 * Sostituisce gradualmente `.fm-upbar > .selwrapbtncopy` legacy con 6 azioni
 * primarie (SalvaTEX, Overleaf, ZIP, GENERA, filtri, Editor) coerenti col
 * design system (gradient, dark-theme aware).
 *
 * Bridge invisibile: contiene gli hidden input (#overleaf, #Server,
 * #syncDrive, #btnCopyver) usati da utilities.js per save/restore delle
 * preferenze utente in print_info.json (G22.S6: print-export.js dismesso).
 *
 * Visibilita: gestita da topbar-modern.js. Server-side il markup e' sempre
 * presente; il client-side decide se attivarlo in base al contesto pagina
 * (body.exercise-context + presenza placeholder #upbar).
 */

use App\Core\Auth;

$__topbar_isAdmin = class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin');
?>
<div class="fm-topbar" id="fm-topbar"
     data-fm-doc-mode="exercises"
     data-fm-admin="<?= $__topbar_isAdmin ? '1' : '0' ?>"
     hidden
     aria-label="Barra strumenti documento">

    <div class="fm-topbar__zone fm-topbar__zone--meta">
        <span class="fm-topbar__doctype" data-fm-doctype-label>Documento</span>
        <span class="fm-topbar__title" data-fm-title-label></span>
        <!-- G9.25 — slot per P.TOT-A / P.TOT-R spostati qui via JS al
             page-load (window.FM.TopbarModern.relocateTotals). -->
        <span class="fm-topbar__totals" data-fm-totals-slot></span>
    </div>

    <div class="fm-topbar__zone fm-topbar__zone--target" role="toolbar" aria-label="Azioni documento">
        <button type="button" class="fm-topbar__btn fm-topbar__btn--primary"
                data-fm-action="salvatex"
                title="Salva il .tex nel database e genera i PDF compilando sul microservizio tex-compile del VPS pantedu.eu.">
            <span class="fm-topbar__ico" aria-hidden="true">💾</span><span class="fm-topbar__lbl">TEX/PDF</span>
        </button>
        <!-- G24 (ADR-022) — toggle-edit + export trio JSON/TeX/HTML RIMOSSI da
             qui: ora incapsulati nel WebComponent <fm-pt-document> (toolbar
             interna .ptdoc__toolbar). Le pagine custom usano il componente,
             non più questi button topbar. Vedi js/components/pt-document/. -->
        <button type="button" class="fm-topbar__btn fm-topbar__btn--logo"
                data-fm-action="overleaf"
                title="Apri Overleaf con il file generato (commit + open)."
                aria-label="Overleaf">
            <img class="fm-topbar__logo" src="/img/topbar/overleaf.svg" alt="" aria-hidden="true" width="20" height="20" loading="lazy">
        </button>
        <button type="button" class="fm-topbar__btn"
                data-fm-action="zip"
                title="Scarica un .zip con tutti i .tex/asset.">
            <span class="fm-topbar__lbl"><strong>ZIP</strong></span>
        </button>
        <!-- G19.35 — GENERA rimosso (non piu' necessario). VSC e' il nuovo
             flow primario: salva batch + ZIP + apre cartella in VSCode
             (nuova finestra via `vscode://vscode.openFolder?...&windowId=_blank`). -->
        <button type="button" class="fm-topbar__btn fm-topbar__btn--accent fm-topbar__btn--logo"
                data-fm-action="vsc"
                title="Salva + scarica .zip + apri la cartella in nuova finestra VSCode."
                aria-label="VSCode">
            <img class="fm-topbar__logo" src="/img/topbar/vscode.svg" alt="" aria-hidden="true" width="20" height="20" loading="lazy">
        </button>
        <button type="button" class="fm-topbar__btn fm-topbar__btn--icon"
                data-fm-action="vsc-settings"
                title="Configura cartella radice VSC (path assoluto host)."
                aria-label="VSC settings">
            <span class="fm-topbar__ico" aria-hidden="true">⚙</span>
        </button>
        <!-- G19.35 — GENERA hidden (back-compat per test E2E che dispatchano
             `[data-fm-action="genera"]` programmatically). UI invisibile. -->
        <button type="button" class="fm-topbar__btn fm-d-none" data-fm-action="genera"
                aria-hidden="true" tabindex="-1" >G</button>
    </div>

    <!-- G19.22 — slot dove `topbar-modern.js` rilocca `.selector-eser`
         (Crea-gruppo + .scelte-verifica-wrapper + #savePrintInfoBtn /
         #loadPrintInfoBtn) al primo attivamento del topbar.
         G20.6 — il bottone Info, #verTitlePrefix e #verTitle vengono
         inseriti DENTRO questa zona (dopo #loadPrintInfoBtn) via
         topbar-modern.js relocateVerTitle(). Info e' ricreato ad ogni
         chiamata perche' ui-comp `_caricaCheckboxABin` rimuove e
         re-clona `.selector-eser` (ui-comp.js:2227). -->
    <div class="fm-topbar__zone fm-topbar__zone--eser" data-fm-eser-slot></div>

    <div class="fm-topbar__zone fm-topbar__zone--actions" role="toolbar" aria-label="Strumenti aggiuntivi">
        <button type="button" class="fm-topbar__btn fm-topbar__btn--icon"
                data-fm-action="filtri"
                title="Filtri (difficolta, origine, visibilita)"
                aria-label="Filtri">
            <span class="fm-topbar__ico" aria-hidden="true">⚙</span><span class="fm-topbar__lbl">filtri</span>
        </button>
        <button type="button" class="fm-topbar__btn fm-topbar__btn--icon"
                data-fm-action="editor"
                title="Editor template TEX (intestazione, griglia voti, criteri, footer)"
                aria-label="Editor template">
            <span class="fm-topbar__ico" aria-hidden="true">⚙</span><span class="fm-topbar__lbl">Editor</span>
        </button>
    </div>

    <!-- Bridge invisibile: utilities.js (vanilla) legge #overleaf / #Server /
         #syncDrive per save/restore delle preferenze utente
         in `print_info.json`. Stato sincronizzato da topbar-modern.js
         dai bottoni primari.
         G22.S15.bis Fase 5+: rimosso #btnCopyver (M11 dead path,
         verifica-builder.js eliminato). -->
    <div class="fm-topbar__legacy-bridge" hidden aria-hidden="true">
        <input type="checkbox" id="overleaf" data-fm-bridge="overleaf">
        <input type="checkbox" id="Server" data-fm-bridge="server">
        <input type="checkbox" id="syncDrive" data-fm-bridge="syncDrive" checked>
    </div>
</div>
