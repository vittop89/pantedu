import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * Credenziali di classe del docente (piano classi-credenziali-scenari, C).
 *
 * Negli scenari 1 e 2 gli studenti non hanno un account: entrano con una
 * credenziale che il docente crea qui, legata alla sua classe. Il pannello
 * elenca le credenziali con scadenza e contatori (solo numeri, mai chi), e
 * offre: creazione, attiva/spegni, nuova password (l'username resta), nuova
 * scadenza, QR permanente, codice a tempo da proiettare in classe (dieci
 * minuti), nuovo QR, nuova etichetta, eliminazione.
 *
 * ADR-044 (19 settembre 2026):
 * - l'etichetta non si scrive più: la compone il server con classe, indirizzo,
 *   le materie scelte fra quelle spuntate dal docente e un'aggiunta facoltativa.
 *   Il modulo ne mostra l'anteprima chiedendola al server, così la
 *   composizione sta in un posto solo;
 * - le regole di username, password e aggiunta stanno nel server: la vista le
 *   stampa negli attributi dei campi e nel testo d'aiuto, e qui il testo
 *   d'aiuto diventa il messaggio del browser (setCustomValidity). I numeri
 *   (3, 64, 6…) non sono scritti in questo file;
 * - i codici d'errore del server diventano frasi in un posto solo,
 *   `messaggio()`, anche per la nuova password e la scadenza, che prima
 *   mostravano il codice grezzo («Non cambiata: weak_password»).
 *
 * La lista si rilegge dopo ogni mutazione con un URL sempre diverso: il
 * service worker serve /api/* in stale-while-revalidate, e senza questo la
 * riga nuova compariva solo al reload e «Spegni» sembrava volere due clic
 * (2026-09-06; l'endpoint e' anche network-first in sw.js).
 *
 * API: GET /api/teacher/credentials[?institute_id], GET .../anteprima-etichetta,
 *      POST /api/teacher/credentials,
 *      POST .../{id}/toggle|delete|password|expiry|etichetta|qr/regenerate,
 *      GET  .../{id}/qr.svg[?mode=temp]
 */

const API = "/api/teacher/credentials";

/** Codici il cui messaggio è la regola del campo: il testo d'aiuto della vista. */
export const AIUTO = {
    invalid_username:      ["Username non valido.", "fm-cred-username-help"],
    weak_password:         ["Password troppo corta.", "fm-cred-password-help"],
    password_troppo_lunga: ["Password troppo lunga.", "fm-cred-password-help"],
    password_non_valida:   ["Password con caratteri non ammessi.", "fm-cred-password-help"],
    aggiunta_non_valida:   ["Aggiunta non valida.", "fm-cred-aggiunta-help"],
};

/** Gli altri codici d'errore del server, in parole. */
export const MESSAGGI = {
    username_in_uso:        "Questo username è già usato: scegline un altro.",
    invalid_expiry:         "Scadenza non valida.",
    scadenza_passata:       "La scadenza è già passata: scegli oggi o una data futura.",
    invalid_institute:      "L'istituto non è fra le tue scuole: scegli quello attivo dal profilo.",
    invalid_classe:         "La classe scelta non è nel catalogo della scuola attiva.",
    sezione_non_ammessa:    "In questo istituto la sezione la usa solo il docente che ne ha l'incarico: scegli l'anno, che vale per tutte le sue sezioni.",
    invalid_indirizzo:      "L'indirizzo scelto non è nel catalogo della scuola attiva.",
    materia_non_tua:        "Una delle materie scelte non è fra quelle che hai spuntato in questo istituto.",
    etichetta_gia_usata:    "Hai già una credenziale con questa etichetta: scrivi un'aggiunta per distinguerle (per esempio GRUPPO-B).",
    etichetta_in_conflitto: "Riportandola in vita avresti due credenziali con la stessa etichetta: rigenera prima l'etichetta di una delle due, poi rimetti la scadenza.",
    etichetta_troppo_lunga: "Etichetta troppo lunga: togli qualche materia o accorcia l'aggiunta.",
    persist_failed:         "Non salvata: riprova tra poco.",
    not_found:              "Credenziale non trovata: ricarica la pagina.",
    db_unavailable:         "Servizio non disponibile: riprova tra poco.",
    unauthorized:           "Sessione scaduta: ricarica la pagina e accedi di nuovo.",
};

/** La frase per un codice d'errore del server. */
export function messaggio(codice, doc = document) {
    const aiuto = AIUTO[codice];
    if (aiuto) {
        const regola = doc.getElementById(aiuto[1])?.textContent?.trim() || "";
        return regola ? `${aiuto[0]} ${regola}` : aiuto[0];
    }
    return MESSAGGI[codice] || `Errore: ${codice || "sconosciuto"}`;
}

/**
 * Il fumetto del browser dice la regola del campo, non «Rispetta il formato
 * richiesto»: il testo è quello d'aiuto collegato con aria-describedby. Si
 * ricalcola a ogni battuta, così il campo torna valido appena lo è; il campo
 * vuoto resta al messaggio del browser («compila questo campo»).
 */
export function applicaRegola(input, doc = document) {
    if (!input) return;
    const aiuto = () => doc.getElementById(input.getAttribute("aria-describedby") || "")?.textContent?.trim() || "";
    const controlla = () => {
        input.setCustomValidity("");
        const v = input.validity;
        if (v.patternMismatch || v.tooShort || v.tooLong) input.setCustomValidity(aiuto());
    };
    input.addEventListener("input", controlla);
    input.addEventListener("invalid", controlla);
    controlla();
}

/**
 * Crea un elemento con attributi e figli (le stringhe diventano testo).
 *
 * L'elenco si costruisce con i nodi, non con `innerHTML` e una stringa
 * interpolata: etichette e username li scrive il docente, e un testo che
 * finisce nel markup va per forza escapato — con i nodi non c'è niente da
 * escapare. È anche quello che la regola semgrep `js-innerhtml-with-user-data`
 * conta, e il conto non deve salire (2026-09-13).
 */
function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === "dataset") { Object.assign(node.dataset, v); continue; }
        node.setAttribute(k, String(v));
    }
    node.append(...children.filter((c) => c !== null && c !== undefined && c !== false));
    return node;
}

