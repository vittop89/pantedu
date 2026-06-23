// Questo file è caricato come script CLASSICO (no type=module): fm-router lo
// re-inietta ad ogni navigazione SPA e i moduli si eseguono una sola volta per
// URL — un import romperebbe la re-inizializzazione (e dava
// "Cannot use import statement outside a module"). escHtml arriva dal modulo
// dom-utils via window.FM.DomUtils (lazy: risolto a call-time, quando il
// bootstrap ha già popolato FM). Nessuna duplicazione del helper.
const escHtml = (s) => window.FM.DomUtils.escHtml(s);
// CSRF token centralizzato (cache 60s in dom-utils): un solo helper invece
// delle ~8 copie di `fetch('/auth/csrf').then(...token)` sparse nel file.
const csrf = () => window.FM.DomUtils.fetchCsrf();

// G22.S22 — Dashboard sub-tabs: toggle panels by data-dash-panel attribute.
(function() {
    const tabs = document.querySelectorAll('.fm-dash-tab');
    const panels = document.querySelectorAll('[data-dash-panel]');
    if (!tabs.length) return;

    function activate(name) {
        tabs.forEach(t => {
            const on = t.dataset.dashTab === name;
            t.classList.toggle('fm-dash-tab--active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(p => {
            const tags = (p.dataset.dashPanel || '').split(/\s+/);
            p.hidden = !tags.includes(name);
        });
        try { history.replaceState({}, '', '#' + name); } catch (_) {}
    }

    tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.dashTab)));

    // G22.S25 — Ricerca contenuti docente (teacher_content via
    // /api/teacher/content). Dropdown popolati dal curriculum dell'istituto
    // attivo. Click su un risultato → apre /studio/{type}/{ind}/{cls}/{subj}.
    const exForm = document.getElementById('fm-ex-form');
    const exResults = document.getElementById('fm-ex-results');
    if (exForm && exResults) {
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g,
            c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const TYPE_ICONS = { mappa: '🗺️', esercizio: '📝', verifica: '📋', lab: '🧪' };
        const VIS_LABELS = { draft: 'bozza', archived: 'archiviato', published: 'pubblicato' };

        // G22.S25 — Filter persistence per session: sessionStorage save/restore
        // dei valori del form. Saved on input/change, restored on init.
        const STATE_KEY = 'fm-dash-ex-search-filters';
        const saveState = () => {
            try {
                const fd = new FormData(exForm);
                const state = {};
                for (const [k, v] of fd.entries()) state[k] = String(v);
                // Checkbox "archived" non emette key se unchecked: salva esplicito.
                state.archived = exForm.querySelector('input[name="archived"]').checked ? '1' : '';
                sessionStorage.setItem(STATE_KEY, JSON.stringify(state));
            } catch (_) {}
        };
        const restoreState = () => {
            try {
                const raw = sessionStorage.getItem(STATE_KEY);
                if (!raw) return null;
                return JSON.parse(raw);
            } catch (_) { return null; }
        };
        const applyState = (state) => {
            if (!state) return;
            for (const el of exForm.elements) {
                if (!el.name || !(el.name in state)) continue;
                if (el.type === 'checkbox') el.checked = state[el.name] === '1';
                else el.value = state[el.name];
            }
        };
        // Listener: save on change/input. Submit triggera anche save (via change
        // dei field, ma submit è il momento canonico per persistere).
        exForm.addEventListener('change', saveState);
        exForm.addEventListener('input', saveState);

        // Popola dropdown curriculum (one-shot al load del pannello panoramica),
        // poi ripristina filtri sessionStorage e auto-submit se non vuoti.
        (async () => {
            try {
                const r = await fetch('/api/teacher/curriculum?scope=all', { credentials: 'same-origin' });
                const j = await r.json();
                if (j.ok) {
                    const cur = j.curriculum || {};
                    for (const sel of exForm.querySelectorAll('select[data-fm-curriculum]')) {
                        const kind = sel.dataset.fmCurriculum; // indirizzi|classi|materie
                        const items = cur[kind] || [];
                        const fragments = items
                            .filter(e => e.active !== false)
                            .map(e => `<option value="${esc(e.code)}">${esc(e.label || e.code)}</option>`)
                            .join('');
                        sel.insertAdjacentHTML('beforeend', fragments);
                    }
                }
            } catch (_) { /* fallback: dropdown vuoti, ricerca per q text-only */ }
            // Restore filtri dalla sessione precedente. Auto-submit se almeno
            // un valore non-vuoto è ripristinato (evita run inutile su default).
            const state = restoreState();
            if (state) {
                applyState(state);
                const hasAny = Object.values(state).some(v => v && v !== '');
                if (hasAny) exForm.dispatchEvent(new Event('submit'));
            }
        })();

        // Costruisce URL specifico per il contenuto: include topic così aprire
        // il link va direttamente all'item, non alla lista topics. Apre in
        // nuova scheda (target=_blank) per non perdere la dashboard.
        const buildHref = (r) => {
            if (!r.content_type || !r.indirizzo || !r.classe || !r.subject_code) return null;
            const parts = [r.content_type, r.indirizzo, r.classe, r.subject_code];
            if (r.topic) parts.push(r.topic);
            return '/studio/' + parts.map(encodeURIComponent).join('/');
        };

        const restoreRow = async (id, btn) => {
            btn.disabled = true;
            const orig = btn.textContent;
            btn.textContent = '⏳ …';
            try {
                const tok = await csrf();
                const fd = new URLSearchParams();
                fd.set('visibility', 'draft');
                fd.set('_csrf', tok);
                const r = await fetch(`/api/teacher/content/${id}/update`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': tok, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: fd.toString(),
                });
                const j = await r.json();
                if (j.ok) {
                    btn.textContent = '✓ Ripristinato';
                    btn.classList.add('fm-btn--ghost');
                    window.FM?.SyncPanel?.notify?.('Archivio', 'ok', '✓ Spostato in bozze', 2000);
                } else {
                    btn.textContent = orig;
                    btn.disabled = false;
                    window.FM?.SyncPanel?.notify?.('Archivio', 'error', 'Errore: ' + (j.error || 'unknown'), 4000);
                }
            } catch (e) {
                btn.textContent = orig;
                btn.disabled = false;
                window.FM?.SyncPanel?.notify?.('Archivio', 'error', 'Errore rete: ' + e.message, 4000);
            }
        };

        const render = (data) => {
            if (!data.ok) {
                exResults.innerHTML = `<p class="fm-muted fm-text-13 fm-mt-3 fm-mr-0 fm-mb-0 fm-ml-0" >Errore: ${esc(data.error || 'unknown')}</p>`;
                return;
            }
            if (data.count === 0) {
                exResults.innerHTML = `<p class="fm-muted fm-text-13 fm-mt-3 fm-mr-0 fm-mb-0 fm-ml-0" >Nessun contenuto trovato.</p>`;
                return;
            }
            const rows = data.rows.map(r => {
                const icon  = TYPE_ICONS[r.content_type] || '📄';
                const scope = [r.indirizzo, r.classe, r.subject_code].filter(Boolean).join('/');
                const archived = r.visibility === 'archived';
                const href = buildHref(r);
                // Archived: niente link studio (filtrato lato render), bottone Ripristina.
                // Altri: link in nuova scheda all'item specifico.
                const titleEsc = esc(r.title || '(senza titolo)');
                const title = (href && !archived)
                    ? `<a class="fm-link" href="${href}" target="_blank" rel="noopener">${titleEsc}</a>`
                    : titleEsc;
                const actions = archived
                    ? `<button type="button" class="fm-btn fm-btn--xs fm-btn--primary" data-fm-restore="${esc(r.id)}" title="Sposta in bozze così torna visibile nei tuoi elenchi">↩ Ripristina</button>`
                    : (href ? `<a class="fm-btn fm-btn--xs fm-btn--ghost" href="${href}" target="_blank" rel="noopener">↗ Apri</a>` : '');
                return `
                    <div class="fm-ex-row">
                        <div class="fm-ex-meta">
                            <span class="fm-ex-badge">${icon} ${esc(r.content_type)}</span>
                            ${scope ? `<span class="fm-ex-badge">${esc(scope)}</span>` : ''}
                            ${r.topic ? `<span class="fm-ex-badge">topic: ${esc(r.topic)}</span>` : ''}
                            ${r.visibility && r.visibility !== 'published' ? `<span class="fm-ex-badge fm-ex-badge--diff">${esc(VIS_LABELS[r.visibility] || r.visibility)}</span>` : ''}
                        </div>
                        <div class="fm-ex-row__head">
                            <div class="fm-ex-title">${title}</div>
                            ${actions}
                        </div>
                        <div class="fm-ex-meta">id #${esc(r.id)} · aggiornato ${esc((r.updated_at || '').slice(0,16))}</div>
                    </div>`;
            }).join('');
            exResults.innerHTML = `<p class="fm-muted fm-text-xs fm-mt-3 fm-mb-1" >${data.count} risultati</p>` + rows;

            // Bind Restore buttons (delegation locale post-render).
            exResults.querySelectorAll('[data-fm-restore]').forEach(btn => {
                btn.addEventListener('click', () => restoreRow(btn.dataset.fmRestore, btn));
            });
        };

        exForm.addEventListener('submit', async (ev) => {
            // G22.S25 — Salvataggio esplicito anche su submit (in aggiunta a
            // change/input): garantisce persistenza dei filtri usati per
            // l'ultima ricerca anche se l'utente non triggera un change.
            saveState();
            ev?.preventDefault?.();
            const fd = new FormData(exForm);
            const params = new URLSearchParams();
            const wantArchived = fd.get('archived');
            for (const [k, v] of fd.entries()) {
                if (k === 'archived') continue;
                if (v) params.set(k, v);
            }
            if (wantArchived) params.set('visibility', 'archived');
            params.set('limit', '50');
            exResults.innerHTML = '<p class="fm-muted fm-text-13 fm-mt-3 fm-mr-0 fm-mb-0 fm-ml-0" >Caricamento…</p>';
            try {
                const res = await fetch('/api/teacher/content?' + params.toString(), { credentials: 'same-origin' });
                render(await res.json());
            } catch (e) {
                render({ ok: false, error: e.message });
            }
        });
    }

    // Deep-link: legge l'hash all'avvio (es. #sicurezza). G22.S25 — strumenti
    // rimosso (fuso in panoramica); legacy #strumenti → panoramica per
    // bookmark/email residui. Default = sync (prima tab, più "task-oriented").
    const VALID = ['panoramica','pool','sync','sicurezza'];
    let initial = location.hash.replace('#','');
    if (initial === 'strumenti') initial = 'panoramica';
    activate(VALID.includes(initial) ? initial : 'sync');
})();

