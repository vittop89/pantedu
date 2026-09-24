#!/usr/bin/env node
/**
 * CI guard — coerenza fra i documenti legali e il registro delle versioni.
 *
 * Il fallimento che questo script previene: alzare `version:` in
 * docs/legal/aup.md, rigenerare il PDF, e dimenticare di registrare la nuova
 * versione. Prima esisteva una costante PHP da ricordare a mano
 * (TosAcceptanceService::AUP_VERSION_CURRENT) e nessuno la ricordava: il
 * documento pubblicato diceva 1.1 e il sistema continuava a chiedere 1.0.
 *
 * Controlli:
 *   1. ogni documento in docs/legal/versions.json esiste su disco;
 *   2. il `version:` nel frontmatter corrisponde all'ultima voce del registro;
 *   3. le versioni sostanziali hanno almeno `notice_days` fra pubblicazione ed
 *      entrata in vigore (la prima versione di un documento è esente: non c'è
 *      un "prima" di cui dare preavviso);
 *   4. published_at <= effective_from, e nessuna versione duplicata;
 *   5. la versione stampata nel corpo del documento (`**Versione**: x.y`)
 *      coincide con il frontmatter. Un documento che dichiara due versioni
 *      diverse di se stesso non è opponibile a nessuno, e il PDF consegnato
 *      mostra proprio quella riga, non il frontmatter;
 *   6. ogni versione ha un `summary` di al massimo 500 caratteri: è la
 *      colonna `legal_document_versions.summary` (VARCHAR(500)) e il testo
 *      dell'email di preavviso. Oltre, `sync_versions.php --apply` muore a
 *      metà con "Data too long" e il registro resta disallineato — successo
 *      il 2026-09-04 con la 1.3 dei Termini, 705 caratteri;
 *   7. le tre informative (una per scenario: docs/privacy/informativa-
 *      personale.md, informativa.md, informativa-istituto.md, i file che
 *      sceglie DeploymentScenario::informativaFile()) dichiarano ciascuna un
 *      `versione:` nel frontmatter, uguale alla riga `**Versione:**` del
 *      corpo, e stanno nell'immagine (.dockerignore). Dal 23/9/2026 i
 *      consensi registrano la versione del testo dello scenario attivo,
 *      letta a runtime da quel file (DeploymentScenario::versioneInformativa,
 *      revisione architetturale DOC-19): un file senza versione, o fuori
 *      dall'immagine, fermerebbe la registrazione dei consensi. Prima il
 *      numero era ricopiato in app/Config/gdpr.php da informativa.md sola, e
 *      negli scenari 1 e 3 i consensi citavano il testo sbagliato; il
 *      controllo verifica anche che quella copia non torni (né il file né
 *      GDPR_TEXT_VERSION in .env.example).
 */
