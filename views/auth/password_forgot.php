<div class="fm-card fm-card--modal">
    <h1 class="fm-title">🔑 Password dimenticata</h1>

    <?php if (!empty($sent)): ?>
        <?php /* Messaggio identico che l'indirizzo esista o no: la pagina non
                 deve poter essere usata per scoprire chi ha un account. */ ?>
        <div class="fm-alert fm-alert--success" role="status">
            Se l'indirizzo corrisponde a un account attivo, riceverai un'email
            con il link per reimpostare la password. Vale un'ora e una volta sola.
        </div>
        <p class="fm-muted fm-text-13">
            Non arriva nulla? Controlla la posta indesiderata. L'indirizzo
            potrebbe anche non essere quello registrato sull'account.
        </p>
        <p class="fm-mt-4 fm-text-center">
            <a class="fm-btn fm-btn--ghost fm-btn--full" href="/login" data-full-reload>← Torna al login</a>
        </p>
    <?php else: ?>
        <p class="fm-muted fm-text-13">
            Inserisci l'indirizzo email associato al tuo account. Ti mandiamo
            un link per sceglierne una nuova.
        </p>

        <form method="post" action="/password/forgot" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="fm-label" for="fm-forgot-email">Email</label>
            <input id="fm-forgot-email" class="fm-input" type="email" name="email"
                   autocomplete="email" required autofocus>
            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Mandami il link</button>
        </form>

        <p class="fm-muted fm-text-13 fm-mt-4">
            Se hai attivato la <strong>verifica in due passaggi</strong>, ti verrà
            chiesta comunque al prossimo accesso: reimpostare la password non la
            disattiva.
        </p>

        <p class="fm-mt-4 fm-text-center">
            <a class="fm-btn fm-btn--ghost fm-btn--full" href="/login" data-full-reload>← Torna al login</a>
        </p>
    <?php endif; ?>
</div>
