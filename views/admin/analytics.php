<?php
/** @var string $csrf */
$page_title    = '📊 Admin Analytics';
$page_subtitle = 'Statistiche aggregate su utenti, content, accessi. Cross-teacher inspection per audit/security/copyright.';
$breadcrumb    = [['label' => 'Analytics']];
include __DIR__ . '/_partials/page_head.php';
?>
    <input type="hidden" id="fm-an-csrf" value="<?= e($csrf) ?>">

    <div class="fm-tabs" role="tablist">
        <button class="fm-tab fm-tab--active" data-tab="overview">📈 Overview</button>
        <button class="fm-tab" data-tab="teachers">👨‍🏫 Drill-down per docente</button>
        <button class="fm-tab" data-tab="search">🔍 Cross-teacher search</button>
    </div>

    <div class="fm-tab-panels">
        <section class="fm-tab-panel fm-tab-panel--active" data-panel="overview">
            <h2>Overview</h2>
            <div id="fm-an-overview"><p class="fm-muted">Caricamento…</p></div>
        </section>
        <section class="fm-tab-panel" data-panel="teachers">
            <h2>Drill-down per docente</h2>
            <div class="fm-toolbar">
                <input type="number" id="fm-an-tid" class="fm-input fm-max-w-140" placeholder="teacher ID" >
                <button class="fm-btn fm-btn--primary" id="fm-an-tid-load">Carica</button>
                <small class="fm-muted">ID lo trovi nel tab Utenti di /admin/tools.</small>
            </div>
            <div id="fm-an-teacher"></div>
        </section>
        <section class="fm-tab-panel" data-panel="search">
            <h2>Cross-teacher content search <button type="button" class="fm-infotip" aria-label="Info ricerca cross-docente"><span class="fm-infotip__body" hidden>Permette all'admin di ispezionare il content di tutti i docenti (anche draft) per audit, security review, copyright check. Flag euristici: <code>copyright_marker</code>, <code>external_links</code>, <code>publisher_brand_mention</code>.</span></button></h2>
            <div class="fm-toolbar">
                <input type="search" id="fm-an-q" class="fm-input fm-max-w-280" placeholder="Cerca in title/topic/body…" >
                <select id="fm-an-type" class="fm-input fm-max-w-160" >
                    <option value="">Tutti i tipi</option>
                    <option value="mappa">Mappa</option>
                    <option value="esercizio">Esercizio</option>
                    <option value="lab">Lab</option>
                    <option value="verifica">Verifica</option>
                </select>
                <button class="fm-btn fm-btn--primary" id="fm-an-search">Cerca</button>
            </div>
            <div id="fm-an-search-result"></div>
        </section>
    </div>
</div>

<?php /* Phase 25.D — CSS estratto in /css/admin.css (auto-load da layout/shell). */ ?>

