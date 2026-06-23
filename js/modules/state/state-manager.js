/**
 * StateManager — estratto da functions-mod.js (Phase 9f).
 * G26.phase5.5 — migrato a vanilla JS (no jQuery).
 *
 * Note: la semantica di `.index()` (posizione tra siblings, non tra il
 * matched-set jQuery) è preservata per back-compat con stati già salvati
 * in sessionStorage. La load function legge poi con `.eq(N)` che opera
 * sul matched-set — mismatch pre-esistente non in scope di questo refactor.
 */

/** Index del nodo tra tutti i siblings (replica jQuery .index() no-arg). */
function siblingIndex(el) {
    if (!el || !el.parentElement) return -1;
    return Array.from(el.parentElement.children).indexOf(el);
}

/** Trigger evento change bubbling (replica jQuery .trigger("change")). */
function triggerChange(el) {
    if (!el) return;
    el.dispatchEvent(new Event("change", { bubbles: true }));
}

export const StateManager = {
    getStorageKey: function () {
        return `checkboxState_${window.location.pathname}`;
    },

    saveCompleteState: function () {
        const state = {
            checkboxA: [],
            checkboxB: [],
            checkboxAin: [],
            checkboxBin: [],
            defPositionImp: [],
            inputPt: [],
            schoolInputs: {},
            verInputs: {},
            vfTotalPoints: [],
        };

        document.querySelectorAll(".checkboxA").forEach((el, index) => {
            const problemId = el.closest(".fm-groupcollex")?.id;
            if (problemId) {
                state.checkboxA.push({ problemId, index, checked: el.checked });
            }
        });

        document.querySelectorAll(".checkboxB").forEach((el, index) => {
            const problemId = el.closest(".fm-groupcollex")?.id;
            if (problemId) {
                state.checkboxB.push({ problemId, index, checked: el.checked });
            }
        });

        document.querySelectorAll(".fm-checkbox-ain").forEach((el, index) => {
            const collexItem = el.closest(".fm-collection__item");
            const collexIndex = siblingIndex(collexItem);
            const problemId = el.closest(".fm-groupcollex")?.id;
            if (problemId) {
                state.fm-checkbox-ain.push({ problemId, collexIndex, index, checked: el.checked });
            }
        });

        document.querySelectorAll(".fm-checkbox-bin").forEach((el, index) => {
            const collexItem = el.closest(".fm-collection__item");
            const collexIndex = siblingIndex(collexItem);
            const problemId = el.closest(".fm-groupcollex")?.id;
            if (problemId) {
                state.fm-checkbox-bin.push({ problemId, collexIndex, index, checked: el.checked });
            }
        });

        document.querySelectorAll(".fm-def-position-imp").forEach((el, index) => {
            const problemId = el.closest(".fm-groupcollex")?.id;
            if (problemId) {
                state.defPositionImp.push({ problemId, index, value: el.value });
            }
        });

        document.querySelectorAll(".fm-input-pt").forEach((input) => {
            const collexItem = input.closest(".fm-collection__item");
            const problemId = input.closest(".fm-groupcollex")?.id;
            if (problemId) {
                const collexIndex = siblingIndex(collexItem);
                state.inputPt.push({ problemId, collexIndex, value: input.value });
            }
        });

        // Salva elementi di wrapInfoSchool
        // ⚠️ ESCLUDI classe, addressSchool, nPrint* - gestiti da PrintInfoManager in print_info.json
        ["anno", "verTime", "istituto", "versione"].forEach((key) => {
            const el = document.getElementById(key);
            if (el) state.schoolInputs[key] = el.value;
        });

        ["Compensa", "DSA"].forEach((key) => {
            const el = document.getElementById(key);
            if (el) state.verInputs[key] = el.checked;
        });

        const verTitle = document.getElementById("verTitle");
        if (verTitle) state.verInputs.verTitle = verTitle.value;
        const verTitlePrefix = document.getElementById("verTitlePrefix");
        if (verTitlePrefix) state.verInputs.verTitlePrefix = verTitlePrefix.value;

        document.querySelectorAll(".fm-groupcollex").forEach((problem, index) => {
            const problemId = problem.id;
            if (!problemId || !problemId.includes("type_VF")) return;

            const inputA = problem.querySelector(".vf-total-points-inputA");
            const inputB = problem.querySelector(".vf-total-points-inputB");

            const vfData = { problemId, index };
            if (inputA) vfData.totalPointsA = inputA.value || "0";
            if (inputB) vfData.totalPointsB = inputB.value || "0";

            if (inputA || inputB) {
                state.vfTotalPoints.push(vfData);
            }
        });

        sessionStorage.setItem(this.getStorageKey(), JSON.stringify(state));
    },

    loadInfoInputsOnly: function () {
        const savedState = sessionStorage.getItem(this.getStorageKey());
        if (!savedState) return;

        try {
            const state = JSON.parse(savedState);

            if (state.schoolInputs) {
                ["anno", "verTime", "istituto", "versione"].forEach((key) => {
                    const el = document.getElementById(key);
                    if (el && Object.prototype.hasOwnProperty.call(state.schoolInputs, key)) {
                        el.value = state.schoolInputs[key];
                    }
                });
            }

            if (state.verInputs) {
                ["Compensa", "DSA"].forEach((key) => {
                    const el = document.getElementById(key);
                    if (el && Object.prototype.hasOwnProperty.call(state.verInputs, key)) {
                        el.checked = state.verInputs[key];
                    }
                });

                const verTitle = document.getElementById("verTitle");
                if (verTitle && Object.prototype.hasOwnProperty.call(state.verInputs, "verTitle")) {
                    verTitle.value = state.verInputs.verTitle;
                }
                const verTitlePrefix = document.getElementById("verTitlePrefix");
                if (verTitlePrefix && Object.prototype.hasOwnProperty.call(state.verInputs, "verTitlePrefix")) {
                    verTitlePrefix.value = state.verInputs.verTitlePrefix;
                }

                // Forza riallineamento visibilità AddTextDSA se il listener è già attivo
                const dsaElement = document.getElementById("DSA");
                if (dsaElement) triggerChange(dsaElement);
            }
        } catch (error) {
            console.error("❌ Errore nel caricamento info inputs da sessionStorage:", error);
        }
    },

    loadCompleteState: function () {
        const savedState = sessionStorage.getItem(this.getStorageKey());
        if (!savedState) return;

        try {
            const state = JSON.parse(savedState);

            const problemById = (id) => document.getElementById(id);

            state.checkboxA?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const checkbox = problem.querySelector(".checkboxA");
                if (checkbox) checkbox.checked = item.checked;
            });

            state.checkboxB?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const checkbox = problem.querySelector(".checkboxB");
                if (checkbox) checkbox.checked = item.checked;
            });

            state.fm-checkbox-ain?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const collexItem = problem.querySelectorAll(".fm-collection__item")[item.collexIndex];
                if (!collexItem) return;
                const checkbox = collexItem.querySelector(".fm-checkbox-ain");
                if (checkbox) checkbox.checked = item.checked;
            });

            state.fm-checkbox-bin?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const collexItem = problem.querySelectorAll(".fm-collection__item")[item.collexIndex];
                if (!collexItem) return;
                const checkbox = collexItem.querySelector(".fm-checkbox-bin");
                if (checkbox) checkbox.checked = item.checked;
            });

            state.defPositionImp?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const input = problem.querySelector(".fm-def-position-imp");
                if (input && item.value !== undefined) input.value = item.value;
            });

            state.inputPt?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                const collexItem = problem.querySelectorAll(".fm-collection__item")[item.collexIndex];
                if (!collexItem) return;
                const input = collexItem.querySelector(".fm-input-pt");
                if (input && item.value !== undefined) input.value = item.value;
            });

            if (state.schoolInputs) {
                ["anno", "verTime", "istituto", "versione"].forEach((key) => {
                    const el = document.getElementById(key);
                    if (el && Object.prototype.hasOwnProperty.call(state.schoolInputs, key)) {
                        el.value = state.schoolInputs[key];
                    }
                });
            }

            if (state.verInputs) {
                ["Compensa", "DSA"].forEach((key) => {
                    const el = document.getElementById(key);
                    if (el && Object.prototype.hasOwnProperty.call(state.verInputs, key)) {
                        el.checked = state.verInputs[key];
                        // Visibilità AddTextDSA — logica legacy commentata, mantenuta
                        // come hook futuro (cf. originale state-manager.js:323-348)
                    }
                });

                const verTitle = document.getElementById("verTitle");
                if (verTitle && Object.prototype.hasOwnProperty.call(state.verInputs, "verTitle")) {
                    verTitle.value = state.verInputs.verTitle;
                }
                const verTitlePrefix = document.getElementById("verTitlePrefix");
                if (verTitlePrefix && Object.prototype.hasOwnProperty.call(state.verInputs, "verTitlePrefix")) {
                    verTitlePrefix.value = state.verInputs.verTitlePrefix;
                }
            }

            state.vfTotalPoints?.forEach((item) => {
                const problem = problemById(item.problemId);
                if (!problem) return;
                let needsUpdate = false;

                if (item.totalPointsA !== undefined) {
                    const inputA = problem.querySelector(".vf-total-points-inputA");
                    if (inputA) {
                        inputA.value = item.totalPointsA;
                        const totalPointsA = problem.querySelector(".total-pointsA");
                        if (totalPointsA) totalPointsA.textContent = item.totalPointsA;
                        needsUpdate = true;
                        console.log(`✅ Input VF A ripristinato: ${item.totalPointsA}`);
                    } else {
                        problem.dataset.pendingVfValueA = item.totalPointsA;
                    }
                }

                if (item.totalPointsB !== undefined) {
                    const inputB = problem.querySelector(".vf-total-points-inputB");
                    if (inputB) {
                        inputB.value = item.totalPointsB;
                        const totalPointsB = problem.querySelector(".total-pointsB");
                        if (totalPointsB) totalPointsB.textContent = item.totalPointsB;
                        needsUpdate = true;
                        console.log(`✅ Input VF B ripristinato: ${item.totalPointsB}`);
                    } else {
                        problem.dataset.pendingVfValueB = item.totalPointsB;
                    }
                }

                // utilities.updateVFProblemPoints accetta Element (unwrap interno via Xe).
                if (needsUpdate && typeof utilities !== "undefined" && typeof utilities.updateVFProblemPoints === "function") {
                    utilities.updateVFProblemPoints(problem);
                    console.log(`🔄 Input-pt aggiornati per ${item.problemId}`);
                }
            });

            this.recalculatePointsAfterStateRestore();
        } catch (error) {
            console.error("❌ Errore nel caricamento dello stato da sessionStorage:", error);
        }
    },

    recalculatePointsAfterStateRestore: function () {
        setTimeout(() => {
            if (typeof utilities !== "undefined" && typeof utilities.initializeVFProblems === "function") {
                utilities.initializeVFProblems();
            }

            // utilities.updatePointsTotal accetta Element (unwrap interno via Xe).
            document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
                if (typeof utilities !== "undefined" && typeof utilities.updatePointsTotal === "function") {
                    utilities.updatePointsTotal(problem);
                }
            });

            if (typeof utilities !== "undefined" && typeof utilities.updateGlobalPointsTotal === "function") {
                utilities.updateGlobalPointsTotal();
            }
        }, 100);
    },

    clearState: function () {
        sessionStorage.removeItem(this.getStorageKey());
        console.log("🗑️ Stato rimosso da sessionStorage");
    },

    debouncedReload: function () {
        clearTimeout(this.reloadTimeout);
        this.reloadTimeout = setTimeout(() => {
            this.loadCompleteState();
        }, 150);
    },

    reloadTimeout: null,

    debugCurrentState: function () {
        const savedState = sessionStorage.getItem(this.getStorageKey());
        if (savedState) {
            const state = JSON.parse(savedState);
            console.log("📊 Stato corrente in sessionStorage:", state);

            console.log("📈 Statistiche stato salvato:");
            console.log("  - Checkbox A:", state.checkboxA ? state.checkboxA.length : 0);
            console.log("  - Checkbox B:", state.checkboxB ? state.checkboxB.length : 0);
            console.log("  - Checkbox Ain:", state.fm-checkbox-ain ? state.fm-checkbox-ain.length : 0);
            console.log("  - Checkbox Bin:", state.fm-checkbox-bin ? state.fm-checkbox-bin.length : 0);
            console.log("  - Input defPositionImp:", state.defPositionImp ? state.defPositionImp.length : 0);
            console.log("  - School Inputs:", state.schoolInputs ? Object.keys(state.schoolInputs).length : 0, state.schoolInputs);
            console.log("  - Ver Inputs:", state.verInputs ? Object.keys(state.verInputs).length : 0, state.verInputs);
            console.log("  - VF Total Points:", state.vfTotalPoints ? state.vfTotalPoints.length : 0, state.vfTotalPoints);
        } else {
            console.log("❌ Nessuno stato salvato in sessionStorage");
        }

        console.log("🔍 Stato attuale nel DOM:");
        console.log("checkboxA checked:", document.querySelectorAll(".checkboxA:checked").length);
        console.log("checkboxB checked:", document.querySelectorAll(".checkboxB:checked").length);
        console.log("checkboxAin checked:", document.querySelectorAll(".fm-checkbox-ain:checked").length);
        console.log("checkboxBin checked:", document.querySelectorAll(".fm-checkbox-bin:checked").length);

        const filledDefPos = Array.from(document.querySelectorAll(".fm-def-position-imp"))
            .filter((el) => el.value !== "").length;
        console.log("defPositionImp con valori:", filledDefPos);

        const schoolIds = ["anno", "verTime", "classe", "addressSchool", "istituto", "versione", "nPrint", "nPrintDSA", "nPrintDIS"];
        const schoolFilledCount = schoolIds.filter((id) => (document.getElementById(id)?.value ?? "") !== "").length;
        console.log("School inputs con valori:", schoolFilledCount, "/", schoolIds.length);

        const verCheckedCount = document.querySelectorAll("#Compensa:checked, #DSA:checked").length;
        const verTitleFilled = (document.getElementById("verTitle")?.value ?? "") !== "";
        console.log("Ver checkboxes checked:", verCheckedCount, "/ 2");
        console.log("Ver title filled:", verTitleFilled);

        console.log("💰 Punti attuali:");
        document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
            const problemId = problem.id;
            const totalPoints = problem.querySelector(".fm-total-points")?.textContent || "0";
            console.log(`  - ${problemId}: ${totalPoints} punti`);
        });
        const globalTotal = document.getElementById("SumPtot")?.value || "0";
        console.log(`  - Totale globale: ${globalTotal} punti`);
    },

    areProblemsLoaded: function () {
        const problemCount = document.querySelectorAll(".fm-groupcollex").length;
        const checkboxCount = document.querySelectorAll(".checkboxA, .checkboxB, .fm-checkbox-ain, .fm-checkbox-bin").length;
        console.log(`🔍 Controllo problemi: ${problemCount} problemi, ${checkboxCount} checkbox`);
        return problemCount > 0 && checkboxCount > 0;
    },

    saveCheckboxState: function () {
        return this.saveCompleteState();
    },

    loadCheckboxState: function () {
        return this.loadCompleteState();
    },

    forceLoadState: function () {
        console.log("🔄 Caricamento forzato dello stato...");
        this.loadCompleteState();
    },
};

window.FM = window.FM || {};
window.FM.StateManager = StateManager;
window.StateManager    = StateManager;
