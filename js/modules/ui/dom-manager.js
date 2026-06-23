/**
 * DOMManager — estratto da script.js (Phase 9j). sidebar DOM ops.
 * G26.phase6.4 — migrato a vanilla JS (no jQuery).
 *
 * Note transition:
 *  - `elements.body/sidebar/frame/iframe/...` ora sono Element (non jq wrapper).
 *  - `elements.iframe` può essere null se #myframe non esiste; tutti i callsite
 *    fanno guard (caso post-Phase16 SPA dove iframe è opzionale).
 *  - Event delegation namespaced (`.app`, `.linkref`, ecc.) replica via onNs()
 *    WeakMap-tracked handler refs.
 */
import { Endpoints } from "../core/endpoints.js";
import { codesFor } from "../core/curriculum-codes.js";
import { asElement, isVisible, trigger, outerHeight, outerWidth } from "../core/dom-utils.js";

/** offset() jQuery — top/left assoluti vs documento. */
function offset(el) {
    if (!el) return { top: 0, left: 0 };
    const rect = el.getBoundingClientRect();
    return { top: rect.top + window.scrollY, left: rect.left + window.scrollX };
}

/** WeakMap-namespaced event delegation (replica jQuery .on("event.ns")/.off(".ns")). */
const _namespacedHandlers = new Map();
function offNs(target, event, ns) {
    const key = `${event}.${ns}`;
    const targetMap = _namespacedHandlers.get(target);
    if (!targetMap) return;
    const fn = targetMap.get(key);
    if (fn) {
        target.removeEventListener(event, fn);
        targetMap.delete(key);
    }
}
function onNs(target, event, ns, handler) {
    offNs(target, event, ns);
    let targetMap = _namespacedHandlers.get(target);
    if (!targetMap) {
        targetMap = new Map();
        _namespacedHandlers.set(target, targetMap);
    }
    targetMap.set(`${event}.${ns}`, handler);
    target.addEventListener(event, handler);
}

