/**
 * Pantedu Service Worker — Phase Roadmap 12.
 *
 * Strategy:
 *   - Cache-first  per asset versioned (hash in filename, /build/*)
 *   - Network-first per HTML routes (fallback to cache after 3s timeout)
 *   - Network-first per /api/* GET (fresco quando c'è rete, cache se offline);
 *     stale-while-revalidate solo per le rotte in API_STALE_OK (oggi nessuna)
 *   - NEVER cache: POST/PUT/DELETE, i percorsi in NEVER_CACHE_PATHS (/auth/*,
 *     /admin/*, /api/admin/*, /accesso-classe*, /teacher/drive/* e gli altri
 *     scritti lì), e ogni risposta che il server marca `no-store`
 *
 * Che cosa si conserva (2026-09-23, regola unica in `siPuoConservare`, che
 * vale all'ingresso in cache e alla consegna, in tutte e tre le strategie):
 *   - sempre e solo risposte riuscite (`ok`: niente 500, niente risposte
 *     opache) e mai ciò che il server marca `no-store`;
 *   - sotto /api/, in più, **solo** il JSON che il server dichiara
 *     esplicitamente conservabile con `Cache-Control: public`, e mai se dice
 *     anche `private`. Tutto il resto va in rete; senza rete, e senza una copia
 *     così, torna il 503 in JSON. Oggi nessuna API si dichiara `public`, e
 *     quindi la cache delle API resta vuota: è voluto. I JSON dei docenti
 *     (`private, max-age=…` con ETag, lo studio, /api/sidebar/config) non si
 *     conservano: il progetto ha già perso un salvataggio per un client che
 *     riscriveva partendo da una copia vecchia (TeacherContentController::show,
 *     10/9), e una copia stantia servita dopo dieci secondi di attesa è
 *     esattamente quello;
 *   - pagine HTML e asset statici: tutto ciò che non è `no-store`.
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
 *   - Mai cache se la risposta dice `Cache-Control: no-store`, e sotto /api/
 *     solo se dice `public` (vedi sopra e `siPuoConservare`); le cache di
 *     pagine e API si svuotano al logout, alla sessione scaduta e su ogni
 *     pagina resa per un ospite (`avvia` in sw-register.js)
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
// 2026-09-07 — bump v11→v12, e la regola si inverte. Dal 2026-06-09 gli
// endpoint di lista erano network-first ma per ELENCO: chi ne aggiungeva uno
// nuovo doveva ricordarsi di metterlo in lista, e `/api/verifica/*` non c'era.
// Risultato: cancellavi una verifica, il server la cancellava davvero, e il
// pannello la rimetteva in pagina due millisecondi dopo — servita dal service
// worker, non dalla rete (`deliveryType: "cache-storage"`), nonostante il
// server rispondesse `Cache-Control: no-store`. Trovato dalla suite in
// integrazione continua, dove il server più lento allarga la finestra.
// Adesso: **tutte** le GET sotto /api/ sono network-first, e la cache resta
// solo come rete di sicurezza offline. Lo stale-while-revalidate va chiesto
// caso per caso in API_STALE_OK, per i dati che stantii non fanno danno.
// 2026-09-08 — bump v12→v13: le regole nuove impediscono di avvelenare la
// cache con risposte non-JSON sotto /api/, ma non ripuliscono quelle già
// dentro. Cambiare versione le manda via all'attivazione.
//
// 2026-09-21 — bump v13→v14, e la ragione insegna qualcosa. Gli asset sotto
// /build/ si servono dalla cache per primi, «tanto l'impronta è nel nome:
// contenuto nuovo, indirizzo nuovo». Vero per il contenuto, falso per le
// **intestazioni**: pdf.worker.min.*.mjs veniva servito con il tipo sbagliato
// (application/octet-stream, vedi docker/nginx.conf), e correggendo il tipo i
// byte non cambiano — stesso nome, stessa copia in cache, stesso errore per
// sempre. Chi aveva aperto il sito prima della correzione se la sarebbe
// portata dietro. Quando cambia il **modo** in cui un file è servito, e non il
// file, la versione della cache va cambiata a mano: è l'unica cosa che le
// manda via.
//
// 2026-09-23 — bump v14→v15, per due difetti che si coprivano a vicenda
// (revisione architetturale del 23/9, A-72 e A-73).
//
// Il primo: il `no-store` del server non contava niente. Il QR del
// portachiavi di classe (/accesso-classe/pacchetto.svg, `no-store`) andava in
// cache-first come un'icona qualunque: dopo «Esci» restava lì, con gettoni
// ancora validi, per chiunque usasse quel browser dopo. E sotto /api/ la
// regola scritta metteva in cache ogni GET riuscita, `no-store` o no, anche
// sotto /api/admin/: su un PC di scuola condiviso, senza rete, il JSON di chi
// c'era prima sarebbe andato a chi arriva dopo.
//
// Il secondo lo nascondeva: dal 2026-09-08 `eJson` cercava «json» fra due
// caratteri di backspace veri (0x08) — i due `\b` dell'espressione erano
// diventati caratteri passando da una shell — e non riconosceva nessun JSON.
// Sotto /api/ non entrava niente, e il ripiego offline del docente non c'era
// più: senza rete ogni API rispondeva 503. Correggere solo l'espressione
// avrebbe aperto A-72 per intero, e rimesso in cache i JSON privati dei
// docenti; adesso l'espressione è giusta, il `no-store` si rispetta, e sotto
// /api/ si conserva solo ciò che il server dichiara `public` (la regola è in
// cima e in `siPuoConservare`). Il cambio di versione butta via ciò che è già
// dentro, il QR nella cache statica per primo. ESLint ora legge questo file
// con `no-control-regex` (eslint.config.mjs): i due backspace li avrebbe
// fermati.
const CACHE_VERSION = "v15";
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

// 2026-09-23 — tolte `/api/vitals` e `/api/csrf`: non esistono (le misure
// web-vitals sono state tolte il 15/9, il gettone CSRF sta sotto /auth/, già
// qui). Un elenco con voci morte fa credere protetto ciò che non c'è.
const NEVER_CACHE_PATHS = [
    "/auth/",
    "/admin/",
    // 2026-09-23 — le API dell'amministrazione come le sue pagine: il
    // `no-store` le tiene già fuori, ma dati così non devono dipendere dal
    // fatto che ogni rotta nuova si ricordi l'intestazione.
    "/api/admin/",
    // 2026-09-23 — l'accesso per la classe: la pagina del portachiavi (con le
    // etichette delle credenziali e il gettone CSRF), l'ingresso da QR e il QR
    // del pacchetto (pacchetto.svg), che vale quanto le credenziali che
    // contiene. Mai in cache, mai un ripiego offline al posto della rete.
    "/accesso-classe",
    // PDF-Import: endpoint di polling (status) + immagini pagina = dinamici e
    // owner-gated → mai cache (no stale, no risposte servite senza credenziali).
    "/api/teacher/pdf-import/",
    // La PAGINA del tool: mai servirla stantia (entry bundle vecchio → preview rotto).
    "/teacher/pdf-import",
    "/logout",
    "/login",
    "/register",
    // 2026-09-15 — il collegamento a Google Drive (connect, callback): è un
    // passaggio di consegne con Google, e al suo posto una pagina offline non
    // serve a niente. Quel giorno il primo clic su «Collega Drive» ha mostrato
    // offline.html pur essendo arrivato al server; ricaricando è andato. Non
    // riprodotto (tests/js-unit/service-worker-percorsi.test.js): il SW si
    // tiene fuori, e se ricapita si vede l'errore vero del browser.
    "/teacher/drive/",
];

// 2026-09-07 — l'elenco ha cambiato senso: prima diceva «questi devono essere
// freschi» e tutto il resto era stantio per default; adesso dice «questi
// possono essere stantii senza far danno», e tutto il resto è fresco.
//
// Perché: un elenco di eccezioni si dimentica. Quello di giugno copriva
// contenuti, template e study, non `/api/verifica/*` — e su quelle rotte è
// tornato lo stesso identico difetto, cioè la verifica cancellata che
// ricompare. Con la regola invertita, una rotta nuova nasce corretta e la
// scelta di tollerare il stantio è esplicita, scritta qui, con la ragione.
//
// Dentro ci vanno solo dati che cambiano di rado e che, se vecchi di qualche
// secondo, non mostrano niente di falso: elenchi di configurazione, non
// contenuti del docente.
//
// 2026-09-23 — l'elenco è vuoto. Le due voci che c'erano, `/api/curriculum` e
// `/api/config`, non corrispondevano a nessuna rotta (il curriculum sta in
// /curriculum, fuori da /api/; la configurazione della barra in
// /api/sidebar/config): lo stale-while-revalidate non si applicava a niente.
// Chi ne aggiunge una, la aggiunge qui con la ragione, e la regola di
// `siPuoConservare` vale anche per lei: senza `Cache-Control: public` la sua
// risposta non entra in cache, e lo stale-while-revalidate non ha niente da
// servire.
const API_STALE_OK = [];

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

    // 2026-09-23 — i rami di /api/ vengono PRIMA di quello degli asset: il QR
    // di una credenziale (/api/teacher/credentials/{id}/qr.svg) e i file dei
    // modelli condivisi (/api/risdoc/shared/*.js, *.css) finiscono con
    // un'estensione da asset, e prima passavano dal cache-first qui sotto,
    // dove la regola di /api/ (solo il JSON public) non si applicava.
    // API GET dichiarate innocue se stantie → stale-while-revalidate.
    if (API_STALE_OK.some((p) => url.pathname.startsWith(p))) {
        event.respondWith(staleWhileRevalidate(req, API_CACHE));
        return;
    }

    // Tutte le altre API GET → network-first: la risposta che l'utente vede
    // descrive lo stato di adesso, e la cache serve solo se la rete non c'è.
    //
    // Dieci secondi e non tre: ripiegare presto sulla cache rimetterebbe in
    // pagina proprio i dati vecchi che questa regola serve a evitare, e un
    // server occupato — che genera una verifica, per dire — ci mette di più di
    // tre secondi a rispondere anche quando sta benissimo.
    if (url.pathname.startsWith("/api/")) {
        event.respondWith(networkFirst(req, API_CACHE, 10000));
        return;
    }

    // Altri static CSS/font/img (+ js fuori da /js/) → cache-first
    if (/\.(css|js|woff2?|ttf|svg|png|jpe?g|webp|avif|ico)$/i.test(url.pathname)) {
        event.respondWith(cacheFirst(req, STATIC_CACHE));
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
    const inCache = await cache.match(req);
    const cached = (inCache && siPuoConservare(inCache, false)) ? inCache : undefined;
    if (cached) return cached;
    try {
        const res = await fetch(req);
        if (siPuoConservare(res, false)) cache.put(req, res.clone()).catch(() => {});
        return res;
    } catch (e) {
        return cached || new Response("offline", { status: 504 });
    }
}

/**
 * Una risposta è davvero JSON? (`application/json`, con o senza parametri)
 *
 * 2026-09-23 — attenzione ai due `\b`: fino a oggi erano due backspace veri
 * (vedi il cambio v14→v15 in cima), e la funzione rispondeva sempre no.
 * tests/js-unit/service-worker-percorsi.test.js lo sorveglia.
 */
