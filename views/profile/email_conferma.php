<?php
/**
 * La pagina del link ricevuto al nuovo indirizzo (15/9/2026). La conferma è un
 * bottone (POST), non l'apertura del link: vedi AccountController.
 *
 * @var string $csrf
 * @var string $token
 * @var ?string $nuova
 * @var bool $fatto
 */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="fm-card fm-card--form">
    <h1 class="fm-m-0 fm-mb-4">✉️ Nuovo indirizzo</h1>
    <?php if ($fatto): ?>
        <div class="fm-alert fm-alert--success" role="status">
            Fatto: l'email del tuo account adesso è <strong><?= $h($nuova) ?></strong>.
        </div>
        <a class="fm-btn fm-btn--primary fm-btn--full" href="/me/account" data-full-reload>Vai al tuo account</a>
    <?php elseif ($nuova !== null): ?>
        <p>Confermi <strong><?= $h($nuova) ?></strong> come email del tuo account?</p>
        <form method="post" action="/me/account/email/conferma" data-full-reload>
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Conferma il nuovo indirizzo</button>
        </form>
    <?php else: ?>
        <div class="fm-alert fm-alert--error" role="status">
            Il link non vale più: è scaduto, è già stato usato, o l'indirizzo nel frattempo è stato preso da un
            altro account. Puoi chiedere un nuovo cambio dal tuo account.
        </div>
        <a class="fm-btn fm-btn--ghost fm-btn--full" href="/me/account" data-full-reload>Vai al tuo account</a>
    <?php endif; ?>
</div>
