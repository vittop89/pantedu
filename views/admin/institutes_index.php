<?php
/** @var list<array<string,mixed>> $rows */
/** @var array|null $flash */
/** @var string $csrf */
/** @var list<array{name:string,exists:bool,size:int,mtime:?int}> $miur_sources */
/** @var array{exists:bool,size:int,mtime:?int} $miur_index */
/** @var array{path:string,exists:bool,readable:bool,valid:bool,detail:string,indirizzi:int,materie:int} $alias_status */

$h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
$fmtBytes = static function (int $b): string {
    if ($b <= 0) return '—';
    $u = ['B','KB','MB','GB']; $i = 0; $v = (float)$b;
    while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
    return number_format($v, $v >= 100 || $i === 0 ? 0 : 1) . ' ' . $u[$i];
};
$fmtMtime = static function (?int $t): string {
    return $t ? date('d/m/Y H:i', $t) : 'mai scaricato';
};

$attivi   = array_filter($rows, static fn(array $r): bool => (int)$r['active'] === 1);
$sospesi  = count($rows) - count($attivi);
// Un istituto senza indirizzi resta scegliibile in registrazione ma non mostra
// niente a chi ci si iscrive: e' il caso che questa pagina deve far vedere.
// Un istituto senza indirizzi non mostra niente a chi si iscrive; uno senza
// materie non lascia ai docenti niente da dichiarare al primo accesso. Sono
// due mancanze diverse ma la cura e' la stessa: importare le adozioni.
$gusci = array_filter(
    $rows,
    static fn(array $r): bool => (int)$r['active'] === 1
        && ((int)$r['indirizzi'] === 0 || (int)$r['materie'] === 0)
);

$page_title = '🏫 Istituti';
$breadcrumb = [['href' => '/admin', 'label' => 'Admin']];
$back_href  = '/admin';
$back_label = '← Torna alla Dashboard';
include __DIR__ . '/_partials/page_head.php';
?>

<?php if (!empty($flash)): ?>
    <div class="fm-alert fm-alert--<?= $h((string)$flash['type']) ?> fm-mb-4">
        <strong><?= $h((string)($flash['title'] ?? '')) ?></strong>
        <div><?= $flash['message'] ?? '' ?></div>
    </div>
<?php endif; ?>

<section class="fm-admin-kpi">
    <div class="fm-d-flex fm-items-center fm-justify-between fm-gap-4">
        <h2 class="fm-admin-kpi__title">
            Istituti (<?= count($rows) ?>)
            <span class="fm-muted fm-text-13">
                — <?= count($attivi) ?> attivi<?= $sospesi > 0 ? ', ' . $sospesi . ' sospesi' : '' ?>
            </span>
        </h2>
        <a class="fm-btn fm-btn--primary" href="/admin/institutes/new">➕ Nuovo istituto</a>
    </div>
</section>

<?php if ($gusci !== []): ?>
    <div class="fm-alert fm-alert--warning fm-mt-4">
        <strong><?= count($gusci) ?> istituto/i attivo/i non ancora pronto/i.</strong>
        <div class="fm-text-13">
            Senza <strong>indirizzi</strong> chi si iscrive trova la scuola nell'elenco ma poi non
            vede né corsi né classi. Senza <strong>materie</strong> i docenti non hanno nulla da
            dichiarare al primo accesso, e i loro contenuti restano senza categoria.
            Due strade: importare le adozioni MIUR qui sotto, oppure sospendere l'istituto
            finché non è pronto.
        </div>
    </div>
<?php endif; ?>

