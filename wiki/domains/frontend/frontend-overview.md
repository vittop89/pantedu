---
tags:
  - documentazione/architettura
  - dominio/frontend
date: 2026-09-23
tipo: architettura
status: finale
aliases: ["frontend", "javascript", "ui"]
cssclasses: []
---

# Dominio: frontend

> [!abstract] Scopo
> Layer UI: moduli ES vanilla per sidebar, editor esercizi, verifiche, admin; Web Component Lit 3 per i documenti PT e risdoc; navigazione SPA leggera; build Vite.

## Architettura frontend

| Stack | Scope | Entry |
|-------|-------|-------|
| Moduli ES vanilla (zero jQuery dal 2026-06-02) | sidebar, studio, editor esercizi, verifiche, mappe, admin | `js/modules/bootstrap.js` |
| Web Component Lit 3 | documenti PT (`<fm-pt-document>`), risdoc, topbar documento | `js/components/**` (lazy via entry Vite) |
| Vite 8 | entry multiple, chunk hashati in `public/build/assets`, manifest letto da `ViteManifest::script()` (elenco: `vite.config.js`, `input: {...}`) | `vite.config.js` |
| CSS | ITCSS + BEM `fm-*`, `@layer` deterministico (ADR-023), bundle `css/main.bundle.css` generato al deploy; niente CSS iniettato da JS (`npm run css:no-injection`) e il CSS in linea di viste e classi PHP che non cresce (`CssNelleVisteTest`, dal 23/9/2026: vedi ADR-023) | `css/`, `css/modules/README.md` |

Lit (`lit@3`), pdf.js, pako e MathJax sono dipendenze `package.json` e
stanno nel bundle Vite, come Tiptap 3.31 e CodeMirror 6
([[technical-debt]] voce 16, chiusa il 2026-09-04). Restano da CDN solo
GeoGebra (dichiarato) e il viewer diagrams.net delle mappe.

## Struttura `js/modules/`

```
js/modules/
├── bootstrap.js            ← entry: importa e inizializza i moduli
├── bootstrap-compat.js     ← compat per i consumer legacy (window.FM.*)
├── core/                   ← api.js (fetch), declarative.js (attributi data-fm-*), dom-utils.js (escHtml, wafFetch, fetchCsrf, assertJson),
│                             carica-entry.js (entry Vite a richiesta),
│                             endpoints.js, app-state.js, config.js (SIDEBAR_CONFIG legacy), curriculum-codes.js,
│                             sidebar-cascade.js, dom-block-extractor.js, cookie-consent.js, audit-reason.js, teacher-caps.js
├── editor/                 ← editor-system.js e livelli (ADR-016), tex-dropdown/, tikz-templates/, tikz-render-client.js
├── features/               ← checkin-handlers.js, topbar-modern.js, sidepage-*, db-sidepage.js, risdoc-sidepage.js,
│                             section-edit-mode.js, verifica-*.js, drive-sync-buttons.js, drawio-editor.js, admin-*.js, share/
├── integrations/           ← google-apps.js (sidebar, selettori), google-drive-latex-saver.js
├── pdf-import/             ← api.js, review-table.js, key-modal.js, poll.js, side-viewer.js
├── perf/                   ← sw-register.js
├── print/                  ← print-info.js, print-client.js, verifiche-print-ui.js
├── render/                 ← rm-table-view.js
├── risdoc/                 ← model-sync.js, pt/ (pm-schema.js, pt-to-html.js, section-to-pt.js, formula-engine.js,
│                             terna-binding.js, pm-pt-converter.js, html-sanitizer.js)
├── security/               ← html-sanitize-client.js, tikz-geogebra-client-sanitize.js
├── selection/, state/, ui/ ← selezione, state-manager, clone-manager, ui-comp.js, dom-manager.js, toast, autocomplete, fm-dialog
└── a11y/                   ← form-labels.js
```

Moduli più grandi: `features/checkin-handlers.js`, `risdoc/pt/pm-schema.js`,
`ui/ui-comp.js` (potato il 23/9/2026), `editor/editor-system.js` — le righe
di oggi, sorvegliate da un cricchetto che fallisce se crescono, sono in
`tools/ci/dimensioni.json` ([[technical-debt]] voce 18).

## Un canale solo (R-13, 23/9/2026)

