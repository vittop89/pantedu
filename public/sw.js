/**
 * Pantedu Service Worker — Phase Roadmap 12.
 *
 * Strategy:
 *   - Cache-first  per asset versioned (hash in filename, /build/*)
 *   - Network-first per HTML routes (fallback to cache after 3s timeout)
 *   - Stale-while-revalidate per /api/* GET (fresh-when-online)
 *   - NEVER cache: POST/PUT/DELETE, /auth/*, /api/study/*?private,
 *     /api/vitals, /admin/*
 *
 * Offline UX:
 *   - /offline.html fallback page
 *   - IndexedDB queue per write deferred (TODO Phase 12.2)
 *
 * Versioning:
 *   - CACHE_VERSION bumpa quando assets cambiano (cache-bust)
 *   - skipWaiting + clients.claim per attivazione immediata
 *
 * Security:
 *   - HTTPS-only origin (production)
 *   - Mai cache se Authorization header presente (private response)
 */

// 2026-05-24 — bump v1→v2 per invalidare cache vecchi che contenevano
// HTML page con <link href="/css/main.css"> (no bundle, no cache-bust):
// quei tag generavano warning "preloaded but not used" perché il backend
// ora serve main.bundle.css?v=X via Link header → URL mismatch con
// cached HTML/precache.
// 2026-05-26 — bump v3→v4: il SW faceva cacheFirst su TUTTI i .js (incl. i
// moduli RAW /js/ non-versionati) e su /build/manifest.json → serviva codice
// vecchio per sempre, anche dopo "svuota cache" del browser (la cache del SW è
// separata). Sintomo: nuove feature (allineamenti/elenchi/anteprima) invisibili.
// Fix: manifest e /js/ raw NON più cacheFirst (vedi fetch handler) + bump.
// 2026-05-27 — bump v4→v5: i moduli /js/ erano serviti stantii (stale-while-
// revalidate dava la versione vecchia al primo load) → feature nuove invisibili
// finché la cache non si rinfrescava. Il bump invalida tutte le cache vecchie.
// 2026-06-06 — bump v6→v7: fix scelte server-side / ordine gruppi / copia
// elenco / validazione topic invisibili agli utenti con bundle in cache (HTML
// network-first va in timeout su 3G → serve HTML cached → hash build vecchi).
// 2026-06-07 — bump v7→v8: pagina /teacher/pdf-import in NEVER_CACHE (era
// servita stantia → entry bundle vecchio → preview "lampeggiava" con codice
// pre-fix). Invalida anche le cache vecchie.
// 2026-06-09 — bump v8→v9: gli endpoint LISTA di contenuto (study/content.json,
// teacher/content, risdoc/templates) passano da stale-while-revalidate a
// network-first. SWR serviva la lista STANTIA subito dopo una mutazione (delete
// → l'item cancellato riappariva al click ✓; create → il nuovo item non
// compariva) finché un reload non rinfrescava la cache. Per dati mutati
// dall'utente la correttezza richiede fresh-when-online (cache solo offline).
const CACHE_VERSION = "v11";
const STATIC_CACHE = `pantedu-static-${CACHE_VERSION}`;
const PAGES_CACHE  = `pantedu-pages-${CACHE_VERSION}`;
const API_CACHE    = `pantedu-api-${CACHE_VERSION}`;
const OFFLINE_URL  = "/offline.html";

// 2026-05-24 — rimosso `/css/main.css` + altri CSS da precache: l'app
// ora serve `/css/main.bundle.css?v=<mtime>` (cache-bust dinamico). Il SW
// è static JS, non può conoscere il mtime — precache stale forzava il SW
// a servire CSS vecchi anche dopo deploy. Lasciamo cacheFirst runtime
// popolare lazy: prima visita post-deploy fetcha network, poi cache.
// Solo offline.html va precachato (URL stabile, fallback critico).
const STATIC_PRECACHE = [
    OFFLINE_URL,
];

const NEVER_CACHE_PATHS = [
    "/auth/",
    "/admin/",
    "/api/vitals",
    "/api/csrf",
    // PDF-Import: endpoint di polling (status) + immagini pagina = dinamici e
    // owner-gated → mai cache (no stale, no risposte servite senza credenziali).
    "/api/teacher/pdf-import/",
    // La PAGINA del tool: mai servirla stantia (entry bundle vecchio → preview rotto).
    "/teacher/pdf-import",
    "/logout",
    "/login",
    "/register",
];

// 2026-06-09 — endpoint LISTA/INDEX di contenuto mutato dall'utente: la
// correttezza richiede fresh-when-online, quindi network-first (cache solo come
// fallback offline). Con stale-while-revalidate la prima richiesta DOPO una
// mutazione (create/delete/edit/visibility) tornava la copia stantia: l'item
// cancellato riappariva e il nuovo non compariva finché un reload non
// rinfrescava la cache in background. startsWith → copre anche i sub-path GET
// (es. /api/teacher/content/{id}, /api/risdoc/templates/{id}/instances).
const API_NETWORK_FIRST = [
    "/api/study/content.json",
    "/api/teacher/content",
    "/api/risdoc/templates",
];

// ----------------- Lifecycle -----------------

self.addEventListener("install", (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(STATIC_CACHE);
            // Tollerante a 404 individuali (es. critical.css mancante in dev)
            await Promise.allSettled(
                STATIC_PRECACHE.map((url) => cache.add(url).catch(() => null)),
            );
            self.skipWaiting();
        })(),
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((k) => k.startsWith("pantedu-") && !k.endsWith(`-${CACHE_VERSION}`))
                    .map((k) => caches.delete(k)),
            );
            await self.clients.claim();
        })(),
    );
});

// ----------------- Fetch routing -----------------

