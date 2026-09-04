<div class="fm-card fm-card--modal">
    <h1 class="fm-title">📱 Verifica in due passaggi</h1>

    <?php /* role=alert: l'errore arriva dopo un invio, e chi usa uno screen
             reader deve sentirlo senza andarlo a cercare (WCAG 3.3.1). */ ?>
    <?php if (!empty($error)): ?>
        <div class="fm-alert fm-alert--error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (($metodo ?? 'app') === 'email'): ?>
        <p class="fm-muted fm-text-13" id="fm-totp-help">
            Ti abbiamo mandato un codice a sei cifre
            <?php if (!empty($indirizzo)): ?>
                all'indirizzo <strong><?= e($indirizzo) ?></strong>
            <?php endif; ?>.
            Vale dieci minuti e una volta sola. In alternativa puoi inserire
            uno dei codici di backup.
        </p>
    <?php else: ?>
        <p class="fm-muted fm-text-13" id="fm-totp-help">
            Apri l'app di autenticazione e inserisci il codice a sei cifre.
            Cambia ogni 30 secondi. In alternativa puoi inserire uno dei codici
            di backup.
        </p>
    <?php endif; ?>

    <form method="post" action="/login/2fa" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="fm-label" for="fm-totp-code">Codice</label>
        <?php /* inputmode=numeric + autocomplete=one-time-code: su telefono
                 apre il tastierino e, su iOS/Android, propone il codice
                 ricevuto senza farlo ricopiare a mano. Nessun maxlength: un
                 codice di backup e' piu' lungo di sei caratteri e passa dallo
                 stesso campo. */ ?>
        <?php /* Niente `pattern`: un vincolo che rifiuta senza spiegare e' peggio
                 di nessun vincolo (WCAG 3.3.1/3.3.3), e da questo stesso campo
                 passano sia le sei cifre sia i codici di backup, che hanno un
                 formato diverso. La validazione la fa il server, che sa dire
                 perche'. `autocomplete=one-time-code` e nessun blocco
                 dell'incolla: WCAG 2.2 AA 3.3.8 chiede che il codice non debba
                 essere ricopiato a memoria. */ ?>
        <input id="fm-totp-code" class="fm-input" type="text" name="code"
               inputmode="numeric" autocomplete="one-time-code"
               aria-describedby="fm-totp-help"
               placeholder="123456" required autofocus>
        <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Verifica</button>
    </form>

    <p class="fm-muted fm-text-13 fm-mt-4">
        <strong><?= ($metodo ?? 'app') === 'email' ? 'Non arriva niente?' : 'Hai perso il telefono?' ?></strong>
        <?php if (($metodo ?? 'app') === 'email'): ?>
            Controlla la posta indesiderata. Puoi anche inserire qui uno dei
        <?php else: ?>
            Inserisci qui uno dei
        <?php endif; ?>
        <em>codici di backup</em> che hai salvato quando hai attivato la
        verifica: vale una volta sola e poi si consuma.
        Se li hai finiti, scrivi a
        <a class="fm-link" href="/dpo-contact">assistenza</a>.
    </p>

    <p class="fm-mt-4 fm-text-center">
        <a class="fm-btn fm-btn--ghost fm-btn--full" href="/logout" data-full-reload>← Annulla e torna al login</a>
    </p>
</div>