// G22.S22 — Rimossa sezione "Le mie verifiche TeX" (endpoint
// /teacher/verifiche.json dead-path post M11 cleanup, sostituito
// da /api/teacher/content?type=verifica).

// Phase G1.a — Drive status pill (read-only stato + connect/disconnect).
(async function() {
    const pill = document.getElementById('fm-drive-status');
    if (!pill) return;
    const label   = pill.querySelector('.fm-drive-label');
    const actions = pill.querySelector('.fm-drive-actions');

    // Toast feedback se rientriamo dal callback OAuth.
    const params = new URLSearchParams(location.search);
    const driveFlag = params.get('drive');
    if (driveFlag) {
        const msg = {
            connected: '✅ Drive collegato con successo.',
            denied:    '⚠️ Hai negato il consenso. Drive non collegato.',
            error:     '❌ Errore durante la connessione a Drive.',
        }[driveFlag];
        if (msg) {
            const banner = document.createElement('div');
            banner.className = 'fm-alert fm-alert--info';
            banner.style.marginTop = 'var(--fm-space-4)';
            banner.textContent = msg;
            pill.parentElement.insertBefore(banner, pill);
            params.delete('drive');
            const clean = location.pathname + (params.toString() ? '?' + params.toString() : '');
            history.replaceState({}, '', clean);
        }
    }

    const csrfToken = csrf; // alias storico → helper centralizzato

    function setState(state, text) {
        pill.dataset.state = state;
        label.textContent = text;
    }

    function renderActions(connected) {
        actions.innerHTML = '';
        if (connected) {
            const btn = document.createElement('button');
            btn.className = 'fm-btn fm-btn--ghost fm-btn--sm';
            btn.textContent = 'Disconnetti';
            btn.addEventListener('click', async () => {
                if (!(await window.FM.Dialog.confirm('Disconnettere il tuo account Google Drive?').catch(() => false))) return;
                btn.disabled = true;
                const token = await csrfToken();
                await fetch('/teacher/drive/disconnect', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
                });
                load();
            });
            actions.appendChild(btn);
        } else {
            const a = document.createElement('a');
            a.className = 'fm-btn fm-btn--sm';
            a.href = '/teacher/drive/connect';
            a.textContent = 'Collega Drive';
            actions.appendChild(a);
        }
    }

    async function load() {
        try {
            const res = await fetch('/teacher/drive/status.json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const ct = res.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                setState('error', `Risposta non JSON (HTTP ${res.status}). Sessione scaduta?`);
                return;
            }
            const data = await res.json();
            if (!data.ok) {
                setState('error', 'Errore stato: ' + (data.error || 'unknown'));
                return;
            }
            if (data.connected) {
                const email = data.email ? ` (${data.email})` : '';
                const last  = data.last_sync_at ? ` · ultima sync ${data.last_sync_at}` : ' · mai sincronizzato';
                setState('connected', `Connesso${email}${last}`);
            } else {
                setState('disconnected', 'Non collegato.');
            }
            renderActions(!!data.connected);
        } catch (e) {
            setState('error', 'Errore: ' + e.message);
        }
    }
    load();
})();

