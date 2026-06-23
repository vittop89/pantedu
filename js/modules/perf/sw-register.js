/**
 * Service Worker registration — Phase Roadmap 12.
 *
 * Registra /sw.js con feature-flag opt-in tramite localStorage
 * (allow gradual rollout). Auto-update prompt quando new SW disponibile.
 *
 * NON registra se:
 *   - protocollo non https (eccetto localhost)
 *   - SW già controlla la page (refresh trigger)
 *   - user disabilita via localStorage.setItem("pantedu_sw","0")
 *
 * Usage in bootstrap.js:
 *   import("./modules/perf/sw-register.js").then(m => m.register()).catch(() => {});
 */

const SW_URL = "/sw.js";
const FLAG_KEY = "pantedu_sw";

function isAllowed() {
    if (!("serviceWorker" in navigator)) return false;
    const proto = location.protocol;
    const host = location.hostname;
    if (proto !== "https:" && host !== "localhost" && host !== "127.0.0.1") return false;
    try {
        if (localStorage.getItem(FLAG_KEY) === "0") return false;
    } catch (e) {
        // localStorage blocked → still allow (default opt-in)
    }
    return true;
}

export async function register() {
    if (!isAllowed()) return;

    try {
        // Auto-update: se esiste già un controller (visitatore di ritorno), un
        // nuovo SW (che fa skipWaiting in install) lo rimpiazzerà → al
        // controllerchange ricarichiamo UNA volta, così il contenuto stantio
        // (es. home autenticata cachata dal vecchio SW) viene rimpiazzato senza
        // svuotare la cache a mano. Niente reload al primo install (no controller).
        if (navigator.serviceWorker.controller) {
            let _reloaded = false;
            navigator.serviceWorker.addEventListener("controllerchange", () => {
                if (_reloaded) return;
                _reloaded = true;
                location.reload();
            });
        }

        const reg = await navigator.serviceWorker.register(SW_URL, {
            scope: "/",
            updateViaCache: "none",
        });

        // Auto-detect new version
        reg.addEventListener("updatefound", () => {
            const sw = reg.installing;
            if (!sw) return;
            sw.addEventListener("statechange", () => {
                if (sw.state === "installed" && navigator.serviceWorker.controller) {
                    // New version waiting — notify UI (optional toast)
                    window.dispatchEvent(new CustomEvent("pantedu:sw-update-available"));
                }
            });
        });

        // Periodic check (every hour)
        setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000);
    } catch (e) {
        // Fail silent — SW is optional enhancement
    }
}

export async function unregister() {
    if (!("serviceWorker" in navigator)) return;
    const regs = await navigator.serviceWorker.getRegistrations();
    await Promise.all(regs.map((r) => r.unregister()));
}

export function skipWaiting() {
    if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: "SKIP_WAITING" });
    }
}

/**
 * Purga le cache SW di PAGINE e API (contenuti potenzialmente autenticati),
 * mantenendo la cache statica. Da chiamare al confine di logout (caricamento
 * pagina /login): evita che una pagina autenticata stantia venga servita al
 * guest dopo il logout (→ chiamate teacher → 302 /login → rimbalzo) e migliora
 * la privacy su computer condivisi. Best-effort: messaggia il SW se controlla
 * già la pagina, altrimenti l'attivazione del nuovo SW pulisce comunque le
 * versioni vecchie.
 */
export async function purgeAuthCaches() {
    if (!("serviceWorker" in navigator)) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        const sw = navigator.serviceWorker.controller || reg.active;
        sw?.postMessage({ type: "PURGE_AUTH" });
    } catch (_) { /* no-op */ }
}