Cinque cose che il JavaScript fa in un posto solo. Dove una regola ESLint o
una prova lo tiene fermo, è scritto accanto.

| che cosa | dove | chi lo tiene fermo |
|---|---|---|
| la rete verso il PHP | `wafFetch` (o `fetchJson`) di `core/dom-utils.js`: su un 403 `waf_challenge` risolve la sfida e ripete la richiesta una volta. `Api` (`core/api.js`) e `fetchCsrf` ci passano | `no-restricted-syntax` sulla `fetch` diretta nei file già passati (`CLIENT_CHE_RISOLVONO_LA_SFIDA` in `eslint.config.mjs`); `sfida-del-waf-nei-client.test.js` |
| l'escape HTML | `escHtml` (alias `esc`, `escAttr`) di `core/dom-utils.js`: null e undefined → stringa vuota, lo zero è «0», codificati `& < > " '`. `editor/html-text-utils.js` lo riesporta, `escHtmlStrict` cambia solo l'apostrofo | `no-restricted-syntax` su funzioni chiamate come l'escape e sulla tabella `"&" → "&amp;"` scritta a mano, fuori da `dom-utils.js`; `un-solo-escape.test.js` |
| un'entry Vite a richiesta (un editor, un dialogo) | `caricaEntry(sorgente, { pronto })` di `core/carica-entry.js`: manifest fresco, una richiesta per entry, e un errore scritto in console e nel pannello | `carica-entry.test.js`, anche che solo lui nomini `/build/manifest.json` |
| il dialogo di conferma scuro | `confirmDialog` di `editor/tex-dropdown-helpers.js` | `dialogo-di-conferma.test.js` |
| il tema all'avvio | `js/tema-iniziale.js`: la scelta salvata, poi la preferenza del sistema, poi scuro. Il PHP lo mette in linea nel `<head>` (`App\Support\TemaIniziale::script()`), l'app lo importa; gli altri leggono `html[data-theme]` o `window.fmTemaScuro()` | `tema-all-avvio.test.js`, `TemaInizialeTest` |

I moduli di una pagina arrivano dal bundle, dalla sua entry Vite, e non come
`<script>` che punta a `/js/`: quelli che restano (il ripiego «manifest
assente», `teacher-templates.js`, gli script classici) sono censiti in
`moduli-dal-bundle.test.js`, ognuno con il suo perché.

Il registro `window.FM` (circa 135 chiavi) resta, ma ogni chiave la scrive un
file solo, di solito il modulo che la definisce quando viene valutato;
`bootstrap.js` registra solo quello che non si registra da sé
(`registro-window-fm.test.js`).

## Web Component (`js/components/`)

| Componente | File | Funzione |
|-----------|------|---------|
| `fm-pt-document` | `pt-document/fm-pt-document.js` | documento PT unificato (ADR-022/024/026): monta le sezioni, topbar, salvataggio; adapter `risdoc-template-adapter.js`, `teacher-content-adapter.js` |
| `fm-doc-topbar` | `doc-topbar/fm-doc-topbar.js` | topbar del documento (TeX/PDF, modal, render mode) |
| `fm-risdoc-pt-editor`, `fm-risdoc-pt-toolbar`, `fm-risdoc-pt-section` | `risdoc/` | editor Tiptap dei blocchi PT (tabelle, formule ADR-031, valori per terna ADR-030), toolbar sticky, card sezione |
| `fm-risdoc-checkbox-group`, `fm-risdoc-form-checkbox`, `fm-risdoc-dynamic-table`, `fm-risdoc-info-field`, `fm-risdoc-nota-textarea`, `fm-risdoc-nota-pt-rich`, `fm-risdoc-grade-selector`, `fm-risdoc-giudizio-group`, `fm-risdoc-giudizio-item`, `fm-risdoc-glossary-table`, `fm-risdoc-signature-block`, `fm-risdoc-privacy-block`, `fm-risdoc-section-header`, `fm-risdoc-section-navigator`, `fm-risdoc-static-content`, `fm-risdoc-text-section`, `fm-risdoc-images-manager` | `risdoc/` | widget dei modelli |
| `_options-fetcher.js`, `_pt-loader.js`, `index.js` | `risdoc/` | fetch delle sorgenti opzioni, caricamento lazy, registrazione |

`fm-risdoc-template` e `fm-risdoc-export` (aprile) non esistono più: il
rendering dei modelli passa da `<fm-pt-document>` (ADR-026).

## Sidebar / Sidepage (ADR-027)

Sei pulsanti `.fm-sb-sec[data-sidepage="<key>"]` → sei pannelli, popolati da
due loader; le sezioni arrivano dal DB (`sidebar_sections`) via
`GET /api/sidebar/config`, con visibilità per ruolo, docenti assegnati,
pubblicazione al super-admin e categorie custom.

| key | loader | type | group | note |
|-----|--------|------|-------|------|
| `mappe`, `lab`, `eser` | `db` | `mappa`, `lab`, `esercizio` | subject | `GET /api/study/content.json` |
| `verif` | `db` | `verifica` | subject | per categoria (`/api/teacher/content`, categorie custom) se la configurazione dice `category` |
| `bes`, `risdoc` | `risdoc` | `bes`, `risdoc` | category | `GET /api/risdoc/templates`, `/api/risdoc/teacher/instances`, `/api/teacher/content`; fork delle istanze |

Registry: `js/modules/features/sidepage-registry.js`; categorie custom in
`sidepage-custom-categories.js`; azioni inline in `section-edit-mode.js`; i
selettori indirizzo/classe/materia seguono l'istituto attivo
(`core/sidebar-cascade.js`, `core/curriculum-codes.js` dal catalogo
`/curriculum`).

Il raggruppamento vero lo dice la configurazione (`group_mode` in
`sidebar_sections`; in sviluppo e in produzione `verif` è per materia, la
migrazione 070 lo seminava per categoria). La base del registro serve solo
prima che arrivi: all'evento `fm:sidebar-config-hydrated` `db-sidepage.js`
ridisegna i pannelli già disegnati con un raggruppamento diverso
(`pannelliDaRidisegnare`, 19/9/2026).

### Il 📥 e il modale ✎

- **Il 📥 (ZIP TeX)** compare su una voce con `data-has-body-pt="1"`. Il valore
  lo calcola solo il server, con una regola sola (`App\Support\RigheDellaBarra`):
  c'è un corpo da scaricare se il contenuto non è una mappa e il suo `body_pt`
  non è un segnaposto (solo titoli di sezione e blocchi vuoti, come il seme di
  «Stile esercizi»). Lo mandano `/api/study/content.json`,
  `/api/public/study/content.json`, `/api/teacher/content?with_metadata=1` e le
  risposte di creazione e salvataggio; lo leggono tutti e due i loader. Il
  pulsante si chiama «Scarica ZIP TeX «titolo»».
- **Il modale ✎ in modifica** manda `metadata_patch`, le sole chiavi che
  l'utente ha cambiato nel modulo (`null` = togli); il server le fonde con i
  metadati salvati (`App\Domain\MetadatiDelContenuto`). Non manda mai i
  metadati interi, non scrive il modello (`layout`) e non semina `body_pt`: il
  seme vale solo in creazione. `metadata`, che sostituisce tutto, resta per chi
  ha in mano i metadati interi (l'editor del documento,
  `TeacherContentAdapter._patchMeta`). Una mappa non accetta `layout`,
  `body_pt` né `doc_roles` da nessuna delle due strade; se quello che le si
  toglie è un corpo diverso dal seme, resta una riga nel registro delle
  anomalie (vedi [[mappe-overview]]).
- **La categoria** in modifica viaggia in un campo nascosto che nasce dalla
  categoria salvata, e finisce nella patch solo se qualcosa ce ne scrive una
  diversa: il confronto è con quella salvata, non con il `defaultValue` del
  campo, che è la stessa stringa e non cambia mai.

## Routing SPA leggero

| File | Funzione |
|------|---------|
| `js/fm-router.js` | navigazione parziale (`X-Partial: 1`), `data-full-reload` per logout e pagine auth-dipendenti. Con `X-Partial` (e con `?embed=1`) `views/layout/app.php` rende il solo contenuto e non calcola istituto, vocabolario né servizi della barra: dal 23/9/2026 li calcola solo per la cornice intera (A-33, `LayoutModesTest`) |
| `js/fm-url-state.js` | stato in URL (selettori) |
| `public/sw.js` | service worker: cache-first per gli asset, network-first per le API e le pagine, network-only per quelle auth-dipendenti; che cosa conserva sta qui sotto |

### Il service worker: che cosa conserva

Dal 23/9/2026 la regola è una sola, in `siPuoConservare` di `public/sw.js`, e
vale sia quando una risposta entra in cache sia quando se ne consegna una
copia, in tutte e tre le strategie:

- mai una risposta non riuscita (500, risposte opache) né una che il server
  marca `Cache-Control: no-store`;
- **sotto `/api/`, solo il JSON che il server dichiara `public`**, e mai se
  dice anche `private`. Tutto il resto va in rete; senza rete, e senza una
  copia così, la richiesta riceve un 503 con corpo JSON. Oggi nessuna API si
  dichiara `public`, quindi la cache delle API resta vuota, ed è voluto: i
  JSON dei docenti (`private, max-age=…` con ETag, lo studio,
  `/api/sidebar/config`) non si conservano, perché una copia vecchia è quella
  da cui un client riscrive perdendo l'ultimo salvataggio. Una rotta che deve
  funzionare offline lo dichiara dal server con `public`, e solo se il suo
  contenuto non è di nessuno in particolare. Vale per **ogni** percorso sotto
  `/api/`, anche quelli che finiscono con un'estensione da asset (il QR di una
  credenziale, `…/qr.svg`; i file dei modelli condivisi,
  `/api/risdoc/shared/*.js|css`): i rami di `/api/` vengono prima di quello
  degli asset, e una copia di quei percorsi già nella cache statica non si
  consegna;
- pagine HTML e asset statici fuori da `/api/`: tutto ciò che non è `no-store`;
- mai `/accesso-classe*`, `/api/admin/*` e gli altri percorsi di
  `NEVER_CACHE_PATHS`.

Le cache di pagine e API si buttano al logout, alla sessione scaduta e su ogni
pagina resa per un ospite: lo decide `avvia` (con `vaPulitaLaCache`) in
`js/modules/perf/sw-register.js`, che `bootstrap.js` chiama a ogni pagina.
Tutto questo lo sorveglia `tests/js-unit/service-worker-percorsi.test.js`, e
ESLint legge `public/sw.js` con `no-control-regex` (il difetto dell'8/9 erano
due backspace veri in un'espressione).

## Entry Vite

Elenco completo per gruppo, con l'uso di ciascuna: [[entrypoints]] § Vite
(fonte vera: `vite.config.js`, `input: {...}`). Le viste caricano gli entry
via `ViteManifest::script()`; senza manifest (dev senza build) `head.php`
carica `js/modules/bootstrap.js` sorgente, ma gli entry con dipendenze npm
(Tiptap, CodeMirror) richiedono il build.

## Script inline nelle viste

Il 2026-09-04 (revisione architetturale, intervento P8) il JavaScript delle
sette viste più grandi è passato in entry Vite sotto `js/entries/`
(`area-docente-templates`, `area-docente-profilo`, `area-docente-fonti`,
`auth-register`, `admin-waf-blocks`, `admin-logs`, `admin-sections`): i
valori del server arrivano via attributi `data-*` o blocchi JSON, i
listener sono nel modulo, e `js/entries/**` è nel perimetro di ESLint. Il
2026-09-05 sono passate altre viste (voce 19 del debito, chiusa): oggi resta
script inline solo in cinque partial (`views/admin/delete_temp.html`,
`views/admin/system/deployment.php`, `views/admin/waf/config.php`,
`views/area_docente/templates.php`, `views/partials/sidebar.php`), con
nonce, e il CSP di produzione è `strict` dal pannello WAF
([[technical-debt]] voce 19).

## Test

Vitest (`tests/js-unit/`): `rm-layout-model`, `sidebar-cascade`,
`conflict-resolver`, `html-text-utils`, `audit-reason`,
`tikz-svg-id-isolation`. Il resto è E2E ([[testing]]).

## Link correlati

[[domains/esercizi/editor-architecture]] · [[domains/risdoc/risdoc-overview]] · [[decisions/ADR-002-lit3-web-components]] · [[decisions/ADR-016-editor-modular-architecture]] · [[decisions/ADR-022-fm-pt-document-unified]] · [[decisions/ADR-023-deterministic-css-cascade]] · [[decisions/ADR-027-dynamic-sidebar-config]]
