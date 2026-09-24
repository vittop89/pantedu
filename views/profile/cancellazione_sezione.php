<?php
/**
 * «I tuoi dati» (/privacy/your-data), per chi è entrato: la cancellazione
 * dell'account (24/9/2026). Senza richieste in corso, il modulo per
 * chiederla, con i passi; con una richiesta in corso, il suo stato e il
 * modulo per annullarla. Vedi TrustPagesController::sezioneCancellazione.
 *
 * @var string $csrf
 * @var array<string, mixed>|null $richiesta DeletionRequestService::activeRequest()
 * @var int $giorniCollegamento
 * @var int $giorniRipensamento
 */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$giorno = static function (mixed $quando): string {
    $t = \is_string($quando) && $quando !== '' ? strtotime($quando) : false;
    return $t !== false ? date('d/m/Y', $t) : '';
};
?>
<h2 id="cancellazione">Cancellare l'account (Art. 17)</h2>
<?php if ($richiesta === null): ?>
    <p>
        Come funziona: ti mandiamo un'email all'indirizzo del tuo account con un collegamento, che vale
        <?= (int)$giorniCollegamento ?> giorni. Aprendolo trovi un pulsante per confermare la cancellazione.
        Dopo la conferma la cancellazione viene eseguita fra <?= (int)$giorniRipensamento ?> giorni, e fino
        ad allora la puoi annullare da questa pagina.
    </p>
    <form method="post" action="/me/request-deletion">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <button type="submit" class="fm-btn fm-btn--danger">Chiedi la cancellazione dell'account</button>
    </form>
<?php else: ?>
    <?php if (($richiesta['status'] ?? '') === 'cooling_off'): ?>
        <p>
            La cancellazione del tuo account è confermata e sarà eseguita il
            <strong><?= $h($giorno($richiesta['execute_after'] ?? null)) ?></strong>. Fino ad allora puoi annullarla.
        </p>
    <?php else: ?>
        <p>
            Hai chiesto la cancellazione dell'account il <?= $h($giorno($richiesta['requested_at'] ?? null)) ?>.
            Per confermarla apri il collegamento dell'email di conferma entro <?= (int)$giorniCollegamento ?>
            giorni dalla richiesta: finché non la confermi non succede niente, e la richiesta scade da sola.
        </p>
    <?php endif; ?>
    <form method="post" action="/me/cancel-deletion">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <button type="submit" class="fm-btn fm-btn--primary">Annulla la richiesta di cancellazione</button>
    </form>
<?php endif; ?>
