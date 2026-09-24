#!/usr/bin/env node
/**
 * Avvia in locale il microservizio TeX (tools/tex-compile-vps), lo stesso che
 * in produzione gira sul VPS: serve alla suite Playwright (compilazione PDF,
 * render TikZ, SyncTeX) e allo sviluppo dei documenti TeX.
 *
 *   node tools/dev/tex-service-local.mjs           avvia (Ctrl+C per fermare)
 *   node tools/dev/tex-service-local.mjs --check   interroga solo /health
 *
 * Prerequisiti: Python 3.10+ e una distribuzione TeX nel PATH (MiKTeX o
 * TeX Live: pdflatex, xelatex, dvisvgm, synctex). Il venv Python vive in
 * storage/_tmp/tex-compile-venv (ignorato da git), l'area di lavoro in
 * storage/_tmp/tex-compile-work.
 *
 * Legge TEX_COMPILE_ENDPOINT e TEX_COMPILE_SECRET da .env.local (poi .env):
 * l'app PHP e il servizio devono condividere lo stesso segreto HMAC. Il
 * segreto non viene mai stampato.
 */
import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
const SERVICE_DIR = path.join(ROOT, "tools", "tex-compile-vps");
const VENV_DIR = path.join(ROOT, "storage", "_tmp", "tex-compile-venv");
const WORK_DIR = path.join(ROOT, "storage", "_tmp", "tex-compile-work");
const IS_WIN = process.platform === "win32";

function readEnv() {
    const out = {};
    for (const file of [".env", ".env.local"]) {
        let raw = "";
        try { raw = readFileSync(path.join(ROOT, file), "utf8"); } catch { continue; }
        for (const line of raw.split(/\r?\n/)) {
            const m = line.match(/^\s*(TEX_COMPILE_[A-Z_]+|TIKZ_RENDER_[A-Z_]+)\s*=\s*(.*?)\s*$/);
            if (m) out[m[1]] = m[2].replace(/^"(.*)"$/, "$1");
        }
    }
    return out;
}

const env = readEnv();
const endpoint = env.TEX_COMPILE_ENDPOINT || "http://127.0.0.1:8001";
const secret = env.TEX_COMPILE_SECRET || "";
const port = Number(new URL(endpoint).port || 8001);

async function check() {
    try {
        const r = await fetch(`${endpoint}/health`, { signal: AbortSignal.timeout(4000) });
        console.log(`[tex-service] ${endpoint}/health → HTTP ${r.status}`);
        return r.ok;
    } catch (e) {
        console.log(`[tex-service] ${endpoint}/health non raggiungibile (${e.message})`);
        return false;
    }
}

if (process.argv.includes("--check")) {
    process.exit((await check()) ? 0 : 1);
}

if (!secret) {
    console.error("[tex-service] TEX_COMPILE_SECRET vuoto in .env.local: generane uno (64 hex) e mettilo sia lì sia qui.");
    process.exit(2);
}

const venvPython = IS_WIN
    ? path.join(VENV_DIR, "Scripts", "python.exe")
    : path.join(VENV_DIR, "bin", "python");

if (!existsSync(venvPython)) {
    console.log(`[tex-service] creo il venv in ${path.relative(ROOT, VENV_DIR)}`);
    const r = spawnSync(IS_WIN ? "python" : "python3", ["-m", "venv", VENV_DIR], { stdio: "inherit" });
    if (r.status !== 0) process.exit(r.status ?? 1);
}
console.log("[tex-service] installo le dipendenze (requirements.txt)");
const pip = spawnSync(venvPython, ["-m", "pip", "install", "-q", "-r", path.join(SERVICE_DIR, "app", "requirements.txt")], { stdio: "inherit" });
if (pip.status !== 0) process.exit(pip.status ?? 1);
// Ripiego SVG→PDF senza librsvg (svglib + reportlab), solo per lo sviluppo locale.
const localReq = path.join(SERVICE_DIR, "app", "requirements-local.txt");
if (existsSync(localReq)) {
    const pipLocal = spawnSync(venvPython, ["-m", "pip", "install", "-q", "-r", localReq], { stdio: "inherit" });
    if (pipLocal.status !== 0) console.warn("[tex-service] ripiego svglib non installato: /svg-to-pdf richiede rsvg-convert");
}

mkdirSync(WORK_DIR, { recursive: true });
console.log(`[tex-service] avvio uvicorn su 127.0.0.1:${port} (Ctrl+C per fermare)`);
const child = spawn(venvPython, ["-m", "uvicorn", "app.main:app", "--host", "127.0.0.1", "--port", String(port)], {
    cwd: SERVICE_DIR,
    stdio: "inherit",
    env: {
        ...process.env,
        TEX_COMPILE_SECRET: secret,
        TEX_COMPILE_WORKDIR: WORK_DIR,
        TEX_COMPILE_TIMEOUT: env.TEX_COMPILE_TIMEOUT || "60",
        TEX_COMPILE_LOG_LEVEL: process.env.TEX_COMPILE_LOG_LEVEL || "INFO",
    },
});
child.on("exit", (code) => process.exit(code ?? 0));
process.on("SIGINT", () => child.kill("SIGINT"));
process.on("SIGTERM", () => child.kill("SIGTERM"));
