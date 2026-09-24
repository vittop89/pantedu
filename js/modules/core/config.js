/**
 * Config — estratto da script.js:6 (Phase 9b).
 *
 * Definizione statica della sidebar: 6 sezioni
 * (#fm-sp-mappe, #fm-sp-lab, #fm-sp-eser, #fm-sp-verif, #fm-sp-bes,
 * #fm-sp-risdoc). Ogni entry ha `sidepage` (key data-sidepage del
 * button), templateURL, categories e pathPattern(fn) per calcolare
 * URL dinamici. Lookup da button click via
 * `Config.SIDEBAR_CONFIG[sidebarId].sidepage === btn.dataset.sidepage`.
 */

export const Config = {
    // La scelta del vecchio banner dei cookie, tolto il 15/9/2026: resta il
    // nome solo perché bootstrap.js la cancelli dai browser che l'hanno.
    COOKIE_CONSENT_KEY: "user_cookie_consent_v2",
    SIDEBAR_CONFIG: {
        "#fm-sp-mappe": {
            sidepage: "mappe",
            dirName: "mappe",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: (dirName, numArg, materia, argomento, optsel, folder) => ({
                file_links: `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${materia}_${dirName}-links_${optsel}.json`,
                file_php:   `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${numArg}_${materia}-${argomento}-${optsel}.php`,
                dirfile:    `/${dirName}/${folder}/${dirName}_${optsel}/${materia}`,
            }),
            showHideLogic: true,
        },
        "#fm-sp-lab": {
            sidepage: "lab",
            dirName: "lab",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: (dirName, numArg, materia, argomento, optsel, folder) => ({
                file_links: `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${materia}_${dirName}-links_${optsel}.json`,
                file_php:   `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${numArg}_${materia}-${argomento}-${optsel}.php`,
                dirfile:    `/${dirName}/${folder}/${dirName}_${optsel}/${materia}`,
            }),
            showHideLogic: true,
        },
        "#fm-sp-eser": {
            sidepage: "eser",
            dirName: "eser",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: (dirName, numArg, materia, argomento, optsel, folder) => ({
                file_links: `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${materia}_${dirName}-links_${optsel}.json`,
                file_php:   `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${numArg}_${materia}-${argomento}-${optsel}.php`,
                dirfile:    `/${dirName}/${folder}/${dirName}_${optsel}/${materia}`,
                users_json: `/${dirName}/${folder}/${dirName}_${optsel}/users.json`,
            }),
            showHideLogic: true,
        },
        "#fm-sp-verif": {
            sidepage: "verif",
            dirName: "eser",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: () => ({}),
            showHideLogic: true,
            // 2026-09-05 — il gate PasswordSidepage (re-auth via /log/auth,
            // spento dalla Phase 25.B1) non esiste più: l'accesso lo decide il
            // server. Per gli studenti vedi student-resource-auth.js.
        },
        "#fm-sp-bes": {
            sidepage: "bes",
            dirName: "strcomp_bes_altro",
            categories:   ["STRCOMP", "ALTRO"],
            IDcategories: ["#STRCOMP", "#ALTRO"],
            pathPattern: (dirName, numArg, category, argomento) => ({
                file_links: `/${dirName}/${category}/${category}_links.json`,
                file_php:   `/${dirName}/${category}/${numArg}_SBA-${argomento}-${category}.php`,
                dirfile:    `/${dirName}/${category}`,
            }),
            showHideLogic: true,
        },
        // Phase 21 — #fm-sp-risdoc gestito da risdoc-sidepage.js (auth
        // server-side via Permission). Niente password sidepage + template vuoto.
        "#fm-sp-risdoc": {
            sidepage: "risdoc",
            dirName: "risdoc",
            categories:   ["MODELLI", "RISORSE"],
            IDcategories: ["#MODELLI", "#RISORSE"],
            pathPattern: () => ({}),
            showHideLogic: false,
        },
    },
};

window.FM = window.FM || {};
window.FM.Config = Config;
window.Config    = Config;
