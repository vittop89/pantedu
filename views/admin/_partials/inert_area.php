<?php
/**
 * Avviso «area inattiva in questo scenario» (piano classi-credenziali-scenari, B).
 *
 * Si include in testa a una pagina, o sopra un blocco, che nello scenario
 * corrente non ha effetto: la pagina resta raggiungibile e funzionante, ma chi
 * ci arriva sa subito che cio' che imposta qui non cambia nulla, e con quale
 * scenario tornerebbe a contare.
 *
 * Parametri nello scope di include:
 *   $inert_area  string  chiave in App\Support\AdminAreas (es. 'sections')
 *
 * Se l'area e' attiva non stampa niente: si puo' includere senza condizioni.
 */

/** @var string $inert_area */
$_notice = \App\Support\AdminAreas::notice((string)($inert_area ?? ''));
if ($_notice !== null):
    $_e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="fm-alert fm-alert--warn fm-mb-4" role="status" data-inert-area="<?= $_e($_notice['key']) ?>">
    <strong>⏸️ Inattivo nello scenario <?= (int)$_notice['number'] ?></strong>
    <span>(<?= $_e($_notice['scenario_label']) ?>)</span>
    — <?= $_e($_notice['why']) ?>
    Si attiva con <?= $_e($_notice['activates_with']) ?>:
    <a class="fm-link-inline" href="/admin/system/deployment" data-full-reload>pannello Deployment</a>.
</div>
<?php endif; ?>
