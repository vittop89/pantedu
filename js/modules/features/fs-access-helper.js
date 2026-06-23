/**
 * Phase G19.36 — File System Access API helper.
 *
 * Permette di salvare un FileSystemDirectoryHandle (folder picker) in
 * IndexedDB cosi' le scritture future possono avvenire senza re-prompt
 * (con permission persistente accordata dall'utente).
 *
 * API esposte:
 *   - isSupported() — boolean (Chrome/Edge desktop)
 *   - pickRoot() — apre showDirectoryPicker, salva handle in IDB
 *   - getRoot() — recupera handle salvato (o null)
 *   - clearRoot() — elimina handle salvato
 *   - writeFile(rootHandle, relPath, content) — scrive file (crea sub-cartelle)
 *   - getOrRequestPermission(handle, mode='readwrite') — re-richiede permesso
 *
 * IndexedDB schema:
 *   db: 'fm-fs-access', store: 'handles', key: 'vsc-root', value: FileSystemDirectoryHandle
 *
 * Note sicurezza:
 *   - L'handle non da' accesso alla path assoluta (security browser).
 *   - L'utente deve fornire l'absolute path SEPARATAMENTE
 *     (`localStorage["fm.vscode.user_dir"]`) per costruire `vscode://file/`.
 *   - Permesso persistente solo finche' la sessione browser non viene chiusa.
 *     Al re-load potremmo dover richiedere `requestPermission` di nuovo.
 */

const DB_NAME = "fm-fs-access";
const STORE = "handles";
const KEY_VSC_ROOT = "vsc-root";

export function isSupported() {
    return typeof window !== "undefined"
        && typeof window.showDirectoryPicker === "function"
        && typeof window.indexedDB !== "undefined";
}

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE);
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function idbGet(key) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, "readonly");
        const req = tx.objectStore(STORE).get(key);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
    });
}

async function idbPut(key, value) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, "readwrite");
        tx.objectStore(STORE).put(value, key);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function idbDel(key) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, "readwrite");
        tx.objectStore(STORE).delete(key);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

export async function pickRoot() {
    if (!isSupported()) throw new Error("File System Access API non supportata (usa Chrome/Edge desktop).");
    // G19.39 — Chrome blocca system folders (C:\, C:\Windows, C:\Program Files,
    // C:\Users root, root del disco, Desktop in alcune versioni). L'utente deve
    // scegliere una sub-cartella dedicata (es. C:\Users\<user>\Documents\pantedu-vsc).
    // `startIn: "documents"` apre il picker dentro Documents per evitare che
    // l'utente cerchi di pickare il root del disco.
    const handle = await window.showDirectoryPicker({
        mode: "readwrite",
        id: "fm-vsc-root",
        startIn: "documents",
    });
    await idbPut(KEY_VSC_ROOT, handle);
    return handle;
}

export async function getRoot() {
    if (!isSupported()) return null;
    return await idbGet(KEY_VSC_ROOT);
}

export async function clearRoot() {
    return idbDel(KEY_VSC_ROOT);
}

/** Re-richiede permesso readwrite se necessario (handle persistente puo'
 *  perdere il grant tra session/reload). */
export async function getOrRequestPermission(handle, mode = "readwrite") {
    if (!handle) return false;
    const opts = { mode };
    if ((await handle.queryPermission(opts)) === "granted") return true;
    if ((await handle.requestPermission(opts)) === "granted") return true;
    return false;
}

/**
 * Scrive un file dentro `rootHandle`. `relPath` puo' includere subdirs
 * (es. `"mat/2sc/Verifica1/file.tex"`); le subdir vengono create on-demand.
 * `content` puo' essere string | Uint8Array | Blob.
 */
export async function writeFile(rootHandle, relPath, content) {
    const parts = relPath.split("/").filter(Boolean);
    if (!parts.length) throw new Error("relPath vuoto");
    const fileName = parts.pop();
    let dir = rootHandle;
    for (const seg of parts) {
        dir = await dir.getDirectoryHandle(seg, { create: true });
    }
    const fileHandle = await dir.getFileHandle(fileName, { create: true });
    const writable = await fileHandle.createWritable();
    await writable.write(content);
    await writable.close();
    return fileHandle;
}

/**
 * G22.S20 — Legge un file dalla rootHandle dato relPath. Ritorna Uint8Array.
 * Throws NotFoundError se path non esiste.
 */
export async function readFile(rootHandle, relPath) {
    const parts = relPath.split("/").filter(Boolean);
    if (!parts.length) throw new Error("relPath vuoto");
    const fileName = parts.pop();
    let dir = rootHandle;
    for (const seg of parts) {
        dir = await dir.getDirectoryHandle(seg, { create: false });
    }
    const fh = await dir.getFileHandle(fileName, { create: false });
    const file = await fh.getFile();
    return new Uint8Array(await file.arrayBuffer());
}

/**
 * G22.S20 — One-shot pick di una cartella DIVERSA dalla root persistente
 * (es. per import bundle scaricato precedentemente, separato dalla
 * cartella sync locale corrente). NON salva in IDB.
 */
export async function pickFolderOneShot(suggestedName = "pantedu-bundle") {
    if (!isSupported()) throw new Error("File System Access API non supportata (usa Chrome/Edge desktop).");
    return window.showDirectoryPicker({ id: "fm-import-" + suggestedName, mode: "read" });
}

/**
 * G22.S20 — Walk recursivo di un dirHandle. Ritorna lista `[{path, file}]`
 * dove `path` è il path relativo (forward slash) e `file` è File API object.
 * Salta directory hidden (.git, .DS_Store, ecc).
 */
export async function walkAll(dirHandle, prefix = "") {
    const out = [];
    for await (const [name, handle] of dirHandle.entries()) {
        if (name.startsWith(".")) continue;
        const sub = prefix ? `${prefix}/${name}` : name;
        if (handle.kind === "directory") {
            const nested = await walkAll(handle, sub);
            out.push(...nested);
        } else if (handle.kind === "file") {
            const file = await handle.getFile();
            out.push({ path: sub, file });
        }
    }
    return out;
}

window.FM = window.FM || {};
window.FM.FsAccess = {
    isSupported, pickRoot, getRoot, clearRoot, getOrRequestPermission, writeFile,
    readFile, pickFolderOneShot, walkAll,
};
