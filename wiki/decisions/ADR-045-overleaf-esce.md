---
tags:
  - documentazione/adr
date: 2026-09-21
tipo: adr
status: accettato
aliases: ["ADR-045", "overleaf", "esportazione verso terzi", "compilazione esterna"]
cssclasses: []
---

# ADR-045 — Overleaf esce dall'applicazione

## Stato

**ACCETTATO** dall'utente il 21 settembre 2026, con due parole: «togli il
bottone, non serve». La domanda era nata da una sua obiezione di privacy sulle
esportazioni dei modelli risdoc, e la risposta è arrivata da una misura, non da
un'opinione.

## Il fatto misurato

Overleaf era in quattro punti, e **nessuno era vivo per un docente**.

1. **Un bottone che mentiva.** Nella barra delle verifiche c'era un comando
   visibile, cliccabile e riservato ai docenti. Premendolo diceva «Apertura
   Overleaf attiva» e metteva `aria-pressed="true"` — e non apriva niente, né
   allora né dopo. L'unico che leggeva quel valore era
   `utilities.salvaScelte`, che non è chiamata da nessuna parte da quando
   `print-export.js` è stato dismesso. Il percorso vivo del salvataggio delle
   scelte (`verifica-scelte.js`) quella chiave non la scrive nemmeno.
2. **Un modulo nascosto su ogni pagina.** `dom-manager.injectHiddenForm()`
   metteva in fondo a **ogni** pagina del sito, login compreso, un
   `<form action="https://www.overleaf.com/docs">` con dentro una textarea per
   il sorgente TeX. Nessuno la riempiva, nessuno faceva il submit. Si portava
   dietro tre errori di accessibilità (WCAG 1.3.1 F68 e 4.1.2 H91) tappati a
   mano con `aria-hidden` e `title`, perché `display:none` non basta.
3. **Un modulo intero senza chiamanti.** `integrations/overleaf-progress.js`,
   88 righe nel fascio *eager* di `bootstrap.js` — scaricate da chiunque apra
   una pagina — con zero chiamate ai suoi metodi. Serviva a `print-export.js`,
   che non esiste più.
4. **Un ramo del server tenuto in vita dal proprio test.** `mode=overleaf` in
   `ExportController` e nel gemello `ContentExportController` rispondeva con un
   `overleaf_url`. Nessun chiamante JS lo mandava: l'unico in tutto il
   repository era una spec end-to-end.

C'era anche, nella finestra GENERA, l'unico codice che avrebbe funzionato
davvero (`openInOverleaf`) — dentro una finestra che l'interfaccia non apre mai,
perché il bottone che la aprirebbe è `display:none` + `aria-hidden` +
`tabindex="-1"` e lo aziona solo la suite.

## La decisione

Overleaf esce: bottone, modulo nascosto, modulo `overleaf-progress.js`, ramo
`mode=overleaf` nei due controller, il permesso `frame-src` nella CSP (che non
serviva comunque: i vecchi percorsi aprivano una scheda, non una cornice), il
CSS e le icone. E i consigli rivolti ai docenti che nominavano Overleaf
(«genera il PDF da Overleaf, TeXworks o VSCode») restano senza Overleaf.

**Non si tocca** il valore `third_party_export` nell'enum dei consensi: è dato
salvato, la migrazione è applicata, e quel consenso serve ancora per Google
Drive. Si aggiorna solo il commento che lo descrive.

## Perché

- **Non toglie una funzione a nessuno**: toglie un'esca. Un comando che dice di
  aver fatto qualcosa e non l'ha fatta è peggio di un comando che non c'è.
- **È la stessa scelta di [[ADR-012-tex-compile-vps]]**, che nel maggio 2026
  aveva già deciso contro Overleaf come strada di compilazione (account
  richiesto al docente, quote, dipendenza da terzi) a favore del servizio TeX
  sulla propria macchina. Da allora Overleaf era un residuo, non un'alternativa.
- **Ha un peso di privacy.** Mandare a Overleaf il sorgente di un piano di
  lavoro o di una relazione significa mandare a un'azienda terza un documento
  che parla di una classe. Finché non serve a nessuno, la strada più semplice
  per non doverlo dichiarare è non averla. Questo è anche il motivo per cui la
  cosa non si «rimette al volo» se un giorno servisse: tornare a Overleaf vuol
  dire dichiararlo nell'informativa e nel registro dei trattamenti, cioè una
  decisione, non una riga di codice. Lo tiene chiuso una prova che vieta gli
  indirizzi di Overleaf nel codice servito
  (`tests/js-unit/niente-strade-verso-overleaf.test.js`).

## Conseguenze

- Chi vuole compilare fuori dalla piattaforma scarica il pacchetto ZIP e usa
  TeXworks o VSCode: è la strada che i testi dell'applicazione indicano già.
- [[ADR-010-modern-topbar]] descrive il bottone e il ponte `#overleaf`: resta
  com'è, perché racconta una decisione del 30 aprile 2026. Questo ADR la
  supera sul solo punto di Overleaf.
- Il ponte invisibile della barra conserva `#Server` e `#syncDrive`, che sono
  letti dalle stesse funzioni senza chiamanti: restano da smontare, e non è
  stato fatto qui per non allargare un lavoro nato da una domanda di privacy.

## Alternative scartate

- **Farlo funzionare**: il bottone c'era, bastava collegarlo. Scartata perché
  nessuno lo usava (non poteva: non funzionava), e perché avrebbe aperto un
  trasferimento verso terzi da dichiarare.
- **Lasciare il codice morto e togliere solo il bottone**: scartata perché il
  modulo nascosto verso overleaf.com sarebbe rimasto in fondo a ogni pagina,
  con il suo debito di accessibilità, e perché un ramo del server tenuto in
  vita dal proprio test è esattamente il genere di verde che questo progetto
  insegue.
