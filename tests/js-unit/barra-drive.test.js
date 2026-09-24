import { describe, it, expect, beforeAll } from "vitest";

/**
 * La barra della sessione del docente porta il comando di Drive solo con Drive
 * acceso sull'installazione (ADR-038, 14/9/2026). Le altre destinazioni ci
 * sono sempre. Nei due versi, sulla stessa funzione che la pagina chiama.
 */

function barraDelDocente(stato) {
    document.body.innerHTML = "";
    const banner = document.createElement("div");
    banner.className = "fm-session-banner fm-session-banner--teacher";
    banner.dataset.fmDrive = stato;
    const links = document.createElement("div");
    links.className = "fm-session-links";
    banner.appendChild(links);
    document.body.appendChild(banner);
    return links;
}

describe("barra della sessione e stato di Drive", () => {
    beforeAll(async () => {
        barraDelDocente("spento");
        await import("../../js/modules/features/drive-sync-buttons.js");
    });

    it("con Drive spento non c'è il comando di Drive, ma le altre destinazioni sì", () => {
        const links = barraDelDocente("spento");
        window.FM.DriveSyncButtons.injectGlobalSync();
        expect(links.querySelector(".fm-session-drive-sync")).toBeNull();
        expect(links.querySelector(".fm-session-local-sync")).not.toBeNull();
        expect(links.querySelector(".fm-session-github-sync")).not.toBeNull();
        expect(links.querySelector(".fm-session-sync-all")).not.toBeNull();
    });

    it("con Drive guasto nemmeno", () => {
        const links = barraDelDocente("guasto");
        window.FM.DriveSyncButtons.injectGlobalSync();
        expect(links.querySelector(".fm-session-drive-sync")).toBeNull();
        expect(links.querySelector(".fm-session-local-sync")).not.toBeNull();
    });

    it("con Drive acceso il comando di Drive c'è, insieme agli altri", () => {
        const links = barraDelDocente("acceso");
        window.FM.DriveSyncButtons.injectGlobalSync();
        expect(links.querySelector(".fm-session-drive-sync")).not.toBeNull();
        expect(links.querySelector(".fm-session-local-sync")).not.toBeNull();
        expect(links.querySelector(".fm-session-sync-all")).not.toBeNull();
    });
});
