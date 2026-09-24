<?php
/**
 * Il catalogo di un istituto: il vocabolario con la provenienza di ogni voce,
 * quanti docenti l'hanno spuntata e quanti contenuti ci puntano (ADR-035).
 *
 * @var array<string,mixed> $istituto
 * @var array{indirizzi:list<array<string,mixed>>,classi:list<array<string,mixed>>,materie:list<array<string,mixed>>} $catalogo
 * @var array{type:string,title:string,message:string}|null $flash
 * @var string $csrf
 */

$h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
$iid = (int)$istituto['id'];

$origini = [
    'miur'     => ['🏛️ MIUR',     'dal dataset delle adozioni'],
    'istituto' => ['🏫 scuola',   'messa dall\'amministratore'],
    'docente'  => ['👤 docente',  'promossa dalla copia di un docente: da rivedere'],
];
$titoli = ['indirizzi' => 'Indirizzi', 'classi' => 'Classi e sezioni', 'materie' => 'Materie'];
$daRivedere = 0;
foreach ($catalogo as $voci) {
    foreach ($voci as $v) {
        if (($v['origine'] ?? '') === 'docente') {
            $daRivedere++;
        }
    }
}

$page_title = '📚 Catalogo — ' . (string)$istituto['name'];
$breadcrumb = [
    ['href' => '/admin', 'label' => 'Admin'],
    ['href' => '/admin/institutes', 'label' => 'Istituti'],
    ['label' => 'Catalogo'],
];
$back_href  = '/admin/institutes';
$back_label = '← Torna agli istituti';
include __DIR__ . '/_partials/page_head.php';
?>

<?php if (is_array($flash ?? null)): ?>
    <div class="fm-alert fm-alert--<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
        <strong><?= $h($flash['title'] ?? '') ?></strong> <?= $h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="fm-admin-kpi">
    <h2 class="fm-admin-kpi__title"><span class="fm-code"><?= $h((string)$istituto['code']) ?></span> <?= $h((string)$istituto['name']) ?></h2>
    <p class="fm-muted fm-text-13">
        Il vocabolario è della scuola: una voce per codice. I docenti la spuntano dal profilo e
        vedono l'etichetta che scelgono loro; quella qui sotto la vedono tutti gli altri
        (studenti in registrazione, selettori, stampe). Ogni modifica va a registro con la motivazione.
        <?php if ($daRivedere > 0): ?>
            <br><strong><?= $daRivedere ?></strong> voce/i con origine «docente»: erano copie di un docente
            senza una voce della scuola, promosse dalla migrazione. Vanno accettate o tolte.
        <?php endif; ?>
    </p>
</section>

