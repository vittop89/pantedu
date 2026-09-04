/**
 * Endpoints centralizzati — URL canonici per ogni API server.
 *
 * Phase 18 — rimossi endpoint legacy filesystem:
 *   - exercises.{saveNew,duplicateCollex,ensureCollexIds,cloneCollex,countCollex}
 *   - editor.{saveRevision,loadRevision,listRevisions,saveSnapshot}
 *   - verifiche.listFolders
 *   - update.{file,table,dsa,dsaCheckbox,origins}
 *   - create.{file,verFile}
 *   - links.{check,save,updateOrigins}
 *
 * Il nuovo flusso CRUD contract-based vive su /api/teacher/content/*.
 */

export const Endpoints = {
    auth: {
        login:         "/login",
        logout:        "/logout",
        csrf:          "/auth/csrf",
        userInfo:      "/auth/user-info",
        register:      "/register",
        checkPassword: "/check/password",
    },
    files: {
        saveTex:      "/files/save-tex",
        saveLatex:    "/files/save-latex",
        saveImage:    "/files/save-image",
        savePdf:      "/files/save-pdf",
        deleteFile:   "/files/delete",
        deleteFolder: "/files/delete-folder",
        deleteTemp:   "/files/clear-temp",
        list:         "/files/list",
    },
    exercises: {
        // @deprecated G22.S15.bis Fase 5+ (PROBLEM-16) — zero callers UI moderna.
        // Sostituito da /api/study/content.json + /api/teacher/content.
        // Verra' rimosso in Phase 26 col drop tabella `exercises`.
        searchJson: "/exercises/search.json",
        // Phase 18 — rimossi: usa teacherContent.* endpoints
        saveNew:         null,
        duplicateCollex: null,
        ensureCollexIds: null,
        cloneCollex:     null,
        countCollex:     null,
    },
    tikz: {
        saveSvg:        "/tikz/save-svg",
        saveNewElement: "/tikz/save-new-element",
        editElement:    "/tikz/edit-element",
        deleteElement:  "/tikz/delete-element",
        getContent:     "/tikz/content",
        ensureJson:     "/tikz/ensure-json",
        generateJson:   "/tikz/generate-json",
    },
    verifiche: {
        saveScelte:      "/verifiche/scelte",
        managePrintInfo: "/verifiche/print-info",
        listFolders:     null, // Phase 18: usa study.topicsJson
    },
    // Phase 18 — legacy editor/update/create/links backend rimossi.
    // Endpoints esposti come null: i caller legacy (table-manager,
    // editor-system, event-handler, batch-delete) devono migrare a
    // teacherContent.* + study.*. I fetch su null URL falliscono
    // con TypeError catturato dagli error handler esistenti.
    editor: { saveRevision: null, loadRevision: null, listRevisions: null, saveSnapshot: null },
    update: { file: null, table: null, dsa: null, dsaCheckbox: null, origins: null },
    create: { file: null, verFile: null },
    check: {
        password:       "/check/password",
        fileProtection: "/check/file-protection",
    },
    // Phase 19 — endpoint probe no-op per health check + test CSRF/rate
    probe: "/api/probe",
    // Phase 18 — CRUD content-based: usare queste URL builders.
    teacherContent: {
        quesitoPatch:       (id, ref) => `/api/teacher/content/${id}/quesito/${encodeURIComponent(ref)}/patch`,
        quesitoDelete:      (id, ref) => `/api/teacher/content/${id}/quesito/${encodeURIComponent(ref)}/delete`,
        quesitoMove:        (id, ref) => `/api/teacher/content/${id}/quesito/${encodeURIComponent(ref)}/move`,
        quesitoDuplicate:   (id, ref) => `/api/teacher/content/${id}/quesito/${encodeURIComponent(ref)}/duplicate`,
        quesitoCloneToEser: (id, ref) => `/api/teacher/content/${id}/quesito/${encodeURIComponent(ref)}/clone-to-eser`,
        groupMove:          (id, gref) => `/api/teacher/content/${id}/group/${encodeURIComponent(gref)}/move`,
        sourcesJson:        "/api/teacher/sources.json",
        headerPageJson:     "/api/teacher/header-page.json",
        originsJson:        "/api/teacher/origins.json",
    },
    study: {
        topicsJson:  "/api/study/topics.json",
        contentJson: "/api/study/content.json",
    },
    admin: {
        accessLog:           "/admin/access-log",
        accessStats:         "/admin/access-stats",
        debugLog:            "/admin/debug-log",
        whoami:              "/admin/whoami",
        dashboard:           "/admin/dashboard",
        registrations:       "/admin/registrations",
        approveRegistration: (id) => `/admin/registrations/${encodeURIComponent(id)}/approve`,
        rejectRegistration:  (id) => `/admin/registrations/${encodeURIComponent(id)}/reject`,
        generateHash:        "/admin/generate-hash",
        curriculum:          "/curriculum",
        curriculumAdd:       (kind) => `/admin/curriculum/${encodeURIComponent(kind)}`,
        curriculumUpdate:    (kind, code) => `/admin/curriculum/${encodeURIComponent(kind)}/${encodeURIComponent(code)}/update`,
        curriculumRemove:    (kind, code) => `/admin/curriculum/${encodeURIComponent(kind)}/${encodeURIComponent(code)}/remove`,
        print:               "/admin/print",
        printBatch:          "/admin/print/batch",
    },
    teacher: {
        dashboard:   "/area-docente/dashboard",
        resources:   "/area-docente/resources",
        print:       "/teacher/print",
        // G22.S15.bis Fase 5+ — rimossi M11: verifiche, cloneExercise.
        // Sostituiti da /api/teacher/content + /api/verifica/{id}/tex.
    },
    analytics: {
        nav: "/analytics/nav",
    },
    templates: {
        // Phase 20 — dismissi: modelliEser/modelliTikz/pagEsercizi/pagEserciziVer.
        //   - modelliEser: sostituito da server-render (group/add + ContractRenderer)
        //   - modelliTikz: source-of-truth ora è modelli_tikz*.json
        //   - pagEsercizi / pagEserciziVer: usati solo da Api.createFile
        //     deprecato Phase 18 (reject immediato).
        pagListSidebar: "/modello_pag_listSidebar.php",
        upBarEs:        "/UpBar_Es.html",
        upBarEsLoader:  "/UpBar_Es_loader.php",
    },
};

window.FM = window.FM || {};
window.FM.Endpoints = Endpoints;
