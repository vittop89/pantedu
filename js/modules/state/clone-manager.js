/**
 * CloneManager — estratto da functions-mod.js (Phase 9e).
 * G26.phase4.8 — migrato a vanilla JS (no jQuery).
 *
 * Sprint B (2026-06-02): rimosso il wrapper boundary jQuery — UIComp/EventHendler/
 * PathManager accettano Element direttamente (unwrap interno via asElement/Xe).
 */
import { Endpoints } from "../core/endpoints.js";
import { asElement } from "../core/dom-utils.js";

/** Parse HTML fragment to single Element. */
function htmlToElement(html) {
    const tmp = document.createElement("template");
    tmp.innerHTML = String(html).trim();
    return tmp.content.firstElementChild;
}

/** fetch wrapper riproducendo error shape jQuery (responseText/responseJSON/status). */
async function fetchOrThrow(url, opts = {}) {
    let response;
    try {
        response = await fetch(url, { credentials: "same-origin", ...opts });
    } catch (netErr) {
        const e = new Error(`Network error: ${netErr.message}`);
        e.status = 0;
        throw e;
    }
    const text = await response.text();
    if (!response.ok) {
        const err = new Error(`HTTP ${response.status}: ${response.statusText}`);
        err.status = response.status;
        err.statusText = response.statusText;
        err.responseText = text;
        try { err.responseJSON = JSON.parse(text); } catch (_) { /* not json */ }
        throw err;
    }
    return text;
}

/** Estrae solo text-nodes diretti da un Element (no figli). */
function directTextOf(el) {
    if (!el) return "";
    return Array.from(el.childNodes)
        .filter((n) => n.nodeType === 3)
        .map((n) => n.textContent.trim())
        .join(" ")
        .trim();
}

