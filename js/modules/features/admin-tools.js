/**
 * Admin Tools page (Phase 13) — gestisce tabs interattive + bridge
 * eventi cross-tab. ESM module loaded by views/admin/tools.php.
 *
 * Cross-tab events:
 *   - "fm:reg-changed"   → ricarica notifiche + counter Registrations badge
 *   - "fm:user-changed"  → opzionale refresh tab Notifiche
 *   - "fm:sec-changed"   → ricarica blocked lists + notifiche
 */

const csrf = () => document.getElementById("fm-tools-csrf")?.value || "";

const post = async (url, body = {}) => {
    const fd = new URLSearchParams();
    fd.set("_csrf", csrf());
    for (const [k, v] of Object.entries(body)) fd.set(k, String(v));
    const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: fd.toString(),
    });
    return { status: r.status, body: await r.json().catch(() => ({})) };
};

const get = async (url) => {
    const r = await fetch(url, { credentials: "same-origin" });
    return { status: r.status, body: await r.json().catch(() => ({})) };
};

const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

// ─────── Tabs ───────

document.querySelectorAll(".fm-tab").forEach((btn) => {
    btn.addEventListener("click", () => {
        document.querySelectorAll(".fm-tab").forEach((b) => b.classList.remove("fm-tab--active"));
        document.querySelectorAll(".fm-tab-panel").forEach((p) => p.classList.remove("fm-tab-panel--active"));
        btn.classList.add("fm-tab--active");
        const panel = document.querySelector(`[data-panel="${btn.dataset.tab}"]`);
        if (panel) {
            panel.classList.add("fm-tab-panel--active");
            // Lazy load on first activation
            if (!panel.dataset.loaded) {
                loadPanel(btn.dataset.tab);
                panel.dataset.loaded = "1";
            }
        }
    });
});

function loadPanel(tab) {
    if (tab === "notifications")  loadNotifications();
    if (tab === "users")          /* on-demand search */ null;
    if (tab === "registrations")  loadRegistrations();
    if (tab === "security")       loadSecurity();
    if (tab === "logs")           /* on-demand */ null;
}

// ─────── Notifications panel ───────

async function loadNotifications() {
    const r = await get("/api/admin/notifications");
    if (!r.body.ok) return;
    const d = r.body;
    const grid = document.getElementById("fm-notif-grid");
    if (!grid) return;
    const tile = (label, n, hint = "", alertColor = false) => `
        <div class="fm-tile${n > 0 ? " fm-tile--alert" : ""}">
            <h3>${esc(label)}</h3>
            <div class="fm-big${alertColor ? " fm-big--danger" : ""}">${n}</div>
            ${hint ? `<small class="fm-muted">${esc(hint)}</small>` : ""}
        </div>`;
    grid.innerHTML = [
        tile("📝 Registrazioni in attesa", d.pending_registrations, "Vai al tab Registrazioni"),
        tile("🚨 Login falliti 24h",       d.failed_logins_24h, "Vai al tab Log"),
        tile("🔒 Credenziali bloccate",    d.blocked_credentials, "Vai al tab Sicurezza"),
        tile("🌐 IP bloccati",             d.blocked_ips, "Vai al tab Sicurezza"),
        tile("📚 Nuovi content 24h",       d.new_teacher_content_24h, "Teacher activity"),
        tile("⚠️ Totale actionable",       d.total, "pending + login_failed", d.total > 0),
    ].join("");
    setTabBadge("notif", d.total);
    setTabBadge("reg", d.pending_registrations);
}
document.getElementById("fm-notif-refresh")?.addEventListener("click", loadNotifications);

function setTabBadge(key, n) {
    const id = key === "notif" ? "tab-badge-notif" : key === "reg" ? "tab-badge-reg" : null;
    if (!id) return;
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = n > 0 ? String(n) : "";
}

// ─────── Users panel ───────