// G22.S15.bis Fase 5 — Cartella locale (FS Access API): mostra nome
// cartella corrente se pairata + bottone per cambiarla. Lo store del
// handle è gestito da window.FM.FsAccess (modulo fs-access.js).
(function() {
    const pill   = document.getElementById('fm-local-folder');
    if (!pill) return;
    const label  = pill.querySelector('.fm-drive-label');
    const pickBtn = document.getElementById('fm-local-folder-pick');
    const clearBtn = document.getElementById('fm-local-folder-clear');

    function setLabel(name, state) {
        pill.dataset.state = state;
        label.textContent = name;
        clearBtn.hidden = state !== 'connected';
    }

    async function refresh() {
        const fs = window.FM && window.FM.FsAccess;
        if (!fs || typeof fs.isSupported !== 'function') {
            setLabel('Funzionalità non disponibile in questo browser. Usa Chrome o Edge desktop.', 'error');
            pickBtn.disabled = true;
            return;
        }
        if (!fs.isSupported()) {
            setLabel('File System Access API non supportata. Usa Chrome o Edge desktop.', 'error');
            pickBtn.disabled = true;
            return;
        }
        try {
            const root = await fs.getRoot();
            if (!root) {
                setLabel('Nessuna cartella selezionata. Clicca "Scegli/cambia cartella" per configurarla.', 'idle');
                return;
            }
            const ok = await fs.getOrRequestPermission(root, 'read');
            if (!ok) {
                setLabel('Permesso negato. Riconfigura la cartella per autorizzare.', 'error');
                return;
            }
            setLabel('Cartella collegata: ' + (root.name || '(senza nome)'), 'connected');
        } catch (e) {
            setLabel('Errore: ' + e.message, 'error');
        }
    }

    pickBtn?.addEventListener('click', async () => {
        const fs = window.FM && window.FM.FsAccess;
        if (!fs || !fs.isSupported || !fs.isSupported()) return;
        try {
            await fs.pickRoot();
            await refresh();
            alert('✅ Cartella locale aggiornata.');
        } catch (e) {
            if (e?.name !== 'AbortError') {
                alert('Errore cartella locale: ' + (e.message || 'Errore'));
            }
        }
    });

    clearBtn?.addEventListener('click', async () => {
        const fs = window.FM && window.FM.FsAccess;
        if (!fs?.clearRoot) return;
        if (!(await window.FM.Dialog.confirm('Rimuovere la cartella locale? Dovrai sceglierne una nuova al prossimo sync.').catch(() => false))) return;
        await fs.clearRoot();
        await refresh();
    });

    // refresh quando il modulo FS Access carica (può essere lazy)
    window.addEventListener('fm:fs-access-ready', refresh);
    refresh();
})();

// G22.S15.bis Fase 5 — GitHub sync configurazione (PAT + repo).
(function() {
    const pill = document.getElementById('fm-github-status');
    if (!pill) return;
    const label  = pill.querySelector('.fm-drive-label');
    const cfgBtn = document.getElementById('fm-github-configure');
    const disBtn = document.getElementById('fm-github-disconnect');


    async function refresh() {
        try {
            const r = await fetch('/api/teacher/github/status', { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) {
                pill.dataset.state = 'error';
                label.textContent = 'Errore: ' + (j.error || 'unknown');
                return;
            }
            if (!j.configured) {
                pill.dataset.state = 'idle';
                label.textContent = 'Non configurato. Clicca "Configura" per collegare un repo.';
                disBtn.hidden = true;
                cfgBtn.textContent = 'Configura';
                return;
            }
            const c = j.config;
            const last = c.last_sync_at
                ? `· ultima sync ${c.last_sync_at}`
                : '· mai sincronizzato';
            // Audit 25.R.31 — escape dei valori server-derivati prima dell'innerHTML
            // (repo_owner/name/branch/last_error sono editabili dal docente → XSS).
            const _e = s => String(s ?? '').replace(/[&<>"']/g, c => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const errPart = c.last_error
                ? ` · ⚠ ${_e(c.last_error)}`
                : '';
            pill.dataset.state = c.last_error ? 'error' : 'connected';
            label.innerHTML = `Connesso → <strong>${_e(c.repo_owner)}/${_e(c.repo_name)}</strong> (${_e(c.branch)}) ${_e(last)}${errPart}`;
            disBtn.hidden = false;
            cfgBtn.textContent = 'Riconfigura';
        } catch (e) {
            pill.dataset.state = 'error';
            label.textContent = 'Errore: ' + e.message;
        }
    }

    cfgBtn?.addEventListener('click', async () => {
        const D = window.FM.Dialog;
        const owner = await D.prompt('GitHub: owner del repo (es. il tuo username)?').catch(() => null);
        if (!owner) return;
        const repo = await D.prompt('GitHub: nome del repo (es. pantedu-backup)?').catch(() => null);
        if (!repo) return;
        const branch = (await D.prompt('GitHub: branch (default: main)?', 'main').catch(() => null)) || 'main';
        // Audit 25.R.31 (L10d) — PAT in input mascherato (type=password) invece di
        // prompt() in chiaro (shoulder-surf / visibile a schermo).
        const pat = await D.prompt('GitHub: incolla il Personal Access Token (github_pat_...). NON sarà mai visualizzato di nuovo.', '', { type: 'password' }).catch(() => null);
        if (!pat) return;
        // G22.S15.bis Fase 5 — visual feedback durante 10-20s di GitHub API
        // roundtrip (validate PAT + push README). Senza, l'utente vedeva
        // solo "click handler took 20s" violation in console.
        const cfgBtnLabel = cfgBtn.textContent;
        cfgBtn.disabled = true;
        cfgBtn.textContent = '⏳ Configuro…';
        pill.dataset.state = 'loading';
        label.textContent = 'Validazione PAT + smoke test push (può durare 10-20s)…';
        try {
            const token = await csrf();
            const r = await fetch('/api/teacher/github/configure', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ pat, owner, repo, branch, _csrf: token }),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) {
                alert('Errore configurazione: ' + (j.error || r.status));
                return;
            }
            alert('✅ GitHub configurato. Test smoke: invio README.md…');
            // Smoke test
            const t2 = await fetch('/api/teacher/github/sync-test', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ _csrf: token }),
            });
            const j2 = await t2.json();
            // G22.S15.bis Fase 5 — la dashboard NON carica jQuery: ToastManager
            // (che usa window.jQuery) crasha. Usiamo alert nativo.
            if (j2.ok) {
                alert('✅ GitHub: README.md ' + j2.action + ' (smoke test OK)');
            } else {
                alert('⚠ Smoke test fallito: ' + (j2.error || 'unknown'));
            }
            await refresh();
        } catch (e) {
            alert('Errore di rete: ' + e.message);
        } finally {
            cfgBtn.disabled = false;
            cfgBtn.textContent = cfgBtnLabel;
        }
    });

    disBtn?.addEventListener('click', async () => {
        if (!(await window.FM.Dialog.confirm('Disconnettere GitHub? Il PAT verrà cancellato dal database. Il repo non verrà toccato.').catch(() => false))) return;
        try {
            const token = await csrf();
            await fetch('/api/teacher/github/disconnect', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token },
            });
            await refresh();
        } catch (e) {
            alert('Errore: ' + e.message);
        }
    });

    refresh();
})();

