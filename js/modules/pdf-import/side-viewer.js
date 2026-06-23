/**
 * Phase PDF-Import — viewer immagine pagina (navigazione prev/next).
 * L'immagine è servita owner-gated da /session/{id}/page/{n}.
 */
import { pageImageUrl } from "./api.js";

export class SideViewer {
    constructor({ container, info, imgWrap, prevBtn, nextBtn }) {
        this.container = container;
        this.info = info;
        this.imgWrap = imgWrap;
        this.sessionId = null;
        this.pageCount = 0;
        this.page = 1;
        prevBtn?.addEventListener("click", () => this.show(this.page - 1));
        nextBtn?.addEventListener("click", () => this.show(this.page + 1));
    }

    setSession(sessionId, pageCount) {
        this.sessionId = sessionId;
        this.pageCount = pageCount || 0;
        if (this.page > this.pageCount) this.page = 1;
        this.show(this.page);
    }

    show(page) {
        if (!this.sessionId || this.pageCount < 1) return;
        this.page = Math.max(1, Math.min(this.pageCount, page));
        if (this.info) this.info.textContent = `Pagina ${this.page} / ${this.pageCount}`;
        const img = document.createElement("img");
        img.alt = `Pagina ${this.page}`;
        img.loading = "lazy";
        img.src = pageImageUrl(this.sessionId, this.page);
        this.imgWrap.replaceChildren(img);
    }
}
