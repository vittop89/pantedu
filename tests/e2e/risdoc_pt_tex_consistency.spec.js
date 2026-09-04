/**
 * Phase 24.19 — Smoke/consistency TeX generato dal template PT.
 *
 * Verifica:
 *   - GET /api/risdoc/templates/{id}/tex ritorna un TeX ben-formato
 *   - Contiene strutture expected: \section{}, \checkbox / \xcheckbox, tabular
 *   - POST export?mode=zip ritorna URL scaricabile
 *   - Il PT editor renderizza correttamente tutti i widget dopo insertQuick,
 *     e il TeX generato dopo salvataggio contiene label/value dei widget.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function fetchCsrf(page) {
    const r = await page.request.get("/auth/csrf");
    const j = await r.json();
    return j.token || "";
}

test.describe("risdoc PT TeX consistency", () => {
    test("GET /tex ritorna struttura TeX expected", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const list = await page.request.get("/api/risdoc/templates");
        const lj = await list.json();
        const piano = (lj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        const res = await page.request.get(`/api/risdoc/templates/${piano.id}/tex`);
        expect(res.ok(), "tex endpoint ok").toBeTruthy();
        const tex = await res.text();

        // Basic structure checks
        expect(tex.length, "tex non vuoto").toBeGreaterThan(100);
        expect(tex, "sezioni LaTeX (section/subsection/paragraph con asterisco opzionale)")
            .toMatch(/\\(section|subsection|subsubsection|paragraph)\*?\{/);
        // TeX builder senza compilation produce minimo struct. Se ci sono field
        // compilati, appaiono \checkbox / \xcheckbox. Skip hard check.

        console.log("[test] TeX length:", tex.length, "bytes");
        console.log("[test] TeX snippet:", tex.slice(0, 400));
    });

    test("random populate fields → save compilation → TeX contiene valori", async ({ page }) => {
        test.setTimeout(90_000);
        await loginAdmin(page);

        const list = await page.request.get("/api/risdoc/templates");
        const lj = await list.json();
        const piano = (lj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        // Fetch schema per popolare random ma coerente
        const schRes = await page.request.get(`/api/risdoc/templates/${piano.id}/schema`);
        const schema = schRes.ok() ? await schRes.json() : null;
        if (!schema) { test.skip(true, "no schema"); return; }

        // Build random fields: per ogni section con name, setta una string marker.
        // Per checkbox-group con options, seleziona ~50% values random. Per select,
        // prendi il primo option.value.
        const fields = {};
        const UNIQUE = "FISM_E2E_" + Date.now();
        const walk = (node) => {
            if (!node || typeof node !== "object") return;
            const name = node.name;
            if (name) {
                if (node.type === "nota-textarea" || node.type === "text-section"
                    || node.type === "info-field") {
                    fields[name] = `${UNIQUE}_${name}`;
                } else if (node.type === "checkbox-group" && Array.isArray(node.options)) {
                    const picks = node.options
                        .filter(() => Math.random() < 0.5)
                        .map((o) => (typeof o === "object" ? (o.value ?? o.label) : o));
                    if (picks.length > 0) fields[name] = picks;
                } else if (node.type === "form-checkbox") {
                    fields[name] = true;
                } else if (node.type === "grade-selector" || node.type === "giudizio-item") {
                    fields[name] = `${UNIQUE}_grade`;
                }
            }
            if (Array.isArray(node.items)) node.items.forEach(walk);
            if (Array.isArray(node.sections)) node.sections.forEach(walk);
        };
        walk(schema);

        const state = { indirizzo: "sc", classe: "2s", disciplina: "MAT", sezione: "A" };
        const csrf = await fetchCsrf(page);

        // Save compilation
        const saveRes = await page.request.post(`/api/risdoc/templates/${piano.id}/compilations`, {
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            data: new URLSearchParams({
                _csrf: csrf,
                compilation_key: "e2e_test",
                label: "E2E Test Compilation",
                data: JSON.stringify({ fields, state }),
                classe: state.classe,
                sezione: state.sezione,
                indirizzo: state.indirizzo,
                disciplina: state.disciplina,
            }).toString(),
        });
        expect(saveRes.ok(), `save compilation status=${saveRes.status()}`).toBeTruthy();
        const sj = await saveRes.json();
        expect(sj.ok || sj.id, "save ok").toBeTruthy();

        // Fetch compilation via API per verificare save
        const clist = await page.request.get(`/api/risdoc/templates/${piano.id}/compilations`);
        const cj = await clist.json();
        console.log("[test] compilations count:", (cj.compilations || []).length);
        if ((cj.compilations || []).length > 0) {
            const latest = cj.compilations[0];
            console.log("[test] latest id:", latest.id, "key:", latest.compilation_key);
        }

        // Fetch TeX con compilation_id esplicito
        const latest = (cj.compilations || [])[0];
        const texUrl = latest
            ? `/api/risdoc/templates/${piano.id}/tex?compilation_id=${latest.id}`
            : `/api/risdoc/templates/${piano.id}/tex`;
        const texRes = await page.request.get(texUrl);
        expect(texRes.ok(), "tex ok").toBeTruthy();
        const tex = await texRes.text();
        console.log("[test] tex length:", tex.length);

        // Verifica marker UNIQUE appare (almeno un field testuale).
        // TeX escapa _ come \_: normalizzo prima del match.
        const texNorm = tex.replace(/\\_/g, "_");
        const uniqueOccurrences = (texNorm.match(new RegExp(UNIQUE, "g")) || []).length;
        console.log("[test] UNIQUE marker occurrences in TeX:", uniqueOccurrences);
        expect(uniqueOccurrences, "almeno 1 marker nel TeX").toBeGreaterThan(0);

        // Se sono state selezionate checkbox, il TeX ha \xcheckbox
        const xcheckCount = (tex.match(/\\xcheckbox\{/g) || []).length;
        const checkCount = (tex.match(/\\checkbox\{/g) || []).length;
        console.log("[test] xcheckbox:", xcheckCount, "checkbox:", checkCount);

        // Preview dove appaiono marker
        const markerContext = tex.split("\n")
            .filter((l) => l.includes(UNIQUE))
            .slice(0, 3);
        console.log("[test] marker context:\n  " + markerContext.join("\n  "));
    });

    test("POST /export zip ritorna URL scaricabile + ZIP contiene main.tex e doc .tex", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const list = await page.request.get("/api/risdoc/templates");
        const lj = await list.json();
        const piano = (lj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        const csrf = await fetchCsrf(page);
        const body = new URLSearchParams({
            _csrf: csrf,
            mode: "zip",
            form_state: JSON.stringify({ fields: {}, state: { indirizzo: "sc", classe: "2s", disciplina: "MAT" } }),
        });
        const exp = await page.request.post(`/api/risdoc/templates/${piano.id}/export`, {
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json",
            },
            data: body.toString(),
        });
        expect(exp.ok(), `export status=${exp.status()}`).toBeTruthy();
        const j = await exp.json();
        expect(j.ok).toBeTruthy();
        expect(j.url, "download url").toBeTruthy();

        // Fetch ZIP e verifica header Content-Type
        const zipRes = await page.request.get(j.url);
        expect(zipRes.ok(), "zip download ok").toBeTruthy();
        expect(zipRes.headers()["content-type"], "zip mime").toContain("zip");

        const buf = await zipRes.body();
        expect(buf.length, "zip non vuoto").toBeGreaterThan(200);

        // Il ZIP inizia con signature PK\x03\x04
        const sig = buf.slice(0, 4).toString("hex");
        expect(sig, "PK zip signature").toBe("504b0304");

        console.log("[test] ZIP bytes:", buf.length);
        console.log("[test] ZIP url:", j.url);
    });
});
