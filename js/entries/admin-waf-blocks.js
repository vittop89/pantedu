// Entry Vite di views/admin/waf/blocks.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { auditReason } from "../modules/core/audit-reason.js";
import { escHtml as escapeHtml } from "../modules/core/dom-utils.js";

// ── blocco 1 ──
{
const _wafCfg = document.getElementById('fm-waf-blocks-config')?.dataset ?? {};
const CSRF = _wafCfg.csrf ?? '';
const CLIENT_IP = _wafCfg.clientIp ?? '';
const ENRICH = _wafCfg.enrich === '1';

// ─── Lists API (whitelist/blacklist) ───────────────────────────
async function addList(e, kind) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const res = await fetch(`/admin/waf/api/${  kind}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest',
            // Niente indirizzo nel motivo: in privileged_access_log l'IP sta solo
            // come hash, e la voce della lista lo tiene già (23/9/2026). E il
            // motivo scritto a mano passa da auditReason, che toglie i
            // caratteri che un'intestazione non sa portare.
            'X-Audit-Reason': auditReason(`Aggiunta di un indirizzo alla ${  kind}`, data.reason || 'nessun motivo indicato')
        },
        body: JSON.stringify(data),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
    else alert(`Errore: ${  j.error || 'sconosciuto'}`);
    return false;
}

async function deleteListItem(kind, id) {
    if (!confirm('Eliminare?')) return;
    const res = await fetch(`/admin/waf/api/${  kind  }/${  id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest',
            'X-Audit-Reason': `Rimozione voce #${  id  } da ${  kind}`
        },
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
}

async function addMyIp(kind) {
    const reason = prompt(`Motivo per aggiungere ${  CLIENT_IP  } a ${  kind  }:`,
                          kind === 'whitelist' ? 'admin trusted' : 'manual block');
    if (reason === null) return;
    const res = await fetch(`/admin/waf/api/${  kind}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest',
            'X-Audit-Reason': auditReason(`Aggiunta del proprio indirizzo alla ${  kind}`, reason)
        },
        body: JSON.stringify({ ip_or_cidr: CLIENT_IP, reason }),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
    else alert(`Errore: ${  j.error || 'sconosciuto'}`);
}

async function removeMyIpFromBlacklist() {
    const blSection = document.getElementById('blacklist');
    const tbody = blSection.querySelectorAll('table.fm-waf-table tbody tr');
    for (const tr of tbody) {
        const code = tr.querySelector('code')?.textContent || '';
        if (code === CLIENT_IP) {
            const btn = tr.querySelector('button[data-act="delItem"][data-list="blacklist"]');
            if (btn) { btn.click(); return; }
        }
    }
    alert(`Il tuo IP (${  CLIENT_IP  }) non è in blacklist.`);
}

async function unbanAll() {
    if (!confirm('⚠️ ATTENZIONE: questa operazione rimuove TUTTI gli IP dalla blacklist.\n\nProcedere?')) return;
    if (!confirm('Confermi davvero? Operazione irreversibile.')) return;
    const tbody = document.querySelector('#blacklist table.fm-waf-table tbody');
    const btns = tbody?.querySelectorAll('button[data-act="delItem"][data-list="blacklist"]') ?? [];
    let count = 0;
    for (const btn of btns) {
        const id = btn.dataset.id;
        if (!id) continue;
        const res = await fetch(`/admin/waf/api/blacklist/${  id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest',
                'X-Audit-Reason': `Sblocco massivo: rimozione voce #${  id  } dalla blacklist`
            },
            credentials: 'same-origin'
        });
        if ((await res.json()).ok) count++;
    }
    alert(`${count  } IP sbloccati. Ricarico pagina.`);
    location.reload();
}

// ─── Auth-flow API (IP+credentials) ────────────────────────────
async function apiGet(url) {
    if (ENRICH) {
        url += `${url.includes('?') ? '&' : '?'  }enrich=1`;
    }
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });
    return res.json();
}

// `motivo` va in X-Audit-Reason: le rotte /api/admin/security/* lo
// pretendono dal 23/9/2026 (A-69), e senza rispondono 400.
async function apiPost(url, data, motivo) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    fd.append('_csrf', CSRF);
    const res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'X-Audit-Reason': motivo },
        credentials: 'same-origin'
    });
    return res.json();
}

