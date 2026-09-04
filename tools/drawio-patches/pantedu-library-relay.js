/**
 * G22.S15.bis Fase 5 — Plugin Pantedu per drawio webapp self-hosted.
 *
 * Registrato via window.Draw.loadPlugin(fn) — API ufficiale drawio per
 * plugin con accesso a editorUi gia' inizializzato.
 *
 * Due responsabilita':
 *   1. Pre-load librerie shape del docente come open-file (LocalLibrary)
 *      tramite editorUi.libraryLoaded(). Drawio le mostra nel sidebar
 *      come voci editabili (matita per drag/+/x/save).
 *   2. Monkey-patcha App.saveLibrary: dopo save, fa
 *      parent.postMessage(event=fmLibraryUpdate, {name, xml}) e SKIP
 *      l'originale (no download, no provider esterni cloud).
 *
 * Il parent (drawio-editor.js) ascolta l'evento e POSTa al server
 * /api/teacher/drawio/libraries/save-content per persistere il file.
 */
(function () {
    "use strict";

    if (window.parent === window) return; // standalone, no-op

    function setup() {
        if (typeof window.Draw === "undefined" || typeof window.Draw.loadPlugin !== "function") {
            // Draw API non ancora pronta, ritenta.
            setTimeout(setup, 100);
            return;
        }
        window.Draw.loadPlugin(function (ui) {
            console.log("[fm-relay] plugin invoked, ui:", !!ui);
            try {
                patchSaveLibrary();
            } catch (e) {
                console.warn("[fm-relay] patch err:", e);
            }
            try {
                loadPanteduLibraries(ui);
            } catch (e) {
                console.warn("[fm-relay] load err:", e);
            }
        });
        console.log("[fm-relay] registered with Draw.loadPlugin");
    }

    function patchSaveLibrary() {
        if (typeof App === "undefined" || !App.prototype || App.prototype.__fmPatched) return;
        App.prototype.__fmPatched = true;
        var orig = App.prototype.saveLibrary;
        App.prototype.saveLibrary = function (name, images, file, mode, noSpin, noReload, fn) {
            try {
                if (typeof this.createLibraryDataFromImages === "function") {
                    var xml = this.createLibraryDataFromImages(images);
                    if (xml && name) {
                        window.parent.postMessage(JSON.stringify({
                            event: "fmLibraryUpdate",
                            name: String(name),
                            xml: String(xml),
                        }), "*");
                        console.log("[fm-relay] saveLibrary -> parent", name, xml.length);
                        // Aggiorna sidebar in-place (no reload).
                        if (file && typeof this.libraryLoaded === "function") {
                            this.libraryLoaded(file, images);
                        }
                        if (typeof fn === "function") fn();
                        return; // SKIP downstream provider chain
                    }
                }
            } catch (e) {
                console.warn("[fm-relay] saveLibrary error:", e);
            }
            return orig.apply(this, arguments);
        };
        console.log("[fm-relay] App.saveLibrary patched");
    }

    /**
     * Pulisce eventuali RemoteLibraries stale che drawio ricostruisce da
     * mxSettings.customLibraries (URL salvate in localStorage da Import
     * precedenti). Le sostituiamo con LocalLibrary editabili nostre.
     */
    function clearStaleCustomLibraries() {
        try {
            if (typeof mxSettings !== "undefined" && mxSettings.settings) {
                mxSettings.settings.customLibraries = [];
                if (typeof mxSettings.save === "function") mxSettings.save();
                console.log("[fm-relay] cleared stale customLibraries from mxSettings");
            }
        } catch (e) {
            console.warn("[fm-relay] clearStale err:", e);
        }
    }

    function loadPanteduLibraries(ui) {
        clearStaleCustomLibraries();
        fetch("/api/teacher/drawio/libraries", { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok || !Array.isArray(j.libraries)) return;
                console.log("[fm-relay] teacher libs:", j.libraries.length);

                // Chiudo eventuali libraries gia' aperte da restoreLibraries
                // che corrispondono ai nomi delle nostre (evita duplicazione
                // sidebar: una read-only via Remote + una editable nostra).
                if (Array.isArray(ui.openLibraries)) {
                    var ourNames = new Set(j.libraries.map(function (l) { return l.name; }));
                    var toClose = ui.openLibraries.filter(function (entry) {
                        return entry && entry.file && ourNames.has(entry.file.getTitle());
                    });
                    toClose.forEach(function (entry) {
                        try {
                            if (typeof ui.closeLibrary === "function") {
                                ui.closeLibrary(entry.file);
                            }
                        } catch (e) { /* skip */ }
                    });
                }

                j.libraries.forEach(function (lib) {
                    if (!lib || !lib.name || !/^[a-zA-Z0-9._-]+\.xml$/.test(lib.name)) return;
                    fetch("/api/teacher/drawio/libraries/read/" + encodeURIComponent(lib.name),
                        { credentials: "same-origin" })
                        .then(function (r2) {
                            if (!r2.ok) {
                                console.warn("[fm-relay] read", lib.name, "HTTP", r2.status);
                                return null;
                            }
                            return r2.text();
                        })
                        .then(function (xml) {
                            if (!xml) return;
                            // Parse XML mxlibrary via drawio mxUtils (decode entities &lt;).
                            var doc, images;
                            try {
                                doc = mxUtils.parseXml(xml);
                            } catch (e) {
                                console.warn("[fm-relay] parseXml fail", lib.name, e.message);
                                return;
                            }
                            if (!doc.documentElement || doc.documentElement.nodeName !== "mxlibrary") {
                                console.warn("[fm-relay] not mxlibrary root:", lib.name);
                                return;
                            }
                            try {
                                images = JSON.parse(mxUtils.getTextContent(doc.documentElement));
                            } catch (e) {
                                console.warn("[fm-relay] JSON parse fail", lib.name, e.message);
                                return;
                            }
                            if (!Array.isArray(images)) return;

                            if (typeof LocalLibrary === "undefined") {
                                console.warn("[fm-relay] LocalLibrary not in scope");
                                return;
                            }
                            var f = new LocalLibrary(ui, xml, lib.name);
                            // libraryLoaded(file, images, title?, expand?, tags?)
                            // title=null → drawio usa file.getTitle() e strippa .xml
                            ui.libraryLoaded(f, images, null, true);
                            console.log("[fm-relay] loaded", lib.name, "(" + images.length + " shapes)");
                        })
                        .catch(function (e) {
                            console.warn("[fm-relay] error", lib.name, e);
                        });
                });
            })
            .catch(function (e) {
                console.warn("[fm-relay] fetch list:", e);
            });
    }

    setup();
})();
