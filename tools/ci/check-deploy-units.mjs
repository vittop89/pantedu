#!/usr/bin/env node
/**
 * Coerenza fra gli script del rilascio e le unità systemd che li lanciano.
 *
 * Perché esiste. L'8 settembre 2026 `pantedu-deploy.service` dichiarava
 * `SuccessExitStatus=1`, ma `1` è il codice con cui `deploy.sh` esce **quando
 * il rilascio è fallito**. Risultato: ogni rilascio andato male veniva
 * registrato come riuscito, l'unità non poteva finire in `failed` nemmeno
 * volendo, e nessun `OnFailure=` avrebbe potuto scattare. Il difetto stava
 * nello spazio fra due file — nessuno dei due era sbagliato da solo — ed è
 * esattamente il posto dove non guarda nessuno.
 *
 * Questa guardia non esegue niente: legge i file e verifica che si accordino.
 * Un banco che esegua `deploy.sh` per davvero richiederebbe una macchina con
 * systemd, nginx e un database, e non è quello che serve qui: serve che le
 * dichiarazioni non si contraddicano.
 *
 * Gira in `npm run ci` dentro le «Guardie del progetto».
 */

import { readFileSync, readdirSync, existsSync } from "node:fs";
import { join, basename } from "node:path";

const RADICE = new URL("../..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const UNITA_DIR = join(RADICE, "tools", "systemd");

/** Le unità dove un guasto silenzioso ha conseguenze: devono avvisare. */
const DEVONO_AVVISARE = [
    "pantedu-deploy.service",
    "pantedu-backup-encrypted.service",
    "pantedu-gdpr-deletions.service",
    "pantedu-gdpr-retention.service",
    "pantedu-audit-chain.service",
    "pantedu-ensure-config.service",
    // 2026-09-09 — la diagnostica esiste apposta per rendere rumorosi i guasti
    // silenziosi: se fallisse in silenzio non servirebbe a niente.
    "pantedu-diagnostica.service",
    // 2026-09-09 — questi due erano righe nel crontab di un utente cancellato
    // col rinominamento del progetto: cron le saltava e lo diceva solo con un
    // «ORPHAN» che nessuno legge. Rifatti come unita' proprio perche' un
    // guasto si senta.
    "pantedu-compile-jobs.service",
    "pantedu-drive-sync.service",
    // 2026-09-23 — la pulizia di `rate_limits` applica una conservazione
    // dichiarata nel registro dei trattamenti e nella DPIA: se smettesse in
    // silenzio, i due documenti tornerebbero falsi senza che nessuno lo sappia.
    "pantedu-rate-limit-cleanup.service",
];

const problemi = [];
const leggi = (p) => readFileSync(p, "utf8");
const file = readdirSync(UNITA_DIR);

// ── 1. L'uscita 1 significa guasto: non può essere dichiarata un successo ────
//
// `deploy.sh` accumula i passi falliti e esce 1. Se un'unità dichiara `1` fra
// i codici di successo, quel meccanismo non esiste.
for (const nome of file.filter((f) => f.endsWith(".service"))) {
    const testo = leggi(join(UNITA_DIR, nome));
    const m = testo.match(/^SuccessExitStatus\s*=\s*(.+)$/m);
    if (!m) continue;
    const codici = m[1].trim().split(/\s+/);
    if (codici.includes("1")) {
        problemi.push(
            `${nome}: SuccessExitStatus contiene 1, ma 1 è il codice del GUASTO.\n` +
            "    Con quella riga un rilascio fallito passa per riuscito e l'unità\n" +
            "    non finisce mai in failed. Usare un codice diverso per il caso\n" +
            "    che non è un guasto (vedi pantedu-deploy-trigger.sh, che usa 3).",
        );
    }
}

// ── 2. I codici «non è un guasto» degli script stanno in SuccessExitStatus ──
//
// Se lo script del trigger esce con un codice che l'unità non conosce, un
// doppione ignorato diventa un allarme.
const coppie = [
    { script: "pantedu-deploy-trigger.sh", unita: "pantedu-deploy.service" },
];
for (const { script, unita } of coppie) {
    const pScript = join(UNITA_DIR, script);
    const pUnita = join(UNITA_DIR, unita);
    if (!existsSync(pScript) || !existsSync(pUnita)) continue;

    const testoScript = leggi(pScript);
    const testoUnita = leggi(pUnita);
    const dichiarati = (testoUnita.match(/^SuccessExitStatus\s*=\s*(.+)$/m)?.[1] ?? "")
        .trim().split(/\s+/).filter(Boolean);

    // Gli `exit N` con N diverso da 0 e da 1: sono i «non è un guasto».
    const usati = [...testoScript.matchAll(/^\s*exit\s+(\d+)/gm)]
        .map((x) => x[1])
        .filter((c) => c !== "0" && c !== "1");

    for (const codice of new Set(usati)) {
        if (!dichiarati.includes(codice)) {
            problemi.push(
                `${script} esce con ${codice}, ma ${unita} non lo elenca in\n` +
                `    SuccessExitStatus (${dichiarati.join(" ") || "assente"}).\n` +
                "    Quel caso finirebbe in failed e farebbe partire un avviso.",
            );
        }
    }
}

// ── 3. Le unità che contano avvisano quando falliscono ──────────────────────
for (const nome of DEVONO_AVVISARE) {
    const p = join(UNITA_DIR, nome);
    if (!existsSync(p)) {
        problemi.push(`${nome} è nell'elenco di quelle che devono avvisare, ma il file non c'è.`);
        continue;
    }
    if (!/^OnFailure\s*=/m.test(leggi(p))) {
        problemi.push(
            `${nome}: manca OnFailure=. Un guasto qui resterebbe nel giornale,\n` +
            "    che nessuno legge (è il difetto corretto l'8 settembre 2026).",
        );
    }
}

// ── 4. Ogni OnFailure punta a un'unità che esiste ───────────────────────────
for (const nome of file.filter((f) => f.endsWith(".service") || f.endsWith(".timer"))) {
    const testo = leggi(join(UNITA_DIR, nome));
    for (const m of testo.matchAll(/^OnFailure\s*=\s*(\S+)/gm)) {
        // `pantedu-avviso@%n.service` → il file è `pantedu-avviso@.service`.
        const bersaglio = m[1].replace(/@[^.]*\./, "@.");
        if (!file.includes(bersaglio)) {
            problemi.push(`${nome}: OnFailure punta a ${m[1]}, ma ${bersaglio} non esiste in tools/systemd/.`);
        }
    }
}

// ── 5. Ogni ExecStart punta a un file che sta nel repository ────────────────
//
// Le unità sono copiate verbatim sul server: un percorso sbagliato si scopre
// solo quando serve, cioè nel momento peggiore.
const ATTESI = {
    "/usr/local/bin/pantedu-deploy.sh": "tools/webhook/deploy.sh",
    "/usr/local/bin/pantedu-deploy-container.sh": "tools/webhook/deploy-container.sh",
    "/usr/local/bin/pantedu-deploy-trigger.sh": "tools/systemd/pantedu-deploy-trigger.sh",
    "/usr/local/bin/pantedu-deploy-differito.sh": "tools/systemd/pantedu-deploy-differito.sh",
    "/usr/local/bin/pantedu-finestra-rilascio.sh": "tools/systemd/pantedu-finestra-rilascio.sh",
};
for (const nome of file.filter((f) => f.endsWith(".service"))) {
    const testo = leggi(join(UNITA_DIR, nome));
    for (const m of testo.matchAll(/^Exec(?:Start|StartPre)\s*=\s*(\S+)/gm)) {
        const percorso = m[1].replace(/^-/, "");
        const sorgente = ATTESI[percorso];
        if (sorgente === undefined) continue;   // non è uno dei nostri
        if (!existsSync(join(RADICE, sorgente))) {
            problemi.push(`${nome}: ExecStart punta a ${percorso}, che dovrebbe venire da ${sorgente} — assente.`);
        }
    }
}

// ── 6. Il socket di MariaDB si monta con --mount, e Docker parte dopo MariaDB ─
//
// 15/9/2026: al riavvio Docker è partito insieme a MariaDB e, con `-v`, ha creato
// una cartella al posto del socket mancante; MariaDB non è più partita, e con
// lei il sito. Con `--mount` un socket mancante ferma il container e non crea
// niente (misurato sul VPS). Qui si impedisce che `-v` torni sul socket negli
// script che creano il container, e che l'ordine all'avvio sparisca.
const SCRIPT_DEL_CONTAINER = ["tools/webhook/deploy-container.sh", "tools/webhook/passa-ai-container.sh"];
for (const rel of SCRIPT_DEL_CONTAINER) {
    const p = join(RADICE, rel);
    if (!existsSync(p)) continue;
    const righe = leggi(p).split("\n").filter((r) => !/^\s*#/.test(r));
    for (const riga of righe) {
        if (/(?:^|\s)(?:-v|--volume)\s+\S*\/run\/mysqld\//.test(riga)) {
            problemi.push(
                `${rel}: il socket di MariaDB è montato con -v («${riga.trim()}»).\n` +
                "    Se all'avvio il socket manca, Docker crea una cartella al suo posto e\n" +
                "    MariaDB non riparte (15/9/2026). Usare --mount type=bind,src=…,dst=….",
            );
        }
    }
}
const DOPO_MARIADB = join(UNITA_DIR, "docker.service.d", "pantedu-dopo-mariadb.conf");
if (!existsSync(DOPO_MARIADB)) {
    problemi.push("tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf non c'è: all'avvio Docker può partire prima di MariaDB.");
} else {
    const testo = leggi(DOPO_MARIADB);
    if (!/^After\s*=.*\bmariadb\.service\b/m.test(testo)) {
        problemi.push("tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf: manca After=mariadb.service.");
    }
}

// ── 7. Il servizio TeX: il rilascio lo aggiorna, e i container lo raggiungono ─
//
// 19/9/2026. Il TeX è rimasto sull'host quando l'applicazione è passata nel
// container (8/9/2026), e due cose si sono perse nel passaggio, in silenzio:
//
//   - il rilascio vecchio portava in /opt il codice del servizio quando
//     cambiava; quello a container no. Qui si pretende il passo 7-bis
//     (`passo_tex`), chiamato davvero e non solo definito;
//   - il servizio ascoltava su 127.0.0.1, che dal container è il container
//     stesso: undici giorni senza compilazioni dal sito. L'unità deve partire
//     dopo Docker (ascolta sul gateway del bridge, che nasce con Docker) e non
//     legarsi al loopback. E il rilascio deve chiedere /health/tex.
const RILASCIO = join(RADICE, "tools", "webhook", "deploy-container.sh");
if (existsSync(RILASCIO)) {
    const testo = leggi(RILASCIO);
    const codice = testo.split("\n").filter((r) => !/^\s*#/.test(r)).join("\n");
    const blocco = testo.match(/^# >>> tex:[\s\S]*?^# <<< tex/m)?.[0] ?? "";
    if (blocco === "") {
        problemi.push(
            "tools/webhook/deploy-container.sh: manca il blocco fra `# >>> tex:` e `# <<< tex`.\n" +
            "    Lì stanno le funzioni che portano in /opt il codice del servizio TeX,\n" +
            "    e la prova tests/ops/sincronizza-tex.test.sh le prende da lì.",
        );
    } else {
        for (const [cosa, re] of [
            ["il confronto su tools/tex-compile-vps/app", /TEX_SORGENTE_REL="tools\/tex-compile-vps\/app"/],
            ["il riavvio del servizio", /systemctl restart "\$TEX_SERVIZIO"/],
            ["la sonda dopo il riavvio", /tex_sonda/],
            // 20/9/2026 — il confronto è sui file installati, non su due
            // commit: il `reset --hard` del passo 1 viene prima di ogni uscita
            // anticipata, quindi `git diff PRIMA COMMIT` non vede il codice
            // che un rilascio uscito a metà ha lasciato in /opt.
            ["il confronto `cmp` fra i file del repository e quelli installati", /cmp -s "\$f" "\$TEX_DIR\/app\//],
        ]) {
            if (!re.test(blocco)) {
                problemi.push(`tools/webhook/deploy-container.sh: nel blocco del TeX manca ${cosa}.`);
            }
        }
        if (/git_come_proprietario diff[^\n]*TEX_SORGENTE_REL/.test(blocco)) {
            problemi.push(
                "tools/webhook/deploy-container.sh: il passo del TeX decide con `git diff` fra due commit.\n" +
                "    Il reset del passo 1 viene prima di ogni uscita anticipata: un rilascio\n" +
                "    che porta codice del TeX e poi esce lascia /opt indietro per sempre.\n" +
                "    Confrontare i file installati (docs/ops/tex-dal-container.md).",
            );
        }
    }
    if (!/^passo_tex\s*$/m.test(codice)) {
        problemi.push(
            "tools/webhook/deploy-container.sh: `passo_tex` non è chiamato.\n" +
            "    Definire il passo senza chiamarlo è il modo più silenzioso di non averlo.",
        );
    }
    if (!/https:\/\/127\.0\.0\.1\/health\/tex/.test(codice)) {
        problemi.push(
            "tools/webhook/deploy-container.sh: il passo 8 non chiede /health/tex attraverso nginx.\n" +
            "    Senza, il rilascio non sa se il container nuovo raggiunge il servizio TeX.",
        );
    }
}
const UNITA_TEX = join(RADICE, "tools", "tex-compile-vps", "systemd", "tex-compile.service");
if (existsSync(UNITA_TEX)) {
    const testo = leggi(UNITA_TEX);
    if (!/^After\s*=.*\bdocker\.service\b/m.test(testo)) {
        problemi.push(
            "tools/tex-compile-vps/systemd/tex-compile.service: manca After=docker.service.\n" +
            "    Il servizio ascolta sul gateway del bridge Docker, che esiste solo dopo Docker.",
        );
    }
    if (/^ExecStart\s*=.*--host\s+(127\.0\.0\.1|localhost)\b/m.test(testo)) {
        problemi.push(
            "tools/tex-compile-vps/systemd/tex-compile.service: ascolta sul loopback.\n" +
            "    Dal container 127.0.0.1 è il container stesso: il TeX non si raggiunge (8/9/2026).",
        );
    }
}

// ── 8. Chi scrive nel registro delle anomalie da root lo lascia al gruppo ───
//
// 19/9/2026: `anomalie.jsonl` era 0640 e il container (`www-data`, che sta solo
// nel gruppo) non ci scriveva. Le righe dell'applicazione si perdevano in
// silenzio. Gli strumenti di root che ci scrivono devono quindi rimettere
// gruppo **e** permessi ogni volta — anche perché uno di loro può essere il
// primo a creare il file, e con la umask di root nascerebbe 0644.
//
// 20/9/2026: `versioni-indietro.sh` faceva il `chown` e non il `chmod`, mentre
// docs/ops/diagnostica.md prometteva tutti e due. Questa guardia impedisce che
// la promessa e il codice tornino a divergere.
const SCRITTORI_ANOMALIE = ["tools/ops", "tools/webhook", "tools/backup", "tools/systemd"];
for (const cartella of SCRITTORI_ANOMALIE) {
    const dir = join(RADICE, ...cartella.split("/"));
    if (!existsSync(dir)) continue;
    for (const nome of readdirSync(dir).filter((f) => f.endsWith(".sh"))) {
        const testo = leggi(join(dir, nome));
        if (!/>>\s*"\$ANOMALIE"/.test(testo)) continue;
        for (const [cosa, re] of [
            ["chown pantedu:www-data \"$ANOMALIE\"", /chown pantedu:www-data "\$ANOMALIE"/],
            ["chmod 0660 \"$ANOMALIE\"", /chmod 0660 "\$ANOMALIE"/],
        ]) {
            if (!re.test(testo)) {
                problemi.push(
                    `${cartella}/${nome}: scrive nel registro delle anomalie ma non fa ${cosa}.\n` +
                    "    Il registro lo leggono e lo scrivono utenti diversi (pantedu dall'host,\n" +
                    "    www-data dal container): un file 0644 o con un altro gruppo fa sparire\n" +
                    "    le righe dell'applicazione, in silenzio (19/9/2026).",
                );
            }
        }
    }
}

if (problemi.length > 0) {
    console.error("Incoerenze fra gli script del rilascio e le unità systemd:\n");
    for (const p of problemi) console.error("  ✗ " + p + "\n");
    console.error(`${problemi.length} ${problemi.length === 1 ? "problema" : "problemi"}.`);
    process.exit(1);
}

const quante = file.filter((f) => f.endsWith(".service")).length;
console.log(`✓ Rilascio: ${quante} unità coerenti con gli script che lanciano.`);