export const CloneManager = {
    /**
     * Sostituisce il testo dopo il blocco regexParts con testo standard.
     * @param {string} latexContent
     * @returns {string}
     */
    replaceTextAfterArrayBlock: function (latexContent) {
        const regexParts = {
            arrayBlock: /\\begin\{array\}\{\|c\|\}[\n\s\S]*?\\hline[\n\s\S]*?\\small\{\\text\{[^\}]*\}\}[\n\s\S]*?\\\[-5pt\][\n\s\S]*?\\tiny\{\\text\{[^\}]*\}[\n\s\S]*?\\\[-5pt\][\n\s\S]*?\\tiny\{\\text\{[^\}]*\}\}[\n\s\S]*?\\\[-5pt\][\n\s\S]*?\\hline[\n\s\S]*?\\end\{array\}\\quad/,
            oversetBlock: /[\n\s]*?\\overset[\n\s\S]*?\\color\s*\{red\}[\n\s\S]*?\\huge[\n\s\S]*?[^}]*\}\{/,
            undersetBlock: /[\n\s]*?\\underset\{[\n\s\S]*?\\text\{[^}]*\}[^}]*[\n\s\S]*?\}\{/,
            bboxBlock: /[\n\s]*?\\bbox\[border:\s*1px\s*solid\s*white;\s*background:\s*[^,]*,\s*[^p]*pt\][\n\s\S]*?\{\{\\mathmakebox\[cm\]\[c\][\n\s\S]*?\\textcolor\{white\}\{[^}]*[\n\s\S]*?\}\}\}\}/,
            restBlock: /\}[\n\s]*?\}\\quad[\s\S]*/,
        };
        const fullPattern = new RegExp(
            regexParts.arrayBlock.source + regexParts.oversetBlock.source + regexParts.undersetBlock.source + regexParts.bboxBlock.source + regexParts.restBlock.source,
            "s",
        );

        const match = latexContent.match(fullPattern);
        if (!match) {
            console.log("⚠️ Pattern non trovato, nessuna sostituzione");
            return latexContent;
        }

        const patternUntilBbox = new RegExp(
            regexParts.arrayBlock.source + regexParts.oversetBlock.source + regexParts.undersetBlock.source + regexParts.bboxBlock.source,
            "s",
        );
        const bboxMatch = latexContent.match(patternUntilBbox);
        if (!bboxMatch) {
            console.log("⚠️ Pattern fino a bbox non trovato");
            return latexContent;
        }

        const replacementText = "}}\\quad\\) Traccia e soluzioni presenti sul libro di testo in adozione.";
        const result = latexContent.replace(fullPattern, bboxMatch[0] + replacementText);

        console.log("✅ Testo sostituito in JS (preservato numero e pagina)");
        return result;
    },

    /**
     * Clona un collex-item da un file di verifica a un file di esercizi.
     * Accetta sia Element che jQuery wrapper (transition compat).
     * @param {Element|object} collexItemArg - Element o jQuery wrapper
     * @param {string|null} modifiedHtml
     * @returns {Promise<Object>}
     */
    cloneCollexItem: async function (collexItemArg, modifiedHtml = null) {
        try {
            console.log("🔄 Inizio clonazione collex-item");

            const collexItem = asElement(collexItemArg);
            if (!collexItem) throw new Error("collex-item non fornito");

            const problem = collexItem.closest(".fm-groupcollex");
            if (!problem) {
                throw new Error("Problema non trovato");
            }

            if (!problem.closest(".DraggableContainer_ver")) {
                throw new Error("Questa funzione funziona solo su file di verifica (DraggableContainer_ver)");
            }

            const problemId = problem.id;
            const cleanedProblemId = problemId.replace(/_add\d+$/, "");

            const collexItemIndex = PathManager.globalTOrelativeIndex(".fm-collection__item", collexItem, ".fm-groupcollex");

            // PathManager.extractPath accetta Element (unwrap interno via Xe).
            const verificationPath = PathManager.extractPath(problem);

            let exercisePath = sessionStorage.getItem("linkref");
            if (!exercisePath && typeof visitedLinks !== "undefined" && visitedLinks.length > 0) {
                exercisePath = visitedLinks[0];
            }

            if (!exercisePath) {
                console.warn("⚠️ linkref e visitedLinks non disponibili, calcolo exercisePath da verificationPath");
                exercisePath = this._getExercisePathFromVerification(verificationPath);
                console.log("📊 exercisePath calcolato:", exercisePath);
            }

            console.log("📋 Dati clonazione:", {
                problemId: cleanedProblemId,
                originalProblemId: problemId,
                collexItemIndex,
                verificationPath,
                exercisePath,
                currentPath: window.location.pathname,
            });

            if (exercisePath === verificationPath) {
                console.error("❌ ERRORE: exercisePath è uguale a verificationPath!");
                throw new Error("Il path esercizi non può essere uguale al path verifiche");
            }

            console.log("✅ Paths verificati: esercizi ≠ verifiche");

            const selectedOrigin = this._getSelectedOrigin();
            if (!selectedOrigin) {
                throw new Error("Nessuna origine selezionata. Clicca su un'origine nella dropdown per selezionarla.");
            }

            console.log("✅ Origine selezionata:", selectedOrigin);

            if (modifiedHtml) {
                modifiedHtml = typeof reEncodeAccentedChars === "function" ? reEncodeAccentedChars(modifiedHtml) : modifiedHtml;
            }

            const rawText = await fetchOrThrow(Endpoints.exercises.cloneCollex, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    verificationPath,
                    exercisePath,
                    problemId: cleanedProblemId,
                    collexItemIndex,
                    checkedOrigin: selectedOrigin,
                    modifiedHtml,
                }),
            });

            let parsedResponse;
            try {
                parsedResponse = JSON.parse(rawText);
            } catch (e) {
                console.error("❌ Errore parsing JSON:", e);
                console.error("Response raw:", rawText);
                throw new Error("Risposta non valida dal server");
            }

            if (parsedResponse.debug) {
                console.log("🔍 DEBUG dal server PHP:");
                console.log("  📁 verificationPath:", parsedResponse.debug.verificationPath);
                console.log("  📁 exercisePath:", parsedResponse.debug.exercisePath);
                console.log("  💾 exerciseFullPath:", parsedResponse.debug.exerciseFullPath);
                console.log("  ✅ File salvato:", parsedResponse.debug.fileSaved);
                console.log("  📊 File size:", parsedResponse.debug.fileSize, "bytes");
            }

            await this._updateDomAfterClone(parsedResponse, exercisePath, cleanedProblemId, collexItemIndex);

            const successMessage = parsedResponse.message || (parsedResponse.action === "appended" ? "Collex-item aggiunto al problema esistente" : "Nuovo problema creato con il collex-item");

            if (typeof ToastManager !== "undefined") {
                ToastManager.showSuccess(successMessage);
            } else {
                alert(successMessage);
            }

            return parsedResponse;
        } catch (error) {
            console.error("❌ Errore durante la clonazione:", error);
            console.error("Response text:", error.responseText);
            console.error("Status:", error.status, error.statusText);

            const errorMessage = error.responseJSON?.error || error.message || "Errore sconosciuto durante la clonazione";

            if (typeof ToastManager !== "undefined") {
                ToastManager.showError(errorMessage);
            } else {
                alert(`Errore: ${errorMessage}`);
            }

            throw error;
        }
    },

    _getExercisePathFromVerification: function (verificationPath) {
        const fileName = verificationPath.split("/").pop();
        let fileNameWithoutExt = fileName.replace(".php", "").replace("-ver", "");
        fileNameWithoutExt = fileNameWithoutExt.replace(/^[\d.]+_/, "");
        const subject = fileNameWithoutExt.split("-")[0];

        const currentPath = window.location.pathname;
        // Seed DINAMICO dal catalogo curriculum (no "SCI"/"sc3s" hardcoded);
        // sovrascritto sotto dal path o dalla selezione corrente.
        let address = window.FM?.Curriculum?.firstCode("indirizzi") || "";
        let className = address + (window.FM?.Curriculum?.firstCode("classi") || "");

        const pathMatch = currentPath.match(/\/(eser|lab|mappe)\/([a-z]+)\/\w+_([a-z0-9]+)\//);
        if (pathMatch) {
            address = pathMatch[2];
            className = pathMatch[3];
        } else {
            const selectedIIS = sessionStorage.getItem("selectedIIS");
            const selectedCLS = sessionStorage.getItem("selectedCLS");
            if (selectedIIS && selectedCLS) {
                address = selectedIIS;
                className = selectedIIS + selectedCLS;
            }
        }

        return `/eser/${address}/eser_${className}/${subject}/1.0_${fileNameWithoutExt}-${className}.php`;
    },

    _getSelectedOrigin: function () {
        const dropdownButton = document.querySelector(".fm-dropdown-button-gen");
        if (!dropdownButton) return null;

        const buttonText = (dropdownButton.textContent || "").trim();
        if (buttonText === "Seleziona origine" || buttonText === "") {
            return null;
        }
        return buttonText;
    },

    /**
     * Aggiorna il DOM dopo la clonazione senza ricaricare la pagina.
     * @param {Object} response
     * @param {string} exercisePath
     * @param {string} _problemId
     * @param {number} _collexItemIndex
     */
    _updateDomAfterClone: async function (response, exercisePath, _problemId, _collexItemIndex) {
        try {
            // Cerca container esercizi (non _ver)
            const exerciseContainers = document.querySelectorAll(".fm-draggable-container:not(.DraggableContainer_ver)");

            if (exerciseContainers.length === 0) {
                console.log("⚠️ .fm-draggable-container (esercizi) non trovato nella pagina, salto aggiornamento DOM locale");
                console.log("✅ File esercizi aggiornato correttamente su:", exercisePath);
                return;
            }

            console.log("🎯 Trovato .fm-draggable-container, aggiorno DOM locale");

            if (response.action === "appended") {
                const fileText = await fetchOrThrow(`${exercisePath}?_=${Date.now()}`, { method: "GET" });

                const parser = new DOMParser();
                const doc = parser.parseFromString(fileText, "text/html");

                const collapsibleText = response.collapsibleText;
                const problems = document.querySelectorAll(".fm-draggable-container:not(.DraggableContainer_ver) .fm-groupcollex");

                console.log("🔍 _updateDomAfterClone cerca problema con collapsible:", collapsibleText);
                console.log("🔍 Problemi trovati nel DOM esercizi:", problems.length);

                for (const problem of problems) {
                    const collapsible = problem.querySelector(".fm-collapsible");
                    if (!collapsible) continue;

                    const problemCollapsibleText = directTextOf(collapsible);

                    if (problemCollapsibleText !== collapsibleText) continue;

                    const problemsInDoc = doc.querySelectorAll(".fm-groupcollex");
                    let matchedProblemNode = null;
                    for (const problemNode of problemsInDoc) {
                        const collapsibleNode = problemNode.querySelector(".fm-collapsible");
                        if (!collapsibleNode) continue;
                        const docCollapsibleText = directTextOf(collapsibleNode);
                        if (docCollapsibleText === collapsibleText) {
                            matchedProblemNode = problemNode;
                            break;
                        }
                    }
                    if (!matchedProblemNode) continue;

                    const collexItems = matchedProblemNode.querySelectorAll(".fm-collection__item");

                    const collexList = problem.querySelector(".fm-collexercise") || problem.querySelector(".Aff");
                    if (!collexList) {
                        console.error("❌ Nessun elemento .collexercise o .Aff trovato nel problema!");
                        break;
                    }

                    collexList.querySelectorAll(".fm-collection__item").forEach((el) => el.remove());
                    collexItems.forEach((item) => {
                        collexList.insertAdjacentHTML("beforeend", item.outerHTML);
                    });

                    const afterUpdate = () => this._postCloneSetup(problem, /*appendedOnly*/ true);

                    if (typeof MathJax !== "undefined") {
                        MathJax.typesetPromise([problem]).then(afterUpdate);
                    } else {
                        afterUpdate();
                    }

                    break;
                }
            } else if (response.action === "created") {
                console.log("🆕 Nuova tipologia creata, caricamento da:", exercisePath);

                const fileText = await fetchOrThrow(`${exercisePath}?_=${Date.now()}`, { method: "GET" });

                console.log("📥 File caricato, lunghezza:", fileText.length, "chars");
                const parser = new DOMParser();
                const doc = parser.parseFromString(fileText, "text/html");

                const collapsibleText = response.collapsibleText;
                const allProblemsInDoc = doc.querySelectorAll(".fm-groupcollex");
                console.log("🔍 Problemi trovati nel file caricato:", allProblemsInDoc.length);

                let newProblemNode = null;

                for (const problemNode of allProblemsInDoc) {
                    const collapsibleNode = problemNode.querySelector(".fm-collapsible");
                    if (!collapsibleNode) continue;
                    const probText = directTextOf(collapsibleNode);

                    console.log("🔍 Confronto collapsible:", {
                        found: probText,
                        expected: collapsibleText,
                        match: probText === collapsibleText,
                    });

                    if (probText === collapsibleText) {
                        newProblemNode = problemNode;
                        console.log("✅ Problema trovato con collapsible:", collapsibleText);
                        break;
                    }
                }

                if (!newProblemNode) {
                    console.error("❌ Nuovo problema non trovato nel documento caricato");
                    throw new Error("Problema creato ma non trovato nel file esercizi");
                }

                if (newProblemNode.style) newProblemNode.style.display = "";
                const styleAttr = newProblemNode.getAttribute("style");
                if (styleAttr && styleAttr.includes("display")) {
                    newProblemNode.removeAttribute("style");
                }

                const newProblem = htmlToElement(newProblemNode.outerHTML);
                if (!newProblem) {
                    throw new Error("Impossibile parsare nuovo problema HTML");
                }

                newProblem.style.display = "block";
                newProblem.removeAttribute("style");

                newProblem.querySelectorAll('[style*="display"]').forEach((el) => {
                    if (getComputedStyle(el).display === "none") {
                        el.style.display = "";
                    }
                });

                console.log("🔍 Nuovo problema HTML (dopo pulizia):", newProblem.outerHTML.substring(0, 200));

                const container = document.querySelector(".fm-draggable-container:not(.DraggableContainer_ver)");
                if (!container) {
                    console.warn("⚠️ fm-draggable-container (esercizi) non trovato nel DOM corrente");
                    return;
                }

                container.appendChild(newProblem);

                console.log("✅ Nuovo problema aggiunto al DOM esercizi:", newProblem.id);
                console.log("🔍 Display dopo append:", getComputedStyle(newProblem).display);

                newProblem.style.setProperty("display", "block", "important");
                const contentEl = newProblem.querySelector(".content");
                if (contentEl) contentEl.style.display = "block";

                console.log("🔍 Display dopo forzatura:", getComputedStyle(newProblem).display);

                if (typeof MathJax !== "undefined") {
                    MathJax.typesetPromise([newProblem]).then(() => {
                        if (typeof UIComp !== "undefined" && typeof UIComp.setupProblemElements === "function") {
                            console.log("🔧 Chiamata setupProblemElements per problema clonato");
                            // setupProblemElements accetta Element (unwrap interno).
                            UIComp.setupProblemElements(newProblem);
                        }
                    });
                } else if (typeof UIComp !== "undefined" && typeof UIComp.setupProblemElements === "function") {
                    UIComp.setupProblemElements(newProblem);
                }
            }
        } catch (error) {
            console.error("❌ Errore aggiornamento DOM:", error);
            if (await window.FM.Dialog.confirm("Errore nell'aggiornamento del DOM. Ricaricare la pagina?")) {
                location.reload();
            }
        }
    },

    /** Setup post-clone: checkIN, move-position, origins, checkmod, height. */
    _postCloneSetup: function (problem, _appendedOnly) {
        UIComp.preloadElementiRiservati((tempDivArg) => {
            const tempEl = asElement(tempDivArg);
            if (!tempEl) return;

            const clonedTemp = tempEl.cloneNode(true);
            const checkIN = clonedTemp.querySelector(".fm-check-in");

            problem.querySelectorAll(".fm-collection__item").forEach((item) => {
                if (!item.querySelector(".fm-check-in") && checkIN) {
                    item.insertBefore(checkIN.cloneNode(true), item.firstChild);
                }
            });

            // EventHendler.updateMovePositions accetta Element (unwrap interno).
            if (typeof EventHendler !== "undefined" && typeof EventHendler.updateMovePositions === "function") {
                EventHendler.updateMovePositions(problem);
                console.log("  ✅ move-position aggiornati");
            }

            // Popola origins da API (memo-fetch dedup)
            window.FM.memoFetchJson("/api/teacher/origins.json")
                .then((origins) => {
                    problem.querySelectorAll(".fm-collection__item").forEach((item) => {
                        const select = item.querySelector(".origin");
                        if (!select || select.querySelectorAll("option").length > 0) return;

                        const optionsHtml = `<option value="origine">origine</option>${origins.map((value) => `<option value="${value}">${value}</option>`).join("")}`;
                        select.insertAdjacentHTML("beforeend", optionsHtml);

                        let selectedClass = null;
                        let found = false;
                        item.className.split(" ").forEach((cls) => {
                            if (cls !== "origine" && origins.includes(cls)) {
                                selectedClass = cls;
                                found = true;
                            }
                        });
                        select.value = found ? selectedClass : "origine";
                    });
                    console.log("  ✅ origins popolate");

                    if (typeof CheckmodManager !== "undefined" && typeof CheckmodManager.insertCheckmodInCollapsibles === "function") {
                        CheckmodManager.insertCheckmodInCollapsibles();
                    }

                    if (typeof UIComp !== "undefined" && typeof UIComp.SetHeightProblem === "function") {
                        UIComp.SetHeightProblem(problem);
                    } else {
                        console.warn("⚠️ UIComp o SetHeightProblem non disponibile");
                    }
                })
                .catch((err) => console.error("Errore caricamento origins:", err));
        });
    },
};

window.FM = window.FM || {};
window.FM.CloneManager = CloneManager;
window.CloneManager    = CloneManager;
