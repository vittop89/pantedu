/**
 * G22.S25 — Popup gestione grants espliciti per un contenuto.
 *
 * Apertura: openShareGrantsPopup({ source, id, title }).
 * Tre sezioni: istituti, docenti colleghi, gruppi personali.
 * Multi-select per ognuna. Save → setGrants() (sostituisce set).
 *
 * Sources: 'teacher_content' | 'verifica_documents'.
 *
 * API delegata a share/share-client.js (cached + CSRF). ESM-only —
 * nessun shim su window.FM (consumer chiamano `import {...}`).
 */

import {
    listGrants, setGrants,
    getColleagues, getGroups, getInstitutes,
    invalidateShareCache,
} from "./share/share-client.js";
import { escHtml } from "../core/dom-utils.js";

const Z_INDEX = 10200; // sopra detail popup verifica (10100) e backdrop generico (9999)

function notify(kind, msg, ms = 2500) {
    window.FM?.SyncPanel?.notify?.("Pool", kind, msg, ms);
}

function buildShell(title) {
    const backdrop = document.createElement("div");
    backdrop.className = "fm-modal-backdrop fm-share-grants-backdrop";
    backdrop.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:${Z_INDEX};display:flex;align-items:center;justify-content:center;padding:20px`;

    const box = document.createElement("div");
    box.className = "fm-modal fm-share-grants-modal";
    box.style.cssText = "background:var(--fm-c-bg,#1f2330);color:var(--fm-c-fg,#e2e8f0);padding:16px 20px;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.4);max-width:680px;width:100%;max-height:85vh;overflow:auto";
    box.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h2 style="margin:0;font-size:1.1rem">🎯 Condivisione avanzata</h2>
            <button type="button" class="fm-btn fm-btn--xs fm-grants-close" title="Chiudi">✕</button>
        </div>
        <p class="fm-muted" style="margin:0 0 12px 0;font-size:13px">
            Contenuto: <strong>${escHtml(title || "")}</strong><br>
            Seleziona istituti, docenti o gruppi specifici. Coesiste col toggle rapido "🤝 Condividi": la riga è
            recuperabile se l'attore è in almeno uno dei target sottostanti, oppure se hai attivato il toggle rapido.
        </p>
        <div data-fm-grants-loading class="fm-muted">Caricamento…</div>
        <div data-fm-grants-content hidden>
            <section style="margin-bottom:16px">
                <h3 style="margin:0 0 8px 0;font-size:.95rem">🏫 Istituti</h3>
                <div data-fm-grants-institutes style="display:flex;flex-direction:column;gap:6px"></div>
            </section>
            <section style="margin-bottom:16px">
                <h3 style="margin:0 0 8px 0;font-size:.95rem">👤 Docenti colleghi</h3>
                <input type="search" data-fm-grants-filter placeholder="Cerca docente…"
                       class="fm-input fm-input--sm" style="width:100%;margin-bottom:8px">
                <div data-fm-grants-teachers style="max-height:180px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding:4px;border:1px solid rgba(255,255,255,0.1);border-radius:4px"></div>
            </section>
            <section style="margin-bottom:16px">
                <h3 style="margin:0 0 8px 0;font-size:.95rem;display:flex;align-items:center;gap:8px">
                    👥 Gruppi personali
                    <a href="/area-docente/dashboard#fm-share-groups-section" class="fm-link" style="font-size:11px;font-weight:normal">gestisci gruppi →</a>
                </h3>
                <div data-fm-grants-groups style="display:flex;flex-direction:column;gap:6px"></div>
            </section>
            <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid rgba(255,255,255,0.1);padding-top:12px">
                <button type="button" class="fm-btn fm-btn--sm fm-grants-cancel">Annulla</button>
                <button type="button" class="fm-btn fm-btn--sm fm-btn--primary fm-grants-save">💾 Salva grants</button>
            </div>
        </div>
        <div data-fm-grants-error class="fm-alert fm-alert--error" hidden></div>
    `;
    backdrop.appendChild(box);
    return { backdrop, box };
}

