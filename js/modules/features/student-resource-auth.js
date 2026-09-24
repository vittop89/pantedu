import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * Student → resource auth (Phase 13).
 *
 * Lo studente (guest) inserisce username/password nel widget
 * #fm-resource-auth della sidebar per accedere alle risorse di un docente.
 * Verifica via POST /api/access/student-login. Se ok, il backend mette il
 * grant nel portachiavi di sessione e l'API /api/study/* mostra le risorse
 * dei docenti presenti.
 *
 * Piano classi-credenziali-scenari, C — il portachiavi: il widget elenca le
 * credenziali attive (una per docente), permette di uscire da una sola, di
 * aggiungerne un'altra, e mostra gli avvisi delle credenziali cadute (per
 * esempio disattivate dal docente) invece di far sparire i contenuti in
 * silenzio.
 *
 * Visibility:
 *   - admin/teacher loggati → widget nascosto (hanno i propri edit-btn
 *     scoped per sidepage, vedi section-edit-mode.js)
 *   - student loggati       → widget nascosto (hanno già account proprio;
 *     Phase 25.Q.9 UX fix per evitare confusione "invalid_credentials")
 *   - guest                 → widget visibile con form o portachiavi
 */

const ROOT_ID = "fm-resource-auth";

const REASONS = {
    disattivata: "è stata disattivata dal docente",
    scaduta:     "è scaduta",
    eliminata:   "non esiste più",
};

async function init() {
    const root = document.getElementById(ROOT_ID);
    if (!root || root.dataset.fmInit === "1") return;
    root.dataset.fmInit = "1";

    // Phase 25.Q.9 — nascosto per QUALUNQUE utente autenticato (staff o
    // studente). Solo guest non autenticato vede il widget.
    const isAuthenticated = !!document.querySelector(".sel-session-banner");
    if (isAuthenticated) { root.hidden = true; return; }

    let status = { grant: null, grants: [], notices: [] };
    try {
        const j = await fetchJson("/api/access/status");
        status = { grant: j.grant || null, grants: j.grants || [], notices: j.notices || [] };
    } catch (_) { /* senza stato: form */ }

    if (status.grant && status.grant.source === "user_account") {
        upgradeToStaffBanner(status.grant);
        root.hidden = true;
        return;
    }
    if (status.grants.length) {
        renderKeychain(root, status.grants, status.notices);
    } else {
        renderForm(root, status.notices);
    }
}

async function postLogout(credentialId) {
    const csrf = await fetchCsrf();
    const body = new URLSearchParams({ _csrf: csrf });
    if (credentialId) body.set("credential_id", String(credentialId));
    const r = await fetch("/api/access/student-logout", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
    });
    let j = {};
    try { j = await r.json(); } catch (_) { /* vuoto */ }
    return j.grants || [];
}

/**
 * Gli avvisi sulle credenziali cadute, come nodi: le etichette le scrive il
 * docente, e con i nodi non c'è niente da escapare (né da contare per la
 * regola semgrep sull'innerHTML interpolato).
 */
function noticesNode(notices) {
    const frag = document.createDocumentFragment();
    if (!notices || !notices.length) return frag;
    const box = document.createElement("div");
    box.className = "fm-resource-notices";
    box.setAttribute("role", "status");
    for (const n of notices) {
        const p = document.createElement("p");
        p.className = "fm-m-0 fm-text-13";
        p.textContent = `⚠️ La credenziale «${n.label || ""}» ${REASONS[n.reason] || "non è più valida"}: chiedi al docente quella nuova.`;
        box.appendChild(p);
    }
    frag.appendChild(box);
    return frag;
}

function renderKeychain(root, grants, notices) {
    root.hidden = false;
    root.innerHTML = `
        <div class="fm-resource-grant fm-resource-keychain">
            <small>🔓 Accesso risorse${grants.length > 1 ? ` · ${grants.length} docenti` : ""}</small>
            <ul class="fm-resource-keychain__list">
                ${grants.map((g) => `
                <li data-credential-id="${g.credential_id ? Number(g.credential_id) : ""}">
                    <strong${g.materie_nomi ? ` title="Materie: ${esc(g.materie_nomi)}"` : ""}>${esc(g.label || "(senza label)")}</strong>${g.classe ? ` <span class="fm-muted">· ${esc(g.classe)}</span>` : ""}
                    ${g.credential_id ? `<button type="button" class="fm-btn fm-btn--small fm-resource-exit" data-credential-id="${Number(g.credential_id)}" title="Togli questa credenziale">Esci</button>` : ""}
                </li>`).join("")}
            </ul>
            <p class="fm-m-0 fm-mt-1">
                <a class="fm-link fm-text-13" href="/accesso-classe" data-full-reload>➕ Aggiungi credenziale</a>
                · <button type="button" id="fm-resource-logout" class="fm-btn fm-btn--small">Esci da tutte</button>
            </p>
        </div>`;
    root.querySelector(".fm-resource-keychain")?.prepend(noticesNode(notices));
    root.querySelectorAll(".fm-resource-exit").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const rest = await postLogout(btn.dataset.credentialId);
            if (rest.length) renderKeychain(root, rest, []); else renderForm(root, []);
            // Contenuti e sidebar dipendono dai docenti presenti: ricarica.
            location.reload();
        });
    });
    root.querySelector("#fm-resource-logout")?.addEventListener("click", async () => {
        await postLogout(null);
        renderForm(root, []);
        location.reload();
    });
    // Notifica anche listener esterni (banner badge ecc.)
    window.dispatchEvent(new CustomEvent("fm:resource-grant-changed", { detail: grants[0] || null }));
}

/**
 * Promuove l'utente "self-access" a staff banner reale (visualmente
 * indistinguibile dal banner sessione admin/teacher renderizzato SSR).
 * Crea/aggiorna .sel-session-banner con link a /admin.
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
    const homeUrl   = isAdmin ? "/admin" : "/area-docente/dashboard";
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
        await postLogout(null);
        location.reload();
    });
    // Trigger badge admin refresh (counter notifications)
    window.FM?.initAdminBadge?.();
    window.dispatchEvent(new CustomEvent("fm:resource-grant-changed", { detail: grant }));
}

function renderForm(root, notices) {
    root.hidden = false;
    root.innerHTML = `
        <form class="fm-resource-form" id="fm-resource-auth-form" autocomplete="off">
            <small>🔑 Accesso risorse docente</small>
            <input name="username" type="text" placeholder="username docente" required minlength="3" autocomplete="username">
            <input name="password" type="password" placeholder="password" required minlength="6" autocomplete="current-password">
            <button type="submit" class="fm-btn fm-btn--small fm-btn--primary">Entra</button>
            <p class="fm-resource-msg" hidden></p>
        </form>`;
    root.prepend(noticesNode(notices));
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
            // Phase 20 — dopo il login il server rende sidebar e contenuti
            // per i docenti presenti: reload per il banner SSR completo.
            if (j.grant && j.grant.source === "user_account") {
                location.reload();
                return;
            }
            renderKeychain(root, j.grants || [j.grant], []);
            location.reload();
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
