<?php
/**
 * Spostare i materiali da una classe a un'altra, a blocchi.
 *
 * Si sceglie la classe di partenza, si spuntano i contenuti e le verifiche,
 * si sceglie la classe di arrivo. Cambia la classe (e il corso, se la classe
 * di arrivo appartiene a un corso); il contenuto e la materia no.
 *
 * Segue la cornice delle altre pagine /area-docente/*: nav in cima, layout
 * `app.php` (sidebar, modali, footer).
 *
 * Variabili attese dal controller:
 * @var string $csrf
 * @var list<array{id:int,code:string,label:string,istituto:string,institute_id:int,indirizzo:?string,sospesa:bool}> $classi  le classi di partenza
 * @var list<array{id:int,code:string,label:string,istituto:string,institute_id:int,indirizzo:?string,sospesa:bool}> $arrivi  le spuntate
 * @var array{id:int,code:string,label:string,istituto:string,institute_id:int,indirizzo:?string,sospesa:bool}|null $partenza
 * @var array{contenuti:list<array<string,mixed>>,verifiche:list<array<string,mixed>>}|null $elenco  le verifiche una per verifica («varianti», «ids»)
 * @var list<array<string,mixed>> $avvisoSezioni  ADR-041, i materiali su sezioni senza incarico
 * @var array|null $flash
 */
$pageTitle    = 'PANTEDU — Sposta di classe';
$bodyClass    = 'fm-area-docente-sposta';
$currentRoute = '/area-docente/sposta-di-classe';

$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);