document.getElementById("fm-users-search")?.addEventListener("click", searchUsers);
document.getElementById("fm-users-q")?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") searchUsers();
});
async function searchUsers() {
    const qs = new URLSearchParams({
        q:      document.getElementById("fm-users-q").value || "",
        role:   document.getElementById("fm-users-role").value,
        status: document.getElementById("fm-users-status").value,
        limit:  "100",
    });
    const r = await get(`/api/admin/users?${  qs}`);
    const out = document.getElementById("fm-users-result");
    if (!r.body.ok) { out.innerHTML = `<p class="fm-alert fm-alert--error">${esc(r.body.error || "Errore")}</p>`; return; }
    const rows = r.body.rows || [];
    if (!rows.length) { out.innerHTML = `<p class="fm-muted">Nessun utente.</p>`; return; }
    out.innerHTML = `
        <table class="fm-tools-table">
          <thead><tr><th>ID</th><th>Username</th><th>Nome</th><th>Email</th><th>Ruolo</th><th>Status</th><th>Active</th><th>Azioni</th></tr></thead>
          <tbody>${rows.map(u => `
            <tr data-id="${u.id}">
              <td><code>${u.id}</code></td>
              <td><code>${esc(u.username)}</code></td>
              <td>${esc(u.first_name || "")} ${esc(u.last_name || "")}</td>
              <td><a href="mailto:${esc(u.email)}">${esc(u.email)}</a></td>
              <td>
                <select class="fm-input fm-user-role" data-id="${u.id}" style="font-size:11px">
                  ${["student","teacher","collaborator","administrator"].map(r =>
                    `<option value="${r}" ${u.role === r ? "selected" : ""}>${r}</option>`).join("")}
                </select>
              </td>
              <td>${esc(u.status)}</td>
              <td>${Number(u.active) ? "✅" : "⛔"}</td>
              <td class="fm-row-actions">
                <button class="fm-btn fm-btn--xs ${Number(u.active) ? "fm-btn--danger" : "fm-btn--primary"} fm-user-toggle" data-id="${u.id}" data-active="${u.active ? 0 : 1}">${Number(u.active) ? "Disattiva" : "Attiva"}</button>
                <button class="fm-btn fm-btn--xs fm-btn--danger fm-user-del" data-id="${u.id}">Elimina</button>
              </td>
            </tr>`).join("")}</tbody>
        </table>`;
    bindUserRowActions();
}
function bindUserRowActions() {
    document.querySelectorAll(".fm-user-toggle").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const r = await post(`/api/admin/users/${btn.dataset.id}/active`, { active: btn.dataset.active });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:user-changed"));
            searchUsers();
        });
    });
    document.querySelectorAll(".fm-user-role").forEach((sel) => {
        sel.addEventListener("change", async () => {
            const r = await post(`/api/admin/users/${sel.dataset.id}/role`, { role: sel.value });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:user-changed"));
        });
    });
    document.querySelectorAll(".fm-user-del").forEach((btn) => {
        btn.addEventListener("click", async () => {
            if (!await window.FM.Dialog.confirm("Eliminare definitivamente l'utente?")) return;
            const r = await post(`/api/admin/users/${btn.dataset.id}/delete`);
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:user-changed"));
            searchUsers();
        });
    });
}

// ─────── Registrations panel ───────

async function loadRegistrations() {
    const r = await get("/admin/registrations");
    const out = document.getElementById("fm-reg-result");
    if (!r.body.ok) { out.innerHTML = `<p class="fm-alert fm-alert--error">${esc(r.body.error || "Errore")}</p>`; return; }
    const rows = r.body.pending || [];
    if (!rows.length) { out.innerHTML = `<p class="fm-muted">Nessuna registrazione in attesa.</p>`; return; }
    out.innerHTML = `
        <table class="fm-tools-table">
          <thead><tr><th>Quando</th><th>Username</th><th>Nome</th><th>Email</th><th>Ruolo</th><th>Istituto/i</th><th>Azioni</th></tr></thead>
          <tbody>${rows.map(p => `
            <tr data-id="${p.id}">
              <td><code>${esc(p.created)}</code></td>
              <td><code>${esc(p.username)}</code></td>
              <td>${esc(p.first_name || "")} ${esc(p.last_name || "")}</td>
              <td><a href="mailto:${esc(p.email)}">${esc(p.email)}</a></td>
              <td>${esc(p.role)}</td>
              <td>${p.institute_id ? `id:${p.institute_id}` : (p.institute_ids?.length ? p.institute_ids.join(",") : "—")}</td>
              <td class="fm-row-actions">
                <button class="fm-btn fm-btn--xs fm-btn--primary fm-reg-app" data-id="${p.id}">✓ Approva</button>
                <button class="fm-btn fm-btn--xs fm-btn--danger fm-reg-rej" data-id="${p.id}">✕ Rifiuta</button>
              </td>
            </tr>`).join("")}</tbody>
        </table>`;
    bindRegActions();
}
function bindRegActions() {
    document.querySelectorAll(".fm-reg-app").forEach((btn) => {
        btn.addEventListener("click", async () => {
            if (!await window.FM.Dialog.confirm("Approvare?")) return;
            const r = await post(`/admin/registrations/${btn.dataset.id}/approve`);
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:reg-changed"));
            loadRegistrations();
            loadNotifications();
        });
    });
    document.querySelectorAll(".fm-reg-rej").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const reason = await window.FM.Dialog.prompt("Motivo rifiuto (opzionale):") ?? "";
            const r = await post(`/admin/registrations/${btn.dataset.id}/reject`, { reason });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:reg-changed"));
            loadRegistrations();
            loadNotifications();
        });
    });
}