function eJson(risposta) {
    return /\bjson\b/i.test(risposta.headers.get("Content-Type") || "");
}

/**
 * Le direttive di Cache-Control, per nome e in minuscolo: `private,
 * max-age=60` dà {"private", "max-age"}; `private="Set-Cookie"` conta come
 * `private`.
 * @returns {Set<string>}
 */
function direttive(risposta) {
    return new Set(
        (risposta.headers.get("Cache-Control") || "")
            .split(",")
            .map((d) => d.split("=")[0].trim().toLowerCase())
            .filter(Boolean),
    );
}

/**
 * La risposta chiede di non essere conservata? Conta la direttiva `no-store`,
 * da sola o con altre (`private, no-store`, `no-store, no-cache,
 * must-revalidate`).
 */
function vietaLaCache(risposta) {
    return direttive(risposta).has("no-store");
}

/**
 * Sotto /api/: il server dichiara la risposta conservabile? Solo con
 * `public`, e mai se dice anche `private` (la regola in cima al file).
 */
function dichiaraPubblica(risposta) {
    const d = direttive(risposta);
    return d.has("public") && !d.has("private");
}

/**
 * 2026-09-23 — la regola unica per entrare in cache, e per uscirne: una
 * risposta riuscita, che non chiede `no-store`; sotto /api/, in più, JSON e
 * dichiarata `public`. Si guarda prima di metterla dentro e prima di
 * consegnarla, come insegna il 2026-09-08 in `networkFirst`: un controllo
 * solo all'ingresso non copre ciò che era già entrato.
 *
 * Fino a oggi il `no-store` si ignorava (revisione del 23/9, A-72 e A-73). E
 * `no-store` è quasi tutto: la sessione PHP (cache_limiter `nocache`) lo mette
 * su ogni risposta che non ne dichiari un'altra — misurato sul server locale
 * su /api/institutes, /curriculum, /accesso-classe e /. Restano in cache gli
 * asset statici e le pagine che non lo dicono. Sotto /api/ nemmeno ciò che si
 * dichiara `private, max-age=…` (`withETag`, lo studio, la configurazione
 * della barra): sono i dati del docente, e una loro copia vecchia è quella da
 * cui un client riscrive perdendo l'ultimo salvataggio. Il ripiego offline
 * delle API vale solo per ciò che il server dichiara `public`.
 *
 * @param {Response} risposta
 * @param {boolean} sottoApi  la richiesta sta sotto /api/
 */