// Una tendina delle classi del docente, raggruppata per istituto; la sezione
// dice anche il suo corso, perche' scegliere «2A» vuol dire scegliere anche
// lo scientifico. `$escludi`: la classe di partenza non e' un arrivo.
$tendina = static function (string $nome, string $id, array $voci, int $scelta = 0, int $escludi = 0, bool $obbligatoria = false) use ($h): string {
    $out = '<select id="' . $h($id) . '" name="' . $h($nome) . '" class="fm-input fm-max-w-260"' . ($obbligatoria ? ' required' : '') . '>'
         . '<option value="">— scegli —</option>';
    $prec = null;
    foreach ($voci as $v) {
        if ($v['id'] === $escludi) {
            continue;
        }
        if ($v['istituto'] !== $prec) {
            if ($prec !== null) {
                $out .= '</optgroup>';
            }
            $out .= '<optgroup label="' . $h($v['istituto']) . '">';
            $prec = $v['istituto'];
        }
        $testo = $v['label'] . ' (' . $v['code'] . ')' . ($v['indirizzo'] !== null ? ' · ' . $v['indirizzo'] : '')
               . (!empty($v['sospesa']) ? ' — senza incarico' : '');
        $out .= '<option value="' . (int)$v['id'] . '"' . ($v['id'] === $scelta ? ' selected' : '')
              . ' data-indirizzo="' . $h((string)($v['indirizzo'] ?? '')) . '" data-codice="' . $h($v['code']) . '">' . $h($testo) . '</option>';
    }
    return $out . ($prec !== null ? '</optgroup>' : '') . '</select>';
};
$plurale = static fn(string $tipo): string => [
    'mappa' => 'mappa', 'esercizio' => 'esercizio', 'verifica' => 'verifica', 'document' => 'documento',
][$tipo] ?? $tipo;
$quanti = $elenco !== null ? count($elenco['contenuti']) + count($elenco['verifiche']) : 0;
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>🔀 Sposta di classe <button type="button" class="fm-infotip" aria-label="Info spostamento di classe"><span class="fm-infotip__body" hidden><p>I materiali nascono con la classe scelta nella barra laterale. Da qui li sposti <strong>a blocchi</strong> in un'altra classe: scegli quella di partenza, spunta cosa spostare, scegli quella di arrivo.</p><p>Cambia solo la <strong>classe</strong> — e il <strong>corso</strong>, se la classe di arrivo appartiene a un corso (una sezione «2A» dello scientifico è dello scientifico). La materia e il contenuto non si toccano. Le classi fra cui scegliere sono quelle che hai spuntato nel profilo; come partenza, anche le sezioni di cui non hai più l'incarico, finché ci sono tuoi materiali.</p></span></button></h1>
        <p class="fm-text-muted fm-max-w-720">
            Per esempio: i materiali archiviati sull'anno («2») quando la scuola ha le sezioni («2A»).
        </p>
    </header>

    <?php if (!empty($flash)): ?>
        <div class="fm-alert fm-alert--<?= $h((string)$flash['type']) ?>" role="alert">
            <strong><?= $h((string)($flash['title'] ?? '')) ?></strong>
            <div><?= $h((string)($flash['message'] ?? '')) ?></div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/../partials/_avviso_sezioni_da_spostare.php'; ?>

    <?php if ($classi === []): ?>
        <section class="fm-card">
            <p class="fm-text-muted fm-mt-0">Non hai ancora spuntato nessuna classe: fallo dal profilo, sotto «Curriculum dell'istituto attivo».</p>
            <a class="fm-btn" href="/area-docente/profilo">Vai al profilo</a>
        </section>
    <?php else: ?>
        <section class="fm-card">
            <form method="get" action="/area-docente/sposta-di-classe" class="fm-d-flex fm-items-center fm-gap-2 fm-flex-wrap">
                <label for="sposta-classe-da"><strong>Classe di partenza</strong></label>
                <?= $tendina('classe', 'sposta-classe-da', $classi, $partenza['id'] ?? 0) ?>
                <?php /* Con JavaScript la scelta ricarica da sola (sposta-partenza.js); il bottone resta per chi non l'ha. */ ?>
                <button type="submit" class="fm-btn fm-btn--sm">Mostra i materiali</button>
            </form>
        </section>

        <?php if ($elenco !== null): ?>
            <section class="fm-card fm-mt-3" id="sposta-elenco" data-quanti="<?= (int)$quanti ?>">
                <?php if (!empty($partenza['sospesa'])): ?>
                    <p class="fm-text-sm fm-mt-0" id="sposta-sospesa">
                        <strong>Non hai più l'incarico della <?= $h($partenza['code']) ?>.</strong>
                        Questa classe è solo di partenza: porta i materiali in una classe di cui hai l'incarico.
                    </p>
                <?php endif; ?>
                <?php
                $conPosti = array_filter(array_merge($elenco['contenuti'], $elenco['verifiche']),
                    static fn(array $x): bool => ($x['principale_in'] ?? null) !== null);
                if ($conPosti !== []): ?>
                    <p class="fm-text-sm fm-mt-0" id="sposta-posti-in-piu">
                        <strong><?= count($conPosti) ?></strong> di questi hanno nella <?= $h($partenza['code']) ?> solo un
                        posto in più di «Dove vale» («principale in…»): spostandoli si sposta quel posto, e il loro posto
                        principale resta dov'è. Se il posto principale è già nella classe di arrivo, i due diventano uno.
                    </p>
                <?php endif; ?>
                <?php if ($quanti === 0): ?>
                    <p class="fm-text-muted fm-mt-0 fm-mb-0">Nessun materiale nella classe <strong><?= $h($partenza['label']) ?></strong> (<?= $h($partenza['code']) ?>).</p>
                <?php else: ?>
                    <p class="fm-text-sm fm-text-muted fm-mt-0">
                        <strong><?= (int)$quanti ?></strong> elementi nella classe <strong><?= $h($partenza['label']) ?></strong> (<?= $h($partenza['code']) ?>).
                        Spunta quelli da spostare — o tutti — e scegli la classe di arrivo.
                    </p>
                    <?php /* data-partenza-*: il riepilogo prima di inviare (area-docente-sposta.js). */ ?>
                    <form method="post" action="/area-docente/sposta-di-classe" id="sposta-form"
                          data-partenza-codice="<?= $h($partenza['code']) ?>"
                          data-partenza-indirizzo="<?= $h((string)($partenza['indirizzo'] ?? '')) ?>"
                          data-partenza-sospesa="<?= !empty($partenza['sospesa']) ? '1' : '0' ?>">
                        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="classe_da" value="<?= (int)$partenza['id'] ?>">
                        <table class="fm-table fm-mt-2">
                            <thead>
                                <tr>
                                    <th scope="col"><input type="checkbox" id="sposta-tutti" aria-label="Seleziona tutti gli elementi"></th>
                                    <th scope="col">Tipo</th>
                                    <th scope="col">Titolo</th>
                                    <th scope="col">Corso</th>
                                    <th scope="col">Materia</th>
                                    <th scope="col">Dove sta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($elenco['contenuti'] as $c): ?>
                                    <tr>
                                        <td><input type="checkbox" name="contenuti[]" value="<?= (int)$c['id'] ?>" data-sposta-voce data-titolo="<?= $h($c['title']) ?>" aria-label="Sposta «<?= $h($c['title']) ?>»"></td>
                                        <td class="fm-text-sm"><?= $h($plurale((string)$c['tipo'])) ?></td>
                                        <td><?= $h($c['title']) ?></td>
                                        <td class="fm-text-sm"><?= $c['indirizzo'] !== null ? $h($c['indirizzo']) : '<span class="fm-text-muted">—</span>' ?></td>
                                        <td class="fm-text-sm"><?= $c['materia'] !== null ? $h($c['materia']) : '<span class="fm-text-muted">—</span>' ?></td>
                                        <td class="fm-text-sm"><?= $c['sezione'] !== null ? $h($c['sezione']) : '<span class="fm-text-muted">nessuna sezione</span>' ?><?= ($c['principale_in'] ?? null) !== null ? '<br><span class="fm-text-muted">posto in più; principale in ' . $h($c['principale_in']) . '</span>' : '' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($elenco['verifiche'] as $v): ?>
                                    <tr>
                                        <td><input type="checkbox" name="verifiche[]" value="<?= (int)$v['id'] ?>" data-sposta-voce data-titolo="<?= $h($v['title']) ?>" aria-label="Sposta la verifica «<?= $h($v['title']) ?>», con tutte le sue varianti"></td>
                                        <td class="fm-text-sm">verifica<?= (int)$v['varianti'] > 1 ? ' · ' . (int)$v['varianti'] . ' varianti' : '' ?></td>
                                        <td><?= $h($v['title']) ?></td>
                                        <td class="fm-text-sm"><?= $v['indirizzo'] !== null ? $h($v['indirizzo']) : '<span class="fm-text-muted">—</span>' ?></td>
                                        <td class="fm-text-sm"><?= $v['materia'] !== null ? $h($v['materia']) : '<span class="fm-text-muted">—</span>' ?></td>
                                        <td class="fm-text-sm">verifiche<?= ($v['principale_in'] ?? null) !== null ? '<br><span class="fm-text-muted">posto in più; principale in ' . $h($v['principale_in']) . '</span>' : '' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php // Solo classi della stessa scuola: materia e corso dei materiali sono di quella.
                        // Da ADR-041 l'arrivo sono solo le classi spuntate: una sezione senza incarico è solo di partenza.
                        $stessaScuola = array_values(array_filter($arrivi, static fn(array $c): bool => $c['institute_id'] === $partenza['institute_id'])); ?>
                        <div class="fm-d-flex fm-items-center fm-gap-2 fm-flex-wrap fm-mt-3">
                            <label for="sposta-classe-a"><strong>Classe di arrivo</strong></label>
                            <?= $tendina('classe_a', 'sposta-classe-a', $stessaScuola, 0, (int)$partenza['id'], true) ?>
                            <button type="submit" class="fm-btn fm-btn--primary" id="sposta-invia">Sposta gli elementi scelti</button>
                        </div>
                        <p class="fm-text-sm fm-text-muted fm-mt-2 fm-mb-0" id="sposta-nota">
                            Se la classe di arrivo appartiene a un corso, anche il corso dei materiali diventa quello.
                            La materia e il contenuto restano com'erano. Spostare non cancella niente: si può rifare al contrario.
                        </p>
                        <p class="fm-text-sm fm-text-muted fm-mt-2 fm-mb-0">
                            <strong>Chi li vede segue la classe.</strong> Gli studenti e le credenziali della classe di arrivo
                            trovano i materiali spostati; quelli della classe di partenza no. Un anno («2») resta visibile
                            dalle sue sezioni (2A, 2B…), una sezione («2A») solo da sé: spostare dall'anno alla sezione A
                            li toglie alle altre sezioni. Anche gli altri posti di «Dove vale» che stanno nella classe di
                            partenza passano in quella di arrivo. Se un materiale era già pubblicato nella classe di
                            arrivo, i due posti diventano uno solo quando questo non cambia chi lo vede; altrimenti
                            restano tutti e due e la pagina lo dice. Una verifica si sposta con tutte le sue varianti.
                        </p>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?= \App\Support\ViteManifest::script('js/entries/area-docente-sposta.js') ?>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
