/**
 * <fm-risdoc-images-manager> — Phase 24.30
 *
 * Image manager standalone usato dal popup nella pt-toolbar (e potenzialmente
 * altrove). Mostra grid thumbnail delle override esistenti + form upload
 * multipart. Risolve template-id da window.fm_risdoc_template_id (server-set)
 * o dall'attr template-id di fm-pt-document.
 */

import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { fetchJson, fetchCsrf } from "../../modules/core/dom-utils.js";

export class FmRisdocImagesManager extends LitElement {
    static properties = {
        templateId: { type: String, attribute: "template-id" },
        _images: { state: true },
        _systemImages: { state: true },
        _busy:   { state: true },
        _err:    { state: true },
    };

    static styles = css`
        :host { display: block; font-size: 12px; color: var(--fm-risdoc-text, #1e293b); }
        form {
            display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
            padding: 10px; margin-bottom: 10px;
            background: var(--fm-risdoc-bg-field, #f8fafc);
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 6px;
        }
        input[type="text"] {
            font-size: 12px; padding: 4px 6px;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 3px; min-width: 220px;
        }
        button.upload {
            padding: 5px 12px; font-size: 12px; font-weight: 600;
            background: var(--fm-risdoc-accent, #2a5ac7); color: var(--fm-risdoc-text-inverse, #fff);
            border: 0; border-radius: 4px; cursor: pointer;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 8px;
            max-height: 300px;
            overflow-y: auto;
        }
        .item {
            background: var(--fm-risdoc-card-bg, #fff);
            border: 1px solid var(--fm-risdoc-border-subtle, #e2e8f0);
            border-radius: 4px;
            padding: 6px;
        }
        .item img {
            width: 100%; height: 80px; object-fit: contain;
            background: var(--fm-risdoc-bg-field, #f1f5f9); border-radius: 3px;
        }
        .item .path {
            font-size: 10px; word-break: break-all;
            margin-top: 4px; color: var(--fm-risdoc-text-muted, #64748b);
        }
        .item--system { border-color: var(--fm-c-info, #3b82f6); }
        .item__badge {
            display: inline-block; padding: 1px 6px;
            font-size: 9px; font-weight: 600;
            border-radius: 3px; margin-top: 4px;
            background: color-mix(in srgb, var(--fm-c-info, #3b82f6) 18%, transparent);
            color: var(--fm-c-info, #3b82f6);
            letter-spacing: 0.04em; text-transform: uppercase;
        }
        .item--system .item__badge--sys {
            background: color-mix(in srgb, var(--fm-c-info, #3b82f6) 18%, transparent);
            color: var(--fm-c-info, #3b82f6);
        }
        .item--override .item__badge--ovr {
            background: color-mix(in srgb, var(--fm-c-success, #10b981) 22%, transparent);
            color: var(--fm-c-success, #10b981);
        }
        .section-head {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--fm-risdoc-text-muted, #64748b);
            margin: 12px 0 6px 0; padding-bottom: 2px;
            border-bottom: 1px solid var(--fm-c-border, #e5e7eb);
        }
        .empty { font-style: italic; color: var(--fm-risdoc-text-muted, #94a3b8); padding: 12px 0; }
        .err { color: var(--fm-risdoc-error-fg, #b91c1c); font-size: 11px; }
    `;

    constructor() {
        super();
        this._images = [];
        this._systemImages = [];
        this._busy = false;
        this._err = "";
    }

    connectedCallback() {
        super.connectedCallback();
        this._refresh();
    }

    _templateId() {
        // Preferenza: attr "template-id" sull'elemento (uso standalone es. in
        // /admin/templates). Fallback: leggere da fm-pt-document presente sulla
        // pagina (uso embedded dentro la doc shell).
        const own = parseInt(this.templateId || "0", 10);
        if (own > 0) return own;
        const tmpl = document.querySelector("fm-pt-document");
        return parseInt(tmpl?.getAttribute("template-id") || "0", 10) || 0;
    }

