<?php
/**
 * Phase 25.R.25 — Admin Logs unified panel.
 *
 * @var array<string,string> $tabs        key=table_name → label
 * @var string               $current     tab attivo
 * @var string               $csrf
 * @var array                $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '📜 Admin Logs — Vista unificata';
$page_subtitle = 'Visualizza tutti i log applicativi: eventi contenuti, accessi privilegiati, operazioni crypto, recovery, custody KMS.';
$breadcrumb    = [['label' => 'Logs']];
include __DIR__ . '/_partials/page_head.php';
?>

<nav class="fm-admin-tabs fm-m-0 fm-mb-6" >
    <?php foreach ($tabs as $key => $label): ?>
        <a class="fm-admin-tab <?= $key === $current ? 'is-active' : '' ?>"
           href="/admin/logs?tab=<?= $h($key) ?>" data-tab="<?= $h($key) ?>">
            <?= $h($label) ?>
        </a>
        <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){event.preventDefault(); fmLogsLoadTab('<?= $h($key) ?>'); return false;})</script>
    <?php endforeach; ?>
</nav>

<section class="fm-card fm-mb-4" >
    <h2 class="fm-mt-0 fm-text-17">🔎 Filtri</h2>
    <div class="fm-form-grid">
        <label>
            <span class="fm-form-label-text">Da data (since)</span>
            <input type="date" id="fm-logs-since" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">A data (until)</span>
            <input type="date" id="fm-logs-until" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Teacher ID</span>
            <input type="number" id="fm-logs-tid" min="1" placeholder="es. 77" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Actor user ID</span>
            <input type="number" id="fm-logs-actor" min="1" placeholder="es. 77" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Limit (1-500)</span>
            <input type="number" id="fm-logs-limit" min="1" max="500" value="100" class="fm-w-full">
        </label>
        <div class="fm-form-actions">
            <button type="button" class="fm-btn fm-btn--primary">🔎 Aggiorna</button>
            <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){fmLogsRefresh()})</script>
            <button type="button" class="fm-btn fm-btn--ghost">📥 Export CSV</button>
            <script>document.currentScript.previousElementSibling.addEventListener("click",function(event){fmLogsExport()})</script>
            <span id="fm-logs-status" class="fm-inline-status"></span>
        </div>
    </div>
</section>

<section class="fm-card">
    <div id="fm-logs-results">
        <p class="fm-muted">Caricamento…</p>
    </div>
</section>

</div><!-- /.fm-card -->

<script>
let fmLogsCurrentTab = <?= json_encode($current) ?>;
let fmLogsLastData = [];

function fmLogsLoadTab(tab) {
    fmLogsCurrentTab = tab;
    // Update tab classes
    document.querySelectorAll('.fm-admin-tab').forEach(a => {
        a.classList.toggle('fm-is-active', a.dataset.tab === tab);
    });
    // Update URL without reload
    history.replaceState(null, '', '/admin/logs?tab=' + encodeURIComponent(tab));
    fmLogsRefresh();
}

function fmEscapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function fmLogsRefresh() {
    const status = document.getElementById('fm-logs-status');
    const results = document.getElementById('fm-logs-results');
    status.textContent = '⏳ Caricamento…';
    status.className = 'fm-inline-status';

    const params = new URLSearchParams();
    const since = document.getElementById('fm-logs-since').value;
    const until = document.getElementById('fm-logs-until').value;
    const tid   = document.getElementById('fm-logs-tid').value;
    const actor = document.getElementById('fm-logs-actor').value;
    const limit = document.getElementById('fm-logs-limit').value || 100;
    if (since) params.append('since', since);
    if (until) params.append('until', until);
    if (tid)   params.append('teacher_id', tid);
    if (actor) params.append('actor', actor);
    params.append('limit', limit);

    try {
        const url = '/admin/logs/api/' + encodeURIComponent(fmLogsCurrentTab) + '?' + params.toString();
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await res.json();
        if (!j.ok) {
            status.textContent = '✗ ' + (j.error || 'error');
            status.className = 'fm-inline-status fm-inline-status--error';
            results.innerHTML = '<p class="fm-muted">Errore caricamento dati.</p>';
            return;
        }
        fmLogsLastData = j.rows || [];
        renderTable(j.rows);
        status.textContent = `✓ ${j.count} righe`;
        status.className = 'fm-inline-status fm-inline-status--ok';
    } catch (e) {
        status.textContent = '✗ ' + e.message;
        status.className = 'fm-inline-status fm-inline-status--error';
    }
}

// Phase 25.R.25 — Action badge mapping (content_action_log + privileged_access_log)
const FM_ACTION_BADGES = {
    // content_action_log
    'content_created':     ['🆕', 'created',      'fm-waf-badge pass'],
    'content_updated':     ['✏️', 'updated',      'fm-waf-badge monitor'],
    'content_published':   ['🌐', 'published',    'fm-waf-badge whitelist'],
    'content_unpublished': ['🔒', 'unpublished',  'fm-waf-badge soft'],
    'content_archived':    ['📦', 'archived',     'fm-waf-badge'],
    'content_deleted':     ['🗑️', 'deleted',      'fm-waf-badge block'],
    'content_cloned_from': ['📋', 'cloned',       'fm-waf-badge monitor'],
    'content_shared':      ['🔗', 'shared',       'fm-waf-badge whitelist'],
    'content_unshared':    ['🚫', 'unshared',     'fm-waf-badge soft'],
    'content_exported':    ['📥', 'exported',     'fm-waf-badge challenge_first'],
    // privileged_access_log
    'list':                ['📋', 'list',         'fm-waf-badge monitor'],
    'read':                ['👁', 'read',          'fm-waf-badge monitor'],
    'write':               ['✍️', 'write',         'fm-waf-badge soft'],
    'create':              ['🆕', 'create',       'fm-waf-badge pass'],
    'update':              ['✏️', 'update',       'fm-waf-badge soft'],
    'delete':              ['🗑️', 'delete',       'fm-waf-badge block'],
    'admin_mutation':      ['⚙️', 'admin_mutation','fm-waf-badge challenge_first'],
    'export':              ['📥', 'export',       'fm-waf-badge challenge_first'],
    // crypto_access_log
    'encrypt':             ['🔐', 'encrypt',      'fm-waf-badge whitelist'],
    'decrypt':             ['🔓', 'decrypt',      'fm-waf-badge soft'],
    'shred':               ['💥', 'shred',        'fm-waf-badge block'],
    'rotate':              ['🔄', 'rotate',       'fm-waf-badge monitor'],
    'wrap':                ['📦', 'wrap',         'fm-waf-badge monitor'],
    'unwrap':              ['📂', 'unwrap',       'fm-waf-badge soft'],
};
const FM_DATE_COLS = ['occurred_at', 'created_at', 'accessed_at', 'ts', 'updated_at'];
const FM_LONG_COLS = ['details_json', 'reason', 'description_preview', 'change_summary', 'user_agent'];

function fmFormatRelative(ts) {
    if (!ts) return '—';
    const d = new Date(ts.replace(' ', 'T'));
    if (isNaN(d.getTime())) return ts;
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return Math.floor(diff) + 's fa';
    if (diff < 3600) return Math.floor(diff/60) + 'min fa';
    if (diff < 86400) return Math.floor(diff/3600) + 'h fa';
    if (diff < 86400*7) return Math.floor(diff/86400) + 'gg fa';
    return ts.substring(0, 10);
}

function fmRenderActionBadge(action) {
    const def = FM_ACTION_BADGES[action];
    if (!def) return `<span class="fm-waf-badge">${fmEscapeHtml(action)}</span>`;
    return `<span class="${def[2]}" title="${fmEscapeHtml(action)}">${def[0]} ${def[1]}</span>`;
}

function fmRenderJsonCell(jsonStr) {
    if (!jsonStr) return '<small class="fm-muted">—</small>';
    try {
        const obj = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
        const pretty = JSON.stringify(obj, null, 2);
        const summary = Object.keys(obj).slice(0, 3).join(', ');
        return `<details class="fm-max-w-300"><summary class="fm-cursor-pointer fm-text-em-md">{${fmEscapeHtml(summary)}…}</summary>`
             + `<pre class="fm-log-preview">${fmEscapeHtml(pretty)}</pre></details>`;
    } catch {
        return `<small title="${fmEscapeHtml(String(jsonStr))}">${fmEscapeHtml(String(jsonStr).substring(0, 60))}…</small>`;
    }
}

function renderTable(rows) {
    const results = document.getElementById('fm-logs-results');
    if (!rows || rows.length === 0) {
        results.innerHTML = '<p class="fm-muted">Nessun record trovato con questi filtri.</p>';
        return;
    }
    // Column ordering: id first, dates next, action/event/type early, long fields last
    const allCols = Object.keys(rows[0]);
    const dateCols = allCols.filter(c => FM_DATE_COLS.includes(c));
    const longCols = allCols.filter(c => FM_LONG_COLS.includes(c));
    const otherCols = allCols.filter(c => !dateCols.includes(c) && !longCols.includes(c) && c !== 'id');
    const cols = ['id', ...dateCols, ...otherCols, ...longCols].filter(c => allCols.includes(c));

    let html = '<div class="fm-logs-wrapper">'
             + '<table class="fm-logs-table"><thead><tr>';
    for (const c of cols) html += `<th scope="col">${fmEscapeHtml(c)}</th>`;
    html += '</tr></thead><tbody>';

    for (const r of rows) {
        html += '<tr>';
        for (const c of cols) {
            const v = r[c];
            if (v === null || v === undefined) {
                html += `<td><small class="fm-muted">—</small></td>`;
                continue;
            }
            // Dates → relative + tooltip absolute
            if (FM_DATE_COLS.includes(c)) {
                html += `<td title="${fmEscapeHtml(String(v))}"><small>${fmEscapeHtml(fmFormatRelative(String(v)))}</small></td>`;
                continue;
            }
            // Action badge
            if (c === 'action') {
                html += `<td>${fmRenderActionBadge(String(v))}</td>`;
                continue;
            }
            // Event type (custody) badge
            if (c === 'event_type') {
                html += `<td><code class="fm-text-em-sm">${fmEscapeHtml(String(v))}</code></td>`;
                continue;
            }
            // JSON details collapsible
            if (FM_LONG_COLS.includes(c) && c === 'details_json') {
                html += `<td>${fmRenderJsonCell(v)}</td>`;
                continue;
            }
            // outcome: red/green/yellow badge
            if (c === 'outcome') {
                const s = String(v);
                const sl = s.toLowerCase();
                const isOk = ['ok','granted','success','pass','allow'].includes(sl);
                const isBad = ['denied','fail','error','blocked'].some(k => sl.includes(k));
                const isWarn = ['warn','soft','challenge','partial'].some(k => sl.includes(k));
                const cls = isOk   ? 'fm-waf-badge pass'
                          : isBad  ? 'fm-waf-badge block'
                          : isWarn ? 'fm-waf-badge soft'
                          : 'fm-waf-badge';
                html += `<td><span class="${cls}">${fmEscapeHtml(s)}</span></td>`;
                continue;
            }
            // actor_role: ruoli colorati
            if (c === 'actor_role') {
                const s = String(v);
                const cls = s === 'super_admin' ? 'fm-waf-badge block'
                          : s === 'administrator' ? 'fm-waf-badge challenge_first'
                          : s === 'teacher' ? 'fm-waf-badge whitelist'
                          : 'fm-waf-badge';
                html += `<td><span class="${cls}">${fmEscapeHtml(s)}</span></td>`;
                continue;
            }
            // resource_type / content_type: code style
            if (c === 'content_type' || c === 'resource_type' || c === 'table_name' || c === 'operation') {
                html += `<td><code class="fm-text-em-sm">${fmEscapeHtml(String(v))}</code></td>`;
                continue;
            }
            // IDs: monospace right-aligned
            if (c === 'id' || c === 'content_id' || c === 'teacher_id' || c === 'user_id' ||
                c === 'actor_user_id' || c === 'accessor_id' || c === 'row_id' || c === 'resource_id') {
                html += `<td class="fm-text-right fm-font-mono fm-text-em-md">${fmEscapeHtml(String(v))}</td>`;
                continue;
            }
            // IP address: monospace
            if (c === 'ip_address') {
                html += `<td><code class="fm-text-em-sm">${fmEscapeHtml(String(v))}</code></td>`;
                continue;
            }
            // Default
            const s = String(v);
            const short = s.length > 60 ? s.substring(0, 60) + '…' : s;
            html += `<td title="${fmEscapeHtml(s)}"><small>${fmEscapeHtml(short)}</small></td>`;
        }
        html += '</tr>';
    }
    html += '</tbody></table></div>';
    results.innerHTML = html;
}

function fmLogsExport() {
    if (!fmLogsLastData.length) { alert('Nessun dato da esportare. Esegui prima una query.'); return; }
    const cols = Object.keys(fmLogsLastData[0]);
    let csv = cols.join(',') + '\n';
    for (const r of fmLogsLastData) {
        csv += cols.map(c => {
            let v = (r[c] === null || r[c] === undefined) ? '' : String(r[c]);
            // Audit 25.R.31 — neutralizza formula/CSV injection (Excel/LibreOffice).
            if (v && "=+-@\t\r".includes(v[0])) v = "'" + v;
            return v.includes(',') || v.includes('"') ? '"' + v.replaceAll('"', '""') + '"' : v;
        }).join(',') + '\n';
    }
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `admin-logs-${fmLogsCurrentTab}-${new Date().toISOString().replace(/[:.]/g, '-')}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// Load initial data
fmLogsRefresh();
</script>