// G22.S20 — Recovery Key (Modalità A): genera/revoca + show-once modal.
(function() {
    const pill = document.getElementById('fm-recovery-status');
    if (!pill) return;
    const label = pill.querySelector('.fm-drive-label');
    const genBtn = document.getElementById('fm-recovery-generate');
    const rotBtn = document.getElementById('fm-recovery-rotate');
    const revBtn = document.getElementById('fm-recovery-revoke');


    async function refresh() {
        try {
            const r = await fetch('/api/teacher/recovery-key/status', { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) {
                pill.dataset.state = 'error';
                label.textContent = 'Errore: ' + (j.error || 'unknown');
                return;
            }
            const s = j.status;
            if (!s.exists || s.revoked_at) {
                pill.dataset.state = 'idle';
                label.textContent = s.revoked_at
                    ? 'Recovery Key revocata. Genera una nuova chiave.'
                    : 'Nessuna Recovery Key. Genera ora per abilitare l\'export/import firmato.';
                genBtn.hidden = false;
                genBtn.textContent = s.revoked_at ? 'Genera nuova' : 'Genera';
                rotBtn.hidden = true;
                revBtn.hidden = true;
                return;
            }
            pill.dataset.state = 'connected';
            const created = s.created_at ? new Date(s.created_at).toLocaleString() : '?';
            label.innerHTML = `Recovery Key attiva (creata <strong>${created}</strong>, scaricata <strong>${s.download_count}×</strong>)`;
            genBtn.hidden = true;
            rotBtn.hidden = false;
            revBtn.hidden = false;
        } catch (e) {
            pill.dataset.state = 'error';
            label.textContent = 'Errore: ' + e.message;
        }
    }

    let cachedUsername = '';
    fetch('/auth/user-info', { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : null)
        .then(j => { cachedUsername = j?.username || j?.user?.username || ''; })
        .catch(() => {});

    function showRecoveryModal(hex, b32) {
        const m = document.createElement('div');
        m.className = 'fm-import-modal';
        // Safety net: forza position fixed via inline style nel caso il CSS
        // .fm-import-modal non sia ancora caricato (cache browser stale).
        m.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;';
        m.innerHTML = `
            <div class="fm-import-backdrop"></div>
            <div class="fm-import-dialog" role="dialog" aria-modal="true">
                <h2>🔐 Recovery Key generata</h2>
                <p><strong>⚠ ATTENZIONE</strong>: questo codice viene mostrato <em>una sola volta</em>.
                Salvalo subito in un gestore password o stampalo. Senza, non potrai re-importare
                bundle scaricati su altri account/server.</p>
                <label><strong>Codice esadecimale (64 char):</strong>
                    <textarea readonly rows="3" onclick="this.select()"
                        class="fm-rk-code">${hex}</textarea>
                </label>
                <label><strong>Codice base32 (52 char, più facile da digitare):</strong>
                    <textarea readonly rows="2" onclick="this.select()"
                        class="fm-rk-code">${b32}</textarea>
                </label>
                <div class="fm-mt-2">
                    <button type="button" class="fm-import-pick" data-act="copy-hex">📋 Copia hex</button>
                    <button type="button" class="fm-import-pick" data-act="copy-b32">📋 Copia base32</button>
                    <button type="button" class="fm-import-pick" data-act="download">💾 Scarica .txt</button>
                </div>
                <p class="fm-mt-4"><label>
                    <input type="checkbox" class="fm-rk-confirm">
                    Ho salvato il codice in un luogo sicuro e ne accetto la responsabilità
                </label></p>
                <div class="fm-import-actions">
                    <button type="button" class="fm-import-next" disabled>Chiudi</button>
                </div>
            </div>
        `;
        document.body.appendChild(m);
        const close = () => m.remove();
        const closeBtn = m.querySelector('.fm-import-next');
        const cb = m.querySelector('.fm-rk-confirm');
        cb.addEventListener('change', () => { closeBtn.disabled = !cb.checked; });
        closeBtn.addEventListener('click', () => close());
        m.querySelector('.fm-import-backdrop').addEventListener('click', () => {
            if (cb.checked) close();
        });
        m.addEventListener('click', e => {
            const act = e.target?.dataset?.act;
            if (!act) return;
            if (act === 'copy-hex') navigator.clipboard.writeText(hex);
            else if (act === 'copy-b32') navigator.clipboard.writeText(b32);
            else if (act === 'download') {
                const username = cachedUsername
                    || (document.querySelector('.fm-session-banner--teacher .fm-session-user strong')?.textContent || '').trim()
                    || (document.querySelector('.fm-session-user strong')?.textContent || '').trim()
                    || '';
                const blob = new Blob([
                    `Pantedu — Recovery Key\nUtente: ${username}\nData: ${new Date().toISOString()}\n\nHEX (64 char):\n${hex}\n\nBASE32 (52 char):\n${b32}\n\nCONSERVARE IN CASSAFORTE.\nSenza questa chiave non sarà possibile re-importare i bundle.\n`,
                ], { type: 'text/plain' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'pantedu-recovery-key.txt';
                a.click();
                URL.revokeObjectURL(a.href);
            }
        });
    }

    genBtn?.addEventListener('click', async () => {
        if (!confirmGenerate()) return;
        genBtn.disabled = true;
        genBtn.textContent = '⏳ Genero…';
        try {
            const tok = await csrf();
            const r = await fetch('/api/teacher/recovery-key/generate', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ _csrf: tok }),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) {
                window.FM?.SyncPanel?.notify?.('Recovery Key', 'error', j.error || r.status, 5000);
                return;
            }
            showRecoveryModal(j.recovery_hex, j.recovery_b32);
            await refresh();
        } catch (e) {
            window.FM?.SyncPanel?.notify?.('Recovery Key', 'error', e.message, 5000);
        } finally {
            genBtn.disabled = false;
            genBtn.textContent = 'Genera';
        }
    });

    revBtn?.addEventListener('click', async () => {
        if (!confirmRevoke()) return;
        try {
            const tok = await csrf();
            const r = await fetch('/api/teacher/recovery-key/revoke', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ _csrf: tok }),
            });
            const j = await r.json();
            if (j.ok) await refresh();
        } catch (e) {
            window.FM?.SyncPanel?.notify?.('Recovery Key', 'error', e.message, 5000);
        }
    });

    // G22.S20 — Rigenera = revoca + genera atomico, mostra modal con nuovo codice
    rotBtn?.addEventListener('click', async () => {
        if (!confirmRotate()) return;
        rotBtn.disabled = true;
        const orig = rotBtn.textContent;
        rotBtn.textContent = '⏳ Rigenero…';
        try {
            const tok = await csrf();
            // Step 1: revoca
            const r1 = await fetch('/api/teacher/recovery-key/revoke', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ _csrf: tok }),
            });
            const j1 = await r1.json();
            if (!j1.ok) {
                window.FM?.SyncPanel?.notify?.('Recovery Key', 'error',
                    'Revoca fallita: ' + (j1.error || r1.status), 5000);
                return;
            }
            // Step 2: genera (riusa stesso token CSRF)
            const r2 = await fetch('/api/teacher/recovery-key/generate', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ _csrf: tok }),
            });
            const j2 = await r2.json();
            if (!r2.ok || !j2.ok) {
                window.FM?.SyncPanel?.notify?.('Recovery Key', 'error',
                    'Genera fallita: ' + (j2.error || r2.status), 5000);
                return;
            }
            showRecoveryModal(j2.recovery_hex, j2.recovery_b32);
            await refresh();
        } catch (e) {
            window.FM?.SyncPanel?.notify?.('Recovery Key', 'error', e.message, 5000);
        } finally {
            rotBtn.disabled = false;
            rotBtn.textContent = orig;
        }
    });

    function confirmGenerate() {
        // Flusso 2-step inline su click successivo (no browser confirm).
        if (genBtn.dataset.armed === '1') {
            genBtn.dataset.armed = '';
            return true;
        }
        genBtn.dataset.armed = '1';
        const prev = genBtn.textContent;
        genBtn.textContent = '⚠ Conferma genera (mostrato 1 volta)';
        setTimeout(() => {
            if (genBtn.dataset.armed === '1') {
                genBtn.dataset.armed = '';
                genBtn.textContent = prev;
            }
        }, 5000);
        return false;
    }
    function confirmRevoke() {
        if (revBtn.dataset.armed === '1') {
            revBtn.dataset.armed = '';
            return true;
        }
        revBtn.dataset.armed = '1';
        const prev = revBtn.textContent;
        revBtn.textContent = '⚠ Conferma revoca';
        setTimeout(() => {
            if (revBtn.dataset.armed === '1') {
                revBtn.dataset.armed = '';
                revBtn.textContent = prev;
            }
        }, 5000);
        return false;
    }
    function confirmRotate() {
        if (rotBtn.dataset.armed === '1') {
            rotBtn.dataset.armed = '';
            return true;
        }
        rotBtn.dataset.armed = '1';
        const prev = rotBtn.textContent;
        rotBtn.textContent = '⚠ Conferma rigenera (vecchio codice non valido)';
        setTimeout(() => {
            if (rotBtn.dataset.armed === '1') {
                rotBtn.dataset.armed = '';
                rotBtn.textContent = prev;
            }
        }, 5000);
        return false;
    }

    refresh();
})();

