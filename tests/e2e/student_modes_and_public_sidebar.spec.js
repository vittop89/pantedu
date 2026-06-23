// E2E — WS3 modalità registrazione (full/reduced) + WS4 sidebar pubblica.
// Toggla lo stato lato server (config JSON + colonna publish_public via mysql),
// poi verifica in browser reale. Cattura console.log.
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const REPO = path.resolve(__dirname, "..", "..");
const CFG = path.join(REPO, "storage", "config", "student_registration.json");
const MYSQL = "C:/xampp/mysql/bin/mysql.exe";

function setMode(mode) {
    fs.mkdirSync(path.dirname(CFG), { recursive: true });
    fs.writeFileSync(CFG, JSON.stringify({ mode, only_superadmin_classes: false }));
}
function resetMode() { try { fs.unlinkSync(CFG); } catch (_) {} }
function sql(q) { execFileSync(MYSQL, ["-u", "root", "pantedu_dev", "-e", q], { stdio: "pipe" }); }

test.afterAll(() => {
    resetMode();
    try { sql("UPDATE sidebar_sections SET publish_public=0 WHERE institute_id=0 AND section_key='mappe'"); } catch (_) {}
});

function capture(page) {
    const logs = [];
    page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
    page.on("pageerror", (e) => logs.push(`[pageerror] ${e.message}`));
    return logs;
}

test("registrazione FULL → campo data di nascita presente", async ({ page }) => {
    setMode("full");
    const logs = capture(page);
    await page.goto("/register");
    await expect(page.locator('input[name="birth_date"]')).toHaveCount(1);
    console.log("FULL console:", JSON.stringify(logs));
    expect(logs.filter((l) => l.startsWith("[pageerror]"))).toEqual([]);
});

test("registrazione REDUCED → niente data di nascita né email genitore", async ({ page }) => {
    setMode("reduced");
    const logs = capture(page);
    await page.goto("/register");
    await expect(page.locator('input[name="birth_date"]')).toHaveCount(0);
    await expect(page.locator('input[name="parent_email"]')).toHaveCount(0);
    await expect(page.locator('input[name="email"]')).toHaveCount(1); // email resta
    console.log("REDUCED console:", JSON.stringify(logs));
    expect(logs.filter((l) => l.startsWith("[pageerror]"))).toEqual([]);
});

test("sidebar pubblica → /public/sidebar/{key} 200 se publish_public, 404 altrimenti", async ({ page }) => {
    sql("UPDATE sidebar_sections SET publish_public=1 WHERE institute_id=0 AND section_key='mappe'");
    const ok = await page.request.get("/public/sidebar/mappe");
    expect(ok.status()).toBe(200);
    const body = await ok.text();
    expect(body).toContain('class="sel-wrapper sel-wrapper--public"');
    expect(body).not.toContain("sel-istituto"); // niente selettore istituto

    sql("UPDATE sidebar_sections SET publish_public=0 WHERE institute_id=0 AND section_key='mappe'");
    const no = await page.request.get("/public/sidebar/mappe");
    expect(no.status()).toBe(404);
});