// ─────── Security panel ───────

async function loadSecurity() {
    const [cred, ips, anom, cfg] = await Promise.all([
        get("/api/admin/security/blocked-credentials"),
        get("/api/admin/security/blocked-ips"),
        get("/api/admin/security/anomalies"),
        get("/api/admin/security/config"),
    ]);
    document.getElementById("fm-sec-anomalies").innerHTML = renderAnomalies(anom.body.rows || [], anom.body.summary || {});
    document.getElementById("fm-sec-creds").innerHTML     = renderSecTable(cred.body.rows || [], "credentials");
    document.getElementById("fm-sec-ips").innerHTML       = renderSecTable(ips.body.rows  || [], "ips");
    renderSecConfig(cfg.body.config || {});
    bindSecActions();
    bindSecConfigForm();
}

function renderSecConfig(cfg) {
    const body = document.getElementById("fm-sec-cfg-body");
    if (!body) return;
    const ea = cfg.security_alerts?.excessive_access || {};
    const cs = cfg.security_alerts?.credential_sharing || {};
    const eaRl = ea.risk_levels || {};
    const csRl = cs.risk_levels || {};
    const num = (v, fb = "") => (v === undefined || v === null ? fb : String(v));
    body.innerHTML = `
      <fieldset style="border:1px solid #ddd;padding:.6rem;margin-bottom:.5rem">
        <legend><strong>🛡️ Excessive access</strong> (DoS-like — stesso IP × sezione)</legend>
        <label><input type="checkbox" name="ea_enabled" ${ea.enabled ? "checked" : ""}> Abilitato</label><br>
        <label>Soglia per sezione: <input type="number" name="ea_threshold_per_section" value="${num(ea.threshold_per_section, 3)}" min="1" max="9999"></label>
        <label style="margin-left:.6rem">Time window (h): <input type="number" name="ea_time_window_hours" value="${num(ea.time_window_hours, 24)}" min="1" max="720"></label>
        <table class="fm-tools-table" style="margin-top:.4rem;max-width:560px">
          <thead><tr><th>Risk</th><th>min accessi</th><th>max accessi</th></tr></thead>
          <tbody>
            <tr><td>low</td><td><input type="number" name="ea_low_min" value="${num(eaRl.low?.min_accesses, 3)}" min="0"></td><td><input type="number" name="ea_low_max" value="${num(eaRl.low?.max_accesses, 5)}" min="0"></td></tr>
            <tr><td>medium</td><td><input type="number" name="ea_medium_min" value="${num(eaRl.medium?.min_accesses, 6)}" min="0"></td><td><input type="number" name="ea_medium_max" value="${num(eaRl.medium?.max_accesses, 15)}" min="0"></td></tr>
            <tr><td>high</td><td><input type="number" name="ea_high_min" value="${num(eaRl.high?.min_accesses, 16)}" min="0"></td><td>∞</td></tr>
          </tbody>
        </table>
      </fieldset>
      <fieldset style="border:1px solid #ddd;padding:.6rem">
        <legend><strong>🔑 Credential sharing</strong> (stesso user × N IP distinti)</legend>
        <label><input type="checkbox" name="cs_enabled" ${cs.enabled ? "checked" : ""}> Abilitato</label><br>
        <label>Min IP richiesti: <input type="number" name="cs_min_ips_required" value="${num(cs.min_ips_required, 2)}" min="2" max="100"></label>
        <label style="margin-left:.6rem">Min accessi/IP: <input type="number" name="cs_min_accesses_per_ip" value="${num(cs.min_accesses_per_ip, 1)}" min="1" max="100"></label>
        <label style="margin-left:.6rem">Time window (h): <input type="number" name="cs_time_window_hours" value="${num(cs.time_window_hours, 24)}" min="1" max="720"></label>
        <table class="fm-tools-table" style="margin-top:.4rem;max-width:560px">
          <thead><tr><th>Risk</th><th>min IP</th><th>max IP</th></tr></thead>
          <tbody>
            <tr><td>low</td><td><input type="number" name="cs_low_min" value="${num(csRl.low?.min_ips, 2)}" min="0"></td><td><input type="number" name="cs_low_max" value="${num(csRl.low?.max_ips, 3)}" min="0"></td></tr>
            <tr><td>medium</td><td><input type="number" name="cs_medium_min" value="${num(csRl.medium?.min_ips, 4)}" min="0"></td><td><input type="number" name="cs_medium_max" value="${num(csRl.medium?.max_ips, 6)}" min="0"></td></tr>
            <tr><td>high</td><td><input type="number" name="cs_high_min" value="${num(csRl.high?.min_ips, 7)}" min="0"></td><td>∞</td></tr>
          </tbody>
        </table>
      </fieldset>`;
}

