<?php
/**
 * Phase 25.R.22 — Authority Export Wizard
 *
 * Form guidato per export firmato verso autorità competenti (Garante, magistratura,
 * polizia postale). Esegue in singola transazione:
 *   1. INSERT crypto_custody_events: authority_request (con base giuridica)
 *   2. Fetch eventi filtrati per perimetro
 *   3. INSERT crypto_custody_events: data_provided (con sha256 del bundle)
 *   4. Download ZIP firmato {export.{json|csv} + manifest.json}
 *
 * @var list<array{id:int,username:string,label:string}> $teachers
 * @var list<string> $eventTypes
 * @var string $csrf
 * @var array|null $flash
 * @var array $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '🚨 Authority Export — Bundle firmato per autorità';
$page_subtitle = 'Esportazione mirata di crypto_custody_events con chain-of-custody (Art. 6(1)(c) + Art. 32 GDPR).';
$breadcrumb    = [['label' => 'GDPR', 'href' => '/admin/gdpr'], ['label' => 'Authority Export']];
include __DIR__ . '/_partials/page_head.php';
$gdpr_current = 'authority-export';
include __DIR__ . '/_partials/gdpr_nav.php';

// Phase 25.R.24 — Scope contenuti unificato dentro Step ② (GDPR minimizzazione perimetro)
$contentScopes = [
    'profile'           => ['👤', 'Profilo utente', 'Anagrafica (no password/TOTP)'],
    'consents'          => ['📋', 'Consensi GDPR', 'Art. 7 + parent consents Art. 8'],
    'teacher_content'   => ['📚', 'Contenuti docente', 'Mappe + esercizi + lab + contract files'],
    'verifiche'         => ['📝', 'Verifiche', 'TEX + PDF compilati (decifrati)'],
    'templates'         => ['🧾', 'Template', 'Verifica + RisDoc templates'],
    'risdoc'            => ['🎓', 'RisDoc / BES', 'Modelli personalizzati'],
    'curriculum'        => ['📅', 'Curriculum', 'Materie/classi/indirizzi'],
    'shares'            => ['🔗', 'Condivisioni', 'Share grants + groups'],
    'published_content' => ['🌐', 'Contenuti pubblicati', 'Pubblicati a classi (con orphan recovery)'],
    'classe_keys'       => ['🔑', 'Chiavi classe', 'Per decryption published_content (cifrate)'],
    'audit_log'         => ['📜', 'Audit log', 'Accessi privilegiati + crypto'],
];
?>

<?php if (!empty($flash)): ?>
    <div class="fm-alert fm-alert--<?= ($flash['type'] ?? '') === 'error' ? 'danger' : 'success' ?>">
        <?= $h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="fm-alert fm-alert--warn fm-mb-6" >
    <h2 class="fm-m-0 fm-mb-2 fm-text-17">⚠️ Procedura formale — leggi prima di procedere</h2>
    <ol class="fm-my-2 fm-pl-em-lg">
        <li><strong>Verifica legittimità</strong> della richiesta (decreto firmato, autorità identificata, base giuridica valida).</li>
        <li>I dati esportati sono <strong>firmati con HMAC-SHA256</strong> derivato da KMS_MASTER_KEY: l'autorità può verificare integrità senza esporre la chiave.</li>
        <li>Questa azione <strong>registra automaticamente 2 eventi</strong> in <code>crypto_custody_events</code> (append-only): <code>authority_request</code> + <code>data_provided</code>.</li>
        <li>Il bundle include <strong>manifest.json</strong> con SHA-256, HMAC, filtri applicati, timestamp e operatore.</li>
        <li>Procedura completa: <a href="/docs/security/operations/authority-cooperation.md"><code>docs/security/operations/authority-cooperation.md</code></a> §3.3.</li>
    </ol>
</div>

<form method="POST" action="/admin/gdpr/authority-export" enctype="application/x-www-form-urlencoded" id="fm-auth-export-form">
    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

    <!-- ════════════════ STEP 1 — Dati richiesta autorità ════════════════ -->
    <section class="fm-card fm-mb-6" >
        <h2 class="fm-mt-0">① Dati richiesta autorità <span class="fm-required" aria-label="campo obbligatorio">*</span></h2>
        <p class="fm-muted fm-text-em-lg fm-m-0 fm-mb-4" >
            Tutti i campi sono obbligatori. Vengono salvati nell'evento <code>authority_request</code>.
        </p>
        <div class="fm-form-grid">
            <label>
                <span class="fm-form-label-text">Autorità richiedente *</span>
                <input type="text" name="authority_name" required maxlength="160"
                       placeholder="es. Tribunale di Milano / Garante Privacy / Procura della Repubblica"
                       class="fm-w-full">
            </label>

            <label>
                <span class="fm-form-label-text">Riferimento procedimento *</span>
                <input type="text" name="authority_ref" required maxlength="255"
                       placeholder="es. n. 1234/2026 R.G.N.R. / Provv. Garante n. 567/2026"
                       class="fm-w-full">
            </label>

            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">Base giuridica *</span>
                <input type="text" name="legal_basis" required maxlength="255"
                       placeholder="es. Art. 6(1)(c) GDPR + Decreto 14/2026 del Tribunale di Milano"
                       class="fm-w-full">
            </label>

            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">URL evidenza (PEC, PDF firmato) *consigliato</span>
                <input type="url" name="evidence_url" maxlength="512"
                       placeholder="https://... (link interno a PDF decreto firmato)"
                       class="fm-w-full">
            </label>

            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">Descrizione operazione * (min 20 caratteri)</span>
                <textarea name="description" required minlength="20" rows="3" class="fm-w-full"
                          placeholder="es. Consegna registro eventi custodia chiavi per indagine n. 1234/2026. Perimetro: docente ID 7, periodo 2026-01-01 / 2026-03-31."></textarea>
            </label>
        </div>
    </section>

    <!-- ════════════════ STEP 2 — Perimetro export ════════════════ -->
    <section class="fm-card fm-mb-6" >
        <h2 class="fm-mt-0">② Perimetro export (GDPR minimizzazione)</h2>
        <p class="fm-muted fm-text-em-lg fm-m-0 fm-mb-4" >
            Filtra il perimetro per consegnare <strong>solo</strong> i record richiesti.
            Tutti i campi opzionali — vuoto = "nessun filtro" su quel campo.
        </p>
        <div class="fm-form-grid">
            <label>
                <span class="fm-form-label-text">Data inizio (occurred_at)</span>
                <input type="date" name="date_from" class="fm-w-full">
            </label>

            <label>
                <span class="fm-form-label-text">Data fine (occurred_at)</span>
                <input type="date" name="date_to" class="fm-w-full">
            </label>

            <label>
                <span class="fm-form-label-text">Teacher ID specifico</span>
                <?php if (!empty($teachers)): ?>
                    <select name="teacher_id" class="fm-w-full">
                        <option value="">— Tutti (no filtro) —</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> · <?= $h($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="number" name="teacher_id" min="1" placeholder="es. 7" class="fm-w-full">
                <?php endif; ?>
            </label>

            <label>
                <span class="fm-form-label-text">Max contenuti per tipo (1-500, default 50)</span>
                <input type="number" name="max_per_type" min="1" max="500" placeholder="50"
                       class="fm-w-full">
                <small class="fm-muted fm-d-block fm-mt-1" >
                    Limit safety per evitare OOM. Aumenta a 200-500 per export completi.
                </small>
            </label>

            <div class="fm-form-fullrow">
                <span class="fm-form-label-text fm-d-block fm-fw-500 fm-mb-1" >
                    Content ID specifici (export mirato — opzionale)
                </span>
                <input type="text" name="content_ids" id="fm-content-ids" placeholder="es. 123, 456, 789"
                       class="fm-w-full fm-font-mono">
                <div class="fm-d-flex fm-gap-2 fm-mt-1 fm-items-center fm-flex-wrap">
                    <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm">
                        🔍 Cerca contenuti docente
                    </button>
                    <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){fmContentSearchOpen()})</script>
                    <small class="fm-muted">
                        Apre lista filtrabile dei contenuti del docente selezionato.
                        Spunta quelli da includere → ID si auto-popolano qui sopra.
                    </small>
                </div>
                <small class="fm-muted fm-d-block fm-mt-1" >
                    💡 <strong>Come funziona</strong>: gli "ID" sono numeri interni al DB,
                    NON sono nei decreti delle autorità. Workflow tipico:
                    <ol class="fm-m-0 fm-mt-1 fm-pl-em-lg fm-text-em-lg">
                        <li>Autorità chiede dati specifici (es. "verifiche matematica della classe 3A periodo X").</li>
                        <li>Tu clicchi <code>🔍 Cerca contenuti</code> → filtri per tipo/titolo/parola chiave.</li>
                        <li>Spunti i contenuti che matchano la richiesta.</li>
                        <li>Il sistema riempie automaticamente questo campo con gli ID corretti.</li>
                        <li>Submit → bundle ZIP contiene SOLO quei contenuti (override date+limit).</li>
                    </ol>
                </small>
            </div>

            <!-- Search modal contenuti (collapsible inline) -->
            <div id="fm-content-search-panel" class="fm-form-fullrow fm-content-search-panel fm-d-none" >
                <div class="fm-d-flex fm-gap-2 fm-mb-2 fm-flex-wrap fm-items-end">
                    <label class="fm-flex-grow-min-50">
                        <span class="fm-d-block fm-text-em-md fm-mb-1">Parola chiave (titolo / topic)</span>
                        <input type="search" id="fm-cs-q" placeholder="es. matematica, equazioni..." class="fm-w-full">
                    </label>
                    <label class="fm-flex-grow-min-130">
                        <span class="fm-d-block fm-text-em-md fm-mb-1">Tipo</span>
                        <select id="fm-cs-type" class="fm-w-full">
                            <option value="">Tutti</option>
                            <option value="mappa">Mappe</option>
                            <option value="esercizio">Esercizi</option>
                            <option value="lab">Lab</option>
                            <option value="verifica">Verifiche</option>
                            <option value="bes">BES</option>
                            <option value="risdoc">RisDoc</option>
                            <option value="didattica">Didattica</option>
                        </select>
                    </label>
                    <button type="button" class="fm-btn fm-btn--primary fm-btn--sm">🔎 Cerca</button>
                    <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){fmContentSearchRun()})</script>
                    <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm">✕ Chiudi</button>
                    <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){fmContentSearchClose()})</script>
                </div>
                <div id="fm-cs-results" class="fm-muted fm-text-em-md" >
                    Seleziona un docente nel campo "Teacher ID specifico" sopra, poi clicca "Cerca".
                </div>
            </div>

            <label class="fm-form-fullrow">
                <span class="fm-form-label-text">Tipi evento custody (lascia vuoto per tutti)</span>
                <div class="fm-d-flex fm-flex-wrap fm-gap-2 fm-py-1">
                    <?php foreach ($eventTypes as $et): ?>
                        <label class="fm-d-inline-flex fm-items-center fm-gap-1 fm-fw-400 fm-text-em-md">
                            <input type="checkbox" name="event_types_arr[]" value="<?= $h($et) ?>">
                            <code><?= $h($et) ?></code>
                        </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="event_types" id="fm-event-types-csv" value="">
            </label>

            <!-- Subsezione scope contenuti decifrati (era ③b, unificato qui — Phase 25.R.24) -->
            <div class="fm-form-fullrow fm-section-sep" >
                <span class="fm-form-label-text fm-d-block fm-fw-500 fm-mb-1" >
                    Categorie contenuti decifrati da includere nel bundle
                </span>
                <label class="fm-d-flex fm-gap-2 fm-items-start fm-cursor-pointer fm-mb-3">
                    <input type="checkbox" name="include_contents" value="1" id="fm-include-contents">
                    <span>
                        <strong>Includi contenuti decifrati nel bundle</strong>
                        <small class="fm-muted fm-d-block fm-mt-1" >
                            Se NON spuntato: bundle contiene solo audit trail crypto_custody_events.
                            Se spuntato: aggiunge anche i contenuti reali (mappe, verifiche, esercizi…) decifrati.
                            Richiede Teacher ID specifico sopra.
                        </small>
                    </span>
                </label>
                <div class="fm-grid fm-grid--220">
                    <?php foreach ($contentScopes as $key => [$icon, $label, $desc]): ?>
                        <label class="fm-d-flex fm-gap-2 fm-items-start fm-fw-400 fm-text-em-md fm-cursor-pointer">
                            <input type="checkbox" name="content_scope[]" value="<?= $h($key) ?>" checked class="fm-mt-1">
                            <span>
                                <strong><?= $icon ?> <?= $h($label) ?></strong><br>
                                <small class="fm-muted"><?= $h($desc) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="fm-muted fm-text-em-base fm-mt-3 fm-mb-0" >
                    ⚠️ <strong>Minimizzazione GDPR Art. 5(1)(c)</strong>: spunta SOLO le categorie
                    esplicitamente richieste dal decreto. L'autorità non deve ricevere dati eccedenti
                    la base giuridica della richiesta.
                </p>
            </div>
        </div>
    </section>

    <!-- ════════════════ STEP 3 — Formato + firma ════════════════ -->
    <section class="fm-card fm-mb-6" >
        <h2 class="fm-mt-0">③ Formato bundle</h2>
        <div class="fm-form-grid">
            <label>
                <span class="fm-form-label-text">Formato custody (audit trail crypto_custody_events)</span>
                <select name="format" class="fm-w-full">
                    <option value="json" selected>JSON (consigliato — più strutturato)</option>
                    <option value="csv">CSV (Excel-friendly)</option>
                </select>
            </label>
        </div>
        <p class="fm-muted fm-text-em-md fm-mt-2 fm-mb-0" >
            Bundle ZIP automatico con <code>custody/export.{json|csv}</code> + <code>manifest.json</code>.
            Manifest contiene: <code>sha256</code> + <code>HMAC-SHA256</code> per OGNI file del bundle, firmato
            con chiave derivata da <code>KMS_MASTER_KEY</code> (HKDF), timestamp, operatore, filtri applicati.
        </p>
    </section>

    <?php /* Step ③b 'Scope contenuti decifrati' spostato dentro § ② (Phase 25.R.24) */ ?>

    <!-- ════════════════ STEP 4 — Conferma + download ════════════════ -->
    <section class="fm-card fm-mb-6" >
        <h2 class="fm-mt-0">④ Conferma e download</h2>
        <p class="fm-m-0 fm-mb-4">
            Cliccando "Genera bundle firmato":
        </p>
        <ul class="fm-m-0 fm-mb-4 fm-pl-em-lg">
            <li>Viene <strong>registrato</strong> un evento <code>authority_request</code> (immutabile).</li>
            <li>Viene <strong>registrato</strong> un evento <code>data_provided</code> con SHA-256 del bundle (immutabile).</li>
            <li>Browser scarica <code>authority-export-YYYYMMDD_HHMMSS.zip</code> firmato.</li>
        </ul>

        <!-- 🧪 Test mode — non scrive in DB, utile per simulazione -->
        <div class="fm-alert fm-alert--info fm-mb-4" >
            <label class="fm-d-flex fm-gap-2 fm-items-start fm-cursor-pointer">
                <input type="checkbox" name="test_mode" value="1" class="fm-mt-1">
                <span>
                    <strong>🧪 Modalità test (simulazione)</strong><br>
                    <small>
                        Genera bundle ZIP con manifest HMAC verificabile MA non scrive nessun evento in
                        <code>crypto_custody_events</code>. Utile per validare workflow e contenuto bundle
                        senza polluire il registro custody reale.
                    </small>
                </span>
            </label>
        </div>

        <label class="fm-d-flex fm-gap-2 fm-items-center fm-mb-4">
            <input type="checkbox" required>
            <span>Confermo di aver verificato la legittimità della richiesta e la validità del decreto.</span>
        </label>
        <button type="submit" class="fm-btn fm-btn--danger fm-text-em-lg" >
            🚨 Genera bundle firmato + log eventi
        </button>
        <a href="/admin/gdpr" class="fm-btn fm-btn--ghost fm-ml-2" >Annulla</a>
    </section>
