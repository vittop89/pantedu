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
$page_title    = '⚙️ Scenario di esercizio';
$page_subtitle = 'Uso personale (1), colleghi docenti (2) o adozione da parte di un Istituto (3): chi si iscrive, chi è titolare, quali documenti valgono.';
$breadcrumb    = [['label' => 'System'], ['label' => 'Deployment']];
include __DIR__ . '/../_partials/page_head.php';

// ADR-032 — lo scenario e' la sorgente di verita'; $snapshot (modo legacy)
// resta per le sezioni che ancora ragionano in single/institute.
$scenario          = (isset($scenario) && is_array($scenario)) ? $scenario : \App\Support\DeploymentScenario::snapshot();
$scenarioKey       = (string)$scenario['scenario'];
$scenario_docs     = $scenario_docs ?? [];
$scenario_blockers = $scenario_blockers ?? [];
$isInstitute = $snapshot['mode'] === 'institute';
$flashLabels = [
    'scenario_personal'     => 'Scenario 1 attivo: iscrizioni chiuse, nessun account studente.',
    'scenario_colleagues'   => 'Scenario 2 attivo: iscrizione dei docenti aperta (con approvazione), nessun account studente.',
    'scenario_institute'    => 'Scenario 3 attivo: Istituto Titolare; account studente secondo la modalità scelta qui sotto.',
    'scenario_unchanged'    => 'Lo scenario richiesto è già quello attivo.',
    'scenario_no_reason'    => 'Serve una motivazione di almeno 10 caratteri: cambiare scenario va a registro.',
    'invalid_scenario'      => 'Scenario non riconosciuto.',
    'personal_blocked_active_users'   => 'Scenario 1 bloccato: ci sono ' . (int)$active_users . ' utenti attivi oltre al super-admin. Disattiva o anonimizza prima.',
    'institute_requires_acn_instance' => 'Scenario 3 non attivabile: l\'istanza non è dichiarata su infrastruttura qualificata ACN (INSTANCE_ACN_QUALIFIED in .env).',
    'institute_requires_ack'          => 'Per lo scenario 3 devi confermare infrastruttura qualificata ACN e DPA sottoscritto.',
    'reset_done'            => 'Override runtime rimossi. Scenario e modo ora derivano da .env.',
    'invalid_email'         => 'Email DPO non valida.',
    'invalid_name'          => 'Ragione sociale istituto mancante o troppo lunga.',
    'invalid_action'        => 'Azione non riconosciuta.',
    'exception'             => 'Errore tecnico (vedi log).',
    'class_added'           => 'Classe aggiunta alle ammesse alla registrazione.',
    'class_removed'         => 'Classe rimossa dalle ammesse.',
    'class_invalid'         => 'Indirizzo/classe non validi.',
    'cap_saved'             => 'Profilo capabilities salvato.',
    'cap_deleted'           => 'Profilo eliminato.',
    'cap_assigned'          => 'Profilo assegnato al docente.',
    'cap_invalid'           => 'Dati profilo non validi (o tentata eliminazione del profilo default).',
    'reg_mode_saved'        => 'Modalità registrazione studenti salvata.',
    'tos_enforce_on'        => 'Blocco ToS/AUP ATTIVATO. Chi non ha accettato la versione vigente viene ora rediretto al form.',
    'tos_enforce_off'       => 'Blocco ToS/AUP disattivato. Il preavviso (banner + email) resta attivo.',
    'tos_enforce_reset'     => 'Override rimosso: il blocco ToS/AUP torna a dipendere da TOS_ENFORCE in .env.',
    'tos_enforce_bad_action' => 'Azione non riconosciuta per il blocco ToS/AUP.',
    'tos_enforce_no_reason' => 'Serve una motivazione di almeno 10 caratteri: accendere o spegnere il blocco va a registro.',
    'twofactor_enforce_off'   => 'Obbligo 2FA rimosso. Chi l\'ha attivata di sua iniziativa continua a usarla.',
    'twofactor_enforce_admins' => '2FA ora OBBLIGATORIA per amministratori e super-admin.',
    'twofactor_enforce_all'   => '2FA ora OBBLIGATORIA per tutti gli operatori, docenti compresi.',
    'twofactor_enforce_reset' => 'Override rimosso: l\'obbligo 2FA torna a dipendere da SECURITY_TOTP_* in .env.',
    'twofactor_enforce_bad_action' => 'Azione non riconosciuta per l\'obbligo 2FA.',
    'twofactor_enforce_no_reason' => 'Serve una motivazione di almeno 10 caratteri: imporre un secondo fattore va a registro.',
];
$twofactorSnap    = $twofactor_enforce ?? ['mode' => 'off', 'source' => 'env', 'updated_at' => null, 'updated_by' => null];
$twofactorMode    = (string)($twofactorSnap['mode'] ?? 'off');
$twofactorSource  = (string)($twofactorSnap['source'] ?? 'env');
$twofactorAt      = $twofactorSnap['updated_at'] ?? null;
$twofactorBy      = $twofactorSnap['updated_by'] ?? null;
$twofactorMissing = (int)($twofactor_missing ?? 0);
$twofactorLabels  = [
    'off'    => 'nessun obbligo (facoltativa)',
    'admins' => 'obbligatoria per amministratori e super-admin',
    'all'    => 'obbligatoria per tutti gli operatori',
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
<?php $scenarioIcons = ['personal' => '👤', 'colleagues' => '👥', 'institute' => '🏫']; ?>
<section class="fm-grid fm-grid--3 fm-mb-6" >

    <div class="fm-tile<?= $isInstitute ? ' fm-tile--alert' : '' ?>">
        <h3>Scenario corrente</h3>
        <p class="fm-text-em-xxl fm-fw-600 fm-my-2">
            <?= $scenarioIcons[$scenarioKey] ?? '' ?> Scenario <?= (int)$scenario['number'] ?>
        </p>
        <p class="fm-text-em-md"><?= $h($scenario['label']) ?></p>
        <p class="fm-muted"><small>
            Source: <code><?= $h($scenario['source']) ?></code>
            <?php if ($scenario['source'] === 'runtime_override'): ?>
                <br>impostato da <code><?= $h($scenario['updated_by'] ?? '?') ?></code>
                il <?= $h(substr((string)($scenario['updated_at'] ?? ''), 0, 19)) ?>
                (<code>storage/config/deployment_scenario.json</code>)
                <?php if (!empty($scenario['reason'])): ?><br>«<?= $h($scenario['reason']) ?>»<?php endif; ?>
            <?php elseif ($scenario['source'] === 'env'): ?>
                <br>da <code>DEPLOYMENT_SCENARIO</code> in <code>.env</code>
            <?php else: ?>
                <br>dedotto dal modo legacy <code>DEPLOYMENT_MODE=<?= $h($snapshot['mode']) ?></code>
            <?php endif; ?>
        </small></p>
    </div>

    <div class="fm-tile">
        <h3>Titolare del trattamento</h3>
        <p class="fm-text-em-xl"><?= $h($scenario['controller']) ?></p>
        <p class="fm-muted"><small>
            <?= $isInstitute
                ? 'L\'Istituto; chi conduce l\'istanza è Responsabile ex art. 28 (DPA).'
                : 'Il gestore dell\'istanza (art. 4(7) GDPR): la piattaforma non è uno strumento d\'Istituto.' ?>
        </small></p>
    </div>

    <div class="fm-tile">
        <h3>DPO / Contatto privacy</h3>
        <p class="fm-text-em-xl fm-break-all">
            <?= $scenario['dpo_contact'] !== ''
                ? $h($scenario['dpo_contact'])
                : '<span class="fm-muted">(non configurato)</span>' ?>
        </p>
        <p class="fm-muted"><small>
            Iscrizione docenti: <strong><?= $scenario['teacher_signup_open'] ? 'aperta' : 'chiusa' ?></strong>
            · Account studenti: <strong><?= $scenario['student_accounts'] ? 'attivi' : 'nessuno' ?></strong>
            · Istanza qualificata ACN: <strong><?= $scenario['acn_qualified'] ? 'sì' : 'no' ?></strong>
        </small></p>
    </div>

</section>

<!-- ═══════════════════════════════════════════════════════
     INFO MODI
     ═══════════════════════════════════════════════════════ -->
<details class="fm-section fm-mb-6" >
    <summary><strong>📖 Cosa cambia tra gli scenari</strong></summary>
    <div class="fm-p-4">
    <table class="fm-table">
        <thead>
            <tr><th scope="col">Aspetto</th><th scope="col">1 — Uso personale</th><th scope="col">2 — Colleghi</th><th scope="col">3 — Istituto</th></tr>
        </thead>
        <tbody>
            <?php foreach (\App\Support\DeploymentScenario::comparison() as $aspect => $row): ?>
            <tr>
                <td><?= $h($aspect) ?></td>
                <td><?= $h($row['personal']) ?></td>
                <td><?= $h($row['colleagues']) ?></td>
                <td><?= $h($row['institute']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>Dettagli: <a href="/wiki/decisions/ADR-032-deployment-scenarios.md" target="_blank">ADR-032</a> (scenari)
       e <a href="/wiki/decisions/ADR-017-deployment-mode-switch.md" target="_blank">ADR-017</a> (modo legacy, allineato dallo scenario).</p>
    </div>
</details>

<!-- ═══════════════════════════════════════════════════════
     ADR-032 — DOCUMENTI DI RIFERIMENTO DELLO SCENARIO
     ═══════════════════════════════════════════════════════ -->
<section class="fm-section fm-mb-6">
    <h2>📚 Documenti di riferimento in questo scenario
        <button type="button" class="fm-infotip" aria-label="Info documenti"><span class="fm-infotip__body" hidden>Ogni scenario ha la sua informativa e i suoi documenti: <code>/privacy/informativa</code> serve l'informativa del gestore negli scenari 1 e 2 e quella dell'Istituto nel 3; il DPA compare solo nel 3. I link nel footer delle pagine legali e nel form di registrazione seguono questa lista.</span></button>
    </h2>
    <table class="fm-table fm-max-w-640">
        <thead><tr><th scope="col">Documento</th><th scope="col">Nota</th></tr></thead>
        <tbody>
        <?php foreach ($scenario_docs as $d): ?>
            <tr>
                <td><a href="<?= $h($d['route']) ?>" target="_blank"><?= $h($d['label']) ?></a>
                    <code class="fm-text-em-sm"><?= $h($d['route']) ?></code></td>
                <td class="fm-muted"><?= $h($d['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<!-- ═══════════════════════════════════════════════════════
     SWITCH WIZARD
     ═══════════════════════════════════════════════════════ -->

<section class="fm-section">
    <h2>🔀 Cambia scenario
        <button type="button" class="fm-infotip" aria-label="Info cambio scenario"><span class="fm-infotip__body" hidden><p>Cambiare scenario è un <strong>interruttore di comportamento</strong>: <strong>non cancella</strong> account né contenuti. Scrive <code>storage/config/deployment_scenario.json</code> e allinea il modo legacy (<code>deployment.json</code>) e la registrazione studenti (<code>student_registration.json</code>, anonima fuori dallo scenario 3).</p><p>La motivazione è obbligatoria e finisce nel log degli accessi privilegiati: cambiare scenario cambia chi è il titolare, chi può iscriversi e quali documenti valgono.</p></span></button>
    </h2>

    <?php foreach (\App\Support\DeploymentScenario::ALL as $s):
        if ($s === $scenarioKey) { continue; }
        $blocker = $scenario_blockers[$s] ?? null;
    ?>
    <details class="fm-mb-4">
        <summary>
            <strong><?= $scenarioIcons[$s] ?? '' ?> <?= $h(\App\Support\DeploymentScenario::label($s)) ?></strong>
            <?php if ($blocker !== null): ?><span class="fm-muted"> — non attivabile adesso</span><?php endif; ?>
        </summary>
        <div class="fm-p-4">
        <?php if ($blocker === 'personal_blocked_active_users'): ?>
            <div class="fm-alert fm-alert--warning">
                Bloccato: ci sono <strong><?= (int)$active_users ?></strong> utenti attivi oltre al super-admin.
                Lo scenario 1 prevede il solo autore: <strong>disattiva o anonimizza</strong> gli altri account
                (da <a href="/admin">Admin → Tools → Utenti</a>, oppure cancellali se di prova).
                Quando resta al più un utente attivo, il pulsante compare da solo.
            </div>
        <?php elseif ($blocker === 'institute_requires_acn_instance'): ?>
            <div class="fm-alert fm-alert--warning">
                <strong>Non attivabile su questa istanza.</strong> Lo scenario 3 tratta dati di cui è Titolare un Istituto,
                e il Regolamento cloud ACN (Decreto direttoriale n. 21007/24) consente alle scuole di avvalersi
                soltanto di infrastrutture e servizi cloud qualificati. Chi conduce un'istanza qualificata
                — l'Istituto stesso, o un fornitore qualificato — lo dichiara in <code>.env</code> con
                <code>INSTANCE_ACN_QUALIFIED=true</code>. Questo pannello non può assumersi quella responsabilità
                al posto suo.
            </div>
        <?php else: ?>
            <form method="post" action="/admin/system/deployment/switch" class="fm-form fm-max-w-640">
                <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="set_scenario">
                <input type="hidden" name="scenario" value="<?= $h($s) ?>">

                <?php if ($s === 'institute'): ?>
                    <label class="fm-label">
                        <span class="fm-form-label-text">Ragione sociale completa dell'Istituto (Titolare)</span>
                        <input type="text" name="institute_legal_name" class="fm-input" required maxlength="255"
                               value="<?= $h($snapshot['institute_legal_name'] ?? '') ?>"
                               placeholder="Istituto Superiore Statale &quot;G. Galilei&quot; — Roma">
                    </label>
                    <label class="fm-label">
                        <span class="fm-form-label-text">Email del DPO / contatto privacy dell'Istituto</span>
                        <input type="email" name="institute_owner_email" class="fm-input" required
                               value="<?= $h($snapshot['institute_owner_email'] ?? '') ?>"
                               placeholder="dpo@iss-nomescuola.edu.it">
                    </label>
                    <label class="fm-label fm-d-flex fm-gap-2 fm-items-start fm-fw-400">
                        <input type="checkbox" name="acn_ack" value="1" required class="fm-mt-1">
                        <span>Confermo che questa istanza è condotta dall'Istituto, o da un fornitore qualificato,
                        su infrastruttura qualificata ACN, e che l'Accordo ex art. 28
                        (<a href="/legal/dpa" target="_blank">DPA</a>) è sottoscritto.</span>
                    </label>
                <?php endif; ?>

                <label class="fm-label">
                    <span class="fm-form-label-text">Motivazione (obbligatoria, finisce nel log accessi privilegiati)</span>
                    <input type="text" name="_audit_reason" class="fm-input" required minlength="10" maxlength="200"
                           placeholder="<?= $h($s === 'colleagues'
                               ? 'es. parere del DPO ricevuto: apro le iscrizioni ai colleghi'
                               : ($s === 'institute' ? 'es. delibera dell\'Istituto del … e DPA sottoscritto' : 'es. chiudo le iscrizioni e torno all\'uso personale')) ?>">
                </label>

                <p class="fm-muted"><small>Cosa cambia:
                    <?php $parts = []; foreach (\App\Support\DeploymentScenario::comparison() as $aspect => $row) { $parts[] = $aspect . ': ' . $row[$s]; } ?>
                    <?= $h(implode(' · ', $parts)) ?>
                </small></p>

                <button type="submit" class="fm-btn <?= $s === 'personal' ? 'fm-btn--danger' : 'fm-btn--primary' ?>">
                    Attiva lo <?= $h(\App\Support\DeploymentScenario::label($s)) ?>
                </button>
                <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){if(!confirm('Confermi il cambio di scenario? Pagine di accesso, registrazione e documenti di riferimento cambiano subito.'))event.preventDefault()})</script>
            </form>
        <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
</section>

<!-- ═══════════════════════════════════════════════════════
     ADVANCED — reset runtime override (rare)
     ═══════════════════════════════════════════════════════ -->
<?php if ($snapshot['source'] === 'runtime_override' || $scenario['source'] === 'runtime_override'): ?>
<details class="fm-section fm-mt-8" >
    <summary><strong>🧹 Reset runtime override (advanced)</strong> <button type="button" class="fm-infotip" aria-label="Info configurazione scenario / .env"><span class="fm-infotip__body" hidden><p>Lo scenario si decide in <strong>due posti</strong>:</p><p>1) i file di override <code>storage/config/deployment_scenario.json</code> e <code>deployment.json</code> — scritti dai pulsanti di questa pagina, hanno la <strong>priorità</strong>;<br>2) <code>.env DEPLOYMENT_SCENARIO</code> (o, se vuoto, <code>DEPLOYMENT_MODE</code>) — il <strong>default base</strong>.</p><p>I pulsanti del sito scrivono <strong>solo</strong> i file di override, <strong>mai</strong> l'.env. "Reset" li cancella → si torna a quanto scritto in <code>.env</code>. Per cambiare l'.env serve accesso al server (SSH/deploy): non è possibile dal sito.</p></span></button></summary>
    <div class="fm-p-4">
        <p>
            Rimuove <code>storage/config/deployment_scenario.json</code> e <code>deployment.json</code>.
            Lo scenario torna a quello definito in <code>.env</code>. Utile per debug o per "ripartire pulito".
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

