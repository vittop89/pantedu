<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🔑 Scegli una nuova password</h1>

    <?php if (!empty($error)): ?>
        <div class="fm-alert fm-alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($valid)): ?>
        <div class="fm-alert fm-alert--error">
            Questo link non è più valido: può essere scaduto, già usato, oppure
            sostituito da una richiesta più recente.
        </div>
        <p class="fm-mt-4 fm-text-center">
            <a class="fm-btn fm-btn--primary fm-btn--full" href="/password/forgot">Richiedi un nuovo link</a>
        </p>
    <?php else: ?>
        <form method="post" action="/password/reset" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <label class="fm-label" for="fm-reset-pwd">Nuova password</label>
            <input id="fm-reset-pwd" class="fm-input" type="password" name="new_password"
                   autocomplete="new-password" minlength="8" required autofocus>

            <label class="fm-label" for="fm-reset-pwd2">Ripeti la password</label>
            <input id="fm-reset-pwd2" class="fm-input" type="password" name="confirm_password"
                   autocomplete="new-password" minlength="8" required>

            <?php /* Stesso modulo JS del login (data-pw-toggle), un toggle per
                     campo: e' proprio qui che rileggere quel che si e' scritto
                     evita di impostare due volte una password sbagliata e
                     restare chiusi fuori subito dopo averla reimpostata. */ ?>
            <label class="fm-pwtoggle">
                <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-reset-pwd">
                <span class="fm-pwtoggle__text">Mostra la nuova password</span>
            </label>
            <label class="fm-pwtoggle">
                <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-reset-pwd2">
                <span class="fm-pwtoggle__text">Mostra la ripetizione</span>
            </label>

            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Imposta la password</button>
        </form>

        <p class="fm-muted fm-text-13 fm-mt-4">
            Almeno 8 caratteri. Le password comparse in violazioni note vengono
            rifiutate: il controllo avviene senza mai inviare la password
            (<em>k-anonymity</em>, solo le prime cifre del suo hash).
        </p>
    <?php endif; ?>
</div>
