/**
 * GoogleDriveLatexSaver — estratto da script_sel-mod.js (Phase 9k).
 * G26.phase4.6 — migrato a vanilla JS (no jQuery).
 * Salva i .tex generati via Google Drive webhook.
 */
import { Endpoints } from "../core/endpoints.js";

/** Stub no-op compatibile col vecchio return $() (chain remove/html/css/attr). */
function noopNotification() {
    const stub = {
        remove() {},
        html() { return stub; },
        css() { return stub; },
        attr() { return stub; },
        length: 0,
    };
    return stub;
}

/** Helpers DOM. */
const byId = (id) => document.getElementById(id);
const getVal = (id) => byId(id)?.value || "";

/** Risolve un selettore in elemento (string CSS o reference). */
function resolveEl(sel) {
    if (!sel) return null;
    if (typeof sel === "string") return document.querySelector(sel);
    if (sel.nodeType === 1) return sel;
    // window.parent.document fallback
    if (sel.ownerDocument) return sel;
    return null;
}

/** Blink field background via setInterval (replace $.animate). */
function blinkField(sel) {
    const el = resolveEl(sel);
    if (!el) return;
    let count = 0;
    const orig = el.style.backgroundColor || "";
    const id = setInterval(() => {
        el.style.backgroundColor = count % 2 === 0 ? "#ff3333" : "#ffffff";
        count++;
        if (count >= 6) {
            clearInterval(id);
            el.style.backgroundColor = orig;
            try { el.focus(); } catch (_) { /* noop */ }
        }
    }, 500);
    try { el.focus(); } catch (_) { /* noop */ }
}

/** Fade out + remove elements matching CSS selector. */
function fadeOutAndRemove(selector, duration = 400) {
    document.querySelectorAll(selector).forEach((el) => {
        el.style.transition = `opacity ${duration}ms`;
        el.style.opacity = "0";
        setTimeout(() => el.remove(), duration);
    });
}