<?php
// Phase 25.P — gate ToS/AUP. Il preavviso (banner + email) resta attivo comunque:
// spegnere l'enforcement non spegne l'informazione.
$tosOn      = !empty($tos_enforce['enabled']);
$tosSource  = (string)($tos_enforce['source'] ?? 'env');
$tosPending = (int)($tos_pending ?? 0);
?>
<section class="fm-section fm-mt-8">
    <h2>⚖️ Accettazione ToS/AUP — blocco accesso
        <button type="button" class="fm-infotip" aria-label="Info gate ToS"><span class="fm-infotip__body" hidden>Quando è <strong>attivo</strong>, il docente che non ha accettato la versione vigente di Termini e AUP viene rediretto al form e non può usare l'applicativo finché non accetta. Il super-admin è sempre esente. I documenti da accettare restano leggibili anche dal blocco.</span></button>
    </h2>

    <p>
        Stato:
        <strong class="<?= $tosOn ? 'fm-text-danger' : 'fm-muted' ?>"><?= $tosOn ? 'ATTIVO — blocco in corso' : 'spento — nessun blocco' ?></strong>
        <?php if ($tosSource === 'runtime_override'): ?>
            <span class="fm-muted">(impostato da questo pannello<?php
                if (!empty($tos_enforce['updated_by'])) {
                    echo ' da ' . $h((string)$tos_enforce['updated_by']);
                }
                if (!empty($tos_enforce['updated_at'])) {
                    echo ' il ' . $h(date('d/m/Y H:i', strtotime((string)$tos_enforce['updated_at'])));
                }
            ?>)</span>
        <?php else: ?>
            <span class="fm-muted">(da <code>TOS_ENFORCE</code> in <code>.env</code>, nessun override attivo)</span>
        <?php endif; ?>
    </p>

    <?php if ($tosPending > 0): ?>
        <div class="fm-alert fm-alert--warn" role="status">
            <strong><?= $tosPending ?></strong>
            <?= $tosPending === 1 ? 'utente non ha' : 'utenti non hanno' ?>
            ancora accettato la versione vigente.
            <?php if ($tosOn): ?>
                Sono bloccati adesso: al login vedono il form di accettazione.
            <?php else: ?>
                Attivando il blocco, dovranno passare dal form prima di poter
                continuare. Avvisali prima.
            <?php endif; ?>
            Elenco nominativo: <code>php tools/legal/tos_gate_preview.php</code>
        </div>
    <?php else: ?>
        <p class="fm-muted">Tutti gli utenti teacher/admin sono in regola con la versione vigente.</p>
    <?php endif; ?>

    <form method="post" action="/admin/system/tos-enforce" class="fm-stack">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <p>
            <label for="tos-reason"><strong>Motivazione</strong> (obbligatoria, finisce nel log accessi privilegiati)</label><br>
            <input type="text" id="tos-reason" name="_audit_reason" required minlength="10" maxlength="200"
                   class="fm-input" style="min-width:min(100%,32rem)"
                   placeholder="es. avvio Scenario B: estensione ai docenti del dipartimento">
        </p>
        <p>
            <?php if (!$tosOn): ?>
                <button type="submit" name="tos_action" value="enable" class="fm-btn fm-btn--danger">
                    Attiva il blocco
                </button>
            <?php else: ?>
                <button type="submit" name="tos_action" value="disable" class="fm-btn fm-btn--primary">
                    Disattiva il blocco
                </button>
            <?php endif; ?>
            <?php if ($tosSource === 'runtime_override'): ?>
                <button type="submit" name="tos_action" value="reset" class="fm-btn">
                    Rimuovi l'override (torna a <code>.env</code>)
                </button>
            <?php endif; ?>
        </p>
    </form>