export const DOMManager = {
    elements: {},
    detachedChildren: null,
    sidebarContentState: null,
    pendingFrameUrls: new Set(),

    init: function () {
        this.elements = {
            body:           document.body,
            sidebar:        document.querySelector(".sidebar"),
            frame:          document.querySelector(".frame"),
            iframe:         document.getElementById("myframe"),
            switchContainer: document.querySelector(".fm-switch"),
            ioBar:          document.getElementById("IObar"),
            slider:         document.querySelector(".fm-sb-slider"),
            closeTextMenu:  document.querySelector(".fm-sb-close"),
            suggestionsDiv: null,
        };
        this.injectHiddenForm();
        this._bindGlobalEvents();
        this._bindResizeHandler();
        this.updateLayout();
        this._initializeIOBar();
        this._restoreIOBarState();
    },

    _bindResizeHandler: function () {
        onNs(window, "resize", "app", () => this.updateLayout());
    },

    injectHiddenForm: function () {
        if (!this.elements.body) return;
        // Idempotente: se il form Overleaf esiste già non ne crea un secondo
        // (init può girare più volte → ID duplicati overleaf-form/overleaf-snippet,
        // invalidi e ambigui per le reference ARIA).
        if (document.getElementById("overleaf-form")) return;
        this.elements.body.insertAdjacentHTML("beforeend",
            `<form id="overleaf-form" action="https://www.overleaf.com/docs" method="post" target="_blank" style="display: none;"><textarea name="snip[main.tex]" id="overleaf-snippet"></textarea></form>`);
    },

    _bindGlobalEvents: function () {
        const body = this.elements.body;
        if (!body) return;
        const self = this;

        // Delegation namespaced "click.app" via single handler che dispatcha sui selector
        onNs(body, "click", "app", (e) => {
            const t = e.target;
            if (t.closest("#btnAct_sidebar")) {
                App.toggleEditMode();
                return;
            }
            const addBtn = t.closest(".addArgBtn");
            if (addBtn) {
                e.preventDefault();
                e.stopPropagation();
                self.handleAddArgument(addBtn);
                return;
            }
            const delBtn = t.closest(".delArgBtn");
            if (delBtn) {
                self.handleDeleteArgument(delBtn);
                return;
            }
            const driveBtn = t.closest(".DriveBtn");
            if (driveBtn) {
                e.preventDefault();
                e.stopPropagation();
                self.handleDriveButtonClick(driveBtn);
                return;
            }
        });

        // change sui select globali (no namespace — uno solo)
        document.querySelectorAll("select").forEach((sel) => {
            sel.addEventListener("change", () => App.handleSelectChange());
        });

        if (this.elements.ioBar) {
            this.elements.ioBar.addEventListener("change", () => this.toggleSidebar());
        }

        // a.linkref delegation
        onNs(body, "click", "linkref", (e) => {
            const link = e.target.closest("a.linkref");
            if (!link) return;
            e.preventDefault();
            App.handleLinkrefClick(link);
        });

        // Phase 25.E15 — legacy /cookies_privacy-policy.html → /privacy/informativa
        body.addEventListener("click", (e) => {
            const legacyLink = e.target.closest('a[href="/cookies_privacy-policy.html"]');
            if (!legacyLink) return;
            e.preventDefault();
            const target = "/privacy/informativa";
            AppState.linkref = "privacy-policy";
            sessionStorage.setItem("linkref", AppState.linkref);
            AppState.addVisitedLink(target);
            if (window.fmRouter?.navigate) {
                window.fmRouter.navigate(target);
            } else {
                window.location.href = target;
            }
        });

        // Tooltip per #btnAct_sidebar e #control-panel-info via mouseover/out/move
        // (mouseenter/mouseleave non bubblano → uso mouseover/mouseout con closest)
        onNs(body, "mouseover", "btnTooltip", (e) => {
            const btn = e.target.closest("#btnAct_sidebar");
            if (btn) {
                const btnText = (btn.textContent || "").trim().toUpperCase();
                if (btnText.includes("SALVA")) {
                    document.querySelectorAll("#fm-sb-tip, #tooltip").forEach((el) => {
                        el.style.display = "block";
                        el.style.left = `${e.pageX - 210}px`;
                        el.style.top = `${e.pageY - 80}px`;
                    });
                }
                return;
            }
            const cpInfo = e.target.closest("#control-panel-info");
            if (cpInfo) {
                const tooltip = document.getElementById("control-panel-tooltip");
                if (tooltip) {
                    tooltip.style.display = "block";
                    tooltip.style.position = "absolute";
                    tooltip.style.left = `${e.pageX - 200}px`;
                    tooltip.style.top = `${e.pageY - 10}px`;
                    tooltip.style.zIndex = "1000";
                }
            }
        });

        onNs(body, "mouseout", "btnTooltip", (e) => {
            const btn = e.target.closest("#btnAct_sidebar");
            if (btn) {
                // Verifica che mouse esca dall'elemento (relatedTarget non dentro)
                if (!btn.contains(e.relatedTarget)) {
                    document.querySelectorAll("#fm-sb-tip, #tooltip").forEach((el) => {
                        el.style.display = "none";
                    });
                }
                return;
            }
            const cpInfo = e.target.closest("#control-panel-info");
            if (cpInfo && !cpInfo.contains(e.relatedTarget)) {
                const tooltip = document.getElementById("control-panel-tooltip");
                if (tooltip) tooltip.style.display = "none";
            }
        });

        onNs(body, "mousemove", "btnTooltip", (e) => {
            const btn = e.target.closest("#btnAct_sidebar");
            if (btn) {
                const btnText = (btn.textContent || "").trim().toUpperCase();
                if (btnText.includes("SALVA")) {
                    document.querySelectorAll("#fm-sb-tip, #tooltip").forEach((el) => {
                        el.style.left = `${e.pageX - 210}px`;
                        el.style.top = `${e.pageY - 80}px`;
                    });
                }
                return;
            }
            const cpInfo = e.target.closest("#control-panel-info");
            if (cpInfo) {
                const tooltip = document.getElementById("control-panel-tooltip");
                if (tooltip) {
                    tooltip.style.left = `${e.pageX - 200}px`;
                    tooltip.style.top = `${e.pageY - 10}px`;
                }
            }
        });
    },

    _initializeIOBar: function () {
        if (this.elements.slider) this.elements.slider.textContent = "✖";
    },

    _restoreIOBarState: function () {
        const closed = sessionStorage.getItem("ioBarState") === "closed";
        if (this.elements.ioBar) this.elements.ioBar.checked = !closed;
        AppState.sidebarCheck = closed ? 0 : 1;
        document.body.classList.toggle("fm-sidebar-closed", closed);
        if (this.elements.slider) this.elements.slider.textContent = closed ? "☰" : "✖";
    },

    _reattachEventListeners: function () {
        const body = this.elements.body;
        if (!body) return;
        const self = this;

        onNs(body, "click", "linkref", (e) => {
            const link = e.target.closest("a.linkref");
            if (!link) return;
            e.preventDefault();
            App.handleLinkrefClick(link);
        });

        // .fm-sb-sec click.sidebarBtn — rebind (cloneNode resetta listener su questi nodi statici)
        document.querySelectorAll(".fm-sb-sec").forEach((btn) => {
            const newBtn = btn.cloneNode(true);
            btn.replaceWith(newBtn);
            newBtn.addEventListener("click", () => {
                const sidepageKey = newBtn.getAttribute("data-sidepage");
                if (!sidepageKey) return;

                const sidebarId = Object.keys(Config.SIDEBAR_CONFIG).find(
                    (key) => Config.SIDEBAR_CONFIG[key].sidepage === sidepageKey,
                );
                const dirName = Config.SIDEBAR_CONFIG[sidebarId]?.dirName;

                if (sidebarId && dirName) {
                    const bordStore = `bordSt_${sidepageKey}`;
                    const page = `/${dirName}/${AppState.folder}/${dirName}_${AppState.optsel}.html`;
                    DOMManager.toggleSidebarSection(newBtn, sidebarId, bordStore, page);
                }
            });
        });

        if (AppState.isEditMode) {
            onNs(body, "click", "editMode", (e) => {
                const addBtn = e.target.closest(".addArgBtn");
                if (!addBtn) return;
                e.preventDefault();
                e.stopPropagation();
                self.handleAddArgument(addBtn);
            });

            onNs(body, "click", "editMode_del", (e) => {
                const delBtn = e.target.closest(".delArgBtn");
                if (delBtn) self.handleDeleteArgument(delBtn);
            });
        }
    },

    toggleSidebar: function () {
        const open = this.elements.ioBar?.checked === true;
        AppState.sidebarCheck = open ? 1 : 0;
        sessionStorage.setItem("ioBarState", open ? "open" : "closed");
        document.body.classList.toggle("fm-sidebar-closed", !open);
        if (this.elements.slider) this.elements.slider.textContent = open ? "✖" : "☰";

        if (this.elements.iframe) {
            this.postMessageToFrame({ type: "sidebarCheck", data: AppState.sidebarCheck });
        }
    },

    updateButtonState: function (isEditMode) {
        const btn = document.getElementById("btnAct_sidebar");
        if (!btn) return;
        if (isEditMode) {
            btn.style.backgroundColor = "#ff0000";
            btn.style.color = "white";
            btn.innerHTML = '<strong style="letter-spacing: 5px;">SALVA</strong>';
        } else {
            btn.style.backgroundColor = "#f2ff00";
            btn.style.color = "black";
            btn.innerHTML = '<strong style="letter-spacing: 3px;">ATTIVA</strong>';
        }
    },

    /**
     * @param {Element|object} linkElement - link element (jQuery wrapper o Element)
     * @param {object} data
     * @param {boolean} hasFile
     * @param {Element|object} template - template element (jQuery wrapper o Element)
     * @param {string} category
     * @param {object} verfilenames
     */
    switchToEditView: function (linkElement, data, hasFile, template, category, verfilenames) {
        const linkEl = asElement(linkElement);
        const templateEl = asElement(template);
        if (!linkEl || !templateEl) return;
        const newDiv = templateEl.cloneNode(true);

        const setInputVal = (selector, value) => {
            const inp = newDiv.querySelector(selector);
            if (inp) inp.value = value;
        };
        setInputVal(".input-numArg", data.numArg);
        setInputVal(".input-argomento", data.argomento);
        setInputVal(".input-href", data.href);
        setInputVal(".input-href-hide", data["href-hide"] || "");

        const displayToggle = newDiv.querySelector(".display-toggle");
        const displayValue = data.display || "show";
        const icon = displayValue === "show" ? "👁️" : "🙈";
        if (displayToggle) {
            displayToggle.setAttribute("data-display", displayValue);
            displayToggle.textContent = icon;
            displayToggle.setAttribute("title", displayValue === "show"
                ? "Elemento visibile - Clicca per nascondere"
                : "Elemento nascosto - Clicca per mostrare");
        }

        if (displayValue === "hide") newDiv.setAttribute("data-hidden", "true");

        this._setupInputInteractions(newDiv, hasFile, category, verfilenames);

        const parentLi = linkEl.parentElement;
        if (parentLi && parentLi.tagName === "LI") {
            parentLi.replaceChildren(newDiv);
        } else {
            linkEl.replaceWith(newDiv);
        }
    },

    _setupInputInteractions: function (inputWrapper, hasFile, category, verfilenames) {
        const wrap = asElement(inputWrapper);
        if (!wrap) return;
        const hrefInput = wrap.querySelector(".input-href");
        const argInput = wrap.querySelector(".input-argomento");
        const saveFileButton = wrap.querySelector(".saveFile");
        const accessFileButton = wrap.querySelector(".accessFile");
        const checkboxWrapper = wrap.querySelector(".checkbox-wrapper-saveFile");
        const saveFileLabel = saveFileButton?.nextElementSibling?.tagName === "LABEL"
            ? saveFileButton.nextElementSibling : null;

        const hrefHideWrapper = wrap.querySelector(".input-wrapper-href-hide");

        if (hasFile) {
            if (saveFileButton) {
                saveFileButton.checked = true;
                saveFileButton.disabled = true;
            }
            if (checkboxWrapper) checkboxWrapper.style.backgroundColor = "red";
            if (hrefInput) {
                hrefInput.disabled = true;
                hrefInput.style.opacity = "0.5";
            }
            if (saveFileLabel) saveFileLabel.textContent = "Saved";

            hrefHideWrapper?.classList.remove("show");
            this._checkFileProtection(hrefInput?.value, accessFileButton);
        } else {
            hrefHideWrapper?.classList.add("show");
        }

        if (hrefInput) {
            onNs(hrefInput, "input", "hrefLogic", () => {
                if (!hasFile) {
                    const isExternal = hrefInput.value.includes("https://") || hrefInput.value.includes("www.");
                    if (checkboxWrapper) checkboxWrapper.style.backgroundColor = isExternal ? "grey" : "orange";
                }
                const isExternal = hrefInput.value.includes("https://") || hrefInput.value.includes("www.");
                if (saveFileButton) {
                    saveFileButton.disabled = isExternal;
                    saveFileButton.checked = isExternal ? false : saveFileButton.checked;
                }
            });
        }

        if (saveFileButton) {
            onNs(saveFileButton, "change", "saveFileLogic", () => {
                const isChecked = saveFileButton.checked;
                const hrefHide = wrap.querySelector(".input-wrapper-href-hide");
                if (hrefInput) {
                    hrefInput.disabled = isChecked;
                    hrefInput.style.opacity = isChecked ? "0.5" : "1";
                }
                if (isChecked) {
                    hrefHide?.classList.remove("show");
                    if (checkboxWrapper) checkboxWrapper.style.backgroundColor = "red";
                    if (saveFileLabel) saveFileLabel.textContent = "Saved";
                } else {
                    hrefHide?.classList.add("show");
                    const isExternal = hrefInput?.value.includes("https://") || hrefInput?.value.includes("www.");
                    if (checkboxWrapper) checkboxWrapper.style.backgroundColor = isExternal ? "grey" : "orange";
                    if (saveFileLabel) saveFileLabel.textContent = "Salva";
                }
            });
        }

        if (accessFileButton) {
            onNs(accessFileButton, "change", "accessFileLogic", () => {
                const isChecked = accessFileButton.checked;
                const accessLabel = accessFileButton.nextElementSibling?.tagName === "LABEL"
                    ? accessFileButton.nextElementSibling : null;
                const accessCheckboxWrapper = accessFileButton.closest(".checkbox-wrapper-accessFile");
                if (isChecked) {
                    if (accessLabel) {
                        accessLabel.textContent = "🔒";
                        accessLabel.style.color = "black";
                    }
                    if (accessCheckboxWrapper) accessCheckboxWrapper.style.backgroundColor = "red";
                    console.log("🔒 Protezione attivata per questo file");
                } else {
                    if (accessLabel) {
                        accessLabel.textContent = "🔓";
                        accessLabel.style.color = "white";
                    }
                    if (accessCheckboxWrapper) accessCheckboxWrapper.style.backgroundColor = "";
                    console.log("🔓 Protezione disattivata per questo file");
                }
            });
        }

        const displayToggle = wrap.querySelector(".display-toggle");
        if (displayToggle) {
            onNs(displayToggle, "click", "displayToggle", () => {
                const currentDisplay = displayToggle.getAttribute("data-display");
                const newDisplay = currentDisplay === "show" ? "hide" : "show";
                const newIcon = newDisplay === "show" ? "👁️" : "🙈";
                displayToggle.setAttribute("data-display", newDisplay);
                displayToggle.textContent = newIcon;
                displayToggle.setAttribute("title", newDisplay === "show"
                    ? "Elemento visibile - Clicca per nascondere"
                    : "Elemento nascosto - Clicca per mostrare");

                const wrapper = displayToggle.closest(".input-wrapper-linkref");
                if (wrapper) {
                    if (newDisplay === "hide") wrapper.setAttribute("data-hidden", "true");
                    else wrapper.removeAttribute("data-hidden");
                }
            });
        }

        if (argInput) {
            if (category && verfilenames && Object.prototype.hasOwnProperty.call(verfilenames, category)) {
                onNs(argInput, "input", "suggestions", () => {
                    this.handleArgumentInputWithSuggestions(argInput, category, verfilenames);
                });
            } else {
                onNs(argInput, "input", "validation", () => {
                    const specialChars = /[-\/\\?%#]/g;
                    if (specialChars.test(argInput.value)) {
                        argInput.value = argInput.value.replace(specialChars, "");
                        this.showValidationError(wrap, "Carattere non consentito");
                    }
                });
            }
        }

        const hLinkOpener = wrap.querySelector(".h-link-opener");
        if (hLinkOpener) {
            onNs(hLinkOpener, "click", "hLinkOpener", () => {
                const siblings = Array.from(hLinkOpener.parentElement.children);
                const hrefHideInput = siblings.find((s) => s.classList.contains("input-href-hide"));
                const hrefHideValue = hrefHideInput?.value?.trim();
                if (hrefHideValue) {
                    let url = hrefHideValue;
                    if (!url.startsWith("http://") && !url.startsWith("https://") && !url.startsWith("www.")) {
                        url = `https://${url}`;
                    } else if (url.startsWith("www.")) {
                        url = `https://${url}`;
                    }
                    window.open(url, "_blank", "noopener,noreferrer");
                } else {
                    alert("Nessun link H-link specificato");
                }
            });
        }

        if (hrefInput) trigger(hrefInput, "input");
    },

    _checkFileProtection: async function (fileUrl, accessFileButton) {
        if (!fileUrl || !accessFileButton) return;
        try {
            const response = await Api.checkFileProtection(fileUrl);
            console.log("🔍 Verifica protezione file:", { fileUrl, response });
            const isProtected = response && response.isProtected === true;

            const accessLabel = accessFileButton.nextElementSibling?.tagName === "LABEL"
                ? accessFileButton.nextElementSibling : null;
            const wrapper = accessFileButton.closest(".checkbox-wrapper-accessFile");

            if (isProtected) {
                accessFileButton.checked = true;
                if (accessLabel) {
                    accessLabel.textContent = "🔒";
                    accessLabel.style.color = "black";
                }
                if (wrapper) wrapper.style.backgroundColor = "red";
                console.log("🔒 File protetto -", response.reason || "Include AuthCode.php presente");
            } else {
                accessFileButton.checked = false;
                if (accessLabel) {
                    accessLabel.textContent = "🔓";
                    accessLabel.style.color = "";
                }
                if (wrapper) wrapper.style.backgroundColor = "";
                console.log("🔓 File non protetto -", response.reason || "Nessun include AuthCode.php trovato");
            }
        } catch (error) {
            console.warn("⚠️ Impossibile verificare la protezione del file:", error);
            accessFileButton.checked = false;
            const accessLabel = accessFileButton.nextElementSibling?.tagName === "LABEL"
                ? accessFileButton.nextElementSibling : null;
            if (accessLabel) {
                accessLabel.textContent = "🔓";
                accessLabel.style.color = "";
            }
            const wrapper = accessFileButton.closest(".checkbox-wrapper-accessFile");
            if (wrapper) wrapper.style.backgroundColor = "";
        }
    },

    handleArgumentInputWithSuggestions: function (input, category, verfilenames) {
        const argInput = asElement(input);
        if (!argInput) return;
        const specialChars = /[-\/\\?%#]/g;
        if (specialChars.test(argInput.value)) {
            argInput.value = argInput.value.replace(specialChars, "");
        }
        this._showSuggestions(argInput, category, verfilenames);
    },

    _showSuggestions: function (argInput, category, verfilenames) {
        if (!this.elements.suggestionsDiv) {
            const div = document.createElement("div");
            div.id = "suggestions-container";
            div.classList.add("suggestions");
            this.elements.body.appendChild(div);
            this.elements.suggestionsDiv = div;
        }
        const suggestionsDiv = this.elements.suggestionsDiv;
        const suggestions = verfilenames[category] || [];
        suggestionsDiv.replaceChildren();
        suggestionsDiv.style.display = "none";
        if (suggestions.length === 0) return;

        const words = (argInput.value || "").trim().toLowerCase().split(/\s+/).filter(Boolean);
        if (words.length === 0) return;

        const filteredSuggestions = suggestions
            .map((s) => ({ suggestion: s, count: words.reduce((a, w) => a + (s.toLowerCase().includes(w) ? 1 : 0), 0) }))
            .filter((i) => i.count > 0)
            .sort((a, b) => b.count - a.count);
        if (filteredSuggestions.length === 0) return;

        filteredSuggestions.forEach((item) => {
            let highlighted = item.suggestion;
            words.forEach((word) => {
                highlighted = highlighted.replace(new RegExp(`(${word})`, "gi"), '<span class="highlight">$1</span>');
            });
            const div = document.createElement("div");
            div.classList.add("suggestion-item");
            div.innerHTML = highlighted;
            div.addEventListener("click", () => {
                argInput.value = item.suggestion;
                suggestionsDiv.style.display = "none";
                suggestionsDiv.replaceChildren();
            });
            suggestionsDiv.appendChild(div);
        });

        const argOffset = offset(argInput);
        suggestionsDiv.style.top = `${argOffset.top + outerHeight(argInput)}px`;
        suggestionsDiv.style.left = `${argOffset.left}px`;
        suggestionsDiv.style.width = `${outerWidth(argInput)}px`;
        suggestionsDiv.style.display = "";

        const onceMouseDown = (e) => {
            if (!suggestionsDiv.contains(e.target)) {
                suggestionsDiv.style.display = "none";
                suggestionsDiv.replaceChildren();
            }
            document.removeEventListener("mousedown", onceMouseDown);
        };
        document.addEventListener("mousedown", onceMouseDown);
    },

    handleAddArgument: async function (button) {
        const btnEl = asElement(button);
        if (!btnEl) return;
        try {
            const templateHtml = await Api.fetchHtmlTemplate("./Elementi_Riservati.html");
            const templateContainer = document.createElement("div");
            templateContainer.innerHTML = templateHtml;
            const newFormElement = templateContainer.querySelector(".input-wrapper-linkref");
            if (!newFormElement) return;

            const uniqueID = Utils.generateUUID();
            const newLi = document.createElement("li");
            newLi.id = uniqueID;
            newLi.appendChild(newFormElement);

            if (btnEl.classList.contains("addArgBtn-main")) {
                const sectionHeader = btnEl.closest(".materia, .documenti");
                const mainContainer = sectionHeader?.parentElement;
                if (!mainContainer) return;
                let targetUl = Array.from(mainContainer.children).find((c) => c.tagName === "UL");
                if (!targetUl) {
                    targetUl = document.createElement("ul");
                    sectionHeader.after(targetUl);
                }
                targetUl.insertBefore(newLi, targetUl.firstChild);
            } else {
                const currentLi = btnEl.closest("li");
                if (currentLi) currentLi.after(newLi);
            }

            const verfilenames = await Api.filesInVerifiche();
            const { category } = App._getCategoryConfigFromElement(newFormElement);
            this._setupInputInteractions(newFormElement, false, category, verfilenames);
        } catch (error) {
            console.error("❌ Errore in handleAddArgument:", error);
        }
    },

    handleDeleteArgument: async function (button) {
        const btnEl = asElement(button);
        if (!btnEl) return;
        const liElement = btnEl.closest("li");
        if (!liElement || !await window.FM.Dialog.confirm("Sei sicuro di voler eliminare questo elemento?")) return;
        try {
            const inputWrapper = liElement.querySelector(".input-wrapper-linkref");
            const href = inputWrapper?.querySelector(".input-href")?.value;
            const elemID = liElement.id;
            if (!href) {
                liElement.remove();
                return;
            }
            const { category: _category, config } = App._getCategoryConfigFromElement(inputWrapper);
            if (!config) {
                liElement.remove();
                return;
            }
            const paths = config.pathPattern(config.dirName, "", _category, "", AppState.optsel, AppState.folder);
            await Api.deleteFile({ fileHref: href, elemID, file_links: paths.file_links });
            liElement.remove();

            console.log("🔍 Verifico se è un file verifica o esercizio. href:", href);

            const deleteFolder = async (svgFolderPath) => {
                const r = await fetch(Endpoints.files.deleteFolder, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                    body: new URLSearchParams({ folderPath: svgFolderPath }).toString(),
                });
                return await r.text();
            };

            // Elimina cartella SVG per verifiche
            if (href.includes("-ver.php")) {
                console.log("✅ È un file verifica, procedo con eliminazione cartella SVG");
                try {
                    const fileName = href.split("/").pop().replace(".php", "");
                    let svgFolderName = fileName.replace(/^[\d.]+_/, "").replace(/-[a-z]+\d+[a-z]$/, "");
                    if (!svgFolderName.endsWith("-svg")) svgFolderName += "-svg";
                    const svgFolderPath = `${href.substring(0, href.lastIndexOf("/"))}/svg/${svgFolderName}`;
                    console.log("🗑️ Tentativo eliminazione cartella SVG verifica:", svgFolderPath);
                    const response = await deleteFolder(svgFolderPath);
                    console.log("✅ Risposta server eliminazione SVG verifica:", response);
                } catch (svgError) {
                    console.error("❌ Errore eliminazione cartella SVG verifica:", svgError);
                    if (svgError.responseText) console.error("Risposta errore:", svgError.responseText);
                }
            }

            if (href.includes("/eser/")) {
                console.log("✅ È un file esercizio, procedo con eliminazione cartella SVG");
                try {
                    const fileName = href.split("/").pop().replace(".php", "");
                    let svgFolderName = fileName.replace(/^[\d.]+_/, "").replace(/-[a-z]+\d+[a-z]$/, "");
                    if (!svgFolderName.endsWith("-svg")) svgFolderName += "-svg";
                    const svgFolderPath = `${href.substring(0, href.lastIndexOf("/"))}/svg/${svgFolderName}`;
                    console.log("🗑️ Tentativo eliminazione cartella SVG esercizio:", svgFolderPath);
                    const response = await deleteFolder(svgFolderPath);
                    console.log("✅ Risposta server eliminazione SVG esercizio:", response);
                } catch (svgError) {
                    console.error("❌ Errore eliminazione cartella SVG esercizio:", svgError);
                    if (svgError.responseText) console.error("Risposta errore:", svgError.responseText);
                }
            }

            if (href.includes("/eser/") && await window.FM.Dialog.confirm("Vuoi eliminare anche la relativa VERIFICA?")) {
                const verpath = new Utils.PathFileVerExtractor(href).verPath();
                console.log("🔍 Eliminazione verifica collegata:", verpath);
                await Api.deleteFile({ fileHref: verpath, file_links: "" });

                try {
                    const fileName = verpath.split("/").pop().replace(".php", "");
                    let svgFolderName = fileName
                        .replace(/^[\d.]+_/, "")
                        .replace(/-ver$/, "")
                        .replace(/-[a-z]+\d+[a-z]$/, "");
                    if (!svgFolderName.endsWith("-svg")) svgFolderName += "-svg";
                    const svgFolderPath = `${verpath.substring(0, verpath.lastIndexOf("/"))}/svg/${svgFolderName}`;
                    console.log("🗑️ Tentativo eliminazione cartella SVG verifica:", svgFolderPath);
                    const response = await deleteFolder(svgFolderPath);
                    console.log("✅ Risposta server eliminazione SVG verifica:", response);
                } catch (svgError) {
                    console.error("❌ Errore eliminazione cartella SVG verifica:", svgError);
                    if (svgError.responseText) console.error("Risposta errore:", svgError.responseText);
                }
            }
        } catch (_error) {
            alert("Errore durante l'eliminazione.");
        }
    },

    handleDriveButtonClick: async function (button) {
        const btnEl = asElement(button);
        if (!btnEl) return;
        try {
            console.log("🎯 DriveBtn clicked, cercando categoria più vicina...");
            const category = this._findClosestCategoryId(btnEl);
            if (!category) {
                alert("Impossibile determinare la categoria (MAT, FIS, GEO) per questo elemento.");
                return;
            }
            console.log(`📋 Categoria trovata: ${category}`);

            const jsonFilePath = this._buildDrawioLinksPath(category);
            console.log(`📄 Percorso file JSON: ${jsonFilePath}`);
            const driveData = await this._loadDrawioLinksData(jsonFilePath);
            if (!driveData) {
                alert(`File drawio-links-${category}.json non trovato o vuoto.`);
                return;
            }
            this._openGoogleDriveFolder(driveData);
        } catch (error) {
            console.error("❌ Errore in handleDriveButtonClick:", error);
            alert(`Errore durante l'apertura della cartella Google Drive: ${error.message}`);
        }
    },

    _findClosestCategoryId: function (button) {
        const btnEl = asElement(button);
        if (!btnEl) return null;
        // Materie DINAMICHE dal catalogo curriculum (no preset hardcoded).
        const categories = codesFor("materie");
        if (categories.length === 0) return null;

        let element = btnEl;
        while (element) {
            if (element.id && categories.includes(element.id)) return element.id;
            const categoryEl = element.querySelector?.(`#${categories.join(", #")}`);
            if (categoryEl) return categoryEl.id;
            element = element.parentElement;
        }

        for (const category of categories) {
            const el = document.getElementById(category);
            if (el && isVisible(el)) return category;
        }
        return null;
    },

    _buildDrawioLinksPath: function (category) {
        const folder = AppState.folder || "ART";
        const optsel = AppState.optsel || "ART3S";
        return `/mappe/${folder}/mappe_${optsel}/${category}/drawio-links-${category}.json`;
    },

    _loadDrawioLinksData: async function (filePath) {
        try {
            console.log(`📂 Caricamento file: ${filePath}`);
            const response = await fetch(filePath, {
                method: "GET",
                headers: { "Cache-Control": "no-cache" },
            });
            if (!response.ok) {
                console.warn(`⚠️ File non trovato: ${filePath} (status: ${response.status})`);
                return null;
            }
            const jsonText = await response.text();
            if (!jsonText || jsonText.trim() === "") {
                console.warn(`⚠️ File vuoto: ${filePath}`);
                return null;
            }
            const data = JSON.parse(jsonText);
            console.log("✅ Dati JSON caricati:", data);
            return data;
        } catch (error) {
            console.error(`❌ Errore caricamento ${filePath}:`, error);
            return null;
        }
    },

    _openGoogleDriveFolder: function (driveData) {
        try {
            let driveUrl = "";
            if (driveData.cartella && driveData.cartella.driveId) {
                driveUrl = `https://drive.google.com/drive/folders/${driveData.cartella.driveId}`;
            } else if (driveData.files && driveData.files.length > 0) {
                const firstFile = driveData.files[0];
                if (firstFile.editLink || firstFile.viewerLink) {
                    const fileUrl = firstFile.editLink || firstFile.viewerLink;
                    const folderMatch = fileUrl.match(/\/folders\/([a-zA-Z0-9-_]+)/);
                    if (folderMatch) driveUrl = `https://drive.google.com/drive/folders/${folderMatch[1]}`;
                }
            }
            if (!driveUrl) {
                alert("Impossibile determinare l'URL della cartella Google Drive.");
                return;
            }
            const newWindow = window.open(driveUrl, "_blank", "noopener,noreferrer");
            if (!newWindow) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(driveUrl).catch((err) => {
                        console.warn("⚠️ Impossibile copiare negli appunti:", err);
                    });
                }
            }

            AppState.linkref = `drive-${driveData.cartella?.materia || "unknown"}`;
            sessionStorage.setItem("linkref", AppState.linkref);
            AppState.addVisitedLink(driveUrl);

            console.log(`✅ Cartella Google Drive aperta per ${driveData.cartella?.materiaName || "materia sconosciuta"}`);
        } catch (error) {
            console.error("❌ Errore apertura Google Drive:", error);
            alert("Errore durante l'apertura della cartella Google Drive.");
        }
    },

    createFile: async function () {
        const fileName = await window.FM.Dialog.prompt("Inserisci il nome del file (con estensione):");
        if (!fileName) return;
        fetch("create_File.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `fileName=${encodeURIComponent(fileName)}&fileContent=`,
        })
            .then((response) => response.text())
            .then((data) => {
                if (data.includes("successo")) {
                    this.loadFileList();
                    alert("File creato con successo!");
                } else {
                    alert(`Errore nella creazione del file: ${data}`);
                }
            })
            .catch((error) => {
                console.error("Errore:", error);
                alert("Errore nella creazione del file");
            });
    },

    deleteFile: async function () {
        const fileName = await window.FM.Dialog.prompt("Inserisci il nome del file da eliminare:");
        if (!fileName || !await window.FM.Dialog.confirm(`Sei sicuro di voler eliminare il file "${fileName}"?`)) return;
        fetch("delete_File.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `fileName=${encodeURIComponent(fileName)}`,
        })
            .then((response) => response.text())
            .then((data) => {
                if (data.includes("successo")) {
                    this.loadFileList();
                    alert("File eliminato con successo!");
                    if (currentFile === fileName) {
                        editor.setValue("");
                        currentFile = "";
                        document.getElementById("currentFileName").textContent = "Nessun file selezionato";
                    }
                } else {
                    alert(`Errore nell'eliminazione del file: ${data}`);
                }
            })
            .catch((error) => {
                console.error("Errore:", error);
                alert("Errore nell'eliminazione del file");
            });
    },

    updateLayout: function () {
        const windowHeight = window.innerHeight;
        const windowWidth = window.innerWidth;
        const switchHeight = outerHeight(this.elements.switchContainer);
        const barContentHeight = outerHeight(document.querySelector(".bar-content"));

        if (this.elements.sidebar) {
            this.elements.sidebar.style.height = `${windowHeight - switchHeight - barContentHeight}px`;
        }
        if (this.elements.iframe) {
            this.elements.iframe.style.height = `${windowHeight - barContentHeight}px`;
        }

        if (this.elements.sidebar) {
            const sidebarWidth = this.elements.sidebar.getBoundingClientRect().width;
            if (this.elements.frame) this.elements.frame.style.marginLeft = `${sidebarWidth}px`;
            const newWidth = `${windowWidth - sidebarWidth}px`;
            if (this.elements.iframe) this.elements.iframe.style.width = newWidth;
            if (this.elements.frame) this.elements.frame.style.width = newWidth;
        }
    },

    postMessageToFrame: function (message) {
        if (this.elements.iframe && this.elements.iframe.contentWindow) {
            this.elements.iframe.contentWindow.postMessage(message, window.location.origin);
        }
    },

    updateSelectsFromState: function () {
        const setVal = (sel, val) => {
            const el = document.querySelector(sel);
            if (el) el.value = val;
        };
        setVal("select#sel-iis", AppState.selectedIIS);
        setVal("select#sel-cls", AppState.selectedCLS);
        setVal("select#sel-mater", AppState.selectedMATER);
        // Perf 2026-05-24 — RIMOSSO trigger("change") programmatico:
        // duplicava setupSidebarButtons (già chiamato da App.init subito
        // dopo updateSelectsFromState) → ~22 fetch /api/study/content.json
        // inutili al boot. risdoc-section-header sincronizza via
        // queueMicrotask in connectedCallback, non serve change qui.
    },

    renderSidebarContent: function (sidebarId, links) {
        const ulElement = document.querySelector(`${sidebarId} ul`);
        if (!ulElement) return;
        ulElement.replaceChildren();

        const filteredLinks = links.filter((link) => {
            if (App.isEditMode) return true;
            return link.display !== "hide";
        });

        filteredLinks.forEach((link) => {
            const isRegistered = !link.notRegistered;
            const uniqueID = isRegistered ? link.id : Utils.generateUUID();
            const argomento = link.argomento.replace(/_/g, " ");

            const li = document.createElement("li");
            li.id = uniqueID;
            const a = document.createElement("a");
            a.classList.add("linkref");
            a.href = link.href;

            if (link.display) a.setAttribute("data-display", link.display);
            if (link["href-hide"]) a.setAttribute("data-href-hide", link["href-hide"]);

            const spanNumArg = document.createElement("span");
            spanNumArg.classList.add("numArg");
            spanNumArg.textContent = link.NumArg;

            const spanArgomento = document.createElement("span");
            spanArgomento.classList.add("argomento");
            spanArgomento.textContent = argomento;

            if (!isRegistered) {
                spanNumArg.style.color = "red";
                spanArgomento.style.color = "red";
                a.append(spanNumArg, " - ", spanArgomento);
                const notReg = document.createElement("span");
                notReg.classList.add("bannerNotReg");
                notReg.style.color = "red";
                notReg.textContent = "(non reg.)";
                a.append(" ", notReg);
            } else {
                a.append(spanNumArg, " - ", spanArgomento);
            }
            li.appendChild(a);
            ulElement.appendChild(li);
        });
    },

    /**
     * Definizione UNIFICATA toggleSidebarSection — nel file legacy esisteva
     * un override duplicato (object literal: secondo definitore vinceva).
     * Versione preservata = quella in fondo al file con dedup 5s + edit-mode guard.
     */
    toggleSidebarSection: function (btn, sectionId, bordStore, page) {
        const btnEl = asElement(btn);
        if (!btnEl) return;
        const bordType = sessionStorage.getItem(bordStore) || getComputedStyle(btnEl).borderStyle;

        const sectionEl = document.querySelector(sectionId);

        if (bordType === "outset") {
            btnEl.style.borderStyle = "inset";
            sessionStorage.setItem(bordStore, "inset");

            // Phase 15 — dedup rapido: se popolata di recente, solo mostra.
            // NB: .fm-sb-panel ha `display: none` di default in CSS → serve
            // forzare `display: block` (replica jQuery .show() che imposta
            // valore non vuoto, non solo rimozione di inline display).
            if (sectionEl) {
                const lastLoad = parseInt(sectionEl.getAttribute("data-fm-last-load") || "0", 10);
                const alreadyPopulated = sectionEl.querySelector("a.linkref, .fm-db-block") !== null;
                const fresh = Date.now() - lastLoad < 5000;
                if (alreadyPopulated && fresh) {
                    sectionEl.style.display = "block";
                    if (App.isEditMode && sectionId === "#fm-sp-mappe") {
                        document.querySelectorAll(".control-panel-class").forEach((el) => { el.style.display = "block"; });
                    }
                    return;
                }

                if (App.isEditMode && sectionEl.querySelector(".input-wrapper-linkref")) {
                    sectionEl.style.display = "block";
                } else {
                    sectionEl.setAttribute("data-fm-last-load", String(Date.now()));
                    App.loadSidebarContent(sectionId, page);
                }
            }

            if (App.isEditMode && sectionId === "#fm-sp-mappe") {
                document.querySelectorAll(".control-panel-class").forEach((el) => { el.style.display = "block"; });
            }
        } else {
            btnEl.style.borderStyle = "outset";
            sessionStorage.setItem(bordStore, "outset");
            if (sectionEl) sectionEl.style.display = "none";

            if (App.isEditMode && sectionId === "#fm-sp-mappe") {
                document.querySelectorAll(".control-panel-class").forEach((el) => { el.style.display = "none"; });
            }
        }
    },

    collectAllInputData: function () {
        const data = [];
        document.querySelectorAll(".input-wrapper-linkref").forEach((wrapper) => {
            if (!isVisible(wrapper)) return;
            data.push({
                element: wrapper,
                id: wrapper.parentElement?.id || Utils.generateUUID(),
                numArg: (wrapper.querySelector(".input-numArg")?.value || "").trim(),
                argomento: (wrapper.querySelector(".input-argomento")?.value || "").trim(),
                href: (wrapper.querySelector(".input-href")?.value || "").trim(),
                hrefHide: (wrapper.querySelector(".input-href-hide")?.value || "").trim(),
                shouldSaveFile: wrapper.querySelector(".saveFile")?.checked === true,
                shouldProtectFile: wrapper.querySelector(".accessFile")?.checked === true,
                display: wrapper.querySelector(".display-toggle")?.getAttribute("data-display") || "show",
            });
        });
        return data;
    },

    showValidationError: function (_element, _message) {
        /* placeholder legacy */
    },

    switchToLinkView: function (inputWrapper, data) {
        const wrap = asElement(inputWrapper);
        if (!wrap) return;
        const a = document.createElement("a");
        a.classList.add("linkref");
        a.href = data.href;

        const spanNumArg = document.createElement("span");
        spanNumArg.classList.add("numArg");
        spanNumArg.textContent = data.numArg;

        const spanArgomento = document.createElement("span");
        spanArgomento.classList.add("argomento");
        spanArgomento.textContent = data.argomento;

        a.append(spanNumArg, " - ", spanArgomento);
        wrap.replaceWith(a);
    },

    loadUrlInFrame: function (url) {
        if (!url) return;

        if (url.toLowerCase().includes("diagrams.net") && !CookieConsentManager.isFunctionalAllowed()) {
            const warn = document.getElementById("iframe-specific-warning");
            if (warn) {
                warn.innerHTML = '<div class="icon danger"></div>IL CARICAMENTO DI DIAGRAMS.NET È BLOCCATO. È RICHIESTO IL CONSENSO AI COOKIE FUNZIONALI.';
                warn.style.display = "";
            }
            return;
        }
        const warn = document.getElementById("iframe-specific-warning");
        if (warn) warn.style.display = "none";

        let isExternal = false;
        try {
            const u = new URL(url, window.location.href);
            isExternal = u.origin !== window.location.origin;
        } catch (_) { /* url relativa */ }

        if (isExternal) {
            this._loadExternalInContent(url);
        } else if (window.fmRouter && typeof window.fmRouter.navigate === "function") {
            window.fmRouter.navigate(url);
        } else {
            window.location.href = url;
        }

        const onNavigated = (e) => {
            if (e && e.detail && e.detail.url && !e.detail.url.includes(url.split("?")[0])) return;
            this._injectCssForRisDoc(url);
            window.removeEventListener("fm:navigated", onNavigated);
        };
        window.addEventListener("fm:navigated", onNavigated);
    },

    _loadExternalInContent: function (url) {
        const target = document.getElementById("fm-content");
        if (!target) { window.location.href = url; return; }
        const existing = target.querySelector("iframe.fm-external-iframe");
        if (existing && existing.getAttribute("src") === url) return;

        const safe = url.replace(/"/g, "&quot;");
        target.innerHTML = `<iframe class="fm-external-iframe" src="${safe}" loading="lazy" referrerpolicy="no-referrer"></iframe>`;
        window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url, external: true } }));
    },

    _injectCssForRisDoc: function (url) {
        try {
            if (!url.includes("/risdoc/")) return;

            const config = Config.SIDEBAR_CONFIG["#fm-sp-risdoc"];
            if (!config || !config.pathPattern) {
                console.warn("⚠️ Configurazione #fm-sp-risdoc non trovata");
                return;
            }

            const urlParts = url.split("/");
            let category = "";
            let argomento = "";

            const categoryIndex = urlParts.findIndex((part) => config.categories.includes(part.toUpperCase()));
            if (categoryIndex !== -1) category = urlParts[categoryIndex].toUpperCase();

            const fileName = urlParts[urlParts.length - 1];
            if (fileName) {
                const match = fileName.match(/\d+_DOC-(.+?)-[A-Z]+\.php$/);
                if (match) argomento = match[1];
            }

            if (!category || !argomento) {
                console.warn("⚠️ Impossibile determinare category o argomento da URL:", url);
                return;
            }

            const paths = config.pathPattern(config.dirName, "", category, argomento);
            const cssUrl = paths.file_css;
            if (!cssUrl) {
                console.log("📋 Nessun CSS configurato per questo documento");
                return;
            }

            const iframeDoc = this.elements.iframe?.contentDocument;
            if (!iframeDoc) {
                console.error("❌ Impossibile accedere al documento dell'iframe");
                return;
            }

            if (iframeDoc.querySelector(`link[href="${cssUrl}"]`)) {
                console.log("✅ CSS già presente nell'iframe:", cssUrl);
                return;
            }

            const linkElement = iframeDoc.createElement("link");
            linkElement.rel = "stylesheet";
            linkElement.type = "text/css";
            linkElement.href = `${window.location.origin}${cssUrl}`;
            linkElement.onload = () => console.log("✅ CSS iniettato con successo nell'iframe:", linkElement.href);
            linkElement.onerror = () => console.error("❌ Errore nel caricamento del CSS:", linkElement.href);
            iframeDoc.head.appendChild(linkElement);

            console.log("🎨 CSS iniettato nell'iframe per #fm-sp-risdoc:", linkElement.href);
        } catch (error) {
            console.error("❌ Errore durante l'iniezione del CSS:", error);
        }
    },

    appendContentToFrame: async function (url) {
        if (!url) return;
        if (this.pendingFrameUrls.has(url)) {
            console.log(`⏳ Append già in corso per: ${url}`);
            return;
        }

        this.pendingFrameUrls.add(url);
        try {
            const response = await Api.fetchHtmlTemplate(url);
            const parser = new DOMParser();
            const doc = parser.parseFromString(response, "text/html");
            const elements = doc.querySelectorAll('.fm-pagestyle, [id^="type_verAll"]');

            const iframeEl = this.elements.iframe;
            const iframeDocument = iframeEl?.contentDocument;
            if (!iframeDocument) {
                const main = document.getElementById("fm-content");
                if (!main) return;
                const linkIndex = AppState.visitedLinks.indexOf(url);
                const suffixIndex = linkIndex >= 0 ? linkIndex : AppState.visitedLinks.length;
                const suffix = `_add${suffixIndex}`;
                elements.forEach((element) => {
                    element.setAttribute("data-source-url", url);
                    if (element.id) element.id += suffix;
                    element.querySelectorAll("[id]").forEach((child) => { child.id += suffix; });
                    main.appendChild(document.importNode(element, true));
                });
                if (window.MathJax?.typesetPromise) {
                    try { await window.MathJax.typesetPromise([main]); } catch (_) { /* noop */ }
                }
                try { window.FM?.populatePositionInputs?.(); } catch (_) { /* noop */ }
                try { window.FM?.populateOriginSelects?.(); } catch (_) { /* noop */ }
                window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url, multiarg: true } }));
                return;
            }

            iframeDocument.querySelectorAll(".fm-draggable-container").forEach((container) => {
                container.innerHTML = "";
            });

            const linkIndex = AppState.visitedLinks.indexOf(url);
            const suffixIndex = linkIndex >= 0 ? linkIndex : AppState.visitedLinks.length;

            elements.forEach((element) => {
                element.setAttribute("data-source-url", url);
                if (element.classList && element.classList.contains("fm-draggable-container")) {
                    element.innerHTML = "";
                }
                element.querySelectorAll(".fm-draggable-container").forEach((container) => {
                    container.innerHTML = "";
                    container.setAttribute("data-source-url", url);
                });

                const suffix = `_add${suffixIndex}`;
                if (element.id) element.id += suffix;
                element.querySelectorAll("[id]").forEach((child) => { child.id += suffix; });
                iframeDocument.body.appendChild(iframeDocument.importNode(element, true));
            });

            document.getElementById("myframe").contentWindow.postMessage({ type: "link", data: url }, "*");
            document.getElementById("myframe").contentWindow.postMessage({ type: "sidebarCheck", data: AppState.sidebarCheck }, "*");

            this._injectCssForRisDoc(url);
        } catch (error) {
            console.error("Errore nell'appendere contenuto all'iframe:", error);
        } finally {
            this.pendingFrameUrls.delete(url);
        }
    },

    isUrlAlreadyInFrame: function (url) {
        if (!url) return false;
        const root = this.elements.iframe?.contentDocument
                  || document.getElementById("fm-content");
        if (!root) return false;
        const sourceNodes = root.querySelectorAll("[data-source-url]");
        for (const node of sourceNodes) {
            if (node.getAttribute("data-source-url") === url) return true;
        }
        return false;
    },

    isUrlPending: function (url) {
        return this.pendingFrameUrls.has(url);
    },
};

window.FM = window.FM || {};
window.FM.DOMManager = DOMManager;
window.DOMManager    = DOMManager;