export const GoogleDriveLatexSaver = {
    _lastGeneratedContent: null,

    setLatestGeneratedContent(content) {
        this._lastGeneratedContent = content;
        console.log("📝 [GoogleDriveLatexSaver] Contenuto LaTeX salvato per Drive (lunghezza:", content ? content.length : 0, "caratteri)");
    },

    async getLatestGeneratedContent() {
        return this._lastGeneratedContent;
    },

    async saveLatexToDrive(content, fileName, providedParams = null, options = {}) {
        try {
            console.log("💾 [GoogleDriveLatexSaver] Avvio salvataggio LaTeX su Drive e Server:", fileName);
            console.log("🔍 [GoogleDriveLatexSaver] Verifica disponibilità AppState:", typeof window !== "undefined" && window.AppState ? "DISPONIBILE" : "NON DISPONIBILE");

            const startNotification = this.showNotification("info", `📝 Preparazione salvataggio: ${fileName}.tex`, true);

            const classParams = providedParams || this.getClassParameters();
            console.log("📊 [GoogleDriveLatexSaver] Parametri usati:", providedParams ? "FORNITI" : "AUTO-RILEVATI", classParams);

            if (!classParams.selectedCLS || !classParams.selectedMATER) {
                startNotification.remove();
                throw new Error("Impossibile determinare classe e/o materia dalla UI corrente");
            }

            const verTitleRaw = getVal("verTitle") || "ALL";
            const verTitlePrefix = getVal("verTitlePrefix") || "VERIFICA:";

            let verTitle = verTitleRaw.toLowerCase().trim();
            if (verTitle && verTitle !== "all") {
                if (verTitlePrefix === "VERIFICA DI RECUPERO:") {
                    verTitle = `rec_${verTitle}`;
                }
            }
            const materia = classParams.selectedMATER || "materia_sconosciuta";
            const anno = getVal("anno");
            const sezione = getVal("sezione");
            const classe = classParams.selectedCLS || "";
            const indirizzo = classParams.selectedIIS || "";

            function validateRequiredField({ value, fieldSelector, fieldName, notification, errorMsg }) {
                if (!value || value === "" || value === "ALL" || value === "_senza_titolo") {
                    if (notification && notification.remove) notification.remove();
                    alert(errorMsg);
                    console.error(`❌ [GoogleDriveLatexSaver] Salvataggio bloccato: ${fieldName} mancante o non valido`);
                    blinkField(fieldSelector);
                    setTimeout(() => fadeOutAndRemove(".google-drive-notification.error"), 4000);
                    throw new Error(`${fieldName} mancante o non valido`);
                }
            }

            // Cross-frame: usa window.parent.document se disponibile
            const parentDoc = (window.parent && window.parent !== window) ? window.parent.document : document;
            const parentSel = (id) => parentDoc.getElementById(id) || `#${id}`;

            validateRequiredField({
                value: anno,
                fieldSelector: "#anno",
                fieldName: "Anno scolastico",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Anno scolastico" è obbligatorio.\n\nInserisci un anno valido prima di salvare il file LaTeX.',
            });

            validateRequiredField({
                value: sezione,
                fieldSelector: "#sezione",
                fieldName: "Sezione",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Sezione" è obbligatorio.\n\nInserisci una sezione valida prima di salvare il file LaTeX.',
            });

            validateRequiredField({
                value: verTitle,
                fieldSelector: "#verTitle",
                fieldName: "Titolo verifica",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Titolo verifica" è obbligatorio.\n\nInserisci un titolo valido prima di salvare il file LaTeX.',
            });

            validateRequiredField({
                value: materia,
                fieldSelector: parentSel("sel-mater"),
                fieldName: "Materia",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Materia" è obbligatorio.\n\nSeleziona una materia valida prima di salvare il file LaTeX.',
            });

            validateRequiredField({
                value: classe,
                fieldSelector: parentSel("sel-cls"),
                fieldName: "Classe",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Classe" è obbligatorio.\n\nSeleziona una classe valida prima di salvare il file LaTeX.',
            });

            validateRequiredField({
                value: indirizzo,
                fieldSelector: parentSel("sel-iis"),
                fieldName: "Indirizzo",
                notification: startNotification,
                errorMsg: '⚠️ ATTENZIONE!\n\nIl campo "Indirizzo" è obbligatorio.\n\nSeleziona un indirizzo valido prima di salvare il file LaTeX.',
            });

            const versione = getVal("versione");
            const isDSA = byId("DSA")?.checked === true;
            const nPrintDSA = getVal("nPrintDSA");
            const nPrintDIS = getVal("nPrintDIS");

            let versionFolder = "";
            if (versione) {
                versionFolder = versione;
                const suffixes = [];
                if (isDSA && nPrintDSA && parseInt(nPrintDSA) > 0) suffixes.push("DSA");
                if (isDSA && nPrintDIS && parseInt(nPrintDIS) > 0) suffixes.push("DIS");
                if (suffixes.length > 0) versionFolder += `_${suffixes.join("-")}`;
            }

            console.log(`📚 [GoogleDriveLatexSaver] Parametri finali:`, classParams);
            console.log(`📝 [GoogleDriveLatexSaver] Titolo verifica (pulito):`, verTitle);
            console.log(`📂 [GoogleDriveLatexSaver] Sottocartella versione:`, versionFolder || "NESSUNA");

            // No-op .html() su startNotification (showNotification ritorna stub)
            startNotification.html(`⏳ Salvataggio in corso: ${fileName}.tex<br><small>Classe: ${classParams.optsel} | Materia: ${classParams.selectedMATER}${versionFolder ? ` | ${versionFolder}` : ""}</small>`);

            const skipServerSave = options.skipServerSave === true;
            const skipDriveWebhook = options.skipDriveWebhook === true;
            const serverSavePromise = skipServerSave
                ? Promise.resolve({ success: true, skipped: true })
                : this.saveLatexToServer(content, fileName, classParams, verTitle, versionFolder);

            const webhookData = {
                action: "save-latex-to-drive",
                selectedIIS: classParams.selectedIIS,
                selectedCLS: classParams.selectedCLS,
                selectedMATER: classParams.selectedMATER,
                fileName,
                content,
                optsel: classParams.optsel,
                verTitle,
                versionFolder,
                timestamp: new Date().toISOString(),
            };

            console.log("📤 [GoogleDriveLatexSaver] Dati preparati per webhook:", {
                action: webhookData.action,
                useSecureProxy: true,
                classParams,
                contentLength: webhookData.content.length,
            });

            const driveResponse = skipDriveWebhook ? { success: true, skipped: true } : await this.callWebhook(webhookData);
            const serverResponse = await serverSavePromise;

            startNotification.css({ opacity: 0, transform: "translateX(20px)" });
            setTimeout(() => startNotification.remove(), 300);

            const driveSuccess = driveResponse.success;
            const serverSuccess = serverResponse.success;

            if ((driveSuccess || skipDriveWebhook) && (serverSuccess || skipServerSave)) {
                const driveLabel = skipDriveWebhook ? "" : " | 📁 Drive: OK";
                const serverLabel = skipServerSave ? "" : "💾 Server: OK";
                const label = [serverLabel, driveLabel].filter(Boolean).join("") || "OK";

                this.showNotification("success", `✅ ${fileName}.tex<br><small>${label}</small>`, true);

                return {
                    success: true,
                    message: skipServerSave ? "File salvato su Google Drive" : "File salvato su Google Drive e Server",
                    driveFile: driveResponse.driveResult?.file,
                    serverFile: skipServerSave ? null : serverResponse.filePath,
                    details: { drive: driveResponse, server: serverResponse },
                };
            } else if (driveSuccess) {
                console.warn("⚠️ [GoogleDriveLatexSaver] Salvato solo su Drive, errore Server:", serverResponse.error);
                this.showNotification("warning", `⚠️ ${fileName}.tex<br><small>📁 Drive: OK | ❌ Server: ${serverResponse.error}</small>`, true);
                return {
                    success: true,
                    message: "File salvato solo su Google Drive",
                    driveFile: driveResponse.driveResult?.file,
                    serverError: serverResponse.error,
                };
            } else if (serverSuccess) {
                console.warn("⚠️ [GoogleDriveLatexSaver] Salvato solo su Server, errore Drive:", driveResponse.error);
                this.showNotification("warning", `⚠️ ${fileName}.tex<br><small>❌ Drive: ${driveResponse.error} | 💾 Server: OK</small>`, true);
                return {
                    success: true,
                    message: "File salvato solo sul Server",
                    serverFile: serverResponse.filePath,
                    driveError: driveResponse.error,
                };
            } else {
                throw new Error(`Errore Drive: ${driveResponse.error}; Errore Server: ${serverResponse.error}`);
            }
        } catch (error) {
            console.error("❌ [GoogleDriveLatexSaver] Errore:", error);
            this.showNotification("error", `❌ Errore: ${fileName}.tex<br><small>${error.message}</small>`, true);
            throw error;
        }
    },

    async saveLatexToServer(content, fileName, classParams, verTitle, versionFolder = "") {
        try {
            console.log("💾 [saveLatexToServer] Salvataggio sul server:", fileName);

            const response = await fetch(Endpoints.files.saveLatex, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    selectedIIS: classParams.selectedIIS,
                    selectedCLS: classParams.selectedCLS,
                    selectedMATER: classParams.selectedMATER,
                    verTitle,
                    versionFolder,
                    fileName,
                    content,
                }),
            });

            const result = await response.json();

            if (result.success) {
                console.log("✅ [saveLatexToServer] Salvato con successo:", result.filePath);
            } else {
                console.error("❌ [saveLatexToServer] Errore:", result.error);
            }

            return result;
        } catch (error) {
            console.error("❌ [saveLatexToServer] Errore di rete:", error);
            return { success: false, error: error.message };
        }
    },

    getClassParameters() {
        let selectedIIS, selectedCLS, selectedMATER, optsel;

        if (typeof window !== "undefined" && window.AppState) {
            try {
                window.AppState.updateFromSelects();
                selectedIIS = window.AppState.selectedIIS;
                selectedCLS = window.AppState.selectedCLS;
                selectedMATER = window.AppState.selectedMATER;
                optsel = window.AppState.optsel;

                console.log("📊 [GoogleDriveLatexSaver] Parametri da AppState:", {
                    selectedIIS, selectedCLS, selectedMATER, optsel,
                });

                if (selectedIIS && selectedCLS && selectedMATER) {
                    return { selectedIIS, selectedCLS, selectedMATER, optsel };
                }

                console.warn("⚠️ [GoogleDriveLatexSaver] AppState disponibile ma valori mancanti");
            } catch (error) {
                console.warn("⚠️ [GoogleDriveLatexSaver] Errore accesso AppState:", error.message);
            }
        } else {
            console.warn("⚠️ [GoogleDriveLatexSaver] AppState non disponibile");
        }

        try {
            selectedIIS = document.querySelector("select#sel-iis")?.value || getVal("sel-origin") || window.FM?.Curriculum?.firstCode("indirizzi") || "";
            selectedCLS = document.querySelector("select#sel-cls")?.value || this.extractClassFromUI() || window.FM?.Curriculum?.firstCode("classi") || "";
            selectedMATER = document.querySelector("select#sel-mater")?.value || this.extractSubjectFromUI() || window.FM?.Curriculum?.firstCode("materie") || "";
            optsel = selectedIIS + selectedCLS;

            console.log("🔧 [GoogleDriveLatexSaver] Parametri da selettori DOM:", {
                selectedIIS, selectedCLS, selectedMATER, optsel,
            });

            if (selectedIIS && selectedCLS && selectedMATER) {
                return { selectedIIS, selectedCLS, selectedMATER, optsel };
            }

            console.warn("⚠️ [GoogleDriveLatexSaver] Selettori DOM restituiscono valori mancanti");
        } catch (error) {
            console.warn("⚠️ [GoogleDriveLatexSaver] Errore lettura selettori DOM:", error.message);
        }

        console.warn("🔧 [GoogleDriveLatexSaver] Uso default dinamici dal catalogo");
        const dIIS = window.FM?.Curriculum?.firstCode("indirizzi") || "";
        const dCLS = window.FM?.Curriculum?.firstCode("classi") || "";
        const dMAT = window.FM?.Curriculum?.firstCode("materie") || "";
        return { selectedIIS: dIIS, selectedCLS: dCLS, selectedMATER: dMAT, optsel: dIIS + dCLS };
    },

    extractClassFromUI() {
        const classeSelect = byId("classe");
        if (classeSelect && classeSelect.value) {
            const match = classeSelect.value.match(/(\d+)[A-Z]*S?/i);
            return match ? `${match[1].toLowerCase()}s` : null;
        }

        const firstProblem = document.querySelector(".fm-groupcollex");
        const firstProblemId = firstProblem?.id;
        if (firstProblemId) {
            const match = firstProblemId.match(/[a-z]+(\d+s)/);
            return match ? match[1] : null;
        }

        return null;
    },

    extractSubjectFromUI() {
        const firstProblem = document.querySelector(".fm-groupcollex");
        const firstProblemId = firstProblem?.id;
        if (firstProblemId) {
            const match = firstProblemId.match(/[a-z]+\d+s_([A-Z]+)_/);
            return match ? match[1] : null;
        }
        return null;
    },

    async callWebhook(data) {
        const webhookUrl = "/scriptGoogle_sync/webhook-proxy.php";

        const dataWithToken = { ...data, timestamp: new Date().toISOString() };

        console.log("🔧 [GoogleDriveLatexSaver] Usando proxy webhook (token server-side)");
        console.log("📡 [GoogleDriveLatexSaver] Dati da inviare al webhook:", {
            action: dataWithToken.action,
            hasToken: false,
            selectedIIS: dataWithToken.selectedIIS,
            selectedCLS: dataWithToken.selectedCLS,
            selectedMATER: dataWithToken.selectedMATER,
            fileName: dataWithToken.fileName,
            contentLength: dataWithToken.content ? dataWithToken.content.length : 0,
        });

        const response = await fetch(webhookUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify(dataWithToken),
        });

        if (!response.ok) {
            let errorMessage = `HTTP Error: ${response.status}`;

            if (response.status === 401) {
                errorMessage += " (Unauthorized) - Verifica il token di sicurezza";
                console.error("❌ [GoogleDriveLatexSaver] Errore 401 - Token inviato:", dataWithToken.token ? "PRESENTE" : "MANCANTE");
            } else if (response.status === 403) {
                errorMessage += " (Forbidden) - Accesso negato";
            } else if (response.status === 404) {
                errorMessage += " (Not Found) - Webhook non trovato";
            } else {
                errorMessage += ` ${response.statusText}`;
            }

            try {
                const errorBody = await response.text();
                if (errorBody) {
                    console.error("❌ [GoogleDriveLatexSaver] Dettagli errore server:", errorBody);
                    errorMessage += ` - ${errorBody}`;
                }
            } catch (_e) {
                console.warn("⚠️ [GoogleDriveLatexSaver] Impossibile leggere corpo errore");
            }

            throw new Error(errorMessage);
        }

        const result = await response.json();
        console.log("📡 [GoogleDriveLatexSaver] Risposta webhook:", result);

        return result;
    },

    /** Redirect notifiche al log overlay. Ritorna stub no-op chainable. */
    showNotification(type, message, _persistent = false) {
        if (window.VerGenerationOverlay && window.VerGenerationOverlay._suppressNotifications) {
            const logType = type === "success" ? "success" : type === "error" ? "error" : "info";

            // Strip HTML via off-DOM template
            const tmp = document.createElement("div");
            tmp.innerHTML = message;
            const small = tmp.querySelector("small");
            const subText = small ? small.textContent.replace(/\s+/g, " ").trim() : "";
            if (small) small.remove();
            const mainText = tmp.textContent.replace(/\s+/g, " ").trim();

            if (mainText) window.VerGenerationOverlay.addLog(mainText, logType);
            if (subText) window.VerGenerationOverlay.addLog(subText, "info");
        }
        return noopNotification();
    },

    addClearAllButton() {},

    showSummaryNotification(totalFiles) {
        const summary = this.showNotification("info", `📊 Inizio salvataggio<br><small>File da salvare: ${totalFiles}</small>`, true);
        summary.attr("id", "save-summary-notification");
        return summary;
    },

    updateSummaryNotification(completed, total, errors = 0) {
        // Legacy path: il vecchio impl cercava un DOM node con id "save-summary-notification".
        // showNotification ora redirige all'overlay log; questo blocco resta inerte
        // se l'elemento non esiste (caso normale post-G26).
        const summary = byId("save-summary-notification");
        if (summary) {
            const percentage = Math.round((completed / total) * 100);
            summary.innerHTML = `
                📊 Progresso salvataggio: ${completed}/${total} (${percentage}%)
                <br><small>✅ Completati: ${completed - errors} | ❌ Errori: ${errors}</small>
            `;

            if (completed === total) {
                summary.style.backgroundColor = errors === 0 ? "#28a745" : "#ffc107";
                summary.innerHTML = `
                    ${errors === 0 ? "✅" : "⚠️"} Salvataggio completato
                    <br><small>Totale: ${total} | Successi: ${total - errors} | Errori: ${errors}</small>
                `;
            }
        }
    },
};

window.FM = window.FM || {};
window.FM.GoogleDriveLatexSaver = GoogleDriveLatexSaver;
window.GoogleDriveLatexSaver    = GoogleDriveLatexSaver;
