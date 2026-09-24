// Entry Vite di views/admin/dashboard.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { auditReason } from "../modules/core/audit-reason.js";

// ── blocco 1 ──
{
(function () {
    const CSRF = document.getElementById("fm-page-config")?.dataset.csrf ?? "";
    // 23/9/2026 (A-69) — approvare e rifiutare una registrazione vogliono la
    // motivazione (X-Audit-Reason): senza, 400 audit_reason_required.
    // L'indirizzo intero sta nella chiamata, non composto qui dentro, così la
    // guardia sui chiamanti (ChiamantiMandanoLaMotivazioneTest) lo riconosce.
    async function call(id, url, motivo, extra) {
        const body = new URLSearchParams({ _csrf: CSRF, ...(extra || {}) });
        const res  = await fetch(url, {
            method: 'POST', body, headers: { Accept: 'application/json', 'X-Audit-Reason': motivo }
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || !json.ok) {
            alert(`Errore: ${  json.error || res.status}`);
            return false;
        }
        document.querySelector(`tr[data-id="${  CSS.escape(id)  }"]`)?.remove();
        return true;
    }
    document.querySelectorAll('.fm-reg-approve').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (confirm('Approvare la registrazione?')) {
                call(id, `/admin/registrations/${encodeURIComponent(id)}/approve`,
                    auditReason(`Approvazione della registrazione #${id}, confermata nel cruscotto`));
            }
        });
    });
    document.querySelectorAll('.fm-reg-reject').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const reason = prompt('Motivo del rifiuto (opzionale):') ?? '';
            call(id, `/admin/registrations/${encodeURIComponent(id)}/reject`,
                auditReason(`Rifiuto della registrazione #${id}`, reason), { reason });
        });
    });
})();
}
