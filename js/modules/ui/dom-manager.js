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

    init () {
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
        this._bindGlobalEvents();
        this._bindResizeHandler();
        this.updateLayout();
        this._initializeIOBar();
        this._restoreIOBarState();
    },

    _bindResizeHandler () {
        onNs(window, "resize", "app", () => this.updateLayout());
    },

    // 21/9/2026 — qui c'era `injectHiddenForm()`, che metteva in fondo a OGNI
    // pagina (login compreso) un modulo nascosto verso overleaf.com con dentro
    // una textarea per il sorgente TeX. Non lo riempiva e non lo spediva
    // nessuno: era rimasto da un percorso dismesso, e si portava dietro tre
    // errori di accessibilità tappati a mano (WCAG 1.3.1 F68 e 4.1.2 H91, che
    // il display:none non toglie). Tolto il modulo, il debito sparisce alla
    // radice invece di restare mascherato.

    _bindGlobalEvents () {
        const body = this.elements.body;
        if (!body) return;
        const self = this;

        // Delegation namespaced "click.app" via single handler che dispatcha sui selector
        onNs(body, "click", "app", (e) => {
            const t = e.target;
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

    },

    _initializeIOBar () {
        if (this.elements.slider) this.elements.slider.textContent = "✖";
    },

    _restoreIOBarState () {
        const closed = sessionStorage.getItem("ioBarState") === "closed";
        if (this.elements.ioBar) this.elements.ioBar.checked = !closed;
        AppState.sidebarCheck = closed ? 0 : 1;
        document.body.classList.toggle("fm-sidebar-closed", closed);
        if (this.elements.slider) this.elements.slider.textContent = closed ? "☰" : "✖";
    },

    _reattachEventListeners () {
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

    },

    toggleSidebar () {
        const open = this.elements.ioBar?.checked === true;
        AppState.sidebarCheck = open ? 1 : 0;
        sessionStorage.setItem("ioBarState", open ? "open" : "closed");
        document.body.classList.toggle("fm-sidebar-closed", !open);
        if (this.elements.slider) this.elements.slider.textContent = open ? "✖" : "☰";

        if (this.elements.iframe) {
            this.postMessageToFrame({ type: "sidebarCheck", data: AppState.sidebarCheck });
        }
    },

    async handleDriveButtonClick (button) {
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

    _findClosestCategoryId (button) {
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

    _buildDrawioLinksPath (category) {
        const folder = AppState.folder || "ART";
        const optsel = AppState.optsel || "ART3S";
        return `/mappe/${folder}/mappe_${optsel}/${category}/drawio-links-${category}.json`;
    },

    async _loadDrawioLinksData (filePath) {
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

    _openGoogleDriveFolder (driveData) {
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

    async createFile () {
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

    async deleteFile () {
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

    updateLayout () {
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

    postMessageToFrame (message) {
        if (this.elements.iframe && this.elements.iframe.contentWindow) {
            this.elements.iframe.contentWindow.postMessage(message, window.location.origin);
        }
    },

    updateSelectsFromState () {
        // Niente da ricordare = niente da scrivere: i selettori hanno un
        // segnaposto con valore vuoto, e scrivere "" li rimetterebbe li',
        // cancellando la terna che fm-url-state.js ha appena letto dall'URL.
        const setVal = (sel, val) => {
            const el = document.querySelector(sel);
            if (el && val) el.value = val;
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

    renderSidebarContent (sidebarId, links) {
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
    toggleSidebarSection (btn, sectionId, bordStore, page) {
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

    collectAllInputData () {
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

    showValidationError (_element, _message) {
        /* placeholder legacy */
    },

    switchToLinkView (inputWrapper, data) {
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

    loadUrlInFrame (url) {
        if (!url) return;

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

    _loadExternalInContent (url) {
        const target = document.getElementById("fm-content");
        if (!target) { window.location.href = url; return; }
        const existing = target.querySelector("iframe.fm-external-iframe");
        if (existing && existing.getAttribute("src") === url) return;

        const safe = url.replace(/"/g, "&quot;");
        target.innerHTML = `<iframe class="fm-external-iframe" src="${safe}" loading="lazy" referrerpolicy="no-referrer"></iframe>`;
        window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url, external: true } }));
    },

    _injectCssForRisDoc (url) {
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

    async appendContentToFrame (url) {
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

            document.getElementById("myframe").contentWindow.postMessage({ type: "link", data: url }, window.location.origin);
            document.getElementById("myframe").contentWindow.postMessage({ type: "sidebarCheck", data: AppState.sidebarCheck }, window.location.origin);

            this._injectCssForRisDoc(url);
        } catch (error) {
            console.error("Errore nell'appendere contenuto all'iframe:", error);
        } finally {
            this.pendingFrameUrls.delete(url);
        }
    },

    isUrlAlreadyInFrame (url) {
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

    isUrlPending (url) {
        return this.pendingFrameUrls.has(url);
    },
};

window.FM = window.FM || {};
window.FM.DOMManager = DOMManager;
window.DOMManager    = DOMManager;
