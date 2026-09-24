// @ts-check
/**
 * Amministrazione — utenti, blocchi e soglie di sicurezza.
 * Riscrittura di admin_users_security_api.spec.js.
 *
 * La pagina con le sei schede degli strumenti non esiste più (fase 25.H): gli
 * strumenti stanno nella barra dell'amministratore, e le rotte che quella
 * pagina usava sono queste. Riguardano cose che non si possono sbagliare:
 * disattivare un utente, bloccare un indirizzo di rete, cambiare le soglie
 * oltre le quali un accesso viene considerato anomalo.
 *
 * Due garanzie che i test sorvegliano: un amministratore non può disattivare
 * sé stesso, e ogni mutazione lascia una motivazione nel registro degli
 * accessi privilegiati — che qui è dichiarata una volta per tutta la suite
 * nella configurazione di Playwright.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente gettone di
 * sicurezza chiesto a mano, la password dell'amministratore non passa più dal
 * test (il client se la prende da sé) e i ripristini di stato — l'utente
 * riattivato, l'indirizzo sbloccato, le soglie rimesse com'erano — sono
 * registrati nella pulizia invece di essere l'ultima riga del test.
 */
const { test, expect } = require("../support/test");

/** La motivazione dell'accesso ai dati personali va anche in query. */
const MOTIVO = { reason: "suite_e2e" };