function bindSecConfigForm() {
    const form = document.getElementById("fm-sec-cfg-form");
    if (!form || form.dataset.fmBound === "1") return;
    form.dataset.fmBound = "1";
    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = {};
        for (const [k, v] of fd.entries()) body[k] = v;
        // Checkbox unchecked non sono in FormData → forza "0"
        if (!body.ea_enabled) body.ea_enabled = "0";
        if (!body.cs_enabled) body.cs_enabled = "0";
        const r = await post("/api/admin/security/config", body);
        if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
        alert("Configurazione salvata. Le anomalie vengono ricalcolate al prossimo refresh.");
        loadSecurity();
        loadNotifications();
    });
    document.getElementById("fm-sec-cfg-reload")?.addEventListener("click", loadSecurity);
}

function renderAnomalies(rows, summary) {
    if (!rows.length) {
        return `<p class="fm-muted">✅ Nessuna anomalia rilevata.</p>`;
    }
    const head = `<p class="fm-bridge-msg">
        Totale: <strong>${summary.total||0}</strong> · Attive: <strong>${summary.active||0}</strong> ·
        DoS-like: <strong>${summary.excessive_access||0}</strong> ·
        Sharing: <strong>${summary.credential_sharing||0}</strong>
    </p>`;
    const riskBadge = (lvl) => {
        const c = { high: "#c62828", medium: "#e6a900", low: "#888" }[lvl] || "#888";
        return `<span style="background:${c};color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:bold">${lvl.toUpperCase()}</span>`;
    };
    const body = rows.map(a => {
        if (a.type === "excessive_access") {
            const us = (a.usernames || []).join(", ") || "—";
            return `<tr style="${a.blocked ? 'opacity:.5;text-decoration:line-through' : ''}">
                <td>🛡️ DoS</td>
                <td>${riskBadge(a.risk_level)}</td>
                <td><code>${esc(a.ip)}</code></td>
                <td><code>${esc(a.section)}</code></td>
                <td>${esc(us)}</td>
                <td><strong>${a.count}</strong></td>
                <td>${esc(a.last_seen)}</td>
                <td>${a.blocked ? '🔒 bloccato' : `<button class="fm-btn fm-btn--xs fm-btn--danger fm-anom-block-ip" data-ip="${esc(a.ip)}" data-section="${esc(a.section)}">Blocca IP</button>`}</td>
            </tr>`;
        }
        // credential_sharing
        const ips = (a.ips || []).join(", ");
        return `<tr style="${a.blocked ? 'opacity:.5;text-decoration:line-through' : ''}">
            <td>🔑 Sharing</td>
            <td>${riskBadge(a.risk_level)}</td>
            <td colspan="2"><code>${esc(a.username)}</code> da <strong>${(a.ips || []).length}</strong> IP</td>
            <td title="${esc(ips)}">${esc(ips.slice(0,60))}${ips.length>60?'…':''}</td>
            <td><strong>${a.count}</strong></td>
            <td>${esc(a.last_seen)}</td>
            <td>${a.blocked ? '🔒 bloccato' : `<button class="fm-btn fm-btn--xs fm-btn--danger fm-anom-block-cred" data-username="${esc(a.username)}">Blocca user</button>`}</td>
        </tr>`;
    }).join("");
    return `${head  }<table class="fm-tools-table">
        <thead><tr><th>Tipo</th><th>Risk</th><th>IP / User</th><th>Sezione</th><th>Dettagli</th><th>#</th><th>Ultimo</th><th>Azione</th></tr></thead>
        <tbody>${body}</tbody>
    </table>`;
}
function renderSecTable(rows, kind) {
    if (!rows.length) return `<p class="fm-muted">Nessun record.</p>`;
    if (kind === "credentials") {
        return `<table class="fm-tools-table">
          <thead><tr><th>Username</th><th>Bloccato il</th><th>Motivo</th><th>Azioni</th></tr></thead>
          <tbody>${rows.map(r => `
            <tr><td><code>${esc(r.username || "")}</code></td>
                <td>${esc(r.blocked_at || "")}</td>
                <td>${esc(r.reason || "")}</td>
                <td><button class="fm-btn fm-btn--xs fm-sec-unblock-cred" data-username="${esc(r.username || "")}">Sblocca</button></td></tr>
          `).join("")}</tbody></table>`;
    }
    return `<table class="fm-tools-table">
      <thead><tr><th>IP</th><th>Username associati</th><th>Sezione</th><th>Bloccato il</th><th>Motivo</th><th>Azioni</th></tr></thead>
      <tbody>${rows.map(r => {
          const users = (r.associated_usernames || []).join(", ") || "—";
          return `<tr><td><code>${esc(r.ip || "")}</code></td>
            <td title="${esc(users)}">${esc(users.slice(0, 80))}${users.length > 80 ? "…" : ""}</td>
            <td>${esc(r.section || "")}</td>
            <td>${esc(r.blocked_at || "")}</td>
            <td>${esc(r.reason || "")}</td>
            <td><button class="fm-btn fm-btn--xs fm-sec-unblock-ip" data-ip="${esc(r.ip || "")}" data-section="${esc(r.section || "")}">Sblocca</button></td></tr>`;
      }).join("")}</tbody></table>`;
}
function bindSecActions() {
    // Block actions from anomaly rows
    document.querySelectorAll(".fm-anom-block-ip").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const r = await post("/api/admin/security/ips/block", {
                ip: btn.dataset.ip, section: btn.dataset.section, reason: "anomaly_excessive_access",
            });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:sec-changed"));
            loadSecurity();
            loadNotifications();
        });
    });
    document.querySelectorAll(".fm-anom-block-cred").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const r = await post("/api/admin/security/credentials/block", {
                username: btn.dataset.username, reason: "anomaly_credential_sharing",
            });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:sec-changed"));
            loadSecurity();
            loadNotifications();
        });
    });
    document.querySelectorAll(".fm-sec-unblock-cred").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const r = await post("/api/admin/security/credentials/unblock", { username: btn.dataset.username });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:sec-changed"));
            loadSecurity();
            loadNotifications();
        });
    });
    document.querySelectorAll(".fm-sec-unblock-ip").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const r = await post("/api/admin/security/ips/unblock", {
                ip: btn.dataset.ip, section: btn.dataset.section,
            });
            if (!r.body.ok) return alert(`Errore: ${  r.body.error || r.status}`);
            window.dispatchEvent(new CustomEvent("fm:sec-changed"));
            loadSecurity();
            loadNotifications();
        });
    });
}