// G22.S21 Fase D — Pool browse: lista materiali condivisi da colleghi.
(function() {
    const loadBtn = document.getElementById('fm-pool-load');
    const typeSel = document.getElementById('fm-pool-type');
    const subjInp = document.getElementById('fm-pool-subject');
    const listEl  = document.getElementById('fm-pool-list');
    if (!loadBtn) return;

    function typeIcon(t) {
        return { mappa:'🗺', esercizio:'📝', verifica:'📄', bes:'📋',
                 risdoc:'📑', didattica:'📚', lab:'🧪' }[t] || '📄';
    }

    async function loadList() {
        listEl.innerHTML = '<em>Caricamento…</em>';
        const params = new URLSearchParams();
        if (typeSel.value) params.set('content_type', typeSel.value);
        if (subjInp.value) params.set('subject_code', subjInp.value.toUpperCase());
        try {
            const r = await fetch('/api/teacher/pool/materials?' + params.toString(),
                                  { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) { listEl.innerHTML = '<em>Errore: ' + escHtml(j.error || 'unknown') + '</em>'; return; }
            const items = j.items || [];
            if (!items.length) {
                listEl.innerHTML = '<em>Nessun materiale condiviso disponibile per il filtro corrente.</em>';
                return;
            }
            listEl.innerHTML = renderTable(items);
            wireRecoverButtons();
        } catch (e) {
            listEl.innerHTML = '<em>Errore di rete: ' + escHtml(e.message) + '</em>';
        }
    }

    function renderTable(items) {
        const rows = items.map(it => {
            const recoveredBadge = it.already_recovered
                ? `<span title="Hai già recuperato questo elemento (#${it.my_recovered_id})" class="fm-pill-pool-recovered">✓ già recuperato</span>`
                : '';
            const recoverBtn = it.already_recovered
                ? `<button class="fm-btn fm-btn--xs" disabled title="Hai già una copia di questo elemento (#${it.my_recovered_id})">✓ Già nel tuo account</button>`
                : `<button class="fm-btn fm-btn--xs fm-btn--primary"
                            data-recover-id="${it.id}"
                            data-source-code="${escHtml(it.subject_code)}"
                            data-source-title="${escHtml(it.title)}"
                            title="Copia nel mio account dentro una mia materia">
                        ⬇ Recupera
                    </button>`;
            return `
            <tr data-source-id="${it.id}" class="${it.already_recovered ? 'fm-opacity-75' : ''}">
                <td>${typeIcon(it.content_type)} ${escHtml(it.content_type)}</td>
                <td><strong>${escHtml(it.title)}</strong>
                    ${it.topic ? `<br><span class="fm-muted fm-text-11" >${escHtml(it.topic)}</span>` : ''}
                    ${recoveredBadge ? `<br>${recoveredBadge}` : ''}
                </td>
                <td><span class="fm-mono-muted">${escHtml(it.subject_code)}</span>
                    <br><span class="fm-muted fm-text-11" >${escHtml(it.subject_label)}</span></td>
                <td>${escHtml(it.owner_name)}</td>
                <td class="fm-text-right">${recoverBtn}</td>
            </tr>`;
        }).join('');
        return `
            <table class="fm-data-table fm-mt-2">
                <thead><tr>
                    <th>Tipo</th><th>Titolo</th><th>Materia</th><th>Docente</th><th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <div id="fm-pool-feedback" class="fm-muted fm-text-xs fm-mt-2" ></div>
        `;
    }

    let myMateriePromise = null;
    async function myMaterieList() {
        if (myMateriePromise) return myMateriePromise;
        myMateriePromise = (async () => {
            // G22.S22 — scope=all per cross-institute (multi-istituto teacher).
            const r = await fetch('/api/teacher/curriculum?scope=all',
                                  { credentials: 'same-origin' });
            const j = await r.json();
            const materie = j?.curriculum?.materie || [];
            return materie.filter(m => m.owner_user_id);
        })();
        return myMateriePromise;
    }

    // G22.S22 — Cache catalog indirizzi/classi per institute_id (la materia
    // target determina l'istituto, quindi diversi institute_id richiedono
    // catalog diversi).
    const instCatalogCache = new Map();
    async function instituteCatalog(instituteId) {
        if (instCatalogCache.has(instituteId)) return instCatalogCache.get(instituteId);
        const p = (async () => {
            const r = await fetch('/api/teacher/curriculum?institute_id=' + encodeURIComponent(instituteId),
                                  { credentials: 'same-origin' });
            const j = await r.json();
            return {
                indirizzi: (j?.curriculum?.indirizzi || []).filter(e => e.active),
                classi:    (j?.curriculum?.classi    || []).filter(e => e.active),
            };
        })();
        instCatalogCache.set(instituteId, p);
        return p;
    }

    function wireRecoverButtons() {
        listEl.querySelectorAll('[data-recover-id]').forEach(btn => {
            btn.addEventListener('click', () => onRecoverClick(btn));
        });
    }

    async function onRecoverClick(btn) {
        const sourceId = parseInt(btn.dataset.recoverId, 10);
        const sourceCode = btn.dataset.sourceCode || '';
        const fb = document.getElementById('fm-pool-feedback');
        if (!fb) return;
        fb.innerHTML = 'Carico le tue materie…';
        let materie;
        try {
            materie = await myMaterieList();
        } catch (e) {
            fb.innerHTML = '<em>Errore caricamento materie: ' + escHtml(e.message) + '</em>';
            return;
        }
        if (!materie.length) {
            fb.innerHTML = '<em>Non hai ancora materie tue. Aggiungine una dal Profilo prima di recuperare.</em>';
            return;
        }
        // Pre-seleziona materia con stesso code se esiste
        const pre = materie.find(m => m.code === sourceCode);
        const opts = materie.map(m =>
            `<option value="${m.id}" data-inst="${m.institute_id || ''}"${m === pre ? ' selected' : ''}>${escHtml(m.label)} (${escHtml(m.code)})</option>`
        ).join('');
        fb.innerHTML = `
            <div class="fm-info-note">
                <div class="fm-d-flex fm-items-center fm-gap-2 fm-flex-wrap">
                    <span><strong>Copia in materia:</strong></span>
                    <select id="fm-pool-target" class="fm-input fm-input--sm">${opts}</select>
                </div>
                <div class="fm-d-flex fm-items-center fm-gap-2 fm-flex-wrap">
                    <span>Indirizzo:</span>
                    <select id="fm-pool-target-ind" class="fm-input fm-input--sm"><option value="">— nessuno —</option></select>
                    <span class="fm-ml-2">Classe:</span>
                    <select id="fm-pool-target-cls" class="fm-input fm-input--sm"><option value="">— nessuna —</option></select>
                </div>
                <p class="fm-muted fm-text-11 fm-m-0 fm-lh-base" >
                    Senza indirizzo/classe il contenuto resta in dashboard ma non appare nei select del sito quando filtri per indirizzo/classe specifici.
                </p>
                <div class="fm-d-flex fm-gap-2 fm-items-center">
                    <button id="fm-pool-confirm" class="fm-btn fm-btn--sm fm-btn--primary">✓ Conferma copia</button>
                    <button id="fm-pool-cancel" class="fm-btn fm-btn--sm fm-btn--ghost">Annulla</button>
                </div>
            </div>`;
        const indSel = document.getElementById('fm-pool-target-ind');
        const clsSel = document.getElementById('fm-pool-target-cls');
        const matSel = document.getElementById('fm-pool-target');

        async function repopulateIndCls() {
            const opt = matSel.options[matSel.selectedIndex];
            const inst = parseInt(opt?.dataset?.inst || '0', 10);
            if (!inst) {
                indSel.innerHTML = '<option value="">— nessuno —</option>';
                clsSel.innerHTML = '<option value="">— nessuna —</option>';
                return;
            }
            try {
                const cat = await instituteCatalog(inst);
                indSel.innerHTML = '<option value="">— nessuno —</option>' +
                    cat.indirizzi.map(e => `<option value="${e.id}">${escHtml(e.label)} (${escHtml(e.code)})</option>`).join('');
                clsSel.innerHTML = '<option value="">— nessuna —</option>' +
                    cat.classi.map(e => `<option value="${e.id}">${escHtml(e.label)} (${escHtml(e.code)})</option>`).join('');
            } catch (e) {
                indSel.innerHTML = '<option value="">— errore —</option>';
                clsSel.innerHTML = '<option value="">— errore —</option>';
            }
        }
        matSel.addEventListener('change', repopulateIndCls);
        repopulateIndCls();

        document.getElementById('fm-pool-cancel').addEventListener('click', () => { fb.innerHTML = ''; });
        document.getElementById('fm-pool-confirm').addEventListener('click', async () => {
            const target = parseInt(matSel.value, 10);
            if (!target) { fb.innerHTML = '<em>Materia non valida.</em>'; return; }
            const targetInd = parseInt(indSel.value, 10) || 0;
            const targetCls = parseInt(clsSel.value, 10) || 0;
            fb.innerHTML = 'Recupero in corso…';
            try {
                const tok = await csrf();
                const r = await fetch('/api/teacher/pool/recover/' + sourceId, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                    body: JSON.stringify({
                        target_subject_id: target,
                        target_indirizzo_id: targetInd || undefined,
                        target_classe_id:    targetCls || undefined,
                        _csrf: tok,
                    }),
                });
                const j = await r.json();
                if (!j.ok) { fb.innerHTML = '<em>Errore: ' + escHtml(j.error || 'unknown') + (j.detail ? ' — ' + escHtml(j.detail) : '') + '</em>'; return; }
                let msg = `✅ Copiato come #${j.new_id} (tipo ${escHtml(j.content_type)}).`;
                if (j.verifica_blob_skipped) {
                    msg += ' <strong>Nota</strong>: per le verifiche solo i metadati sono stati clonati; i file .tex restano dell\'originale. Usa export bundle per i file completi.';
                }
                fb.innerHTML = msg;
            } catch (e) {
                fb.innerHTML = '<em>Errore di rete: ' + escHtml(e.message) + '</em>';
            }
        });
    }

    loadBtn.addEventListener('click', loadList);
})();

