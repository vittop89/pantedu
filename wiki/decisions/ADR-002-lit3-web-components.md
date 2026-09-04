---
tags:
  - documentazione/adr
date: 2026-04-23
tipo: adr
status: accettato
aliases: ["ADR-002"]
cssclasses: []
---

# ADR-002: Lit 3 Web Components su Shadow DOM per risdoc (Plan B)

**Status**: accettata

## Contesto

L'editor risdoc Plan A usava una IIFE jQuery (`risdoc.js`, 4931 LOC) che manipolava DOM globale, aveva regex inline per TeX processing, e non era testabile. Con Plan B si è rivalutato l'approccio frontend.

## Decisione

Adottare **Lit 3 Web Components** con Shadow DOM per il rendering dei form risdoc (Plan B). I componenti sono in `js/components/risdoc/fm-risdoc-*.js`. Il rendering è schema-driven: ogni sezione del form è un WC separato (`fm-risdoc-checkbox-group`, `fm-risdoc-dynamic-table`, `fm-risdoc-info-field`, ecc.).

## Motivazioni

1. **Encapsulamento Shadow DOM**: stili e logica isolati — nessun conflitto con CSS legacy.
2. **Schema-driven**: il form si costruisce automaticamente da `schemas/risdoc/*.json` senza PHP template custom per ogni documento.
3. **Riuso**: stesso WC per tutti i template risdoc.
4. **Testabilità**: WC sono testabili unitariamente (almeno in teoria — coverage attuale assente per WC).
5. **Separazione**: la logica TeX processing resta nel PHP backend (`ExportController`); il WC è solo presentazione e raccolta dati.

## Conseguenze

- **Pro**: eliminata la dipendenza da `risdoc.js` legacy per nuovi template; form schema-driven.
- **Contro**: Lit 3 richiede bundler (Vite) per ottimizzazione; non tutti i browser supportano Shadow DOM (ma target è docenti su Chrome moderno). Complexity: il WC `fm-risdoc-template.js` orchestra tutti i sub-componenti.
- **Debito** (storico, risolto): `risdoc.js` Plan A è stato **rimosso dal repo** (in git history); i template sono ora serviti dall'architettura Plan B (Web Components). La migrazione dei template residui resta tracciata altrove.

> [!info] Scelta Architetturale
> La scelta di Lit 3 vs React/Vue è stata dettata dalla natura "vanilla" del progetto (no build obbligatorio originariamente) e dalla compatibilità con jQuery esistente. Lit è standard W3C, non framework proprietario.
