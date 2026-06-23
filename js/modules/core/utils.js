/**
 * Phase 20 — Utils thin re-export per retro-compat.
 *
 * Source-of-truth:
 *   - generateUUID, shuffleArray, getColorName, lightenColor,
 *     sendLoginRedirectPath, altri helper → js/modules/core/utilities.js
 *   - PathFileVerExtractor class → js/modules/core/path-file-ver-extractor.js
 *
 * Il namespace `Utils` (object) + `window.Utils` + `window.FM.Utils`
 * sono preservati come alias per caller legacy (dom-manager, google-apps).
 */

import { utilities } from "./utilities.js";
import { PathFileVerExtractor } from "./path-file-ver-extractor.js";

export const Utils = {
    generateUUID: utilities.generateUUID,
    sendLoginRedirectPath: utilities.sendLoginRedirectPath,
    PathFileVerExtractor,
};

window.FM = window.FM || {};
window.FM.Utils = Utils;
window.Utils    = Utils;
