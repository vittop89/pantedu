// Entry Vite di views/exercises/search.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
{
(function() {
    const form    = document.getElementById('fm-ex-form');
    const results = document.getElementById('fm-ex-results');

    function render(data) {
        if (!data.ok) {
            results.innerHTML = `<p class="fm-ex-empty">Errore: ${  data.error || 'unknown'  }</p>`;
            return;
        }
        if (data.count === 0) {
            results.innerHTML = '<p class="fm-ex-empty">Nessun esercizio trovato.</p>';
            return;
        }
        const rows = data.rows.map(r => `
            <div class="fm-ex-row">
                <div class="fm-ex-meta">
                    <span class="badge">${r.indirizzo}/${r.classe}/${r.materia}</span>
                    <span class="badge">${r.topic}</span>
                    <span class="badge diff">diff ${r.difficulty}</span>
                    ${(r.tags || []).map(t => `<span class="badge">${  t  }</span>`).join('')}
                </div>
                <div class="fm-ex-title">${r.title}</div>
                <div class="fm-ex-meta">id #${r.id} — fonte: ${r.source}</div>
            </div>
        `).join('');
        results.innerHTML = `<p class="fm-ex-meta">${data.count} risultati</p>${  rows}`;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const params = new URLSearchParams();
        for (const [k, v] of fd.entries()) if (v) params.set(k, v);
        results.innerHTML = '<p class="fm-ex-empty">Caricamento...</p>';
        try {
            const res = await fetch(`/exercises/search.json?${  params.toString()}`);
            const data = await res.json();
            render(data);
        } catch (e) {
            render({ ok: false, error: e.message });
        }
    });
})();
}
