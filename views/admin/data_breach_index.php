<?php
/** @var list<array> $rows @var array<string,int> $counts @var string|null $statusFilter @var array $user */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '🚨 Data Breach Register';
$page_subtitle = 'Registro incident Art. 33-34 GDPR. Append-only. SLA notifica Garante: 72h da rilevamento.';
$breadcrumb    = [['label' => 'GDPR', 'href' => '/admin/gdpr'], ['label' => 'Data Breach']];
include __DIR__ . '/_partials/page_head.php';
$gdpr_current = 'breach';
include __DIR__ . '/_partials/gdpr_nav.php';
?>

<?php /* 2026-09-22 — i primi passi in chiaro, perché in emergenza non si
   scarica niente: si legge. Il piano completo è un clic più in là. Il testo
   qui è la Fase 0 e la Fase 1 di docs/privacy/data_breach_runbook.md,
   ridotte all'osso: se quelle cambiano, cambia anche questo riquadro. */ ?>
<aside class="fm-card fm-mb-4" role="note" style="border-left:4px solid var(--fm-risdoc-privacy-head-border,#dc143c)">
    <h2 class="fm-mt-0">Se è appena successo</h2>
    <p class="fm-m-0 fm-mb-2"><strong>Le settantadue ore dell'art. 33 non decorrono dal guasto: decorrono da quando ne sei venuto a conoscenza.</strong> Il primo compito non è riparare — è fermare l'ora e scriverla.</p>
    <ol class="fm-m-0 fm-mb-2">
        <li><strong>Apri l'incidente adesso</strong> e metti <code>detected_at</code> all'istante in cui hai saputo. Quel campo non si può più correggere, ed è voluto: è l'unica data che, spostata, falsificherebbe il rispetto del termine.</li>
        <li><strong>Non cancellare niente</strong>, nemmeno ciò che sembra spazzatura: è la prova.</li>
        <li><strong>Non rispondere ancora a nessuno.</strong> Una prima risposta sbagliata è difficile da ritirare, e non c'è nessuna fretta nelle prime ore.</li>
        <li><strong>Chiediti di chi sono i dati.</strong> Se un docente ha scritto di uno studente in un campo a testo libero, titolare è il suo <strong>Istituto</strong>, non noi: va avvisato senza ingiustificato ritardo, perché siamo gli unici a poterlo sapere. Si registra qui, con «Marca avvisato il titolare».</li>
    </ol>
    <p class="fm-m-0"><a href="/admin/data-breach/piano" class="fm-btn fm-btn--ghost" download>📄 Scarica il piano completo</a></p>
</aside>

<div class="fm-d-flex fm-justify-end fm-mb-4">
    <a href="/admin/data-breach/new" class="fm-btn fm-btn--danger" data-full-reload>+ Nuovo incident</a>
</div>

<div class="fm-toolbar fm-d-flex fm-gap-1 fm-flex-wrap fm-m-0 fm-mb-4" >
    <?php
    $statuses = ['' => 'Tutti', 'detected' => 'Detected', 'assessing' => 'Assessing', 'notified_garante' => 'Notif. Garante', 'notified_users' => 'Notif. utenti', 'closed' => 'Closed'];
    foreach ($statuses as $k => $label):
        $active = ($statusFilter ?? '') === $k;
        $href = '/admin/data-breach' . ($k !== '' ? '?status=' . $k : '');
        $count = $k === '' ? array_sum($counts ?? []) : (int)($counts[$k] ?? 0);
    ?>
        <a href="<?= $h($href) ?>" class="fm-btn fm-btn--ghost<?= $active ? ' is-active' : '' ?>" data-full-reload>
            <?= $h($label) ?> <span class="fm-muted">(<?= $count ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="fm-empty">Nessun incident registrato 🎉</div>
<?php else: ?>
    <table class="fm-table">
        <thead>
            <tr>
                <th scope="col">ID</th><th scope="col">Detected</th><th scope="col">Severity</th><th scope="col">Affected</th>
                <th scope="col">Status</th><th scope="col">72h SLA</th><th scope="col">Notif. utenti</th><th scope="col">Azione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $id = (int)$r['id'];
                $sev = (string)$r['severity'];
                $detectedTs = strtotime((string)$r['detected_at']) ?: time();
                $deadlineTs = $detectedTs + 72 * 3600;
                $slaOk = !empty($r['notified_garante_at'])
                    ? (strtotime((string)$r['notified_garante_at']) <= $deadlineTs)
                    : (time() <= $deadlineTs);
            ?>
                <tr>
                    <td>#<?= $id ?></td>
                    <td><?= $h($r['detected_at']) ?></td>
                    <td><span class="fm-badge fm-badge--severity-<?= $h($sev) ?>"><?= $h($sev) ?></span></td>
                    <td><?= $r['affected_users_count'] !== null ? (int)$r['affected_users_count'] : '—' ?></td>
                    <td><span class="fm-status fm-status--<?= $h($r['status']) ?>"><?= $h($r['status']) ?></span></td>
                    <td><?= $slaOk
                        ? '<span class="fm-status fm-status--new">✅ OK</span>'
                        : '<span class="fm-status fm-status--spam">❌ scaduto</span>' ?></td>
                    <td><?= !empty($r['notified_users_at']) ? '✅ ' . $h($r['notified_users_at']) : '—' ?></td>
                    <td><a href="/admin/data-breach/<?= $id ?>" class="fm-btn fm-btn--sm">Dettaglio →</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