<section class="fm-mt-8">
    <?php if (empty($rows)): ?>
        <p class="fm-muted">Nessun istituto.</p>
    <?php else: ?>
        <table class="fm-table">
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Codice MIUR</th>
                    <th scope="col">Nome</th>
                    <th scope="col">Città</th>
                    <th scope="col" class="fm-text-right">Indirizzi</th>
                    <th scope="col" class="fm-text-right">Sezioni</th>
                    <th scope="col" class="fm-text-right">Materie</th>
                    <th scope="col" class="fm-text-right">Docenti</th>
                    <th scope="col" class="fm-text-right">Studenti</th>
                    <th scope="col">Stato</th>
                    <th scope="col" title="Compilazioni dei modelli istituzionali: sul server o solo nel browser del docente">Compilazioni</th>
                    <th scope="col" title="Chi dei docenti può legarsi a una sezione (ADR-041)">Sezioni dei docenti</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $id      = (int)$r['id'];
                    $attivo  = (int)$r['active'] === 1;
                    $code    = (string)$r['code'];
                    // Codice MIUR reale: due lettere di provincia + 8 alfanumerici.
                    // Un codice sintetico e' un residuo da prima che ci fosse
                    // l'anagrafica, e non aggancia nessun dataset ministeriale.
                    $reale   = (bool)preg_match('/^[A-Z]{2}[A-Z0-9]{8}$/', $code);
                    ?>
                    <tr<?= $attivo ? '' : ' class="fm-muted"' ?>>
                        <td><span class="fm-code"><?= $id ?></span></td>
                        <td>
                            <span class="fm-code"><?= $h($code) ?></span>
                            <?php if (!$reale): ?>
                                <span class="fm-badge fm-badge--severity-medium" title="Non è un codice MIUR: non aggancia i dataset ministeriali">sintetico</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $h((string)$r['name']) ?></td>
                        <td><?= $h((string)($r['city'] ?? '—')) ?></td>
                        <td class="fm-text-right"><?= (int)$r['indirizzi'] ?: '<span class="fm-muted">0</span>' ?></td>
                        <td class="fm-text-right"><?= (int)$r['sezioni'] ?: '<span class="fm-muted">0</span>' ?></td>
                        <td class="fm-text-right"><?= (int)$r['materie'] ?: '<span class="fm-muted">0</span>' ?></td>
                        <td class="fm-text-right"><?= (int)$r['docenti'] ?></td>
                        <td class="fm-text-right"><?= (int)$r['studenti'] ?></td>
                        <td>
                            <?php /* ADR-035 — il vocabolario con la provenienza di ogni voce,
                                     le spunte dei docenti e i contenuti che ci puntano. */ ?>
                            <a class="fm-btn fm-btn--ghost fm-btn--sm fm-mb-2" href="/admin/institutes/<?= $id ?>/catalogo" data-full-reload>📚 Catalogo</a>
                            <form method="post" action="/admin/institutes/<?= $id ?>/active" class="fm-d-flex fm-items-center fm-gap-2">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="_audit_reason" value="Istituto #<?= $id ?> sospeso o riattivato dal pannello Istituti">
                                <input type="hidden" name="active" value="<?= $attivo ? '0' : '1' ?>">
                                <span><?= $attivo ? '✅ attivo' : '⏸️ sospeso' ?></span>
                                <button type="submit" class="fm-btn fm-btn--sm">
                                    <?= $attivo ? 'Sospendi' : 'Riattiva' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php $salva = !array_key_exists('compilation_storage', $r) || (int)$r['compilation_storage'] === 1; ?>
                            <form method="post" action="/admin/institutes/<?= $id ?>/compilation-storage" class="fm-d-flex fm-items-center fm-gap-2">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="_audit_reason" value="Istituto #<?= $id ?>: scelta di dove si salvano le compilazioni dei docenti">
                                <input type="hidden" name="storage" value="<?= $salva ? '0' : '1' ?>">
                                <span title="<?= $salva
                                    ? 'Le compilazioni dei modelli istituzionali (piano annuale, relazione finale, schede) si salvano sul server, cifrate con la chiave del docente'
                                    : 'Le compilazioni dei modelli istituzionali restano nel browser del docente: sul server resta il solo modello' ?>">
                                    <?= $salva ? '💾 sul server' : '🖥️ solo nel browser' ?>
                                </span>
                                <button type="submit" class="fm-btn fm-btn--sm">
                                    <?= $salva ? 'Solo browser' : 'Sul server' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php $modoSezioni = (string)($r['sezioni_docenti'] ?? \App\Services\SezioniDeiDocenti::PREDEFINITA); ?>
                            <form method="post" action="/admin/institutes/<?= $id ?>/sezioni-docenti" class="fm-d-flex fm-items-center fm-gap-2">
                                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="_audit_reason" value="Istituto #<?= $id ?>: regola su chi dei docenti si lega a una sezione (ADR-041)">
                                <label class="fm-sr-only" for="fm-sezioni-<?= $id ?>">Sezioni dei docenti</label>
                                <select id="fm-sezioni-<?= $id ?>" name="modalita" class="fm-select fm-select--sm">
                                    <?php foreach ([
                                        \App\Services\SezioniDeiDocenti::SOLO_INCARICATI => 'Solo incaricati',
                                        \App\Services\SezioniDeiDocenti::NESSUNO         => 'Nessuno (solo anni)',
                                        \App\Services\SezioniDeiDocenti::TUTTI           => 'Tutti',
                                    ] as $valore => $etichetta): ?>
                                        <option value="<?= $h($valore) ?>"<?= $valore === $modoSezioni ? ' selected' : '' ?>><?= $h($etichetta) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="fm-btn fm-btn--sm">Imposta</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="fm-muted fm-text-13 fm-mt-2">
            Sospendere toglie l'istituto dalle liste in cui lo si <em>sceglie</em> — registrazione,
            collegamento docente, selettori admin. Non cancella niente e non scollega nessuno:
            studenti e docenti già dentro continuano a lavorare.
        </p>
        <p class="fm-muted fm-text-13 fm-mt-1">
            <strong>Sezioni dei docenti</strong> (ADR-041): l'elenco delle sezioni di una scuola è pubblico,
            il legame «questo docente insegna in 2A» no, e messo insieme per molti docenti ricostruisce
            l'organigramma. «Solo incaricati»: una classe, anno o sezione, la usa solo il docente che ne ha
            l'incarico (<a class="fm-link-inline" href="/admin/sections">Sezioni e incarichi</a>); l'anno vale per
            tutte le sue sezioni in quell'indirizzo. «Tutti»: ogni docente, anni e sezioni. «Nessuno»: nessuno
            usa le sezioni, tutti usano gli anni. Le spunte che la modalità non ammette si sospendono, non si
            cancellano, e si riprendono se la si allarga.
        </p>
        <p class="fm-muted fm-text-13 fm-mt-1">
            <strong>Compilazioni dei modelli istituzionali</strong> (piano annuale, relazione finale,
            schede di progetto e di recupero): per impostazione si salvano sul server, cifrate con la
            chiave del docente. «Solo nel browser» le tiene sul dispositivo del docente, che esporta il
            PDF e lo deposita nei sistemi della scuola: sul server resta il solo modello. Si imposta per
            Istituto. La decide il Titolare della piattaforma, non l'Istituto. Le compilazioni già salvate restano leggibili e
            cancellabili dal docente; nuovi salvataggi vengono rifiutati.
        </p>
    <?php endif; ?>
