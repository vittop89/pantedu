/**
 * ADR-024 (rework editor custom) — l'editing del documento custom usa la STESSA
 * interfaccia di risdoc: N card <fm-risdoc-pt-section> (collassabili, chrome/dark
 * condivisi) invece dell'editor unico. Verifica:
 *
 *   #3 DATA-LOSS (critico): Modifica → Salva → reload → il contenuto NON si perde
 *       (split body_pt in sezioni + merge in salvataggio, lossless).
 *   #2 NUOVA SEZIONE: "+ Nuova sezione" crea una NUOVA card e porta il focus
 *       nell'<input> titolo della card appena creata.
 *   UI: in edit compaiono le card sezione (.fm-section-wrap) con il loro editor.
 *
 * Credenziali via env (no PII) — vedi .env.local (gitignored).
 */
const { test, expect } = require("@playwright/test");

const TEACHER = process.env.E2E_TEACHER_USER || "superadmin";
const PASS    = process.env.E2E_TEACHER_PASS || "";

async function login(page) {
    if (!PASS) { test.skip(true, "Set E2E_TEACHER_PASS"); return; }
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
}

async function createCustom(page, topic, bodyPt) {
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const r = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "lab", subject: "MAT", indirizzo: "SCI", classe: "3",
            topic, title: topic, visibility: "draft",
            metadata: JSON.stringify({ layout: "custom", body_pt: bodyPt }),
        }).toString(),
    });
    return { id: (await r.json())?.id, csrf };
}

