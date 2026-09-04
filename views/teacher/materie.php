<?php
/**
 * Scelta delle materie del docente.
 *
 * @var string $csrf
 * @var list<array{code:string,label:string}> $disponibili
 * @var list<string> $mie
 * @var array|null $flash
 */
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
$primaVolta = $mie === [];

$pageTitle    = 'PANTEDU — Le tue materie';
$bodyClass    = 'fm-area-docente-materie';
$currentRoute = '/area-docente/materie';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
<div class="fm-card fm-max-w-720 fm-mt-6">
    <h1 class="fm-mt-0"><?= $primaVolta ? 'Che materie insegni?' : 'Le tue materie' ?></h1>

    <?php if (!empty($flash)): ?>
        <div class="fm-alert fm-alert--<?= $h((string)$flash['type']) ?>" role="alert">
            <strong><?= $h((string)($flash['title'] ?? '')) ?></strong>
            <div><?= $h((string)($flash['message'] ?? '')) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($primaVolta): ?>
        <p class="fm-muted">
            Serve una volta sola. Senza materie i contenuti che pubblichi restano
            senza categoria, e i tuoi studenti non li trovano nell'elenco.
        </p>
        <p class="fm-muted fm-text-13">
            Finché non scegli, l'area docente riporta qui: non è un blocco, è che
            senza materie le altre pagine non avrebbero niente da mostrarti. Il
            profilo e l'uscita restano raggiungibili.
        </p>
    <?php endif; ?>
    <p class="fm-muted fm-text-13">
        L'elenco è quello della tua scuola, così com'è pubblicato dal MIUR. Se la tua materia
        non c'è, scegli quelle che puoi e segnalalo all'amministratore dell'istituto:
        aggiungerla è una sua operazione, non tua. Anche una spunta sbagliata la corregge lui —
        e togliere una materia non cancella nulla di ciò che hai pubblicato.
    </p>

    <form method="post" action="/area-docente/materie">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

        <?php if ($disponibili === []): ?>
            <div class="fm-alert fm-alert--warn" role="alert">
                <strong>La tua scuola non ha ancora un elenco di materie.</strong>
                Non è qualcosa che puoi sistemare da qui: scrivi all'amministratore dell'istituto.
            </div>
        <?php else: ?>
            <fieldset class="fm-mt-4">
                <legend class="fm-label">Materie</legend>
                <div class="fm-d-flex fm-gap-3 fm-flex-wrap fm-mt-2">
                    <?php foreach ($disponibili as $m): ?>
                        <?php $id = 'mia-' . $h($m['code']); ?>
                        <label class="fm-d-flex fm-gap-1 fm-items-center" for="<?= $id ?>">
                            <input type="checkbox" id="<?= $id ?>" name="materia[]"
                                   value="<?= $h($m['code']) ?>"
                                   <?= in_array($m['code'], $mie, true) ? 'checked' : '' ?>>
                            <?= $h($m['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="fm-d-flex fm-gap-2 fm-items-center fm-mt-6">
                <button type="submit" class="fm-btn fm-btn--primary">Salva</button>
                <?php if (!$primaVolta): ?>
                    <a class="fm-btn" href="/area-docente/dashboard">Annulla</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
</div>
</main>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
