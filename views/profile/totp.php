<?php
/** @var string $csrf */
/** @var array $user */
/** @var bool $totp_enabled */
/** @var ?string $enrolled_at */
/** @var bool $required */
/** @var ?array $pending */
/** @var ?string $qr_svg */
/** @var ?string $metodo */
/** @var bool $email_pending */
/** @var ?string $indirizzo */
/** @var ?array $flash */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="fm-card fm-card--form">
    <h1 class="fm-m-0 fm-mb-4">📱 Verifica in due passaggi</h1>

    <?php if ($flash): ?>
        <?php /* role=status: il messaggio compare dopo un'azione, e chi usa uno
                 screen reader deve sentirlo senza andarlo a cercare. */ ?>
        <div class="fm-alert fm-alert--<?= $flash['type'] === 'ok' ? 'ok' : 'warn' ?> fm-mb-4" role="status">
            <?= $h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($required && !$totp_enabled): ?>
        <div class="fm-alert fm-alert--warn fm-mb-4" role="status">
            Per il tuo ruolo la verifica in due passaggi è <strong>obbligatoria</strong>.
            Finché non la attivi non puoi usare il resto dell'applicativo.
        </div>
    <?php endif; ?>

    <?php if ($totp_enabled): ?>
        <p>
            <strong class="fm-text-success">✓ Attiva</strong>
            dal <?= $h($enrolled_at ?? '?') ?>,
            <?php if (($metodo ?? 'app') === 'email'): ?>
                con il codice inviato <strong>via email</strong><?= $indirizzo ? ' a ' . $h($indirizzo) : '' ?>.
            <?php else: ?>
                con l'<strong>app di autenticazione</strong>.
            <?php endif; ?>
            Al login ti viene chiesto un codice a sei cifre.
        </p>

        <?php if (!empty($_SESSION['totp_backups_once'])): ?>
            <?php /* Mostrati una volta sola, subito dopo l'attivazione via
                     email: il percorso con l'app li mostra durante
                     l'iscrizione, questo no perche' non passa dal QR. */ ?>
            <h3 class="fm-text-15 fm-mt-6 fm-text-danger">⚠️ Codici di backup</h3>
            <p class="fm-text-14">
                <strong>Salvali ora, fuori da questo computer.</strong> Servono
                se la casella diventa irraggiungibile. Ognuno vale una volta
                sola, e non potrai più rivederli.
            </p>
            <pre class="fm-keybox"><?php
                foreach ($_SESSION['totp_backups_once'] as $c) { echo $h($c) . "\n"; }
                unset($_SESSION['totp_backups_once']);
            ?></pre>
        <?php endif; ?>

        <hr class="fm-my-6">
        <h2 class="fm-text-17">Disattiva la verifica</h2>
        <p class="fm-muted fm-text-13">
            Serve la password attuale: disattivare un fattore di sicurezza non
            deve essere possibile a chi trovi la sessione aperta.
        </p>
        <form method="POST" action="/me/2fa/disable">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label class="fm-label" for="fm-2fa-pwd">Password attuale</label>
            <input id="fm-2fa-pwd" type="password" name="current_password" required
                   autocomplete="current-password" class="fm-input fm-max-w-280">
            <label class="fm-pwtoggle">
                <input class="fm-pwtoggle__input" type="checkbox" data-pw-toggle="fm-2fa-pwd">
                <span class="fm-pwtoggle__text">Mostra password</span>
            </label>
            <button type="submit" class="fm-btn fm-btn--danger">Disattiva</button>
        </form>

    <?php elseif (!empty($email_pending)): ?>
        <h2 class="fm-text-17">Conferma il codice che ti ho mandato</h2>
        <p>
            Ho spedito un codice a sei cifre
            <?php if (!empty($indirizzo)): ?>a <strong><?= $h($indirizzo) ?></strong><?php endif; ?>.
            Vale dieci minuti. Inseriscilo qui per attivare la verifica.
        </p>
        <form method="POST" action="/me/2fa/enable-email">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label class="fm-label" for="fm-2fa-mailcode">Codice ricevuto</label>
            <input id="fm-2fa-mailcode" type="text" name="code" required inputmode="numeric"
                   maxlength="6" placeholder="123456" class="fm-input-otp"
                   autocomplete="one-time-code" autofocus>
            <button type="submit" class="fm-btn fm-btn--primary">Verifica e attiva</button>
        </form>
        <?php /* Il form sta FUORI dal <p>: un <form> dentro un paragrafo viene
                 spostato dal parser, che chiude il <p> prima — markup valido,
                 resa imprevedibile. */ ?>
        <p class="fm-muted fm-text-13 fm-mt-4 fm-mb-1">
            Non è arrivato? Controlla la posta indesiderata.
        </p>
        <form method="POST" action="/me/2fa/setup-email">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Richiedine un altro</button>
        </form>

    <?php elseif ($pending): ?>
        <h2 class="fm-text-17">Inquadra il codice e conferma</h2>
        <p>Apri la tua app di autenticazione e inquadra questo codice:</p>

        <?php /* 2026-09-01 — il QR si genera qui (App\Services\Security\QrCode).
                 Prima veniva da `quickchart.io/qr?text=<otpauth-uri>`, e
                 quell'URI CONTIENE IL SEGRETO: ogni attivazione lo spediva, in
                 chiaro e dentro una query string, a un servizio statunitense
                 che non compare in alcun elenco di sub-responsabili. */ ?>
        <div class="fm-qr-card"><?= $qr_svg ?? '' ?></div>

        <p>Se non riesci a inquadrarlo, inserisci a mano questo codice:</p>
        <code class="fm-keybox fm-text-em-xl fm-ls-wider"><?= $h($pending['secret']) ?></code>

        <h3 class="fm-text-15 fm-mt-6 fm-text-danger">⚠️ Codici di backup</h3>
        <p class="fm-text-14">
            <strong>Salvali ora, fuori da questo computer.</strong> Sono l'unico
            modo di rientrare se perdi il telefono. Ognuno vale una volta sola,
            e non potrai più rivederli dopo questa pagina.
        </p>
        <pre class="fm-keybox"><?php
            foreach ($pending['backups'] as $code) {
                echo $h($code) . "\n";
            }
        ?></pre>

        <h3 class="fm-text-15 fm-mt-6">Conferma il codice</h3>
        <form method="POST" action="/me/2fa/enable">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label class="fm-label" for="fm-2fa-code">Codice a sei cifre</label>
            <input id="fm-2fa-code" type="text" name="code" required inputmode="numeric"
                   maxlength="6" placeholder="123456" class="fm-input-otp"
                   autocomplete="one-time-code" autofocus>
            <button type="submit" class="fm-btn fm-btn--primary">Verifica e attiva</button>
        </form>

    <?php else: ?>
        <p>Non è attiva sul tuo account.</p>
        <p>
            Aggiunge un secondo controllo al login: oltre alla password ti viene
            chiesto un codice a sei cifre, generato dal telefono e valido trenta
            secondi. Chi ruba la password, da sola, non entra.
        </p>
        <h2 class="fm-text-17 fm-mt-6">Come vuoi ricevere il codice?</h2>

        <div class="fm-2fa-choice">
            <section class="fm-2fa-choice__option">
                <h3 class="fm-text-15 fm-mt-0">📱 App di autenticazione <span class="fm-badge">consigliata</span></h3>
                <p class="fm-text-14">
                    Il codice lo genera il telefono, senza rete e senza passare
                    da nessuna parte. È il metodo che protegge di più.
                </p>
                <p class="fm-muted fm-text-13">
                    Google Authenticator, Authy, Microsoft Authenticator,
                    Bitwarden, 1Password, Aegis (Android), Raivo (iOS).
                </p>
                <form method="POST" action="/me/2fa/setup">
                    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                    <button type="submit" class="fm-btn fm-btn--primary">Usa l'app</button>
                </form>
            </section>

            <section class="fm-2fa-choice__option">
                <h3 class="fm-text-15 fm-mt-0">✉️ Email</h3>
                <p class="fm-text-14">
                    Il codice arriva <?= $indirizzo ? 'a <strong>' . $h($indirizzo) . '</strong>' : 'nella tua casella' ?>
                    ogni volta che accedi. Serve solo la posta, nessuna app da
                    installare.
                </p>
                <?php /* Il compromesso va detto qui, dove si sceglie, non
                         sepolto in una pagina di aiuto: e' l'unico momento in
                         cui l'informazione cambia una decisione. */ ?>
                <p class="fm-alert fm-alert--warn fm-text-13">
                    <strong>Protegge meno.</strong> Anche il recupero password
                    passa da questa casella: chi ne prendesse il controllo
                    avrebbe entrambi i fattori. Resta comunque molto meglio
                    della sola password — ma se hai uno smartphone, scegli l'app.
                </p>
                <?php if (empty($indirizzo)): ?>
                    <p class="fm-muted fm-text-13">
                        Sul tuo account non risulta un indirizzo email: aggiungilo
                        prima di poter usare questo metodo.
                    </p>
                <?php else: ?>
                    <form method="POST" action="/me/2fa/setup-email">
                        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                        <button type="submit" class="fm-btn fm-btn--ghost">Usa l'email</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <p class="fm-mt-8">
        <a class="fm-link" href="/area-docente/profilo">← Torna al profilo</a>
        &nbsp;·&nbsp;
        <a class="fm-link" href="/me/change-password">Cambia password</a>
    </p>
</div>
