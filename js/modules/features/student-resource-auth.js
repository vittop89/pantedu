import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * Student → resource auth (Phase 13).
 *
 * Lo studente (loggato come `student` o guest) inserisce
 * username/password nel widget #fm-resource-auth della sidebar per
 * accedere alle risorse di un docente. Verifica via
 * POST /api/access/student-login. Se ok, il backend salva un grant
 * in sessione (`fm_teacher_access`) e l'API /api/study/* mostra le
 * risorse del docente.
 *
 * Visibility:
 *   - admin/teacher loggati → widget nascosto (hanno i propri edit-btn
 *     scoped per sidepage, vedi section-edit-mode.js)
 *   - student loggati       → widget nascosto (hanno già account proprio,
 *     niente bisogno di credenziali alias; Phase 25.Q.9 UX fix per
 *     evitare confusione "invalid_credentials")
 *   - guest                 → widget visibile con form
 */

const ROOT_ID = "fm-resource-auth";

async function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root || root.dataset.fmInit === "1") return;
    root.dataset.fmInit = "1";

    // Phase 25.Q.9 — nascosto per QUALUNQUE utente autenticato (staff o
    // studente). Solo guest non autenticato vede il widget per accedere
    // alle risorse di un docente con credenziali alias (teacher_access_credentials).
    const isAuthenticated = !!document.querySelector(".sel-session-banner");
    if (isAuthenticated) { root.hidden = true; return; }

    // Stato grant attuale
    let grant = null;
    try {
        const j = await fetchJson("/api/access/status");
        grant = j.grant || null;
    } catch (_) {}

    if (grant) {
        renderGrant(root, grant);
    } else {
        renderForm(root);
    }
}

function renderGrant(root, grant) {
    // Se grant è source=user_account (admin/teacher self-access),
    // promuoviamo a sel-session-banner stile staff: link a /admin/tools
    // o /teacher/dashboard + nascondiamo il widget.
    // Phase 20 — dopo login self-access: full reload per far emettere
    // al server il banner SSR completo (layout 2-row + Logout nella
    // sel-wrapper-actions). Senza reload la sidebar resta nel vecchio
    // stato transient con banner minimale injected da upgradeToStaffBanner.
    if (grant.source === "user_account") {
        if (grant.__fresh === true) {
            location.reload();
            return;
        }
        upgradeToStaffBanner(grant);
        root.hidden = true;
        return;
    }
    // Altrimenti grant è da teacher_access_credentials (studente):
    // mostra strip compatto con label + esci.
    root.hidden = false;
    root.innerHTML = `
        <div class="fm-resource-grant">
            <small>🔓 Accesso risorse</small>
            <strong>${esc(grant.label || "(senza label)")}</strong>
            <button type="button" id="fm-resource-logout" class="fm-btn fm-btn--small">Esci</button>
        </div>`;
    root.querySelector("#fm-resource-logout")?.addEventListener("click", async () => {
        const csrf = await fetchCsrf();
        await fetch("/api/access/student-logout", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        renderForm(root);
    });
    // Notifica anche listener esterni (banner badge ecc.)
    window.dispatchEvent(new CustomEvent("fm:resource-grant-changed", { detail: grant }));
}

/**
 * Promuove l'utente "self-access" a staff banner reale (visualmente
 * indistinguibile dal banner sessione admin/teacher renderizzato SSR).
 * Crea/aggiorna .sel-session-banner con link a /admin/tools.
 */
function upgradeToStaffBanner(grant) {
    let banner = document.querySelector(".sel-session-banner");
    if (!banner) {
        banner = document.createElement("div");
        banner.className = "sel-session-banner";
        banner.style.cssText = "padding:.5rem;margin:.5rem;background:#fde8ec;border-radius:4px;font-size:.85rem";
        const sidebar = document.querySelector(".sidebar");
        const refNode = document.getElementById("fm-resource-auth");
        sidebar?.insertBefore(banner, refNode || sidebar.firstChild);
    }
    const isAdmin   = /administrator/i.test(grant.label || "");
    const homeUrl   = isAdmin ? "/admin/tools" : "/area-docente/dashboard";
    const homeLabel = isAdmin ? "Admin Tools"  : "Area docente";
    // Preserva il dark-mode btn esistente: stacca PRIMA, riattaccato DOPO
    // innerHTML. Lookup via `.fm-sb-dark` (class canonica, no ID).
    const existingDarkBtn = banner.querySelector(".fm-sb-dark");
    banner.innerHTML = `
        ${isAdmin ? "🔧" : "👨‍🏫"}
        <a href="${homeUrl}" style="color:${isAdmin ? "#c73149" : "#0b5fd1"};font-weight:600">${homeLabel}</a> ·
        <a href="#" id="fm-grant-logout" style="color:#666">Esci risorse</a> ·
        <a href="/logout" style="color:#333">Logout</a>`;
    if (existingDarkBtn) {
        banner.appendChild(existingDarkBtn);
    } else {
        // Crea ex novo se mancava (banner generato da zero).
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fm-sb-dark fm-darkmode-mini";
        btn.title = "Attiva/Disattiva modalità scura";
        btn.innerHTML = '<span class="fm-darkmode-icon">🌙</span>';
        banner.appendChild(btn);
        // Ri-bind handler tramite bootstrap-compat
        window.FM?.syncExerciseContext?.();
    }
    banner.querySelector("#fm-grant-logout")?.addEventListener("click", async (e) => {
        e.preventDefault();
        const csrf = await fetchCsrf();
        await fetch("/api/access/student-logout", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        location.reload();
    });
    // Trigger badge admin refresh (counter notifications)
    window.FM?.initAdminBadge?.();
    window.dispatchEvent(new CustomEvent("fm:resource-grant-changed", { detail: grant }));
}

function renderForm(root) {
    root.innerHTML = `
        <form class="fm-resource-form" id="fm-resource-auth-form" autocomplete="off">
            <small>🔑 Accesso risorse docente</small>
            <input name="username" type="text" placeholder="username docente" required minlength="3" autocomplete="username">
            <input name="password" type="password" placeholder="password" required minlength="6" autocomplete="current-password">
            <button type="submit" class="fm-btn fm-btn--small fm-btn--primary">Entra</button>
            <p class="fm-resource-msg" hidden></p>
        </form>`;
    root.querySelector("#fm-resource-auth-form")?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        const msg  = form.querySelector(".fm-resource-msg");
        msg.hidden = true;
        try {
            const csrf = await fetchCsrf();
            const fd   = new FormData(form);
            fd.set("_csrf", csrf);
            const j = await fetchJson("/api/access/student-login", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams(fd).toString(),
            });
            if (!j.ok) throw new Error(j.error || "richiesta non riuscita");
            // Phase 20 — flag __fresh per discriminare login appena avvenuto
            // (renderGrant farà reload per preferire banner SSR) vs grant
            // caricato all'init della pagina (no reload, upgrade inline).
            const fresh = { ...j.grant, __fresh: true };
            renderGrant(root, fresh);
            window.dispatchEvent(new CustomEvent("fm:resource-grant-changed", { detail: j.grant }));
        } catch (ex) {
            msg.textContent = `❌ ${  ex.message || ex}`;
            msg.hidden = false;
        }
    });
}

window.addEventListener("fm:navigated", init);
document.addEventListener("DOMContentLoaded", init);

window.FM = window.FM || {};
window.FM.initResourceAuth = init;
