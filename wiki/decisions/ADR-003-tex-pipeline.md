---
tags:
  - documentazione/adr
date: 2026-04-23
tipo: adr
status: accettato
aliases: ["ADR-003"]
cssclasses: []
---

# ADR-003: Pipeline TeX/pdflatex server-side per produzione PDF

**Status**: accettata con workaround

## Contesto

I documenti risdoc devono essere consegnati come PDF formali (piani annuali, relazioni finali, ecc.). I template `.tex` legacy sono strutturati per pdflatex con stile `risdoc.sty`, intestazione IIS, e immagini (logo scuola, stemma). L'alternativa sarebbe generare PDF via HTML/CSS (es. WeasyPrint) o wkhtmltopdf.

## Decisione

Mantenere la **pipeline pdflatex server-side** come standard. Il server PHP esegue `pdflatex` su file temporanei e restituisce il PDF al client.

**Workaround per hosting legacy**: su hosting condiviso hosting legacy (dove pdflatex non è installabile), il server genera un **ZIP** contenente `main.tex + doc.tex + risdoc.sty + images` e lo serve via URL. Il client può poi:
1. Scaricare lo ZIP e compilare localmente con MiKTeX/TexLive.
2. Aprire in Overleaf via `snip_uri` (upload automatico da URL).

## Motivazioni

1. **Qualità tipografica**: pdflatex produce PDF di qualità professionale con microtype, matematica, tabelle complesse.
2. **Template esistenti**: centinaia di `.tex` già sviluppati e validati. Riscrivere in HTML/CSS non è fattibile.
3. **Dipendenze TeX**: `risdoc.sty`, `intestaLAteX_IIS.tex`, immagini — già presenti in `storage/templates/risdoc/texCommon/`.

## Conseguenze

- **Pro**: qualità PDF alta; template riusabili; nessuna riscrittura HTML/CSS.
- **Contro**: dipendenza da pdflatex installato sul server; non disponibile su hosting condiviso shared. Il workaround ZIP/Overleaf introduce un passo manuale per l'utente.
- **Zone protette**: i file `texCommon/risdoc.sty`, `texCommon/main.tex`, `texCommon/intestaLAteX_IIS.tex` non devono essere modificati senza test di compilazione. La classe `TexBuilder::esc()` è il layer di sicurezza contro LaTeX injection.
- **Test**: `tests/e2e/risdoc_tex_production.spec.js` verifica che 7 template producano PDF validi con pdflatex locale.
