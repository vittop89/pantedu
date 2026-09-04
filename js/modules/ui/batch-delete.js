/**
 * BatchDeleteManager — estratto da functions-mod.js (Phase 9e).
 * G26.phase5.3 — migrato a vanilla JS (no jQuery).
 */
import { Endpoints } from "../core/endpoints.js";

async function postForm(url, fields) {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) {
        body.append(k, v == null ? "" : String(v));
    }
    const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
        credentials: "same-origin",
    });
    const text = await response.text();
    // Try to parse JSON; fall back to string
    try { return JSON.parse(text); } catch (_) { return text; }
}

export const BatchDeleteManager = {
    collectCheckedProblems: function () {
        const checkedProblems = [];

        document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
            const hasCheckedA = problem.querySelectorAll(".checkboxA:checked").length > 0;
            const hasCheckedB = problem.querySelectorAll(".checkboxB:checked").length > 0;
            if (!hasCheckedA && !hasCheckedB) return;

            const problemID = problem.id;
            const path = PathManager.extractPath(problem);
            const pathDir = path.substring(0, path.lastIndexOf("/"));

            let problemType = "";
            if (pathDir.includes("/verifiche/php/")) problemType = "verifiche";
            else if (pathDir.includes("/eser/")) problemType = "esercizi";

            const serverID = problemID.replace(/_add\d+$/, "");

            checkedProblems.push({
                element: problem,
                id: serverID,
                domID: problemID,
                path,
                pathDir,
                type: problemType,
                hasCheckedA,
                hasCheckedB,
            });
        });

        return checkedProblems;
    },

    validateProblemTypes: function (problems) {
        if (problems.length === 0) {
            return {
                valid: false,
                type: "",
                message: "Nessun problema selezionato. Seleziona almeno un checkboxA o checkboxB.",
            };
        }

        const types = new Set(problems.map((p) => p.type));

        if (types.size > 1) {
            return {
                valid: false,
                type: "mixed",
                message: "❌ BLOCCO ELIMINAZIONE: Hai selezionato tipologie di verifiche e di esercizi insieme.\n\nSeleziona solo tipologie di verifiche OPPURE solo tipologie di esercizi.",
            };
        }

        const problemType = Array.from(types)[0];
        return { valid: true, type: problemType, message: "" };
    },

    showDeleteConfirmation: async function (problems) {
        const problemType = problems[0].type;
        const typeLabel = problemType === "verifiche" ? "VERIFICHE" : "ESERCIZI";

        let message = `🗑️ ELIMINAZIONE BATCH - ${typeLabel}\n\n`;
        message += `Stai per eliminare ${problems.length} problem${problems.length > 1 ? "i" : "a"}:\n\n`;

        problems.forEach((problem, index) => {
            const checkInfo = [];
            if (problem.hasCheckedA) checkInfo.push("A");
            if (problem.hasCheckedB) checkInfo.push("B");
            message += `${index + 1}. ${problem.id} [${checkInfo.join(", ")}]\n`;
        });

        message += `\n⚠️ ATTENZIONE: L'eliminazione è DEFINITIVA e includerà anche tutti gli SVG TikZ associati.\n\n`;
        message += `Confermi l'eliminazione?`;

        return await window.FM.Dialog.confirm(message);
    },

    executeBatchDelete: async function (problems) {
        const toastId = ToastManager.showLoading(`Eliminazione di ${problems.length} problemi in corso...`);

        let successCount = 0;
        let errorCount = 0;

        for (let index = 0; index < problems.length; index++) {
            const problem = problems[index];

            try {
                const selectedIIS = sessionStorage.getItem("selectedIIS");
                const selectedCLS = sessionStorage.getItem("selectedCLS");
                const classeFolder = selectedIIS + selectedCLS;

                const svgToDelete = [];
                problem.element.querySelectorAll("svg[data-tikz-script-id]").forEach((svg) => {
                    const scriptId = svg.getAttribute("data-tikz-script-id");
                    if (scriptId) svgToDelete.push(`${scriptId}.svg`);
                });

                console.log(`🗑️ [${index + 1}/${problems.length}] Eliminazione problema:`);
                console.log(`   DOM ID: ${problem.domID}`);
                console.log(`   Server ID: ${problem.id}`);
                console.log(`   SVG da eliminare:`, svgToDelete);

                const response = await postForm(Endpoints.update.file, {
                    filePath: problem.path,
                    deleteProblemID: problem.id,
                });

                if (typeof response === "string" && response.includes("not found")) {
                    console.warn(`⚠️ Problema ${problem.id} non trovato sul server (solo DOM):`, response);
                    problem.element.remove();
                    successCount++;
                    continue;
                }

                if (typeof response === "string" && response.toLowerCase().includes("error")) {
                    console.error(`❌ Errore dal server per ${problem.id}:`, response);
                    errorCount++;
                    continue;
                }

                console.log(`✅ Problema ${problem.id} eliminato dal server:`, response);

                if (svgToDelete.length > 0) {
                    const svgDeletePromises = svgToDelete.map((svgFileName) =>
                        postForm(Endpoints.files.deleteFile, {
                            filePath: `${problem.pathDir}/svg_${classeFolder}`,
                            fileName: svgFileName,
                        })
                            .then((delResponse) => {
                                console.log(`   ✅ SVG eliminato: ${svgFileName}`, delResponse);
                            })
                            .catch((delError) => {
                                console.error(`   ❌ Errore eliminazione SVG: ${svgFileName}`, delError);
                            }),
                    );
                    await Promise.all(svgDeletePromises);
                }

                problem.element.remove();
                successCount++;
            } catch (error) {
                console.error(`❌ Errore eliminazione problema ${problem.id}:`, error);
                errorCount++;
            }
        }

        if (errorCount === 0) {
            ToastManager.update(toastId, "success", "Successo", `${successCount} problemi eliminati con successo!`);
            console.log(`✅ Eliminazione batch completata: ${successCount} problemi eliminati`);
        } else {
            ToastManager.update(toastId, "error", "Completato con errori", `${successCount} eliminati, ${errorCount} errori`);
            console.warn(`⚠️ Eliminazione completata con errori: ${successCount} successi, ${errorCount} falliti`);
        }
    },
};

window.FM = window.FM || {};
window.FM.BatchDeleteManager = BatchDeleteManager;
window.BatchDeleteManager    = BatchDeleteManager;