<?php foreach ($catalogo as $kind => $voci): ?>
    <section class="fm-mt-6" id="catalogo-<?= $h($kind) ?>">
        <h3 class="fm-card__title"><?= $h($titoli[$kind] ?? $kind) ?> <span class="fm-muted fm-text-13">(<?= count($voci) ?>)</span></h3>
        <?php if ($voci === []): ?>
            <p class="fm-muted fm-text-13">Nessuna voce. Si importano dal dataset MIUR delle adozioni, dalla pagina degli istituti.</p>
        <?php else: ?>
        <table class="fm-table">
            <thead>
                <tr>
                    <th scope="col">Sigla</th>
                    <th scope="col">Etichetta</th>
                    <?php if ($kind === 'classi'): ?><th scope="col">Corso</th><?php endif; ?>
                    <th scope="col">Origine</th>
                    <th scope="col" class="fm-text-right">Docenti</th>
                    <th scope="col" class="fm-text-right">Contenuti</th>
                    <th scope="col">Stato</th>
                    <th scope="col">Modifica</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($voci as $v): ?>
                <?php
                $origine = (string)($v['origine'] ?? 'istituto');
                [$badge, $spiega] = $origini[$origine] ?? [$origine, ''];
                $usata = (int)$v['docenti'] > 0 || (int)$v['contenuti'] > 0;
                ?>
                <tr data-voce="<?= (int)$v['id'] ?>" data-origine="<?= $h($origine) ?>"<?= $v['active'] ? '' : ' class="fm-muted"' ?>>
                    <td><span class="fm-code"><?= $h((string)$v['code']) ?></span></td>
                    <td><?= $h((string)$v['label']) ?><?php if (!empty($v['group'])): ?> <span class="fm-muted fm-text-13">· <?= $h((string)$v['group']) ?></span><?php endif; ?></td>
                    <?php if ($kind === 'classi'): ?><td><?= $v['indirizzo'] !== null ? '<span class="fm-code">' . $h((string)$v['indirizzo']) . '</span>' : '<span class="fm-muted">nessuno</span>' ?></td><?php endif; ?>
                    <td title="<?= $h($spiega) ?>"><?= $h($badge) ?></td>
                    <td class="fm-text-right"><?= (int)$v['docenti'] ?></td>
                    <td class="fm-text-right"><?= (int)$v['contenuti'] ?></td>
                    <td><?= $v['active'] ? 'attiva' : 'disattivata' ?></td>
                    <td>
                        <form method="post" action="/admin/institutes/<?= $iid ?>/catalogo/<?= (int)$v['id'] ?>" class="fm-d-flex fm-items-center fm-gap-1 fm-flex-wrap">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="text" name="label" class="fm-input" value="<?= $h((string)$v['label']) ?>" maxlength="120" aria-label="Nuova etichetta di <?= $h((string)$v['code']) ?>" style="max-width:14em">
                            <input type="text" name="_audit_reason" class="fm-input" placeholder="motivazione (10-255 caratteri)" minlength="10" maxlength="255" required aria-label="Motivazione per <?= $h((string)$v['code']) ?>" style="max-width:16em">
                            <button type="submit" name="azione" value="rinomina" class="fm-btn fm-btn--sm">Rinomina</button>
                            <button type="submit" name="azione" value="<?= $v['active'] ? 'disattiva' : 'attiva' ?>" class="fm-btn fm-btn--sm"><?= $v['active'] ? 'Disattiva' : 'Attiva' ?></button>
                            <?php if ($origine === 'docente'): ?>
                                <button type="submit" name="azione" value="accetta" class="fm-btn fm-btn--sm fm-btn--primary" title="Diventa una voce della scuola">Accetta</button>
                            <?php endif; ?>
                            <?php if (!$usata): ?>
                                <button type="submit" name="azione" value="elimina" class="fm-btn fm-btn--sm fm-btn--danger">Elimina</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
        // Una voce che il dataset MIUR non porta: una sezione aperta a settembre,
        // una materia di indirizzo. La sigla è la chiave: 3-6 lettere per corsi
        // e materie, anno più sezione per le classi («2A», «3BS»). Un anno solo
        // («3») chiede il corso: ne esiste uno per indirizzo (ADR-042).
        $sigla = $kind === 'classi' ? '[1-9][A-Za-z0-9]{0,5}' : '[A-Za-z]{3,6}';
        $esempio = $kind === 'classi' ? 'es. 2A' : ($kind === 'materie' ? 'es. SCN' : 'es. SCI');
        ?>
        <form method="post" action="/admin/institutes/<?= $iid ?>/catalogo" class="fm-d-flex fm-items-center fm-gap-1 fm-flex-wrap fm-mt-2" id="catalogo-aggiungi-<?= $h($kind) ?>">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="kind" value="<?= $h($kind) ?>">
            <input type="text" name="code" class="fm-input" placeholder="sigla (<?= $esempio ?>)" pattern="<?= $sigla ?>" required aria-label="Sigla della nuova voce in <?= $h($titoli[$kind] ?? $kind) ?>" style="max-width:9em">
            <input type="text" name="label" class="fm-input" placeholder="etichetta" maxlength="120" required aria-label="Etichetta della nuova voce in <?= $h($titoli[$kind] ?? $kind) ?>" style="max-width:14em">
            <?php if ($kind === 'classi'): ?>
                <input type="text" name="indirizzo" class="fm-input" placeholder="corso (sigla)" pattern="[A-Za-z]{3,6}" aria-label="Corso della nuova classe, obbligatorio per un anno" title="Obbligatorio per un anno («3»): la «3» dello scientifico e quella dell'artistico sono due classi" style="max-width:9em">
            <?php endif; ?>
            <input type="text" name="_audit_reason" class="fm-input" placeholder="motivazione (10-255 caratteri)" minlength="10" maxlength="255" required aria-label="Motivazione della nuova voce in <?= $h($titoli[$kind] ?? $kind) ?>" style="max-width:16em">
            <button type="submit" class="fm-btn fm-btn--sm fm-btn--primary">➕ Aggiungi</button>
        </form>
    </section>
<?php endforeach; ?>

<section class="fm-mt-8">
    <p class="fm-muted fm-text-13">
        Una voce con docenti o contenuti sopra non si elimina: prima si spostano, dal profilo dei
        docenti e dalla pagina «da categorizzare». Le sigle non compaiono mai nell'interfaccia:
        servono come chiave interna e restano stabili mentre le etichette cambiano.
    </p>
</section>

</div><!-- /.fm-card -->
