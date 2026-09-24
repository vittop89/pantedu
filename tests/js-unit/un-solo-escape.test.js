import { describe, it, expect } from "vitest";
import * as domUtils from "../../js/modules/core/dom-utils.js";
import * as testoHtml from "../../js/modules/editor/html-text-utils.js";

/**
 * Un escape HTML solo (revisione architetturale del 23/9/2026, A-21).
 *
 * L'escape è una difesa contro l'XSS, e ne esistevano due versioni esportate
 * che non erano d'accordo: `escHtml` di core/dom-utils.js scriveva «0» per lo
 * zero, quella di editor/html-text-utils.js una stringa vuota (`s || ""`);
 * `escHtmlStrict` scriveva «null» per null. Più una ventina di copie locali,
 * due delle quali non codificavano le virgolette (una usata dentro un
 * attributo).
 *
 * La politica, scritta qui e in dom-utils: null e undefined diventano la
 * stringa vuota, ogni altro valore la sua forma di stringa (lo zero è «0»),
 * e si codificano & < > " ' come htmlspecialchars(ENT_QUOTES).
 */

const CASI = [
    [null, ""],
    [undefined, ""],
    [0, "0"],
    [42, "42"],
    ["", ""],
    [`<a href="x" title='y'>&</a>`, "&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;"],
];

describe("escHtml, la versione di riferimento (core/dom-utils.js)", () => {
    it.each(CASI)("escHtml(%j) → %j", (ingresso, atteso) => {
        expect(domUtils.escHtml(ingresso)).toBe(atteso);
    });

    it("esc ed escAttr sono la stessa funzione", () => {
        expect(domUtils.esc).toBe(domUtils.escHtml);
        expect(domUtils.escAttr).toBe(domUtils.escHtml);
    });
});

describe("html-text-utils non ha più un escape suo", () => {
    it("escHtml ed escapeHtml sono quelle di dom-utils", () => {
        expect(testoHtml.escHtml).toBe(domUtils.escHtml);
        expect(testoHtml.escapeHtml).toBe(domUtils.escHtml);
    });

    it.each(CASI)("escHtml(%j) di html-text-utils → %j (lo zero non sparisce)", (ingresso, atteso) => {
        expect(testoHtml.escHtml(ingresso)).toBe(atteso);
    });

    it("escHtmlStrict segue la stessa politica, con l'apostrofo come ContractRenderer", () => {
        expect(testoHtml.escHtmlStrict(null)).toBe("");
        expect(testoHtml.escHtmlStrict(undefined)).toBe("");
        expect(testoHtml.escHtmlStrict(0)).toBe("0");
        expect(testoHtml.escHtmlStrict(`it's <b>"x"</b> & y`))
            .toBe("it&#039;s &lt;b&gt;&quot;x&quot;&lt;/b&gt; &amp; y");
    });
});
