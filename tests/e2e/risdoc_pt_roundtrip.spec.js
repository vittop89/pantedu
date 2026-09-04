/**
 * ADR-026 — cancello di sicurezza del PERCORSO UNICO: prova che la conversione
 * schema→PT (sectionSchemaToPt) è INVERTIBILE via ptToFields senza perdita dei
 * VALORI dei campi. Se questo è verde, il save unificato è lossless e si può
 * abilitare; se rosso, NON si fa lo switch (si tiene il motore per quei tipi).
 */
const { test, expect } = require("@playwright/test");
const T = process.env.E2E_TEACHER_USER || "", P = process.env.E2E_TEACHER_PASS || "";

test("round-trip schema→PT→fields lossless sui valori editabili", async ({ page }) => {
  test.setTimeout(90000); if (!P) { test.skip(true); return; }
  await page.goto("/login");
  await page.fill('input[name="username"]', T); await page.fill('input[name="password"]', P);
  await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
  // pagina autenticata qualsiasi → import modulo same-origin
  await page.goto("/area-docente"); await page.waitForLoadState("domcontentloaded");
  const res = await page.evaluate(async () => {
    const mod = await import("/js/modules/risdoc/pt/section-to-pt.js");
    const { sectionSchemaToPt, ptToFields } = mod;
    const cases = [
      { name:"voto",  field:{ type:"grade-selector", name:"voto", options:[{value:"6",label:"Sei"},{value:"7",label:"Sette"}] }, value:"7" },
      { name:"prof",  field:{ type:"info-field", name:"prof", title:"Professore" }, value:"Mario Rossi" },
      { name:"flag",  field:{ type:"form-checkbox", name:"flag", title:"Attivo" }, value:true },
      { name:"liv",   field:{ type:"checkbox-group", name:"liv", options:[{value:"alto",label:"Alto"},{value:"medio",label:"Medio"},{value:"basso",label:"Basso"}] }, value:["alto","basso"] },
      { name:"tab",   field:{ type:"dynamic-table", name:"tab", columns:["A","B"] }, value:[["1","2"],["3","4"]] },
    ];
    const out = {};
    for (const c of cases) {
      const fields = { [c.name]: c.value };
      const pt = sectionSchemaToPt(c.field, fields, {});
      const back = ptToFields(pt);
      out[c.name] = { expected: c.value, got: back[c.name], ok: JSON.stringify(back[c.name]) === JSON.stringify(c.value) };
    }
    return out;
  });
  console.log("ROUNDTRIP:", JSON.stringify(res, null, 1));
  for (const k of Object.keys(res)) expect(res[k].ok, `campo ${k}`).toBe(true);
});
