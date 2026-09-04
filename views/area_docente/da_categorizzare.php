<?php
/**
 * I contenuti a cui manca un'etichetta, raggruppati per sistemarli a blocchi.
 *
 * Le etichette sono tre, non una: la rotta di studio le vuole tutte
 * (/studio/{indirizzo}/{classe}/{materia}/{argomento}), quindi un contenuto
 * senza materia e' irraggiungibile esattamente come uno senza indirizzo.
 *
 * Segue la cornice delle altre pagine /area-docente/*: nav in cima, layout
 * `app.php` (sidebar, modali, footer).
 *
 * Variabili attese dal controller:
 * @var string $csrf
 * @var list<array<string,mixed>> $gruppi
 * @var list<array{id:int,code:string,label:string,istituto:string}> $indirizzi
 * @var list<array{id:int,code:string,label:string,istituto:string}> $classi
 * @var list<array{id:int,code:string,label:string,istituto:string}> $materie
 * @var array|null $flash
 */
$pageTitle    = 'PANTEDU — Da categorizzare';
$bodyClass    = 'fm-area-docente-da-categorizzare';
$currentRoute = '/area-docente/da-categorizzare';

$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
$totale = array_sum(array_column($gruppi, 'quanti'));

// I plurali italiani non si fanno aggiungendo una lettera: "mappa" fa "mappe",
// non "mappai". I tipi sono quattro e fissi, quindi si scrivono.
$plurale = static function (string $tipo, int $n): string {
    if ($n === 1) {
        return $tipo;
    }
    return [
        'mappa'     => 'mappe',
        'esercizio' => 'esercizi',
        'verifica'  => 'verifiche',
        'document'  => 'documenti',
    ][$tipo] ?? $tipo;
};

