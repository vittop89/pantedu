<?php
/** @var string $csrf */
/** @var array $user */
/** @var bool $totp_enabled */
/** @var ?string $enrolled_at */
/** @var bool $master_enabled */
/** @var ?array $pending */
/** @var ?array $flash */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<div class="fm-card">
    <h1 class="fm-m-0 fm-mb-4">🔐 Autenticazione a 2 fattori (2FA)</h1>

    <?php if ($flash): ?>
        <div class="fm-alert fm-alert--<?= $flash['type'] === 'ok' ? 'ok' : 'warn' ?>"
             class="fm-mb-4">
            <?= $h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$master_enabled): ?>
        <div class="fm-alert fm-alert--warn fm-mb-4" >
            ⚠️ 2FA non è attivato globalmente (admin config <code>security.totp_enabled</code>).
            Puoi configurarlo ora, ma il check al login partirà solo quando l'admin attiva il toggle.
            Gli utenti già enrolled continueranno comunque a verificare il codice al login.
        </div>
    <?php endif; ?>

    <?php if ($totp_enabled): ?>
        <p>
            <strong class="fm-text-success">✓ 2FA attivo</strong> dal <?= $h($enrolled_at ?? '?') ?>.
        </p>
        <p>
            Al prossimo login dovrai inserire il codice 6-cifre generato dalla tua app
            (Google Authenticator, Authy, Bitwarden, 1Password, ecc.).
        </p>
        <hr class="fm-my-6">
        <h2 class="fm-text-17">Disabilita 2FA</h2>
        <form method="POST" action="/me/2fa/disable">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <p>
                <label>Password attuale (richiesta per conferma):<br>
                <input type="password" name="current_password" required class="fm-w-full fm-max-w-280 fm-input">
                </label>
            </p>
            <button type="submit" class="fm-btn fm-btn--danger">🚫 Disabilita 2FA</button>
        </form>

    <?php elseif ($pending): ?>
        <h2 class="fm-text-17">Step 2: Scansiona QR e inserisci codice</h2>
        <p>Scansiona questo QR con la tua app Authenticator:</p>
        <div class="fm-qr-card">
            <img alt="QR TOTP" width="180" height="180"
                 src="https://quickchart.io/qr?text=<?= $h(rawurlencode($pending['uri'])) ?>&size=180">
        </div>
        <p>Oppure inserisci manualmente questo secret:</p>
        <code class="fm-keybox fm-text-em-xl fm-ls-wider">
            <?= $h($pending['secret']) ?>
        </code>

        <h3 class="fm-text-15 fm-mt-6 fm-text-danger">⚠️ Backup codes (single-use)</h3>
        <p class="fm-text-14">
            Salvali OFFLINE prima di proseguire. Permettono accesso se perdi il telefono.
            Ogni codice funziona 1 sola volta.
        </p>
        <pre class="fm-keybox"><?php
            foreach ($pending['backups'] as $code) {
                echo $h($code) . "\n";
            }
        ?></pre>

        <h3 class="fm-text-15 fm-mt-6">Verifica codice corrente</h3>
        <form method="POST" action="/me/2fa/enable">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="text" name="code" required inputmode="numeric" pattern="\d{6}" maxlength="6"
                   placeholder="123456"
                   class="fm-input-otp"
                   autocomplete="one-time-code" autofocus>
            <button type="submit" class="fm-btn fm-btn--primary">✓ Verifica e attiva</button>
        </form>

    <?php else: ?>
        <p>2FA non è attualmente attivato sul tuo account.</p>
        <p>Aggiunge un livello di sicurezza richiedendo, al login, un codice
            6-cifre generato dalla tua app smartphone oltre alla password.</p>
        <p><strong>App compatibili</strong>: Google Authenticator, Authy, Microsoft
            Authenticator, Bitwarden, 1Password, Aegis (Android), Raivo (iOS).</p>
        <form method="POST" action="/me/2fa/setup">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <button type="submit" class="fm-btn fm-btn--primary">🔐 Configura 2FA</button>
        </form>
    <?php endif; ?>

    <p class="fm-mt-8">
        <a class="fm-link" href="/me/change-password">← Profilo</a>
    </p>
</div>
