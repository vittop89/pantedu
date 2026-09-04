/**
 * Web Vitals collector — Phase Roadmap (Web Vitals dashboard).
 *
 * Captures real user metrics (RUM) e li invia a /api/vitals.
 * Rate-limited server-side. Anonymized: nessun user-id, solo route +
 * viewport + connection type.
 *
 * Lazy-load: dynamic import("web-vitals") da CDN come fallback se npm
 * package non disponibile (fast cold start).
 *
 * Usage in bootstrap.js:
 *   import("./modules/perf/web-vitals.js").then(m => m.start()).catch(() => {});
 */

const ENDPOINT = "/api/vitals";
const SAMPLE_RATE = 0.5; // 50% of users — reduce server load

function shouldSample() {
    // Skip on slow devices to avoid extra battery drain
    if (navigator.deviceMemory && navigator.deviceMemory < 1) return false;
    if (navigator.connection?.saveData) return false;
    return Math.random() < SAMPLE_RATE;
}

function send(metric) {
    if (!metric || typeof metric.value !== "number") return;

    const payload = {
        name: metric.name,
        value: Math.round(metric.value),
        rating: metric.rating,
        id: metric.id,
        navigationType: metric.navigationType,
        url: location.pathname,
        viewport: `${window.innerWidth}x${window.innerHeight}`,
        connection: navigator.connection?.effectiveType ?? "unknown",
        rtt: navigator.connection?.rtt ?? null,
        downlink: navigator.connection?.downlink ?? null,
    };

    const body = JSON.stringify(payload);

    // sendBeacon survives page unload
    if (navigator.sendBeacon) {
        try {
            const blob = new Blob([body], { type: "application/json" });
            const ok = navigator.sendBeacon(ENDPOINT, blob);
            if (ok) return;
        } catch (e) {
            // fall through
        }
    }

    // Fallback fetch keepalive
    fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
        keepalive: true,
        credentials: "same-origin",
    }).catch(() => {});
}

let started = false;

export async function start() {
    if (started || !shouldSample()) return;
    started = true;

    try {
        // 2026-05-24: self-hosted (era unpkg.com CDN bloccato da CSP
        // script-src 'self' restrictive). Self-host elimina dipendenza
        // esterna, no CSP allowlist da estendere, no leak privacy verso
        // CDN third-party. File: public/js/web-vitals/web-vitals.module.js
        // (ESM build da unpkg.com/web-vitals@4/dist/web-vitals.js, 7 KB).
        // Path /js/* NOT /vendor/* perché nginx/Apache hanno alias
        // /vendor/ → composer dir, conflict con asset pubblici.
        const wv = await import("/js/web-vitals/web-vitals.module.js");
        wv.onCLS(send);
        wv.onINP(send);
        wv.onLCP(send);
        wv.onFCP(send);
        wv.onTTFB(send);
    } catch (e) {
        // Self-hosted file missing — fail silent (not critical)
    }
}