async function gotoDoc(page, topic) {
    await page.goto(`/studio/lab/SCI/3/MAT/${topic}`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForFunction(() => {
        const el = document.querySelector("fm-pt-document");
        return el && el.querySelector(".fm-doc-topbar--custom");
    }, { timeout: 8000 });
}

async function clickTopbar(page, re) {
    await page.evaluate((src) => {
        const el = document.querySelector("fm-pt-document");
        [...el.querySelectorAll(".fm-doc-topbar__btn")]
            .find((b) => new RegExp(src).test(b.textContent))?.click();
    }, re.source);
}

async function enterEdit(page) {
    await clickTopbar(page, /Modifica/);
    // Attendi che almeno una card sezione abbia il proprio editor montato.
    await page.waitForFunction(() => {
        const host = document.querySelector("fm-pt-document");
        const card = host?.querySelector("fm-risdoc-pt-section");
        const ed = card?.shadowRoot?.querySelector("fm-risdoc-pt-editor");
        return !!ed?._editor;
    }, { timeout: 10000 });
}

/** body_pt corrente ricostruito dall'host (merge sezioni) in edit mode. */
async function currentBody(page) {
    return page.evaluate(() => document.querySelector("fm-pt-document")._currentBodyPt());
}

test.describe("ADR-024 rework — editor documento custom (card sezioni)", () => {

    test("#3 no data-loss: Modifica → Salva → reload → contenuto presente", async ({ page }, testInfo) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "rework-dl-" + Date.now().toString(36);
        const initialBody = [
            { _type: "sectionHeader", title: "Introduzione", level: 2 },
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "Testo iniziale ", marks: [] },
                { _type: "span", text: "in grassetto", marks: ["strong"] },
            ] },
            { _type: "staticContent", title: "Nota", level: 3, body: "<p>blocco statico</p>" },
        ];
        const { id, csrf } = await createCustom(page, topic, initialBody);
        expect(id).toBeTruthy();

        try {
            await gotoDoc(page, topic);

            // 1. Entra in edit: compaiono le card sezione col contenuto reale.
            await enterEdit(page);
            const cards = await page.evaluate(() => {
                const host = document.querySelector("fm-pt-document");
                const list = [...host.querySelectorAll("fm-risdoc-pt-section")];
                return {
                    count: list.length,
                    hasWrap: list.every((c) => !!c.shadowRoot?.querySelector(".fm-section-wrap")),
                };
            });
            expect(cards.count, "almeno una card sezione").toBeGreaterThanOrEqual(1);
            expect(cards.hasWrap, "card con chrome .fm-section-wrap (UI risdoc)").toBeTruthy();

            const onEdit = await currentBody(page);
            const onEditJson = JSON.stringify(onEdit);
            expect(onEditJson, "contenuto presente in edit").toContain("Introduzione");
            expect(onEditJson).toContain("blocco statico");

            await page.screenshot({ path: testInfo.outputPath("edit-cards.png"), fullPage: true });

            // 2. Modifica il titolo della 1ª sezione (così il salvataggio cambia
            //    davvero il body — il server risponde 404 se nulla è cambiato,
            //    quirk pre-esistente: rowCount()>0). Poi salva.
            await page.evaluate(() => {
                const card = document.querySelector("fm-pt-document fm-risdoc-pt-section");
                const ed = card.shadowRoot.querySelector("fm-risdoc-pt-editor");
                const input = ed.shadowRoot.querySelector(".pt-section-title-input");
                input.focus();
                input.value = `${input.value} [EDIT]`;
                input.dispatchEvent(new Event("blur")); // NodeView commit titolo
            });
            await page.waitForTimeout(300); // pt-change debounce (150ms) + merge

            const [saveResp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/api/teacher/content/${id}/update`) && r.request().method() === "POST",
                    { timeout: 8000 },
                ),
                clickTopbar(page, /Salva/),
            ]);
            expect(saveResp.ok(), "POST update 2xx").toBeTruthy();

            // 3. Persistenza reale lato server (metadata.body_pt).
            const meta = await page.evaluate(async (cid) => {
                const r = await fetch(`/api/teacher/content/${cid}`, { credentials: "same-origin" });
                const j = await r.json();
                const m = j.content?.metadata || (() => {
                    try { return JSON.parse(j.content?.metadata_json || "{}"); } catch { return {}; }
                })();
                return m.body_pt ?? null;
            }, id);
            expect(Array.isArray(meta), "body_pt persistito è un array").toBeTruthy();
            const metaJson = JSON.stringify(meta);
            expect(metaJson, "persistito: titolo modificato").toContain("Introduzione [EDIT]");
            expect(metaJson, "persistito: 'blocco statico' (non perso)").toContain("blocco statico");

            // 4. Reload + re-edit: TUTTO il contenuto è ancora lì (no data-loss).
            await gotoDoc(page, topic);
            await enterEdit(page);
            const afterJson = JSON.stringify(await currentBody(page));
            expect(afterJson, "dopo reload: titolo modificato").toContain("Introduzione [EDIT]");
            expect(afterJson, "dopo reload: blocco statico").toContain("blocco statico");
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("seed pulito: prima sezione = sectionHeader (no staticContent) + '§ Sezione' nascosto", async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "rework-seed-" + Date.now().toString(36);
        // Seed come quello del nuovo documento custom (sidepage-modal-content):
        // sezione pulita (header + corpo), NON staticContent HTML grezzo.
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "sectionHeader", title: "Nuova sezione", level: 2 },
            { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
        ]);
        expect(id).toBeTruthy();
        try {
            await gotoDoc(page, topic);
            await enterEdit(page);
            const res = await page.evaluate(() => {
                const host = document.querySelector("fm-pt-document");
                const card0 = host.querySelector("fm-risdoc-pt-section");
                const tb = host.querySelector("fm-risdoc-pt-toolbar");
                const labels = [...tb.shadowRoot.querySelectorAll("button")].map((b) => b.textContent.trim());
                return {
                    firstType: (card0?.section?.default || [])[0]?._type,
                    hasInlineSezione: labels.some((l) => /^§ Sezione$/.test(l)),
                    hasNuovaSezione: labels.some((l) => /Nuova sezione/.test(l)),
                };
            });
            expect(res.firstType, "prima sezione inizia con sectionHeader (stile risdoc)").toBe("sectionHeader");
            expect(res.hasInlineSezione, "'§ Sezione' inline NASCOSTO nel custom (no annidamento)").toBeFalsy();
            expect(res.hasNuovaSezione, "'➕ Nuova sezione' presente (crea card)").toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("#2 '+ Nuova sezione' crea una card e ne focalizza il titolo", async ({ page }, testInfo) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "rework-fok-" + Date.now().toString(36);
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "sectionHeader", title: "Sezione A", level: 2 },
            { _type: "block", style: "normal", children: [{ _type: "span", text: "contenuto", marks: [] }] },
        ]);
        expect(id).toBeTruthy();

        try {
            await gotoDoc(page, topic);
            await enterEdit(page);

            const before = await page.evaluate(() =>
                document.querySelectorAll("fm-pt-document fm-risdoc-pt-section").length);

            // Click "+ Nuova sezione" nella toolbar (shadow DOM).
            await page.evaluate(() => {
                const tb = document.querySelector("fm-pt-document fm-risdoc-pt-toolbar");
                const btn = [...tb.shadowRoot.querySelectorAll("button")]
                    .find((b) => /Nuova sezione/.test(b.textContent));
                btn?.click();
            });

            // La nuova card monta async + il focus arriva via setInterval.
            await page.waitForFunction((n) =>
                document.querySelectorAll("fm-pt-document fm-risdoc-pt-section").length === n + 1,
                before, { timeout: 6000 });
            await page.waitForTimeout(400);

            const res = await page.evaluate(() => {
                const cards = [...document.querySelectorAll("fm-pt-document fm-risdoc-pt-section")];
                const last = cards[cards.length - 1];
                const ed = last?.shadowRoot?.querySelector("fm-risdoc-pt-editor");
                const active = ed?.shadowRoot?.activeElement;
                return {
                    cardCount: cards.length,
                    activeIsTitleInput: !!active && active.classList?.contains("pt-section-title-input"),
                };
            });
            await page.screenshot({ path: testInfo.outputPath("new-section-card.png"), fullPage: true });
            expect(res.cardCount, "card aggiunta").toBe(before + 1);
            expect(res.activeIsTitleInput, "focus nell'input titolo della nuova card").toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });
});