self.addEventListener("fetch", (event) => {
    const req = event.request;
    if (req.method !== "GET") return;

    const url = new URL(req.url);

    // Same-origin only (no cache di terze parti)
    if (url.origin !== self.location.origin) return;

    // Never-cache blacklist
    if (NEVER_CACHE_PATHS.some((p) => url.pathname.startsWith(p))) return;

    // Auth header presente → mai cache
    if (req.headers.has("Authorization")) return;

    // manifest.json NON versionato (cambia a ogni deploy) → network-first,
    // mai servire stale (altrimenti i lazy-loader caricano bundle col vecchio hash).
    if (url.pathname === "/build/manifest.json") {
        event.respondWith(networkFirst(req, STATIC_CACHE, 3000));
        return;
    }

    // Versioned build assets (hash nel filename) → cache-first lungo (sicuro:
    // nuovo hash = nuova URL = cache miss = fetch fresco).
    if (url.pathname.startsWith("/build/")) {
        event.respondWith(cacheFirst(req, STATIC_CACHE));
        return;
    }

    // Moduli JS RAW (/js/, NON versionati) → stale-while-revalidate: serve la
    // copia in cache subito ma riscarica in background, così dopo un deploy il
    // refresh successivo prende il codice nuovo (niente più stale infinito).
    if (url.pathname.startsWith("/js/") && url.pathname.endsWith(".js")) {
        event.respondWith(staleWhileRevalidate(req, STATIC_CACHE));
        return;
    }

    // Altri static CSS/font/img (+ js fuori da /js/) → cache-first
    if (/\.(css|js|woff2?|ttf|svg|png|jpe?g|webp|avif|ico)$/i.test(url.pathname)) {
        event.respondWith(cacheFirst(req, STATIC_CACHE));
        return;
    }

    // API GET di liste mutate dall'utente → network-first (fresh-when-online).
    if (API_NETWORK_FIRST.some((p) => url.pathname.startsWith(p))) {
        event.respondWith(networkFirst(req, API_CACHE, 3000));
        return;
    }

    // Altre API GET (dati semi-statici: curriculum, config, ...) → SWR.
    if (url.pathname.startsWith("/api/")) {
        event.respondWith(staleWhileRevalidate(req, API_CACHE));
        return;
    }

    // HTML navigation.
    if (req.mode === "navigate" || req.headers.get("Accept")?.includes("text/html")) {
        // Pagine il cui MARKUP dipende dallo stato di autenticazione (home guest
        // vs loggato; aree docente/studente): MAI servirle dalla cache. Una
        // versione AUTENTICATA stantia servita a un guest dopo il logout fa
        // partire chiamate teacher → rimbalzo a /login (e leak privacy). Quindi
        // network-only (fallback offline.html solo se davvero offline), niente
        // caching → il server rende sempre lo stato auth corretto.
        if (isAuthDependentNav(url.pathname)) {
            event.respondWith(networkOnly(req));
            return;
        }
        // Altre pagine (legali, statiche) → network-first con fallback cache.
        event.respondWith(networkFirst(req, PAGES_CACHE, 3000));
    }
});

// Pagine auth-dipendenti: home esatta + aree riservate/contenuto.
const AUTH_DEPENDENT_PREFIXES = ["/studio/", "/area-docente/", "/me/", "/teacher/"];
function isAuthDependentNav(pathname) {
    return pathname === "/" || AUTH_DEPENDENT_PREFIXES.some((p) => pathname.startsWith(p));
}

async function networkOnly(req) {
    try {
        return await fetch(req);
    } catch (_) {
        return (await caches.match(OFFLINE_URL)) || Response.error();
    }
}

// ----------------- Strategies -----------------

async function cacheFirst(req, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(req);
    if (cached) return cached;
    try {
        const res = await fetch(req);
        if (res.ok) cache.put(req, res.clone()).catch(() => {});
        return res;
    } catch (e) {
        return cached || new Response("offline", { status: 504 });
    }
}

async function networkFirst(req, cacheName, timeoutMs) {
    const cache = await caches.open(cacheName);
    try {
        const fresh = await Promise.race([
            fetch(req),
            new Promise((_, rej) => setTimeout(() => rej(new Error("timeout")), timeoutMs)),
        ]);
        if (fresh.ok) cache.put(req, fresh.clone()).catch(() => {});
        return fresh;
    } catch (e) {
        const cached = await cache.match(req);
        if (cached) return cached;
        // Final fallback: offline page
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        return new Response("Offline", { status: 504, headers: { "Content-Type": "text/plain" } });
    }
}

async function staleWhileRevalidate(req, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(req);
    const fetchPromise = fetch(req)
        .then((res) => {
            if (res.ok) cache.put(req, res.clone()).catch(() => {});
            return res;
        })
        .catch(() => cached);
    return cached || fetchPromise;
}

// ----------------- Message handler -----------------

self.addEventListener("message", (event) => {
    if (event.data?.type === "SKIP_WAITING") {
        self.skipWaiting();
    } else if (event.data?.type === "CLEAR_CACHE") {
        event.waitUntil(
            caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))),
        );
    } else if (event.data?.type === "PURGE_AUTH") {
        // Logout boundary: elimina le cache di PAGINE e API (possono contenere
        // contenuti AUTENTICATI cachati mentre si era loggati). Mantiene la
        // cache statica (asset). Evita che, dopo il logout, una pagina
        // autenticata stantia venga servita a un utente guest → richieste a
        // endpoint teacher → 302 /login → "rimbalzo" alla pagina di login.
        // È anche corretto per privacy (no contenuti autenticati per il
        // prossimo utente su computer condiviso).
        event.waitUntil(Promise.all([
            caches.delete(PAGES_CACHE),
            caches.delete(API_CACHE),
        ]));
    }
});
