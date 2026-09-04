<?php
/**
 * Anteprima dell'import adozioni: cosa verrebbe scritto, prima di scriverlo.
 *
 * La pagina esiste perché i codici degli indirizzi sono PROPOSTI da
 * IndirizzoCodeDeriver, e una volta che i docenti ci hanno agganciato contenuti
 * cambiarli non è più una scelta ma una migrazione. Quindi la sigla si legge
 * adesso, non dopo.
 *
 * @var array<string,mixed> $plan
 * @var string $token
 * @var string $fileCaricato
 * @var string $filtro
 * @var int    $creato
 * @var string $csrf
 */

$h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);

$stats  = $plan['stats'] ?? ['indirizzi' => 0, 'sezioni' => 0, 'sistemate' => 0, 'illeggibili' => 0];
$totale = (int)$stats['indirizzi'] + (int)$stats['sezioni']
        + (int)($stats['materie'] ?? 0) + (int)$stats['sistemate'];

$etichetteStato = [
    'nuovo'           => ['+', 'indirizzo nuovo'],
    'alias-nuovo'     => ['+', 'indirizzo nuovo, sigla da alias'],
    'alias-unificato' => ['=', 'unificato con una voce già a registro'],
    'esistente'       => ['=', 'già a registro'],
    'non-derivabile'  => ['!', 'sigla non derivabile — va inserito a mano'],
];

$page_title = '📚 Anteprima import adozioni';
$breadcrumb = [
    ['href' => '/admin', 'label' => 'Admin'],
    ['href' => '/admin/institutes', 'label' => 'Istituti'],
];
$back_href  = '/admin/institutes';
$back_label = '← Torna agli istituti';
include __DIR__ . '/_partials/page_head.php';
?>

<section class="fm-admin-kpi">
    <h2 class="fm-admin-kpi__title">Nessuna modifica è stata scritta</h2>
    <p class="fm-muted fm-text-13">
        File: <span class="fm-code"><?= $h($fileCaricato) ?></span> ·
        <?= number_format((int)($plan['righe'] ?? 0), 0, ',', '.') ?> righe lette ·
        <?= count($plan['istituti'] ?? []) ?> scuole con corrispondenza
        <?= $filtro !== '' ? ' · filtro: <span class="fm-code">' . $h($filtro) . '</span>' : '' ?>
        · letto il <?= $h(date('d/m/Y H:i', $creato)) ?>
    </p>
</section>

<section class="fm-mt-4">
    <div class="fm-alert fm-alert--<?= $totale > 0 ? 'info' : 'success' ?>">
        <strong>
            Indirizzi nuovi: <?= (int)$stats['indirizzi'] ?> ·
            Sezioni nuove: <?= (int)$stats['sezioni'] ?> ·
            Materie nuove: <?= (int)($stats['materie'] ?? 0) ?> ·
            Sezioni a cui verrebbe assegnato l'indirizzo: <?= (int)$stats['sistemate'] ?>
        </strong>
        <?php if ((int)$stats['illeggibili'] > 0): ?>
            <div class="fm-text-13">
                <?= (int)$stats['illeggibili'] ?> descrizione/i senza sigla derivabile: vengono
                saltate, e con esse le loro sezioni. Sono segnate con <code>!</code> qui sotto.
            </div>
        <?php endif; ?>
        <?php if ($totale === 0): ?>
            <div class="fm-text-13">Il registro è già allineato al file: non c'è niente da scrivere.</div>
        <?php endif; ?>
    </div>
</section>

