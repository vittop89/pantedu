<?php
/**
 * ADR-032 — Accesso per la classe (scenari 1 e 2).
 *
 * Gli studenti non hanno un account: entrano con la credenziale che il
 * docente ha creato per la classe (teacher_access_credentials). Il POST va
 * all'endpoint gia' esistente /api/access/student-login, che mette il grant
 * in sessione; nessun dato personale dello studente viene raccolto.
 *
 * @var string $csrf
 * @var array<string,mixed> $scenario
 */
$controller = (string)($scenario['controller'] ?? '');
?>
<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🎓 Accesso per la classe</h1>
    <p class="fm-muted fm-text-em-md">
        Inserisci la credenziale che ti ha dato il docente. Non serve un account e
        non viene raccolto alcun dato personale: la sessione non è associata a te.
    </p>

    <div id="fm-class-access-error" class="fm-alert fm-alert--error" role="alert" hidden></div>

    <form id="fm-class-access-form" method="post" action="/api/access/student-login" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="fm-label" for="fm-ca-username">Credenziale della classe</label>
        <input id="fm-ca-username" class="fm-input" type="text" name="username"
               autocomplete="username" required minlength="3" autofocus>
        <label class="fm-label" for="fm-ca-pwd">Password</label>
        <input id="fm-ca-pwd" class="fm-input" type="password" name="password"
               autocomplete="current-password" required>
        <label class="fm-pwtoggle">
            <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-ca-pwd">
            <span class="fm-pwtoggle__text">Mostra password</span>
        </label>
        <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Entra</button>
    </form>

    <p class="fm-muted fm-mt-4 fm-text-center">
        Sei un docente? <a class="fm-link" href="/login">Accedi con il tuo account</a>
    </p>
    <p class="fm-mt-4 fm-text-center">
        <a class="fm-btn fm-btn--ghost fm-btn--full fm-login-home" href="/" data-full-reload>← Torna alla home</a>
    </p>
    <p class="fm-muted fm-text-13 fm-text-center fm-mt-2">
        <a class="fm-link" href="/privacy/informativa">Informativa privacy</a>
        <?php if ($controller !== ''): ?> · Titolare: <?= e($controller) ?><?php endif; ?>
    </p>
    <script>
    (function () {
        var form = document.getElementById('fm-class-access-form');
        var box  = document.getElementById('fm-class-access-error');
        if (!form || !box) return;
        var messages = {
            invalid_credentials:  'Credenziale non valida. Controlla quello che ti ha dato il docente.',
            missing_credentials:  'Inserisci credenziale e password.',
            two_factor_required:  'Questo è un account personale con verifica in due passaggi: entra da /login.',
            db_unavailable:       'Servizio momentaneamente non disponibile. Riprova tra poco.',
            rate_limited:         'Troppi tentativi: aspetta qualche minuto.'
        };
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            box.hidden = true;
            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                var r = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    body: new URLSearchParams(new FormData(form)).toString()
                });
                var j = {};
                try { j = await r.json(); } catch (_) {}
                if (r.ok && j.ok) {
                    window.location.href = '/';
                    return;
                }
                var code = j.error || (r.status === 429 ? 'rate_limited' : 'invalid_credentials');
                box.textContent = j.message || messages[code] || ('Accesso non riuscito (' + code + ').');
                box.hidden = false;
            } catch (_) {
                box.textContent = 'Connessione non riuscita. Riprova.';
                box.hidden = false;
            } finally {
                btn.disabled = false;
            }
        });
    })();
    </script>
</div>