async function loadCreds() {
    const r = await apiGet('/api/admin/security/blocked-credentials');
    const tbl = document.getElementById('fm-cred-table');
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = '<p class="fm-muted">Nessuna credenziale bloccata.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr><th scope="col">Username</th><th scope="col">Bloccato il</th><th scope="col">Motivo</th><th scope="col">Da</th><th scope="col"></th></tr></thead><tbody>';
    for (const row of r.rows) {
        html += `<tr>
            <td><code>${escapeHtml(row.username||'')}</code></td>
            <td><small>${escapeHtml(row.blocked_at||'')}</small></td>
            <td>${escapeHtml(row.reason||'')}</td>
            <td><small>${escapeHtml(row.blocked_by||'')}</small></td>
            <td><button class="fm-btn fm-btn--xs" data-act="unblockCred" data-username="${escapeHtml(row.username||'')}">✓ Sblocca</button></td>
        </tr>`;
    }
    tbl.innerHTML = `${html  }</tbody></table></div>`;
}

async function loadIps() {
    const r = await apiGet('/api/admin/security/blocked-ips');
    const tbl = document.getElementById('fm-ip-table');
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = '<p class="fm-muted">Nessun IP bloccato lato auth-flow.</p>';
        return;
    }
    const extraCols = ENRICH ? '<th scope="col">rDNS</th><th scope="col">ASN</th>' : '';
    let html = `<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr><th scope="col">IP</th><th scope="col">Country</th>${extraCols}<th scope="col">Sezione</th><th scope="col">Bloccato il</th><th scope="col">Motivo</th><th scope="col"></th></tr></thead><tbody>`;
    for (const row of r.rows) {
        const flagHtml = row.country_flag || '';
        const ccText = escapeHtml(row.country || '–');
        let enrichCells = '';
        if (ENRICH) {
            const rdns = row.rdns
                ? `<span class="fm-waf-rdns">${escapeHtml(row.rdns)}</span>`
                : '<small class="fm-muted">–</small>';
            const asn = row.asn
                ? `<span class="fm-waf-asn"><span class="fm-waf-asn__num">AS${parseInt(row.asn, 10)}</span>${row.org ? `<span class="fm-waf-asn__org">${escapeHtml(row.org)}</span>` : ''}</span>`
                : '<small class="fm-muted">–</small>';
            enrichCells = `<td>${rdns}</td><td>${asn}</td>`;
        }
        html += `<tr>
            <td><code>${escapeHtml(row.ip||'')}</code></td>
            <td><span class="fm-waf-flag">${flagHtml}</span> <small>${ccText}</small></td>
            ${enrichCells}
            <td><small>${escapeHtml(row.section||'global')}</small></td>
            <td><small>${escapeHtml(row.blocked_at||'')}</small></td>
            <td>${escapeHtml(row.reason||'')}</td>
            <td>
                <button class="fm-btn fm-btn--xs" data-act="unblockIp" data-ip="${escapeHtml(row.ip||'')}" data-section="${escapeHtml(row.section||'')}">✓ Sblocca</button>
                <button class="fm-btn fm-btn--xs" data-act="cti" data-ip="${escapeHtml(row.ip||'')}" title="Chiedi a CrowdSec che reputazione ha questo indirizzo">🔎 Chi è</button>
            </td>
        </tr>`;
    }
    tbl.innerHTML = `${html  }</tbody></table></div>`;
}

async function blockCred(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    // Niente nome utente né indirizzo nel motivo: stanno nell'elenco dei
    // blocchi, e nei registri di audit l'IP si tiene solo come hash.
    const r = await apiPost('/api/admin/security/credentials/block', data,
        auditReason('Blocco manuale di una credenziale dal pannello WAF', data.reason));
    if (r.ok) { e.target.reset(); loadCreds(); }
    else alert(`Errore: ${  r.error || 'sconosciuto'}`);
    return false;
}

async function blockIp(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const r = await apiPost('/api/admin/security/ips/block', data,
        auditReason(`Blocco manuale di un indirizzo sulla sezione ${data.section || 'globale'}`, data.reason));
    if (r.ok) { e.target.reset(); loadIps(); }
    else alert(`Errore: ${  r.error || 'sconosciuto'}`);
    return false;
}