<?php foreach (($plan['istituti'] ?? []) as $inst): ?>
    <section class="fm-mt-8">
        <h3 class="fm-card__title">
            <span class="fm-code"><?= $h((string)$inst['code']) ?></span>
            <?= $h((string)$inst['name']) ?>
        </h3>
        <table class="fm-table">
            <thead>
                <tr>
                    <th scope="col"></th>
                    <th scope="col">Descrizione MIUR</th>
                    <th scope="col">Etichetta mostrata</th>
                    <th scope="col">Sigla</th>
                    <th scope="col">Sezioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inst['indirizzi'] as $ind): ?>
                    <?php
                    $stato = (string)$ind['stato'];
                    [$segno, $spiega] = $etichetteStato[$stato] ?? ['·', $stato];
                    $nuovo = $stato === 'nuovo' || $stato === 'alias-nuovo';
                    ?>
                    <tr>
                        <td title="<?= $h($spiega) ?>"><strong><?= $h($segno) ?></strong></td>
                        <td class="fm-text-13"><?= $h((string)$ind['descrizione']) ?></td>
                        <td>
                                <?= $h((string)$ind['label']) ?>
                                <?php if (($ind['label_proposta'] ?? null) !== null): ?>
                                    <div class="fm-muted fm-text-13">
                                        il registro alias propone
                                        <em><?= $h((string)$ind['label_proposta']) ?></em>,
                                        ma l'import non riscrive le etichette esistenti
                                    </div>
                                <?php endif; ?>
                            </td>
                        <td>
                            <?php if ($ind['code'] === null): ?>
                                <span class="fm-muted">—</span>
                            <?php else: ?>
                                <span class="fm-code"><?= $h((string)$ind['code']) ?></span>
                            <?php endif; ?>
                            <?php if ($nuovo): ?>
                                <span class="fm-badge fm-badge--severity-medium" title="<?= $h($spiega) ?>">nuovo</span>
                            <?php endif; ?>
                        </td>
                        <td class="fm-text-13">
                            <?php if ($ind['sezioni_nuove'] !== []): ?>
                                <div>+ <?= $h(implode(' ', $ind['sezioni_nuove'])) ?></div>
                            <?php endif; ?>
                            <?php if ($ind['sezioni_sistemate'] !== []): ?>
                                <div class="fm-muted">
                                    indirizzo assegnato a: <?= $h(implode(' ', $ind['sezioni_sistemate'])) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($ind['sezioni_nuove'] === [] && $ind['sezioni_sistemate'] === []): ?>
                                <span class="fm-muted">nessuna modifica</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (($inst['materie'] ?? []) !== []): ?>
            <h4 class="fm-mt-4">Materie</h4>
            <p class="fm-muted fm-text-13 fm-mt-0">
                Restano a livello di istituto, senza corso: una Matematica insegnata
                all'artistico e una insegnata allo scientifico sono la stessa materia.
            </p>
            <table class="fm-table">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col">Descrizione MIUR</th>
                        <th scope="col">Etichetta mostrata</th>
                        <th scope="col">Sigla</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inst['materie'] as $m): ?>
                        <?php
                        $stato = (string)$m['stato'];
                        [$segno, $spiega] = $etichetteStato[$stato] ?? ['·', $stato];
                        $nuovo = $stato === 'nuovo' || $stato === 'alias-nuovo';
                        ?>
                        <tr>
                            <td title="<?= $h($spiega) ?>"><strong><?= $h($segno) ?></strong></td>
                            <td class="fm-text-13"><?= $h((string)$m['descrizione']) ?></td>
                            <td>
                                <?= $h((string)$m['label']) ?>
                                <?php if (($m['label_proposta'] ?? null) !== null): ?>
                                    <div class="fm-muted fm-text-13">
                                        il registro alias propone
                                        <em><?= $h((string)$m['label_proposta']) ?></em>,
                                        ma l'import non riscrive le etichette esistenti
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m['code'] === null): ?>
                                    <span class="fm-muted">—</span>
                                <?php else: ?>
                                    <span class="fm-code"><?= $h((string)$m['code']) ?></span>
                                <?php endif; ?>
                                <?php if ($nuovo): ?>
                                    <span class="fm-badge fm-badge--severity-medium">nuova</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="fm-mt-8">
    <p class="fm-muted fm-text-13 fm-mb-3">
        Le sigle non compaiono mai nell'interfaccia — lo studente legge l'etichetta. Servono come
        chiave interna e restano stabili mentre le etichette cambiano. Se una proposta non va bene,
        correggila in <span class="fm-code">docs/curriculum/miur_alias.json</span> e ricarica il
        file: dopo l'applicazione, cambiarla diventa una migrazione.
    </p>
    <div class="fm-d-flex fm-gap-2 fm-items-center">
        <form method="post" action="/admin/institutes/adozioni/apply">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="plan_token" value="<?= $h($token) ?>">
            <button type="submit" class="fm-btn fm-btn--primary"<?= $totale === 0 ? ' disabled' : '' ?>>
                ✅ Applica queste <?= $totale ?> modifiche
            </button>
        </form>
        <a class="fm-btn" href="/admin/institutes">Annulla</a>
    </div>
</section>

</div><!-- /.fm-card -->
