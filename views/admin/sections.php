<?php
/**
 * Pannello /admin/sections — incarichi docente↔sezione e classe degli studenti.
 *
 * NB CSP: niente handler inline (`on*=`), vietati dal guard
 * tools/ci/no-inline-handlers.mjs e bloccati da script-src-attr. Dal
 * 2026-09-04 (revisione P8) il JavaScript sta in js/entries/admin-sections.js
 * (entry Vite): la vista espone solo attributi data-* (es. data-fm-autosubmit
 * sul select dell'istituto) e il blocco JSON #fm-assign-stato.
 *
 * @var string $csrf
 * @var array  $user
 * @var list<array<string,mixed>> $institutes
 * @var int    $instituteId
 * @var list<array<string,mixed>> $assignments
 * @var list<array<string,mixed>> $teachers
 * @var list<array<string,mixed>> $students
 * @var list<array<string,mixed>> $indirizzi
 * @var list<array<string,mixed>> $classi
 * @var list<array<string,mixed>> $senzaSezione
 * @var list<array{indirizzo:string,classe:string,studenti:int}> $scoperte
 * @var list<array{code:string,label:string}> $materie
 * @var array<int, list<array{code:string,label:string}>> $materieDoc
 * @var list<int> $senzaMaterie
 * @var list<array<string,mixed>> $rimasti   ADR-041, materiali su sezioni che i docenti non possono più usare
 * @var string|null $scadenza                ADR-041, la data dell'avviso ai docenti
 * @var array{type:string,msg:string}|null $flash
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$page_title    = '🎯 Sezioni';
$page_subtitle = 'Chi insegna in quale sezione, e in quale sezione sta ogni studente.';
$breadcrumb    = [['label' => 'Admin'], ['label' => 'Sezioni']];
include __DIR__ . '/_partials/page_head.php';
// Piano classi-credenziali-scenari, B — negli scenari 1 e 2 gli incarichi non
// aprono niente (gli studenti entrano con la credenziale di classe): la pagina
// resta raggiungibile, ma lo dice subito.
$inert_area = 'sections';
include __DIR__ . '/_partials/inert_area.php';

// Le SIGLE degli indirizzi non si mostrano: sono una chiave interna (il join
// fra contenuti, studenti e incarichi) e all'utente non dicono nulla. Qui la
// mappa per risalire all'etichetta ovunque serva.
$indLabel = [];
foreach ($indirizzi as $_i) { $indLabel[(string)$_i['code']] = (string)$_i['label']; }

// Sezioni vere: un codice di solo numero e' l'anno di corso, non una sezione.
$sezioni = array_values(array_filter($classi, static fn($c) => (bool)preg_match('/^[1-9].+$/', (string)$c['code'])));

// Il modulo degli incarichi, una riga per anno: prima l'anno intero, poi le sue
// sezioni. Dal 15/9/2026 (ADR-042) ogni anno appartiene a un indirizzo come le
// sezioni, e il modulo mostra solo le classi dell'indirizzo scelto: il nome del
// corso accanto a ogni casella ripeteva quello del selettore (segnalato
// dall'utente).
$perAnno = [];
foreach ($classi as $_c) {
    $_code = (string)$_c['code'];
    if (!preg_match('/^([1-9])(.*)$/', $_code, $_m)) {
        continue;
    }
    $perAnno[$_m[1]][$_m[2] === '' ? 'anni' : 'sezioni'][] = $_c;
}
ksort($perAnno);
$annoLabel = ['1' => 'Prima', '2' => 'Seconda', '3' => 'Terza', '4' => 'Quarta', '5' => 'Quinta'];
?>

<?php if ($flash !== null): ?>
    <div class="fm-alert fm-alert--<?= $flash['type'] === 'ok' ? 'success' : 'danger' ?>" role="alert">
        <?= $h($flash['msg']) ?>
    </div>
<?php endif; ?>

<form method="GET" action="/admin/sections" class="fm-d-flex fm-gap-2 fm-items-center fm-mb-4">
    <label for="inst">Istituto:</label>
    <select id="inst" name="institute_id" class="fm-input fm-flex-1 fm-max-w-640" data-fm-autosubmit>
        <option value="0">— scegli —</option>
        <?php foreach ($institutes as $i): ?>
            <option value="<?= (int)$i['id'] ?>" <?= (int)$i['id'] === $instituteId ? 'selected' : '' ?>>
                <?= $h($i['name']) ?> (<?= $h($i['code']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Vai</button>
</form>

<?php if ($instituteId <= 0): ?>
    <p class="fm-muted">Scegli un istituto per vedere gli incarichi.</p>
<?php else: ?>

<?php if ($scoperte !== []): ?>
    <div class="fm-alert fm-alert--warn" role="alert">
        <strong><?= count($scoperte) ?> class<?= count($scoperte) === 1 ? 'e' : 'i' ?> con studenti che nessun docente raggiunge.</strong>
        Il filtro per sezione è sempre attivo: chi sta in queste classi <strong>non vede
        nessun contenuto</strong> finché non assegni almeno un docente. I docenti continuano
        a vedere i propri.
        <div class="fm-mt-1 fm-text-xs">
            <?php foreach ($scoperte as $c): ?>
                <code><?= $h($indLabel[$c['indirizzo']] ?? $c['indirizzo']) ?> <?= $h($c['classe']) ?></code>
                (<?= (int)$c['studenti'] ?> student<?= (int)$c['studenti'] === 1 ? 'e' : 'i' ?>)&nbsp;
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($senzaSezione !== []): ?>
    <div class="fm-alert fm-alert--warn" role="alert">
        <strong><?= count($senzaSezione) ?> student<?= count($senzaSezione) === 1 ? 'e' : 'i' ?> senza sezione.</strong>
        <strong>Non vedono alcun contenuto</strong>: un docente assegnato a “1A” non raggiunge
        chi è iscritto al solo “1”, e senza sezione non c'è nulla che possa raggiungerli.
        Spostali qui sotto.
        <div class="fm-mt-1 fm-text-xs">
            <?php foreach ($senzaSezione as $s): ?>
                <code><?= $h($s['username']) ?></code>
                (<?= $h($indLabel[$s['indirizzo']] ?? ($s['indirizzo'] ?: '—')) ?> <?= $h($s['classe'] ?: '—') ?>)&nbsp;
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="fm-card">
    <h2 class="fm-mt-0">Incarichi dei docenti</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">
        Scegli docente e indirizzo: le caselle si presentano <strong>già spuntate</strong> su
        quello che ha adesso. Da lì è un elenco — quello che spunti gli viene assegnato,
        quello a cui togli la spunta gli viene tolto.
        <br>Un incarico su <strong>“1”</strong> copre tutte le sezioni di quell'anno, comprese
        quelle che nasceranno dopo; uno su <strong>“1A”</strong> copre solo quella. Spuntare
        1A, 1B e 1C una per una equivale a “1” <em>oggi</em>, ma non copre la 1D del prossimo
        anno: è la differenza fra una regola e un elenco.
        <br>Lo studente vede i contenuti dei docenti che lo raggiungono — e di nessun altro.
        <strong>Senza incarichi non vede niente</strong>: il filtro parte chiuso e sono gli
        incarichi ad aprirlo. I docenti vedono sempre i propri contenuti.
    </p>

    <?php
    /* Gli incarichi gia' in essere, per docente e per indirizzo. Il modulo li
       usa per presentarsi con le caselle gia' spuntate: chiedere di ricordare
       a memoria cosa c'e' gia' nella tabella qui sotto e' un modo per farlo
       sbagliare. */
    $incarichiPer = [];
    foreach ($assignments as $a) {
        $incarichiPer[(int)$a['user_id']][(string)$a['indirizzo']][] = (string)$a['classe'];
    }
    ?>
    <script type="application/json" id="fm-assign-stato"><?= json_encode((object)$incarichiPer, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <form method="POST" action="/admin/sections/assign" class="fm-mb-3" id="fm-assign-form">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Assegnazione di incarichi di sezione a un docente dal pannello Sezioni">
        <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
        <?php /* Cosa il modulo mostrava quando e' stato compilato: senza, il
                 server non puo' distinguere "non l'ho spuntata" da "non c'era".
                 Lo riempie il JS, quindi se il JS non gira resta vuoto e il
                 salvataggio torna a essere solo additivo. */ ?>
        <input type="hidden" name="stato" id="fm-assign-stato-val" value="">
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-2">
            <?php /* Docente e indirizzo dell'ultimo salvataggio restano scelti: il JS
                     (admin-sections.js) all'avvio rimette le caselle dello stato
                     aggiornato. Fino al 15/9/2026 il modulo tornava vuoto. */ ?>
            <select name="user_id" id="fm-assign-doc" class="fm-input fm-flex-1 fm-max-w-280" required>
                <option value="">— docente —</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)($sceltaDocente ?? 0) ? 'selected' : '' ?>><?= $h($t['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="indirizzo" id="fm-assign-ind" class="fm-input fm-flex-1 fm-max-w-300" required>
                <option value="">— indirizzo —</option>
                <?php foreach ($indirizzi as $i): ?>
                    <option value="<?= $h($i['code']) ?>" <?= (string)$i['code'] === (string)($sceltaIndirizzo ?? '') ? 'selected' : '' ?>><?= $h($i['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="fm-btn fm-btn--primary fm-btn--sm">💾 Salva incarichi</button>
        </div>

        <?php /* Checkbox e non una tendina: un docente sta quasi sempre su piu'
                 sezioni dello stesso indirizzo, e assegnarle una per volta
                 invita a usare l'anno "1" come scorciatoia — cioe' proprio cio'
                 che si vuole evitare quando le sezioni contano. */ ?>
        <fieldset class="fm-curr-form fm-d-block" id="fm-assign-classi">
            <legend class="fm-text-xs fm-muted">Classi e sezioni (una o più)</legend>
            <?php if ($classi === []): ?>
                <p class="fm-muted fm-text-13 fm-m-0">Nessuna classe censita per questo istituto.</p>
            <?php else: ?>
                <p class="fm-text-11 fm-muted fm-m-0 fm-mt-1">
                    Le classi dell'indirizzo scelto. <strong>Anno intero</strong> copre ogni sezione di quell'anno,
                    anche quelle che verranno; una sezione vale solo per sé.
                </p>
                <div id="fm-assign-sezioni">
                <?php /* Una riga per anno di corso: un elenco piatto ordinato per
                         codice mette 1A, 1AA, 1ALSS, 1BLSS, 2A... di seguito, e
                         trovarci la propria sezione diventa un lavoro. Il filtro
                         per indirizzo (admin-sections.js, data-ind) nasconde anni e
                         sezioni degli altri corsi, e le righe rimaste vuote. */ ?>
                <?php foreach ($perAnno as $anno => $gruppo): ?>
                    <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-1" data-anno-riga="<?= $h($anno) ?>">
                        <span class="fm-text-11 fm-muted fm-w-20"><?= $h($annoLabel[$anno] ?? $anno) ?></span>
                        <?php foreach ($gruppo['anni'] ?? [] as $c): ?>
                            <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs" data-anno="1"
                                   data-ind="<?= $h($c['indirizzo'] ?? '') ?>">
                                <input type="checkbox" name="classe[]" value="<?= $h($c['code']) ?>">
                                <strong><?= $h($c['code']) ?></strong>
                                <span class="fm-text-11 fm-muted">anno intero</span>
                            </label>
                        <?php endforeach; ?>
                        <?php foreach ($gruppo['sezioni'] ?? [] as $c): ?>
                            <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs"
                                   data-ind="<?= $h($c['indirizzo'] ?? '') ?>">
                                <input type="checkbox" name="classe[]" value="<?= $h($c['code']) ?>">
                                <strong><?= $h($c['code']) ?></strong>
                                <?php if (empty($c['indirizzo'])): ?>
                                    <span class="fm-text-11 fm-muted">senza indirizzo</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
    </form>

    <?php if (($modoSezioni ?? null) !== null): ?>
        <p class="fm-muted fm-text-13">
            <?php /* ADR-041, ADR-043 — gli incarichi decidono quali classi, anni e sezioni, un docente può usare. */ ?>
            Classi dei docenti in questo istituto:
            <strong><?= $h(match ($modoSezioni) {
                \App\Services\SezioniDeiDocenti::TUTTI   => 'tutti i docenti, anni e sezioni',
                \App\Services\SezioniDeiDocenti::NESSUNO => 'solo gli anni, per tutti i docenti',
                default                                  => 'solo i docenti incaricati qui sotto',
            }) ?></strong>
            (si cambia da <a class="fm-link-inline" href="/admin/institutes">Istituti</a>).
            <?php if ($modoSezioni === \App\Services\SezioniDeiDocenti::SOLO_INCARICATI): ?>
                Un docente usa solo gli anni e le sezioni di cui ha l'incarico: l'anno vale per tutte le sue sezioni in quell'indirizzo.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($assignments === []): ?>
        <p class="fm-muted fm-text-13">
            Nessun incarico. Finché resta così il filtro per sezione è <strong>inerte</strong>:
            gli studenti vedono i contenuti di tutti i docenti dell'istituto, come prima.
        </p>
    <?php else: ?>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-2">
            <input type="search" id="fm-inc-q" class="fm-input fm-flex-1 fm-max-w-240"
                   placeholder="Cerca docente…" aria-label="Cerca incarico per docente">
            <select id="fm-inc-ind" class="fm-input fm-flex-1 fm-max-w-240" aria-label="Filtra incarichi per indirizzo">
                <option value="">Tutti gli indirizzi</option>
                <?php foreach ($indirizzi as $i): ?>
                    <option value="<?= $h($i['code']) ?>"><?= $h($i['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="fm-inc-anno" class="fm-input fm-flex-1 fm-max-w-160" aria-label="Filtra incarichi per anno">
                <option value="">Tutti gli anni</option>
                <?php foreach ($annoLabel as $k => $v): ?>
                    <option value="<?= $h($k) ?>"><?= $h($v) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="fm-text-11 fm-muted" id="fm-inc-conta"></span>
        </div>
        <table class="fm-curr-table" id="fm-inc-tabella">
            <thead><tr>
                <th scope="col">Docente</th><th scope="col">Indirizzo</th>
                <th scope="col">Classe</th><th scope="col">Dal</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($assignments as $a): ?>
                <tr data-doc="<?= $h($a['nome'] . ' ' . $a['username']) ?>"
                    data-ind="<?= $h($a['indirizzo']) ?>"
                    data-anno="<?= $h(substr((string)$a['classe'], 0, 1)) ?>">
                    <td><?= $h($a['nome']) ?> <code class="fm-text-11"><?= $h($a['username']) ?></code></td>
                    <td><?= $h($indLabel[$a['indirizzo']] ?? $a['indirizzo']) ?></td>
                    <td><strong><?= $h($a['classe']) ?></strong></td>
                    <td class="fm-text-11"><?= $h($a['assigned_at']) ?></td>
                    <td>
                        <?php /* data-fm-revoca: prima di inviare, la finestra con i
                                 materiali del docente su quella sezione (admin-sections.js). */ ?>
                        <form method="POST" action="/admin/sections/revoke" data-fm-revoca
                              data-docente="<?= (int)$a['user_id'] ?>" data-nome="<?= $h($a['nome']) ?>"
                              data-indirizzo="<?= $h($a['indirizzo']) ?>" data-classe="<?= $h($a['classe']) ?>">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="_audit_reason" value="Revoca dell'incarico #<?= (int)$a['id'] ?> dal pannello Sezioni, dopo la finestra di conferma">
                            <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
                            <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Revoca</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<?php if (($modoSezioni ?? null) !== \App\Services\SezioniDeiDocenti::TUTTI || $rimasti !== []): ?>
<div class="fm-card fm-mt-6" id="fm-rimasti">
    <h2 class="fm-mt-0">Materiali su classi senza incarico</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">
        Togliere un incarico non sposta i materiali del docente: restano sulla classe, e dai suoi menù
        non li raggiunge più. In «Sposta di classe» la classe compare ancora come classe di partenza, e da
        lì li porta in una classe di cui ha l'incarico; il suo cruscotto glielo ricorda.
        Da una sezione, se non lo fa, li porti tu sull'anno da qui, docente per docente. <strong>Niente parte da solo</strong>:
        un incarico tolto per sbaglio si rimedia ridandolo, uno spostamento no.
    </p>

    <form method="POST" action="/admin/sections/scadenza" class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-3">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Data entro cui i docenti spostano i materiali rimasti sulle sezioni (ADR-041)">
        <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
        <label for="fm-rimasti-scadenza" class="fm-text-13">Entro quando i docenti li spostano (compare nel loro avviso):</label>
        <input type="date" id="fm-rimasti-scadenza" name="scadenza" class="fm-input fm-max-w-220" value="<?= $h($scadenza ?? '') ?>">
        <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Salva la data</button>
        <span class="fm-text-11 fm-muted">Vuota: nessuna data. La data non sposta niente.</span>
    </form>

    <?php if ($rimasti === []): ?>
        <p class="fm-muted fm-text-13 fm-mb-0">Nessun docente ha materiali su classi di cui non ha l'incarico.</p>
    <?php else: ?>
        <table class="fm-curr-table" id="fm-rimasti-tabella">
            <thead><tr>
                <th scope="col">Docente</th><th scope="col">Classe</th>
                <th scope="col">Contenuti</th><th scope="col">Verifiche</th><th scope="col">Posti</th>
                <th scope="col">Credenziali sulla classe</th><th scope="col">Porta sull'anno</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rimasti as $r): ?>
                <tr data-rimasti-docente="<?= (int)$r['docente'] ?>" data-rimasti-sezione="<?= $h($r['code']) ?>">
                    <td><?= $h($r['nome']) ?> <code class="fm-text-11"><?= $h($r['username']) ?></code></td>
                    <td><strong><?= $h($r['code']) ?></strong>
                        <?php if ($r['indirizzo'] !== null): ?><span class="fm-text-11 fm-muted"><?= $h($indLabel[$r['indirizzo']] ?? $r['indirizzo']) ?></span><?php endif; ?>
                    </td>
                    <td><?= (int)$r['contenuti'] ?></td>
                    <td><?= (int)$r['verifiche'] ?></td>
                    <td class="fm-text-11"><?= (int)$r['posti'] ?>
                        <?php if ((int)$r['bersagli'] > 0): ?>
                            <br><span class="fm-muted">+<?= (int)$r['bersagli'] ?> «per più classi»: si tolgono dal contenuto</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$r['credenziali'] ?></td>
                    <td>
                        <?php if (!empty($r['e_anno'])): ?>
                            <span class="fm-text-11">È già un anno: li sposta il docente, o gli ridai l'incarico.</span>
                        <?php elseif ($r['anno_id'] !== null && empty($r['anno_ammesso'])): ?>
                            <span class="fm-text-11">Non ha l'incarico sulla «<?= $h((string)$r['anno']) ?>»: daglielo prima, lì li ritroverebbe.</span>
                        <?php elseif ($r['anno_id'] === null): ?>
                            <span class="fm-text-11">Manca l'anno «<?= $h((string)$r['anno']) ?>» nel catalogo: aggiungilo da
                                <a class="fm-link-inline" href="/admin/institutes">Istituti</a>.</span>
                        <?php else: ?>
                            <form method="POST" action="/admin/sections/materiali" class="fm-d-flex fm-gap-1 fm-items-center fm-flex-wrap">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$r['docente'] ?>">
                                <input type="hidden" name="classe_id" value="<?= (int)$r['id'] ?>">
                                <input type="text" name="_audit_reason" class="fm-input fm-max-w-220" minlength="10" maxlength="255" required
                                       placeholder="motivo (10-255 caratteri)"
                                       aria-label="Motivo per portare sull'anno «<?= $h((string)$r['anno']) ?>» i materiali di <?= $h($r['nome']) ?> della <?= $h($r['code']) ?>">
                                <button type="submit" class="fm-btn fm-btn--sm">Sulla «<?= $h((string)$r['anno']) ?>»</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="fm-muted fm-text-13 fm-mt-2 fm-mb-0">
            <strong>Che cosa cambia.</strong> I contenuti con la classe su quella sezione passano sull'anno; quelli che
            lì hanno solo un posto in più di «Dove vale» perdono quel posto, che si unisce a quello sull'anno quando
            non cambia chi li vede. Sull'anno li vedono tutte le classi di quell'anno del docente: le sue credenziali
            della sezione continuano a vederli, quelle delle altre sezioni dello stesso anno cominciano a vederli.
            Le credenziali restano com'erano. L'anno diventa una classe spuntata del docente, se non lo era.
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="fm-card fm-mt-6">
    <h2 class="fm-mt-0">Materie dei docenti</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">
        Le materie fra cui scegliere sono quelle dell'istituto, importate dal MIUR in
        <a class="fm-link-inline" href="/admin/institutes#miur-schools">Istituti</a>. Un docente le propone da sé al
        primo accesso; qui si correggono.
        <br>Togliere una materia <strong>non cancella niente</strong>: la disattiva. I contenuti
        già pubblicati continuano a puntarci, e rimetterla è una spunta.
    </p>

    <?php if ($materie === []): ?>
        <div class="fm-alert fm-alert--warn" role="alert">
            <strong>Questo istituto non ha ancora materie.</strong>
            Nessun docente può sceglierne finché non le importi:
            <a class="fm-link-inline" href="/admin/institutes#miur-schools">Istituti → Dati MIUR → Adozioni</a>.
        </div>
    <?php elseif ($teachers === []): ?>
        <p class="fm-muted">Nessun docente collegato a questo istituto.</p>
    <?php else: ?>
        <?php if ($senzaMaterie !== []): ?>
            <div class="fm-alert fm-alert--warn" role="alert">
                <strong><?= count($senzaMaterie) ?> docent<?= count($senzaMaterie) === 1 ? 'e' : 'i' ?> senza materie.</strong>
                <?= count($senzaMaterie) === 1 ? 'Non può' : 'Non possono' ?> pubblicare nulla di
                categorizzabile: al primo accesso <?= count($senzaMaterie) === 1 ? 'trova' : 'trovano' ?>
                la schermata di scelta, ma puoi anche assegnargliele tu qui sotto.
            </div>
        <?php endif; ?>

        <label class="fm-label" for="fm-mat-cerca">Cerca docente</label>
        <input type="search" id="fm-mat-cerca" class="fm-input fm-max-w-300 fm-mb-3"
               placeholder="cognome o nome" autocomplete="off">

        <table class="fm-table">
            <thead>
                <tr><th scope="col">Docente</th><th scope="col">Materie</th><th scope="col"></th></tr>
            </thead>
            <tbody id="fm-mat-corpo">
                <?php foreach ($teachers as $t): ?>
                    <?php
                    $tid  = (int)$t['id'];
                    $sue  = $materieDoc[$tid] ?? [];
                    $spun = array_column($sue, 'code');
                    ?>
                    <tr data-doc="<?= $h(mb_strtolower((string)$t['nome'])) ?>">
                        <td>
                            <?= $h($t['nome']) ?>
                            <?php if ($sue === []): ?>
                                <span class="fm-badge fm-badge--severity-medium">nessuna materia</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/sections/subjects" id="fm-mat-<?= $tid ?>">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="_audit_reason" value="Materie del docente #<?= $tid ?> impostate dal pannello Sezioni">
                                <input type="hidden" name="institute_id" value="<?= (int)$instituteId ?>">
                                <input type="hidden" name="user_id" value="<?= $tid ?>">
                                <div class="fm-d-flex fm-gap-3 fm-flex-wrap">
                                    <?php foreach ($materie as $m): ?>
                                        <?php $id = 'mat-' . $tid . '-' . $h($m['code']); ?>
                                        <label class="fm-d-flex fm-gap-1 fm-items-center" for="<?= $id ?>">
                                            <input type="checkbox" id="<?= $id ?>" name="materia[]"
                                                   value="<?= $h($m['code']) ?>"
                                                   <?= in_array($m['code'], $spun, true) ? 'checked' : '' ?>>
                                            <?= $h($m['label']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </form>
                        </td>
                        <td>
                            <button type="submit" form="fm-mat-<?= $tid ?>" class="fm-btn fm-btn--sm">Salva</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="fm-muted fm-text-13 fm-mt-2" id="fm-mat-conta"></p>

    <?php endif; ?>
</div>

<div class="fm-card fm-mt-6">
    <h2 class="fm-mt-0">Classe degli studenti</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">
        Cambiare classe è una <strong>rettifica</strong> (Art. 16 GDPR), non una nuova
        iscrizione: reiscrivere creerebbe una seconda identità per la stessa persona e
        farebbe perdere consensi, accettazione dei ToS e — per un minore — il consenso
        del genitore. Ogni spostamento va a registro con il prima e il dopo.
    </p>

    <?php if ($students === []): ?>
        <p class="fm-muted fm-text-13">Nessuno studente in questo istituto.</p>
    <?php else: ?>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-2" id="fm-stud-filtri">
            <input type="search" id="fm-stud-q" class="fm-input fm-flex-1 fm-max-w-240" placeholder="Cerca username…"
                   aria-label="Cerca studente per username">
            <select id="fm-stud-ind" class="fm-input fm-flex-1 fm-max-w-240" aria-label="Filtra per indirizzo">
                <option value="">Tutti gli indirizzi</option>
                <?php foreach ($indirizzi as $i): ?>
                    <option value="<?= $h($i['code']) ?>"><?= $h($i['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="fm-stud-cls" class="fm-input fm-flex-1 fm-max-w-160" aria-label="Filtra per classe">
                <option value="">Tutte le classi</option>
                <?php /* Una sigla per opzione: gli anni si ripetono per corso (ADR-042). */ ?>
                <?php foreach (array_values(array_unique(array_map(static fn($c) => (string)$c['code'], $classi))) as $codice): ?>
                    <option value="<?= $h($codice) ?>"><?= $h($codice) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs">
                <input type="checkbox" id="fm-stud-senza"> solo senza sezione
            </label>
            <span class="fm-text-11 fm-muted" id="fm-stud-conta"></span>
        </div>

        <table class="fm-curr-table" id="fm-stud-tabella">
            <thead><tr>
                <th scope="col">Studente</th><th scope="col">Ora</th>
                <th scope="col">Sposta in</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $senza = empty($s['classe']) || preg_match('/^[1-9]$/', (string)$s['classe']); ?>
                <tr data-user="<?= $h($s['username']) ?>"
                    data-ind="<?= $h($s['indirizzo'] ?? '') ?>"
                    data-cls="<?= $h($s['classe'] ?? '') ?>"
                    data-senza="<?= $senza ? '1' : '0' ?>">
                    <td>
                        <code><?= $h($s['username']) ?></code>
                        <?php if ($s['status'] !== 'active'): ?>
                            <span class="fm-text-11 fm-muted">(<?= $h($s['status']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $h($indLabel[$s['indirizzo']] ?? ($s['indirizzo'] ?: '—')) ?> <strong><?= $h($s['classe'] ?: '—') ?></strong>
                        <?= $senza ? ' ⚠️' : '' ?>
                    </td>
                    <td colspan="2">
                        <form method="POST" action="/admin/sections/student" class="fm-d-flex fm-gap-1 fm-items-center">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="_audit_reason" value="Studente #<?= (int)$s['id'] ?> spostato di sezione: rettifica (art. 16 GDPR) dal pannello Sezioni">
                            <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
                            <select name="indirizzo" class="fm-input fm-flex-1 fm-max-w-160 fm-stud-ind-sel" required>
                                <option value="">— indirizzo —</option>
                                <?php foreach ($indirizzi as $i): ?>
                                    <option value="<?= $h($i['code']) ?>" <?= $i['code'] === $s['indirizzo'] ? 'selected' : '' ?>>
                                        <?= $h($i['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="classe" class="fm-input fm-flex-1 fm-max-w-160 fm-stud-cls-sel" required>
                                <option value="">— sezione —</option>
                                <?php foreach ($sezioni as $c): ?>
                                    <option value="<?= $h($c['code']) ?>"
                                            data-ind="<?= $h($c['indirizzo'] ?? '') ?>"
                                            <?= $c['code'] === $s['classe'] ? 'selected' : '' ?>>
                                        <?= $h($c['code']) ?><?= $c['indirizzo'] ? ' — ' . $h($indLabel[$c['indirizzo']] ?? $c['indirizzo']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="fm-btn fm-btn--warn fm-btn--sm">Sposta</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($sezioni === []): ?>
            <p class="fm-muted fm-text-13 fm-mt-2">
                ⚠️ Questo istituto non ha sezioni censite, solo anni di corso. Finché non
                ci sono, gli studenti non possono essere spostati in una sezione.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php // Fuori da ogni condizione: il modulo serve anche senza istituto scelto
     // (invio automatico del select) e ogni blocco controlla da se' che il suo
     // pezzo di pagina esista (P8, 2026-09-04). ?>
<?= \App\Support\ViteManifest::script('js/entries/admin-sections.js') ?>

</div><?php /* /.fm-card aperto da page_head.php */ ?>