async function unblockCred(username) {
    if (!confirm(`Sblocca credenziale ${  username  }?`)) return;
    const r = await apiPost('/api/admin/security/credentials/unblock', { username },
        auditReason('Sblocco di una credenziale dal pannello WAF, confermato'));
    if (r.ok) loadCreds();
}

async function unblockIp(ip, section) {
    if (!confirm(`Sblocca IP ${  ip  }${section ? ` (sezione: ${  section  })` : ''  }?`)) return;
    const r = await apiPost('/api/admin/security/ips/unblock', { ip, section },
        auditReason(`Sblocco di un indirizzo sulla sezione ${section || 'globale'}, confermato`));
    if (r.ok) loadIps();
}


// ─── Live blocks (cross-source aggregate da waf_logs) ──────────
async function loadLiveBlocks() {
    const hours = document.getElementById('fm-live-hours')?.value || 24;
    const status = document.getElementById('fm-live-status');
    const tbl = document.getElementById('fm-live-blocks-table');
    if (status) status.textContent = '⏳';
    const r = await apiGet(`/api/admin/security/live-blocks?hours=${  encodeURIComponent(hours)}`);
    if (status) status.textContent = '';
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = `<p class="fm-muted">Nessun IP bloccato nelle ultime ${  escapeHtml(String(hours))  } ore.</p>`;
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr>'
        + '<th scope="col">IP</th><th scope="col">Country</th><th scope="col">Sorgente (last)</th><th scope="col">Tutte sorgenti</th>'
        + '<th scope="col">Hit</th><th scope="col">Primo</th><th scope="col">Ultimo</th><th scope="col">Stato</th><th scope="col"></th></tr></thead><tbody>';
    for (const row of r.rows) {
        const flag = row.country_flag || '';
        const cc = escapeHtml(row.country || '–');
        const last = escapeHtml(row.last_outcome || '');
        const sources = escapeHtml(row.sources || '').replaceAll(',', ', ');
        let stateBadge;
        if (row.in_whitelist) {
            stateBadge = '<span class="fm-waf-badge whitelist" title="IP in whitelist permanente">✅ whitelisted</span>';
        } else if (row.in_blacklist) {
            stateBadge = '<span class="fm-waf-badge block" title="Già in waf_blacklist permanente">📌 in blacklist</span>';
        } else {
            stateBadge = '<span class="fm-waf-badge monitor" title="Bloccato al volo (no entry persistente)">⚡ live-only</span>';
        }
        const action = (row.in_blacklist || row.in_whitelist)
            ? ''
            : `<button class="fm-btn fm-btn--xs" data-act="promote" data-ip="${escapeHtml(row.ip)}" data-outcome="${escapeHtml(row.last_outcome)}" title="Aggiungi a waf_blacklist permanente">📌 Blacklist</button>`;
        const btnCti = `<button class="fm-btn fm-btn--xs" data-act="cti" data-ip="${escapeHtml(row.ip||'')}" title="Chiedi a CrowdSec che reputazione ha questo indirizzo">🔎 Chi è</button>`;
        html += `<tr>
            <td><code>${escapeHtml(row.ip||'')}</code></td>
            <td><span class="fm-waf-flag">${flag}</span> <small>${cc}</small></td>
            <td><span class="fm-waf-badge ${last}">${last}</span></td>
            <td><small class="fm-muted">${sources}</small></td>
            <td>${row.count}</td>
            <td><small>${escapeHtml(row.first_seen||'')}</small></td>
            <td><small>${escapeHtml(row.last_seen||'')}</small></td>
            <td>${stateBadge}</td>
            <td>${action} ${btnCti}</td>
        </tr>`;
    }
    tbl.innerHTML = `${html  }</tbody></table></div>`;
}

