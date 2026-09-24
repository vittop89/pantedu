<?php
/**
 * Le pagine della cancellazione dell'account (24/9/2026): l'esito della
 * richiesta, la pagina del collegamento dell'email con il pulsante di
 * conferma, la conferma, l'annullamento. Prima chi arrivava da un modulo o da
 * un collegamento vedeva JSON grezzo. Vedi SelfServiceController.
 *
 * La conferma è un pulsante (POST), non l'apertura del collegamento, come per
 * il cambio dell'email. L'indirizzo della pagina del collegamento contiene il
 * gettone, e non deve arrivare come Referer alle richieste che partono da qui:
 * lo impediscono l'intestazione e il meta `no-referrer` di PaginaConGettone.
 * `rel="noreferrer"` da solo non bastava: Chromium lo ignora sui moduli, e i
 * fogli di stile e gli script non lo guardano (misurato il 24/9/2026).
 *
 * @var string $titolo
 * @var string $esito 'ok' | 'errore' | 'info'
 * @var list<string> $righe testo semplice
 * @var string $csrf
 * @var ?string $gettone se c'è, il pulsante di conferma
 * @var bool $contatto se mostrare a chi scrivere
 * @var string $dpo il recapito privacy del titolare, o ''
 * @var ?string $collegamentoDiProva solo fuori dalla produzione
 */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$classe = match ($esito) {
    'ok'     => 'fm-alert--success',
    'errore' => 'fm-alert--error',
    default  => 'fm-alert--info',
};
?>
<div class="fm-card fm-card--form">
    <h1 class="fm-m-0 fm-mb-4"><?= $h($titolo) ?></h1>
    <?php /* Un solo figlio: .fm-alert è flex, e i paragrafi diventerebbero colonne. */ ?>
    <div class="fm-alert <?= $classe ?>" role="status">
        <div>
            <?php foreach ($righe as $riga): ?>
                <p><?= $h($riga) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($contatto): ?>
        <p>
            Puoi riprovare più tardi, oppure chiedere la cancellazione al titolare:
            <?php if ($dpo !== ''): ?>
                scrivi a <a href="mailto:<?= $h($dpo) ?>?subject=<?= $h(rawurlencode('Cancellazione dell\'account')) ?>"><?= $h($dpo) ?></a>,
                oppure usa il modulo <a href="/dpo-contact" data-full-reload>per le richieste sulla privacy</a>.
            <?php else: ?>
                usa il modulo <a href="/dpo-contact" data-full-reload>per le richieste sulla privacy</a>.
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($gettone !== null): ?>
        <form method="post" action="/me/confirm-deletion" rel="noreferrer" data-full-reload>
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="token" value="<?= $h($gettone) ?>">
            <button type="submit" class="fm-btn fm-btn--danger fm-btn--full">Conferma la cancellazione dell'account</button>
        </form>
    <?php endif; ?>
    <?php if ($collegamentoDiProva !== null): ?>
        <p>Ambiente di prova: <a href="<?= $h($collegamentoDiProva) ?>" data-full-reload>il collegamento di conferma</a>.</p>
    <?php endif; ?>
    <a class="fm-btn fm-btn--ghost fm-btn--full" href="/privacy/your-data" rel="noreferrer" data-full-reload>Torna a «I tuoi dati»</a>
</div>
