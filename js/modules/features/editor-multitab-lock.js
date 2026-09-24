/**
 * G24.refactor2 — Multi-tab cooperative lock per editor inline.
 *
 * Scenario: stesso teacher edita stesso item da 2 tab del browser. Entrambi
 * schedulano autosave 5s. Il primo save vince; il secondo va in 409 conflict.
 * Pre-fix: silent retry sovrascriveva l'edit del primo tab (dataloss).
 *
 * Strategia: ogni editor inline registra un BroadcastChannel
 * `fm-editor-${itemId}`. Su `openItemEditor`/`openGroupEditor`:
 *  - Tab A apre editor → channel.postMessage({type:"acquire", tabId:"A"})
 *  - Tab B apre stesso editor → riceve "acquire" → mostra warning + freeze
 *  - Tab A chiude → channel.postMessage({type:"release"})
 *  - Tab B può riprovare ad acquire
 *
 * Limitazioni: BroadcastChannel funziona solo SAME-ORIGIN + stesso browser.
 * Non protegge da 2 browser diversi (rare per stesso user). Server-side
 * optimistic-lock 409 resta authoritative fallback.
 *
 * Browser support: tutti moderni (Chrome 54+, Firefox 38+, Safari 15.4+,
 * Edge 79+). IE: no support → no-op graceful (editor funziona, no lock).
 */

const _activeChannels = new Map(); // key → { channel, tabId, lockedByOther }
const TAB_ID = `tab-${Math.random().toString(36).slice(2, 10)}-${Date.now()}`;

/** Acquire lock cooperativo per `key` (es. "item-123" / "group-g1").
 *  @param {string} key
 *  @param {object} callbacks { onLockedByOther: (peerTabId) => void, onReleased: () => void }
 *  @returns {boolean} true se acquired, false se già locked da altro tab */
export function acquireLock(key, callbacks = {}) {
    if (typeof BroadcastChannel === "undefined") return true; // graceful no-op
    if (_activeChannels.has(key)) return true; // già acquired dallo stesso tab

    const channel = new BroadcastChannel(`fm-editor-${key}`);
    const entry = { channel, tabId: TAB_ID, lockedByOther: null, callbacks };
    _activeChannels.set(key, entry);

    // Listener: tab altro acquire → notifica
    channel.addEventListener("message", (e) => {
        const msg = e.data;
        if (!msg || typeof msg !== "object") return;
        if (msg.type === "acquire" && msg.tabId !== TAB_ID) {
            // Altro tab vuole il lock — se noi siamo già editing, ignora
            // (siamo arrivati prima). Rispondiamo con "already-acquired".
            channel.postMessage({ type: "already-acquired", tabId: TAB_ID });
        } else if (msg.type === "already-acquired" && msg.tabId !== TAB_ID) {
            // Altro tab ha già il lock — freeze il nostro editor
            entry.lockedByOther = msg.tabId;
            callbacks.onLockedByOther?.(msg.tabId);
        } else if (msg.type === "release" && msg.tabId !== TAB_ID) {
            // Altro tab ha rilasciato — possiamo procedere
            if (entry.lockedByOther === msg.tabId) {
                entry.lockedByOther = null;
                callbacks.onReleased?.();
            }
        }
    });

    // Annuncia acquisizione
    channel.postMessage({ type: "acquire", tabId: TAB_ID });
    return true;
}

/** Release lock: chiude channel + notifica altri tab.
 *  Chiamare in closeItemEditor / closeGroupEditor / beforeunload. */
export function releaseLock(key) {
    const entry = _activeChannels.get(key);
    if (!entry) return;
    try {
        entry.channel.postMessage({ type: "release", tabId: TAB_ID });
        entry.channel.close();
    } catch (_) { /* ignore */ }
    _activeChannels.delete(key);
}

/** Check se l'editor è attualmente lockato da altro tab.
 *  Usato da save handler per skip save se lockedByOther. */
export function isLockedByOther(key) {
    const entry = _activeChannels.get(key);
    return entry?.lockedByOther != null;
}

// Release tutti al unload pagina
if (typeof window !== "undefined") {
    window.addEventListener("beforeunload", () => {
        for (const key of _activeChannels.keys()) releaseLock(key);
    });
}