async function post(url, fields = {}) {
    const csrf = await fetchCsrf();
    const body = new URLSearchParams({ _csrf: csrf, ...fields });
    return fetchJson(url, {
        method: "POST",
        cache: "no-store",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
    });
}

/** Conferma inline in due passi (niente confirm() del browser). */
function confirmInline(btn, action, timeoutMs = 4000) {
    if (btn.dataset.confirmPending === "1") return;
    const orig = btn.textContent;
    btn.dataset.confirmPending = "1";
    btn.textContent = "⚠ Conferma?";
    const handler = (e) => { e.preventDefault(); cleanup(); action(); };
    const cleanup = () => {
        btn.removeEventListener("click", handler);
        btn.textContent = orig;
        delete btn.dataset.confirmPending;
    };
    btn.addEventListener("click", handler, { once: true });
    setTimeout(cleanup, timeoutMs);
}

function fmtDate(s) {
    if (!s) return "—";
    const d = new Date(String(s).replace(" ", "T"));
    return Number.isNaN(d.getTime()) ? String(s) : d.toLocaleDateString("it-IT");
}

/** Oggi, nel fuso del browser, come YYYY-MM-DD: il minimo delle scadenze. */
function oggi() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** Caselle delle materie (tutte o quelle scelte spuntate), con la sigla come valore. */
function caselleMaterie(materie, nome, scelte = null) {
    return materie.map((m) => {
        const code = String(m.code ?? "");
        return el("label", { class: "fm-d-inline-flex fm-items-center fm-gap-1" },
            el("input", {
                type: "checkbox",
                name: nome,
                value: code,
                checked: scelte === null || scelte.includes(code.toUpperCase()) ? "" : undefined,
            }),
            el("strong", {}, code),
            " ",
            el("span", { class: "fm-muted" }, String(m.label ?? "")),
        );
    });
}

