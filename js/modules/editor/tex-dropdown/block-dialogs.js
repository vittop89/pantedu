/**
 * G24.faseC-block-dialogs — Block-level dialogs per editare i blocchi
 * TikZ/GeoGebra/Template embedded nel textarea quesito.
 *
 * Trigger: pulsanti dinamici "🔍 Modifica TikZ", "📐 Modifica GeoGebra",
 * "📋 Modifica schema" che vengono renderizzati dal `_renderTikzButtons`
 * sopra ogni textarea con `_tikzBlocks` / `_geogebraBlocks` popolati.
 *
 * Lazy-load: il bundle del dialog (CM6/GeoGebra/filler) viene caricato
 * via dynamic import al primo click. Vedi `loadFmDialogEntry` per il
 * pattern manifest-based.
 *
 * Dipendenze (DI factory):
 *   - toast       — UI feedback messaggi
 *   - updatePreview — re-render preview post-save (force bypass debounce)
 */

/** Lazy-load di un FM dialog tramite manifest entry. Aspetta che
 *  `window.FM[fmKey]` sia popolato dal bundle. */
async function loadFmDialogEntry(entryKey, fmKey) {
    if (window.FM?.[fmKey]) return;
    const cacheBust = `?t=${Date.now()}`;
    const res = await fetch(`/build/manifest.json${cacheBust}`, {
        credentials: "same-origin", cache: "no-store",
    });
    if (!res.ok) throw new Error(`manifest HTTP ${res.status} — npm run build`);
    const manifest = await res.json();
    const entry = manifest[entryKey];
    if (!entry) throw new Error(`entry ${entryKey} assente`);
    await import(/* @vite-ignore */ `/build/${entry.file}`);
    if (!window.FM?.[fmKey]) throw new Error(`bundle non popola FM.${fmKey}`);
}

/** Factory: ritorna le 3 dialog functions bound alle deps.
 *  @param {{ toast: Function, updatePreview: Function }} deps */