</form>

<script>
// Aggrega checkbox event_types_arr[] in CSV event_types prima del submit (compat backend)
document.getElementById('fm-auth-export-form').addEventListener('submit', () => {
    const checked = Array.from(document.querySelectorAll('input[name="event_types_arr[]"]:checked'))
        .map(el => el.value);
    document.getElementById('fm-event-types-csv').value = checked.join(',');
});

// ─── Content search panel (Phase 25.R.24) ──────────────────────
function fmContentSearchOpen() {
    document.getElementById('fm-content-search-panel').style.display = 'block';
    document.getElementById('fm-cs-q').focus();
}
function fmContentSearchClose() {
    document.getElementById('fm-content-search-panel').style.display = 'none';
}
function fmEscapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
async function fmContentSearchRun() {
    const tidSel = document.querySelector('select[name="teacher_id"], input[name="teacher_id"]');
    const tid = tidSel ? tidSel.value.trim() : '';
    const results = document.getElementById('fm-cs-results');
    if (!tid) {
        results.innerHTML = '<span class="fm-text-danger">⚠️ Seleziona prima un docente nel campo "Teacher ID specifico".</span>';
        return;
    }
    const q = document.getElementById('fm-cs-q').value.trim();
    const type = document.getElementById('fm-cs-type').value;
    const url = '/api/admin/gdpr/teacher-content-search?teacher_id=' + encodeURIComponent(tid)
              + (q !== '' ? '&q=' + encodeURIComponent(q) : '')
              + (type !== '' ? '&type=' + encodeURIComponent(type) : '');
    results.innerHTML = '⏳ Ricerca in corso…';
    try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await res.json();
        if (!j.ok) {
            results.innerHTML = '<span class="fm-text-danger">✗ Errore: ' + fmEscapeHtml(j.error || '') + '</span>';
            return;
        }
        if (!j.rows || j.rows.length === 0) {
            results.innerHTML = '<em>Nessun contenuto trovato con questi filtri.</em>';
            return;
        }
        // Render table con checkbox
        let html = '<div class="fm-mb-1">Trovati <strong>' + j.count + '</strong> contenuti '
                 + '(max 200 visualizzati). Spunta quelli da includere:</div>';
        html += '<div class="fm-scroll-panel">';
        html += '<table class="fm-waf-table fm-w-full fm-text-em-md fm-m-0" >';
        html += '<thead class="fm-sticky-top">'
              + '<tr><th scope="col"></th><th scope="col">ID</th><th scope="col">Tipo</th><th scope="col">Titolo</th><th scope="col">Materia/Classe</th><th scope="col">Data</th></tr>'
              + '</thead><tbody>';
        for (const r of j.rows) {
            const subj = [r.subject_code, r.indirizzo, r.classe].filter(Boolean).join(' · ');
            html += '<tr>'
                  + '<td><input type="checkbox" class="fm-cs-cb" data-id="' + r.id + '"></td>'
                  + '<td><code>' + r.id + '</code></td>'
                  + '<td>' + fmEscapeHtml(r.content_type) + '</td>'
                  + '<td>' + fmEscapeHtml(r.title || r.topic || '(no title)') + '</td>'
                  + '<td><small>' + fmEscapeHtml(subj) + '</small></td>'
                  + '<td><small>' + fmEscapeHtml((r.created_at||'').substring(0,10)) + '</small></td>'
                  + '</tr>';
        }
        html += '</tbody></table></div>';
        html += '<div class="fm-d-flex fm-gap-2 fm-mt-2 fm-items-center">'
              + '<button type="button" class="fm-btn fm-btn--primary fm-btn--sm" data-cs-act="apply">📥 Aggiungi ID selezionati al wizard</button>'
              + '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-cs-act="all">Seleziona tutti</button>'
              + '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-cs-act="none">Deseleziona tutti</button>'
              + '</div>';
        results.innerHTML = html;
    } catch (e) {
        results.innerHTML = '<span class="fm-text-danger">✗ Errore di rete: ' + fmEscapeHtml(e.message) + '</span>';
    }
}
function fmContentSearchSelectAll() {
    document.querySelectorAll('.fm-cs-cb').forEach(cb => cb.checked = true);
}
function fmContentSearchSelectNone() {
    document.querySelectorAll('.fm-cs-cb').forEach(cb => cb.checked = false);
}
function fmContentSearchApply() {
    const ids = Array.from(document.querySelectorAll('.fm-cs-cb:checked')).map(cb => cb.dataset.id);
    if (ids.length === 0) {
        alert('Nessun contenuto selezionato. Spunta almeno una riga.');
        return;
    }
    const field = document.getElementById('fm-content-ids');
    const existing = field.value.trim();
    const existingIds = existing ? existing.split(',').map(s => s.trim()).filter(Boolean) : [];
    const merged = Array.from(new Set([...existingIds, ...ids])); // dedup
    field.value = merged.join(', ');
    // Highlight visivo per dare feedback
    field.style.transition = 'background .3s';
    field.style.background = 'rgba(34,197,94,.15)';
    setTimeout(() => field.style.background = '', 600);
}

// CSP strict: delega i click dei bottoni generati in innerHTML (no inline onclick).
document.getElementById('fm-cs-results')?.addEventListener('click', function (e) {
    const b = e.target.closest('[data-cs-act]');
    if (!b) return;
    if (b.dataset.csAct === 'apply') fmContentSearchApply();
    else if (b.dataset.csAct === 'all') fmContentSearchSelectAll();
    else if (b.dataset.csAct === 'none') fmContentSearchSelectNone();
});
</script>

</div><!-- /.fm-card -->
