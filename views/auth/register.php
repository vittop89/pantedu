<?php
/** @var string|null $errorMessage */
/** @var bool|null   $done */
/** @var string      $csrf */
/** @var bool        $singleMode */
$singleMode = (bool)($singleMode ?? false);
?>
<div class="fm-card fm-card--modal">
    <h1 class="fm-title">✍️ Registrazione</h1>

    <?php if (!empty($done)): ?>
        <div class="fm-alert fm-alert--success">
            Registrazione inviata. In attesa di approvazione da parte dell'amministratore.
            Riceverai comunicazione via email quando l'account sarà attivo.
        </div>
        <a class="fm-btn fm-btn--primary fm-btn--full" href="/login">Vai al login</a>
    <?php else: ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="fm-alert fm-alert--error"><?= e($errorMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="/register" autocomplete="on" id="fm-register-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <?php if ($singleMode): ?>
                <!-- Phase S2 (ADR-017) SINGLE mode — solo studenti via self-signup.
                     Nuovi docenti vengono aggiunti dall'admin manualmente. -->
                <input type="hidden" name="role" value="student">
                <p class="fm-muted fm-text-em-md fm-mt-0" >
                    Registrazione riservata agli studenti del docente.
                </p>
            <?php else: ?>
                <label class="fm-label" for="role">Ruolo</label>
                <select id="role" class="fm-input" name="role" required>
                    <option value="student">Studente</option>
                    <option value="teacher">Docente</option>
                </select>
            <?php endif; ?>

            <label class="fm-label" for="first_name">Nome</label>
            <input id="first_name" class="fm-input" type="text" name="first_name" maxlength="80" required>

            <label class="fm-label" for="last_name">Cognome</label>
            <input id="last_name"  class="fm-input" type="text" name="last_name"  maxlength="80" required>

            <label class="fm-label" for="fm-reg-email">Email</label>
            <input id="fm-reg-email" class="fm-input" type="email" name="email" maxlength="180" required>

            <label class="fm-label" for="fm-reg-pwd">Password (min 8 caratteri)</label>
            <input id="fm-reg-pwd"   class="fm-input" type="password" name="password"
                   autocomplete="new-password" minlength="8" required>

            <?php /* WS3 — età/genitore solo in modalità 'full'. In 'reduced' niente
                     data di nascita né dati del genitore (minimizzazione). */ ?>
            <?php if (($studentRegMode ?? 'full') === 'full'): ?>
            <!-- Phase 25.C2 — birth_date per studenti (Art. 8 GDPR validation
                 minori D.Lgs. 101/2018: < 14 anni richiede parent_email).
                 Hidden per docenti (non serve età). -->
            <div id="fm-reg-birth-block" class="fm-inst-block" hidden>
                <label class="fm-label" for="birth_date">Data di nascita</label>
                <input id="birth_date" class="fm-input" type="date" name="birth_date"
                       max="<?= date('Y-m-d') ?>">
                <p class="fm-muted fm-text-em-base" >Necessaria per validare i requisiti GDPR minori (Art. 8). Solo studenti.</p>
            </div>

            <!-- Phase 25.C7 — parent_email obbligatorio se età < 14. Mostrato
                 dinamicamente quando birth_date è compilata e calcola età < 14. -->
            <div id="fm-reg-parent-block" class="fm-inst-block" hidden>
                <label class="fm-label" for="parent_email">Email del genitore o tutore legale</label>
                <input id="parent_email" class="fm-input" type="email" name="parent_email"
                       maxlength="180" autocomplete="off">
                <label class="fm-label" for="parent_name">Nome del genitore o tutore (opzionale)</label>
                <input id="parent_name" class="fm-input" type="text" name="parent_name" maxlength="120">
                <p class="fm-muted fm-text-em-md" >
                    Hai meno di 14 anni: il GDPR (Art. 8) richiede il consenso di un genitore.
                    Invieremo un'email al genitore/tutore con un link di conferma. L'account
                    sarà attivo solo dopo la conferma del genitore (entro 30 giorni).
                </p>
            </div>
            <?php endif; ?>

            <!-- Phase 25.C2 — TOS + privacy disclosure obbligatori (Art. 7 + 13).
                 Checkbox NON pre-spuntate (consenso esplicito). -->
            <div class="fm-inst-block fm-mt-4" >
                <label class="fm-label fm-d-flex fm-gap-2 fm-items-start fm-fw-400" >
                    <input type="checkbox" name="accept_tos" value="1" required class="fm-mt-1">
                    <span>Accetto i <a href="/legal/tos" target="_blank" class="fm-link">Termini di Servizio</a>,
                    l'<a href="/legal/aup" target="_blank" class="fm-link">uso accettabile (AUP)</a>
                    e l'<a href="/privacy/informativa" target="_blank" class="fm-link">Informativa Privacy</a>
                    (Art. 13 GDPR).</span>
                </label>
            </div>

            <!-- Phase 14 — istituto MIUR autocomplete server-side (/api/scuole).
                 Il JSON sorgente (~54 MB) non viene MAI esposto al client.
                 Progressione studente: cerca istituto → seleziona → sblocca
                 indirizzo (tipologie distinte per quella scuola) → classe.
                 Teacher: stesso meccanismo, istituti accumulati in lista. -->
            <div id="fm-reg-inst-student" class="fm-inst-block">
                <label class="fm-label" for="fm-reg-inst-search">Istituto (cerca almeno 3 caratteri)</label>
                <input id="fm-reg-inst-search" class="fm-input" type="text"
                       autocomplete="off" placeholder="Nome scuola o comune">
                <div id="institute_results" class="fm-autocomplete" hidden></div>
                <input type="hidden" name="institute_denom"   id="institute_denom">
                <input type="hidden" name="institute_comune"  id="institute_comune">
                <input type="hidden" name="institute_code"    id="institute_code">
                <p id="institute_selected" class="fm-muted fm-text-em-md"  hidden></p>

                <label class="fm-label" for="reg_indirizzo">Indirizzo</label>
                <select id="reg_indirizzo" class="fm-input" name="reg_indirizzo" disabled>
                    <option value="">— Seleziona prima l'istituto —</option>
                </select>
                <label class="fm-label" for="reg_classe">Classe</label>
                <select id="reg_classe" class="fm-input" name="reg_classe" disabled>
                    <option value="">— Seleziona prima l'indirizzo —</option>
                </select>
                <p class="fm-muted fm-text-em-base" >Istituto, indirizzo e classe servono al docente per profilare gli accessi degli studenti.</p>
            </div>
            <div id="fm-reg-inst-teacher" class="fm-inst-block" hidden>
                <label class="fm-label" for="institute_search_t">Istituti in cui lavori (aggiungine uno o più)</label>
                <input id="institute_search_t" class="fm-input" type="text"
                       autocomplete="off" placeholder="Nome scuola o comune">
                <div id="institute_results_t" class="fm-autocomplete" hidden></div>
                <ul id="institute_chips" class="fm-chip-list" hidden></ul>
                <p class="fm-muted fm-text-em-base" >Cerca e clicca per aggiungere. I dati ufficiali provengono dal MIUR.</p>
            </div>

            <button type="submit" class="fm-btn fm-btn--primary fm-btn--full">Invia richiesta</button>
        </form>
        <p class="fm-muted fm-mt-4 fm-text-center" >
            Hai già un account? <a class="fm-link" href="/login">Accedi</a>
        </p>
        <style>
            .fm-autocomplete { position: relative; margin-top: -6px; margin-bottom: 8px;
                max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #c7cdd6;
                border-top: none; border-radius: 0 0 4px 4px; box-shadow: 0 2px 4px rgba(0,0,0,.08); }
            .fm-autocomplete-item { padding: 6px 10px; cursor: pointer; font-size: .9rem; color: #1a1a1a; }
            .fm-autocomplete-item:hover,
            .fm-autocomplete-item[aria-selected="true"] { background: #e3ecf7; }
            .fm-autocomplete-item small { color: #555; }
            .fm-chip-list { list-style: none; padding: 0; margin: 6px 0; display: flex; flex-wrap: wrap; gap: 4px; }
            .fm-chip { background: #e3ecf7; color: #0a4fad; padding: 4px 8px; border-radius: 12px;
                font-size: .85rem; display: inline-flex; align-items: center; gap: 6px; }
            .fm-chip button { background: transparent; border: 0; color: #8a1024; font-size: 1rem;
                cursor: pointer; padding: 0 2px; line-height: 1; }
        </style>
        <script>
        (() => {
            const MIN_CHARS = 3;
            const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

            async function fetchSchools(q) {
                const r = await fetch('/api/scuole?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
                const j = await r.json().catch(() => ({ items: [] }));
                return j.items || [];
            }
            async function fetchTypesFor(denom) {
                const r = await fetch('/api/scuole?types_for=' + encodeURIComponent(denom), { credentials: 'same-origin' });
                const j = await r.json().catch(() => ({ types: [] }));
                return j.types || [];
            }
            async function fetchClassi(instituteCode) {
                // Phase 25.Q.5 — passa institute_code per lookup pubblico
                // delle classi di QUEL istituto specifico (no auth required).
                const url = instituteCode
                    ? '/curriculum?institute_code=' + encodeURIComponent(instituteCode)
                    : '/curriculum';
                const r = await fetch(url, { credentials: 'same-origin' });
                const j = await r.json().catch(() => ({}));
                const cur = j.curriculum || j;
                return (cur.classi || []).filter(x => x && x.active);
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
                    div.querySelector('small').textContent = meta ? ' — ' + meta : '';
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
                    inpS.value = it.denom + (it.city ? ' — ' + it.city : '');
                    hDen.value  = it.denom;
                    hCom.value  = it.city  || '';
                    hCode.value = it.code  || '';
                    sel.hidden = false;
                    sel.textContent = 'Istituto selezionato: ' + it.denom + (it.city ? ' (' + it.city + ')' : '');
                    boxS.hidden = true;

                    // Sblocca indirizzo
                    indSel.innerHTML = '<option value="">— Seleziona indirizzo —</option>';
                    const types = await fetchTypesFor(it.denom);
                    for (const t of types) {
                        const o = document.createElement('option');
                        o.value = t; o.textContent = t;
                        indSel.appendChild(o);
                    }
                    indSel.disabled = types.length === 0;
                });
            }, 200));
            inpS.addEventListener('blur', () => setTimeout(() => { boxS.hidden = true; }, 150));

            indSel.addEventListener('change', async () => {
                if (!indSel.value) { clsSel.disabled = true; clsSel.innerHTML = '<option value="">— Seleziona prima l\'indirizzo —</option>'; return; }
                // Phase 25.Q.5 — passa institute_code MIUR per scoping classi
                const code = hCode.value || '';
                const classi = await fetchClassi(code);
                clsSel.innerHTML = '<option value="">— Seleziona classe —</option>';
                for (const c of classi) {
                    const o = document.createElement('option');
                    o.value = c.code; o.textContent = c.label || c.code;
                    clsSel.appendChild(o);
                }
                clsSel.disabled = false;
                // Hint UX se l'istituto selezionato non ha classi (es. utente
                // ha scelto un IS contenitore invece del liceo specifico).
                let hint = document.getElementById('classi-hint');
                if (classi.length === 0) {
                    if (!hint) {
                        hint = document.createElement('p');
                        hint.id = 'classi-hint';
                        hint.className = 'fm-muted';
                        hint.style.cssText = 'font-size:.85em;color:#d9a900;margin:.4em 0 0';
                        clsSel.insertAdjacentElement('afterend', hint);
                    }
                    hint.textContent = '⚠️ Nessuna classe disponibile per questo istituto. Cerca il liceo specifico (es. "Liceo Scientifico di Esempio") invece dell\'IS contenitore.';
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
                    const type = p.type ? ' · ' + p.type : '';
                    label.textContent = p.denom + (p.city ? ' (' + p.city + ')' : '') + type;
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
            const syncTeacherRequired = () => {
                // required solo se il docente non ha ancora aggiunto chip
                inpT.required = isTeacher() && teacherPicks.length === 0;
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
        </script>
    <?php endif; ?>
</div>