function sceltePer(root, nome) {
    return [...root.querySelectorAll(`input[type="checkbox"][name="${nome}"]:checked`)].map((i) => i.value);
}

export function initTeacherCredentials({ getInstituteId } = {}) {
    const list = document.getElementById("fm-cred-list");
    const form = document.getElementById("fm-cred-form");
    if (!list || !form) return { ricaricaMaterie: async () => {} };
    const feedback  = document.getElementById("fm-cred-feedback");
    const qrBox     = document.getElementById("fm-cred-qr");
    const qrImg     = document.getElementById("fm-cred-qr-img");
    const qrTitle   = document.getElementById("fm-cred-qr-title");
    const qrNote    = document.getElementById("fm-cred-qr-note");
    const scopeLabel = document.getElementById("fm-cred-scope-label");
    const materieBox = document.getElementById("fm-cred-materie-list");
    const anteprima  = document.getElementById("fm-cred-anteprima");
    const anteprimaNota = document.getElementById("fm-cred-anteprima-nota");
    const campoPassword = form.querySelector('input[name="password"]');
    const campoAggiunta = form.querySelector('input[name="aggiunta"]');
    const campoUsername = form.querySelector('input[name="username"]');
    const campoScadenza = form.querySelector('input[name="expires_at"]');

    const istituto = () => String((typeof getInstituteId === "function" && getInstituteId()) || "");

    const say = (msg, isError = false) => {
        if (!feedback) return;
        feedback.textContent = msg;
        feedback.classList.toggle("fm-text-danger", isError);
    };

    // Le regole dei campi: il fumetto del browser dice il testo d'aiuto.
    for (const input of [campoUsername, campoPassword, campoAggiunta]) applicaRegola(input);
    // Uno spazio in testa o in coda il server lo toglierebbe; il browser lo
    // rifiuterebbe col pattern. Si toglie qui, all'uscita dal campo.
    for (const input of [campoUsername, campoAggiunta]) {
        input?.addEventListener("change", () => {
            const t = input.value.trim();
            if (t !== input.value) { input.value = t; input.dispatchEvent(new Event("input")); }
        });
    }
    if (campoScadenza) campoScadenza.min = oggi();

    // ── Materie: quelle spuntate dal docente nell'istituto, per istituto ──
    const materieCache = new Map();
    let materieChiave = null;

    async function materieDi(iid) {
        const k = String(iid || "");
        if (materieCache.has(k)) return materieCache.get(k);
        const j = await fetchJson(`${API}?institute_id=${encodeURIComponent(k)}&t=${Date.now()}`, { cache: "no-store" });
        const materie = String(j.institute_id ?? "") === k || k === "" ? (j.materie_disponibili || []) : [];
        materieCache.set(k, materie);
        return materie;
    }

    function disegnaMaterie(materie) {
        if (!materieBox) return;
        const chiave = JSON.stringify(materie.map((m) => m.code));
        if (chiave === materieChiave) return;
        materieChiave = chiave;
        materieBox.replaceChildren(...(materie.length
            ? caselleMaterie(materie, "materie")
            : [el("span", { class: "fm-muted" }, "Nessuna materia spuntata in questo istituto: l'etichetta avrà classe, indirizzo e aggiunta.")]));
        aggiornaAnteprima();
    }

    async function ricaricaMaterie() {
        materieCache.clear();
        try {
            disegnaMaterie(await materieDi(istituto()));
        } catch (e) {
            materieBox?.replaceChildren(el("span", { class: "fm-text-danger" }, `Materie non disponibili: ${e.message || e}`));
        }
        aggiornaAnteprima();
    }

    /**
     * Legge l'elenco e lo disegna.
     *
     * 21/9/2026 — segnalazione dell'utente: «entrando nel profilo le
     * credenziali non si caricano, devo sempre riaggiornare la pagina per
     * vederle». Un tentativo solo non basta: la prima richiesta della pagina
     * può incontrare la verifica di sicurezza del WAF, o semplicemente non
     * rispondere, e quello che restava era una riga rossa (o «Caricamento…»
     * per sempre) con il ricaricamento a mano come unica via. Adesso ritenta
     * una volta da sé — il tempo di risolvere la verifica — e, se proprio non
     * arriva, lascia un pulsante invece di un vicolo cieco.
     *
     * @param {{secondoTentativo?: boolean}} [opzioni]
     */
    async function load({ secondoTentativo = false } = {}) {
        try {
            // URL sempre diverso: il service worker non puo' servire una copia stantia.
            const iid = istituto();
            const j = await fetchJson(`${API}?institute_id=${encodeURIComponent(iid)}&t=${Date.now()}`, { cache: "no-store" });
            // 21/9/2026 — un rifiuto arriva in JSON, e `fetchJson` lo
            // restituisce come un corpo qualunque: senza questa riga
            // `j.credentials` era `undefined`, e il pannello scriveva «Nessuna
            // credenziale ancora» a un docente che ne ha tre. Un elenco vuoto
            // e un elenco non arrivato sono due cose diverse, e vanno dette
            // diverse. (La verifica di sicurezza del WAF risponde proprio
            // così: 403 con `security_check_required`.)
            if (!j || j.error || j.ok === false) {
                throw new Error(String(j?.error || "risposta non riuscita"));
            }
            if (j.materie_disponibili) {
                materieCache.set(iid, j.materie_disponibili);
                disegnaMaterie(j.materie_disponibili);
            }
            render(j.credentials || []);
        } catch (e) {
            if (!secondoTentativo) {
                list.replaceChildren(el("p", { class: "fm-muted" }, "Non è arrivato l'elenco: riprovo…"));
                await new Promise((r) => setTimeout(r, 900));
                return load({ secondoTentativo: true });
            }
            const riprova = el("button", { type: "button", class: "fm-btn fm-btn--sm" }, "Riprova");
            riprova.addEventListener("click", () => { load(); });
            list.replaceChildren(
                el("p", { class: "fm-text-danger" },
                    `Le credenziali non sono arrivate (${e.message || e}). Non sono andate perse: è la lettura che non è riuscita.`),
                riprova,
            );
        }
    }

    /** Un comando della riga: `data-act` è la chiave che il gestore dei clic legge. */
    function comando(act, testo, title, danger = false) {
        return el("button", {
            type: "button",
            class: `fm-btn fm-btn--sm${danger ? " fm-btn--danger" : ""}`,
            dataset: { act },
            title,
        }, testo);
    }

    const righe = new Map();

    function riga(r) {
        const attiva = Boolean(Number(r.active));
        righe.set(String(Number(r.id)), r);
        return el("tr", { dataset: { id: String(Number(r.id)), active: attiva ? "1" : "0" }, class: attiva ? undefined : "fm-muted" },
            el("td", { class: "fm-cred-etichetta" }, el("strong", {}, String(r.label ?? ""))),
            el("td", {}, el("code", {}, String(r.access_username ?? ""))),
            el("td", {}, ...(r.classe
                ? [String(r.classe), r.indirizzo ? el("span", { class: "fm-muted" }, ` · ${r.indirizzo}`) : null]
                : [el("em", {}, "tutte")])),
            el("td", {}, attiva ? "attiva" : "spenta"),
            el("td", {}, el("input", {
                type: "date",
                class: "fm-input fm-cred-expiry",
                value: r.expires_at || "",
                min: oggi(),
                style: "max-width:10.5em",
                "aria-label": `Scadenza di ${r.label ?? ""}`,
                title: "Vuoto = senza scadenza. Da oggi in poi.",
            })),
            el("td", { title: `Ultimo ingresso: ${fmtDate(r.last_used_at)}` }, String(Number(r.use_count || 0))),
            el("td", { class: "fm-cred-actions" },
                comando("qr", "QR", "QR permanente della credenziale"),
                comando("temp", "⏱ 10 min", "Codice a tempo (10 minuti) da proiettare in classe"),
                comando("toggle", attiva ? "Spegni" : "Attiva"),
                comando("password", "Nuova password", "Cambia solo la password: l'username resta"),
                comando("etichetta", "Rigenera etichetta", "Ricomponi l'etichetta: classe, indirizzo, materie e aggiunta"),
                comando("requr", "Nuovo QR", "Nuovo QR: il vecchio smette di valere"),
                comando("delete", "Elimina", undefined, true),
            ),
        );
    }

    function render(rows) {
        righe.clear();
        if (!rows.length) {
            list.replaceChildren(el("p", { class: "fm-muted" }, "Nessuna credenziale ancora. Creane una qui sotto: è la porta dei tuoi studenti."));
            return;
        }
        const intestazioni = ["Etichetta", "Username", "Classe", "Stato", "Scadenza", "Usi", "Azioni"];
        list.replaceChildren(
            el("table", { class: "fm-table fm-text-13" },
                el("thead", {}, el("tr", {}, ...intestazioni.map((t) => el("th", { scope: "col" }, t)))),
                el("tbody", {}, ...rows.map(riga)),
            ),
            el("p", { class: "fm-muted fm-m-0" }, "Gli usi contano gli ingressi, non chi entra. Una credenziale spenta o scaduta cade dal portachiavi degli studenti al primo passaggio successivo, con un avviso. «Rigenera etichetta» ricompone l'etichetta: gli studenti già entrati la vedono cambiata al passaggio successivo."),
        );
    }

    // ── Perimetro: la coppia (indirizzo, classe) dei selettori in sidebar ──
    // I selettori seguono il curriculum dell'istituto e le classi portano il
    // loro corso (migrazione 100): la coppia e' coerente per costruzione. Qui
    // si mostra e si sceglie solo se delimitare o no; il server, per sicurezza,
    // fa comunque vincere l'indirizzo della classe.
    function sidebarScope() {
        const ind = document.getElementById("sel-iis");
        const cls = document.getElementById("sel-cls");
        const text = (sel) => (sel?.selectedOptions?.[0]?.text || "").trim();
        const indVal = ind?.value || "";
        const clsVal = cls?.value || "";
        const valid = indVal && clsVal && !ind.selectedOptions[0]?.disabled && !cls.selectedOptions[0]?.disabled;
        return valid
            ? { indirizzo: indVal, classe: clsVal, label: `${text(cls) || clsVal} · ${text(ind) || indVal}` }
            : null;
    }

    function refreshScopeLabel() {
        if (!scopeLabel) return;
        const s = sidebarScope();
        scopeLabel.textContent = s ? s.label : "nessuna classe selezionata";
        aggiornaAnteprima();
    }
    document.addEventListener("change", (e) => {
        if (e.target?.matches?.("#sel-iis, #sel-cls")) refreshScopeLabel();
    });
    window.addEventListener("fm:navigated", refreshScopeLabel);

    /** I campi del perimetro e dell'etichetta, come li legge il server. */
    function campiEtichetta() {
        const fd = new FormData(form);
        const delimita = String(fd.get("perimetro") || "classe") === "classe";
        const scope = delimita ? sidebarScope() : null;
        return {
            delimita,
            scope,
            fields: {
                classe:       scope ? scope.classe : "",
                indirizzo:    scope ? scope.indirizzo : "",
                institute_id: istituto(),
                materie:      sceltePer(form, "materie").join(","),
                aggiunta:     String(fd.get("aggiunta") || "").trim(),
            },
        };
    }

    // ── Anteprima dell'etichetta: la compone il server ────────────────
    let anteprimaTimer = null;
    let anteprimaTurno = 0;
    function aggiornaAnteprima() {
        if (!anteprima) return;
        clearTimeout(anteprimaTimer);
        anteprimaTimer = setTimeout(async () => {
            const turno = ++anteprimaTurno;
            const { delimita, scope, fields } = campiEtichetta();
            const nota = (t, errore = false) => {
                if (!anteprimaNota) return;
                anteprimaNota.textContent = t;
                anteprimaNota.classList.toggle("fm-text-danger", errore);
            };
            if (delimita && !scope) {
                anteprima.textContent = "—";
                nota("Seleziona indirizzo e classe nella sidebar, oppure scegli «Tutte le mie classi».");
                return;
            }
            if (campoAggiunta && !campoAggiunta.checkValidity()) {
                anteprima.textContent = "—";
                nota(messaggio("aggiunta_non_valida"), true);
                return;
            }
            try {
                const j = await fetchJson(`${API}/anteprima-etichetta?${new URLSearchParams({ ...fields, t: String(Date.now()) })}`, { cache: "no-store" });
                if (turno !== anteprimaTurno) return;
                if (!j.ok) {
                    anteprima.textContent = "—";
                    nota(messaggio(j.error), true);
                    return;
                }
                anteprima.textContent = String(j.label || "");
                nota(j.gia_usata ? messaggio("etichetta_gia_usata") : "", Boolean(j.gia_usata));
            } catch (e) {
                if (turno === anteprimaTurno) nota(`Anteprima non disponibile: ${e.message || e}`, true);
            }
        }, 250);
    }
    form.addEventListener("input", (e) => {
        if (e.target?.matches?.('input[name="aggiunta"]')) aggiornaAnteprima();
    });
    form.addEventListener("change", (e) => {
        if (e.target?.matches?.('input[name="materie"], input[name="perimetro"]')) aggiornaAnteprima();
    });

    // ── Azioni sulle righe ─────────────────────────────────────────────
    function showQr(id, label, mode) {
        if (!qrBox || !qrImg) return;
        const temp = mode === "temp";
        qrImg.src = `${API}/${id}/qr.svg${temp ? "?mode=temp&" : "?"}t=${Date.now()}`;
        // 21/9/2026 — l'username accanto all'etichetta: questo riquadro è
        // quello che si proietta o si mostra alla classe, e chi non riesce a
        // scansionare il codice la credenziale deve poterla digitare.
        const nome = String(righe.get(String(Number(id)))?.access_username || "");
        if (qrTitle) {
            qrTitle.textContent = `${temp ? "Codice a tempo" : "QR"} — ${label}`
                + (nome ? ` · username «${nome}»` : "");
        }
        if (qrNote) {
            qrNote.textContent = temp
                ? "Vale dieci minuti: proiettalo, chi è in classe lo scansiona ed entra senza digitare nulla. Poi muore."
                : "Vale quanto la credenziale: chi lo scansiona entra senza digitare nulla. Da mostrare solo a chi riceverebbe la password.";
        }
        qrBox.hidden = false;
        qrBox.scrollIntoView({ block: "nearest" });
    }

    /** Il campo della nuova password: stesse regole e stesso aiuto del modulo. */
    function campoNuovaPassword() {
        const input = el("input", {
            type: "text",
            class: "fm-input",
            "aria-label": "Nuova password",
            placeholder: "nuova password",
            style: "max-width:12em",
            autocomplete: "new-password",
        });
        for (const a of ["minlength", "maxlength", "pattern", "title", "aria-describedby"]) {
            const v = campoPassword?.getAttribute(a);
            if (v !== null && v !== undefined) input.setAttribute(a, v);
        }
        applicaRegola(input);
        return input;
    }

    /** L'editor dell'etichetta di una riga: materie e aggiunta, poi il server ricompone. */
    async function apriEtichetta(tr, id) {
        const cell = tr.querySelector("td.fm-cred-etichetta");
        if (!cell || cell.querySelector(".fm-cred-etichetta-edit")) return;
        const r = righe.get(id) || {};
        let materie = [];
        try {
            materie = await materieDi(r.institute_id ?? istituto());
        } catch (e) {
            say(`Materie non disponibili: ${e.message || e}`, true);
        }
        const scelte = r.materie === null || r.materie === undefined
            ? null
            : String(r.materie).split(",").filter(Boolean).map((s) => s.toUpperCase());
        const aggiunta = el("input", {
            type: "text",
            class: "fm-input",
            name: "aggiunta",
            value: r.aggiunta || "",
            "aria-label": "Aggiunta all'etichetta",
            placeholder: "aggiunta (facoltativa)",
            style: "max-width:10em",
        });
        for (const a of ["maxlength", "pattern", "title", "aria-describedby"]) {
            const v = campoAggiunta?.getAttribute(a);
            if (v !== null && v !== undefined) aggiunta.setAttribute(a, v);
        }
        applicaRegola(aggiunta);
        const box = el("div", { class: "fm-cred-etichetta-edit", role: "group", "aria-label": "Nuova etichetta" },
            ...caselleMaterie(materie, "materie-riga", scelte),
            aggiunta,
            el("button", { type: "button", class: "fm-btn fm-btn--sm fm-btn--primary", dataset: { act: "etichetta-save" } }, "Salva etichetta"),
            el("button", { type: "button", class: "fm-btn fm-btn--sm", dataset: { act: "etichetta-cancel" } }, "Annulla"),
        );
        cell.appendChild(box);
        aggiunta.focus();
    }

    list.addEventListener("click", async (e) => {
        const btn = e.target.closest("button[data-act]");
        if (!btn) return;
        const tr = btn.closest("tr[data-id]");
        const id = tr?.dataset.id;
        if (!id) return;
        const label = tr.querySelector("td strong")?.textContent || "";
        const act = btn.dataset.act;
        try {
            if (act === "qr" || act === "temp") { showQr(id, label, act === "temp" ? "temp" : "permanent"); return; }
            if (act === "toggle") {
                // Lo stato vero sta nella riga, non nel testo del pulsante.
                const active = tr.dataset.active === "1" ? "" : "1";
                btn.disabled = true;
                await post(`${API}/${id}/toggle`, { active });
                await load();
                return;
            }
            if (act === "password") {
                // Campo inline, niente prompt() del browser: l'username resta.
                const cell = btn.closest("td");
                if (!cell || cell.querySelector(".fm-cred-pwd-inline")) return;
                const input = campoNuovaPassword();
                const wrap = el("span", { class: "fm-cred-pwd-inline" }, " ", input,
                    el("button", { type: "button", class: "fm-btn fm-btn--sm fm-btn--primary", dataset: { act: "password-save" } }, "Salva"),
                    el("button", { type: "button", class: "fm-btn fm-btn--sm", dataset: { act: "password-cancel" } }, "Annulla"),
                );
                cell.appendChild(wrap);
                input.focus();
                return;
            }
            if (act === "password-cancel") { btn.closest(".fm-cred-pwd-inline")?.remove(); return; }
            if (act === "password-save") {
                const wrap = btn.closest(".fm-cred-pwd-inline");
                const input = wrap?.querySelector("input");
                if (!input) return;
                if (!input.reportValidity()) return;
                const j = await post(`${API}/${id}/password`, { password: input.value });
                say(j.ok ? "Password cambiata: gli studenti reinseriscono solo quella." : `Non cambiata. ${messaggio(j.error)}`, !j.ok);
                if (j.ok) wrap?.remove();
                return;
            }
            if (act === "etichetta") { await apriEtichetta(tr, id); return; }
            if (act === "etichetta-cancel") { btn.closest(".fm-cred-etichetta-edit")?.remove(); return; }
            if (act === "etichetta-save") {
                const box = btn.closest(".fm-cred-etichetta-edit");
                const aggiunta = box?.querySelector('input[name="aggiunta"]');
                if (!box || (aggiunta && !aggiunta.reportValidity())) return;
                btn.disabled = true;
                const j = await post(`${API}/${id}/etichetta`, {
                    materie: sceltePer(box, "materie-riga").join(","),
                    aggiunta: String(aggiunta?.value || "").trim(),
                });
                btn.disabled = false;
                if (!j.ok) { say(`Etichetta non cambiata. ${messaggio(j.error)}`, true); return; }
                say(`Nuova etichetta: ${j.label}. Gli studenti già entrati la vedono al passaggio successivo.`);
                await load();
                return;
            }
            if (act === "requr") {
                confirmInline(btn, async () => {
                    await post(`${API}/${id}/qr/regenerate`);
                    say("Nuovo QR generato: il vecchio non vale più.");
                    if (qrBox && !qrBox.hidden) showQr(id, label, "permanent");
                });
                return;
            }
            if (act === "delete") {
                confirmInline(btn, async () => {
                    await post(`${API}/${id}/delete`);
                    await load();
                });
            }
        } catch (ex) {
            say(`Errore: ${ex.message || ex}`, true);
            btn.disabled = false;
        }
    });

    list.addEventListener("change", async (e) => {
        const input = e.target.closest("input.fm-cred-expiry");
        if (!input) return;
        const id = input.closest("tr[data-id]")?.dataset.id;
        if (!id) return;
        try {
            const j = await post(`${API}/${id}/expiry`, { expires_at: input.value || "" });
            say(j.ok ? (input.value ? `Scadenza: ${fmtDate(input.value)}.` : "Senza scadenza.") : `Scadenza non salvata. ${messaggio(j.error)}`, !j.ok);
            // Rifiutata: la riga torna a dire la scadenza vera.
            if (!j.ok) await load();
        } catch (ex) {
            say(`Errore: ${ex.message || ex}`, true);
        }
    });

    document.getElementById("fm-cred-qr-close")?.addEventListener("click", () => { if (qrBox) qrBox.hidden = true; });

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const { delimita, scope, fields } = campiEtichetta();
        if (delimita && !scope) {
            say("Seleziona indirizzo e classe nella sidebar, oppure scegli «Tutte le mie classi».", true);
            return;
        }
        const fd = new FormData(form);
        // Si legge prima del `reset()` qui sotto: dopo, il campo è vuoto.
        const scritto = String(fd.get("username") || "").trim();
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const j = await post(API, {
                ...fields,
                username:   scritto,
                password:   String(fd.get("password") || ""),
                expires_at: String(fd.get("expires_at") || ""),
            });
            if (!j.ok) throw new Error(j.error || "creazione non riuscita");
            form.reset();
            for (const input of [campoUsername, campoPassword, campoAggiunta]) input?.setCustomValidity("");
            refreshScopeLabel();
            // 21/9/2026 — l'username nel messaggio, non solo nella tabella:
            // è la metà della cosa da consegnare (l'altra è la password, che
            // l'hai appena scritta tu e che il server non ti ridice).
            const nome = String(j.access_username || scritto).trim();
            say(`Credenziale creata: ${j.label || ""}${nome ? ` — username «${nome}»` : ""}. `
                + "Dalla riga puoi mostrare il QR o il codice a tempo.");
            await load();
        } catch (ex) {
            say(messaggio(ex.message), true);
        } finally {
            if (submit) submit.disabled = false;
        }
    });

    refreshScopeLabel();
    load();
    return { ricaricaMaterie };
}
