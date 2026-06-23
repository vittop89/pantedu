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
    COOKIE_CONSENT_KEY: "user_cookie_consent_v2",
    SIDEBAR_CONFIG: {
        "#fm-sp-mappe": {
            sidepage: "mappe",
            templateURL: "/modello_pag_listSidebar.php",
            dirName: "mappe",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: (dirName, numArg, materia, argomento, optsel, folder) => ({
                file_links: `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${materia}_${dirName}-links_${optsel}.json`,
                file_php:   `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${numArg}_${materia}-${argomento}-${optsel}.php`,
                dirfile:    `/${dirName}/${folder}/${dirName}_${optsel}/${materia}`,
            }),
            showHideLogic: true,
            PasswordSidepage: false,
        },
        "#fm-sp-lab": {
            sidepage: "lab",
            templateURL: "/modello_pag_listSidebar.php",
            dirName: "lab",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: (dirName, numArg, materia, argomento, optsel, folder) => ({
                file_links: `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${materia}_${dirName}-links_${optsel}.json`,
                file_php:   `/${dirName}/${folder}/${dirName}_${optsel}/${materia}/${numArg}_${materia}-${argomento}-${optsel}.php`,
                dirfile:    `/${dirName}/${folder}/${dirName}_${optsel}/${materia}`,
            }),
            showHideLogic: true,
            PasswordSidepage: false,
        },
        "#fm-sp-eser": {
            sidepage: "eser",
            templateURL: "/modello_pag_listSidebar.php",
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
            PasswordSidepage: false,
        },
        "#fm-sp-verif": {
            sidepage: "verif",
            templateURL: "/modello_pag_listSidebar.php",
            dirName: "eser",
            categories:   ["MAT", "GEO", "FIS"],
            IDcategories: ["#MAT", "#GEO", "#FIS"],
            pathPattern: () => ({}),
            showHideLogic: true,
            // Phase 25.B1 — PasswordSidepage:true (legacy gate per studenti)
            // disabilitato: i teacher devono accedere al loro dashboard
            // verifiche senza step di re-auth (sessione già validata).
            // Per access studenti a verifiche pubblicate, vedi
            // student-resource-auth.js (gate dedicato class-by-class).
            PasswordSidepage: false,
        },
        "#fm-sp-bes": {
            sidepage: "bes",
            templateURL: "/strcomp_bes_altro/modello_pag_listSidebar-strcomp_bes_altro.php",
            dirName: "strcomp_bes_altro",
            categories:   ["STRCOMP", "ALTRO"],
            IDcategories: ["#STRCOMP", "#ALTRO"],
            pathPattern: (dirName, numArg, category, argomento) => ({
                file_links: `/${dirName}/${category}/${category}_links.json`,
                file_php:   `/${dirName}/${category}/${numArg}_SBA-${argomento}-${category}.php`,
                dirfile:    `/${dirName}/${category}`,
            }),
            showHideLogic: true,
            PasswordSidepage: false,
        },
        // Phase 21 — #fm-sp-risdoc gestito da risdoc-sidepage.js (auth
        // server-side via Permission). Niente password sidepage + template vuoto.
        "#fm-sp-risdoc": {
            sidepage: "risdoc",
            templateURL: "",
            dirName: "risdoc",
            categories:   ["MODELLI", "RISORSE"],
            IDcategories: ["#MODELLI", "#RISORSE"],
            pathPattern: () => ({}),
            showHideLogic: false,
            PasswordSidepage: false,
        },
    },
};

window.FM = window.FM || {};
window.FM.Config = Config;
window.Config    = Config;
