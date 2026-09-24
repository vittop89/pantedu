/**
 * Phase PDF-Import — loop di polling con backoff (sostituto di SSE).
 *
 * Lo stack Kernel/WAF non è adatto a stream long-lived (text/event-stream),
 * quindi la "tabella live" è guidata da short-poll su /session/{id}: 1s che
 * cresce fino a 5s, fermandosi quando la sessione raggiunge uno stato stabile.
 */
import { getStatus } from "./api.js";

const ACTIVE = new Set(["uploaded", "rasterized", "extracting", "retry"]);

export class SessionPoller {
    constructor(onUpdate, onError) {
        this.onUpdate = onUpdate;
        this.onError = onError;
        this._timer = null;
        this._stopped = false;
        this._delay = 1000;
    }

    start(sessionId) {
        this.stop();
        this._stopped = false;
        this._delay = 1000;
        this._tick(sessionId);
    }

    stop() {
        this._stopped = true;
        if (this._timer) clearTimeout(this._timer);
        this._timer = null;
    }

    async _tick(sessionId) {
        if (this._stopped) return;
        try {
            const res = await getStatus(sessionId);
            this.onUpdate?.(res);
            const status = res?.session?.status || "";
            if (!ACTIVE.has(status)) {
                this.stop();
                return;
            }
            // backoff progressivo 1s → 5s
            this._delay = Math.min(5000, this._delay + 1000);
        } catch (e) {
            this.onError?.(e);
            this._delay = Math.min(5000, this._delay + 1000);
        }
        if (!this._stopped) {
            this._timer = setTimeout(() => this._tick(sessionId), this._delay);
        }
    }
}
