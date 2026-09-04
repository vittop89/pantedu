<?php
/**
 * Pannello /admin/sections — incarichi docente↔sezione e classe degli studenti.
 *
 * NB CSP: niente handler inline (`on*=`), vietati dal guard
 * tools/ci/no-inline-handlers.mjs e bloccati da script-src-attr. Gli script
 * sono co-locati e agganciano `document.currentScript.previousElementSibling`,
 * come nelle altre view admin.
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
 * @var array{type:string,msg:string}|null $flash
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$page_title    = '🎯 Sezioni';
$page_subtitle = 'Chi insegna in quale sezione, e in quale sezione sta ogni studente.';
$breadcrumb    = [['label' => 'Admin'], ['label' => 'Sezioni']];
include __DIR__ . '/_partials/page_head.php';

// Le SIGLE degli indirizzi non si mostrano: sono una chiave interna (il join
// fra contenuti, studenti e incarichi) e all'utente non dicono nulla. Qui la
// mappa per risalire all'etichetta ovunque serva.
$indLabel = [];
foreach ($indirizzi as $_i) { $indLabel[(string)$_i['code']] = (string)$_i['label']; }

// Sezioni vere: un codice di solo numero e' l'anno di corso, non una sezione.
$sezioni = array_values(array_filter($classi, static fn($c) => (bool)preg_match('/^[1-9].+$/', (string)$c['code'])));
// Gli anni di corso: trasversali agli indirizzi, e non sono sezioni.
$anni = array_values(array_filter($classi, static fn($c) => (bool)preg_match('/^[1-9]$/', (string)$c['code'])));

// Sezioni raggruppate per anno: e' l'ordine con cui si ragiona.
$sezioniPerAnno = [];
foreach ($sezioni as $_c) {
    $sezioniPerAnno[substr((string)$_c['code'], 0, 1)][] = $_c;
}
ksort($sezioniPerAnno);
$annoLabel = ['1' => 'Prima', '2' => 'Seconda', '3' => 'Terza', '4' => 'Quarta', '5' => 'Quinta'];
?>

<?php if ($flash !== null): ?>
    <div class="fm-alert fm-alert--<?= $flash['type'] === 'ok' ? 'success' : 'danger' ?>" role="alert">
        <?= $h($flash['msg']) ?>
    </div>
<?php endif; ?>

<form method="GET" action="/admin/sections" class="fm-d-flex fm-gap-2 fm-items-center fm-mb-4">
    <label for="inst">Istituto:</label>
    <select id="inst" name="institute_id" class="fm-input fm-flex-1 fm-max-w-640">
        <option value="0">— scegli —</option>
        <?php foreach ($institutes as $i): ?>
            <option value="<?= (int)$i['id'] ?>" <?= (int)$i['id'] === $instituteId ? 'selected' : '' ?>>
                <?= $h($i['name']) ?> (<?= $h($i['code']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <script>document.currentScript.previousElementSibling.addEventListener("change",function(){this.form.submit()})</script>
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
        <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
        <?php /* Cosa il modulo mostrava quando e' stato compilato: senza, il
                 server non puo' distinguere "non l'ho spuntata" da "non c'era".
                 Lo riempie il JS, quindi se il JS non gira resta vuoto e il
                 salvataggio torna a essere solo additivo. */ ?>
        <input type="hidden" name="stato" id="fm-assign-stato-val" value="">
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-2">
            <select name="user_id" id="fm-assign-doc" class="fm-input fm-flex-1 fm-max-w-280" required>
                <option value="">— docente —</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= $h($t['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="indirizzo" id="fm-assign-ind" class="fm-input fm-flex-1 fm-max-w-300" required>
                <option value="">— indirizzo —</option>
                <?php foreach ($indirizzi as $i): ?>
                    <option value="<?= $h($i['code']) ?>"><?= $h($i['label']) ?></option>
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
                <?php /* Due gruppi, perche' sono due cose diverse: le SEZIONI
                         vengono dal MIUR e appartengono a un indirizzo; gli ANNI
                         sono trasversali e servono a coprire tutte le sezioni di
                         quell'anno con un incarico solo. Mescolati sembravano
                         classi vaganti della scuola. */ ?>
                <p class="fm-text-11 fm-muted fm-m-0 fm-mt-1">Sezioni dell'indirizzo scelto</p>
                <div id="fm-assign-sezioni">
                <?php /* Una riga per anno di corso: un elenco piatto ordinato per
                         codice mette 1A, 1AA, 1ALSS, 1BLSS, 2A... di seguito, e
                         trovarci la propria sezione diventa un lavoro. */ ?>
                <?php foreach ($sezioniPerAnno as $anno => $gruppo): ?>
                    <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap fm-mb-1" data-anno-riga="<?= $h($anno) ?>">
                        <span class="fm-text-11 fm-muted fm-w-20"><?= $h($annoLabel[$anno] ?? $anno) ?></span>
                        <?php foreach ($gruppo as $c): ?>
                            <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs"
                                   data-ind="<?= $h($c['indirizzo'] ?? '') ?>">
                                <input type="checkbox" name="classe[]" value="<?= $h($c['code']) ?>">
                                <strong><?= $h($c['code']) ?></strong>
                                <?php if (!empty($c['indirizzo'])): ?>
                                    <span class="fm-text-11 fm-muted"><?= $h($indLabel[$c['indirizzo']] ?? $c['indirizzo']) ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <p class="fm-text-11 fm-muted fm-m-0 fm-mt-2">
                    Tutto l'anno — un incarico qui copre <em>ogni</em> sezione di quell'anno
                </p>
                <div class="fm-d-flex fm-gap-3 fm-flex-wrap">
                <?php foreach ($anni as $c): ?>
                    <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs" data-anno="1">
                        <input type="checkbox" name="classe[]" value="<?= $h($c['code']) ?>">
                        <strong><?= $h($c['code']) ?></strong>
                        <span class="fm-muted"><?= $h($c['label']) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php /* Il filtro per indirizzo: una classe senza indirizzo e'
                 trasversale (gli anni di corso) e resta sempre visibile. */ ?>
        <script>
        (function () {
            var form = document.getElementById("fm-assign-form");
            var sel  = document.getElementById("fm-assign-ind");
            var box  = document.getElementById("fm-assign-sezioni");
            if (!form || !sel || !box) { return; }
            function applica() {
                var ind = (sel.value || "").toUpperCase();
                var visibili = 0;
                box.querySelectorAll("label[data-ind]").forEach(function (l) {
                    var suo = (l.getAttribute("data-ind") || "").toUpperCase();
                    var ok  = !ind || suo === "" || suo === ind;
                    // Servono ENTRAMBE le classi. Non basta aggiungere fm-d-none:
                    // in utilities.css e' definita PRIMA di fm-d-flex, stessa
                    // specificita', quindi display:flex vince e l'elemento resta
                    // visibile. Va tolta la classe che lo mostra.
                    l.classList.toggle("fm-d-none", !ok);
                    l.classList.toggle("fm-d-flex", ok);
                    if (!ok) { l.querySelector("input").checked = false; }
                    if (ok) { visibili++; }
                });
                // Una riga d'anno senza sezioni visibili e' rumore.
                box.querySelectorAll("[data-anno-riga]").forEach(function (r) {
                    var viva = r.querySelector("label:not(.fm-d-none)") !== null;
                    r.classList.toggle("fm-d-none", !viva);
                    r.classList.toggle("fm-d-flex", viva);
                });
                var vuoto = box.querySelector("[data-empty]");
                if (!vuoto) {
                    vuoto = document.createElement("p");
                    vuoto.setAttribute("data-empty", "1");
                    vuoto.className = "fm-muted fm-text-13 fm-m-0";
                    box.appendChild(vuoto);
                }
                vuoto.classList.toggle("fm-d-none", visibili > 0);
                vuoto.textContent = "Nessuna sezione censita per questo indirizzo.";
            }
            sel.addEventListener("change", applica);

            // ── Lo stato di partenza ────────────────────────────────────────
            // Il modulo mostra gli incarichi che il docente ha gia' in quel
            // corso. Da qui il salvataggio e' un "questo e' l'elenco": cio' che
            // si spunta viene assegnato, cio' a cui si toglie la spunta viene
            // tolto. Mostrare lo stato e poi ignorare le caselle tolte sarebbe
            // peggio che non mostrarlo: il modulo direbbe una cosa e ne farebbe
            // un'altra.
            var doc   = document.getElementById("fm-assign-doc");
            var stato = document.getElementById("fm-assign-stato-val");
            var mappa = {};
            try {
                mappa = JSON.parse(document.getElementById("fm-assign-stato").textContent || "{}");
            } catch (e) { mappa = {}; }

            function correnti() {
                var perDoc = mappa[doc && doc.value] || {};
                return perDoc[sel.value] || [];
            }
            function riflettiStato() {
                var gia = correnti();
                form.querySelectorAll("input[name='classe[]']").forEach(function (i) {
                    i.checked = gia.indexOf(i.value) !== -1;
                });
                // Le caselle nascoste dal filtro non contano: applica() le
                // sbianca, e lo stato deve restare quello dell'indirizzo scelto.
                applica();
                stato.value = gia.join(",");
            }
            if (doc) { doc.addEventListener("change", riflettiStato); }
            sel.addEventListener("change", riflettiStato);

            form.addEventListener("submit", function (ev) {
                var gia = correnti();
                var ora = Array.prototype.slice
                    .call(form.querySelectorAll("input[name='classe[]']:checked"))
                    .map(function (i) { return i.value; });
                var tolte = gia.filter(function (c) { return ora.indexOf(c) === -1; });
                if (tolte.length === 0) { return; }
                var q = tolte.length === 1
                    ? "Togliere l'incarico su " + tolte[0] + "?"
                    : "Togliere gli incarichi su " + tolte.join(", ") + "?";
                if (!window.confirm(q + "\nGli studenti di quelle sezioni non vedranno più i suoi contenuti.")) {
                    ev.preventDefault();
                }
            });

            applica();
            riflettiStato();
        })();
        </script>
    </form>

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
                        <form method="POST" action="/admin/sections/revoke">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="institute_id" value="<?= $instituteId ?>">
                            <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Revoca</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        (function () {
            var q = document.getElementById("fm-inc-q");
            var fi = document.getElementById("fm-inc-ind");
            var fa = document.getElementById("fm-inc-anno");
            var conta = document.getElementById("fm-inc-conta");
            var tab = document.getElementById("fm-inc-tabella");
            if (!tab || !q) { return; }
            var righe = Array.prototype.slice.call(tab.querySelectorAll("tbody tr"));
            function filtra() {
                var testo = (q.value || "").trim().toLowerCase();
                var visti = 0;
                righe.forEach(function (r) {
                    var ok = (!testo || r.getAttribute("data-doc").toLowerCase().indexOf(testo) !== -1)
                          && (!fi.value || r.getAttribute("data-ind") === fi.value)
                          && (!fa.value || r.getAttribute("data-anno") === fa.value);
                    // Su <tr> basta fm-d-none: nessuna utility di display
                    // concorrente, a differenza delle label flex.
                    r.classList.toggle("fm-d-none", !ok);
                    if (ok) { visti++; }
                });
                conta.textContent = visti + " di " + righe.length;
            }
            [q, fi, fa].forEach(function (el) {
                el.addEventListener("input", filtra);
                el.addEventListener("change", filtra);
            });
            filtra();
        })();
        </script>
    <?php endif; ?>
</div>

<div class="fm-card fm-mt-6">
    <h2 class="fm-mt-0">Materie dei docenti</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">
        Le materie fra cui scegliere sono quelle dell'istituto, importate dal MIUR in
        <a href="/admin/institutes#miur-schools">Istituti</a>. Un docente le propone da sé al
        primo accesso; qui si correggono.
        <br>Togliere una materia <strong>non cancella niente</strong>: la disattiva. I contenuti
        già pubblicati continuano a puntarci, e rimetterla è una spunta.
    </p>

    <?php if ($materie === []): ?>
        <div class="fm-alert fm-alert--warn" role="alert">
            <strong>Questo istituto non ha ancora materie.</strong>
            Nessun docente può sceglierne finché non le importi:
            <a href="/admin/institutes#miur-schools">Istituti → Dati MIUR → Adozioni</a>.
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

        <script>
        (function () {
            var q = document.getElementById("fm-mat-cerca");
            var corpo = document.getElementById("fm-mat-corpo");
            var conta = document.getElementById("fm-mat-conta");
            if (!q || !corpo) { return; }
            var righe = Array.prototype.slice.call(corpo.querySelectorAll("tr"));
            function filtra() {
                var t = q.value.trim().toLowerCase();
                var visti = 0;
                righe.forEach(function (tr) {
                    var ok = t === "" || (tr.dataset.doc || "").indexOf(t) !== -1;
                    tr.classList.toggle("fm-d-none", !ok);
                    if (ok) { visti++; }
                });
                conta.textContent = visti + " di " + righe.length + " docenti";
            }
            q.addEventListener("input", filtra);
            filtra();
        })();
        </script>
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
                <?php foreach ($classi as $c): ?>
                    <option value="<?= $h($c['code']) ?>"><?= $h($c['code']) ?></option>
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
        <script>
        (function () {
            var q     = document.getElementById("fm-stud-q");
            var fInd  = document.getElementById("fm-stud-ind");
            var fCls  = document.getElementById("fm-stud-cls");
            var fSenza= document.getElementById("fm-stud-senza");
            var conta = document.getElementById("fm-stud-conta");
            var tab   = document.getElementById("fm-stud-tabella");
            if (!tab) { return; }
            var righe = Array.prototype.slice.call(tab.querySelectorAll("tbody tr"));

            function filtra() {
                var testo = (q.value || "").trim().toLowerCase();
                var ind   = fInd.value, cls = fCls.value, senza = fSenza.checked;
                var visti = 0;
                righe.forEach(function (r) {
                    var ok = (!testo || r.getAttribute("data-user").toLowerCase().indexOf(testo) !== -1)
                          && (!ind   || r.getAttribute("data-ind") === ind)
                          && (!cls   || r.getAttribute("data-cls") === cls)
                          && (!senza || r.getAttribute("data-senza") === "1");
                    r.classList.toggle("fm-d-none", !ok);
                    if (ok) { visti++; }
                });
                conta.textContent = visti + " di " + righe.length;
            }
            [q, fInd, fCls, fSenza].forEach(function (el) {
                el.addEventListener("input", filtra);
                el.addEventListener("change", filtra);
            });
            filtra();

            // Nella riga, la tendina sezione segue l'indirizzo scelto: offrire a
            // uno studente dello scientifico una sezione dello sportivo lo
            // ancorerebbe a un corso non suo, e non vedrebbe nulla.
            tab.querySelectorAll("form").forEach(function (f) {
                var si = f.querySelector(".fm-stud-ind-sel");
                var sc = f.querySelector(".fm-stud-cls-sel");
                if (!si || !sc) { return; }
                function sync() {
                    var ind = (si.value || "").toUpperCase();
                    Array.prototype.slice.call(sc.options).forEach(function (o) {
                        if (!o.value) { return; }
                        var suo = (o.getAttribute("data-ind") || "").toUpperCase();
                        var ok = (!ind || suo === "" || suo === ind);
                        o.hidden = !ok;
                        o.disabled = !ok;
                        if (!ok && o.selected) { sc.value = ""; }
                    });
                }
                si.addEventListener("change", sync);
                sync();
            });
        })();
        </script>
        <?php if ($sezioni === []): ?>
            <p class="fm-muted fm-text-13 fm-mt-2">
                ⚠️ Questo istituto non ha sezioni censite, solo anni di corso. Finché non
                ci sono, gli studenti non possono essere spostati in una sezione.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

</div><?php /* /.fm-card aperto da page_head.php */ ?>
