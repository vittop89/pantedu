/**
 * Admin/Teacher banner badge (Phase 13).
 *
 * Polla GET /api/admin/notifications e iniettano:
 *   - asterisco rosso `★` accanto al link "Admin Dashboard" (admin)
 *   - tooltip con breakdown contatori (pending registrations, failed
 *     logins 24h, blocked credentials/ips)
 *
 * Polling: ogni 60s (default). Si può disattivare via
 *   localStorage.setItem("fm_admin_badge_disabled", "1");
 *
 * Side-safe: se la fetch fail (403 per non-admin / 503 db down) → no
 * badge, no errori console.
 */

const POLL_MS = 60_000;
const SELECTOR_BANNER = ".sel-session-banner";

let timer = null;

function isAdminUser() {
    // Phase 25.E18 — early bail-out per non-admin: l'endpoint
    // /api/admin/notifications è gated dal middleware role:admin →
    // 403 per teacher/student. Senza questo check lo script pollava
    // indefinitamente generando rumore console + traffico inutile.
    // Hint sincroni: <body class="... fm-admin-access"> emessa server-side
    // da views/layout/app.php quando Auth::hasAccess('admin').
    if (typeof document === "undefined") return false;
    if (document.body.classList.contains("fm-admin-access")) return true;
    // Fallback async-safe: window.FM.user popolato da bootstrap.js
    // (fetch /auth/user-info). Se non ancora caricato, default DENY:
    // re-init avverrà su fm:navigated dopo che FM.user è popolato.
    return !!(window.FM?.user?.is_super_admin || window.FM?.user?.role === "admin");
}

async function init() {
    const banner = document.querySelector(SELECTOR_BANNER);
    if (!banner || banner.dataset.fmBadgeBound === "1") return;
    banner.dataset.fmBadgeBound = "1";
    if (localStorage.getItem("fm_admin_badge_disabled") === "1") return;
    // Phase 25.E18 — skip init per non-admin (no polling, no rumore console).
    if (!isAdminUser()) return;

    await refresh();
    if (timer) clearInterval(timer);
    timer = setInterval(refresh, POLL_MS);
}

async function refresh() {
    const banner = document.querySelector(SELECTOR_BANNER);
    if (!banner) return;
    let data;
    try {
        const r = await fetch("/api/admin/notifications", { credentials: "same-origin" });
        if (!r.ok) return removeBadge(banner);
        data = await r.json();
        if (!data.ok) return removeBadge(banner);
    } catch (_) {
        return removeBadge(banner);
    }
    const total = Number(data.total || 0);
    if (total <= 0) return removeBadge(banner);
    upsertBadge(banner, total, data);
}

function upsertBadge(banner, total, data) {
    let badge = banner.querySelector(".fm-admin-badge");
    if (!badge) {
        badge = document.createElement("a");
        badge.className = "fm-admin-badge";
        badge.href = "/admin/dashboard";
        badge.title = "";
        // Inserisci come primo elemento del banner
        banner.prepend(badge);
    }
    badge.innerHTML = `<span class="fm-admin-badge__star">★</span><span class="fm-admin-badge__num">${total}</span>`;
    badge.title = buildTooltip(data);
    banner.classList.add("fm-has-notifications");
}

function removeBadge(banner) {
    banner.querySelector(".fm-admin-badge")?.remove();
    banner.classList.remove("fm-has-notifications");
}

function buildTooltip(d) {
    const parts = [];
    if (d.pending_registrations) parts.push(`📝 ${d.pending_registrations} registrazioni in attesa`);
    if (d.failed_logins_24h)     parts.push(`🚨 ${d.failed_logins_24h} login falliti 24h`);
    if (d.blocked_credentials)   parts.push(`🔒 ${d.blocked_credentials} credenziali bloccate`);
    if (d.blocked_ips)           parts.push(`🌐 ${d.blocked_ips} IP bloccati`);
    if (d.new_teacher_content_24h) parts.push(`📚 ${d.new_teacher_content_24h} nuovi content 24h`);
    return parts.length ? `Apri dashboard:\n${  parts.join("\n")}` : "Apri dashboard";
}

window.addEventListener("fm:navigated", init);
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    queueMicrotask(init);
}

window.FM = window.FM || {};
window.FM.initAdminBadge = init;
