<?php
/** @var list<array<string,mixed>> $rows */
/** @var array|null $flash */
/** @var string $csrf */
/** @var list<array{name:string,exists:bool,size:int,mtime:?int}> $miur_sources */
/** @var array{exists:bool,size:int,mtime:?int} $miur_index */

$fmtBytes = static function (int $b): string {
    if ($b <= 0) return '—';
    $u = ['B','KB','MB','GB']; $i = 0; $v = (float)$b;
    while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
    return number_format($v, $v >= 100 || $i === 0 ? 0 : 1) . ' ' . $u[$i];
};
$fmtMtime = static function (?int $t): string {
    return $t ? date('d/m/Y H:i', $t) : 'mai scaricato';
};

$page_title = '🏫 Istituti';
$breadcrumb = [['href' => '/admin', 'label' => 'Admin']];
$back_href  = '/admin';
$back_label = '← Torna alla Dashboard';
include __DIR__ . '/_partials/page_head.php';
?>

<?php if (!empty($flash)): ?>
    <div class="fm-alert fm-alert--<?= htmlspecialchars((string)$flash['type'], ENT_QUOTES) ?>" class="fm-mb-4">
        <strong><?= htmlspecialchars((string)($flash['title'] ?? ''), ENT_QUOTES) ?></strong>
        <div><?= $flash['message'] ?? '' ?></div>
    </div>
<?php endif; ?>

<section class="fm-admin-kpi">
    <div class="fm-d-flex fm-items-center fm-justify-between fm-gap-4">
        <h2 class="fm-admin-kpi__title">Istituti attivi (<?= count($rows) ?>)</h2>
        <a class="fm-btn fm-btn--primary" href="/admin/institutes/new">➕ Nuovo istituto</a>
    </div>
</section>

<section class="fm-mt-8">
    <?php if (empty($rows)): ?>
        <p class="fm-muted">Nessun istituto attivo.</p>
    <?php else: ?>
        <table class="fm-table">
            <thead>
                <tr>
                    <th scope="col">ID</th><th scope="col">Codice</th><th scope="col">Nome</th><th scope="col">Città</th><th scope="col">Regione</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="fm-code"><?= (int)$r['id'] ?></span></td>
                        <td><span class="fm-code"><?= htmlspecialchars((string)$r['code'], ENT_QUOTES) ?></span></td>
                        <td><?= htmlspecialchars((string)$r['name'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)($r['city'] ?? '—'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)($r['region'] ?? '—'), ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- ────────── Database scuole MIUR (opendata) ────────── -->
<section class="fm-mt-8" id="miur-schools">
    <h2 class="fm-admin-kpi__title">🏫 Database scuole (MIUR opendata) <button type="button" class="fm-infotip" aria-label="Info database scuole MIUR"><span class="fm-infotip__body" hidden>Usato dalla ricerca istituto in registrazione/onboarding. Scarica i file <strong>JSON</strong> dal catalogo opendata MIUR e <strong>caricali</strong> qui sotto. Sito da cui reperirli: <a href="https://dati.istruzione.it/opendata/opendata/catalogo/elements1/?area=Scuole" target="_blank" rel="noopener">dati.istruzione.it → Scuole ↗</a> — scarica il formato <strong>JSON</strong> dei dataset «Scuole statali» (<code>SCUANAGRAFESTAT…json</code>, ~51 MB) e «Scuole paritarie» (<code>SCUANAGRAFEPAR…json</code>, ~8 MB).</span></button></h2>

    <table class="fm-table fm-mb-3">
        <thead><tr><th scope="col">Sorgente</th><th scope="col">File</th><th scope="col">Stato</th><th scope="col">Dimensione</th><th scope="col">Aggiornato</th></tr></thead>
        <tbody>
            <?php
            $labels = ['scuole_miur.json' => 'Statali', 'scuole_miur_paritarie.json' => 'Paritarie'];
            foreach ($miur_sources as $s):
            ?>
                <tr>
                    <td><?= htmlspecialchars($labels[$s['name']] ?? $s['name'], ENT_QUOTES) ?></td>
                    <td><span class="fm-code"><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></span></td>
                    <td><?= $s['exists'] ? '✅ presente' : '— assente' ?></td>
                    <td><?= htmlspecialchars($fmtBytes((int)$s['size']), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($fmtMtime($s['mtime']), ENT_QUOTES) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="2"><strong>Indice ricerca</strong> <span class="fm-code">scuole_miur_index.json</span></td>
                <td><?= $miur_index['exists'] ? '✅ presente' : '— da generare' ?></td>
                <td><?= htmlspecialchars($fmtBytes((int)$miur_index['size']), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($fmtMtime($miur_index['mtime']), ENT_QUOTES) ?></td>
            </tr>
        </tbody>
    </table>

    <form id="fm-miur-form" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)($csrf ?? ''), ENT_QUOTES) ?>">
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
    <script>document.currentScript.previousElementSibling.addEventListener("submit",function(event){event.preventDefault();miurUpdate(event)})</script>
</section>

<script>
async function miurUpdate(e) {
    e.preventDefault();
    const form = e.target;
    const hasStatali = form.statali_file.files.length > 0;
    const hasParitarie = form.paritarie_file.files.length > 0;
    const status = document.getElementById('fm-miur-status');
    if (!hasStatali && !hasParitarie) {
        status.textContent = '✗ Seleziona almeno un file JSON';
        status.className = 'fm-inline-status fm-inline-status--error';
        return false;
    }
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    status.textContent = '⏳ Caricamento e indicizzazione…';
    status.className = 'fm-inline-status';
    try {
        const fd = new FormData(form);
        const res = await fetch('/admin/institutes/miur/update', {
            method: 'POST',
            body: fd,
            headers: { 'X-CSRF-Token': form._csrf.value, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (res.ok && json.ok) {
            status.textContent = '✓ Aggiornato: ' + json.records.toLocaleString('it-IT') + ' scuole indicizzate';
            status.className = 'fm-inline-status fm-inline-status--ok';
            setTimeout(() => location.reload(), 1500);
        } else {
            status.textContent = '✗ Errore: ' + (json.error || res.status) +
                (json.detail ? ' — ' + json.detail : '') + (json.field ? ' [' + json.field + ']' : '');
            status.className = 'fm-inline-status fm-inline-status--error';
            btn.disabled = false;
        }
    } catch (err) {
        status.textContent = '✗ Errore: ' + err.message;
        status.className = 'fm-inline-status fm-inline-status--error';
        btn.disabled = false;
    }
    return false;
}
</script>

</div><!-- /.fm-card -->
