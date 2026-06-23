/**
 * OverleafProgressManager — estratto da functions-mod.js:17867 (Phase 9a).
 * Apre una tab di progresso mentre i .tex vengono generati, poi la
 * redirige a overleaf.com/docs con l'URL dei file uploadati.
 *
 * Nessuna dipendenza esterna (solo DOM API + window.open).
 */

export const OverleafProgressManager = {
    _createLoadingHtml() {
        return `
      <!doctype html>
      <html lang="it">
      <head>
          <meta charset="utf-8" />
          <title>Preparazione file Overleaf...</title>
          <style>
              body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
              .card { width: min(640px, 92vw); background: #111827; border: 1px solid #1f2937; border-radius: 14px; padding: 24px; box-shadow: 0 12px 32px rgba(0,0,0,.35); }
              .title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
              .subtitle { color: #93c5fd; margin-bottom: 14px; }
              .row { margin: 8px 0; color: #cbd5e1; }
              .pill { display: inline-block; margin-top: 10px; padding: 6px 10px; border-radius: 999px; background: #1e293b; color: #93c5fd; font-size: 13px; }
              .dots::after { content: ''; animation: dots 1.2s steps(4, end) infinite; }
              @keyframes dots { 0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; } 100% { content: ''; } }
          </style>
      </head>
      <body>
          <div class="card">
              <div class="title">Preparazione file per Overleaf</div>
              <div class="subtitle">La pagina verrà aperta automaticamente appena pronta<span class="dots"></span></div>
              <div class="row">Stato: <strong id="phase">Avvio generazione...</strong></div>
              <div class="row">File TEX generati: <strong id="progress-count">0</strong></div>
              <div class="pill">Non chiudere questa scheda</div>
          </div>
      </body>
      </html>
    `;
    },

    openLoadingTab(enabled) {
        if (!enabled) return null;
        const tab = window.open("about:blank", "_blank");
        if (!tab || tab.closed || !tab.document) return tab;
        tab.document.open();
        tab.document.write(this._createLoadingHtml());
        tab.document.close();
        return tab;
    },

    getGeneratedFilesCount(linktoOverleaf) {
        return (String(linktoOverleaf || "").match(/snip_uri\[\]=/g) || []).length;
    },

    updateLoading(tab, phase, linktoOverleaf, expectedTotal = null) {
        if (!tab || tab.closed || !tab.document) return;
        const phaseEl    = tab.document.getElementById("phase");
        const progressEl = tab.document.getElementById("progress-count");
        if (phaseEl) phaseEl.textContent = phase;
        if (progressEl) {
            const generated = this.getGeneratedFilesCount(linktoOverleaf);
            progressEl.textContent = expectedTotal ? `${generated}/${expectedTotal}` : `${generated}`;
        }
    },

    normalizeQuery(query) {
        let q = String(query || "").trim();
        if (q.startsWith("/temp/")) q = `snip_uri[]=${  q}`;
        return q.replace(/^\?+/, "").replace(/^&+/, "")
                .replace(/\?+/g, "").replace(/&&+/g, "&");
    },

    buildUrl(query) {
        return `https://www.overleaf.com/docs?${  this.normalizeQuery(query)}`;
    },

    openFinalUrl(tab, query) {
        const url = this.buildUrl(query);
        if (tab && !tab.closed) tab.location.href = url;
        else window.open(url, "_blank");
        return url;
    },
};

window.FM = window.FM || {};
window.FM.OverleafProgressManager = OverleafProgressManager;
window.OverleafProgressManager    = OverleafProgressManager;