function siPuoConservare(risposta, sottoApi) {
    if (!risposta.ok || vietaLaCache(risposta)) return false;
    return !sottoApi || (eJson(risposta) && dichiaraPubblica(risposta));
}

async function networkFirst(req, cacheName, timeoutMs) {
    const cache = await caches.open(cacheName);
    const sottoApi = new URL(req.url).pathname.startsWith("/api/");
    try {
        const fresh = await Promise.race([
            fetch(req),
            new Promise((_, rej) => setTimeout(() => rej(new Error("timeout")), timeoutMs)),
        ]);
        // 2026-09-08 — sotto /api/ si mette in cache solo ciò che è JSON.
        // Senza questo, una 200 che JSON non è avvelena la cache: succede
        // quando la sessione cade e il server risponde alle API con la pagina
        // di accesso, 200 e HTML. Da quel momento ogni ripiego su quella rotta
        // consegna una pagina a chi aspetta dati.
        // 2026-09-23 — e niente di ciò che il server marca `no-store`, e
        // sotto /api/ solo ciò che dichiara `public`: la regola intera è in
        // `siPuoConservare`.
        if (siPuoConservare(fresh, sottoApi)) cache.put(req, fresh.clone()).catch(() => {});
        return fresh;
    } catch (e) {
        const cached = await cache.match(req);
        // 2026-09-08 — e non si consegna nemmeno quel che c'è già in cache, se
        // JSON non è. Il controllo qui sotto c'era già, ma stava DOPO questo
        // ripiego e quindi non lo copriva: una cache avvelenata prima della
        // regola sopra passava lo stesso. Visto addosso, in un giro notturno:
        // «Unexpected token '<', "<!doctype "... is not valid JSON».
        if (cached && siPuoConservare(cached, sottoApi)) return cached;
        // A chi ha chiesto JSON si risponde JSON, sempre. Prima qui tornava
        // `offline.html`: il client provava a leggerlo come JSON, falliva con
        // un errore che non nomina la causa («Unexpected token <»), e
        // l'elenco restava vuoto senza dire perché. Un 503 con un corpo JSON
        // invece si riconosce, e chi chiama può dirlo all'utente.
        if (sottoApi) {
            return new Response(JSON.stringify({ ok: false, error: "offline" }), {
                status: 503,
                headers: { "Content-Type": "application/json" },
            });
        }
        // Final fallback: offline page
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        return new Response("Offline", { status: 504, headers: { "Content-Type": "text/plain" } });
    }
}

async function staleWhileRevalidate(req, cacheName) {
    const cache = await caches.open(cacheName);
    // 2026-09-08 — stessa regola di `networkFirst`: sotto /api/ si mette in
    // cache e si consegna solo ciò che è JSON. Una 200 che JSON non è — la
    // pagina di accesso servita quando la sessione cade — avvelenerebbe la
    // cache, e da quel momento questa rotta risponderebbe con una pagina a chi
    // aspetta dati, senza che nessuno se ne accorga.
    // 2026-09-23 — la regola intera è in `siPuoConservare`, all'ingresso e
    // alla consegna: niente `no-store`, e sotto /api/ solo il JSON `public`.
    const sottoApi = new URL(req.url).pathname.startsWith("/api/");
    const inCache = await cache.match(req);
    const cached = (inCache && siPuoConservare(inCache, sottoApi)) ? inCache : undefined;
    const fetchPromise = fetch(req)
        .then((res) => {
            if (siPuoConservare(res, sottoApi)) cache.put(req, res.clone()).catch(() => {});
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
