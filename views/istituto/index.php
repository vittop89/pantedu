<?php
/** @var array|null $utente */
/** @var array|null $istituto */
?>
<div class="fm-card fm-card--form">
    <h1 class="fm-title">Il tuo istituto</h1>
    <p class="fm-muted fm-text-em-lg">
        Amministratore di istituto: <strong><?= e((string)($utente['username'] ?? '')) ?></strong>
    </p>

    <?php if ($istituto !== null): ?>
        <p>
            <strong><?= e((string)$istituto['name']) ?></strong><br>
            <span class="fm-muted">Codice meccanografico <?= e((string)$istituto['code']) ?><?php if (!empty($istituto['city'])): ?> · <?= e((string)$istituto['city']) ?><?php endif; ?></span>
        </p>
    <?php else: ?>
        <div class="fm-alert fm-alert--error">
            L'istituto che amministravi non c'è più. Questo account non ha niente da amministrare:
            chiedi a chi gestisce la piattaforma.
        </div>
    <?php endif; ?>

    <h2 class="fm-title">Che cosa puoi fare oggi</h2>
    <ul>
        <li><a href="/me/change-password">Cambiare la password</a></li>
        <li><a href="/me/2fa">Gestire il secondo fattore di accesso</a></li>
        <li><a href="/logout" data-full-reload>Uscire</a></li>
    </ul>

    <p class="fm-muted">
        Le funzioni sull'istituto (le registrazioni e l'elenco dei docenti, le classi ammesse
        all'iscrizione, dove si salvano le compilazioni) si aprono una alla volta:
        ognuna tocca dati personali di chi lavora e studia nell'istituto.
    </p>
</div>
