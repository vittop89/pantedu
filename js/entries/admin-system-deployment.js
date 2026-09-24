// Entry Vite di views/admin/system/deployment.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
{
// Le checkbox "Sezioni" contano solo con Sidebar = "Solo elencate" / "Tutte
// tranne elencate": quando il modo è "Tutte" le disabilito (chiarezza UX).
(function () {
    function syncSectionsState(form) {
        const mode = form.querySelector('select[name="sidebar_mode"]');
        if (!mode) return;
        const off = mode.value === 'all';
        form.querySelectorAll('input[name="sidebar_sections[]"]').forEach((cb) => {
            cb.disabled = off;
            const lbl = cb.closest('label');
            if (lbl) lbl.style.opacity = off ? '0.45' : '';
        });
    }
    document.querySelectorAll('form[action="/admin/system/capability/profile/save"]').forEach((form) => {
        const mode = form.querySelector('select[name="sidebar_mode"]');
        if (mode) mode.addEventListener('change', () => { syncSectionsState(form); });
        syncSectionsState(form);
    });
})();
}

// ── blocco 2 ──
{
(function () {
    const inst = document.getElementById('rc-inst');
    const ind  = document.getElementById('rc-ind');
    const cls  = document.getElementById('rc-cls');
    if (!inst || !ind || !cls) return;
    const fill = (sel, items, ph) => {
        sel.innerHTML = `<option value="">${  ph  }</option>`;
        for (const o of (items || [])) {
            const e = document.createElement('option');
            e.value = o.code; e.textContent = `${o.label || o.code  } (${  o.code  })`;
            sel.appendChild(e);
        }
    };
    inst.addEventListener('change', async () => {
        ind.disabled = cls.disabled = true;
        fill(ind, [], '— …'); fill(cls, [], '— …');
        const iid = inst.value;
        if (!iid) { fill(ind, [], '— scegli istituto prima —'); fill(cls, [], '— scegli istituto prima —'); return; }
        try {
            const r = await fetch(`/curriculum?institute_id=${  encodeURIComponent(iid)}`, { credentials: 'same-origin' });
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

}
