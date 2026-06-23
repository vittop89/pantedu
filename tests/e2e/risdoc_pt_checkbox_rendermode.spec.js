/**
 * Phase 24.23 — verify TeX output per i 3 renderMode del checkboxGroup.
 *
 * Per ogni mode ("all" | "checked-only" | "checked-inline") salva una
 * compilation con un field PT AST contenente un checkboxGroup di 3 items
 * (2 spuntati + 1 non), fetcha /tex, verifica l'output TeX atteso.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function fetchCsrf(page) {
    const r = await page.request.get("/auth/csrf");
    const j = await r.json();
    return j.token || "";
}

function mkCheckboxPt(renderMode) {
    return [{
        _type: "checkboxGroup",
        renderMode,
        items: [
            { state: "x", label: "Alfa" },
            { state: "_", label: "Beta" },
            { state: "x", label: "Gamma" },
        ],
    }];
}

async function saveCompilation(page, templateId, fields, key) {
    const csrf = await fetchCsrf(page);
    const res = await page.request.post(`/api/risdoc/templates/${templateId}/compilations`, {
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        data: new URLSearchParams({
            _csrf: csrf,
            compilation_key: key,
            label: "E2E RenderMode " + key,
            data: JSON.stringify({
                fields,
                state: { indirizzo: "sc", classe: "2s", disciplina: "MAT" },
            }),
            indirizzo: "sc", classe: "2s", disciplina: "MAT",
        }).toString(),
    });
    const body = await res.json().catch(() => ({}));
    return { res, id: body?.id };
}

async function fetchTexById(page, templateId, compilationId) {
    const r = await page.request.get(
        `/api/risdoc/templates/${templateId}/tex?compilation_id=${compilationId}`
    );
    return await r.text();
}

test.describe("checkboxGroup renderMode TeX output", () => {
    let templateId;

    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await loginAdmin(page);
        const j = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (j.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        templateId = piano?.id;
        await ctx.close();
    });

    test('"all" → itemize colonna con \\item[\\xcheckbox] / \\item[\\checkbox]', async ({ page }) => {
        test.setTimeout(30_000);
        if (!templateId) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);
        const { res: save, id } = await saveCompilation(page, templateId, {
            profilo_classe: mkCheckboxPt("all"),
        }, "e2e_rm_all");
        expect(save.ok(), `save ${save.status()}`).toBeTruthy();

        const tex = await fetchTexById(page, templateId, id);
        // Modo "all": colonna verticale — ogni item è un \item con marker
        // box (\xcheckbox spuntato / \checkbox vuoto) come label, NON inline.
        expect(tex, "Alfa item \\xcheckbox").toMatch(/\\item\[\\xcheckbox\]\s+Alfa/);
        expect(tex, "Gamma item \\xcheckbox").toMatch(/\\item\[\\xcheckbox\]\s+Gamma/);
        expect(tex, "Beta item \\checkbox").toMatch(/\\item\[\\checkbox\]\s+Beta/);
    });

    test('"checked-only" → itemize con solo x', async ({ page }) => {
        test.setTimeout(30_000);
        if (!templateId) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);
        const { res: save, id } = await saveCompilation(page, templateId, {
            profilo_classe: mkCheckboxPt("checked-only"),
        }, "e2e_rm_co");
        expect(save.ok(), `save ${save.status()}`).toBeTruthy();

        const tex = await fetchTexById(page, templateId, id);
        // checkbox rendering sostituito con \item bullet per le labels x
        expect(tex, "Alfa item bullet").toMatch(/\\item\s+Alfa/);
        expect(tex, "Gamma item bullet").toMatch(/\\item\s+Gamma/);
        // NO \checkbox{Alfa} / \xcheckbox{Alfa} SPECIFICI per i markers
        // (il doc può contenere altri \checkbox{} da altri checkbox-group schema)
        expect(tex, "no \\checkbox{Alfa|Beta|Gamma}").not.toMatch(/\\x?checkbox\{(Alfa|Beta|Gamma)\}/);
        expect(tex, "Beta NON come item").not.toMatch(/\\item\s+Beta/);
    });

    test('"checked-inline" join SPACE (non \\n\\n) + sectionbox preservato', async ({ page }) => {
        test.setTimeout(30_000);
        if (!templateId) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);

        // Struttura: text "prefisso " + checkboxGroup inline + text " suffisso"
        // Atteso: tutto sulla stessa riga (no \n\n tra)
        const pt = [
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "Prefisso MARK_PFX ", marks: [] },
            ]},
            { _type: "checkboxGroup", renderMode: "checked-inline",
              items: [{ state: "x", label: "INLINE_A" }, { state: "x", label: "INLINE_B" }] },
            { _type: "block", style: "normal", children: [
                { _type: "span", text: " MARK_SFX suffisso", marks: [] },
            ]},
        ];
        const { res: save, id } = await saveCompilation(page, templateId, {
            profilo_classe: pt,
        }, "e2e_rm_flow");
        expect(save.ok()).toBeTruthy();
        const tex = await fetchTexById(page, templateId, id);
        const texN = tex.replace(/\\_/g, "_");

        // sectionbox OSSERVAZIONI ora ripristinato dal TexBuilder modernizzato
        expect(texN, "sectionbox OSSERVAZIONI").toMatch(/\\begin\{sectionbox\}\{OSSERVAZIONI\}/);
        // Inline flow: prefisso + INLINE_A, INLINE_B + suffisso su STESSO paragrafo
        // (cioè join con spazio, non \n\n). Verifica pattern singola riga.
        const inlinePattern = /Prefisso MARK_PFX\s+INLINE_A,\s*INLINE_B\s+MARK_SFX/;
        expect(texN, "inline flow: prefisso + labels + suffisso senza paragraph break")
            .toMatch(inlinePattern);
    });

    test('"checked-inline" → labels inline con virgole', async ({ page }) => {
        test.setTimeout(30_000);
        if (!templateId) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);
        const { res: save, id } = await saveCompilation(page, templateId, {
            profilo_classe: mkCheckboxPt("checked-inline"),
        }, "e2e_rm_ci");
        expect(save.ok(), `save ${save.status()}`).toBeTruthy();

        const tex = await fetchTexById(page, templateId, id);
        console.log("[test] inline tex line:",
            tex.split("\n").filter(l => l.match(/Alfa|Gamma/)).join(" | "));

        // Inline: NO \checkbox{Alfa|Beta|Gamma}; Alfa + Gamma inline con virgola
        expect(tex, "no \\checkbox{marker}").not.toMatch(/\\x?checkbox\{(Alfa|Beta|Gamma)\}/);
        expect(tex, "Alfa, Gamma adiacenti con virgola").toMatch(/Alfa,\s*Gamma/);
        // Beta (unchecked) non presente
        expect(tex, "Beta assente").not.toContain("Beta");
    });
});
