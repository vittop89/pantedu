<?php
/**
 * ADR-032 — la pagina di accesso dipende dallo scenario:
 *   1 (personal)   : accesso dell'autore; iscrizioni chiuse; studenti → /accesso-classe
 *   2 (colleagues) : accesso docenti; iscrizione docenti aperta; studenti → /accesso-classe
 *   3 (institute)  : accesso docenti e studenti; iscrizione aperta; SPID/CIE in roadmap
 *
 * @var string $csrf
 * @var string|null $redirect
 * @var string|null $error
 * @var int|null $rateLimitSeconds
 * @var array<string,mixed>|null $scenario
 */
$sc              = is_array($scenario ?? null) ? $scenario : [];
$scenarioKey     = (string)($sc['scenario'] ?? 'personal');
$signupOpen      = (bool)($sc['teacher_signup_open'] ?? false);
$studentAccounts = (bool)($sc['student_accounts'] ?? false);
$isInstitute     = $scenarioKey === 'institute';
?>
<div class="fm-card fm-card--modal">
    <h1 class="fm-title"><?= $studentAccounts ? '🔑 Accesso Pantedu' : '🔑 Accesso docenti' ?></h1>
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
        <?php /* Gestita da js/modules/features/password-visibility.js: nessun id
                 da coordinare qui, basta data-pw-toggle. Il campo torna nascosto
                 da solo alla submit e quando la scheda passa in background.
                 Stile: blocco .fm-pwtoggle in css/modules/_forms.css. */ ?>
        <label class="fm-pwtoggle">
            <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-login-pwd">
            <span class="fm-pwtoggle__text">Mostra password</span>
        </label>
        <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Entra</button>
        <p class="fm-text-center fm-text-13 fm-mt-2">
            <a class="fm-link" href="/password/forgot">Password dimenticata?</a>
        </p>
    </form>

    <?php if (!$studentAccounts): ?>
    <?php /* Scenari 1 e 2: gli studenti non hanno account. La credenziale di
             classe e' l'unica porta, e va detto qui, dove arrivano. */ ?>
    <div class="fm-login-divider fm-mt-4" aria-hidden="true"><span>studenti</span></div>
    <p class="fm-text-center fm-mt-2">
        <a class="fm-btn fm-btn--full fm-login-home" href="/accesso-classe" data-full-reload>🎓 Entra con la credenziale della classe</a>
    </p>
    <p class="fm-muted fm-text-13 fm-text-center">
        Gli studenti non hanno un account: usano la credenziale data dal docente, senza fornire dati personali.
    </p>
    <?php endif; ?>

    <?php if ($isInstitute): ?>
    <?php /* Phase D.2 — SPID + CIE placeholder buttons, solo nello scenario 3:
             l'identita' digitale ha senso per un'istanza condotta da un
             Istituto, non per la piattaforma di un singolo docente. Restano
             DISABILITATI finche' l'istanza non e' SP certificato AgID. */ ?>
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
    <?php endif; ?>

    <p class="fm-muted fm-mt-4 fm-text-center" >
        <?php if ($signupOpen): ?>
            Non hai un account? <a class="fm-link" href="/register">Registrati</a><br>
        <?php else: ?>
            Le iscrizioni non sono aperte: piattaforma in uso personale.<br>
        <?php endif; ?>
        <span class="fm-text-13">Problemi di accesso? <a class="fm-link" href="/password/forgot">Reimposta la password</a>
        oppure contatta l'amministratore.</span>
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
