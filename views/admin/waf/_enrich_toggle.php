<?php
/**
 * Phase 25.H — Toggle "🔍 RDNS & ASN" per tabelle IP admin.
 *
 * Include in cima a ogni sezione tabellare in IP Lists / Reports /
 * Credentials / Dashboard. Variabile in scope: $enrich (bool — stato corrente).
 *
 * Bind: localStorage `fm-waf-enrich` ON/OFF persiste cross-session.
 * Cambio toggle → ricarica pagina con/senza ?enrich=1 (server-side
 * enrichment via GeoIpService::enrich pesa ~1ms ASN + 50-2000ms rDNS).
 *
 * Render: tab Switch stile iOS — un solo elemento, riusabile.
 */
/** @var bool $enrich */
$enrich = (bool)($enrich ?? false);
$_curUri  = $_SERVER['REQUEST_URI'] ?? '';
$_curPath = strtok($_curUri, '?');
$_qs      = $_SERVER['QUERY_STRING'] ?? '';
parse_str($_qs, $_q);
unset($_q['enrich']);
$_baseQs = http_build_query($_q);
$_offUrl = $_curPath . ($_baseQs ? '?' . $_baseQs : '');
$_onUrl  = $_curPath . '?' . ($_baseQs ? $_baseQs . '&' : '') . 'enrich=1';
?>
<div class="fm-waf-enrich-toggle">
    <a class="fm-waf-switch <?= $enrich ? 'is-on' : '' ?>"
       href="<?= htmlspecialchars($enrich ? $_offUrl : $_onUrl, ENT_QUOTES) ?>"
       data-full-reload
       role="switch"
       aria-checked="<?= $enrich ? 'true' : 'false' ?>"
       title="<?= $enrich ? 'Disattiva enrichment rDNS+ASN (più veloce)' : 'Attiva enrichment rDNS+ASN (più lento ma più info)' ?>">
        <span class="fm-waf-switch__track">
            <span class="fm-waf-switch__thumb"></span>
        </span>
        <span class="fm-waf-switch__lbl">🔍 RDNS &amp; ASN</span>
    </a>
</div>