// ─────── Logs panel ───────

document.getElementById("fm-log-load")?.addEventListener("click", loadLogs);
async function loadLogs() {
    const limit = document.getElementById("fm-log-limit").value || "50";
    const r = await get(`/admin/access-log?limit=${  encodeURIComponent(limit)}`);
    const out = document.getElementById("fm-log-result");
    if (!r.body.ok) { out.innerHTML = `<p class="fm-alert fm-alert--error">${esc(r.body.error || "Errore")}</p>`; return; }
    const rows = r.body.logs || [];
    if (!rows.length) { out.innerHTML = `<p class="fm-muted">Nessun record.</p>`; return; }
    out.innerHTML = `
        <table class="fm-tools-table">
          <thead><tr><th>Quando</th><th>Utente</th><th>Action</th><th>IP</th><th>Linkref</th></tr></thead>
          <tbody>${rows.map(r => `
            <tr><td><code>${esc(r.timestamp || "")}</code></td>
                <td><code>${esc(r.username || "")}</code></td>
                <td>${esc(r.action_type || "")}</td>
                <td><code>${esc(r.ip_address || "")}</code></td>
                <td>${esc(r.linkref || r.redirect_page || "")}</td></tr>
          `).join("")}</tbody></table>`;
}

// ─────── Hash panel ───────

document.getElementById("fm-hash-form")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const out = document.getElementById("fm-hash-out");
    const err = document.getElementById("fm-hash-err");
    const cpy = document.getElementById("fm-hash-copy");
    out.hidden = err.hidden = cpy.hidden = true;
    const fd = new FormData(e.currentTarget);
    const r = await post("/admin/generate-hash", {
        password: fd.get("password"),
        cost:     fd.get("cost") || "12",
    });
    if (!r.body.ok) {
        err.textContent = `❌ ${  r.body.error || (`HTTP ${  r.status}`)}`;
        err.hidden = false;
        return;
    }
    out.textContent = r.body.hash;
    out.hidden = cpy.hidden = false;
    cpy.onclick = () => navigator.clipboard.writeText(r.body.hash).then(() => alert("Copiato!"));
});

// ─────── Init: carica notifiche al primo render ───────

loadNotifications();
window.addEventListener("fm:reg-changed", loadNotifications);
window.addEventListener("fm:sec-changed", loadNotifications);
