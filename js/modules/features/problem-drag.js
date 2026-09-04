/**
 * Phase 17 — Drag-and-drop reorder dei `.fm-groupcollex` via handle `.moveBtn`.
 *
 * Comportamento:
 *   1. mousedown su `.moveBtn` → marca il `.fm-groupcollex` genitore con
 *      `draggable="true"` (HTML5 DnD nativo).
 *   2. dragstart → dataTransfer riceve l'id/index del problem.
 *   3. dragover sugli altri `.fm-groupcollex` siblings → calcola insert-position
 *      based on pointer y (top half → insert before, bottom half → after).
 *   4. drop → riordina DOM + POST `/api/teacher/content/{contractId}/group/{groupRef}/move?to=N`
 *      con `If-Match: "v<N>"` per optimistic locking.
 *   5. dragend → cleanup `draggable` attribute.
 *
 *  Il riordino DOM è fatto PRIMA del POST così il feedback è immediato.
 *  Su 409/conflict il client ri-fetcha la version e ritenta (apiPost retry).
 */

function init() {
    if (document.documentElement.dataset.fmDragProblemBound === "1") return;
    document.documentElement.dataset.fmDragProblemBound = "1";

    // 1) Abilita draggable sul .fm-groupcollex solo durante mousedown su .moveBtn.
    //    Evita che un click normale su testo/contenuti inneschi drag.
    document.addEventListener("mousedown", (e) => {
        const handle = e.target.closest(".fm-move-btn");
        if (!handle) return;
        const problem = handle.closest(".fm-groupcollex");
        if (!problem) return;
        problem.setAttribute("draggable", "true");
    }, true);

    document.addEventListener("mouseup", () => {
        document.querySelectorAll('.fm-groupcollex[draggable="true"]').forEach((p) => {
            if (!p.classList.contains("fm-dragging")) p.removeAttribute("draggable");
        });
    }, true);

    // 2) dragstart
    document.addEventListener("dragstart", (e) => {
        const problem = e.target.closest(".fm-groupcollex");
        if (!problem || !problem.getAttribute("draggable")) return;
        problem.classList.add("fm-dragging");
        e.dataTransfer.effectAllowed = "move";
        try { e.dataTransfer.setData("text/plain", problem.id || ""); } catch {}
    });

    // 3) dragover — calcola la posizione di insert relativa
    document.addEventListener("dragover", (e) => {
        const dragging = document.querySelector(".fm-groupcollex.fm-dragging");
        if (!dragging) return;
        const target = e.target.closest(".fm-groupcollex");
        if (!target || target === dragging) return;
        if (target.parentElement !== dragging.parentElement) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = "move";
        const rect = target.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        if (after) target.after(dragging);
        else target.parentElement.insertBefore(dragging, target);
    });

    // 4) drop — persisti la nuova posizione server-side
    document.addEventListener("drop", async (e) => {
        const dragging = document.querySelector(".fm-groupcollex.fm-dragging");
        if (!dragging) return;
        e.preventDefault();
        const siblings = [...dragging.parentElement.children].filter(
            (n) => n.classList.contains("fm-groupcollex")
        );
        const newIdx = siblings.indexOf(dragging);
        await persistProblemMove(dragging, newIdx);
    });

    // 5) dragend — cleanup
    document.addEventListener("dragend", () => {
        document.querySelectorAll(".fm-groupcollex.fm-dragging").forEach((p) => {
            p.classList.remove("fm-dragging");
            p.removeAttribute("draggable");
        });
    });
}

async function persistProblemMove(problem, newIdx) {
    const wrap = problem.closest(".fm-contract-wrap");
    const contractId = wrap?.dataset.id;
    if (!/^\d+$/.test(contractId || "")) return; // synthetic, skip persist
    const groupRef = problem.id
        || `g${  [...wrap.querySelectorAll(".fm-groupcollex")].indexOf(problem)}`;
    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    const url = `/api/teacher/content/${contractId}/group/${encodeURIComponent(groupRef)}/move?to=${newIdx}`;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const res = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "If-Match": `"v${version}"`,
        },
        body: new URLSearchParams({ _csrf: csrf }).toString(),
    });
    if (res.ok) {
        const j = await res.json().catch(() => ({}));
        if (wrap && Number.isFinite(j.version)) wrap.dataset.version = String(j.version);
        window.FM?.ToastManager?.show?.("success", "OK", "Ordine salvato", 2000);
    } else if (res.status === 409) {
        window.FM?.ToastManager?.show?.("warning", "Conflitto", "Ricarica la pagina", 3000);
    } else if (res.status !== 404) {
        window.FM?.ToastManager?.show?.("error", "Errore", `HTTP ${res.status}`, 3000);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
window.addEventListener("fm:navigated", init);
