/**
 * Auth route entry — Phase Roadmap 10.
 *
 * Per pagine /login, /register, /password-reset. Bundle MINIMO
 * (target <5 kB gzip): no jQuery, no editor, solo form validation
 * + dark theme toggle.
 *
 * Caricato esplicitamente nel template auth:
 *   <script type="module" src="<?= ViteManifest::url('js/entries/auth.js') ?>"></script>
 */

// CSRF token refresh hook (se backend espone /api/csrf)
async function maybeRefreshCsrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    const expired = meta.dataset.expires && Date.now() > Number(meta.dataset.expires);
    if (!expired) return;
    try {
        const res = await fetch("/api/csrf", { credentials: "same-origin" });
        if (!res.ok) return;
        const data = await res.json();
        if (data?.token) meta.setAttribute("content", data.token);
    } catch {}
}

// Form basic UX (disable submit while in-flight)
function wireFormSubmitGuards() {
    document.querySelectorAll("form[method='post']").forEach((form) => {
        form.addEventListener("submit", () => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent || "";
                btn.textContent = "Attendere…";
            }
        });
    });
}

function init() {
    wireFormSubmitGuards();
    maybeRefreshCsrf();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
} else {
    init();
}
