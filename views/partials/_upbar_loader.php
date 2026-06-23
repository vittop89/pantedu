<?php
/**
 * UpBar loader — incluso dai Study controller (ob_start + require).
 *
 * Phase G8.14 — semplificazione post-cleanup:
 *   - upbar.html ora contiene SOLO la scrollbarUpBar con
 *     upbar-controls-container (filtri DIFFICOLTA/ORIGINE/HideAll/etc).
 *     Il blocco `.selwrapbtncopy` e i tooltip sono stati rimossi
 *     dal sorgente; non serve piu' lo strip DOM defensive.
 *   - Promuove `.fm-upbar` → `.fm-upbar.fm-admin-access` per admin (regole CSS legacy
 *     gating sel-origin / toggle-checkboxABin-control).
 *   - Strip defensivo per non-admin: rimuove sel-origin e
 *     toggle-checkboxABin-control (admin-only filtri).
 *   - Append `_topbar_modern.php` (bridge invisibile + bottoni
 *     SalvaTEX/Overleaf/ZIP/GENERA/filtri/Editor).
 *
 * Usa App\Core\Auth — niente session_start() qui (front controller
 * l'ha già fatto) e niente header Content-Type (output è buffered).
 */

use App\Core\Auth;

$isAdmin   = class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin');
$isTeacher = class_exists(Auth::class) && Auth::check() && Auth::role() === 'teacher';

// Phase 25.Q.13 — studente/guest: skip TOTALE upbar legacy. I filtri
// DIFFICOLTA / HideAll / CheckAll sono operativi solo nel contesto admin
// (composizione verifica, multi-selection). Studente vede direttamente
// #header_page (Fonte delle citazioni) senza gap superiore.
if (!$isAdmin && !$isTeacher) {
    return;
}

$__upbarFile = __DIR__ . '/upbar.html';
$upbarHtml = @file_get_contents($__upbarFile);
if ($upbarHtml === false) {
    echo '<!-- UpBar: file non leggibile -->';
    return;
}

if ($isAdmin) {
    // Promuovi la .fm-upbar a .fm-upbar.fm-admin-access: una sola regex sul root.
    $upbarHtml = preg_replace(
        '/<div\s+class="fm-upbar"(\s+id="SelBtnCopy")?/i',
        '<div class="fm-upbar fm-admin-access"$1',
        $upbarHtml,
        1,
    ) ?? $upbarHtml;
    echo $upbarHtml;
} else {
    // Non-admin: strip degli admin-only filtri via DOMDocument.
    $dom = new DOMDocument();
    $prevErr = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $upbarHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);

    $remove = static function (?\DOMNode $n): void {
        if ($n && $n->parentNode) $n->parentNode->removeChild($n);
    };
    $remove($dom->getElementById('sel-origin'));
    $remove($dom->getElementById('toggle-checkboxABin-control'));

    $html = (string)$dom->saveHTML();
    echo str_replace('<?xml encoding="UTF-8">', '', $html);
}

// Phase G8 — append modern topbar dopo upbar legacy. topbar-modern.js
// decide a runtime se mostrarla (in base a body.exercise-context +
// presenza di .fm-groupcollex o layout=exercises).
//
// Phase 25.Q — guard server-side per ruolo: i bottoni TEX/PDF/Overleaf/ZIP/
// vscode/Editor/genera sono docente-only (compongono/scaricano sorgenti).
// Studente e guest non devono ricevere il markup, anche se JS lo nasconde.
if ($isAdmin || $isTeacher) {
    $__topbarModernFile = __DIR__ . '/_topbar_modern.php';
    if (is_file($__topbarModernFile)) {
        require $__topbarModernFile;
    }
}
