<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🔑 Accesso Pantedu</h1>
    <?php if (!empty($error)): ?>
        <div class="fm-alert fm-alert--error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($rateLimitSeconds)): ?>
        <div class="fm-alert fm-alert--warning">
            Troppi tentativi. Riprova tra <strong><?= (int)$rateLimitSeconds ?></strong> secondi.
        </div>
    <?php endif; ?>
    <form method="post" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf"    value="<?= e($csrf) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirect ?? '/') ?>">
        <label class="fm-label" for="fm-login-username">Username</label>
        <input id="fm-login-username" class="fm-input" type="text" name="username"
               autocomplete="username" required autofocus>
        <label class="fm-label" for="fm-login-pwd">Password</label>
        <input id="fm-login-pwd" class="fm-input" type="password" name="password"
               autocomplete="current-password" required>
        <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Entra</button>
    </form>

    <?php /* Phase D.2 — SPID + CIE placeholder buttons.
             I bottoni sono visualizzati ma DISABILITATI (aria-disabled
             + cursor:not-allowed) finche' pantedu non ottiene la
             certificazione AgID come SP. Comunica l'intento + prepara
             il terreno per onboarding scuole/docenti PA. */ ?>
    <div class="fm-login-federated" role="group" aria-label="Accesso federato (in arrivo)">
        <div class="fm-login-divider" aria-hidden="true">
            <span>oppure (prossimamente)</span>
        </div>
        <button type="button"
                class="fm-btn fm-btn--federated fm-btn--spid"
                aria-disabled="true"
                disabled
                title="SPID disponibile dopo certificazione AgID — vedi /accessibility per stato">
            <span class="fm-btn-icon" aria-hidden="true">🆔</span>
            <span>Entra con SPID</span>
            <span class="fm-sr-only">(non ancora disponibile)</span>
        </button>
        <button type="button"
                class="fm-btn fm-btn--federated fm-btn--cie"
                aria-disabled="true"
                disabled
                title="CIE disponibile dopo certificazione AgID — vedi /accessibility per stato">
            <span class="fm-btn-icon" aria-hidden="true">💳</span>
            <span>Entra con CIE</span>
            <span class="fm-sr-only">(non ancora disponibile)</span>
        </button>
        <p class="fm-muted fm-federated-hint">
            Login con identità digitale italiana <strong>in arrivo</strong>
            per docenti di scuole convenzionate.
            <a class="fm-link" href="/accessibility#stato-spid-cie">Stato e roadmap</a>.
        </p>
    </div>

    <p class="fm-muted fm-mt-4 fm-text-center" >
        Non hai un account? <a class="fm-link" href="/register">Registrati</a><br>
        <span class="fm-text-13">Problemi di accesso? Contatta l'amministratore.</span>
    </p>

    <p class="fm-mt-4 fm-text-center">
        <a class="fm-btn fm-btn--ghost fm-btn--full fm-login-home" href="/" data-full-reload>← Torna alla home</a>
    </p>
    <style>
        /* Visibilità robusta in entrambi i temi (body.fm-dark + prefers-color-scheme). */
        .fm-login-home { color: var(--fm-c-text); border: 1px solid var(--fm-c-border); background: transparent; }
        .fm-login-home:hover { background: var(--fm-c-surface-2, rgba(127,127,127,.12)); }
        body.fm-dark .fm-login-home { color: #fff; border-color: #888; background: rgba(255,255,255,.06); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) .fm-login-home { color: #fff; border-color: #888; background: rgba(255,255,255,.06); }
        }
    </style>
</div>