</section>

<!-- ────────── Dataset MIUR (opendata) ────────── -->
<section class="fm-mt-8" id="miur-schools">
    <h2 class="fm-admin-kpi__title">📚 Dati MIUR (opendata)</h2>
    <p class="fm-muted fm-text-13 fm-mb-3">
        La piattaforma usa <strong>due</strong> dataset del catalogo
        <a class="fm-link-inline" href="https://dati.istruzione.it/opendata/opendata/catalogo/" target="_blank" rel="noopener">dati.istruzione.it ↗</a>,
        che rispondono a domande diverse: l'<strong>anagrafica</strong> dice quali scuole esistono,
        le <strong>adozioni</strong> dicono quali indirizzi e classi sono davvero attivi dentro una scuola.
        Nessuno dei due sostituisce l'altro.
    </p>

    <!-- ── 1. Anagrafica: la ricerca scuola in registrazione ── -->
    <h3 class="fm-card__title">1 · Anagrafica scuole — <span class="fm-muted">ricerca istituto in registrazione</span></h3>
    <p class="fm-muted fm-text-13 fm-mb-2">
        È l'unica fonte del codice MIUR vero, quello con cui l'istituto viene poi agganciato
        agli altri dataset. L'indice viene letto a ogni ricerca, quindi questi file devono
        restare sul server. Dal catalogo, formato <strong>JSON</strong>:
        «Scuole statali» (<code>SCUANAGRAFESTAT…json</code>, ~51 MB) e
        «Scuole paritarie» (<code>SCUANAGRAFEPAR…json</code>, ~8 MB).
    </p>

    <table class="fm-table fm-mb-3">
        <thead><tr><th scope="col">Sorgente</th><th scope="col">File</th><th scope="col">Stato</th><th scope="col">Dimensione</th><th scope="col">Aggiornato</th></tr></thead>
        <tbody>
            <?php
            $labels = ['scuole_miur.json' => 'Statali', 'scuole_miur_paritarie.json' => 'Paritarie'];
            foreach ($miur_sources as $s):
            ?>
                <tr>
                    <td><?= $h($labels[$s['name']] ?? $s['name']) ?></td>
                    <td><span class="fm-code"><?= $h($s['name']) ?></span></td>
                    <td><?= $s['exists'] ? '✅ presente' : '— assente' ?></td>
                    <td><?= $h($fmtBytes((int)$s['size'])) ?></td>
                    <td><?= $h($fmtMtime($s['mtime'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="2"><strong>Indice ricerca</strong> <span class="fm-code">scuole_miur_index.json</span></td>
                <td><?= $miur_index['exists'] ? '✅ presente' : '— da generare' ?></td>
                <td><?= $h($fmtBytes((int)$miur_index['size'])) ?></td>
                <td><?= $h($fmtMtime($miur_index['mtime'])) ?></td>
            </tr>
        </tbody>
    </table>

    <form data-fm-action="miurUpdate" id="fm-miur-form" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <div class="fm-waf-kv fm-mb-3">
            <label for="miur_statali">File JSON scuole statali</label>
            <input type="file" id="miur_statali" name="statali_file" accept=".json,application/json">

            <label for="miur_paritarie">File JSON scuole paritarie</label>
            <input type="file" id="miur_paritarie" name="paritarie_file" accept=".json,application/json">
        </div>
        <p class="fm-muted fm-text-13 fm-mb-2">
            Carica almeno un file. L'elaborazione può richiedere qualche secondo
            (lettura + indicizzazione) — non chiudere la pagina finché non compare l'esito.
        </p>
        <div class="fm-d-flex fm-gap-2 fm-items-center">
            <button type="submit" class="fm-btn fm-btn--primary">⬆️ Carica e aggiorna indice</button>
            <span id="fm-miur-status" class="fm-inline-status fm-self-center"></span>
        </div>
    </form>

    <!-- ── 2. Adozioni: indirizzi e sezioni reali ── -->
    <h3 class="fm-card__title fm-mt-8">2 · Adozioni libri di testo — <span class="fm-muted">indirizzi e sezioni della scuola</span></h3>
    <p class="fm-muted fm-text-13 fm-mb-2">
        È un elenco di adozioni, non un'anagrafe di classi — ed è proprio questo a renderlo
        affidabile: se una classe ha adottato un libro, quella classe esiste. È l'unica fonte
        che dia sulla stessa riga l'accoppiata <em>sezione ↔ indirizzo</em>, che serve a mostrare
        allo studente solo le classi del suo corso.
    </p>
    <p class="fm-muted fm-text-13 fm-mb-2">
        Dalla stessa riga arriva anche la <strong>materia</strong> (campo <code>DISCIPLINA</code>):
        un import solo costruisce indirizzi, sezioni e materie della scuola. Sono le tre cose che
        tutto il resto usa come vocabolario — i docenti possono <em>attivare</em> quello che c'è,
        non aggiungerne di nuove.
    </p>
    <p class="fm-muted fm-text-13 fm-mb-3">
        Dal catalogo, ambito «Adozioni libri di testo», formato <strong>CSV</strong>: è
        <strong>uno per regione</strong> (Lombardia: <code>ALTLOMBARDIA…csv</code>, ~50 MB), non
        nazionale. Aggiornato settimanalmente da luglio a novembre, quindi riflette l'anno in corso.
        Serve solo al momento dell'import: nessuna pagina lo legge a runtime.
    </p>

    <?php if (!$alias_status['valid']): ?>
        <div class="fm-alert fm-alert--<?= $alias_status['exists'] ? 'danger' : 'warn' ?>" role="alert">
            <strong>Registro alias non utilizzabile — l'import si fermerebbe qui.</strong>
            <div class="fm-text-13">
                <?= $h($alias_status['detail']) ?><br>
                <span class="fm-code"><?= $h($alias_status['path']) ?></span>
            </div>
        </div>
    <?php else: ?>
        <p class="fm-muted fm-text-13">
            Registro alias: <?= (int)$alias_status['indirizzi'] ?> indirizzi e
            <?= (int)$alias_status['materie'] ?> materie con una sigla decisa a mano.
            Sono le voci che il derivatore non indovinerebbe da solo.
        </p>
    <?php endif; ?>

    <form method="post" action="/admin/institutes/miur/adozioni" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Caricamento del dataset MIUR delle adozioni dal pannello Istituti">
        <div class="fm-waf-kv fm-mb-3">
            <label for="adozioni_file">File CSV adozioni (regione)</label>
            <input type="file" id="adozioni_file" name="adozioni_file" accept=".csv,text/csv" required>

            <label for="adozioni_institute">Limita a un istituto</label>
            <select id="adozioni_institute" name="institute_code" class="fm-input fm-max-w-300">
                <option value="">Tutti gli istituti a tabella</option>
                <?php foreach ($rows as $r): ?>
                    <option value="<?= $h((string)$r['code']) ?>">
                        <?= $h((string)$r['code']) ?> — <?= $h((string)$r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="fm-muted fm-text-13 fm-mb-2">
            Il caricamento mostra un'<strong>anteprima</strong> e non scrive niente. I codici degli
            indirizzi sono proposte: vanno guardati prima di confermare, perché una volta che i
            docenti ci hanno agganciato contenuti cambiarli non è più una scelta ma una migrazione.
        </p>
        <button type="submit" class="fm-btn fm-btn--primary">⬆️ Carica e mostra anteprima</button>
    </form>
</section>

<?= \App\Support\ViteManifest::script('js/entries/admin-institutes.js') ?>

</div><!-- /.fm-card -->