    async _csrf() {
        return fetchCsrf();
    }

    async _refresh() {
        const tid = this._templateId();
        if (!tid) { this._err = "Template ID non disponibile"; return; }
        this._busy = true;
        try {
            const j = await fetchJson(`/api/risdoc/templates/${tid}/overrides`);
            if (j.error) throw new Error(j.error);
            this._images = (j.overrides || []).filter((o) => o.kind === "image");
            // System images (default condivisi: stemma Repubblica, logo scuola,
            // ecc. in storage/templates/risdoc/images/). Visualizzate read-only
            // con badge "sistema": l'admin può sostituirle caricando un file
            // con lo STESSO path (es. "images/logo_scuola.png") che diventa
            // override per-teacher → ha precedenza in fase di render PDF.
            const overrideOnes = new Set(this._images.map((o) => o.relative_path));
            this._systemImages = (j.system_images || []).filter((s) => !overrideOnes.has(s.relative_path));
            this._err = "";
        } catch (e) { this._err = e.message; }
        this._busy = false;
    }

    async _onUpload(e) {
        e.preventDefault();
        const tid = this._templateId();
        if (!tid) { this._err = "Template ID non disponibile"; return; }
        const form = e.target;
        const path = form.path.value.trim();
        const file = form.file.files[0];
        if (!path || !file) return;
        const csrf = await this._csrf();
        const fd = new FormData();
        fd.append("_csrf", csrf);
        fd.append("kind", "image");
        fd.append("path", path);
        fd.append("file", file);
        this._busy = true;
        try {
            const j = await fetchJson(`/api/risdoc/templates/${tid}/override`, {
                method: "POST", body: fd,
            });
            if (!j.ok) throw new Error(j.error || "richiesta non riuscita");
            form.reset();
            await this._refresh();
        } catch (e) { this._err = e.message; }
        this._busy = false;
    }

    render() {
        const tid = this._templateId();
        return html`
            <form @submit=${(e) => this._onUpload(e)}>
                <input type="text" name="path" placeholder="images/logo_scuola.png" value="images/logo_scuola.png">
                <input type="file" name="file" accept="image/*" required>
                <button class="upload" type="submit" ?disabled=${this._busy}>⬆ Upload</button>
            </form>
            ${this._err ? html`<div class="err">${this._err}</div>` : ""}

            ${this._images.length > 0 ? html`
                <div class="section-head">Override per questo template (${this._images.length})</div>
                <div class="grid">
                    ${this._images.map((o) => html`
                        <div class="item item--override">
                            <img src="/api/risdoc/templates/${tid}/file?kind=image&path=${encodeURIComponent(o.relative_path)}"
                                 alt=${o.relative_path} loading="lazy">
                            <div class="path">${o.relative_path}</div>
                            <span class="item__badge item__badge--ovr">override</span>
                        </div>
                    `)}
                </div>
            ` : ""}

            ${this._systemImages.length > 0 ? html`
                <div class="section-head">Immagini di sistema (default risdoc/images/, ${this._systemImages.length})</div>
                <div class="grid">
                    ${this._systemImages.map((o) => html`
                        <div class="item item--system">
                            <img src="/api/risdoc/templates/${tid}/file?kind=image&path=${encodeURIComponent(o.relative_path)}"
                                 alt=${o.relative_path} loading="lazy">
                            <div class="path">${o.relative_path}</div>
                            <span class="item__badge item__badge--sys">sistema</span>
                        </div>
                    `)}
                </div>
            ` : ""}

            ${this._images.length === 0 && this._systemImages.length === 0
                ? html`<div class="empty">Nessuna immagine disponibile.</div>` : ""}
        `;
    }
}

if (!customElements.get("fm-risdoc-images-manager")) {
    customElements.define("fm-risdoc-images-manager", FmRisdocImagesManager);
}