<script>
(() => {
    const csrf = () => document.getElementById("fm-an-csrf")?.value || "";
    const get = async (url) => (await (await fetch(url, { credentials: "same-origin" })).json());
    const esc = (s) => String(s ?? "").replace(/[&<>"']/g, c =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

    document.querySelectorAll(".fm-tab").forEach((btn) => {
        btn.addEventListener("click", () => {
            document.querySelectorAll(".fm-tab").forEach((b) => b.classList.remove("fm-tab--active"));
            document.querySelectorAll(".fm-tab-panel").forEach((p) => p.classList.remove("fm-tab-panel--active"));
            btn.classList.add("fm-tab--active");
            document.querySelector(`[data-panel="${btn.dataset.tab}"]`)?.classList.add("fm-tab-panel--active");
        });
    });

    async function loadOverview() {
        const j = await get("/api/admin/analytics");
        if (!j.ok) return;
        const ov = document.getElementById("fm-an-overview");
        // KPI headline (solo i 2 numeri principali).
        const kpi = (label, n) => `<div class="fm-an-kpi"><span class="fm-an-kpi__n">${n}</span><span class="fm-an-kpi__l">${esc(label)}</span></div>`;
        // Tabella ordinata con intestazioni; "—" se vuota.
        const tbl = (headers, items, fmt) => items && items.length
            ? `<table class="fm-an-table"><thead><tr>${headers.map(h => `<th scope="col">${esc(h)}</th>`).join("")}</tr></thead><tbody>${items.map(fmt).join("")}</tbody></table>`
            : `<p class="fm-muted fm-m-0">—</p>`;
        // Mini-distribuzione (label → conteggio) in box compatto.
        const dist = (title, items, head) => `<div class="fm-an-distbox"><h4>${esc(title)}</h4>${
            tbl([head, "N"], items || [], r => `<tr><td>${esc(r.k)}</td><td class="fm-an-r"><strong>${r.n}</strong></td></tr>`)
        }</div>`;

        ov.innerHTML = `
            <div class="fm-an-kpis">
                ${kpi("Accessi 24h", j.access_24h_total)}
                ${kpi("Accessi 7 giorni", j.access_7d_total)}
            </div>

            <div class="fm-an-dist">
                ${dist("👥 Utenti per ruolo", j.users_by_role, "Ruolo")}
                ${dist("📚 Content per tipo", j.content_by_type, "Tipo")}
                ${dist("👁️ Content per visibilità", j.content_by_vis, "Visibilità")}
            </div>

            <h3>🏆 Top 10 autori (content)</h3>
            ${tbl(["Utente", "Ruolo", "Totali", "Pubblicati"], j.top_authors || [], r =>
                `<tr><td><code>${esc(r.username)}</code></td><td>${esc(r.role)}</td><td class="fm-an-r"><strong>${r.n}</strong></td><td class="fm-an-r">${r.published_n}</td></tr>`)}

            <h3>🏫 Top 10 istituti</h3>
            ${tbl(["Codice", "Nome", "Città", "Utenti", "Docenti"], j.top_institutes || [], r =>
                `<tr><td><code>${esc(r.code)}</code></td><td>${esc(r.name)}</td><td>${esc(r.city || "")}</td><td class="fm-an-r">${r.users_count}</td><td class="fm-an-r">${r.teachers_count}</td></tr>`)}

            <div class="fm-an-dist">
                <div class="fm-an-distbox"><h4>📈 Accessi 30g per ruolo</h4>
                    ${tbl(["Ruolo", "Accessi"], j.access_30d_role || [], r => `<tr><td><code>${esc(r.k)}</code></td><td class="fm-an-r"><strong>${r.n}</strong></td></tr>`)}
                </div>
                <div class="fm-an-distbox"><h4>📍 Accessi 30g per sezione</h4>
                    ${tbl(["Sezione", "Accessi"], j.access_30d_section || [], r => `<tr><td><code>${esc(r.k)}</code></td><td class="fm-an-r"><strong>${r.n}</strong></td></tr>`)}
                </div>
            </div>
        `;
    }

    document.getElementById("fm-an-tid-load")?.addEventListener("click", async () => {
        const tid = document.getElementById("fm-an-tid").value;
        if (!tid) return alert("Inserisci teacher ID");
        const j = await get(`/api/admin/analytics/teacher/${encodeURIComponent(tid)}`);
        const out = document.getElementById("fm-an-teacher");
        if (!j.ok) { out.innerHTML = `<p class="fm-alert fm-alert--error">${esc(j.error || "Errore")}</p>`; return; }
        const tableSection = (rows, headers, fmt) => rows.length
            ? `<table class="fm-an-table"><thead><tr>${headers.map(h => `<th scope="col">${esc(h)}</th>`).join("")}</tr></thead><tbody>${rows.map(fmt).join("")}</tbody></table>`
            : `<p class="fm-muted">—</p>`;
        out.innerHTML = `
            <h3>Teacher #${j.teacher_id}</h3>
            <p>Content totali: <strong>${j.content_count}</strong> · Access codes: <strong>${j.access_codes_count}</strong></p>
            <h4>Istituti</h4>
            ${tableSection(j.institutes || [], ["Code", "Nome"], r => `<tr><td><code>${esc(r.code)}</code></td><td>${esc(r.name)}</td></tr>`)}
            <h4>Content per tipo / visibility</h4>
            ${tableSection(j.content_by_type || [], ["Tipo", "Visibility", "N"], r => `<tr><td>${esc(r.k)}</td><td>${esc(r.v)}</td><td>${r.n}</td></tr>`)}
            <h4>📈 Accessi studenti via codici (30d)</h4>
            ${tableSection(j.student_accesses_30d || [], ["Codice", "Accessi", "IP unici", "Ultimo"],
                r => `<tr><td><code>${esc(r.username)}</code></td><td><strong>${r.count}</strong></td><td>${r.unique_ips}</td><td>${esc(r.last_seen || "—")}</td></tr>`)}
        `;
    });

    document.getElementById("fm-an-search")?.addEventListener("click", async () => {
        const q    = document.getElementById("fm-an-q").value || "";
        const type = document.getElementById("fm-an-type").value || "";
        const qs   = new URLSearchParams({ q, type, limit: "100" });
        const j    = await get(`/api/admin/analytics/cross-search?${qs}`);
        const out  = document.getElementById("fm-an-search-result");
        if (!j.ok) { out.innerHTML = `<p class="fm-alert fm-alert--error">${esc(j.error || "Errore")}</p>`; return; }
        const rows = j.rows || [];
        if (!rows.length) { out.innerHTML = `<p class="fm-muted">Nessun risultato.</p>`; return; }
        out.innerHTML = `<table class="fm-an-table">
            <thead><tr><th scope="col">ID</th><th scope="col">Tipo</th><th scope="col">Author</th><th scope="col">Materia/Sez</th><th scope="col">Title</th><th scope="col">Visibility</th><th scope="col">Risk</th><th scope="col">Snippet</th></tr></thead>
            <tbody>${rows.map(r => `
                <tr>
                    <td><code>${r.id}</code></td>
                    <td>${esc(r.content_type)}</td>
                    <td><code>${esc(r.teacher_username || "—")}</code></td>
                    <td>${esc(r.subject_code || "")} · ${esc(r.indirizzo || "")}/${esc(r.classe || "")}</td>
                    <td>${esc(r.title)}<br><small class="fm-muted">${esc(r.topic || "")}</small></td>
                    <td>${esc(r.visibility)}</td>
                    <td>${(r.risk_flags || []).map(f => `<span class="fm-an-flag">${esc(f)}</span>`).join("") || "—"}</td>
                    <td><small>${esc(r.body_snippet || "")}</small></td>
                </tr>
            `).join("")}</tbody>
        </table>`;
    });

    loadOverview();
})();
</script>

</div><!-- /.fm-card -->
