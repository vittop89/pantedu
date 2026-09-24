// Entry Vite di views/admin/logs_index.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { escHtml as fmEscapeHtml } from "../modules/core/dom-utils.js";

// ── blocco 1 ──
{
let fmLogsCurrentTab = document.querySelector('.fm-admin-tabs')?.dataset.current ?? '';
let fmLogsLastData = [];

function fmLogsLoadTab(tab) {
    fmLogsCurrentTab = tab;
    // Update tab classes
    document.querySelectorAll('.fm-admin-tab').forEach(a => {
        a.classList.toggle('fm-is-active', a.dataset.tab === tab);
    });
    // Update URL without reload
    history.replaceState(null, '', `/admin/logs?tab=${  encodeURIComponent(tab)}`);
    fmLogsRefresh();
}


async function fmLogsRefresh() {
    const status = document.getElementById('fm-logs-status');
    const results = document.getElementById('fm-logs-results');
    status.textContent = '⏳ Caricamento…';
    status.className = 'fm-inline-status';

    const params = new URLSearchParams();
    const since = document.getElementById('fm-logs-since').value;
    const until = document.getElementById('fm-logs-until').value;
    const tid    = document.getElementById('fm-logs-tid').value;
    const actor  = document.getElementById('fm-logs-actor').value;
    const role   = document.getElementById('fm-logs-role').value.trim();
    const action = document.getElementById('fm-logs-action').value.trim();
    const limit  = document.getElementById('fm-logs-limit').value || 100;
    if (since)  params.append('since', since);
    if (until)  params.append('until', until);
    if (tid)    params.append('teacher_id', tid);
    if (actor)  params.append('actor', actor);
    if (role)   params.append('role', role);
    if (action) params.append('action', action);
    params.append('limit', limit);

    try {
        const url = `/admin/logs/api/${  encodeURIComponent(fmLogsCurrentTab)  }?${  params.toString()}`;
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await res.json();
        if (!j.ok) {
            status.textContent = `✗ ${  j.error || 'error'}`;
            status.className = 'fm-inline-status fm-inline-status--error';
            results.innerHTML = '<p class="fm-muted">Errore caricamento dati.</p>';
            return;
        }
        // Una tabella che manca non è "nessun record": è un'installazione
        // incompleta, e va detto invece di lasciar credere che non sia
        // successo niente.
        if (j.warning === 'table_missing') {
            fmLogsLastData = [];
            results.innerHTML = `<p class="fm-alert fm-alert--warning">⚠️ ${
                 fmEscapeHtml(j.message || 'Tabella non presente su questa istanza.')
                 }</p>`;
            status.textContent = '⚠️ tabella assente';
            status.className = 'fm-inline-status fm-inline-status--error';
            return;
        }

        fmLogsLastData = j.rows || [];
        renderTable(j.rows);
        status.textContent = `✓ ${j.count} righe`;
        status.className = 'fm-inline-status fm-inline-status--ok';
    } catch (e) {
        status.textContent = `✗ ${  e.message}`;
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
    // audit_activity_log — operazioni e eventi di dominio
    'http_request':             ['🌐', 'richiesta',        'fm-waf-badge monitor'],
    'registration_submitted':   ['📝', 'iscrizione',       'fm-waf-badge monitor'],
    'registration_approved':    ['✅', 'iscr. approvata',  'fm-waf-badge pass'],
    'registration_rejected':    ['⛔', 'iscr. respinta',   'fm-waf-badge block'],
    'parent_consent_requested': ['📨', 'consenso chiesto', 'fm-waf-badge monitor'],
    'parent_consent_granted':   ['👨‍👩‍👧', 'consenso dato',   'fm-waf-badge pass'],
    'parent_consent_rejected':  ['🚫', 'consenso negato',  'fm-waf-badge block'],
    'parent_consent_revoked':   ['↩️', 'consenso revocato','fm-waf-badge block'],
    'parent_consent_expired':   ['⌛', 'consenso scaduto', 'fm-waf-badge soft'],
    'parent_consent_cleanup':   ['🧹', 'pulizia consensi', 'fm-waf-badge'],
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
    if (diff < 60) return `${Math.floor(diff)  }s fa`;
    if (diff < 3600) return `${Math.floor(diff/60)  }min fa`;
    if (diff < 86400) return `${Math.floor(diff/3600)  }h fa`;
    if (diff < 86400*7) return `${Math.floor(diff/86400)  }gg fa`;
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
            // IP (in chiaro nei log WAF, come hash troncato nei registri di audit): monospace
            if (c === 'ip_address' || c === 'ip_hash_short') {
                html += `<td><code class="fm-text-em-sm">${fmEscapeHtml(String(v))}</code></td>`;
                continue;
            }
            // Default
            const s = String(v);
            const short = s.length > 60 ? `${s.substring(0, 60)  }…` : s;
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
    let csv = `${cols.join(',')  }\n`;
    for (const r of fmLogsLastData) {
        csv += `${cols.map(c => {
            let v = (r[c] === null || r[c] === undefined) ? '' : String(r[c]);
            // Audit 25.R.31 — neutralizza formula/CSV injection (Excel/LibreOffice).
            if (v && "=+-@\t\r".includes(v[0])) v = `'${  v}`;
            return v.includes(',') || v.includes('"') ? `"${  v.replaceAll('"', '""')  }"` : v;
        }).join(',')  }\n`;
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


// ── P8 (2026-09-04): i listener che la vista agganciava con
// document.currentScript.previousElementSibling stanno qui, per data-*.
document.querySelectorAll(".fm-admin-tab[data-tab]").forEach((a) => {
    a.addEventListener("click", (event) => {
        event.preventDefault();
        fmLogsLoadTab(a.dataset.tab);
    });
});
document.querySelectorAll("[data-fm-logs-action]").forEach((btn) => {
    btn.addEventListener("click", () => {
        if (btn.dataset.fmLogsAction === "refresh") fmLogsRefresh();
        if (btn.dataset.fmLogsAction === "export") fmLogsExport();
    });
});
}
