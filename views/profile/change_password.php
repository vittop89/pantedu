<?php
/** @var string      $csrf */
/** @var string|null $errorMessage */
/** @var bool|null   $done */
/** @var array|null  $user */
?>
<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🔐 Cambia password</h1>
    <p class="fm-muted fm-text-em-lg" >
        Utente: <strong><?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?></strong>
    </p>

    <?php if (!empty($done)): ?>
        <div class="fm-alert fm-alert--success">
            Password aggiornata.
        </div>
        <a class="fm-btn fm-btn--primary fm-btn--full" href="/?home=1" data-full-reload>Vai alla home</a>
    <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="fm-alert fm-alert--error"><?= e($errorMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="/me/change-password" autocomplete="on" data-full-reload>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label class="fm-label" for="current_password">Password attuale</label>
            <input id="current_password" class="fm-input" type="password" name="current_password"
                   autocomplete="current-password" required>

            <label class="fm-label" for="new_password">Nuova password (min 8 caratteri)</label>
            <input id="new_password" class="fm-input" type="password" name="new_password"
                   autocomplete="new-password" minlength="8" maxlength="4096" required>

            <label class="fm-label" for="confirm_password">Conferma nuova password</label>
            <input id="confirm_password" class="fm-input" type="password" name="confirm_password"
                   autocomplete="new-password" minlength="8" maxlength="4096" required>

            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Aggiorna password</button>
        </form>
        <p class="fm-muted fm-mt-4 fm-text-center" >
            <a class="fm-link" href="/me/2fa">🔐 Autenticazione a 2 fattori (2FA)</a> ·
            <a class="fm-link" href="/?home=1" data-full-reload>← Torna alla home</a>
        </p>
        <script>
        (() => {
            const a = document.getElementById('new_password');
            const b = document.getElementById('confirm_password');
            const sync = () => {
                if (b.value && a.value !== b.value) {
                    b.setCustomValidity('Le password non corrispondono');
                } else {
                    b.setCustomValidity('');
                }
            };
            a.addEventListener('input', sync);
            b.addEventListener('input', sync);
        })();
        </script>
    <?php endif; ?>
</div>