test.describe("Amministrazione — utenti e sicurezza", () => {
    test("l'elenco degli utenti risponde e un utente si disattiva e si riattiva", async ({ adminApi, env, cleanup }) => {
        const elenco = await adminApi.http.getJson("/api/admin/users", { limit: "50", ...MOTIVO });
        expect(elenco["ok"], "l'elenco risponde").toBe(true);
        const righe = /** @type {{ id: number, username: string, role: string, active: unknown }[]} */ (elenco["rows"]);
        expect(Array.isArray(righe) && righe.length > 0, "e non è vuoto").toBe(true);

        const bersaglio = righe.find((u) => u.username !== env.users.admin.username && u.role !== "administrator");
        expect(bersaglio, "serve un utente che non amministri, da poter disattivare").toBeTruthy();
        if (!bersaglio) return;

        const eraAttivo = !!bersaglio.active;
        cleanup.add("admin", `rimette l'utente ${bersaglio.id} com'era`, async () => {
            await adminApi.http.send("POST", `/api/admin/users/${bersaglio.id}/active`, {
                form: { active: eraAttivo ? "1" : "0" },
            });
        });

        const cambiato = await adminApi.http.send("POST", `/api/admin/users/${bersaglio.id}/active`, {
            form: { active: eraAttivo ? "0" : "1" },
        });
        expect(cambiato.status, `il cambio risponde → ${cambiato.text.slice(0, 120)}`).toBe(200);
        expect(cambiato.body?.["ok"], "il cambio riesce").toBe(true);
        expect(cambiato.body?.["active"], "e lo stato è quello chiesto").toBe(!eraAttivo);
    });

    test("un amministratore non può disattivare sé stesso", async ({ adminApi, env }) => {
        const elenco = await adminApi.http.getJson("/api/admin/users", { q: env.users.admin.username, ...MOTIVO });
        const righe = /** @type {{ id: number, username: string }[]} */ (elenco["rows"] ?? []);
        const io = righe.find((u) => u.username === env.users.admin.username);
        expect(io, "l'amministratore trova sé stesso nell'elenco").toBeTruthy();
        if (!io) return;

        const tentativo = await adminApi.http.send("POST", `/api/admin/users/${io.id}/active`, { form: { active: "0" } });
        expect(tentativo.status, "l'applicazione lo vieta").toBe(403);
        expect(tentativo.body?.["error"], "e dice perché").toBe("cannot_disable_self");
    });

    test("credenziali e indirizzi bloccati sono elenchi, e dicono a chi appartengono", async ({ adminApi }) => {
        const credenziali = await adminApi.http.getJson("/api/admin/security/blocked-credentials");
        expect(credenziali["ok"], "le credenziali bloccate rispondono").toBe(true);
        expect(Array.isArray(credenziali["rows"]), "come elenco").toBe(true);

        const indirizzi = await adminApi.http.getJson("/api/admin/security/blocked-ips");
        expect(indirizzi["ok"], "gli indirizzi bloccati rispondono").toBe(true);
        const righe = /** @type {{ associated_usernames?: unknown }[]} */ (indirizzi["rows"]);
        expect(Array.isArray(righe), "come elenco").toBe(true);
        for (const riga of righe) {
            expect(Array.isArray(riga.associated_usernames), "ogni indirizzo dice con quali utenze è stato visto").toBe(true);
        }
    });

    test("le anomalie arrivano con il riepilogo e con i campi che servono a giudicarle", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/api/admin/security/anomalies");
        expect(risposta["ok"], "la rotta risponde").toBe(true);

        const riepilogo = /** @type {Record<string, unknown>} */ (risposta["summary"]);
        expect(riepilogo, "c'è il riepilogo").toBeTruthy();
        for (const voce of ["total", "active", "excessive_access", "credential_sharing"]) {
            expect(typeof riepilogo[voce], `il riepilogo conta «${voce}»`).toBe("number");
        }

        const anomalie = /** @type {{ type: string, risk_level: string, count: unknown, blocked: unknown, fingerprint: string }[]} */ (risposta["rows"]);
        for (const anomalia of anomalie) {
            expect(["excessive_access", "credential_sharing"], "il tipo è uno dei due previsti").toContain(anomalia.type);
            expect(["low", "medium", "high"], "il rischio è uno dei tre livelli").toContain(anomalia.risk_level);
            expect(typeof anomalia.count, "con il conteggio").toBe("number");
            expect(typeof anomalia.blocked, "e se è già bloccata").toBe("boolean");
            expect(anomalia.fingerprint, "l'impronta dice da quale regola viene").toMatch(/^(ea|cs):/);
        }
    });

    test("un indirizzo si blocca, compare fra i bloccati e si sblocca", async ({ adminApi, cleanup }) => {
        // Indirizzo della rete riservata alla documentazione (RFC 5737):
        // non è di nessuno, bloccarlo non tocca nessun utente vero.
        const indirizzo = `203.0.113.${1 + Math.floor(Math.random() * 250)}`;
        const sezione = "test_e2e";
        cleanup.add("admin", `sblocca l'indirizzo ${indirizzo}`, async () => {
            await adminApi.http.send("POST", "/api/admin/security/ips/unblock", { form: { ip: indirizzo, section: sezione } });
        });

        const bloccato = await adminApi.http.send("POST", "/api/admin/security/ips/block", {
            form: { ip: indirizzo, section: sezione, reason: "prova della suite end-to-end" },
        });
        expect(bloccato.body?.["ok"], `il blocco riesce → ${bloccato.text.slice(0, 150)}`).toBe(true);

        const elenco = /** @type {{ ip: string }[]} */ ((await adminApi.http.getJson("/api/admin/security/blocked-ips"))["rows"]);
        expect(elenco.some((r) => r.ip === indirizzo), "l'indirizzo è fra i bloccati").toBe(true);

        const sbloccato = await adminApi.http.send("POST", "/api/admin/security/ips/unblock", {
            form: { ip: indirizzo, section: sezione },
        });
        expect(sbloccato.body?.["ok"], "lo sblocco riesce").toBe(true);
        expect(sbloccato.body?.["removed"], "e toglie una riga sola").toBe(1);
    });

    test("le soglie di sicurezza si leggono e si cambiano", async ({ adminApi, cleanup }) => {
        const prima = await adminApi.http.getJson("/api/admin/security/config");
        expect(prima["ok"], "la configurazione risponde").toBe(true);
        // Su un'istanza appena installata la configurazione è vuota: la
        // struttura compare dopo il primo salvataggio.
        const allarmi = /** @type {Record<string, Record<string, unknown>>} */ (
            /** @type {Record<string, unknown>} */ (prima["config"])?.["security_alerts"] ?? {}
        );
        const accessi = allarmi["excessive_access"] ?? {};
        const condivisione = allarmi["credential_sharing"] ?? {};

        // I valori predefiniti dell'applicazione, non quelli di questa prova.
        // Stanno in `js/entries/admin-waf-config.js`, che li usa quando la
        // configurazione non c'è ancora.
        //
        // 2026-09-08 — prima qui c'era `?? 5`, cioè il valore che la prova
        // sta per scrivere. Su un database appena seminato la configurazione è
        // vuota, quindi il «ripristino» lasciava la soglia degli accessi a 5
        // invece dei 50 previsti — un decimo — e ce la lasciava per sempre,
        // anche per tutti i giri successivi sullo stesso database. Una pulizia
        // che scrive i valori della prova non è una pulizia.
        const PREDEFINITI = {
            ea_threshold_per_section: 50,
            ea_time_window_hours: 24,
            ea_low_min: 50,
            ea_low_max: 99,
            ea_medium_min: 100,
            ea_medium_max: 199,
            ea_high_min: 200,
            cs_min_ips_required: 3,
        };
        /** Il valore salvato prima della prova, o il predefinito se non c'era. */
        const comEra = (/** @type {Record<string, unknown>} */ origine, /** @type {string} */ chiave,
            /** @type {keyof typeof PREDEFINITI} */ predefinito) =>
            String(origine[chiave] ?? PREDEFINITI[predefinito]);

        cleanup.add("admin", "rimette le soglie di sicurezza com'erano", async () => {
            await adminApi.http.send("POST", "/api/admin/security/config", {
                form: {
                    ea_enabled: accessi["enabled"] === false ? "0" : "1",
                    ea_threshold_per_section: comEra(accessi, "threshold_per_section", "ea_threshold_per_section"),
                    ea_time_window_hours: comEra(accessi, "time_window_hours", "ea_time_window_hours"),
                    ea_low_min: comEra(accessi, "low_min", "ea_low_min"),
                    ea_low_max: comEra(accessi, "low_max", "ea_low_max"),
                    ea_medium_min: comEra(accessi, "medium_min", "ea_medium_min"),
                    ea_medium_max: comEra(accessi, "medium_max", "ea_medium_max"),
                    ea_high_min: comEra(accessi, "high_min", "ea_high_min"),
                    cs_enabled: condivisione["enabled"] === false ? "0" : "1",
                    cs_min_ips_required: comEra(condivisione, "min_ips_required", "cs_min_ips_required"),
                },
            });
        });

        const salvata = await adminApi.http.send("POST", "/api/admin/security/config", {
            form: {
                ea_enabled: "1",
                ea_threshold_per_section: "5",
                ea_low_min: "5",
                ea_low_max: "10",
                ea_medium_min: "11",
                ea_medium_max: "20",
                ea_high_min: "21",
                cs_enabled: "1",
                cs_min_ips_required: "3",
            },
        });
        expect(salvata.status, `il salvataggio risponde → ${salvata.text.slice(0, 150)}`).toBe(200);
        const configurazione = /** @type {any} */ (salvata.body)?.config?.security_alerts;
        expect(configurazione?.excessive_access?.threshold_per_section, "la soglia degli accessi è quella salvata").toBe(5);
        expect(configurazione?.credential_sharing?.min_ips_required, "e quella della condivisione").toBe(3);
    });

    // 15/9/2026 — sulla riga dell'amministratore c'erano «Disattiva», «Elimina» e il
    // cambio di ruolo, che il server rifiuta; e il filtro offriva «Studente» anche
    // dove gli account studente non esistono. Le regole lato server le prova
    // tests/Integration/PannelloUtentiRuoliTest.php; qui la pagina.
    test("nel pannello degli utenti la propria riga non ha comandi, e il filtro segue lo scenario", async ({ adminPage, env }) => {
        await adminPage.goto("/admin/dashboard");
        const distintivo = adminPage.locator(".fm-tb-actions [data-scenario]");
        await expect(distintivo, "il pannello dice lo scenario").toHaveCount(1);
        const n = Number(await distintivo.getAttribute("data-scenario"));
        const superAdmin = (await adminPage.locator('.fm-tb-actions [data-role="super"]').count()) > 0;

        await adminPage.goto("/admin");
        await adminPage.locator('.fm-tab[data-tab="users"]').click();
        const filtro = adminPage.locator("#fm-users-role");
        await expect(filtro, "il filtro per ruolo").toBeVisible();
        if (n !== 3 || superAdmin) {
            await expect(filtro.locator('option[value="student"]'), `nello scenario ${n}${superAdmin ? ", da super-amministratore," : ""} «Studente» non c'è`).toHaveCount(0);
        }
        await expect(filtro.locator('option[value="institute_admin"]'), "l'amministratore di istituto solo nello scenario 3").toHaveCount(n === 3 ? 1 : 0);

        await adminPage.locator("#fm-users-reason").fill("suite_e2e: prova del pannello degli utenti");
        await adminPage.locator("#fm-users-search").click();
        const righe = adminPage.locator("#fm-users-result tbody tr");
        await expect(righe.first(), "l'elenco arriva").toBeVisible({ timeout: 30_000 });

        const username = env.users.admin.username;
        const mia = righe.filter({ has: adminPage.locator("td:nth-child(2) code", { hasText: new RegExp(`^${username.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`) }) });
        await expect(mia, "la riga dell'amministratore c'è").toHaveCount(1);
        await expect(mia, "e dice che è la sua").toContainText("sei tu");
        await expect(mia.locator(".fm-user-del, .fm-user-toggle, .fm-user-role"), "senza comandi").toHaveCount(0);
        await expect(righe.filter({ has: adminPage.locator(".fm-user-del") }).first(), "le altre righe i comandi li hanno").toBeVisible();
    });

    test("chi ha un account vero entra alle risorse riservate con quello", async ({ adminApi }) => {
        const esito = await adminApi.access.studentLoginComeRuolo("admin");
        expect(esito.status, `l'accesso risponde → ${esito.text.slice(0, 150)}`).toBe(200);
        expect(esito.body?.ok, "e riesce").toBe(true);
        expect(esito.body?.grant?.source, "il permesso viene dall'account").toBe("user_account");
        expect(esito.body?.grant?.label, "ed è etichettato come accesso proprio").toContain("Self-access");
    });

    test("lo strumento delle password produce un'impronta bcrypt", async ({ adminApi }) => {
        const esito = await adminApi.http.send("POST", "/admin/generate-hash", {
            form: { password: "PasswordDiProvaE2E!", cost: "10" },
        });
        expect(esito.status, `lo strumento risponde → ${esito.text.slice(0, 150)}`).toBe(200);
        expect(esito.body?.["ok"], "e riesce").toBe(true);
        expect(esito.body?.["hash"], "l'impronta è bcrypt col costo chiesto").toMatch(/^\$2y\$10\$/);
    });

    test("il pannello delle credenziali bloccate si apre, e le iscrizioni in sospeso sono un elenco", async ({ adminApi, adminPage }) => {
        const risposta = await adminPage.goto("/admin/waf/credentials");
        expect(risposta?.status(), "la pagina si apre").toBeLessThan(400);
        await expect(adminPage.locator("#fm-content, main").first(), "col suo contenuto").toBeVisible({ timeout: 30_000 });

        const iscrizioni = await adminApi.http.getJson("/admin/registrations");
        expect(Array.isArray(iscrizioni["pending"]), "le iscrizioni in sospeso sono un elenco").toBe(true);
    });
});
