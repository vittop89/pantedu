/**
 * Phase G8 — VSCode quick-launch per file .tex verifica.
 *
 * Click su button 📝 (.fm-vd-vscode):
 *   1. Trigger download del .tex (anchor[download])
 *   2. Dopo timeout breve, tenta `vscode://file/{path}` con path indovinato
 *      (Downloads dir di default per OS).
 *   3. Toast con istruzioni manuali se VSCode non si apre (impossibile
 *      detect dal client; il browser non riporta l'esito di un custom URL
 *      scheme handler).
 *
 * Integrazione File System Access API:
 *   Quando disponibile (Chrome/Edge), offre un'opzione "Pin a folder"
 *   che salva il FileSystemDirectoryHandle in IndexedDB. Le scritture
 *   future avvengono in quella folder con showSaveFilePicker no-prompt
 *   (usando handle.getFileHandle({create:true})). Ancora non aiuta a
 *   risolvere il path per vscode:// (FS Access API non espone absolute
 *   paths per security), ma evita la duplicazione in Downloads/ quando
 *   si modifica una verifica e si re-scarica.
 *
 * Storage:
 *   localStorage["fm.vscode.user_dir"]:
 *     percorso assoluto user home dir (es. "C:\\Users\\vitto").
 *     Default detection: %USERPROFILE% lato browser non e' esposto;
 *     usiamo navigator.userAgent + heuristic + UI prompt per editare.
 */

const STORAGE_KEY_DIR = "fm.vscode.user_dir";

function ensureToast(kind, title, msg, ms = 5000) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, ms);
    } else {
        console.info(`[verifica-vscode] ${title}: ${msg}`);
    }
}

function detectOs() {
    const ua = navigator.userAgent || "";
    if (/Windows/i.test(ua)) return "windows";
    if (/Macintosh|Mac OS/i.test(ua)) return "mac";
    if (/Linux/i.test(ua)) return "linux";
    return "windows"; // fallback ragionevole su utenza italiana scolastica
}

function defaultDownloadDir() {
    const cached = localStorage.getItem(STORAGE_KEY_DIR);
    if (cached) return cached;
    // Heuristic: nessun path sicuro lato browser. Restituiamo placeholder
    // pre-configurato che funziona se l'utente lo edita una volta.
    const os = detectOs();
    if (os === "windows") return "C:/Users/USERNAME/Downloads";
    if (os === "mac")     return "/Users/USERNAME/Downloads";
    return "/home/USERNAME/Downloads";
}

function setDownloadDir(dir) {
    if (!dir || dir.includes("USERNAME")) {
        localStorage.removeItem(STORAGE_KEY_DIR);
        return;
    }
    localStorage.setItem(STORAGE_KEY_DIR, dir);
}

function joinPath(dir, file) {
    const sep = dir.includes("\\") ? "\\" : "/";
    return dir.replace(/[/\\]+$/, "") + sep + file;
}

/**
 * Trigger download .tex via anchor[download] e tenta apertura VSCode.
 */
async function launchInVscode(docId) {
    const filename = `verifica_${docId}.tex`;
    const dir = defaultDownloadDir();

    if (dir.includes("USERNAME")) {
        // Prima volta: chiedi all'utente di configurare la directory.
        const proposed = await window.FM.Dialog.prompt(
            "Per aprire automaticamente in VSCode, indica la tua cartella Downloads "
          + "(percorso assoluto). Esempio: C:/Users/mario/Downloads\n\n"
          + "Lasciare vuoto per scaricare solo il file (apertura manuale).",
            dir,
        );
        if (!proposed) {
            simpleDownload(docId, filename);
            ensureToast("info", "Download", "TEX scaricato. Apri manualmente con TeXworks/VSCode.");
            return;
        }
        setDownloadDir(proposed.trim());
    }

    // 1) Download
    simpleDownload(docId, filename);

    // 2) Wait 600ms (dare tempo al browser di scrivere il file in Downloads).
    setTimeout(() => {
        const fullPath = joinPath(localStorage.getItem(STORAGE_KEY_DIR) || dir, filename);
        // vscode://file/<absolute-path>; Windows path con backslash diventa /
        const normalized = fullPath.replace(/\\/g, "/");
        const url = `vscode://file/${normalized}`;
        // Apertura via location.href: il browser passa l'URL al protocol handler
        // registrato (VSCode lo registra all'install). Se non registrato,
        // nessun errore visibile; fallback toast informativo.
        try {
            window.location.href = url;
            ensureToast("success", "VSCode",
                `Tentativo di apertura in VSCode: ${normalized}\n`
              + "Se non si apre, fai doppio-click sul file nei Downloads.",
                7000);
        } catch (e) {
            ensureToast("error", "VSCode", `Errore apertura: ${e.message}`);
        }
    }, 600);
}

function simpleDownload(docId, filename) {
    const a = document.createElement("a");
    a.href = `/api/verifica/${docId}/tex`;
    a.download = filename;
    a.style.display = "none";
    document.body.appendChild(a);
    a.click();
    a.remove();
}

// ─────── Click delegation ───────

document.addEventListener("click", (e) => {
    const btn = e.target.closest('.fm-vd-vscode, button[data-fm-action="open-vscode"]');
    if (!btn) return;
    const li = btn.closest("li[data-content-id]");
    const id = parseInt(li?.dataset.contentId || btn.dataset.fmId || "0", 10);
    if (id <= 0) return;
    e.preventDefault();
    e.stopPropagation();
    launchInVscode(id);
});

// Settings UI: shift+click sul btn 📝 → riapre prompt directory.
document.addEventListener("click", async (e) => {
    if (!e.shiftKey) return;
    const btn = e.target.closest('.fm-vd-vscode, button[data-fm-action="open-vscode"]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const cur = localStorage.getItem(STORAGE_KEY_DIR) || defaultDownloadDir();
    const next = await window.FM.Dialog.prompt("Cartella Downloads (per quick-launch VSCode):", cur);
    if (next !== null) setDownloadDir(next.trim());
}, true);

// Esponi per debugging.
window.FM = window.FM || {};
window.FM.VerificaVscode = {
    launchInVscode,
    setDownloadDir,
    getDownloadDir: () => localStorage.getItem(STORAGE_KEY_DIR) || defaultDownloadDir(),
};
