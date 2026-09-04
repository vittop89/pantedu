<?php
/** Vista filtro esercizi — usa /exercises/search.json via fetch. */
$pageTitle = 'Ricerca esercizi — PANTEDU';
$pageHead  = '<style>
.fm-ex-search { padding: 20px; max-width: 1200px; margin: 0 auto; font-family: system-ui, sans-serif; }
.fm-ex-search h1 { margin-bottom: 16px; }
.fm-ex-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; padding: 16px; background: #f5f5f5; border-radius: 6px; }
.fm-ex-filters input, .fm-ex-filters select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.875rem; }
.fm-ex-filters button { padding: 8px 18px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
.fm-ex-filters button:hover { background: #0056b3; }
.fm-ex-results { margin-top: 16px; }
.fm-ex-row { padding: 12px; border: 1px solid #ddd; margin-bottom: 8px; border-radius: 4px; background: white; }
.fm-ex-meta { font-size: 0.75rem; color: #666; margin-bottom: 6px; }
.fm-ex-meta .badge { display: inline-block; padding: 2px 8px; background: #e0e0e0; border-radius: 10px; margin-right: 4px; }
.fm-ex-meta .diff { background: #fff3cd; color: #856404; }
.fm-ex-title { font-weight: 600; margin-bottom: 4px; }
.fm-ex-empty { padding: 30px; text-align: center; color: #999; }
</style>';
require dirname(__DIR__) . '/partials/head.php';
?>
<body class="fm-shell">
<?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>
<main class="fm-ex-search">
    <h1>Ricerca esercizi</h1>
    <p class="fm-ex-meta">Filtra il database esercizi (57 record migrati da eser/**.php).</p>
    <form id="fm-ex-form" class="fm-ex-filters">
        <select name="indirizzo">
            <option value="">— indirizzo —</option>
            <option value="sc">sc (scientifico)</option>
            <option value="ar">ar (artistico)</option>
        </select>
        <select name="classe">
            <option value="">— classe —</option>
            <option value="sc1s">sc1s</option><option value="sc2s">sc2s</option><option value="sc3s">sc3s</option>
            <option value="ar2s">ar2s</option><option value="ar3s">ar3s</option><option value="ar4s">ar4s</option><option value="ar5s">ar5s</option>
        </select>
        <select name="materia">
            <option value="">— materia —</option>
            <option value="MAT">MAT</option><option value="FIS">FIS</option>
        </select>
        <select name="difficulty">
            <option value="">— difficoltà —</option>
            <option value="0">0 (intro)</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
        </select>
        <input type="text" name="topic" placeholder="topic (es. Limiti)" />
        <input type="text" name="q" placeholder="ricerca testuale (full-text)" />
        <button type="submit">Cerca</button>
    </form>
    <div id="fm-ex-results" class="fm-ex-results">
        <p class="fm-ex-empty">Inserisci dei filtri e premi "Cerca".</p>
    </div>
</main>
<script>
(function() {
    const form    = document.getElementById('fm-ex-form');
    const results = document.getElementById('fm-ex-results');

    function render(data) {
        if (!data.ok) {
            results.innerHTML = '<p class="fm-ex-empty">Errore: ' + (data.error || 'unknown') + '</p>';
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
                    ${(r.tags || []).map(t => '<span class="badge">' + t + '</span>').join('')}
                </div>
                <div class="fm-ex-title">${r.title}</div>
                <div class="fm-ex-meta">id #${r.id} — fonte: ${r.source}</div>
            </div>
        `).join('');
        results.innerHTML = `<p class="fm-ex-meta">${data.count} risultati</p>` + rows;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const params = new URLSearchParams();
        for (const [k, v] of fd.entries()) if (v) params.set(k, v);
        results.innerHTML = '<p class="fm-ex-empty">Caricamento...</p>';
        try {
            const res = await fetch('/exercises/search.json?' + params.toString());
            const data = await res.json();
            render(data);
        } catch (e) {
            render({ ok: false, error: e.message });
        }
    });
})();
</script>
</body>
</html>