</section>


<!-- ═══════════════════════════════════════════════════════
     2026-09-01 — OBBLIGO DI VERIFICA IN DUE PASSAGGI (2FA)
     ═══════════════════════════════════════════════════════ -->
<section class="fm-section fm-mt-8">
    <h2>📱 Verifica in due passaggi (2FA)</h2>

    <p>
        Stato: <strong><?= $h($twofactorLabels[$twofactorMode] ?? $twofactorMode) ?></strong>
        <?php if ($twofactorSource === 'runtime_override'): ?>
            <br><small class="fm-muted">
                Impostato da <code><?= $h($twofactorBy ?? '?') ?></code>
                il <?= $h(substr((string)($twofactorAt ?? ''), 0, 19)) ?>
                (<code>storage/config/twofactor_enforcement.json</code>)
            </small>
        <?php else: ?>
            <br><small class="fm-muted">(da <code>SECURITY_TOTP_*</code> in <code>.env</code>, nessun override attivo)</small>
        <?php endif; ?>
    </p>

    <p class="fm-muted">
        L'obbligo riguarda solo chi <em>deve</em> usarla. Chi la attiva per
        conto proprio dal profilo se la vede chiedere al login in ogni caso:
        spegnere l'obbligo non toglie a nessuno una protezione che ha scelto.
    </p>

    <?php if ($twofactorMissing > 0): ?>
        <div class="fm-alert fm-alert--warn" role="status">
            <strong><?= (int)$twofactorMissing ?></strong>
            <?= $twofactorMissing === 1 ? 'utente del ruolo obbligato non ha' : 'utenti dei ruoli obbligati non hanno' ?>
            ancora attivato la 2FA. Al prossimo accesso vengono accompagnati alla
            pagina d'iscrizione e non possono usare il resto finché non la
            completano — non restano chiusi fuori, ma vanno avvisati prima.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/system/2fa-enforce" class="fm-stack">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <p>
            <label for="tfa-reason"><strong>Motivazione</strong> (obbligatoria, finisce nel log accessi privilegiati)</label><br>
            <input type="text" id="tfa-reason" name="_audit_reason" required minlength="10" maxlength="200"
                   class="fm-input fm-w-full fm-max-w-32rem"
                   placeholder="es. estensione ad altri docenti: alzo la soglia sugli account amministrativi">
        </p>
        <p class="fm-d-flex fm-gap-2 fm-flex-wrap">
            <?php if ($twofactorMode !== 'off'): ?>
                <button type="submit" name="twofactor_action" value="off" class="fm-btn">
                    Nessun obbligo
                </button>
            <?php endif; ?>
            <?php if ($twofactorMode !== 'admins'): ?>
                <button type="submit" name="twofactor_action" value="admins" class="fm-btn fm-btn--primary">
                    Obbligatoria per gli amministratori
                </button>
            <?php endif; ?>
            <?php if ($twofactorMode !== 'all'): ?>
                <button type="submit" name="twofactor_action" value="all" class="fm-btn fm-btn--danger">
                    Obbligatoria per tutti (docenti compresi)
                </button>
            <?php endif; ?>
            <?php if ($twofactorSource === 'runtime_override'): ?>
                <button type="submit" name="twofactor_action" value="reset" class="fm-btn">
                    Rimuovi l'override (torna a <code>.env</code>)
                </button>
            <?php endif; ?>
        </p>
    </form>
</section>

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

<?php if (!$isInstitute): ?>
<section class="fm-section fm-mt-8">
    <h2>🧾 Registrazione studenti</h2>
    <p class="fm-muted">
        Nessun account studente in questo scenario: gli studenti consultano i contenuti pubblicati con la
        <a href="/accesso-classe">credenziale della classe</a>, senza fornire dati personali. Le modalità
        di raccolta (Completa / Ridotta / Anonima) si scelgono solo nello scenario 3, dove il Titolare è l'Istituto.
    </p>
</section>
<?php endif; ?>

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