// G22.S24 — "I miei contenuti condivisi": elenco + bulk unshare.
(function() {
    const loadBtn   = document.getElementById('fm-my-shares-load');
    const selectAll = document.getElementById('fm-my-shares-select-all');
    const unshareBtn = document.getElementById('fm-my-shares-unshare');
    const selCount  = document.getElementById('fm-my-shares-selected');
    const countEl   = document.getElementById('fm-my-shares-count');
    const listEl    = document.getElementById('fm-my-shares-list');
    if (!loadBtn) return;

    function typeIcon(t) {
        return { mappa:'🗺', esercizio:'📝', verifica:'📋', verifica_doc:'📄',
                 bes:'📋', risdoc:'📑', didattica:'📚', lab:'🧪' }[t] || '📄';
    }

    function updateSelectionCount() {
        const checked = listEl.querySelectorAll('input[type=checkbox][data-share-id]:checked');
        selCount.textContent = String(checked.length);
        unshareBtn.disabled = checked.length === 0;
    }

    async function load() {
        listEl.innerHTML = '<em>Caricamento…</em>';
        countEl.textContent = '';
        try {
            const r = await fetch('/api/teacher/pool/my-shares', { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) { listEl.innerHTML = '<em>Errore: ' + escHtml(j.error || '?') + '</em>'; return; }
            countEl.textContent = '(' + j.count + ')';
            if (j.count === 0) {
                listEl.innerHTML = '<em>Non hai ancora condiviso nulla. Apri un esercizio/verifica/mappa e attiva il toggle 🤝.</em>';
                return;
            }
            const rows = (j.items || []).map(it => {
                const flags = [];
                if (it.shared_with_pool) flags.push('<span title="Tutto l\'istituto" class="fm-pill-pool-success">🤝 istituto</span>');
                if (it.grants_count > 0) flags.push(`<span title="${it.grants_count} grant espliciti" class="fm-pill-pool-grants">🎯 ${it.grants_count}</span>`);
                return `
                <tr>
                    <td><input type="checkbox" data-share-id="${it.id}" data-share-source="${escHtml(it.source)}"></td>
                    <td>${typeIcon(it.content_type)} ${escHtml(it.content_type)}</td>
                    <td><strong>${escHtml(it.title)}</strong>
                        ${it.topic ? `<br><span class="fm-muted fm-text-11" >${escHtml(it.topic)}</span>` : ''}
                    </td>
                    <td><span class="fm-mono-muted">${escHtml(it.subject_code || '—')}</span></td>
                    <td><span class="fm-muted fm-text-11" >${escHtml(it.institute_name || '—')}</span></td>
                    <td class="fm-ws-nowrap">${flags.join(' ')}</td>
                    <td class="fm-text-right">
                        <button class="fm-btn fm-btn--xs"
                                data-grants-source="${escHtml(it.source)}"
                                data-grants-id="${it.id}"
                                data-grants-title="${escHtml(it.title)}"
                                title="Condivisione avanzata">🎯</button>
                    </td>
                </tr>`;
            }).join('');
            listEl.innerHTML = `
                <table class="fm-data-table fm-mt-2">
                    <thead><tr><th class="fm-w-7-5"></th><th>Tipo</th><th>Titolo</th><th>Materia</th><th>Istituto</th><th>Visibilità</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
            // Wire grants buttons → popup
            listEl.querySelectorAll('[data-grants-id]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const src = btn.dataset.grantsSource;
                    const id = parseInt(btn.dataset.grantsId, 10);
                    const title = btn.dataset.grantsTitle || '';
                    try {
                        const mod = await import('/js/modules/features/share-grants-popup.js');
                        mod.openShareGrantsPopup({ source: src, id, title });
                    } catch (e) {
                        window.FM?.SyncPanel?.notify?.('Pool', 'error', 'Errore: ' + e.message, 4000);
                    }
                });
            });
            listEl.querySelectorAll('input[type=checkbox][data-share-id]').forEach(cb => {
                cb.addEventListener('change', updateSelectionCount);
            });
            updateSelectionCount();
        } catch (e) {
            listEl.innerHTML = '<em>Errore di rete: ' + escHtml(e.message) + '</em>';
        }
    }

    selectAll.addEventListener('click', () => {
        const cbs = listEl.querySelectorAll('input[type=checkbox][data-share-id]');
        const allChecked = [...cbs].every(c => c.checked);
        cbs.forEach(c => c.checked = !allChecked);
        updateSelectionCount();
    });

    unshareBtn.addEventListener('click', async () => {
        const cbs = listEl.querySelectorAll('input[type=checkbox][data-share-id]:checked');
        if (!cbs.length) return;
        const items = [...cbs].map(c => ({
            source: c.dataset.shareSource,
            id: parseInt(c.dataset.shareId, 10),
        }));
        if (!(await window.FM.Dialog.confirm(`Rimuovere ${items.length} contenuti dal pool? I colleghi non li vedranno più.\n\nI contenuti restano nel tuo account.`).catch(() => false))) return;
        unshareBtn.disabled = true;
        try {
            const tok = await csrf();
            const r = await fetch('/api/teacher/pool/unshare', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ items, _csrf: tok }),
            });
            const j = await r.json();
            if (!j.ok) {
                window.FM?.SyncPanel?.notify?.('Pool', 'error',
                    'Errore: ' + (j.error || 'unknown'), 4000);
                return;
            }
            window.FM?.SyncPanel?.notify?.('Pool', 'ok',
                `✓ Rimossi ${j.total} contenuti dal pool`, 3000);
            await load();
        } catch (e) {
            window.FM?.SyncPanel?.notify?.('Pool', 'error',
                'Errore di rete: ' + e.message, 4000);
        } finally {
            unshareBtn.disabled = false;
        }
    });

    loadBtn.addEventListener('click', load);
})();

// G22.S25 — Gruppi di condivisione: CRUD + management membri (spostato da profilo).
(async function() {
    const listEl   = document.getElementById('fm-sg-list');
    const nameInp  = document.getElementById('fm-sg-name');
    const descInp  = document.getElementById('fm-sg-desc');
    const createBtn = document.getElementById('fm-sg-create');
    if (!listEl) return;

    function notify(t, k, m, ms) { window.FM?.SyncPanel?.notify?.(t, k, m, ms); }

    let colleagues = [];
    async function loadColleagues() {
        if (colleagues.length) return colleagues;
        try {
            const r = await fetch('/api/teacher/share/colleagues', { credentials: 'same-origin' });
            const j = await r.json();
            if (j.ok) colleagues = j.colleagues || [];
        } catch (_) {}
        return colleagues;
    }

    async function loadGroups() {
        listEl.innerHTML = '<em>Caricamento…</em>';
        try {
            const r = await fetch('/api/teacher/share/groups', { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) { listEl.innerHTML = `<em>Errore: ${escHtml(j.error || '?')}</em>`; return; }
            if (!(j.groups || []).length) {
                listEl.innerHTML = '<em>Nessun gruppo. Creane uno qui sopra.</em>';
                return;
            }
            listEl.innerHTML = `
                <table class="fm-data-table">
                    <thead><tr><th>Nome</th><th>Descrizione</th><th class="fm-w-20 fm-text-center">Membri</th><th></th></tr></thead>
                    <tbody>
                    ${j.groups.map(g => `
                        <tr data-group-id="${g.id}">
                            <td><strong>${escHtml(g.name)}</strong></td>
                            <td><span class="fm-muted fm-text-xs" >${escHtml(g.description || '—')}</span></td>
                            <td class="fm-text-center">${g.members_count}</td>
                            <td class="fm-text-right fm-ws-nowrap">
                                <button class="fm-btn fm-btn--xs" data-sg-edit="${g.id}" data-sg-name="${escHtml(g.name)}">✎ Membri</button>
                                <button class="fm-btn fm-btn--xs fm-btn--danger" data-sg-del="${g.id}" data-sg-name="${escHtml(g.name)}">🗑</button>
                            </td>
                        </tr>`).join('')}
                    </tbody>
                </table>`;
            wireRows();
        } catch (e) {
            listEl.innerHTML = `<em>Errore rete: ${escHtml(e.message)}</em>`;
        }
    }

    function wireRows() {
        listEl.querySelectorAll('[data-sg-del]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.dataset.sgDel, 10);
                if (!(await window.FM.Dialog.confirm(`Eliminare gruppo "${btn.dataset.sgName}"? I grants verso questo gruppo verranno rimossi.`).catch(() => false))) return;
                const tok = await csrf();
                const r = await fetch(`/api/teacher/share/groups/${id}/delete`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': tok, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ _csrf: tok }),
                });
                const j = await r.json();
                if (j.ok) { notify('Gruppi', 'ok', '✓ Eliminato', 2000); await loadGroups(); }
                else notify('Gruppi', 'error', 'Errore: ' + (j.error || '?'), 0);
            });
        });
        listEl.querySelectorAll('[data-sg-edit]').forEach(btn => {
            btn.addEventListener('click', () => openMembers(parseInt(btn.dataset.sgEdit, 10), btn.dataset.sgName));
        });
    }

    async function openMembers(groupId, groupName) {
        await loadColleagues();
        let currentMembers = new Set();
        try {
            const r = await fetch(`/api/teacher/share/groups/${groupId}/members`, { credentials: 'same-origin' });
            const j = await r.json();
            if (j.ok) currentMembers = new Set((j.members || []).map(m => m.id));
        } catch (_) {}
        const backdrop = document.createElement('div');
        backdrop.className = 'fm-modal-backdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
        backdrop.innerHTML = `
            <div class="fm-modal-dark">
                <h3 class="fm-m-0 fm-mb-3">Membri di "${escHtml(groupName)}"</h3>
                <p class="fm-muted fm-text-xs fm-m-0 fm-mb-2" >Spunta i colleghi membri del gruppo. Salva sostituisce l'intero set.</p>
                <input type="search" id="fm-sg-mem-filter" placeholder="Cerca docente…" class="fm-input fm-input--sm fm-w-full fm-mb-2" >
                <div id="fm-sg-mem-list" class="fm-scroll-panel fm-p-1">
                    ${colleagues.map(t => `
                        <label class="fm-d-flex fm-gap-1 fm-items-center fm-p-1 fm-cursor-pointer">
                            <input type="checkbox" data-uid="${t.id}" ${currentMembers.has(t.id) ? 'checked' : ''}>
                            <span>${escHtml(t.display_name)} <span class="fm-muted fm-text-10" >(${escHtml(t.username)})</span></span>
                        </label>`).join('')}
                </div>
                <div class="fm-d-flex fm-gap-2 fm-justify-end fm-mt-3">
                    <button class="fm-btn fm-btn--sm" id="fm-sg-mem-cancel">Annulla</button>
                    <button class="fm-btn fm-btn--sm fm-btn--primary" id="fm-sg-mem-save">💾 Salva</button>
                </div>
            </div>`;
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) backdrop.remove(); });
        backdrop.querySelector('#fm-sg-mem-cancel').addEventListener('click', () => backdrop.remove());
        const filter = backdrop.querySelector('#fm-sg-mem-filter');
        const memListEl = backdrop.querySelector('#fm-sg-mem-list');
        filter.addEventListener('input', () => {
            const q = filter.value.toLowerCase().trim();
            memListEl.querySelectorAll('label').forEach(lbl => {
                lbl.style.display = lbl.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
        backdrop.querySelector('#fm-sg-mem-save').addEventListener('click', async () => {
            const ids = [...backdrop.querySelectorAll('input[type=checkbox][data-uid]:checked')]
                .map(c => parseInt(c.dataset.uid, 10));
            const tok = await csrf();
            const r = await fetch(`/api/teacher/share/groups/${groupId}/members`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': tok, 'Content-Type': 'application/json' },
                body: JSON.stringify({ member_ids: ids, _csrf: tok }),
            });
            const j = await r.json();
            if (j.ok) {
                notify('Gruppi', 'ok', `✓ ${j.count} membri salvati`, 2500);
                backdrop.remove();
                await loadGroups();
            } else {
                notify('Gruppi', 'error', 'Errore: ' + (j.error || '?'), 0);
            }
        });
    }

    createBtn.addEventListener('click', async () => {
        const name = nameInp.value.trim();
        const desc = descInp.value.trim();
        if (!name) { notify('Gruppi', 'error', 'Inserisci un nome', 2500); return; }
        const tok = await csrf();
        const r = await fetch('/api/teacher/share/groups', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-Token': tok, 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description: desc, _csrf: tok }),
        });
        const j = await r.json();
        if (j.ok) {
            notify('Gruppi', 'ok', `✓ "${name}" creato`, 2000);
            nameInp.value = '';
            descInp.value = '';
            await loadGroups();
        } else if (j.error === 'duplicate_name') {
            notify('Gruppi', 'error', 'Esiste già un gruppo con questo nome', 0);
        } else {
            notify('Gruppi', 'error', 'Errore: ' + (j.error || '?'), 0);
        }
    });

    await loadGroups();
})();

// G22.S15.bis Fase 5 — Cleanup orphan rows.
(function() {
    const scanBtn  = document.getElementById('fm-cleanup-scan');
    const confirmBtn = document.getElementById('fm-cleanup-confirm');
    const resultEl = document.getElementById('fm-cleanup-result');
    if (!scanBtn) return;
    let lastScanHadOrphans = false;

    async function callCleanup(confirm) {
        const tok = await csrf();
        const r = await fetch('/api/teacher/sync/cleanup-orphans', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
            body: JSON.stringify({ confirm: !!confirm, _csrf: tok }),
        });
        return r.json();
    }
    function showResult(j) {
        resultEl.style.display = 'block';
        const ov = j.orphan_verifiche || [];
        const om = j.orphan_mappe || [];
        let txt = '';
        if (j.dry_run) {
            txt += `🔍 Ricerca completata (niente è stato cancellato).\n`;
        } else {
            txt += `✅ Rimossi: ${j.deleted_verifiche || 0} verifiche fantasma, ${j.deleted_mappe || 0} mappe fantasma\n`;
        }
        txt += `Contenuti controllati: ${j.scanned}.\n\n`;
        if (ov.length) {
            txt += `Verifiche con file mancante (${ov.length}):\n`;
            ov.forEach(o => txt += `  • #${o.id} "${o.title}" [${o.variant}]\n`);
        }
        if (om.length) {
            txt += `\nMappe con file mancante (${om.length}):\n`;
            om.forEach(o => txt += `  • #${o.id} "${o.title}"\n`);
        }
        if (!ov.length && !om.length) txt += `Nessun fantasma trovato. Tutti i tuoi contenuti sono integri. ✨`;
        resultEl.textContent = txt;
        return ov.length + om.length;
    }

    scanBtn.addEventListener('click', async () => {
        scanBtn.disabled = true;
        scanBtn.textContent = '🔍 Scansione…';
        try {
            const j = await callCleanup(false);
            if (!j.ok) { alert('Errore: ' + (j.error || 'unknown')); return; }
            const total = showResult(j);
            lastScanHadOrphans = total > 0;
            confirmBtn.disabled = !lastScanHadOrphans;
            confirmBtn.title = lastScanHadOrphans
                ? `Cancella ${total} righe orfane`
                : 'Nessun orfano da cancellare';
        } catch (e) {
            alert('Errore di rete: ' + e.message);
        } finally {
            scanBtn.disabled = false;
            scanBtn.textContent = '🔍 Cerca orfani (dry-run)';
        }
    });

    confirmBtn.addEventListener('click', async () => {
        if (!lastScanHadOrphans) return;
        if (!(await window.FM.Dialog.confirm('Confermi la cancellazione delle righe orfane? L\'operazione è irreversibile (ma i blob sono già mancanti).').catch(() => false))) return;
        confirmBtn.disabled = true;
        confirmBtn.textContent = '🗑 Cancellazione…';
        try {
            const j = await callCleanup(true);
            if (!j.ok) { alert('Errore: ' + (j.error || 'unknown')); return; }
            showResult(j);
            lastScanHadOrphans = false;
            alert(`✅ Cancellati: ${j.deleted_verifiche || 0} verifiche, ${j.deleted_mappe || 0} mappe`);
        } catch (e) {
            alert('Errore di rete: ' + e.message);
        } finally {
            confirmBtn.textContent = '🗑 Cancella orfani trovati';
        }
    });
})();

// G22.S15.bis Fase 5 — sezione drawio spostata in /area-docente/templates?tab=drawio

// G22.S15.bis Fase 5 — Log sincronizzazioni: legge `fm:syncLog` da
// localStorage (popolato da drive-sync-buttons.js). Auto-refresh on
// `fm:sync-log-updated` event + storage change.
(function() {
    const list = document.getElementById('fm-sync-log-list');
    if (!list) return;
    const filterSel = document.getElementById('fm-sync-log-filter');
    const clearBtn  = document.getElementById('fm-sync-log-clear');
    const refreshBtn = document.getElementById('fm-sync-log-refresh');

    // Audit 25.R.31 (L10b) — escHtml consolidato a livello file (vedi cima).

    function render() {
        let entries = [];
        try {
            const raw = localStorage.getItem('fm:syncLog');
            entries = raw ? JSON.parse(raw) : [];
        } catch (_) { entries = []; }
        const filter = filterSel?.value || 'error';
        const filtered = filter === 'all' ? entries
                       : entries.filter(e => e.kind === filter);
        if (!filtered.length) {
            const msg = filter === 'all'
                ? 'Nessuna sincronizzazione registrata.'
                : (filter === 'error' ? 'Nessun errore. Tutto a posto! 🎉'
                                      : 'Nessuna sincronizzazione riuscita registrata.');
            list.innerHTML = '<em>' + msg + '</em>';
            return;
        }
        // Ordine cronologico inverso (più recenti in alto)
        const html = filtered.slice().reverse().map(e => {
            const d = new Date(e.ts);
            const ts = d.toLocaleString('it-IT', { hour12: false });
            const icon = e.kind === 'error' ? '❌'
                       : e.kind === 'ok'    ? '✓'
                                            : 'ℹ';
            const color = e.kind === 'error' ? '#f87171'
                        : e.kind === 'ok'    ? '#4ade80'
                                             : '#9ca3af';
            const tag = `<span class="fm-fw-600" style="--fm-event-color:${color};color:var(--fm-event-color)">${icon} [${e.target}]</span>`;
            return `<div class="fm-py-1"><span>${escHtml(ts)}</span> ${tag} ${escHtml(e.message)}</div>`;
        }).join('');
        list.innerHTML = html;
    }

    filterSel?.addEventListener('change', render);
    refreshBtn?.addEventListener('click', render);
    clearBtn?.addEventListener('click', async () => {
        if (!(await window.FM.Dialog.confirm('Cancellare tutto il log delle sincronizzazioni?').catch(() => false))) return;
        try { localStorage.removeItem('fm:syncLog'); } catch (_) {}
        render();
    });
    window.addEventListener('fm:sync-log-updated', render);
    window.addEventListener('storage', (e) => { if (e.key === 'fm:syncLog') render(); });
    render();
})();
