<?php

/**
 * Banner di preavviso aggiornamento ToS/AUP.
 *
 * Copre la finestra fra la pubblicazione di una nuova versione e la sua data
 * di efficacia — i 30 giorni promessi da ToS §8 e AUP §6. In quella finestra
 * l'utente NON è bloccato: il banner informa e offre l'accettazione anticipata.
 * Il blocco vero arriva dopo, da TosAcceptanceMiddleware.
 *
 * Incluso da views/layout/app.php. Silenzioso e a costo zero se non c'è nulla
 * di pendente o il DB non risponde.
 */

$_fmNotice = null;
try {
    $_fmNoticeUid = (int)(\App\Core\Auth::user()['id'] ?? 0);
    if ($_fmNoticeUid > 0 && \App\Core\Database::isAvailable()) {
        $_fmNotice = (new \App\Services\Gdpr\TosAcceptanceService())->noticeFor($_fmNoticeUid);
    }
} catch (\Throwable $_) {
    $_fmNotice = null;
}

if ($_fmNotice === null) {
    return;
}

$_fmDays = (int)$_fmNotice['days_remaining'];
$_fmDate = date('d/m/Y', strtotime((string)$_fmNotice['effective_from']));
$_fmDocs = [];
foreach ($_fmNotice['versions'] as $_v) {
    $_fmDocs[] = strtoupper((string)$_v['doc_type']) . ' ' . (string)$_v['version'];
}
?>
<div class="fm-legal-notice" role="status" data-fm-legal-notice hidden>
    <div class="fm-legal-notice__body">
        <strong>Aggiornamento dei documenti contrattuali</strong>
        <span>
            <?= e(implode(' · ', $_fmDocs)) ?> —
            <?php if ($_fmDays <= 0): ?>
                in vigore da <strong>oggi</strong>.
            <?php elseif ($_fmDays === 1): ?>
                in vigore da domani, <strong><?= e($_fmDate) ?></strong>.
            <?php else: ?>
                in vigore dal <strong><?= e($_fmDate) ?></strong>
                (fra <?= (int)$_fmDays ?> giorni).
            <?php endif; ?>
            Da quella data l'accettazione sarà necessaria per continuare a usare pantedu.
        </span>
    </div>
    <div class="fm-legal-notice__actions">
        <a class="fm-btn fm-btn--primary fm-btn--sm" href="/tos-acceptance" data-full-reload>
            Leggi e accetta
        </a>
        <button type="button" class="fm-legal-notice__close" data-fm-legal-dismiss
                aria-label="Nascondi l'avviso fino al prossimo accesso">&times;</button>
    </div>
</div>

<style>
.fm-legal-notice {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem 1rem;
    align-items: center;
    justify-content: space-between;
    padding: .7rem 1rem;
    background: var(--fm-c-info-light, #cff4fc);
    color: var(--fm-c-info, #055160);
    border-bottom: 1px solid var(--fm-c-info, #055160);
    font-size: .92rem;
    line-height: 1.4;
}
.fm-legal-notice__body { display: flex; flex-direction: column; gap: .15rem; min-width: 16rem; flex: 1 1 24rem; }
.fm-legal-notice__actions { display: flex; align-items: center; gap: .5rem; flex: none; }
.fm-legal-notice__close {
    background: none;
    border: 0;
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
    color: inherit;
    padding: 0 .35rem;
}
.fm-legal-notice__close:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
</style>

<script>
(function () {
    var el = document.querySelector('[data-fm-legal-notice]');
    if (!el) { return; }
    // La chiave include la data di efficacia: pubblicare una versione nuova
    // riporta il banner anche a chi aveva chiuso il precedente.
    var key = 'fm_legal_notice_' + <?= json_encode((string)$_fmNotice['effective_from']) ?>;
    try {
        if (sessionStorage.getItem(key) === '1') { el.remove(); return; }
    } catch (e) { /* storage non disponibile: mostra comunque */ }
    el.hidden = false;
    var btn = el.querySelector('[data-fm-legal-dismiss]');
    if (btn) {
        btn.addEventListener('click', function () {
            try { sessionStorage.setItem(key, '1'); } catch (e) { /* best-effort */ }
            el.remove();
        });
    }
})();
</script>