async function promoteToBlacklist(ip, lastOutcome) {
    const reason = prompt(`Motivo per blacklist permanente di ${  ip  }:`,
                          `live-block promoted (last outcome: ${  lastOutcome  })`);
    if (reason === null) return;
    const res = await fetch('/admin/waf/api/blacklist', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest',
            'X-Audit-Reason': auditReason('Blacklist permanente di un indirizzo bloccato di recente', reason)
        },
        body: JSON.stringify({ ip_or_cidr: ip, reason }),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) {
        loadLiveBlocks();
        // ricarica pagina per aggiornare anche sezione Blacklist (PHP-rendered)
        setTimeout(() => location.reload(), 600);
    } else {
        alert(`Errore: ${  j.error || 'sconosciuto'}`);
    }
}

// ─── Threat Intel sync (ex /admin/waf/threat-intel) ────────────
async function syncThreatSource(source) {
    const status = document.getElementById('fm-ti-sync-status');
    if (status) {
        status.textContent = source === 'all' ? '⏳ Sync tutti…' : `⏳ Sync ${source}…`;
        status.className = 'fm-inline-status';
    }
    try {
        const res = await fetch('/admin/waf/api/threat-intel/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'X-Audit-Reason': `Sincronizzazione threat intelligence: sorgente ${  source}`,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ source }),
        });
        const j = await res.json();
        if (j.ok) {
            const total = Object.values(j.results || {}).reduce((acc, r) => acc + ((r && r.imported) || 0), 0);
            const failed = Object.entries(j.results || {}).filter(([, r]) => !r.ok).map(([k]) => k);
            let msg = `✓ Sync completo: ${total} entries`;
            if (failed.length) msg += ` (failed: ${failed.join(',')})`;
            if (status) {
                status.textContent = msg;
                status.className = `fm-inline-status fm-inline-status--${  failed.length ? 'error' : 'ok'}`;
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            if (status) {
                status.textContent = '✗ Sync failed';
                status.className = 'fm-inline-status fm-inline-status--error';
            }
        }
    } catch (err) {
        if (status) {
            status.textContent = `✗ Errore: ${  err.message}`;
            status.className = 'fm-inline-status fm-inline-status--error';
        }
    }
}

