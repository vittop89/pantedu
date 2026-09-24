// Entry Vite di views/admin/sidebar-config.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
{
document.addEventListener('change', (e) => {
    const sel = e.target.closest('.fm-ts-mode');
    if (!sel) return;
    const td = sel.closest('td');
    if (!td) return;
    const scope = td.querySelector('.fm-ts-scope');
    const teachers = td.querySelector('.fm-ts-teachers');
    if (scope)    scope.style.display    = sel.value === 'scope'    ? '' : 'none';
    if (teachers) teachers.style.display = sel.value === 'teachers' ? '' : 'none';
});

}

// ── blocco 2 ──
{
// ADR-027 — sync tavolozza nativa ↔ campo hex. Il color-picker scrive l'hex
// nel campo name="color" adiacente; svuotando l'hex la sezione torna al tema.
(function () {
    document.addEventListener("input", (e) => {
        const p = e.target;
        if (!p.classList || !p.classList.contains("fm-color-pick")) return;
        // trova il vicino input[name=color] nello stesso contenitore
        const box = p.closest("td, span, label") || p.parentNode;
        const hex = box && box.querySelector('input[name="color"]');
        if (hex) hex.value = p.value;
    });
})();
}

// ── blocco 3 ──
{
// Phase 24.73 — Salva riga in AJAX: niente più full-page reload. Il submit
// del form-riga viene intercettato, inviato via fetch, e il pulsante mostra
// l'esito inline. (La "Nuova sezione" e l'eliminazione restano full-submit
// perché ristrutturano la tabella.)
(function () {
    // NB: i <form> dentro <tr> sono HTML invalido → il parser li "foster-parenta"
    // FUORI dalla tabella: il <form> resta VUOTO mentre input/button restano
    // nella riga, associati al form via form-OWNER (per questo il submit nativo
    // funziona). Quindi NON usare form.querySelector/descendant: si usano
    // form.elements, e.submitter e new FormData(form) che lavorano via owner.
    const rowForms = document.querySelectorAll('form[action="/admin/sidebar-config/save"]');
    rowForms.forEach((form) => {
        if (form.elements["_new"]) return; // crea-sezione: full submit
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const btn = e.submitter
                || (form.elements ? Array.from(form.elements).find(el => el.type === "submit") : null);
            const old = btn ? btn.textContent : "";
            if (btn) { btn.disabled = true; btn.textContent = "…"; }
            try {
                const res = await fetch(form.action, {
                    method: "POST",
                    body: new FormData(form),
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "fetch" },
                });
                const ok = res.ok || res.redirected;
                if (btn) btn.textContent = ok ? "✓ Salvato" : "✗";
            } catch (_) {
                if (btn) btn.textContent = "✗ Errore";
            }
            if (btn) setTimeout(() => { btn.disabled = false; btn.textContent = old; }, 1300);
        });
    });
})();
}

// ── blocco 4 ──
{
/* Phase 25 — editor categorie predefinite per sezione (dinamico su group_mode). */
(function () {
    function catsBox(sel) { return sel.closest("td")?.querySelector(".fm-sc-cats"); }
    function rowHtml() {
        return '<span class="fm-sc-cat-row" style="display:flex;gap:2px;align-items:center">'
            + '<input type="text" name="default_categories[]" value="" class="fm-input" style="width:7em;font-size:.78em" maxlength="32" pattern="[a-zA-Z0-9_ -]{1,32}">'
            + '<button type="button" class="fm-btn fm-btn--xs fm-sc-cat-del" title="Rimuovi dai default">🗑</button></span>';
    }
    document.addEventListener("change", (e) => {
        const sel = e.target.closest?.(".fm-sc-groupmode");
        if (!sel) return;
        const box = catsBox(sel);
        if (!box) return;
        if (sel.value === "category") { box.style.display = ""; return; }
        // → per materia: se ci sono categorie con valore, avvisa della perdita.
        const vals = Array.from(box.querySelectorAll('input[name="default_categories[]"]'))
            .map(i => i.value.trim()).filter(Boolean);
        if (vals.length) {
            const ok = confirm(`Passando a «per materia» perderai le categorie predefinite di questa sezione:\n\n  ${
                 vals.join(", ")
                 }\n\nI documenti dei docenti NON vengono eliminati (restano come categoria 'residua' migrabile in Area docente).\n\nContinuare?`);
            if (!ok) { sel.value = "category"; return; }
        }
        box.style.display = "none";
    });
    document.addEventListener("click", (e) => {
        const add = e.target.closest?.(".fm-sc-cat-add");
        if (add) {
            const list = add.closest(".fm-sc-cats")?.querySelector(".fm-sc-cats__list");
            if (list) { list.insertAdjacentHTML("beforeend", rowHtml()); list.lastElementChild.querySelector("input")?.focus(); }
            return;
        }
        const del = e.target.closest?.(".fm-sc-cat-del");
        if (del) { del.closest(".fm-sc-cat-row")?.remove(); }
    });
})();
}
