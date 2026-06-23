<?php
/**
 * Phase S2 F3 (ADR-017) — Pannello /admin/system/deployment.
 *
 * @var string $csrf
 * @var array{mode:string, institute_owner_email:string, institute_legal_name:string, source:string} $snapshot
 * @var int    $active_users
 * @var string $flash
 * @var string $flash_kind
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '⚙️ Deployment Mode';
$page_subtitle = 'Switch tra modalità singolo docente (S1) e istituto multi-docente (S2).';
$breadcrumb    = [['label' => 'System'], ['label' => 'Deployment']];
include __DIR__ . '/../_partials/page_head.php';

$isInstitute = $snapshot['mode'] === 'institute';
$flashLabels = [
    'switched_to_institute' => 'Switch a modo INSTITUTE completato. Self-signup ora aperta.',
    'switched_to_single'    => 'Switch a modo SINGLE completato. Self-signup chiusa.',
    'reset_done'            => 'Runtime override rimosso. Modo ora deriva da .env.',
    'invalid_email'         => 'Email DPO non valida.',
    'invalid_name'          => 'Ragione sociale istituto mancante o troppo lunga.',
    'invalid_action'        => 'Azione non riconosciuta.',
    'down_switch_blocked'   => 'Switch a SINGLE bloccato: ci sono ' . (int)$active_users . ' utenti attivi (>1). Disattiva o anonimizza prima.',
    'exception'             => 'Errore tecnico (vedi log).',
    'class_added'           => 'Classe aggiunta alle ammesse alla registrazione.',
    'class_removed'         => 'Classe rimossa dalle ammesse.',
    'class_invalid'         => 'Indirizzo/classe non validi.',
    'cap_saved'             => 'Profilo capabilities salvato.',
    'cap_deleted'           => 'Profilo eliminato.',
    'cap_assigned'          => 'Profilo assegnato al docente.',
    'cap_invalid'           => 'Dati profilo non validi (o tentata eliminazione del profilo default).',
];
$allowed_classes = $allowed_classes ?? [];
$cap_profiles    = $cap_profiles ?? [];
$cap_teachers    = $cap_teachers ?? [];
$cap_doc_types   = $cap_doc_types ?? ['mappa','esercizio','verifica','document','fork','link','custom'];
$cap_sections    = $cap_sections ?? [];
?>

<?php if ($flash !== '' && isset($flashLabels[$flash])): ?>
    <div class="fm-alert fm-alert--<?= $flash_kind === 'error' ? 'danger' : 'success' ?>">
        <?= $h($flashLabels[$flash]) ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     OVERVIEW
     ═══════════════════════════════════════════════════════ -->
<section class="fm-grid fm-grid--3 fm-mb-6" >

    <div class="fm-tile<?= $isInstitute ? ' fm-tile--alert' : '' ?>">
        <h3>Modo corrente</h3>
        <p class="fm-text-em-xxl fm-fw-600 fm-my-2">
            <?= $isInstitute ? '🏫 INSTITUTE' : '👤 SINGLE' ?>
        </p>
        <p class="fm-muted">
            Source: <code><?= $h($snapshot['source']) ?></code>
            <?php if ($snapshot['source'] === 'runtime_override'): ?>
                <br><small>Letto da <code>storage/config/deployment.json</code></small>
            <?php else: ?>
                <br><small>Letto da <code>.env DEPLOYMENT_MODE</code></small>
            <?php endif; ?>
        </p>
    </div>

    <div class="fm-tile">
        <h3>DPO / Contatto</h3>
        <p class="fm-text-em-xl fm-break-all">
            <?= $snapshot['institute_owner_email'] !== ''
                ? $h($snapshot['institute_owner_email'])
                : '<span class="fm-muted">(non configurato)</span>' ?>
        </p>
        <p class="fm-muted"><small>Usato in privacy notice + breach notification + authority cooperation.</small></p>
    </div>

    <div class="fm-tile">
        <h3>Istituto / Titolare</h3>
        <p class="fm-text-em-xl">
            <?= $snapshot['institute_legal_name'] !== ''
                ? $h($snapshot['institute_legal_name'])
                : '<span class="fm-muted">(Vittorio Pantaleo — single)</span>' ?>
        </p>
        <p class="fm-muted"><small>Mostrato in footer + privacy notice + DPA quando institute mode.</small></p>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════════
     INFO MODI
     ═══════════════════════════════════════════════════════ -->
<details class="fm-section fm-mb-6" >
    <summary><strong>📖 Cosa cambia tra i modi</strong></summary>
    <div class="fm-p-4">
    <table class="fm-table">
        <thead>
            <tr><th scope="col">Aspetto</th><th scope="col">SINGLE (S1)</th><th scope="col">INSTITUTE (S2)</th></tr>
        </thead>
        <tbody>
            <tr><td>Self-signup studenti</td><td>✅ /register (role hidden=student)</td><td>✅ /register (student | teacher)</td></tr>
            <tr><td>Self-signup docenti</td><td>❌ blocked — admin add manuale</td><td>✅ via /register con approve</td></tr>
            <tr><td>Tab admin "Registrazioni"</td><td>Visibile (approve studenti)</td><td>Visibile (approve teacher+student)</td></tr>
            <tr><td>Privacy notice — Titolare</td><td>Gestore istanza (Vittorio)</td><td>Istituto scolastico</td></tr>
            <tr><td>Privacy notice — DPO</td><td>APP_MAIL_FROM</td><td>INSTITUTE_OWNER_EMAIL</td></tr>
            <tr><td>Footer watermark</td><td>(nessuno)</td><td>"Gestito da {nome istituto}"</td></tr>
            <tr><td>DPA template scuola</td><td>N/A</td><td>Richiesto (Art. 28)</td></tr>
            <tr><td>DPIA scope</td><td>Singolo controller</td><td>Multi-soggetto</td></tr>
        </tbody>
    </table>
    <p>Dettagli: vedi <a href="/wiki/decisions/ADR-017-deployment-mode-switch.md" target="_blank">ADR-017</a>.</p>
    </div>
</details>

<!-- ═══════════════════════════════════════════════════════
     SWITCH WIZARD
     ═══════════════════════════════════════════════════════ -->

<?php if (!$isInstitute): ?>
<!-- ── Form: switch SINGLE → INSTITUTE ── -->
<section class="fm-section">
    <h2>🏫 Attiva modo INSTITUTE</h2>
    <p>
        Compila i dati del titolare scolastico e del DPO. <strong>Una volta attivato</strong>:
    </p>
    <ul>
        <li>la registrazione self-service diventa accessibile pubblicamente;</li>
        <li>il banner DPO appare in privacy notice + footer;</li>
        <li>devi firmare un DPA scuola↔gestore (Art. 28).</li>
    </ul>

    <form method="post" action="/admin/system/deployment/switch" class="fm-form fm-max-w-640">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="to_institute">

        <label class="fm-label">
            <span class="fm-form-label-text">Email DPO / contatto privacy istituto</span>
            <input type="email" name="institute_owner_email" class="fm-input" required
                   placeholder="dpo@iss-nomescuola.edu.it">
        </label>

        <label class="fm-label">
            <span class="fm-form-label-text">Ragione sociale completa istituto</span>
            <input type="text" name="institute_legal_name" class="fm-input" required
                   maxlength="255"
                   placeholder="Istituto Superiore Statale &quot;G. Galilei&quot; — Roma">
        </label>

        <p class="fm-muted">
            <small>Questi valori sono salvati in <code>storage/config/deployment.json</code>
            (atomic write). Lo switch è immediato — nessun restart php-fpm richiesto.</small>
        </p>

        <button type="submit" class="fm-btn fm-btn--primary">
            Attiva INSTITUTE mode
        </button>
        <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Confermi switch a modo INSTITUTE? La registrazione self-service diventerà accessibile pubblicamente.'))event.preventDefault()})</script>
    </form>
</section>
<?php else: ?>
<!-- ── Form: switch INSTITUTE → SINGLE ── -->
<section class="fm-section">
    <h2>👤 Disattiva INSTITUTE (torna a SINGLE) <button type="button" class="fm-infotip" aria-label="Info account orfani"><span class="fm-infotip__body" hidden><p>Cambiare modalità è solo un <strong>interruttore di comportamento</strong>: <strong>non cancella nessun account</strong>.</p><p>In SINGLE gli account multi-istituto (studenti/colleghi) restano nel DB ma "dormienti" — l'interfaccia assume un solo docente, quindi non vengono mostrati/usati bene. Tornando in INSTITUTE riacquistano contesto e tornano pienamente utilizzabili: non c'è nulla da ripristinare.</p><p>⚠️ Diverso dalla <em>cancellazione</em> account, che invece è permanente.</p></span></button></h2>
    <?php if ($active_users > 1): ?>
        <div class="fm-alert fm-alert--warning">
            ⚠️ Switch <strong>bloccato</strong>: ci sono <strong><?= (int)$active_users ?></strong>
            utenti attivi (oltre al superadmin). È una protezione per non lasciare account "orfani".
            <br><br>
            Per sbloccare: <strong>disattiva o anonimizza</strong> gli account non-admin
            (da <a href="/admin/tools">Admin → Tools → Utenti</a>, oppure cancellali se di test).
            Quando resta ≤ 1 utente attivo, il pulsante qui sotto si abilita da solo.
        </div>
    <?php else: ?>
        <p>
            Attualmente <strong><?= (int)$active_users ?></strong> utente attivo (oltre al superadmin).
            Lo switch a SINGLE è sicuro.
        </p>

        <form method="post" action="/admin/system/deployment/switch" class="fm-form">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="to_single">
            <button type="submit" class="fm-btn fm-btn--danger">
                Torna a SINGLE
            </button>
            <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Confermi switch a SINGLE? La registrazione self-service verrà chiusa.'))event.preventDefault()})</script>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     ADVANCED — reset runtime override (rare)
     ═══════════════════════════════════════════════════════ -->
<?php if ($snapshot['source'] === 'runtime_override'): ?>
<details class="fm-section fm-mt-8" >
    <summary><strong>🧹 Reset runtime override (advanced)</strong> <button type="button" class="fm-infotip" aria-label="Info configurazione modalità / .env"><span class="fm-infotip__body" hidden><p>La modalità si decide in <strong>due posti</strong>:</p><p>1) il file di override <code>storage/config/deployment.json</code> — scritto dai pulsanti di questa pagina, ha la <strong>priorità</strong>;<br>2) <code>.env DEPLOYMENT_MODE</code> — il <strong>default base</strong>.</p><p>I pulsanti del sito scrivono <strong>solo</strong> il file di override, <strong>mai</strong> l'.env. "Reset" cancella l'override → la modalità torna a quella scritta in <code>.env</code>. Per cambiare l'.env serve accesso al server (SSH/deploy): non è possibile dal sito.</p></span></button></summary>
    <div class="fm-p-4">
        <p>
            Rimuove <code>storage/config/deployment.json</code>. Il modo torna a quello
            definito in <code>.env DEPLOYMENT_MODE</code>. Utile per debug o per "ripartire pulito".
        </p>
        <form method="post" action="/admin/system/deployment/switch" class="fm-form">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="reset_to_env">
            <button type="submit" class="fm-btn fm-btn--ghost">
                Reset a .env default
            </button>
            <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Rimuovo runtime override? Tornerà attivo il valore in .env.'))event.preventDefault()})</script>
        </form>
    </div>
</details>
<?php endif; ?>

<?php if ($isInstitute): ?>
<!-- ═══════════════════════════════════════════════════════
     ADR-028 Fase 4 — GOVERNANCE / PERMESSI ISTITUTO (solo INSTITUTE)
     ═══════════════════════════════════════════════════════ -->
<?php
// Helper: form profilo (riusato per "nuovo" e per ogni profilo esistente).
$capForm = function (array $p) use ($h, $csrf, $cap_doc_types, $cap_sections) {
    $caps  = $p['capabilities'] ?? [];
    $sb    = $caps['sidebar'] ?? ['mode' => 'all', 'sections' => []];
    $types = (array)($caps['doc_types'] ?? []);
    $mv    = (string)($caps['max_visibility'] ?? 'general');
    $mode  = (string)($sb['mode'] ?? 'all');
    ?>
    <form method="post" action="/admin/system/capability/profile/save" class="fm-form" style="border:1px solid var(--fm-c-border,#e2e8f0);padding:12px;border-radius:6px;margin:8px 0">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)($p['id'] ?? 0) ?>">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <label>Nome profilo<br>
                <input type="text" name="name" class="fm-input" required maxlength="120"
                       value="<?= $h($p['name'] ?? '') ?>" <?= !empty($p['is_default']) ? 'readonly' : '' ?>>
            </label>
            <label>Visibilità max<br>
                <select name="max_visibility" class="fm-input">
                    <?php foreach (['class' => 'Solo proprie classi', 'classes' => 'Più classi', 'general' => 'Generale'] as $v => $lbl): ?>
                        <option value="<?= $v ?>" <?= $mv === $v ? 'selected' : '' ?>><?= $h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sidebar<br>
                <select name="sidebar_mode" class="fm-input">
                    <?php foreach (['all' => 'Tutte', 'allow' => 'Solo elencate', 'deny' => 'Tutte tranne elencate'] as $v => $lbl): ?>
                        <option value="<?= $v ?>" <?= $mode === $v ? 'selected' : '' ?>><?= $h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <?php $secList = array_map('strval', (array)($sb['sections'] ?? [])); ?>
        <div style="margin:8px 0">
            <strong>Sezioni</strong>
            <span class="fm-muted" style="font-size:.85em">— usate solo con "Solo elencate" / "Tutte tranne elencate":</span>
            <div style="margin-top:4px">
                <?php if (empty($cap_sections)): ?>
                    <em class="fm-muted">Nessuna sezione configurata nel DB.</em>
                <?php else: foreach ($cap_sections as $s): ?>
                    <label style="margin-right:12px; display:inline-block">
                        <input type="checkbox" name="sidebar_sections[]" value="<?= $h($s['section_key']) ?>"
                            <?= in_array($s['section_key'], $secList, true) ? 'checked' : '' ?>>
                        <?= $h($s['label']) ?> <code style="opacity:.6; font-size:.85em"><?= $h($s['section_key']) ?></code>
                    </label>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div style="margin:8px 0">
            <strong>Tipi documento creabili:</strong>
            <?php foreach ($cap_doc_types as $t): ?>
                <label style="margin-right:10px"><input type="checkbox" name="doc_types[]" value="<?= $h($t) ?>"
                    <?= in_array($t, $types, true) ? 'checked' : '' ?>> <?= $h($t) ?></label>
            <?php endforeach; ?>
        </div>
        <label><input type="checkbox" name="can_create_section" value="1" <?= !empty($caps['can_create_section']) ? 'checked' : '' ?>> Può creare nuove sezioni sidebar</label>
        <div style="margin-top:8px">
            <button type="submit" class="fm-btn fm-btn--primary fm-btn--sm"><?= ($p['id'] ?? 0) ? 'Salva modifiche' : 'Crea profilo' ?></button>
        </div>
    </form>
    <?php
};
?>
<section class="fm-section fm-mt-8">
    <h2>🧾 Registrazione studenti — dati raccolti
        <button type="button" class="fm-infotip" aria-label="Info modalità registrazione"><span class="fm-infotip__body" hidden>Sceglie quali dati lo studente fornisce alla registrazione. <strong>Completa</strong>: email + data di nascita (età, consenso minori Art.8) + istituto/indirizzo/classe. <strong>Ridotta</strong>: niente data di nascita né dati del genitore (restano email + istituto/indirizzo/classe). <strong>Anonima</strong>: registrazione studente disattivata, accesso via credenziale del docente (zero dati studente).</span></button>
    </h2>
    <?php $srMode = $student_reg['mode'] ?? 'full'; ?>
    <form method="post" action="/admin/system/registration-mode" class="fm-stack">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <p><label><input type="radio" name="student_reg_mode" value="full" <?= $srMode === 'full' ? 'checked' : '' ?>>
            <strong>Completa</strong> — email + data di nascita (età) + consenso minori (Art.8) + istituto/indirizzo/classe <em>(default)</em></label></p>
        <p><label><input type="radio" name="student_reg_mode" value="reduced" <?= $srMode === 'reduced' ? 'checked' : '' ?>>
            <strong>Ridotta</strong> — niente data di nascita né dati del genitore (restano email + istituto/indirizzo/classe)</label></p>
        <p><label><input type="radio" name="student_reg_mode" value="anonymous" <?= $srMode === 'anonymous' ? 'checked' : '' ?>>
            <strong>Anonima</strong> — registrazione studente disattivata; accesso via credenziale del docente</label></p>
        <p><label><input type="checkbox" name="only_superadmin_classes" value="1" <?= !empty($student_reg['only_superadmin_classes']) ? 'checked' : '' ?>>
            Consenti registrazione studenti <strong>solo per le classi del super-admin</strong> (sincronizza l'elenco classi ammesse)</label></p>
        <p><button type="submit" class="fm-btn fm-btn--primary">Salva modalità</button></p>
    </form>
</section>

<section class="fm-section fm-mt-8">
    <h2>🛡️ Governance / Permessi istituto
        <button type="button" class="fm-infotip" aria-label="Informazioni su Governance"><span class="fm-infotip__body" hidden>Profili di <strong>capabilities</strong> per i docenti: cosa possono <em>vedere</em> (sezioni sidebar), <em>creare</em> (tipi di documento) e con quale <em>visibilità</em>. Assegna un profilo a ciascun docente. Il profilo <strong>Completo</strong> (default) è permissivo.</span></button>
    </h2>

    <h3>Profili</h3>
    <?php foreach ($cap_profiles as $p): ?>
        <details<?= !empty($p['is_default']) ? ' open' : '' ?>>
            <summary><strong><?= $h($p['name']) ?></strong><?= !empty($p['is_default']) ? ' <em>(default)</em>' : '' ?> —
                tipi: <?= $h(implode(', ', (array)($p['capabilities']['doc_types'] ?? []))) ?: '<em>nessuno</em>' ?>;
                vis: <?= $h((string)($p['capabilities']['max_visibility'] ?? 'general')) ?></summary>
            <?php $capForm($p); ?>
            <?php if (empty($p['is_default'])): ?>
                <form method="post" action="/admin/system/capability/profile/delete" class="fm-inline">
                    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Elimina profilo</button>
                    <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Elimino il profilo? I docenti assegnati torneranno al default.'))event.preventDefault()})</script>
                </form>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <details>
        <summary><strong>➕ Nuovo profilo</strong></summary>
        <?php $capForm([]); ?>
    </details>

    <h3 class="fm-mt-6">Assegnazione docenti</h3>
    <?php if (empty($cap_teachers)): ?>
        <p class="fm-muted"><em>Nessun docente/collaboratore registrato.</em></p>
    <?php else: ?>
        <table class="fm-table fm-max-w-640">
            <thead><tr><th scope="col">Docente</th><th scope="col">Ruolo</th><th scope="col">Profilo</th></tr></thead>
            <tbody>
            <?php foreach ($cap_teachers as $t): ?>
                <tr>
                    <td><?= $h($t['name'] !== '' ? $t['name'] : $t['username']) ?> <small class="fm-muted">(<?= $h($t['username']) ?>)</small></td>
                    <td><?= $h($t['role']) ?></td>
                    <td>
                        <form method="post" action="/admin/system/capability/assign" class="fm-inline">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <select name="profile_id" class="fm-input">
                                <option value="">— default (Completo) —</option>
                                <?php foreach ($cap_profiles as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= ($t['profile_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= $h($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <script>document.currentScript.previousElementSibling.addEventListener("change",function(event){this.form.submit()})</script>
                            <noscript><button type="submit" class="fm-btn fm-btn--sm">Assegna</button></noscript>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
// Le checkbox "Sezioni" contano solo con Sidebar = "Solo elencate" / "Tutte
// tranne elencate": quando il modo è "Tutte" le disabilito (chiarezza UX).
(function () {
    function syncSectionsState(form) {
        var mode = form.querySelector('select[name="sidebar_mode"]');
        if (!mode) return;
        var off = mode.value === 'all';
        form.querySelectorAll('input[name="sidebar_sections[]"]').forEach(function (cb) {
            cb.disabled = off;
            var lbl = cb.closest('label');
            if (lbl) lbl.style.opacity = off ? '0.45' : '';
        });
    }
    document.querySelectorAll('form[action="/admin/system/capability/profile/save"]').forEach(function (form) {
        var mode = form.querySelector('select[name="sidebar_mode"]');
        if (mode) mode.addEventListener('change', function () { syncSectionsState(form); });
        syncSectionsState(form);
    });
})();
</script>
<?php endif; /* isInstitute */ ?>