// ─── Anomalies detected (ex /admin/waf/anomalies) ──────────────
async function loadAnomalies() {
    const status = document.getElementById('fm-anomalies-status');
    const list = document.getElementById('fm-anomalies-list');
    if (status) status.textContent = '⏳';
    const r = await apiGet('/api/admin/security/anomalies');
    if (status) status.textContent = '';
    if (!r.ok || !r.rows || !r.rows.length) {
        list.innerHTML = '<p class="fm-muted">Nessuna anomalia rilevata.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr>'
        + '<th scope="col">Tipo</th><th scope="col">Status</th><th scope="col">Utente / IP</th><th scope="col">Risk</th><th scope="col">Count</th>'
        + '<th scope="col">Detected</th><th scope="col">Detail</th></tr></thead><tbody>';
    for (const a of r.rows) {
        const risk = a.risk_level || '?';
        const cls = risk === 'high' ? 'block' : (risk === 'medium' ? 'soft' : 'pass');
        const blocked = a.blocked === true;
        const statusBadge = blocked
            ? '<span class="fm-waf-badge block" title="Già presente in waf_blocked_ips / waf_blocked_credentials">🔒 blocked</span>'
            : '<span class="fm-waf-badge monitor" title="Rilevato ma non bloccato">👁 detected</span>';
        const target  = escapeHtml(a.username || a.ip || '');
        const lastSeen = a.last_seen || a.detected_at || '';
        const detail  = a.detail || (a.section ? `sezione: ${a.section}` : (Array.isArray(a.ips) ? `${a.ips.length} IP` : ''));
        html += `<tr>
            <td>${escapeHtml(a.type)}</td>
            <td>${statusBadge}</td>
            <td><code>${target}</code></td>
            <td><span class="fm-waf-badge ${cls}">${escapeHtml(risk)}</span></td>
            <td>${escapeHtml(a.count || '')}</td>
            <td><small>${escapeHtml(lastSeen)}</small></td>
            <td><small>${escapeHtml(detail)}</small></td>
        </tr>`;
    }
    list.innerHTML = `${html  }</tbody></table></div>`;
}


/**
 * Chiede a CrowdSec CTI che reputazione ha un indirizzo, e la mostra sotto la
 * riga.
 *
 * Su richiesta e un indirizzo per volta: è quello che la chiave del piano
 * gratuito permette, e la quota è giornaliera. Il server tiene la risposta in
 * cache ventiquattr'ore, quindi ripremere il bottone sullo stesso indirizzo
 * non consuma niente — e lo dice, così chi guarda sa se sta leggendo una cosa
 * di adesso o di ieri.
 */
async function chiediCti(bottone, ip) {
    if (!ip) return;
    const riga = bottone.closest('tr');
    if (!riga) return;

    // Se il pannello è già aperto, il bottone lo chiude.
    const gia = riga.nextElementSibling;
    if (gia?.classList.contains('fm-waf-cti-riga')) {
        gia.remove();
        return;
    }

    const colonne = riga.children.length;
    const sotto = document.createElement('tr');
    sotto.className = 'fm-waf-cti-riga';
    sotto.innerHTML = `<td colspan="${colonne}"><small class="fm-muted">Chiedo a CrowdSec…</small></td>`;
    riga.after(sotto);

    let d;
    try {
        d = await apiGet(`/admin/waf/api/cti?ip=${encodeURIComponent(ip)}`);
    } catch (e) {
        sotto.innerHTML = `<td colspan="${colonne}"><small class="fm-muted">Non ha risposto: ${escapeHtml(String(e?.message || e))}</small></td>`;
        return;
    }

    if (!d?.ok) {
        sotto.innerHTML = `<td colspan="${colonne}"><small class="fm-muted">${escapeHtml(d?.motivo || 'nessuna informazione')}</small></td>`;
        return;
    }

    const voce = (etichetta, valore) => valore
        ? `<span class="fm-waf-cti__voce"><b>${escapeHtml(etichetta)}:</b> ${escapeHtml(String(valore))}</span>`
        : '';
    const classi = Array.isArray(d.classificazioni) && d.classificazioni.length
        ? voce('visto come', d.classificazioni.join(', '))
        : '';

    sotto.innerHTML = `<td colspan="${colonne}">
        <div class="fm-waf-cti">
            ${voce('reputazione', d.reputazione)}
            ${voce('fiducia', d.fiducia)}
            ${voce('rumore di fondo', d.rumore)}
            ${voce('operatore', d.operatore ? `${d.operatore}${d.asn ? ` (AS${d.asn})` : ''}` : '')}
            ${voce('paese', d.paese)}
            ${voce('nome inverso', d.inverso)}
            ${classi}
            ${voce('ultimo avvistamento', d.ultimo_avvistamento)}
            <small class="fm-muted">${d.dalla_cache ? 'dalla cache (non consuma quota)' : 'chiesto adesso'}</small>
        </div>
    </td>`;
}

// CSP strict — event delegation (sostituisce gli on* inline rimossi).
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    switch (b.dataset.act) {
        case 'addMyIpWl':   addMyIp('whitelist'); break;
        case 'unbanMyIp':   removeMyIpFromBlacklist(); break;
        case 'unbanAll':    unbanAll(); break;
        case 'loadLive':    loadLiveBlocks(); break;
        case 'loadAnoms':   loadAnomalies(); break;
        case 'syncTi':      syncThreatSource(b.dataset.source); break;
        case 'delItem':     deleteListItem(b.dataset.list, parseInt(b.dataset.id, 10)); break;
        case 'unblockCred': unblockCred(b.dataset.username); break;
        case 'unblockIp':   unblockIp(b.dataset.ip, b.dataset.section); break;
        case 'promote':     promoteToBlacklist(b.dataset.ip, b.dataset.outcome); break;
        case 'cti':         chiediCti(b, b.dataset.ip); break;
    }
});
document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-act-change="loadLive"]');
    if (el) loadLiveBlocks();
});
document.addEventListener('submit', (e) => {
    const f = e.target.closest('[data-act-submit]');
    if (!f) return;
    e.preventDefault();
    switch (f.dataset.actSubmit) {
        case 'addWl':     addList(e, 'whitelist'); break;
        case 'addBl':     addList(e, 'blacklist'); break;
        case 'blockIp':   blockIp(e); break;
        case 'blockCred': blockCred(e); break;
    }
});

loadCreds();
loadIps();
loadLiveBlocks();
loadAnomalies();
}
