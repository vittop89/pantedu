import { describe, it, expect, vi, beforeAll, afterEach } from "vitest";
import { addInlineItemActions } from "../../js/modules/features/sidepage-inline-actions.js";

/**
 * Il 📥 (ZIP TeX) nelle voci della barra (19/9/2026, analisi C-export-bodypt).
 *
 *   - Nelle sidepage Risorse docente e BES/DSA non compariva mai: il loader
 *     risdoc non scriveva data-has-body-pt, nemmeno per i documenti
 *     Personalizzabili, gli unici per cui l'esportazione ha senso.
 *   - Il pulsante si chiamava «📥» per chi usa un lettore di schermo: adesso
 *     dice che cosa scarica.
 *
 * Quando c'è un corpo da scaricare lo decide il server (App\Support\
 * RigheDellaBarra, tests/Unit/Support/RigheDellaBarraTest.php): qui si guarda
 * che il browser lo legga.
 */

function risposta(corpo) {
    return new Response(JSON.stringify(corpo), { status: 200, headers: { "Content-Type": "application/json" } });
}

const RIGHE = [
    { id: 51, content_type: "document", title: "Piano personalizzato", topic: "1.0", visibility: "draft",
      metadata_json: JSON.stringify({ category: "bes", layout: "custom" }), has_body_pt: true, doc_roles: "" },
    { id: 52, content_type: "document", title: "Appena creato", topic: "1.1", visibility: "published",
      metadata_json: JSON.stringify({ category: "bes", layout: "custom" }), has_body_pt: false, doc_roles: "" },
];

beforeAll(async () => {
    globalThis.fetch = vi.fn(async (url) => {
        const u = new URL(String(url), "http://localhost");
        if (u.pathname === "/api/risdoc/templates") return risposta({ templates: [] });
        if (u.pathname === "/api/risdoc/teacher/instances") return risposta({ instances: [] });
        if (u.pathname === "/api/teacher/content") return risposta({ ok: true, rows: RIGHE });
        return risposta({ ok: true, rows: [] });
    });
    await import("../../js/modules/features/risdoc-sidepage.js");
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("sidepage BES/DSA", () => {
    it("la voce di un documento con un corpo ha data-has-body-pt=1, le altre 0", async () => {
        document.body.innerHTML = `
            <nav class="sidebar">
                <select id="sel-iis"><option value="SCI" selected>Scientifico</option></select>
                <select id="sel-cls"><option value="3" selected>3</option></select>
                <select id="sel-mater"><option value="MAT" selected>Matematica</option></select>
                <div class="fm-sb-panel" id="fm-sp-bes" data-sidepage="bes">
                    <button class="js-edit-section" type="button">✎</button>
                </div>
            </nav>`;

        await window.FM.RisdocSidepage.loadSidepage("bes", { panelId: "fm-sp-bes", origin: "strcomp", categories: ["bes", "altro"] });

        const conCorpo = document.querySelector('li[data-content-id="51"]');
        const senza = document.querySelector('li[data-content-id="52"]');
        expect(conCorpo?.dataset.hasBodyPt, "prima: l'attributo non c'era mai").toBe("1");
        expect(senza?.dataset.hasBodyPt).toBe("0");

        addInlineItemActions(document.getElementById("fm-sp-bes"), "bes");
        expect(conCorpo.querySelector(".fm-item-export")).not.toBeNull();
        expect(senza.querySelector(".fm-item-export")).toBeNull();
    });
});

describe("il nome del 📥", () => {
    it("dice che cosa scarica, senza il testo del segno della bozza", () => {
        document.body.innerHTML = `
            <div class="fm-sb-panel" id="fm-sp-eser" data-sidepage="eser">
                <ul class="fm-db-block">
                    <li class="fm-item--bozza" data-content-id="3" data-has-body-pt="1"><span class="fm-numarg">1.0</span>
                        <a href="#"><span class="fm-item-bozza"><span class="fm-sr-only">Bozza, non visibile: </span></span>Equazioni &lt;fratte&gt; · MAT</a></li>
                    <li data-content-id="4" data-has-body-pt="0"><a href="#">Mappa</a></li>
                </ul>
            </div>`;

        addInlineItemActions(document.getElementById("fm-sp-eser"), "esercizio");

        const zip = document.querySelector('li[data-content-id="3"] .fm-item-export');
        expect(zip?.getAttribute("aria-label")).toBe("Scarica ZIP TeX «Equazioni <fratte> · MAT»");
        expect(document.querySelector('li[data-content-id="4"] .fm-item-export')).toBeNull();
    });
});
