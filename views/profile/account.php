<?php
/**
 * «Il mio account» (15/9/2026): email, password e verifica in due passaggi.
 *
 * @var string $csrf
 * @var array $user
 * @var string $email
 * @var bool $dueFattori
 * @var ?string $metodo
 * @var bool $obbligatori
 * @var ?array{0:string,1:string} $messaggio
 * @var string $tornaA
 */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="fm-card fm-card--form">
    <h1 class="fm-m-0 fm-mb-1">👤 Il mio account</h1>
    <p class="fm-muted fm-mt-0 fm-mb-4">
        Utente: <strong><?= $h($user['username'] ?? '') ?></strong>
    </p>

    <?php if ($messaggio !== null): ?>
        <div class="fm-alert fm-alert--<?= $messaggio[0] === 'ok' ? 'success' : 'error' ?> fm-mb-4" role="status">
            <?= $h($messaggio[1]) ?>
        </div>
    <?php endif; ?>

    <section class="fm-mb-4" id="email" aria-labelledby="account-email-titolo">
        <h2 class="fm-mt-0" id="account-email-titolo">✉️ Email</h2>
        <p class="fm-mt-0">
            Attuale: <strong><?= $email !== '' ? $h($email) : '<span class="fm-muted">nessuna</span>' ?></strong>
        </p>
        <p class="fm-muted fm-text-13">
            Serve per recuperare la password e, se lo hai scelto, per il codice della verifica in due
            passaggi. Per cambiarla ti chiediamo la password; poi ti mandiamo un link al nuovo indirizzo, e
            l'email cambia solo quando lo apri. Al vecchio indirizzo arriva un avviso.
        </p>
        <form method="post" action="/me/account/email" autocomplete="off" data-full-reload>
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label class="fm-label" for="nuova_email">Nuovo indirizzo</label>
            <input id="nuova_email" class="fm-input" type="email" name="nuova_email" autocomplete="email" maxlength="255" required>
            <label class="fm-label" for="account_password">Password attuale</label>
            <input id="account_password" class="fm-input" type="password" name="password" autocomplete="current-password" required>
            <button type="submit" class="fm-btn fm-btn--primary fm-mt-2">Manda il link di conferma</button>
        </form>
    </section>

    <section class="fm-mb-4" aria-labelledby="account-password-titolo">
        <h2 id="account-password-titolo">🔐 Password</h2>
        <p class="fm-muted fm-text-13 fm-mt-0">Si cambia con la password attuale.</p>
        <a class="fm-btn fm-btn--ghost" href="/me/change-password">Cambia password</a>
    </section>

    <section class="fm-mb-4" aria-labelledby="account-2fa-titolo">
        <h2 id="account-2fa-titolo">🛡️ Verifica in due passaggi</h2>
        <p class="fm-mt-0">
            <?php if ($dueFattori): ?>
                <strong class="fm-text-success">✓ Attiva</strong>,
                <?= ($metodo ?? 'app') === 'email' ? 'con il codice via email.' : 'con l\'app di autenticazione.' ?>
            <?php else: ?>
                <strong>Non attiva.</strong>
                <?= $obbligatori ? 'Per il tuo ruolo è obbligatoria.' : '' ?>
            <?php endif; ?>
        </p>
        <a class="fm-btn fm-btn--ghost" href="/me/2fa">Gestisci la verifica in due passaggi</a>
    </section>

    <a class="fm-btn fm-btn--ghost fm-btn--sm" href="<?= $h($tornaA) ?>" data-full-reload>← Torna indietro</a>
</div>
