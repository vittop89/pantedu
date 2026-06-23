import { asElement } from "./dom-utils.js";

/**
 * PathManager — estratto da functions-mod.js (Phase 9d).
 * G26.phase5.3 — migrato a vanilla JS (no jQuery).
 *
 * API accetta sia Element che jQuery wrapper (transition compat) per
 * supportare caller legacy non ancora migrati.
 */

/** Walk ancestors of `el` matching CSS selector (jQuery .parents()). */
function ancestorsMatching(el, selector) {
    const out = [];
    let cur = el ? el.parentElement : null;
    while (cur) {
        if (cur.matches?.(selector)) out.push(cur);
        cur = cur.parentElement;
    }
    return out;
}

export const PathManager = {
    PathVerifiche: function (insPath) {
        const path = insPath;
        const segments = path.split("/");
        const filename = segments.pop();
        const filename_ver = this.extractName(filename);
        return `/verifiche/php/${filename_ver}.php`;
    },

    extractName: function (filename) {
        const prefix = filename.substring(0, 3);
        const dashIndex = filename.indexOf("-");
        const underscoreIndex = filename.indexOf("_", dashIndex);
        const word = filename.substring(dashIndex + 1, underscoreIndex);
        return `${prefix}/${prefix}-${word}`;
    },

    extractPath: function (element) {
        const el = asElement(element);
        if (!el) return window.location.pathname;

        const allTypeContainers = ancestorsMatching(el, "[id*=type_]");
        let basePath = window.location.pathname;

        for (const container of allTypeContainers) {
            const typeId = container.id;
            if (typeId && typeId.includes("_add")) {
                const addMatch = typeId.match(/_add(\d+)$/);
                if (addMatch && typeof visitedLinks !== "undefined" && visitedLinks.length > 0) {
                    const linkIndex = parseInt(addMatch[1]);
                    if (visitedLinks[linkIndex]) {
                        basePath = visitedLinks[linkIndex];
                        break;
                    }
                }
            }
        }

        const extractor = new PathFileVerExtractor(basePath);
        const closestTypeVer = el.closest(".DraggableContainer_ver");
        return closestTypeVer ? extractor.verPath() : basePath;
    },

    extractProblemID: function (element) {
        const el = asElement(element);
        if (!el) return null;
        const problemEl = el.closest(".fm-groupcollex");
        let ProblemID = problemEl ? problemEl.id : null;
        if (!ProblemID) return ProblemID;
        const ProblemID_ver = ProblemID.replace(/_add\d+$/, "");
        if (el.closest(".DraggableContainer_ver")) ProblemID = ProblemID_ver;
        return ProblemID;
    },

    getLink: function (element, checkPArg) {
        const el = asElement(element);
        let indexLink = "";
        let correspondingPath = "";
        if (checkPArg == 1) {
            const typeContainer = el ? el.closest("[id*=type_]") : null;
            indexLink = typeContainer?.id?.slice(-1) || "";
            correspondingPath = `${window.location.origin}/${visitedLinks[indexLink]}`;
        } else {
            correspondingPath = window.location.pathname;
        }
        return correspondingPath;
    },

    globalTOrelativeIndex: function (attributo, elemDaTrovare, selettoreContenitore) {
        const el = asElement(elemDaTrovare);
        if (!el) return -1;
        const rifeirmentoPadre = el.closest(selettoreContenitore);
        if (!rifeirmentoPadre) return -1;
        const possibili = rifeirmentoPadre.querySelectorAll(attributo);
        let relativeIndex = -1;
        possibili.forEach((node, index) => {
            if (node === el) relativeIndex = index;
        });
        return relativeIndex;
    },

    checkIfPageExists: async function (url) {
        try {
            const response = await fetch(url, { method: "HEAD" });
            if (response.ok || response.status === 401 || response.status === 403 || response.status === 302) {
                return 1;
            }
            return 0;
        } catch (_error) {
            return 0;
        }
    },
};

window.FM = window.FM || {};
window.FM.PathManager = PathManager;
window.PathManager    = PathManager;
