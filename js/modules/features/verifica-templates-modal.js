/**
 * G20.7 — Editor templates modal: iframe wrapper su /area-docente/templates.
 *
 * Aperto da ⚙ Editor in topbar (data-fm-action="editor"). Sostituisce il
 * vecchio modal pack-based (G13) con un iframe full-size sulla pagina
 * /area-docente/templates: single source of truth, niente duplicazione UI,
 * stessa cascade teacher → istituto → default sia mid-flow (popup) sia
 * deliberata (full page).
 *
 * UI:
 *   - Backdrop scuro tappabile per chiudere.
 *   - Modal 92vw × 92vh con header (titolo + apri-in-tab + close).
 *   - Iframe occupies tutto lo spazio rimanente.
 */

const MODAL_ID = "fm-vd-templates-modal";
const TARGET_URL = "/area-docente/templates";

function open() {
    // Idempotente: re-open chiude il precedente.
    document.getElementById(MODAL_ID)?.remove();

    const m = document.createElement("div");
    m.id = MODAL_ID;
    m.className = "fm-modal-backdrop fm-vd-templates-backdrop";
    m.innerHTML = `
        <div class="fm-vd-templates-modal" role="dialog" aria-modal="true" aria-label="Editor templates verifica">
            <div class="fm-vd-templates-header">
                <h3>Editor templates verifica</h3>
                <div class="fm-vd-templates-actions">
                    <a class="fm-btn fm-vd-templates-open-tab"
                       href="${TARGET_URL}" target="_blank" rel="noopener"
                       title="Apri /area-docente/templates in una nuova scheda">
                        ↗ Apri pagina completa
                    </a>
                    <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
                </div>
            </div>
            <iframe class="fm-vd-templates-iframe"
                    src="${TARGET_URL}?embed=1"
                    title="Editor templates verifica"
                    loading="lazy"></iframe>
        </div>
    `;

    const close = () => {
        m.classList.remove("fm-modal--visible");
        setTimeout(() => m.remove(), 150);
        document.removeEventListener("keydown", onKey);
    };
    const onKey = (e) => { if (e.key === "Escape") close(); };

    m.addEventListener("click", (e) => {
        if (e.target === m || e.target.closest('[data-action="close"]')) {
            e.preventDefault();
            close();
        }
    });
    document.addEventListener("keydown", onKey);

    document.body.appendChild(m);
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
}

window.FM = window.FM || {};
window.FM.openVerificaTemplatesModal = open;
