/**
 * utilities — estratto da functions-mod.js (Phase 9e huge 801L helper block).
 * G26.phase5.6 — migrato a vanilla JS (no jQuery).
 *
 * API surface preserved: tutti i metodi accettano Element o jQuery wrapper
 * (transition compat con caller legacy ancora in jQuery-land).
 */
import { Endpoints } from "./endpoints.js";
import { fetchJson } from "./dom-utils.js";
import { asElement, trigger } from "./dom-utils.js";

export const utilities = {
    generateUUID: function () {
        const now = new Date();
        return `id-${now.getFullYear()}${(now.getMonth() + 1).toString().padStart(2, "0")}${now.getDate().toString().padStart(2, "0")}${now.getHours().toString().padStart(2, "0")}${now.getMinutes().toString().padStart(2, "0")}${now.getSeconds().toString().padStart(2, "0")}${now.getMilliseconds().toString().padStart(3, "0")}`;
    },

    /**
     * Phase 20 — sendLoginRedirectPath consolidato qui (prima in utils.js).
     */
    sendLoginRedirectPath: function (linkHref) {
        if (window.location.pathname.includes("/log/auth/login.php")) {
            const redirectInput = document.getElementById("redirect_url");
            if (redirectInput) redirectInput.value = linkHref;
        } else if (window.opener || window.parent !== window) {
            const message = { type: "UPDATE_LOGIN_REDIRECT", url: linkHref };
            if (window.opener) window.opener.postMessage(message, window.location.origin);
            if (window.parent !== window) window.parent.postMessage(message, window.location.origin);
        }
    },

    shuffleArray: function (array) {
        if (!Array.isArray(array) || array.length <= 1) return;
        for (let i = array.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [array[i], array[j]] = [array[j], array[i]];
        }
    },

    getColorName: function (rgb) {
        if (!rgb || typeof rgb !== "string") return "white";
        const colorRanges = {
            white:  ["rgb(240, 240, 240)", "rgb(255, 255, 255)"],
            green:  ["rgb(0, 70, 0)",      "rgb(0, 255, 0)"],
            blue:   ["rgb(0, 0, 100)",     "rgb(0, 0, 255)"],
            red:    ["rgb(100, 0, 0)",     "rgb(255, 0, 0)"],
            purple: ["rgb(100, 0, 100)",   "rgb(255, 0, 255)"],
            orange: ["rgb(200, 100, 0)",   "rgb(255, 165, 0)"],
        };

        for (const color in colorRanges) {
            const [min, max] = colorRanges[color].map((c) => c.match(/\d+/g).map(Number));
            const [r, g, b] = rgb.match(/\d+/g).map(Number);
            if (r >= min[0] && r <= max[0] && g >= min[1] && g <= max[1] && b >= min[2] && b <= max[2]) {
                return color;
            }
        }
        return "white";
    },

    lightenColor: function (color, percent) {
        if (!color.startsWith("#") && !color.startsWith("rgb")) {
            const ctx = document.createElement("canvas").getContext("2d");
            ctx.fillStyle = color;
            color = ctx.fillStyle;
        }
        const num = color.startsWith("#") ? parseInt(color.slice(1), 16) : 0;
        if (color.startsWith("rgb")) {
            const rgb = color.match(/\d+/g).map(Number);
            return `rgb(${Math.min(255, Math.floor(rgb[0] + (255 - rgb[0]) * percent))},${Math.min(255, Math.floor(rgb[1] + (255 - rgb[1]) * percent))},${Math.min(255, Math.floor(rgb[2] + (255 - rgb[2]) * percent))})`;
        } else if (num) {
            const r = (num >> 16) + Math.round((255 - (num >> 16)) * percent);
            const g = ((num >> 8) & 0x00ff) + Math.round((255 - ((num >> 8) & 0x00ff)) * percent);
            const b = (num & 0x0000ff) + Math.round((255 - (num & 0x0000ff)) * percent);
            return `rgb(${r},${g},${b})`;
        }
        return color;
    },

    removeLastClosingTag: function (html) {
        const lastClosingTagIndex = html.lastIndexOf("</");
        if (lastClosingTagIndex !== -1) {
            const endIndex = html.indexOf(">", lastClosingTagIndex);
            if (endIndex !== -1) {
                return html.substring(0, lastClosingTagIndex) + html.substring(endIndex + 1);
            }
        }
        return html;
    },

    replaceSpecialChars: function (element) {
        const el = asElement(element);
        if (!el) return "";
        let text = el.innerHTML;
        text = text.replace(/(<\/?(div|br|script|li|ol|ul)[^>]*>)|(<|>)/g, (match, p1, p2, p3) => {
            if (p1) return p1;
            if (p3 === "<") return "&lt;";
            if (p3 === ">") return "&gt;";
            return match;
        });
        return text;
    },

    // ADR-023 Fase 2: darkerColor/lighterColor (legacy darkmode-style
    // iniettato a runtime) RIMOSSI — dead code, sostituiti da body.fm-dark.

    /**
     * Salva le scelte della verifica in un file JSON.
     * Accetta scope come Element. Ritorna una Promise (fetchJson).
     */
    salvaScelte: function (verFilePath, versionKey = "v1", scope = null) {
        console.log("💾 Inizio salvataggio scelte per:", verFilePath);

        const root = asElement(scope) || document;
        const getVal = (id) => document.getElementById(id)?.value || "";
        const isChecked = (id) => document.getElementById(id)?.checked === true;

        const data = {
            anno: getVal("anno"),
            data: new Date().toISOString().split("T")[0],
            ora: new Date().toTimeString().split(" ")[0],
            checkboxA: [],
            checkboxB: [],
            defPositionImp: [],
            vfTotalPointsInputA: [],
            vfTotalPointsInputB: [],
            collexItems: [],
            versione: getVal("versione"),
            compensa: isChecked("Compensa"),
            dsa: isChecked("DSA"),
            griglie: isChecked("griglie"),
            misure: isChecked("misure"),
            verTitle: getVal("verTitle"),
            server: isChecked("Server"),
            overleaf: isChecked("overleaf"),
            multiarg: isChecked("multiarg"),
            syncDrive: isChecked("syncDrive"),
        };

        root.querySelectorAll(".fm-groupcollex").forEach((problem) => {
            const problemId = (problem.id || "").replace(/_add\d+$/, "");

            data.checkboxA.push({
                problemId,
                checked: problem.querySelector(".checkboxA")?.checked === true,
            });

            data.checkboxB.push({
                problemId,
                checked: problem.querySelector(".checkboxB")?.checked === true,
            });

            const posValue = problem.querySelector(".fm-def-position-imp")?.value;
            if (posValue) {
                data.defPositionImp.push({ problemId, value: posValue });
            }

            if (problemId.includes("type_VF")) {
                data.vfTotalPointsInputA.push({
                    problemId,
                    value: problem.querySelector(".vf-total-points-inputA")?.value || "0",
                });
                data.vfTotalPointsInputB.push({
                    problemId,
                    value: problem.querySelector(".vf-total-points-inputB")?.value || "0",
                });
            }

            problem.querySelectorAll(".fm-collection__item").forEach((item, index) => {
                data.collexItems.push({
                    problemId,
                    index,
                    checkboxAin: item.querySelector(".fm-checkbox-ain")?.checked === true,
                    checkboxBin: item.querySelector(".fm-checkbox-bin")?.checked === true,
                    inputPt: item.querySelector(".fm-input-pt")?.value || "0",
                    checkgiust: false,
                });
            });

            // .checkgiust è dentro button.fm-collapsible (sibling di .content), uno per problem.
            const isCheckgiust = problem.querySelector(".checkgiust")?.checked === true;
            const problemItems = data.collexItems.filter((ci) => ci.problemId === problemId);
            if (problemItems.length > 0) {
                problemItems[0].checkgiust = isCheckgiust;
            }
        });

        console.log("📊 Dati raccolti:", data);

        return fetchJson(Endpoints.verifiche.saveScelte, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: new URLSearchParams({
                action: "save", verFilePath, versionKey, data: JSON.stringify(data),
            }).toString(),
        });
    },

    caricaScelte: function (verFilePath, versionKey = "v1", scope = null) {
        return fetchJson(Endpoints.verifiche.saveScelte, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: new URLSearchParams({ action: "load", verFilePath, versionKey }).toString(),
        }).then((response) => {
            if (response.success && response.data) {
                console.log("✅ Dati caricati:", response.data);
                utilities.applicaScelte(response.data, scope);
                return response;
            }
            throw new Error(response.message || "Errore nel caricamento");
        });
    },

    /**
     * Applica le scelte caricate al DOM.
     * @param {Object} data
     * @param {Element|object|null} scope
     */
    applicaScelte: function (data, scope = null) {
        const scopeEl = asElement(scope);

        // Helper: trova un .fm-groupcollex per ID base (senza suffisso _add{N}).
        const findProblem = (baseId) => {
            if (scopeEl) {
                return Array.from(scopeEl.querySelectorAll(".fm-groupcollex"))
                    .find((p) => p.id.replace(/_add\d+$/, "") === baseId) || null;
            }
            const direct = document.getElementById(baseId);
            if (direct) return direct;
            return Array.from(document.querySelectorAll(".fm-groupcollex"))
                .find((p) => p.id.replace(/_add\d+$/, "") === baseId) || null;
        };

        const setVal = (id, v) => {
            const el = document.getElementById(id);
            if (el) el.value = v;
        };
        const setChecked = (id, v) => {
            const el = document.getElementById(id);
            if (el) el.checked = !!v;
        };

        if (data.anno) setVal("anno", data.anno);
        if (data.versione) setVal("versione", data.versione);
        if (data.verTitle) setVal("verTitle", data.verTitle);
        setChecked("Server", data.server || false);
        setChecked("overleaf", data.overleaf || false);
        setChecked("multiarg", data.multiarg || false);
        setChecked("syncDrive", data.syncDrive !== false);

        setChecked("Compensa", data.compensa || false);
        setChecked("DSA", data.dsa || false);
        setChecked("griglie", data.griglie !== false);
        setChecked("misure", data.misure !== false);

        if (data.checkboxA) {
            data.checkboxA.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (problem) {
                    const cb = problem.querySelector(".checkboxA");
                    if (cb) cb.checked = !!item.checked;
                }
            });
        }

        if (data.checkboxB) {
            data.checkboxB.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (problem) {
                    const cb = problem.querySelector(".checkboxB");
                    if (cb) cb.checked = !!item.checked;
                }
            });
        }

        // Reset defPositionImp solo nel container di competenza
        const defRoot = scopeEl || document;
        defRoot.querySelectorAll(".fm-def-position-imp").forEach((el) => { el.value = ""; });

        if (data.defPositionImp) {
            data.defPositionImp.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (problem) {
                    const inp = problem.querySelector(".fm-def-position-imp");
                    if (inp) inp.value = item.value;
                }
            });
        }

        if (data.vfTotalPointsInputA) {
            data.vfTotalPointsInputA.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (!problem) return;
                const inputA = problem.querySelector(".vf-total-points-inputA");
                if (inputA) {
                    inputA.value = item.value;
                    trigger(inputA, "change");
                } else {
                    problem.dataset.pendingVfValueA = String(parseFloat(item.value) || 0);
                }
                const tot = problem.querySelector(".total-pointsA");
                if (tot) tot.textContent = item.value;
            });
        }

        if (data.vfTotalPointsInputB) {
            data.vfTotalPointsInputB.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (!problem) return;
                const inputB = problem.querySelector(".vf-total-points-inputB");
                if (inputB) {
                    inputB.value = item.value;
                    trigger(inputB, "change");
                } else {
                    problem.dataset.pendingVfValueB = String(parseFloat(item.value) || 0);
                }
                const tot = problem.querySelector(".total-pointsB");
                if (tot) tot.textContent = item.value;
            });
        }

        const checkgiustByProblem = {};
        if (data.collexItems) {
            data.collexItems.forEach((item) => {
                if (item.checkgiust && !checkgiustByProblem[item.problemId]) {
                    checkgiustByProblem[item.problemId] = true;
                }
            });

            data.collexItems.forEach((item) => {
                const problem = findProblem(item.problemId);
                if (!problem) return;
                const collexItem = problem.querySelectorAll(".fm-collection__item")[item.index];
                if (!collexItem) return;
                const ain = collexItem.querySelector(".fm-checkbox-ain");
                const bin = collexItem.querySelector(".fm-checkbox-bin");
                const pt = collexItem.querySelector(".fm-input-pt");
                if (ain) ain.checked = !!item.checkboxAin;
                if (bin) bin.checked = !!item.checkboxBin;
                if (pt) pt.value = item.inputPt;
            });
        }

        Object.keys(checkgiustByProblem).forEach((problemId) => {
            const problem = findProblem(problemId);
            if (!problem) return;
            const checkgiust = problem.querySelector(".checkgiust");
            if (!checkgiust) return;
            checkgiust.checked = true;
            checkgiust.classList.add("giustifica-checked");
            trigger(checkgiust, "change");
        });

        // Aggiorna totali punti (solo nel container di competenza)
        defRoot.querySelectorAll(".fm-groupcollex").forEach((problem) => {
            if (typeof utilities.updatePointsTotal === "function") {
                utilities.updatePointsTotal(problem);
            }
        });

        setTimeout(() => {
            if (typeof EventHendler !== "undefined" && EventHendler.reorderAllContainers) {
                EventHendler.reorderAllContainers();
            }
        }, 200);
    },

    verificaScelte: function (verFilePath, versionKey = "v1") {
        return fetchJson(Endpoints.verifiche.saveScelte, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: new URLSearchParams({ action: "check", verFilePath, versionKey }).toString(),
        });
    },

    /**
     * Calcola e aggiorna il totale punti per un problema.
     * Accetta Element o jQuery wrapper.
     */
    updatePointsTotal: function (problem) {
        const problemEl = asElement(problem);
        if (!problemEl) return;

        const problemId = problemEl.id || "";
        const isVFProblem = problemId.includes("type_VF");

        let totalPointsA = 0;
        let totalPointsB = 0;

        if (isVFProblem) {
            this.updateVFProblemPoints(problemEl);
            return;
        }

        problemEl.querySelectorAll(".fm-collection__item").forEach((collexItem) => {
            const hasCheckedA = collexItem.querySelectorAll(".fm-checkbox-ain:checked").length > 0;
            const hasCheckedB = collexItem.querySelectorAll(".fm-checkbox-bin:checked").length > 0;

            if (hasCheckedA || hasCheckedB) {
                const pointsText = collexItem.querySelector(".fm-input-pt")?.value || "0";
                const points = parseFloat(pointsText.trim()) || 0;
                if (hasCheckedA) totalPointsA += points;
                if (hasCheckedB) totalPointsB += points;
            }
        });

        const totA = problemEl.querySelector(".total-pointsA");
        const totB = problemEl.querySelector(".total-pointsB");
        if (totA) totA.textContent = totalPointsA.toString();
        if (totB) totB.textContent = totalPointsB.toString();

        this.updateGlobalPointsTotal();
    },

    updateVFProblemPoints: function (problem) {
        const problemEl = asElement(problem);
        if (!problemEl) return;

        const totalPointsElementA = problemEl.querySelector(".total-pointsA");
        const totalPointsElementB = problemEl.querySelector(".total-pointsB");
        const totalPointsA = parseFloat(totalPointsElementA?.textContent) || 0;
        const totalPointsB = parseFloat(totalPointsElementB?.textContent) || 0;

        let activeItemsCountA = 0;
        let activeItemsCountB = 0;
        problemEl.querySelectorAll(".fm-collection__item").forEach((item) => {
            if (item.querySelectorAll(".fm-checkbox-ain:checked").length > 0) activeItemsCountA++;
            if (item.querySelectorAll(".fm-checkbox-bin:checked").length > 0) activeItemsCountB++;
        });

        const pointsPerItemA = activeItemsCountA > 0 ? totalPointsA / activeItemsCountA : 0;
        const pointsPerItemB = activeItemsCountB > 0 ? totalPointsB / activeItemsCountB : 0;

        problemEl.querySelectorAll(".fm-collection__item").forEach((item) => {
            const inputPt = item.querySelector(".fm-input-pt");
            const hasCheckedA = item.querySelectorAll(".fm-checkbox-ain:checked").length > 0;
            const hasCheckedB = item.querySelectorAll(".fm-checkbox-bin:checked").length > 0;

            if (inputPt) {
                if (hasCheckedA || hasCheckedB) {
                    let points = 0;
                    if (hasCheckedA) points = Math.max(points, pointsPerItemA);
                    if (hasCheckedB) points = Math.max(points, pointsPerItemB);
                    inputPt.value = points.toFixed(2);
                } else {
                    inputPt.value = "0";
                }
            }
        });

        this.updateGlobalPointsTotal();
    },

    makeVFTotalPointsEditable: function (problem) {
        const problemEl = asElement(problem);
        if (!problemEl) return;

        const selectionScope = problemEl.querySelector(".fm-pos-check-es .selection") || problemEl;

        // Phase 24.77 — stepper custom ▲▼ accanto a ogni input number VF
        // (step 0.5). Stessa markup/handler della posizione (.fm-num-step*,
        // gestito in checkin-handlers onCheckinClick). Va inserito SUBITO dopo
        // l'input (l'handler lo localizza via previousElementSibling).
        const makeNumSteppers = () => {
            const sp = document.createElement("span");
            sp.className = "fm-stepper";
            sp.setAttribute("aria-hidden", "true");
            sp.innerHTML = '<button type="button" class="fm-stepper__btn fm-stepper__btn--up" tabindex="-1">▲</button>'
                + '<button type="button" class="fm-stepper__btn fm-stepper__btn--down" tabindex="-1">▼</button>';
            return sp;
        };

        // Versione A
        const totalPointsElementA = selectionScope.querySelector(".total-pointsA");
        if (totalPointsElementA && !problemEl.querySelector(".vf-total-points-inputA")) {
            const currentValueA = totalPointsElementA.textContent || "0";
            const inputA = document.createElement("input");
            inputA.type = "number";
            inputA.className = "vf-total-points-inputA fm-vf-total-points-input";
            inputA.step = "0.5";
            inputA.min = "0";

            const pendingValueA = problemEl.dataset.pendingVfValueA;
            if (pendingValueA !== undefined) {
                inputA.value = pendingValueA;
                totalPointsElementA.textContent = String(pendingValueA);
                delete problemEl.dataset.pendingVfValueA;
            } else {
                inputA.value = currentValueA;
            }

            const handlerA = () => {
                const newValue = parseFloat(inputA.value) || 0;
                totalPointsElementA.textContent = newValue.toString();
                this.updateVFProblemPoints(problemEl);
            };
            inputA.addEventListener("input", handlerA);
            inputA.addEventListener("change", handlerA);

            totalPointsElementA.style.display = "none";
            totalPointsElementA.after(inputA);
            inputA.after(makeNumSteppers());
        }

        // Versione B
        const totalPointsElementB = selectionScope.querySelector(".total-pointsB");
        if (totalPointsElementB && !problemEl.querySelector(".vf-total-points-inputB")) {
            const currentValueB = totalPointsElementB.textContent || "0";
            const inputB = document.createElement("input");
            inputB.type = "number";
            inputB.className = "vf-total-points-inputB fm-vf-total-points-input";
            inputB.step = "0.5";
            inputB.min = "0";

            const pendingValueB = problemEl.dataset.pendingVfValueB;
            if (pendingValueB !== undefined) {
                inputB.value = pendingValueB;
                totalPointsElementB.textContent = String(pendingValueB);
                delete problemEl.dataset.pendingVfValueB;
            } else {
                inputB.value = currentValueB;
            }

            const handlerB = () => {
                const newValue = parseFloat(inputB.value) || 0;
                totalPointsElementB.textContent = newValue.toString();
                this.updateVFProblemPoints(problemEl);
            };
            inputB.addEventListener("input", handlerB);
            inputB.addEventListener("change", handlerB);

            totalPointsElementB.style.display = "none";
            totalPointsElementB.after(inputB);
            inputB.after(makeNumSteppers());
        }

        // Retry una sola volta se nessun elemento totale è ancora presente
        if (!totalPointsElementA && !totalPointsElementB) {
            if (!problemEl.dataset.vfInitRetryScheduled) {
                problemEl.dataset.vfInitRetryScheduled = "1";
                setTimeout(() => {
                    delete problemEl.dataset.vfInitRetryScheduled;
                    this.makeVFTotalPointsEditable(problemEl);
                    this.updateVFProblemPoints(problemEl);
                }, 180);
            }
        }
    },

    initializeVFProblems: function () {
        document.querySelectorAll(".fm-groupcollex").forEach((problem) => {
            const problemId = problem.id || "";
            if (!problemId.includes("type_VF")) return;

            const hasInput = problem.querySelector(".fm-vf-total-points-input");
            if (!hasInput) {
                utilities.makeVFTotalPointsEditable(problem);
                utilities.updateVFProblemPoints(problem);
                problem.classList.add("vf-initialized");
            } else {
                console.log(`    ⏭️ Skip ${problemId} (input già esistente)`);
            }
        });
    },

    updateGlobalPointsTotal: function () {
        let globalTotalA = 0;
        let globalTotalB = 0;

        document.querySelectorAll(".total-pointsA").forEach((el) => {
            globalTotalA += parseFloat(el.textContent) || 0;
        });
        document.querySelectorAll(".total-pointsB").forEach((el) => {
            globalTotalB += parseFloat(el.textContent) || 0;
        });

        const sumA = document.getElementById("SumPtotA");
        const sumB = document.getElementById("SumPtotB");
        if (sumA) sumA.value = globalTotalA.toString();
        if (sumB) sumB.value = globalTotalB.toString();
    },

    /**
     * Inizializza un tooltip per un elemento specifico.
     * @param {string} triggerSelector
     * @param {string} tooltipId - "#xxx" (incluso il #)
     * @param {Object} offset
     * @param {boolean} useDelegation
     */
    initTooltip: function (triggerSelector, tooltipId, offset = { x: 20, y: 10 }, useDelegation = true) {
        const offsetX = offset.x || 20;
        const offsetY = offset.y || 10;

        const tooltipEl = () => document.querySelector(tooltipId);
        const setPos = (e) => {
            const el = tooltipEl();
            if (!el) return;
            el.style.left = `${e.clientX + offsetX}px`;
            el.style.top = `${e.clientY + offsetY}px`;
        };

        if (useDelegation) {
            // Delegation: mouseover/mouseout (mouseenter non bubblano)
            document.addEventListener("mouseover", (event) => {
                if (event.target.closest(triggerSelector)) {
                    const el = tooltipEl();
                    if (el) el.style.display = "block";
                    setPos(event);
                }
            });
            document.addEventListener("mouseout", (event) => {
                if (event.target.closest(triggerSelector)) {
                    const el = tooltipEl();
                    if (el) el.style.display = "none";
                }
            });
            document.addEventListener("mousemove", (event) => {
                if (event.target.closest(triggerSelector)) {
                    setPos(event);
                }
            });
        } else {
            document.querySelectorAll(triggerSelector).forEach((trigger) => {
                trigger.addEventListener("mouseenter", (event) => {
                    const el = tooltipEl();
                    if (el) el.style.display = "block";
                    setPos(event);
                });
                trigger.addEventListener("mouseleave", () => {
                    const el = tooltipEl();
                    if (el) el.style.display = "none";
                });
                trigger.addEventListener("mousemove", setPos);
            });
        }
    },
};

window.FM = window.FM || {};
window.FM.utilities = utilities;
window.utilities    = utilities;