// Una tendina raggruppata per istituto. L'opzione vuota vuol dire "non
// toccare questo campo": e' cio' che permette di mettere la materia oggi e la
// classe domani senza che il salvataggio azzeri il resto.
$tendina = static function (string $nome, string $id, array $voci) use ($h): string {
    if ($voci === []) {
        return '<span class="fm-text-sm fm-text-muted">nessuna voce</span>';
    }
    $out = '<select id="' . $h($id) . '" name="' . $h($nome) . '" class="fm-input fm-max-w-220">'
         . '<option value="0">— scegli —</option>';
    $prec = null;
    foreach ($voci as $v) {
        if ($v['istituto'] !== $prec) {
            if ($prec !== null) {
                $out .= '</optgroup>';
            }
            $out .= '<optgroup label="' . $h($v['istituto']) . '">';
            $prec = $v['istituto'];
        }
        $out .= '<option value="' . (int)$v['id'] . '">' . $h($v['label']) . '</option>';
    }
    return $out . ($prec !== null ? '</optgroup>' : '') . '</select>';
};
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>🏷️ Contenuti da categorizzare</h1>
        <?php if ($gruppi !== []): ?>
            <p class="fm-text-muted fm-max-w-720">
                <strong><?= (int)$totale ?></strong> contenuti non hanno tutte le etichette, e per
                questo non compaiono nella navigazione: le pagine di studio si raggiungono con
                <em>indirizzo / classe / materia</em>, e chi ne ha una vuota non viene mai trovato.
                Non sono persi — manca un'etichetta.
            </p>
        <?php endif; ?>
    </header>

    <?php if (!empty($flash)): ?>
        <div class="fm-alert fm-alert--<?= $h((string)$flash['type']) ?>" role="alert">
            <strong><?= $h((string)($flash['title'] ?? '')) ?></strong>
            <div><?= $h((string)($flash['message'] ?? '')) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($gruppi === []): ?>
        <section class="fm-card">
            <p class="fm-text-muted fm-mt-0">
                Tutti i tuoi contenuti hanno indirizzo, classe e materia. Non c'è niente da
                sistemare qui.
            </p>
            <a class="fm-btn" href="/area-docente/dashboard">Torna alla dashboard</a>
        </section>
    <?php else: ?>
        <section class="fm-card">
            <p class="fm-text-sm fm-text-muted fm-mt-0">
                Raggruppati per sezione della sidebar, tipo e per <em>cosa manca</em>: si sistemano
                a blocchi. Ogni tendina è facoltativa — puoi mettere la materia oggi e la classe
                domani. I campi già pieni non vengono toccati, quindi rimandare non costa niente.
                I titoli che hanno un contenuto si aprono in una scheda nuova, per vedere cosa
                sono prima di decidere. Per molti di questi il materiale non sta nel database ma
                in un file a parte: la riga sembra vuota e non lo è, e sotto il titolo trovi
                quanti esercizi contiene.
            </p>

            <table class="fm-table fm-mt-3">
                <thead>
                    <tr>
                        <th scope="col">Contenuti</th>
                        <th scope="col">Dove stanno</th>
                        <th scope="col">Indirizzo</th>
                        <th scope="col">Classe</th>
                        <th scope="col">Materia</th>
                        <th scope="col"><span class="fm-sr-only">Azione</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gruppi as $i => $g): ?>
                        <?php $manca = (array)$g['mancano']; ?>
                        <tr>
                            <td>
                                <strong><?= (int)$g['quanti'] ?></strong>
                                <?= $h($plurale((string)$g['tipo'], (int)$g['quanti'])) ?>
                                <?php if ((int)$g['esercizi'] > 0): ?>
                                    <?php /* Il corpo di questi contenuti non sta nel database ma
                                             in un file a parte: la riga sembra vuota e non lo e'.
                                             Dirlo qui evita di farli sembrare gusci. */ ?>
                                    <div class="fm-text-sm">
                                        <?= (int)$g['esercizi'] ?> esercizi dentro<?php
                                            if ($g['origine'] !== null): ?>,
                                            da <code><?= $h((string)$g['origine']) ?></code><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="fm-text-sm fm-text-muted">
                                    <?php foreach ($g['titoli'] as $k => $t): ?><?= $k > 0 ? ' · ' : '' ?><?php
                                        if (!empty($t['apribile'])): ?><a
                                            href="/api/teacher/content/<?= (int)$t['id'] ?>/contract"
                                            target="_blank" rel="noopener"><?= $h($t['title']) ?></a><?php
                                        else: ?><?= $h($t['title']) ?><?php endif; ?><?php endforeach; ?>
                                    <?php if ((int)$g['quanti'] > count($g['titoli'])): ?>
                                        · e altri <?= (int)$g['quanti'] - count($g['titoli']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="fm-text-sm">
                                <?= $g['sezione'] !== null
                                    ? $h((string)$g['sezione'])
                                    : '<span class="fm-text-muted">nessuna sezione</span>' ?>
                            </td>
                            <form method="post" action="/area-docente/da-categorizzare" id="cat-<?= (int)$i ?>">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="ids" value="<?= $h(implode(',', $g['ids'])) ?>">
                            </form>
                            <td>
                                <?php if (in_array('indirizzo', $manca, true)): ?>
                                    <label class="fm-sr-only" for="ind-<?= (int)$i ?>">Indirizzo</label>
                                    <?= $tendina('indirizzo_id', 'ind-' . $i, $indirizzi) ?>
                                <?php else: ?>
                                    <span class="fm-text-sm fm-text-muted">c'è</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array('classe', $manca, true)): ?>
                                    <label class="fm-sr-only" for="cls-<?= (int)$i ?>">Classe</label>
                                    <?= $tendina('classe_id', 'cls-' . $i, $classi) ?>
                                <?php else: ?>
                                    <span class="fm-text-sm fm-text-muted"><?= $h((string)$g['classe']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array('materia', $manca, true)): ?>
                                    <label class="fm-sr-only" for="mat-<?= (int)$i ?>">Materia</label>
                                    <?= $tendina('subject_id', 'mat-' . $i, $materie) ?>
                                <?php else: ?>
                                    <span class="fm-text-sm fm-text-muted"><?= $h((string)$g['materia']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="submit" form="cat-<?= (int)$i ?>"
                                        class="fm-btn fm-btn--sm fm-btn--primary">Salva</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php /* L'HTML non permette un <form> che avvolga delle <td>. I select stanno nelle
                     celle e il form e' una riga a parte: l'attributo `form` li lega comunque, e
                     lo mette il JS perche' l'id si conosce solo qui. */ ?>
            <script>
            (function () {
                var tab = document.currentScript.previousElementSibling;
                if (!tab) { return; }
                tab.querySelectorAll("tbody > tr").forEach(function (riga) {
                    var f = riga.querySelector("form[id^='cat-']");
                    if (!f) { return; }
                    riga.querySelectorAll("select").forEach(function (s) {
                        s.setAttribute("form", f.id);
                    });
                });
            })();
            </script>

            <p class="fm-text-sm fm-text-muted fm-mt-3 fm-mb-0">
                Mettere un'etichetta non sposta né modifica il contenuto: aggiunge solo ciò che
                serve a ritrovarlo. Se sbagli, si cambia dalla pagina del contenuto.
            </p>
        </section>
    <?php endif; ?>
</main>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