export function createBlockDialogs(deps) {
    const { toast, updatePreview } = deps;

    /** Apre filler dialog per un template TikZ parametrico.
     *  Se editBlockIdx fornito, edita blocco esistente; altrimenti append. */
    async function openTemplateFiller(ta, templateId, editBlockIdx) {
        try {
            await loadFmDialogEntry("js/entries/tikz-template-filler.js", "openTemplateFiller");
            // Riestrai initial data dal data-template-data se in edit mode
            let initialData = null;
            if (editBlockIdx !== null && editBlockIdx !== undefined) {
                const blk = ta._tikzBlocks?.[editBlockIdx];
                const m = blk?.tagOpen?.match(/data-template-data=(["'])([\s\S]*?)\1/i);
                if (m) {
                    try { initialData = JSON.parse(decodeURIComponent(m[2])); }
                    catch (_) { initialData = null; }
                }
            }
            window.FM.openTemplateFiller(templateId, initialData, (tikzString, data) => {
                // Costruisce nuovo blocco con data-template-* attributes
                const dataAttr = encodeURIComponent(JSON.stringify(data));
                const tagOpen = `<script type="text/tikz" data-template-id="${templateId}" data-template-data="${dataAttr}">`;
                const body = `\n${  tikzString  }\n`;
                const tagClose = `</` + `script>`;
                const newBlock = { tagOpen, body, tagClose };
                ta._tikzBlocks = ta._tikzBlocks || [];
                if (editBlockIdx !== null && editBlockIdx !== undefined) {
                    ta._tikzBlocks[editBlockIdx] = newBlock;
                } else {
                    // Append: marker in fondo al textarea
                    ta._tikzBlocks.push(newBlock);
                    const newMarker = `⟨🔍 TikZ #${ta._tikzBlocks.length}⟩`;
                    ta.value = (ta.value.trim() ? `${ta.value  }\n` : "") + newMarker;
                }
                // Trigger preview + ridisegna bottoni.
                // Il listener `input` su ta cerca <script> testuale per re-collapsing,
                // ma noi mettiamo solo il marker → chiamiamo direttamente il re-render.
                ta._lastRenderedValue = undefined;
                if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
                ta.dispatchEvent(new Event("input", { bubbles: true }));
            });
        } catch (e) {
            console.error("[template-filler] load failed:", e);
            toast?.(`Template Filler: ${e.message}`, "err");
        }
    }

    /** Apre la modal CM6 per editare il blocco TikZ N-esimo del textarea.
     *  Lazy-load CM6+stex (~28KB gz) al primo click.
     *  Su save, aggiorna `ta._tikzBlocks[idx].body` e dispatcha input. */
    async function openTikzModalForBlock(ta, blockIdx) {
        try {
            await loadFmDialogEntry("js/entries/tikz-editor-modal.js", "openTikzModal");
            const block = ta._tikzBlocks[blockIdx];
            if (!block) throw new Error("block index out of range");

            // Proxy: textarea-like minimal interface compatibile con openTikzModal.
            const proxy = document.createElement("textarea");
            proxy.value = `${block.tagOpen  }\n${  block.body.replace(/^\n/, "")  }\n${  block.tagClose}`;
            // Hack: usiamo addEventListener per intercettare il dispatch input
            // post-save. La modal scrive via `proxy.value = wrap(newBody)`.
            proxy.addEventListener("input", () => {
                // Estrai il nuovo body dal proxy.value e scrivi nel block originale.
                const m = proxy.value.match(/(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/i);
                const oldBody = ta._tikzBlocks[blockIdx]?.body || "";
                let newBody;
                if (m) {
                    newBody = m[2];
                    ta._tikzBlocks[blockIdx] = { tagOpen: m[1], body: m[2], tagClose: m[3] };
                } else {
                    // Senza wrapper (utente ha cancellato): teniamo il proxy.value come body raw.
                    newBody = proxy.value;
                    ta._tikzBlocks[blockIdx] = { tagOpen: block.tagOpen, body: proxy.value, tagClose: block.tagClose };
                }
                console.debug("[fm-tikz-save]", {
                    blockIdx,
                    oldBodyLen: oldBody.length,
                    newBodyLen: newBody.length,
                    changed: oldBody !== newBody,
                });
                // ESTREMO: invalida fingerprint + force-render bypass debounce.
                ta._lastRenderedValue = undefined;
                // Chiama direttamente updatePreview (bypass debounce 300ms) cercando il pv.
                const pv = ta.closest(".fm-editor-row")?.querySelector(".fm-editor-preview");
                if (pv && typeof updatePreview === "function") {
                    queueMicrotask(() => {
                        console.debug("[fm-tikz-save] forcing updatePreview");
                        updatePreview(ta, pv);
                    });
                } else {
                    ta.dispatchEvent(new Event("input", { bubbles: true }));
                }
            });
            window.FM.openTikzModal(proxy);
        } catch (e) {
            console.error("[edit-tikz-modal] load failed:", e);
            toast?.(`Editor TikZ: ${e.message}`, "err");
        }
    }

    /** Apre GeoGebra editor per editare un blocco grafico esistente.
     *  Su save, sostituisce ggb_b64/svg/label/width nell'array
     *  `ta._geogebraBlocks[idx]`. */
    async function openGeoGebraEditorForBlock(ta, idx) {
        const blk = ta._geogebraBlocks?.[idx];
        if (!blk) return;
        try {
            await loadFmDialogEntry("js/entries/geogebra-editor.js", "openGeoGebraEditor");
        } catch (e) {
            toast(`Errore caricamento GeoGebra: ${e.message}`, "err");
            return;
        }
        window.FM.openGeoGebraEditor({
            initialGgbBase64: blk.ggb_b64 || null,
            initialLabel: blk.label || "",
            onAdd: ({ ggb_b64, svg, label, width }) => {
                ta._geogebraBlocks[idx] = {
                    ggb_b64, svg,
                    label: label || blk.label || "",
                    width: width || blk.width || "",
                };
                ta._lastRenderedValue = undefined;
                if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
                ta.dispatchEvent(new Event("input", { bubbles: true }));
                toast("Grafico GeoGebra aggiornato", "ok");
                return true;
            },
            onCancel: () => {},
        });
    }

    return { openTemplateFiller, openTikzModalForBlock, openGeoGebraEditorForBlock };
}