import { readFileSync, existsSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");
const REGISTRY = join(ROOT, "docs", "legal", "versions.json");

const errors = [];
const warnings = [];

if (!existsSync(REGISTRY)) {
    console.error(`✗ Registro mancante: docs/legal/versions.json`);
    process.exit(1);
}

const registry = JSON.parse(readFileSync(REGISTRY, "utf8"));
const noticeDays = Number(registry.notice_days ?? 30);
// Deve coincidere con legal_document_versions.summary VARCHAR(500).
const SUMMARY_MAX = 500;

/** Estrae `version: "x.y"` dal frontmatter YAML in testa al markdown. */
function frontmatterVersion(md) {
    const fm = md.match(/^---\r?\n([\s\S]*?)\r?\n---/);
    if (!fm) return null;
    const m = fm[1].match(/^version:\s*["']?([^"'\r\n]+)["']?\s*$/m);
    return m ? m[1].trim() : null;
}

function daysBetween(a, b) {
    return Math.round((new Date(b) - new Date(a)) / 86400000);
}

for (const [docType, doc] of Object.entries(registry.documents ?? {})) {
    const rel = doc.file;
    const abs = join(ROOT, rel);

    if (!existsSync(abs)) {
        errors.push(`${docType}: file mancante — ${rel}`);
        continue;
    }
    const versions = Array.isArray(doc.versions) ? doc.versions : [];
    if (versions.length === 0) {
        errors.push(`${docType}: nessuna versione registrata in versions.json`);
        continue;
    }

    // 4. duplicati + coerenza delle date
    const seen = new Set();
    for (const v of versions) {
        if (seen.has(v.version)) {
            errors.push(`${docType}: versione ${v.version} duplicata nel registro`);
        }
        seen.add(v.version);
        if (daysBetween(v.published_at, v.effective_from) < 0) {
            errors.push(
                `${docType} ${v.version}: effective_from (${v.effective_from}) precede ` +
                `published_at (${v.published_at})`
            );
        }
        // 6. summary presente e dentro la colonna del DB
        const summary = typeof v.summary === "string" ? v.summary.trim() : "";
        if (summary.length === 0) {
            errors.push(`${docType} ${v.version}: summary mancante`);
        } else if (summary.length > SUMMARY_MAX) {
            errors.push(
                `${docType} ${v.version}: summary di ${summary.length} caratteri, ` +
                `legal_document_versions.summary ne tiene ${SUMMARY_MAX}`
            );
        }
    }

    // 3. preavviso — esente solo la prima versione del documento
    versions.forEach((v, i) => {
        if (i === 0 || v.substantial === false) return;
        const gap = daysBetween(v.published_at, v.effective_from);
        if (gap >= noticeDays) return;

        // Terza via fra "aspetta 30 giorni" e "dichiarala non sostanziale".
        // Quest'ultima e' la scorciatoia pericolosa: sarebbe una bugia sulla
        // natura della modifica, e resterebbe a registro come tale. La rinuncia
        // motivata dice invece la verita' — la modifica E' sostanziale, e il
        // preavviso e' stato saltato per una ragione scritta e verificabile.
        const waiver = typeof v.notice_waiver === "string" ? v.notice_waiver.trim() : "";
        if (waiver.length >= 40) {
            warnings.push(
                `${docType} ${v.version}: preavviso di ${gap} giorni invece di ${noticeDays}, ` +
                `con rinuncia motivata a registro — ${waiver}`
            );
            return;
        }
        errors.push(
            `${docType} ${v.version}: preavviso di ${gap} giorni, sotto i ${noticeDays} ` +
            `promessi da ToS §8 / AUP §6. Sposta effective_from; oppure marca la ` +
            `modifica "substantial": false se davvero non tocca obblighi o diritti; ` +
            `oppure, se nessuno fa affidamento sul testo precedente, motiva la ` +
            `rinuncia in "notice_waiver" (almeno 40 caratteri).`
        );
    });

    // 2. frontmatter allineato all'ultima voce registrata
    const md = readFileSync(abs, "utf8");
    const declared = frontmatterVersion(md);
    const latest = versions[versions.length - 1].version;

    // 5. la riga visibile nel corpo deve dire la stessa cosa del frontmatter
    const inBody = md.match(/^\*\*Versione\*\*:\s*([^\s·]+)/m);
    if (inBody && declared !== null && inBody[1] !== declared) {
        errors.push(
            `${docType}: ${rel} stampa "**Versione**: ${inBody[1]}" nel corpo ma il ` +
            `frontmatter dice "${declared}". È la riga del corpo quella che finisce ` +
            `nel PDF consegnato.`
        );
    }

    if (declared === null) {
        errors.push(`${docType}: nessun \`version:\` nel frontmatter di ${rel}`);
    } else if (declared !== latest) {
        errors.push(
            `${docType}: ${rel} dichiara version "${declared}" ma l'ultima voce del ` +
            `registro è "${latest}". Aggiorna docs/legal/versions.json, poi esegui ` +
            `\`php tools/legal/sync_versions.php --apply\`.`
        );
    }
}

// 7. le tre informative: versione dichiarata, coerente col corpo, nell'immagine
{
    const scenario = join(ROOT, "app", "Support", "DeploymentScenario.php");
    const dockerignore = join(ROOT, ".dockerignore");
    const php = existsSync(scenario) ? readFileSync(scenario, "utf8") : "";
    // I file che il codice serve: si leggono da informativaFile(), non da un
    // elenco copiato qui (un elenco a parte invecchia).
    const corpo = (php.match(/function informativaFile\([\s\S]*?\n    }/) ?? [""])[0];
    const nomi = [...new Set([...corpo.matchAll(/'(informativa[a-z-]*\.md)'/g)].map((m) => m[1]))];
    if (nomi.length === 0) {
        errors.push("informativa: non trovo i file in DeploymentScenario::informativaFile()");
    } else if (nomi.length !== 3) {
        errors.push(`informativa: DeploymentScenario::informativaFile() serve ${nomi.length} file, attesi 3 (uno per scenario): ${nomi.join(", ")}`);
    }
    const ignorati = existsSync(dockerignore) ? readFileSync(dockerignore, "utf8") : "";
    for (const nome of nomi) {
        const rel = `docs/privacy/${nome}`;
        const abs = join(ROOT, "docs", "privacy", nome);
        if (!existsSync(abs)) {
            errors.push(`informativa: file mancante — ${rel}`);
            continue;
        }
        const md = readFileSync(abs, "utf8");
        const fm = md.match(/^---\r?\n([\s\S]*?)\r?\n---/);
        const declared = fm ? ((fm[1].match(/^versione:[ \t]*["']?([^"'\r\n]+?)["']?[ \t]*$/m) ?? [])[1] ?? "").trim() : "";
        if (!declared) {
            errors.push(`informativa: nessun \`versione:\` nel frontmatter di ${rel} — i consensi dello scenario che lo serve non si registrerebbero`);
            continue;
        }
        if (!/^[0-9]+\.[0-9]+$/.test(declared)) {
            errors.push(`informativa: ${rel} dichiara la versione «${declared}», che non ha la forma x.y`);
        }
        const inBody = md.match(/^\*\*Versione:?\*\*:?[ \t]*([0-9]+\.[0-9]+)/m);
        if (inBody && inBody[1] !== declared) {
            errors.push(
                `informativa: ${rel} stampa "**Versione:** ${inBody[1]}" nel corpo ma il frontmatter dice ` +
                `"${declared}": i consensi registrano il frontmatter, l'utente legge il corpo.`
            );
        }
        if (!new RegExp(`^!${rel.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*$`, "m").test(ignorati)) {
            errors.push(`informativa: ${rel} non è riammesso in .dockerignore (\`!${rel}\`): nell'immagine non ci sarebbe`);
        }
    }
    // La copia del numero non torna.
    if (existsSync(join(ROOT, "app", "Config", "gdpr.php"))) {
        const gdpr = readFileSync(join(ROOT, "app", "Config", "gdpr.php"), "utf8");
        if (/'text_version'/.test(gdpr)) {
            errors.push("informativa: app/Config/gdpr.php definisce di nuovo `text_version`: la versione dei consensi si legge dal testo dello scenario (DeploymentScenario::versioneInformativa)");
        }
    }
    const envExample = join(ROOT, ".env.example");
    if (existsSync(envExample) && /^GDPR_TEXT_VERSION=/m.test(readFileSync(envExample, "utf8"))) {
        errors.push("informativa: .env.example imposta di nuovo GDPR_TEXT_VERSION, che il codice non legge più");
    }
}

if (warnings.length > 0) {
    // Non bloccano, ma devono restare visibili: sono le eccezioni consapevoli.
    console.warn("! Eccezioni registrate:");
    console.warn("");
    for (const w of warnings) console.warn(`  · ${w}`);
    console.warn("");
}

if (errors.length > 0) {
    console.error("✗ Documenti legali e registro versioni non coerenti:\n");
    for (const e of errors) console.error(`  · ${e}`);
    console.error("");
    process.exit(1);
}

console.log("✓ Documenti legali coerenti con docs/legal/versions.json");
