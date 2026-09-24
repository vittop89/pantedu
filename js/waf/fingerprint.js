/**
 * Pantedu WAF — Browser Fingerprinter (client-side)
 *
 * Raccoglie ~30 parametri reali e li invia a /waf/fingerprint.
 * Il server risponde con cookie HMAC `waf_session` + challenge result.
 *
 * Modalità (data-waf-mode su <script>):
 *   - invisible:    raccolta silenziosa, reload pagina dopo cookie set
 *   - interstitial: raccolta + spinner visibile, redirect su pass
 *   - under_attack: come interstitial ma con timeout più lungo
 *
 * Riferimento: docs/todo/waf_security_prompt.md Parte 3.
 */
(function () {
    'use strict';

    /** Estrae attributi dal tag <script> che ha incluso questo file. */
    const scriptEl = document.currentScript || (function () {
        const all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();
    const wafMode = scriptEl?.dataset?.wafMode || 'invisible';
    const shouldReload = scriptEl?.dataset?.wafReload === '1';
    const wafPowToken = scriptEl?.dataset?.wafPow || '';

    // === Proof-of-Work solver ===
    // Trova un nonce tale che sha256(prefix + nonce) abbia >= `bits` bit
    // iniziali a zero. Usa Web Crypto (hash nativo) per coerenza col server.
    function b64urlToStr(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        return atob(s);
    }
    function leadingZeroBits(bytes) {
        let c = 0;
        for (let i = 0; i < bytes.length; i++) {
            const b = bytes[i];
            if (b === 0) { c += 8; continue; }
            for (let k = 7; k >= 0; k--) {
                if ((b >> k) & 1) return c;
                c++;
            }
            return c;
        }
        return c;
    }
    async function solvePow(token) {
        if (!token || !(window.crypto && crypto.subtle)) return '';
        let prefix = '', bits = 16;
        try {
            const p = JSON.parse(b64urlToStr(token.split('.')[0]));
            prefix = String(p.p || ''); bits = parseInt(p.b, 10) || 16;
        } catch (_) { return ''; }
        if (!prefix) return '';
        const enc = new TextEncoder();
        const MAX = 1 << 24; // cap di sicurezza (~16M) per evitare loop infiniti
        for (let nonce = 0; nonce < MAX; nonce++) {
            const buf = await crypto.subtle.digest('SHA-256', enc.encode(prefix + nonce));
            if (leadingZeroBits(new Uint8Array(buf)) >= bits) return String(nonce);
        }
        return '';
    }

    async function collectFingerprint() {
        const fp = {};

        // GRUPPO 1: Display e viewport
        fp.screenW = screen.width;
        fp.screenH = screen.height;
        fp.viewportW = window.innerWidth;
        fp.viewportH = window.innerHeight;
        fp.devicePixelRatio = window.devicePixelRatio || 1;

        // GRUPPO 2 (era: fuso orario e timing) — TOLTO il 22/9/2026. Il fuso
        // orario e' un classico segnale di identificazione e nessuna regola di
        // WafScoringService lo leggeva: si raccoglieva per niente.

        // GRUPPO 3: Hardware
        fp.cpuCores = navigator.hardwareConcurrency || 0;
        fp.deviceMemory = navigator.deviceMemory || 0;
        fp.maxTouchPoints = navigator.maxTouchPoints || 0;

        // GRUPPO 4: Lingua e browser
        fp.language = navigator.language || '';
        fp.platform = navigator.platform || '';
        // Non l'elenco dei plugin — che identifica — ma se ce ne sia almeno uno:
        // e' tutto quello che il punteggio guarda. Resta una stringa, e resta
        // VUOTA quando non ce ne sono, perche' la regola confronta con ''.
        fp.plugins = (navigator.plugins || []).length > 0 ? 'present' : '';

        // GRUPPO 5: il canvas disegna? (non COSA disegna)
        // Fino al 22/9/2026 qui si spediva un pezzo del dataURL, cioe' la vera
        // impronta del canvas. Il punteggio pero' guarda solo `=== 'error'`:
        // gli serve sapere che il motore grafico risponde, perche' le sandbox
        // headless spesso falliscono. Si manda quello.
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillText('waf', 2, 15);
            // Si legge il risultato per costringere il motore a disegnare
            // davvero: una sandbox che non sa farlo solleva qui.
            canvas.toDataURL();
            fp.canvasHash = 'ok';
        } catch (_) { fp.canvasHash = 'error'; }

        // GRUPPO 6: WebGL c'e' o non c'e'
        // Si leggevano vendor e modello reali della scheda video
        // (WEBGL_debug_renderer_info): uno dei segnali piu' identificanti che
        // un browser esponga, e nessuna regola lo guardava. Il punteggio
        // confronta solo con 'no_webgl', perche' headless spesso non ha GPU.
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            fp.webglRenderer = gl ? 'webgl' : 'no_webgl';
        } catch (_) {
            fp.webglRenderer = 'no_webgl';
        }

        // GRUPPO 7: Audio fingerprint
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (AC) {
                const ctx = new AC();
                const oscillator = ctx.createOscillator();
                const analyser = ctx.createAnalyser();
                const gain = ctx.createGain();
                gain.gain.value = 0;
                oscillator.connect(analyser);
                analyser.connect(gain);
                gain.connect(ctx.destination);
                oscillator.start(0);
                // Non si legge piu' lo spettro: il suo valore e' l'impronta
                // audio, e il punteggio guarda solo `!== 'no_audio'`.
                oscillator.stop();
                ctx.close();
                fp.audioFingerprint = 'ok';
            } else {
                fp.audioFingerprint = 'no_audio';
            }
        } catch (_) { fp.audioFingerprint = 'no_audio'; }

        // GRUPPO 8: Comportamento umano (finestra 500ms)
        await new Promise((resolve) => {
            let mouseMoved = false;
            let scrolled = false;
            let mouseEntropy = 0;
            let lastX = 0, lastY = 0;
            let touchDetected = false;

            const onMove = (e) => {
                mouseMoved = true;
                mouseEntropy += Math.abs(e.clientX - lastX) + Math.abs(e.clientY - lastY);
                lastX = e.clientX; lastY = e.clientY;
            };
            const onScroll = () => { scrolled = true; };
            const onTouch  = () => { touchDetected = true; };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('scroll', onScroll);
            document.addEventListener('touchstart', onTouch);

            setTimeout(() => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('scroll', onScroll);
                document.removeEventListener('touchstart', onTouch);
                fp.mouseMoved = mouseMoved;
                fp.mouseEntropy = Math.min(mouseEntropy, 9999);
                fp.scrolled = scrolled;
                fp.touchDetected = touchDetected;
                resolve();
            }, 500);
        });

        // GRUPPO 9: Funzionalità browser reali
        fp.hasServiceWorker = 'serviceWorker' in navigator;
        try {
            localStorage.setItem('_waf_t', '1');
            localStorage.removeItem('_waf_t');
            fp.hasLocalStorage = true;
        } catch (_) { fp.hasLocalStorage = false; }
        fp.windowChrome    = !!(window.chrome && window.chrome.runtime);
        // Tolte il 22/9/2026: hasWebRTC, hasIndexedDB, hasNotification,
        // hasBattery, hasCredentials. Nessuna regola le leggeva, e ogni
        // capacita' in piu' restringe l'insieme dei dispositivi compatibili.
        fp.headlessUA      = /HeadlessChrome|PhantomJS|Puppeteer|playwright|Selenium/i.test(navigator.userAgent);

        // UA dichiarato: il server lo confronta con l'header reale (anti-tamper).
        fp.userAgent = navigator.userAgent || '';

        return fp;
    }

    async function submit() {
        try {
            const fp = await collectFingerprint();
            // Risolvi il Proof-of-Work (se presente) e allega challenge+nonce.
            if (wafPowToken) {
                const nonce = await solvePow(wafPowToken);
                if (nonce !== '') {
                    fp.powChallenge = wafPowToken;
                    fp.powNonce = nonce;
                }
            }
            const res = await fetch('/waf/fingerprint', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(fp),
                credentials: 'same-origin',
                cache: 'no-store'
            });
            let json = null;
            try { json = await res.json(); } catch (_) { json = null; }
            if (!res.ok) {
                console.warn('[WAF] fingerprint POST failed', res.status);
                return json && typeof json === 'object' ? { ...json, _status: res.status } : null;
            }
            return json;
        } catch (err) {
            console.warn('[WAF] fingerprint error', err);
            return null;
        }
    }

    // G27 — segnala l'esito al chiamante (re-solve TRASPARENTE in dom-utils:
    // quando questo script è iniettato con data-waf-reload="0", non ricarica e
    // l'evento dice se la waf_session è stata rinfrescata → la fetch ritenta).
    function signal(ok, extra) {
        try { window.dispatchEvent(new CustomEvent('waf:resolved', { detail: Object.assign({ ok: !!ok }, extra || {}) })); } catch (_) {}
    }

    async function run() {
        const result = await submit();
        if (!result) {
            signal(false);
            return;
        }
        // PoW scaduto/invalido: ricarica UNA volta per ottenere una challenge
        // fresca (guard anti-loop via sessionStorage).
        if (result.retry) {
            signal(false, { retry: true });
            let n = 0;
            try { n = parseInt(sessionStorage.getItem('_waf_retry') || '0', 10) || 0; } catch (_) {}
            // In modalità re-solve trasparente (no reload) non ricarichiamo: il
            // client gestisce il fallback. Reload solo nel flusso challenge-page.
            if (shouldReload && n < 2) {
                try { sessionStorage.setItem('_waf_retry', String(n + 1)); } catch (_) {}
                setTimeout(() => window.location.reload(), 300);
            }
            return;
        }
        try { sessionStorage.removeItem('_waf_retry'); } catch (_) {}
        if (result.challenge === 'block') {
            signal(false, { blocked: true });
            // Server bloccherà al prossimo request comunque; non reload-iamo.
            // (Solo nel flusso challenge-page mostriamo il messaggio full-page.)
            if (shouldReload) {
                document.body.innerHTML = '<h1>Accesso bloccato</h1><p>Punteggio di rischio troppo elevato.</p>';
            }
            return;
        }
        // SUCCESS — waf_session rinfrescata.
        signal(true);
        if (shouldReload) {
            // Reload pagina originale (ora con cookie waf_session valido)
            setTimeout(() => window.location.reload(), wafMode === 'invisible' ? 0 : 800);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
