import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";

/**
 * Chi rilegge dal server le etichette delle categorie (14/9/2026).
 *
 * L'endpoint `/api/teacher/category-labels` sta nella zona dei docenti
 * (`app/Config/roles.php`: teacher, administrator). Da quando
 * l'ospite con la credenziale di classe ha una barra completa, la chiamata
 * partiva anche per lui e riceveva 401. La guardia chiede la zona che il server
 * scrive sul body (`data-fm-zones`, dal 14/9/2026): prima guardava il ruolo, e
 * nel database l'amministratore è `administrator`, non `admin`. Qui si provano
 * i due versi.
 *
 * Il modulo tiene in memoria la prima idratazione: ogni prova lo reimporta.
 */
// Il body come lo scrive il server: il ruolo e le zone (Auth::zone). Dal 14/9 la
// guardia legge la zona; il ruolo c'è per mostrare che non basta più.
const ZONE = {
    teacher: "public student teacher",
    administrator: "public student teacher admin",
    student: "public student",
    guest: "public",
    "": "",
};

async function idrataCome(ruolo) {
    document.body.dataset.fmRole = ruolo;
    document.body.dataset.fmZones = ZONE[ruolo];
    vi.resetModules();
    const { hydrate } = await import("../../js/modules/features/sidepage-category-labels.js");
    await hydrate();
}

function chiamateAlleEtichette(fetchFinta) {
    return fetchFinta.mock.calls.filter(([url]) => String(url).startsWith("/api/teacher/category-labels")).length;
}

describe("sidepage-category-labels, hydrate", () => {
    let fetchFinta;
    beforeEach(() => {
        fetchFinta = vi.fn(async () => new Response(JSON.stringify({ ok: true, labels: {} }), {
            status: 200, headers: { "Content-Type": "application/json" },
        }));
        vi.stubGlobal("fetch", fetchFinta);
        document.body.innerHTML = "";
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        delete document.body.dataset.fmRole;
        delete document.body.dataset.fmZones;
    });

    for (const ruolo of ["teacher", "administrator"]) {
        it(`il ruolo ${ruolo}, che l'endpoint lascia passare, rilegge le etichette`, async () => {
            await idrataCome(ruolo);
            expect(chiamateAlleEtichette(fetchFinta)).toBe(1);
        });
    }

    for (const ruolo of ["student", "guest", ""]) {
        it(`il ruolo «${ruolo}» non chiama l'endpoint dei docenti`, async () => {
            await idrataCome(ruolo);
            expect(chiamateAlleEtichette(fetchFinta)).toBe(0);
        });
    }
});
