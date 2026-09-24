// Entry Vite di views/auth/register.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
{
(() => {
    const MIN_CHARS = 3;
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    async function fetchSchools(q) {
        const r = await fetch(`/api/scuole?q=${  encodeURIComponent(q)}`, { credentials: 'same-origin' });
        const j = await r.json().catch(() => ({ items: [] }));
        return j.items || [];
    }
    // Phase 25.Q.5 — lookup pubblico del curriculum di QUEL istituto
    // (no auth: lo studente e' in registrazione). Indirizzi e classi
    // vengono dalla STESSA risposta: prima gli indirizzi arrivavano da
    // /api/scuole?types_for=, cioe' dai tipi di scuola MIUR, e finivano
    // in users.indirizzo come "LICEO SCIENTIFICO" — stringa che nessun
    // contenuto usa, visto che i contenuti sono chiavati su SCI/ART/...
    // Risultato: lo studente si iscriveva e non vedeva nulla, senza
    // errori. Una cache per codice evita di richiamare l'endpoint
    // quando si cambia indirizzo.
    const curriculumCache = new Map();
    async function fetchCurriculum(instituteCode) {
        const key = instituteCode || '';
        if (curriculumCache.has(key)) { return curriculumCache.get(key); }
        const url = instituteCode
            ? `/curriculum?institute_code=${  encodeURIComponent(instituteCode)}`
            : '/curriculum';
        const r = await fetch(url, { credentials: 'same-origin' });
        const j = await r.json().catch(() => ({}));
        const cur = j.curriculum || j || {};
        curriculumCache.set(key, cur);
        return cur;
    }
    const activeOnly = (rows) => (rows || []).filter(x => x && x.active);
    async function fetchIndirizzi(instituteCode) {
        return activeOnly((await fetchCurriculum(instituteCode)).indirizzi);
    }
    async function fetchClassi(instituteCode) {
        return activeOnly((await fetchCurriculum(instituteCode)).classi);
    }
    // Le classi offerte allo studente sono le SEZIONI del suo indirizzo.
    //
    // Due filtri, per due ragioni diverse:
    //  - per indirizzo (migration 100): 1A e' dello scientifico e 1BLSS
    //    dello sportivo. Offrirle insieme ancorerebbe lo studente a una
    //    sezione di un corso che non e' il suo, e non vedrebbe nulla.
    //    indirizzo null = trasversale, quindi passa sempre.
    //  - solo sezioni: un codice di solo numero ("1") e' l'anno, non una
    //    sezione. La sezione e' obbligatoria — senza, il filtro
    //    contenuti non saprebbe a quali docenti agganciare lo studente.
    const isSezione = (code) => /^[1-9].+$/.test(String(code || ''));
    async function fetchSezioni(instituteCode, indirizzoCode) {
        const ind = String(indirizzoCode || '').toUpperCase();
        return (await fetchClassi(instituteCode)).filter(c =>
            isSezione(c.code)
            && (!c.indirizzo || String(c.indirizzo).toUpperCase() === ind)
        );
    }
    // Un istituto "contenitore" (es. l'IS che raggruppa i licei) non ha
    // indirizzi propri: senza avviso la tendina resta vuota e sembra
    // rotta. Stesso trattamento gia' previsto per le classi.
    function setIndirizziHint(show) {
        let hint = document.getElementById('indirizzi-hint');
        if (!show) { if (hint) { hint.remove(); } return; }
        if (!hint) {
            hint = document.createElement('p');
            hint.id = 'indirizzi-hint';
            hint.className = 'fm-muted';
            hint.style.cssText = 'font-size:.85em;color:#d9a900;margin:.4em 0 0';
            indSel.insertAdjacentElement('afterend', hint);
        }
        hint.textContent = '⚠️ Nessun indirizzo disponibile per questo istituto. '
            + 'Cerca il liceo specifico (es. "Liceo Scientifico di Esempio") invece dell\'IS contenitore.';
    }

    function renderResults(box, items, onPick) {
        box.innerHTML = '';
        if (!items.length) { box.hidden = true; return; }
        for (const it of items) {
            const div = document.createElement('div');
            div.className = 'fm-autocomplete-item';
            div.setAttribute('role', 'option');
            const meta = [it.type, it.city, it.prov].filter(Boolean).join(' · ');
            div.innerHTML = '<strong></strong> <small></small>';
            div.querySelector('strong').textContent = it.denom;
            div.querySelector('small').textContent = meta ? ` — ${  meta}` : '';
            div.addEventListener('mousedown', (e) => { e.preventDefault(); onPick(it); });
            box.appendChild(div);
        }
        box.hidden = false;
    }

    // ─── Studente: single istituto + indirizzo + classe ───
    const inpS  = document.getElementById('fm-reg-inst-search');
    const boxS  = document.getElementById('institute_results');
    const sel   = document.getElementById('institute_selected');
    const hDen  = document.getElementById('institute_denom');
    const hCom  = document.getElementById('institute_comune');
    const hCode = document.getElementById('institute_code');
    const indSel = document.getElementById('reg_indirizzo');
    const clsSel = document.getElementById('reg_classe');

    const clearStudentDownstream = () => {
        indSel.innerHTML = '<option value="">— Seleziona prima l\'istituto —</option>';
        indSel.disabled = true;
        clsSel.innerHTML = '<option value="">— Seleziona prima l\'indirizzo —</option>';
        clsSel.disabled = true;
    };
    inpS.addEventListener('input', debounce(async () => {
        const q = inpS.value.trim();
        if (q.length < MIN_CHARS) { boxS.hidden = true; return; }
        const items = await fetchSchools(q);
        renderResults(boxS, items, async (it) => {
            inpS.value = it.denom + (it.city ? ` — ${  it.city}` : '');
            hDen.value  = it.denom;
            hCom.value  = it.city  || '';
            hCode.value = it.code  || '';
            sel.hidden = false;
            sel.textContent = `Istituto selezionato: ${  it.denom  }${it.city ? ` (${  it.city  })` : ''}`;
            boxS.hidden = true;

            // Sblocca indirizzo. Il value e' il CODICE del curriculum
            // (SCI, ART, ...), non l'etichetta: e' quello che finisce
            // in users.indirizzo e che deve combaciare con i contenuti.
            indSel.innerHTML = '<option value="">— Seleziona indirizzo —</option>';
            curriculumCache.delete(hCode.value || '');
            const indirizzi = await fetchIndirizzi(hCode.value || '');
            for (const i of indirizzi) {
                const o = document.createElement('option');
                o.value = i.code; o.textContent = i.label || i.code;
                indSel.appendChild(o);
            }
            indSel.disabled = indirizzi.length === 0;
            setIndirizziHint(indirizzi.length === 0);
        });
    }, 200));
    inpS.addEventListener('blur', () => setTimeout(() => { boxS.hidden = true; }, 150));

    indSel.addEventListener('change', async () => {
        if (!indSel.value) { clsSel.disabled = true; clsSel.innerHTML = '<option value="">— Seleziona prima l\'indirizzo —</option>'; return; }
        // Phase 25.Q.5 — passa institute_code MIUR per scoping classi
        const code = hCode.value || '';
        const classi = await fetchSezioni(code, indSel.value);
        clsSel.innerHTML = '<option value="">— Seleziona la tua sezione —</option>';
        for (const c of classi) {
            const o = document.createElement('option');
            o.value = c.code; o.textContent = c.label || c.code;
            clsSel.appendChild(o);
        }
        clsSel.disabled = false;
        let hint = document.getElementById('classi-hint');
        if (classi.length === 0) {
            if (!hint) {
                hint = document.createElement('p');
                hint.id = 'classi-hint';
                hint.className = 'fm-muted';
                hint.style.cssText = 'font-size:.85em;color:#d9a900;margin:.4em 0 0';
                clsSel.insertAdjacentElement('afterend', hint);
            }
            // Due cause diverse, e chi si iscrive deve poter capire quale:
            // l'istituto sbagliato (l'IS contenitore non ha classi proprie)
            // oppure un indirizzo per cui le sezioni non sono state ancora
            // censite. Nel secondo caso non c'e' niente che l'utente possa
            // fare da solo, e dirgli di cercare un'altra scuola lo
            // manderebbe fuori strada.
            const totali = (await fetchClassi(code)).length;
            hint.textContent = totali === 0
                ? '⚠️ Nessuna classe disponibile per questo istituto. Cerca il liceo specifico (es. "Liceo Scientifico di Esempio") invece dell\'IS contenitore.'
                : '⚠️ Per questo indirizzo non risultano ancora sezioni. Segnalalo alla scuola: senza sezione non è possibile completare l\'iscrizione.';
        } else if (hint) {
            hint.remove();
        }
    });

    // ─── Teacher: multi-istituto con chips ───
    const inpT = document.getElementById('institute_search_t');
    const boxT = document.getElementById('institute_results_t');
    const chips = document.getElementById('institute_chips');
    const form  = document.getElementById('fm-register-form');
    const teacherPicks = [];

    const keyOf = (p) => [p.denom, p.city || '', p.type || ''].join('|');
    const renderChips = () => {
        chips.innerHTML = '';
        if (!teacherPicks.length) { chips.hidden = true; syncTeacherRequired(); return; }
        teacherPicks.forEach((p, idx) => {
            const li = document.createElement('li');
            li.className = 'fm-chip';
            const label = document.createElement('span');
            const type = p.type ? ` · ${  p.type}` : '';
            label.textContent = p.denom + (p.city ? ` (${  p.city  })` : '') + type;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Rimuovi');
            btn.textContent = '×';
            btn.addEventListener('click', () => { teacherPicks.splice(idx, 1); renderChips(); });
            li.appendChild(label); li.appendChild(btn);
            chips.appendChild(li);
        });
        chips.hidden = false;
        syncTeacherRequired();
    };

    inpT.addEventListener('input', debounce(async () => {
        const q = inpT.value.trim();
        if (q.length < MIN_CHARS) { boxT.hidden = true; return; }
        const items = await fetchSchools(q);
        renderResults(boxT, items, (it) => {
            const k = keyOf(it);
            if (!teacherPicks.some(p => keyOf(p) === k)) {
                teacherPicks.push(it);
                renderChips();
            }
            inpT.value = '';
            boxT.hidden = true;
        });
    }, 200));
    inpT.addEventListener('blur', () => setTimeout(() => { boxT.hidden = true; }, 150));

    // Serializza picks come JSON hidden prima di submit
    form.addEventListener('submit', (e) => {
        // Rimuovi hidden precedente se presente
        form.querySelectorAll('input[name="teacher_institutes_json"]').forEach(n => n.remove());
        if (!isTeacher()) return;
        const h = document.createElement('input');
        h.type  = 'hidden';
        h.name  = 'teacher_institutes_json';
        h.value = JSON.stringify(teacherPicks.map(p => ({
            denom: p.denom, city: p.city || '', type: p.type || '', code: p.code || '',
        })));
        form.appendChild(h);
    });

    // ─── Toggle per ruolo ───
    const blockS = document.getElementById('fm-reg-inst-student');
    const blockT = document.getElementById('fm-reg-inst-teacher');
    // In single mode (ADR-017) il <select id="role"> non è renderizzato
    // (c'è un hidden role=student): role è null → isTeacher() = false.
    const role   = document.getElementById('role');
    const isTeacher = () => !!role && role.value === 'teacher';
    // 2026-09-22 — la scuola e' obbligatoria solo dove la piattaforma e'
    // adottata da un Istituto. Il server decide (App\Services\SenzaScuola) e
    // lo dice alla pagina con un attributo: se il markup non lo porta, si
    // resta sul comportamento di prima, cioe' obbligatoria. Il verso prudente
    // e' chiedere un dato in piu', non lasciarne passare uno che serve.
    const scuolaObbligatoria = document.getElementById('fm-reg-inst-teacher')
        ?.dataset.scuolaObbligatoria !== '0';
    const syncTeacherRequired = () => {
        // required solo se il docente non ha ancora aggiunto chip — e solo
        // dove la scuola e' obbligatoria
        inpT.required = scuolaObbligatoria && isTeacher() && teacherPicks.length === 0;
    };
    // ─── Phase 25.C2 — toggle birth_date + parent_email ───
    const blockBirth  = document.getElementById('fm-reg-birth-block');
    const blockParent = document.getElementById('fm-reg-parent-block');
    const birthInput  = document.getElementById('birth_date');
    const parentEmail = document.getElementById('parent_email');

    const ITALY_MINOR_THRESHOLD = 14;
    const ageFromDate = (yyyymmdd) => {
        if (!yyyymmdd) return null;
        const dob = new Date(yyyymmdd);
        if (isNaN(dob)) return null;
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age--;
        return age;
    };

    const syncMinorBlock = () => {
        if (!birthInput || isTeacher()) {
            if (blockParent) blockParent.hidden = true;
            if (parentEmail) parentEmail.required = false;
            return;
        }
        const age = ageFromDate(birthInput.value);
        const requiresParent = age !== null && age < ITALY_MINOR_THRESHOLD;
        if (blockParent) blockParent.hidden = !requiresParent;
        if (parentEmail) parentEmail.required = requiresParent;
    };
    if (birthInput) birthInput.addEventListener('change', syncMinorBlock);

    const sync = () => {
        const teacher = isTeacher();
        blockS.hidden = teacher;
        blockT.hidden = !teacher;
        if (blockBirth) blockBirth.hidden = teacher;  // birth_date solo per studenti
        if (birthInput) birthInput.required = !teacher;
        inpS.required   = !teacher;
        indSel.required = !teacher;
        clsSel.required = !teacher;
        syncTeacherRequired();
        syncMinorBlock();
        if (teacher) clearStudentDownstream();
    };
    if (role) role.addEventListener('change', sync);
    sync();
})();

}
