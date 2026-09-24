/**
 * G23 page-doc — client-side HTML sanitizer **proprio** (EUPL-1.2).
 *
 * Defense-in-depth: l'autoritatività è SERVER-SIDE
 * (App\Services\Security\HtmlSanitizer::forPageDoc → HTMLPurifier).
 * Questo wrapper serve per:
 *   - Live preview nell'editor PT (block staticContent) durante typing
 *   - Stripping immediato di tag pericolosi PRIMA del POST al server
 *     (UX: utente vede subito che il payload è stato bonificato)
 *
 * Implementazione: zero dipendenze esterne. Pure DOMParser + whitelist
 * walker. Mirror della whitelist server (HtmlSanitizer::buildPageDocConfig).
 *
 * Threat model coperto (client-side, best-effort):
 *   - <script>, <style>, <iframe>, <object>, <embed>, <svg>, <math> → drop
 *   - on* event handlers → drop attr
 *   - href="javascript:" / data: / vbscript: / file: → drop attr
 *   - <img onerror=...>, <a onmouseover=...> → drop attr
 *   - Non-whitelisted tags → drop (preservando text children — KEEP_CONTENT)
 *   - target="_blank" senza rel="noopener noreferrer" → auto-add rel
 *
 * NON copre (relegato a server-side authoritative):
 *   - Mutation XSS (parser quirks browser-specific)
 *   - DOM clobbering avanzato
 *   - SVG namespaced exploits
 *
 * Licenza: EUPL-1.2 (codice proprio Pantedu, zero deps esterne).
 */

const ALLOWED_TAGS = new Set([
    "h2", "h3", "h4",
    "p", "ul", "ol", "li",
    "blockquote", "pre", "code",
    "hr", "br",
    "a", "strong", "em", "span",
]);

const ALLOWED_ATTRS_BY_TAG = {
    a:    new Set(["href", "title", "target", "rel"]),
    span: new Set(["class"]),
    // tutti gli altri tag: nessun attr permesso (sanitizer rimuove tutto)
};

const SAFE_HREF_REGEXP = /^(?:https?:|mailto:|\/(?!\/)|#)/i;

/**
 * Sanitize HTML per il PT block `staticContent`.
 * @param {string} dirty  Input HTML grezzo da editor
 * @returns {string}      HTML bonificato (whitelist mirror server)
 */
export function sanitizeForPageDoc(dirty) {
    if (typeof dirty !== "string" || dirty === "") return "";

    // DOMParser sandboxa il parsing (no script execution durante parse —
    // chrome/firefox spec-compliant: <script> in DOMParser non esegue).
    const doc = new DOMParser().parseFromString(
        `<body>${dirty}</body>`,
        "text/html",
    );
    const body = doc.body;
    if (!body) return "";

    cleanNode(body);
    return body.innerHTML;
}

/**
 * Walker ricorsivo: per ogni node decide tieni/strippa/replace-with-text.
 * Bottom-up: prima sanifica children, poi node corrente (così se il node
 * va via, i text children sono già salvi).
 */
function cleanNode(node) {
    // Snapshot children (lista live altrimenti mutata durante iterazione)
    const children = Array.from(node.childNodes);
    for (const child of children) {
        cleanNode(child);
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        // text, comment, doctype, ecc. — strippa SOLO comment.
        if (node.nodeType === Node.COMMENT_NODE) {
            node.remove();
        }
        return;
    }

    const tag = node.tagName.toLowerCase();

    // Tag in blacklist hard → drop tutto (children inclusi).
    // Anche se sanitization parse non esegue script, meglio rimuovere
    // dal DOM finale prima di .innerHTML serialize.
    const HARD_DROP = ["script", "style", "iframe", "object", "embed", "svg", "math", "link", "meta", "base"];
    if (HARD_DROP.includes(tag)) {
        node.remove();
        return;
    }

    if (!ALLOWED_TAGS.has(tag)) {
        // Tag non whitelisted → unwrap (sostituisce con i suoi children).
        unwrap(node);
        return;
    }

    // Tag whitelisted → bonifica attributi.
    cleanAttributes(node, tag);

    // Hook anchor: aggiungi rel="noopener noreferrer" su target=_blank.
    if (tag === "a" && node.getAttribute("target") === "_blank") {
        const rel = (node.getAttribute("rel") || "").split(/\s+/).filter(Boolean);
        if (!rel.includes("noopener"))    rel.push("noopener");
        if (!rel.includes("noreferrer"))  rel.push("noreferrer");
        node.setAttribute("rel", rel.join(" "));
    }
}

function cleanAttributes(node, tag) {
    const allowed = ALLOWED_ATTRS_BY_TAG[tag] || new Set();
    // Iterate snapshot (rimozione muta NamedNodeMap)
    const attrs = Array.from(node.attributes);
    for (const attr of attrs) {
        const name = attr.name.toLowerCase();
        // on* event handlers → drop sempre
        if (name.startsWith("on")) {
            node.removeAttribute(attr.name);
            continue;
        }
        if (!allowed.has(name)) {
            node.removeAttribute(attr.name);
            continue;
        }
        // href validation
        if (tag === "a" && name === "href") {
            if (!SAFE_HREF_REGEXP.test(attr.value)) {
                node.removeAttribute(attr.name);
            }
        }
        // target whitelist (_blank, _self only)
        if (tag === "a" && name === "target") {
            if (attr.value !== "_blank" && attr.value !== "_self") {
                node.removeAttribute(attr.name);
            }
        }
    }
}

function unwrap(node) {
    const parent = node.parentNode;
    if (!parent) return;
    while (node.firstChild) {
        parent.insertBefore(node.firstChild, node);
    }
    parent.removeChild(node);
}

/**
 * Test rapido: verifica che payload XSS comuni vengano strippati.
 * Usato in dev console + unit test (sanitizer.test.js).
 * @returns {{passed: boolean, results: Array<{input,output,safe}>}}
 */
export function selfTest() {
    const cases = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '<a href="javascript:alert(1)">click</a>',
        '<svg onload=alert(1)>',
        '<iframe src="data:text/html,<script>alert(1)</script>"></iframe>',
        '<style>body{background:red}</style>',
        '<a href="http://example.com" target="_blank">ext</a>',
        '<p onclick="alert(1)">x</p>',
        '<a href="vbscript:msgbox(1)">x</a>',
    ];
    const results = cases.map((c) => {
        const out = sanitizeForPageDoc(c);
        const safe = !/<script|onerror|javascript:|<svg|<iframe|<style|onclick|vbscript:/i.test(out);
        return { input: c, output: out, safe };
    });
    return { passed: results.every((r) => r.safe), results };
}
