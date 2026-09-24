/**
 * G24.phase4 — Client-side XSS sanitization (defense-in-depth).
 *
 * Mirror della policy server `App\Services\Security\HtmlSanitizer` per
 * pre-sanitize content PRIMA del save (UX hint: vedi nel preview cosa
 * arriverà al server) e dopo apply post-save (display).
 *
 * NB: SERVER È AUTHORITATIVE. Mai trust client sanitization come security
 * boundary. Questo modulo riduce surface attack (es. paste da fonti
 * untrusted) ma il server applicherà di nuovo la sanitization a render.
 *
 * Implementazione: usa `DOMParser` + DOM walking (no external library —
 * evita dipendenza CDN run-time + bundle size).
 *
 * Allowlist (mirror server `HtmlSanitizer::buildBlockContentConfig`):
 *   - Tag: b, strong, i, em, u, s, sub, sup, a, span, br
 *   - Attr <a>: href (solo http/https/mailto), title
 *   - Attr <span>: style (subset color/bg-color/font-*), class
 *   - CSS: NO expression()/url(javascript:)/vbscript:
 */

const ALLOWED_TAGS = new Set(['B','STRONG','I','EM','U','S','SUB','SUP','A','SPAN','BR']);
const ALLOWED_ATTRS = {
    A:    ['href', 'title'],
    SPAN: ['style', 'class'],
};
const SAFE_URI_SCHEMES = /^(https?:|mailto:)/i;
const ALLOWED_CSS_PROPS = new Set([
    'color', 'background-color', 'font-weight', 'font-style', 'text-decoration',
]);
// Tag pericolosi: DROP COMPLETO (include content). Per gli altri non-allowlist
// usa UNWRAP (preserva content come text). Cosi' <script>alert(1)</script>x
// → "x" (no alert), <div>safe</div> → "safe".
const DROP_TAGS = new Set([
    'SCRIPT','STYLE','IFRAME','OBJECT','EMBED','LINK','META','BASE',
    'FRAME','FRAMESET','APPLET','FORM','INPUT','BUTTON','TEXTAREA','SELECT',
    'OPTION','OPTGROUP','LABEL','LEGEND','FIELDSET','OUTPUT','PROGRESS',
    'AUDIO','VIDEO','SOURCE','TRACK','MAP','AREA','SVG','MATH',
]);

/** Sanitize HTML string per text block content. Mirror server policy.
 *  @param {string} html
 *  @returns {string} HTML cleaned (allowlist-driven). */
export function sanitizeBlockContent(html) {
    if (!html || typeof html !== 'string') return '';
    if (!_isEnabled()) return html;

    // Parse in document staccato (no execution context)
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const root = doc.body.firstChild;
    if (!root) return '';

    _walkSanitize(root);
    return root.innerHTML;
}

/** Strip ALL HTML markup. Mirror `HtmlSanitizer::forStrictText`.
 *  Drop COMPLETI per tag pericolosi (es. <script>) prima di estrarre text.
 *  Senza, `<script>alert(1)</script>` produrrebbe "alert(1)" come text. */
export function sanitizeStrictText(html) {
    if (!html || typeof html !== 'string') return '';
    if (!_isEnabled()) return html;
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    const root = doc.body.firstChild;
    if (!root) return '';
    // Drop completo dei tag pericolosi (content incluso) prima di textContent
    root.querySelectorAll(Array.from(DROP_TAGS).map(t => t.toLowerCase()).join(',')).forEach(el => el.remove());
    return root.textContent || '';
}

/** Feature flag — env-like via global window.__FM_XSS_SANITIZE_DISABLED.
 *  Default ENABLED. Per debug emergency: window.__FM_XSS_SANITIZE_DISABLED = true. */
function _isEnabled() {
    return typeof window === 'undefined'
        || !window.__FM_XSS_SANITIZE_DISABLED;
}

/** DOM walker: rimuove tag non-allowlist, attributi non-allowlist, attributi
 *  unsafe (href javascript:, style con js URL, on*). */
function _walkSanitize(node) {
    // Iterate children (raccolti in array prima: la rimozione cambia NodeList live)
    const children = Array.from(node.childNodes);
    for (const child of children) {
        if (child.nodeType === Node.TEXT_NODE) continue; // text node sempre safe
        if (child.nodeType !== Node.ELEMENT_NODE) {
            child.remove();
            continue;
        }
        const tag = child.tagName;
        if (DROP_TAGS.has(tag)) {
            // Tag pericoloso → REMOVE completo (include content)
            child.remove();
            continue;
        }
        if (!ALLOWED_TAGS.has(tag)) {
            // Tag non allowlist neutro → UNWRAP (preserva content come text)
            const parent = child.parentNode;
            while (child.firstChild) parent.insertBefore(child.firstChild, child);
            child.remove();
            continue;
        }
        // Strip attributi non allowlist
        const allowedAttrs = ALLOWED_ATTRS[tag] || [];
        const attrsToRemove = [];
        for (const attr of child.attributes) {
            const name = attr.name.toLowerCase();
            // Strip on* event handlers ALWAYS
            if (name.startsWith('on')) { attrsToRemove.push(attr.name); continue; }
            // Whitelist per tag
            if (!allowedAttrs.includes(name)) { attrsToRemove.push(attr.name); continue; }
            // Validation specifica
            if (name === 'href') {
                const v = (attr.value || '').trim();
                if (!SAFE_URI_SCHEMES.test(v) && !v.startsWith('/') && !v.startsWith('#')) {
                    attrsToRemove.push(attr.name);
                }
            } else if (name === 'style') {
                const safe = _sanitizeStyleAttr(attr.value);
                if (safe === '') attrsToRemove.push(attr.name);
                else child.setAttribute('style', safe);
            }
        }
        attrsToRemove.forEach(a => child.removeAttribute(a));
        // Recurse children
        _walkSanitize(child);
    }
}

/** CSS property allowlist + valore check (no js: url(), no expression). */
function _sanitizeStyleAttr(value) {
    if (!value) return '';
    const decls = String(value).split(';').map(s => s.trim()).filter(Boolean);
    const safeDecls = [];
    for (const decl of decls) {
        const colonIdx = decl.indexOf(':');
        if (colonIdx < 0) continue;
        const prop = decl.slice(0, colonIdx).trim().toLowerCase();
        const val  = decl.slice(colonIdx + 1).trim();
        if (!ALLOWED_CSS_PROPS.has(prop)) continue;
        const valLow = val.toLowerCase();
        // eslint-disable-next-line no-script-url
        if (valLow.includes('javascript:')
         || valLow.includes('expression(')
         || valLow.includes('vbscript:')
         || /url\s*\(\s*["']?\s*(javascript|data):/i.test(val)) {
            continue;
        }
        safeDecls.push(`${prop}: ${val}`);
    }
    return safeDecls.join('; ');
}
