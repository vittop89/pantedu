<?php
/**
 * Phase 25.R.5.3 — Crypto status dashboard + log custodia/cooperazione autorità.
 *
 * @var array<string,mixed> $stats
 * @var array<string,int>   $eventCounts
 * @var list<array>         $recent
 * @var list<string>        $eventTypes
 * @var string              $csrf
 * @var array|null          $flash
 * @var array               $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '🔐 Crypto Status & Custodia chiavi';
$page_subtitle = 'Stato KEK / KMS / Recovery + registro custodia chiavi + cooperazione autorità (Art. 32-34 GDPR).';
$breadcrumb    = [['label' => 'Crypto Status']];
include __DIR__ . '/_partials/page_head.php';
?>

<?php if (!empty($flash)): ?>
    <div class="fm-alert fm-alert--<?= ($flash['type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
        <?= $h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="fm-grid fm-grid--3 fm-mb-6" >
    <div class="fm-tile<?= empty($stats['kms_configured']) ? ' fm-tile--alert' : '' ?>">
        <h3>KMS_MASTER_KEY</h3>
        <div class="fm-big"><?= !empty($stats['kms_configured']) ? '✅ Configurata' : '❌ Mancante' ?></div>
        <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >
            <?= !empty($stats['kms_configured'])
                ? 'Custodita off-line dal data controller. Necessaria per decifrare tutte le KEK docenti.'
                : 'Imposta KMS_MASTER_KEY nel file .env per attivare envelope encryption.' ?>
        </p>
    </div>

    <div class="fm-tile">
        <h3>Recovery key service</h3>
        <div class="fm-big"><?= !empty($stats['recovery_configured']) ? '✅ Attivo' : '❌ Non configurato' ?></div>
        <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >
            <?= (int)$stats['teachers_with_recovery'] ?> docenti hanno una Recovery Key attiva.
        </p>
    </div>

    <div class="fm-tile">
        <h3>KEK docenti</h3>
        <div class="fm-big"><?= (int)$stats['teacher_keys_count'] ?></div>
        <p class="fm-muted fm-text-em-md fm-mt-1 fm-mb-0" >
            Chiavi docente in <code>teacher_keys</code>.<br>
            Ultima rotazione: <?= $h($stats['latest_rotation'] ?? '—') ?>
        </p>
    </div>
</section>

<details class="fm-mb-6">
    <summary class="fm-cursor-pointer fm-fw-600 fm-py-2">📖 Procedura cooperazione autorità</summary>
    <div class="fm-card fm-mt-2" >
        <p>Per richieste lecite di accesso ai dati cifrati da parte di:</p>
        <ul>
            <li><strong>Autorità giudiziaria</strong> (tribunale, PM con autorizzazione)</li>
            <li><strong>Garante Privacy</strong></li>
            <li><strong>Forze di polizia</strong> (con decreto motivato)</li>
            <li><strong>Eredi</strong> del docente (con documentazione)</li>
            <li><strong>Docente stesso</strong> per recupero account smarrito (Art. 15 GDPR)</li>
        </ul>
        <p>La procedura completa è documentata in
            <code>docs/security/operations/authority-cooperation.md</code>.
            Sintesi:</p>
        <ol>
            <li>Registra <strong>authority_request</strong> con base giuridica + decreto.</li>
            <li>Valuta legittimità entro 72h.</li>
            <li>Se OK: <strong>authority_granted</strong> + estrazione mirata (<strong>kek_emergency_access</strong>).</li>
            <li>Consegna documentata: <strong>data_recovered</strong> → <strong>data_provided</strong>.</li>
            <li>Notifica interessati (Art. 14 GDPR, salvo segreto investigativo).</li>
        </ol>
    </div>
</details>

<section class="fm-card fm-mb-6" >
    <h2 class="fm-mt-0">+ Registra evento custodia / cooperazione</h2>
    <form method="POST" action="/admin/crypto-status/event" class="fm-form-grid">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

        <label>
            <span class="fm-form-label-text">Tipo evento *</span>
            <select name="event_type" required class="fm-w-full">
                <?php foreach ($eventTypes as $t): ?>
                    <option value="<?= $t ?>"><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span class="fm-form-label-text">Data/ora evento</span>
            <input type="datetime-local" name="occurred_at"
                   value="<?= $h(date('Y-m-d\TH:i')) ?>" class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Teacher ID coinvolto<br><small>(se applicabile)</small></span>
            <input type="number" name="teacher_id" min="1" class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Autorità richiedente</span>
            <input type="text" name="authority_name" maxlength="160"
                   placeholder="es. Tribunale di Milano" class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Riferimento procedimento</span>
            <input type="text" name="authority_ref" maxlength="255"
                   placeholder="es. n. 1234/2026 R.G.N.R." class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Base giuridica</span>
            <input type="text" name="legal_basis" maxlength="255"
                   placeholder="es. Art. 6(1)(c) GDPR + decreto 14/2026" class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Custode <small>(se kms_*)</small></span>
            <input type="text" name="custodian_name" maxlength="160"
                   placeholder="es. Notaio Mario Rossi" class="fm-w-full">
        </label>

        <label>
            <span class="fm-form-label-text">Luogo custodia</span>
            <input type="text" name="custody_location" maxlength="255"
                   placeholder="es. Cassetta sicurezza UniCredit" class="fm-w-full">
        </label>

        <label class="fm-form-fullrow">
            <span class="fm-form-label-text">Descrizione *</span>
            <textarea name="description" required minlength="10" rows="3"
                      class="fm-w-full"></textarea>
        </label>

        <label class="fm-form-fullrow">
            <span class="fm-form-label-text">URL evidenza <small>(decreto firmato, PEC, ricevuta)</small></span>
            <input type="url" name="evidence_url" maxlength="512" class="fm-w-full">
        </label>

        <div class="fm-form-actions">
            <button type="submit" class="fm-btn fm-btn--primary">📝 Registra evento</button>
        </div>
    </form>
</section>

<section>
    <div class="fm-d-flex fm-items-center fm-justify-between fm-flex-wrap fm-gap-2 fm-mb-3">
        <h2 class="fm-m-0">Eventi custody recenti (ultimi 50)</h2>
        <div class="fm-d-flex fm-gap-1 fm-flex-wrap">
            <a href="/admin/crypto-status/export?format=csv" class="fm-btn fm-btn--ghost fm-btn--sm" data-full-reload>📊 Export CSV</a>
            <a href="/admin/crypto-status/export?format=json" class="fm-btn fm-btn--ghost fm-btn--sm" data-full-reload>📋 Export JSON</a>
        </div>
    </div>
    <?php if (empty($recent)): ?>
        <div class="fm-empty">Nessun evento registrato. La migrazione 061 potrebbe non essere stata applicata.</div>
    <?php else: ?>
        <table class="fm-table fm-table--expandable">
            <thead>
                <tr>
                    <th scope="col" class="fm-w-6"></th>
                    <th scope="col">Quando</th><th scope="col">Tipo</th><th scope="col">Teacher</th>
                    <th scope="col">Autorità</th><th scope="col">Custode</th><th scope="col">Descrizione (sintesi)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $e): ?>
                    <?php
                    $descShort = mb_substr((string)$e['description'], 0, 120);
                    $descTruncated = mb_strlen((string)$e['description']) > 120;
                    ?>
                    <tr class="fm-row-summary">
                        <td>
                            <button type="button" class="fm-row-toggle"
                                    aria-expanded="false"
                                    title="Mostra dettagli completi">▶</button>
                        </td>
                        <td><?= $h($e['occurred_at']) ?></td>
                        <td><span class="fm-badge fm-badge--<?= $h($e['event_type']) ?>"><?= $h($e['event_type']) ?></span></td>
                        <td><?= !empty($e['teacher_id']) ? '#' . (int)$e['teacher_id'] : '—' ?></td>
                        <td>
                            <?= $h($e['authority_name'] ?? '—') ?>
                            <?php if (!empty($e['authority_ref'])): ?>
                                <br><small><?= $h($e['authority_ref']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $h($e['custodian_name'] ?? '—') ?>
                            <?php if (!empty($e['custody_location'])): ?>
                                <br><small><?= $h($e['custody_location']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $h($descShort) ?><?php if ($descTruncated): ?>…<?php endif; ?>
                        </td>
                    </tr>
                    <tr class="fm-row-detail" hidden>
                        <td colspan="7" class="fm-row-detail__cell">
                            <dl class="fm-row-detail__grid">
                                <dt>ID evento</dt><dd>#<?= (int)$e['id'] ?></dd>
                                <dt>Recorded at</dt><dd><?= $h($e['recorded_at'] ?? '—') ?></dd>
                                <dt>Actor user_id</dt><dd><?= !empty($e['actor_user_id']) ? '#' . (int)$e['actor_user_id'] : '—' ?></dd>
                                <dt>Base giuridica</dt><dd><?= $h($e['legal_basis'] ?? '—') ?></dd>
                                <dt>Descrizione completa</dt>
                                <dd><pre class="fm-row-detail__pre"><?= $h($e['description']) ?></pre></dd>
                                <dt>URL evidenza</dt>
                                <?php /* Audit 25.R.31 — link solo se schema http/https (no javascript:/data:). */ ?>
                                <dd><?php if (!empty($e['evidence_url']) && preg_match('#^https?://#i', (string)$e['evidence_url'])): ?>
                                    <a href="<?= $h($e['evidence_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h($e['evidence_url']) ?></a>
                                <?php elseif (!empty($e['evidence_url'])): ?>
                                    <span><?= $h($e['evidence_url']) ?></span>
                                <?php else: ?>—<?php endif; ?></dd>
                            </dl>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        (function() {
            // Toggle expandable rows
            document.querySelectorAll('.fm-row-toggle').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var summary = btn.closest('tr');
                    var detail = summary.nextElementSibling;
                    var expanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', !expanded);
                    btn.textContent = expanded ? '▶' : '▼';
                    detail.hidden = expanded;
                });
            });
        })();
        </script>
    <?php endif; ?>
</section>

</div><?php /* /.fm-card from page_head */ ?>
