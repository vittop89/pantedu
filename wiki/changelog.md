---
tags:
  - documentazione/changelog
date: 2026-04-29
tipo: changelog-index
status: finale
aliases: ["changelog"]
cssclasses: []
---

# Changelog Wiki — Index

Storico modifiche tracciato per **mese** in `wiki/changelog/YYYY-MM.md`.

## Mesi disponibili

- [2026-06](changelog/2026-06.md) — Hardening client: WAF JSON-per-XHR,
  CSRF centralizzato (fonte unica `dom-utils.fetchCsrf`), de-jQuery completo
  (rimosso shim `ajax-compat`), CSS-in-JS azzerato (ADR-023 F5), fix profilo
  docente + componente autocomplete, audit `/admin`.
- [2026-05](changelog/2026-05.md) — Phase G20.0 refactor verifiche
  multi-file (texCommon/versioni/griglie con override per istituto,
  ZIP flat vs VSC distribuito). Phase G19.48 sync triplet
  (Drive/Local/GitHub) + path mirror verifiche su Drive.
- [2026-04](changelog/2026-04.md) — Phase 25 quality hardening completa
  (envelope encryption + GDPR self-service + minori + observability +
  pentest + DPA Aruba). Phase 26 OpenAPI 3.1.

## Convenzioni

- **Header H2** = una entry, formato `## YYYY-MM-DD — <Phase> <titolo>`
- **Granularità**: una entry per cambiamento atomico (1 commit o 1 PR)
- **Trigger**: causale sintetica (perché è stata fatta la modifica)
- **Linkare**: file modificati, ADR, ticket. Per riferimenti a codice
  sorgente usa `app/path/file.php` come testo, non come link wiki
  (vedi convenzioni in [_llm-primer](_llm-primer.md))

## Quando aggiungere entry

Vedi tabella in [_llm-primer](_llm-primer.md) "WIKI MAINTENANCE":
ogni cambiamento di route/schema/dominio/architettura richiede entry
nel changelog del mese corrente.

## Trigger nuovo mese

Quando arriva un nuovo mese:
1. Crea `wiki/changelog/YYYY-MM.md` con frontmatter (copia da 2026-04)
2. Aggiungi link in questo file sopra
3. Scrivi entry nel mensile, NON in questo index

Mai più scrivere entry in `wiki/changelog.md` — è solo dispatcher.