<!-- ═══════════════════════════════════════════════════════
     ADR-028 Fase 1 — CLASSI AMMESSE ALLA REGISTRAZIONE (trasversale)
     Sempre visibile (vale anche in SINGLE).
     ═══════════════════════════════════════════════════════ -->
<section class="fm-section fm-mt-8">
    <h2>📋 Classi ammesse alla registrazione
        <button type="button" class="fm-infotip" aria-label="Informazioni su Classi ammesse"><span class="fm-infotip__body" hidden>Limita le coppie <strong>indirizzo + classe</strong> per cui è consentita l'iscrizione studente. <strong>Lista vuota = nessuna restrizione</strong> (tutte ammesse). Con almeno una riga, sono ammesse <em>solo</em> quelle elencate. Trasversale: vale anche in modo SINGLE.</span></button>
    </h2>

    <?php
        $institutes = $institutes ?? [];
        $_instMap = [];
        foreach ($institutes as $i) { $_instMap[(int)$i['id']] = (string)$i['label']; }
    ?>
    <?php if (!empty($allowed_classes)): ?>
        <table class="fm-table fm-max-w-640">
            <thead><tr><th scope="col">Istituto</th><th scope="col">Indirizzo</th><th scope="col">Classe</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($allowed_classes as $c): ?>
                <tr>
                    <td><?= $c['institute_id'] !== null ? $h($_instMap[(int)$c['institute_id']] ?? ('#' . (int)$c['institute_id'])) : '<span class="fm-muted">tutti gli istituti</span>' ?></td>
                    <td><code><?= $h($c['indirizzo']) ?></code></td>
                    <td><code><?= $h($c['classe']) ?></code></td>
                    <td>
                        <form method="post" action="/admin/system/registration-classes/remove" class="fm-inline">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="fm-btn fm-btn--ghost fm-btn--sm">Rimuovi</button>
                            <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Rimuovo questa classe dalle ammesse?'))event.preventDefault()})</script>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="fm-muted"><em>Nessuna restrizione attiva — tutte le classi sono ammesse.</em></p>
    <?php endif; ?>

    <form method="post" action="/admin/system/registration-classes/add" class="fm-form fm-max-w-640 fm-mt-4">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <div class="fm-rc-row">
            <label class="fm-label fm-rc-field">
                <span class="fm-form-label-text">Istituto</span>
                <select name="institute_id" id="rc-inst" class="fm-input" required>
                    <option value="">— scegli istituto —</option>
                    <?php foreach ($institutes as $i): ?>
                        <option value="<?= (int)$i['id'] ?>"><?= $h($i['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fm-label fm-rc-field">
                <span class="fm-form-label-text">Indirizzo</span>
                <select name="indirizzo" id="rc-ind" class="fm-input" required disabled>
                    <option value="">—</option>
                </select>
            </label>
            <label class="fm-label fm-rc-field">
                <span class="fm-form-label-text">Classe</span>
                <select name="classe" id="rc-cls" class="fm-input" required disabled>
                    <option value="">—</option>
                </select>
            </label>
            <button type="submit" class="fm-btn fm-btn--primary fm-rc-add">Aggiungi</button>
        </div>
        <p class="fm-muted fm-mt-2"><small>
            Le opzioni di <strong>indirizzo</strong> e <strong>classe</strong> si popolano dai codici
            realmente in uso nell'istituto scelto. Lista vuota = nessuna restrizione (tutte ammesse).
        </small></p>
    </form>
    <style>
        .fm-rc-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
        .fm-rc-field { margin:0; flex:1 1 11em; min-width:9em; }
        .fm-rc-field .fm-input { width:100%; }
        .fm-rc-add { flex:0 0 auto; }
    </style>
    <script>
    (function () {
        const inst = document.getElementById('rc-inst');
        const ind  = document.getElementById('rc-ind');
        const cls  = document.getElementById('rc-cls');
        if (!inst || !ind || !cls) return;
        const fill = (sel, items, ph) => {
            sel.innerHTML = '<option value="">' + ph + '</option>';
            for (const o of (items || [])) {
                const e = document.createElement('option');
                e.value = o.code; e.textContent = (o.label || o.code) + ' (' + o.code + ')';
                sel.appendChild(e);
            }
        };
        inst.addEventListener('change', async () => {
            ind.disabled = cls.disabled = true;
            fill(ind, [], '— …'); fill(cls, [], '— …');
            const iid = inst.value;
            if (!iid) { fill(ind, [], '— scegli istituto prima —'); fill(cls, [], '— scegli istituto prima —'); return; }
            try {
                const r = await fetch('/curriculum?institute_id=' + encodeURIComponent(iid), { credentials: 'same-origin' });
                const j = await r.json();
                const cur = j.curriculum || {};
                fill(ind, cur.indirizzi, '— indirizzo —');
                fill(cls, cur.classi, '— classe —');
                ind.disabled = cls.disabled = false;
            } catch (_) {
                fill(ind, [], '— errore —'); fill(cls, [], '— errore —');
            }
        });
    })();
    </script>
</section>

</div><?php /* /.fm-card aperto da page_head */ ?>
