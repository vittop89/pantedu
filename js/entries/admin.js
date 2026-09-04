/**
 * Admin route bundle entry — Phase Roadmap 10.
 *
 * Caricato condizionalmente sui template /admin/*. Contiene SOLO
 * funzionalità specifiche admin (no editor/no exercise context).
 *
 * Lazy import dal template:
 *   <?php if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin')): ?>
 *     <script type="module" src="<?= ViteManifest::url('js/entries/admin.js') ?>"></script>
 *   <?php endif; ?>
 *
 * Bundle target: <30 kB gzip.
 */

// Admin-specific feature modules (lazy load on demand).
// 2026-05-24 — scheletro: i moduli admin-tabs.js e waf-charts.js non
// sono stati ancora estratti. Vite static analysis fail su import
// inesistenti, quindi commentati. Riabilitare quando i moduli esistono:
//
//   if (document.querySelector(".fm-admin-tabs")) {
//       const m = await import("../modules/ui/admin-tabs.js");
//       m.initAdminTabs?.();
//   }
//   if (document.querySelector("[data-fm-waf-chart]")) {
//       await import("../modules/features/waf-charts.js");
//   }
async function bootAdmin() {
    // No-op finché i moduli admin specifici non vengono estratti.
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootAdmin, { once: true });
} else {
    bootAdmin();
}
