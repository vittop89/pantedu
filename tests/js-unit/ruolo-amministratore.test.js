import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";

/**
 * L'amministratore usa gli elenchi del docente (14/9/2026; il collaboratore, che
 * c'era, è stato tolto il 15/9/2026).
 *
 * Due moduli sceglievano l'endpoint confrontando `data-fm-role` con "teacher" o
 * "admin". In database l'amministratore è `administrator` (e il collaboratore era
 * `collaborator`): finivano sull'intestazione e sull'elenco delle verifiche dello
 * studente, mentre le rotte del docente li lasciano passare (zona «teacher» di
 * app/Config/roles.php). Ora i moduli chiedono la zona.
 *
 * Ogni caso imposta il body come lo scrive il server: il ruolo in
 * `data-fm-role` e le zone in `data-fm-zones`. Le zone per ruolo qui sotto sono
 * il contratto provato dal lato PHP in tests/Unit/Core/AuthZoneTest.php.
 * Controprova fatta il 14/9: con i moduli di prima cadono i casi di
 * amministratore e collaboratore.
 */
const COME_LO_SCRIVE_IL_SERVER = {
    guest:         "public",
    student:       "public student",
    teacher:       "public student teacher",
    administrator: "public student teacher admin istituto",
};

function comePagina(ruolo) {
    document.body.dataset.fmRole = ruolo;
    document.body.dataset.fmZones = COME_LO_SCRIVE_IL_SERVER[ruolo];
}

afterEach(() => {
    vi.unstubAllGlobals();
    delete document.body.dataset.fmRole;
    delete document.body.dataset.fmZones;
});

describe("upbar-controls: l'intestazione della pagina", () => {
    let fetchVera;
    beforeEach(() => {
        fetchVera = vi.fn(async () => new Response("{}", { status: 200, headers: { "Content-Type": "application/json" } }));
        vi.stubGlobal("fetch", fetchVera);
        document.body.innerHTML = "";
    });

    async function chiediIntestazioneCome(ruolo) {
        comePagina(ruolo);
        vi.resetModules();
        await import("../../js/modules/features/upbar-controls.js");
        fetchVera.mockClear();
        await window.fetch("/api/teacher/header-page.json");
        return fetchVera.mock.calls.map(([url]) => String(url));
    }

    for (const ruolo of ["teacher", "administrator"]) {
        it(`${ruolo}: il proprio modello, da /api/teacher/header-page.json`, async () => {
            expect(await chiediIntestazioneCome(ruolo)).toEqual(["/api/teacher/header-page.json"]);
        });
    }

    for (const ruolo of ["student", "guest"]) {
        it(`${ruolo}: il modello del docente di riferimento, da /api/study/header-page.json`, async () => {
            expect(await chiediIntestazioneCome(ruolo)).toEqual(["/api/study/header-page.json"]);
        });
    }
});

describe("verifica-documents-sidepage: l'elenco delle verifiche", () => {
    beforeEach(() => {
        vi.stubGlobal("fetch", vi.fn(async () => new Response("{}", { status: 200 })));
        document.body.innerHTML = "";
    });

    async function elencoCome(ruolo) {
        comePagina(ruolo);
        vi.resetModules();
        const { elencoDelleVerifiche } = await import("../../js/modules/features/verifica-documents-sidepage.js");
        return elencoDelleVerifiche();
    }

    for (const ruolo of ["teacher", "administrator"]) {
        it(`${ruolo}: le proprie verifiche, da /api/verifica/list`, async () => {
            expect(await elencoCome(ruolo)).toBe("/api/verifica/list");
        });
    }

    for (const ruolo of ["student", "guest"]) {
        it(`${ruolo}: le verifiche pubblicate, da /api/study/verifica/list`, async () => {
            expect(await elencoCome(ruolo)).toBe("/api/study/verifica/list");
        });
    }
});
