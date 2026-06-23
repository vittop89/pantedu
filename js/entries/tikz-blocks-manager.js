/**
 * G22.S15 / D — Manager modal "Blocchi TikZ" con sidebar.
 *
 * Apre un modal full-screen con:
 *   - Sidebar a sinistra: elenco di TUTTI i blocchi TikZ del textarea
 *     corrente (numerati, con preview SVG miniatura quando disponibile,
 *     icona 📋 per template / 🔍 per codice grezzo).
 *   - Pannello centrale: editor del blocco selezionato.
 *     Se il blocco e' un template (data-template-id) → form (template-filler).
 *     Altrimenti → CM6 codice grezzo (tikz-editor-modal).
 *   - Toolbar: aggiungi blocco (+TikZ codice / +TikZ modulare), elimina,
 *     riordina (↑ ↓), salva-tutto, chiudi.
 *
 * Si carica lazy via window.FM.openTikzBlocksManager(textarea).
 */
import { renderAll as tikzRenderAll, normalizeTikz, sha256Hex } from "../modules/editor/tikz-render-client.js";

let _modal = null;
let _styled = false;

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

const _TIKZ_RE = /(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/gi;
const _MARKER_RE = /⟨🔍 TikZ #(\d+)⟩/g;

function _detectKind(block) {
    return /data-template-id=/i.test(block.tagOpen) ? "template" : "code";
}
function _templateId(block) {
    const m = block.tagOpen.match(/data-template-id=(["'])([^"']+)\1/i);
    return m ? m[2] : null;
}

/** Apre il modal. `ta` e' il textarea con `_tikzBlocks` array.
 *  Modifiche live: ogni edit di un blocco aggiorna `ta._tikzBlocks[idx]`
 *  e dispatcha `input` sul textarea cosi' la preview esterna si rinfresca. */
export function openTikzBlocksManager(ta) {
    if (_modal) return;
    injectStyles();
    if (!ta._tikzBlocks) ta._tikzBlocks = [];

    let activeIdx = ta._tikzBlocks.length > 0 ? 0 : -1;

    const backdrop = document.createElement("div");
    backdrop.className = "fm-tbm-backdrop";
    backdrop.innerHTML = `
        <div class="fm-tbm">
            <div class="fm-tbm-header">
                <h3>⚙️ Gestione blocchi TikZ</h3>
                <div class="fm-tbm-toolbar">
                    <button data-act="add-code" class="add">+ Codice</button>
                    <button data-act="add-template" class="add">+ Schema</button>
                    <button data-act="close" class="primary">Chiudi</button>
                </div>
            </div>
            <div class="fm-tbm-body">
                <div class="fm-tbm-sidebar"></div>
                <div class="fm-tbm-content">
                    <div class="fm-tbm-empty">
                        <p style="font-size:48px;margin:0">📐</p>
                        <p>Nessun blocco selezionato</p>
                        <p style="font-size:11px">Aggiungi un nuovo blocco o seleziona uno dalla sidebar.</p>
                    </div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(backdrop);
    const sidebar = backdrop.querySelector(".fm-tbm-sidebar");
    const content = backdrop.querySelector(".fm-tbm-content");

    function close() { if (!_modal) return; _modal = null; backdrop.remove(); }

    function refreshSidebar() {
        sidebar.innerHTML = "";
        if (!ta._tikzBlocks.length) {
            const empty = document.createElement("div");
            empty.style.cssText = "color:#888;font-size:11px;padding:12px;text-align:center;font-style:italic";
            empty.textContent = "Nessun blocco. Usa + Codice o + Schema in alto.";
            sidebar.appendChild(empty);
            return;
        }
        ta._tikzBlocks.forEach((blk, i) => {
            const kind = _detectKind(blk);
            const tplId = _templateId(blk);
            const card = document.createElement("div");
            card.className = "fm-tbm-block" + (i === activeIdx ? " active" : "");
            card.addEventListener("click", () => { activeIdx = i; refreshSidebar(); refreshContent(); });

            const header = document.createElement("div");
            header.className = "fm-tbm-block-header";
            const icon = document.createElement("span");
            icon.className = "fm-tbm-block-icon";
            icon.textContent = kind === "template" ? "📋" : "🔍";
            const title = document.createElement("span");
            title.className = "fm-tbm-block-title";
            title.textContent = `Block #${i + 1}` + (tplId ? ` (${tplId})` : "");
            const actions = document.createElement("span");
            actions.className = "fm-tbm-block-actions";
            actions.appendChild(_smallBtn("↑", "Sposta su", (e) => { e.stopPropagation(); _moveBlock(ta, i, -1); if (activeIdx === i) activeIdx--; else if (activeIdx === i - 1) activeIdx++; refreshSidebar(); refreshContent(); }));
            actions.appendChild(_smallBtn("↓", "Sposta giù", (e) => { e.stopPropagation(); _moveBlock(ta, i, +1); if (activeIdx === i) activeIdx++; else if (activeIdx === i + 1) activeIdx--; refreshSidebar(); refreshContent(); }));
            const del = _smallBtn("🗑", "Elimina", async (e) => {
                e.stopPropagation();
                if (!await window.FM.Dialog.confirm(`Elimina Block #${i + 1}?`)) return;
                _removeBlock(ta, i);
                if (activeIdx === i) activeIdx = -1;
                else if (activeIdx > i) activeIdx--;
                refreshSidebar(); refreshContent();
            });
            del.classList.add("del");
            actions.appendChild(del);
            header.appendChild(icon); header.appendChild(title); header.appendChild(actions);
            card.appendChild(header);

            // Preview SVG
            const preview = document.createElement("div");
            preview.className = "fm-tbm-block-preview";
            preview.innerHTML = '<span class="ph">…</span>';
            _renderMiniPreview(preview, blk);
            card.appendChild(preview);
            sidebar.appendChild(card);
        });
    }

    async function refreshContent() {
        content.innerHTML = "";
        if (activeIdx < 0 || !ta._tikzBlocks[activeIdx]) {
            content.innerHTML = `<div class="fm-tbm-empty">
                <p style="font-size:48px;margin:0">📐</p>
                <p>Seleziona un blocco dalla sidebar</p>
            </div>`;
            return;
        }
        const blk = ta._tikzBlocks[activeIdx];
        const kind = _detectKind(blk);
        const tplId = _templateId(blk);

        if (kind === "template" && tplId) {
            // Apri template-filler INLINE dentro content (no full modal)
            const placeholder = document.createElement("div");
            placeholder.style.cssText = "padding:20px;color:#aaa;text-align:center";
            placeholder.innerHTML = `
                <p>📋 Schema modulare — block #${activeIdx + 1}</p>
                <p style="margin:16px 0">
                    <button class="fm-tbm-edit-tpl primary" style="padding:8px 16px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font:13px/1 system-ui">Apri editor template</button>
                </p>
                <p style="font-size:11px;color:#888">Si aprira' la modal Template Filler (form + tabelle).</p>`;
            placeholder.querySelector(".fm-tbm-edit-tpl").addEventListener("click", async () => {
                if (!window.FM?.openTemplateFiller) {
                    const manifest = await fetch("/build/manifest.json").then(r => r.json());
                    const entry = manifest["js/entries/tikz-template-filler.js"];
                    await import(/* @vite-ignore */ `/build/${entry.file}`);
                }
                let initialData = null;
                const m = blk.tagOpen.match(/data-template-data=(["'])([\s\S]*?)\1/i);
                if (m) { try { initialData = JSON.parse(decodeURIComponent(m[2])); } catch (_) {} }
                window.FM.openTemplateFiller(tplId, initialData, (tikzString, data) => {
                    const dataAttr = encodeURIComponent(JSON.stringify(data));
                    const tagOpen = `<script type="text/tikz" data-template-id="${tplId}" data-template-data="${dataAttr}">`;
                    ta._tikzBlocks[activeIdx] = { tagOpen, body: "\n" + tikzString + "\n", tagClose: '</' + 'script>' };
                    ta._lastRenderedValue = undefined;
                    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
                    ta.dispatchEvent(new Event("input", { bubbles: true }));
                    refreshSidebar();
                });
            });
            content.appendChild(placeholder);
        } else {
            // Codice grezzo: apri CM6 modal
            const placeholder = document.createElement("div");
            placeholder.style.cssText = "padding:20px;color:#aaa;text-align:center";
            placeholder.innerHTML = `
                <p>🔍 Codice TikZ grezzo — block #${activeIdx + 1}</p>
                <p style="margin:16px 0">
                    <button class="fm-tbm-edit-cm6 primary" style="padding:8px 16px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font:13px/1 system-ui">Apri editor codice (CM6)</button>
                </p>
                <p style="font-size:11px;color:#888">Si aprira' la modal CodeMirror con folding e preview live.</p>`;
            placeholder.querySelector(".fm-tbm-edit-cm6").addEventListener("click", async () => {
                if (!window.FM?.openTikzModal) {
                    const manifest = await fetch("/build/manifest.json").then(r => r.json());
                    const entry = manifest["js/entries/tikz-editor-modal.js"];
                    await import(/* @vite-ignore */ `/build/${entry.file}`);
                }
                // Proxy textarea: la modal CM6 lavora su una textarea sintetica;
                // intercettiamo l'input per scrivere su _tikzBlocks reale.
                const proxy = document.createElement("textarea");
                proxy.value = blk.tagOpen + "\n" + (blk.body || "").replace(/^\n/, "") + "\n" + blk.tagClose;
                proxy.addEventListener("input", () => {
                    const m = proxy.value.match(/(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/i);
                    if (m) ta._tikzBlocks[activeIdx] = { tagOpen: m[1], body: m[2], tagClose: m[3] };
                    ta._lastRenderedValue = undefined;
                    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
                    ta.dispatchEvent(new Event("input", { bubbles: true }));
                    refreshSidebar();
                });
                window.FM.openTikzModal(proxy);
            });
            content.appendChild(placeholder);
        }
    }

    backdrop.addEventListener("click", async (e) => {
        const act = e.target?.dataset?.act;
        if (act === "close") close();
        else if (act === "add-code") {
            ta._tikzBlocks.push({
                tagOpen:  '<script type="text/tikz" data-show-console="true">',
                body:     "\n\\begin{tikzpicture}\n  \\draw (0,0) -- (1,1);\n\\end{tikzpicture}\n",
                tagClose: '</' + 'script>',
            });
            _appendMarker(ta);
            activeIdx = ta._tikzBlocks.length - 1;
            refreshSidebar(); refreshContent();
            ta._lastRenderedValue = undefined;
            ta.dispatchEvent(new Event("input", { bubbles: true }));
        } else if (act === "add-template") {
            // Apri il template-filler in modal sopra (sopravvive al backdrop).
            if (!window.FM?.openTemplateFiller) {
                const manifest = await fetch("/build/manifest.json").then(r => r.json());
                const entry = manifest["js/entries/tikz-template-filler.js"];
                await import(/* @vite-ignore */ `/build/${entry.file}`);
            }
            window.FM.openTemplateFiller("schema-modulare", null, (tikzString, data) => {
                const dataAttr = encodeURIComponent(JSON.stringify(data));
                const tagOpen = `<script type="text/tikz" data-template-id="schema-modulare" data-template-data="${dataAttr}">`;
                ta._tikzBlocks.push({ tagOpen, body: "\n" + tikzString + "\n", tagClose: '</' + 'script>' });
                _appendMarker(ta);
                activeIdx = ta._tikzBlocks.length - 1;
                refreshSidebar(); refreshContent();
                ta._lastRenderedValue = undefined;
                ta.dispatchEvent(new Event("input", { bubbles: true }));
            });
        }
    });

    document.addEventListener("keydown", function escH(e) {
        if (!_modal) { document.removeEventListener("keydown", escH); return; }
        if (e.key === "Escape") close();
    });
    backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) close(); });

    refreshSidebar();
    refreshContent();
    _modal = { close, ta };
}

// ─── helpers ───
function _smallBtn(text, title, onClick) {
    const b = document.createElement("button");
    b.type = "button"; b.textContent = text; b.title = title;
    b.addEventListener("click", onClick);
    return b;
}

function _moveBlock(ta, idx, dir) {
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= ta._tikzBlocks.length) return;
    const tmp = ta._tikzBlocks[idx];
    ta._tikzBlocks[idx] = ta._tikzBlocks[newIdx];
    ta._tikzBlocks[newIdx] = tmp;
    // Scambia anche i marker corrispondenti nel value (idx 1-based)
    const m1 = `⟨🔍 TikZ #${idx + 1}⟩`;
    const m2 = `⟨🔍 TikZ #${newIdx + 1}⟩`;
    // Niente swap nel value: i marker restano agli stessi indici, la
    // re-numerazione avviene quando _renderTikzButtons riassegna #N.
    // Ma _tikzBlocks gia' scambiato → block-i e' ora sotto marker-i+1, oops.
    // Fix: rinumera i marker nel value dopo lo swap.
    _renumberMarkers(ta);
    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
    ta._lastRenderedValue = undefined;
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

function _removeBlock(ta, idx) {
    ta._tikzBlocks.splice(idx, 1);
    // Rimuovi il marker corrispondente dal value
    const target = `⟨🔍 TikZ #${idx + 1}⟩`;
    ta.value = ta.value.replace(target, "").replace(/\n{3,}/g, "\n\n");
    _renumberMarkers(ta);
    if (typeof ta._renderTikzButtons === "function") ta._renderTikzButtons();
    ta._lastRenderedValue = undefined;
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

function _renumberMarkers(ta) {
    // Ri-genera tutti i marker da 1..N basandosi sull'ORDINE di apparizione
    // nel value, poi li riassegna a partire da 1. Cosi' value.markers sono
    // sempre 1..N consecutivi.
    let count = 0;
    ta.value = ta.value.replace(/⟨🔍 TikZ #\d+⟩/g, () => `⟨🔍 TikZ #${++count}⟩`);
}

function _appendMarker(ta) {
    const newMarker = `⟨🔍 TikZ #${ta._tikzBlocks.length}⟩`;
    ta.value = (ta.value.trim() ? ta.value + "\n" : "") + newMarker;
}

async function _renderMiniPreview(el, block) {
    try {
        const sandbox = document.createElement("div");
        const s = document.createElement("script");
        s.type = "text/tikz";
        s.textContent = block.body || "";
        sandbox.appendChild(s);
        await tikzRenderAll(sandbox, { defaultScope: "public" });
        const svg = sandbox.querySelector("svg");
        if (svg) { el.innerHTML = ""; el.appendChild(svg); }
        else el.innerHTML = '<span class="ph">errore preview</span>';
    } catch (_) {
        el.innerHTML = '<span class="ph">…</span>';
    }
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.openTikzBlocksManager = openTikzBlocksManager;
}
