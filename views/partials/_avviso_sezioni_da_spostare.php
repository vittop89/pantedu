<?php
/**
 * ADR-041 — i materiali del docente rimasti su classi che non può più usare:
 * sezioni e, da ADR-043, anni di un corso senza incarico.
 *
 * Nel cruscotto e in «Sposta di classe». Dice quanti sono, dove, e la data se
 * l'amministratore ne ha messa una: dopo, li porta sull'anno lui.
 *
 * I collegamenti hanno `fm-underline` e non `fm-link-inline`: nelle pagine con
 * il layout `app.php` la seconda perde contro `a{text-decoration:none}` degli
 * stili vecchi, e axe lo segnala (link-in-text-block, misurato il 14/9/2026).
 *
 * @var list<array{institute_id:int,istituto:string,scadenza:?string,
 *                 sezioni:list<array{id:int,code:string,materiali:int}>}> $avvisoSezioni
 */
if (empty($avvisoSezioni)) {
    return;
}
$_h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
foreach ($avvisoSezioni as $_a):
    $_totale = array_sum(array_column($_a['sezioni'], 'materiali'));
    $_data = null;
    if ($_a['scadenza'] !== null) {
        $_d = \DateTimeImmutable::createFromFormat('!Y-m-d', $_a['scadenza']);
        $_data = $_d !== false ? $_d->format('d/m/Y') : $_a['scadenza'];
    }
    ?>
    <div class="fm-alert fm-alert--warn" role="status" data-avviso-sezioni="<?= (int)$_a['institute_id'] ?>">
        <strong>
            <?= $_totale === 1 ? 'Hai 1 materiale' : 'Hai ' . (int)$_totale . ' materiali' ?>
            su classi di cui non hai l'incarico<?= count($avvisoSezioni) > 1 ? ' (' . $_h($_a['istituto']) . ')' : '' ?>.
        </strong>
        <div>
            Dai tuoi menù non li raggiungi più. Portali in una classe di cui hai l'incarico:
            <?php foreach ($_a['sezioni'] as $_i => $_s): ?><?= $_i > 0 ? ', ' : '' ?><a class="fm-underline" href="/area-docente/sposta-di-classe?classe=<?= (int)$_s['id'] ?>"><?= $_h($_s['code']) ?></a> (<?= (int)$_s['materiali'] ?>)<?php endforeach; ?>.
            <?php if ($_data !== null): ?>
                Entro il <strong><?= $_h($_data) ?></strong>: dopo, quelli sulle sezioni li porta sull'anno l'amministratore.
            <?php endif; ?>
        </div>
    </div>
<?php endforeach;
unset($_a, $_s, $_i, $_d, $_data, $_totale, $_h);
