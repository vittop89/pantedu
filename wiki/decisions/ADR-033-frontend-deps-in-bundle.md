---
tags:
  - documentazione/adr
  - dominio/frontend
date: 2026-09-04
tipo: adr
status: accettato
aliases: ["ADR-033", "librerie frontend nel bundle", "no cdn"]
cssclasses: []
---

# ADR-033 — Le librerie frontend vengono da npm e stanno nel bundle, non da CDN a runtime

**Status**: accettato (2026-09-04). Estende [[decisions/ADR-002-lit3-web-components]].

## Contesto

Fino al 4 settembre 2026 quattro librerie arrivavano al browser da CDN
esterni a runtime: Lit 3 (`lit@3`, importato da 21 componenti), pdf.js
(due entry), pako (anteprima verifica) e MathJax 4 (esercizi, PDF-Import),
più MathJax 2.7.7 e jszip in una vista morta. `lit` non era in
`package.json`; Vite lasciava gli import assoluti fuori dal bundle. Le
conseguenze: nessun fissaggio di versione oltre la major, nessuna
integrità, un host terzo necessario per rendere una formula o aprire un
documento, il CSP costretto ad autorizzare jsdelivr e cdnjs per script,
stili, font e fetch. Contraddiceva «nessuna dipendenza SaaS» dichiarata in
README e `publiccode.yml` e riapriva la classe di rischio vista con
polyfill.io (revisione architetturale 2026-09, rilievo A4; debito 16).

## Decisione

1. Ogni libreria frontend è una dipendenza in `package.json` con versione
   fissata dal lock: `lit`, `pdfjs-dist` (4.7.76, la stessa già in uso),
   `pako` (2.1.0), `mathjax` e `@mathjax/mathjax-stix2-font` (4.1.3).
2. Ciò che è un modulo ES entra nel bundle Vite: i componenti importano
   `lit` con specifier bare; pdf.js e pako sono `import()` dinamici; il
   worker di pdf.js è un asset emesso (`?url`). I Web Component risdoc e
   la shell `fm-pt-document` sono entry Vite (`risdoc-components`,
   `pt-document`) e le pagine li caricano con `ViteManifest::script()`,
   non più come moduli sorgente.
3. Ciò che il browser carica come script classico (MathJax e il suo font)
   viene copiato da `node_modules` in `public/vendor/` dallo script
   `tools/build/vendor-assets.mjs`, eseguito come `prebuild` di
   `npm run build` (quindi nel deploy). `loader.paths.fonts` punta al
   vendor locale; le cartelle generate sono ignorate da git.
4. Il CSP non autorizza più jsdelivr, cdnjs, quilljs e Google Fonts:
   restano solo gli host di GeoGebra (applet e asset propri) e i frame di
   drawio, Overleaf e Drive.
5. `bootstrap.js` importa `fm-pt-document` in modo dinamico: senza bundle
   (moduli sorgente in sviluppo) l'errore resta un avviso invece di far
   cadere tutto il bootstrap.

## Conseguenze

- Versioni e integrità sotto controllo del repository; nessuna richiesta
  verso terzi per Lit, pdf.js, pako, MathJax (verificato con Playwright:
  zero richieste verso CDN sulle pagine docente, admin e risdoc).
- `npm run build` è un requisito anche per la vista risdoc e la preview
  admin, che prima funzionavano con i sorgenti: senza manifest
  `ViteManifest::script()` ricade sul path sorgente e Lit non si risolve.
  `npm run dev` (Vite dev server, `APP_VITE_DEV=true`) resta la via per
  lavorare senza build.
- `public/vendor` cresce di circa 25 MB per deploy (MathJax e font).
- I 15 file HTML legacy in `storage/objects` che citano jQuery e MathJax 3
  da CDN non sono pagine dell'applicazione: restano come contenuti
  pre-Phase 18 già convertiti in contract.
- La vista `views/risdoc/view.php`, non resa da alcun controller, è stata
  rimossa insieme ai suoi script da cdnjs.