function renderCheckboxList(target, items, type, initial, labelFor) {
    if (!items.length) {
        target.innerHTML = `<em class="fm-muted" style="font-size:12px">Nessun elemento.</em>`;
        return;
    }
    target.innerHTML = items.map((it) => {
        const checked = initial.has(`${type}|${it.id}`) ? "checked" : "";
        return `<label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer">
            <input type="checkbox" data-target-type="${type}" data-target-id="${it.id}" ${checked}>
            <span>${labelFor(it)}</span>
        </label>`;
    }).join("");
}

export function closeShareGrantsPopup() {
    document.querySelectorAll(".fm-share-grants-backdrop").forEach(n => n.remove());
}

export async function openShareGrantsPopup({ source, id, title }) {
    closeShareGrantsPopup();
    const { backdrop, box } = buildShell(title);
    document.body.appendChild(backdrop);

    box.querySelector(".fm-grants-close").addEventListener("click", closeShareGrantsPopup);
    box.querySelector(".fm-grants-cancel").addEventListener("click", closeShareGrantsPopup);
    backdrop.addEventListener("click", (e) => { if (e.target === backdrop) closeShareGrantsPopup(); });

    const loadingEl = box.querySelector("[data-fm-grants-loading]");
    const contentEl = box.querySelector("[data-fm-grants-content]");
    const errBox    = box.querySelector("[data-fm-grants-error]");

    try {
        // Le 3 liste (colleagues/groups/institutes) sono cached con TTL 60s
        // dal share-client: invocazioni ripetute non rifanno fetch.
        const [grants, colleagues, groups, institutes] = await Promise.all([
            listGrants(source, id),
            getColleagues(),
            getGroups(),
            getInstitutes(),
        ]);

        const initial = new Set(grants.map(g => `${g.target_type}|${g.target_id}`));

        renderCheckboxList(
            box.querySelector("[data-fm-grants-institutes]"),
            institutes, "institute", initial,
            (i) => `🏫 ${escHtml(i.header_label || i.name)}`,
        );
        renderCheckboxList(
            box.querySelector("[data-fm-grants-teachers]"),
            colleagues, "teacher", initial,
            (t) => `👤 ${escHtml(t.display_name)} <span class="fm-muted" style="font-size:10px">(${escHtml(t.username)})</span>`,
        );
        renderCheckboxList(
            box.querySelector("[data-fm-grants-groups]"),
            groups, "group", initial,
            (g) => `👥 ${escHtml(g.name)} <span class="fm-muted" style="font-size:11px">(${g.members_count} ${g.members_count === 1 ? "membro" : "membri"})</span>`,
        );

        // Filter docenti
        const filter = box.querySelector("[data-fm-grants-filter]");
        const teachersWrap = box.querySelector("[data-fm-grants-teachers]");
        filter.addEventListener("input", () => {
            const q = filter.value.toLowerCase().trim();
            teachersWrap.querySelectorAll("label").forEach((lbl) => {
                lbl.style.display = lbl.textContent.toLowerCase().includes(q) ? "" : "none";
            });
        });

        // Save
        box.querySelector(".fm-grants-save").addEventListener("click", async () => {
            errBox.hidden = true;
            const checks = box.querySelectorAll('input[type=checkbox][data-target-type]:checked');
            const out = [...checks].map(cb => ({
                target_type: cb.dataset.targetType,
                target_id: parseInt(cb.dataset.targetId, 10),
            }));
            try {
                const j = await setGrants(source, id, out);
                // I grant cambiano permission ma non le liste cached
                // (colleagues/groups/institutes). Niente invalidate.
                notify("ok", `✓ Grants aggiornati (${j.count} target)`);
                closeShareGrantsPopup();
            } catch (e) {
                errBox.textContent = "Errore: " + e.message;
                errBox.hidden = false;
            }
        });

        loadingEl.hidden = true;
        contentEl.hidden = false;
    } catch (err) {
        loadingEl.textContent = "Errore caricamento: " + err.message;
        // Bust cache così l'apertura successiva ritenta da rete.
        invalidateShareCache();
    }
}
