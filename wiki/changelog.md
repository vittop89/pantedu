---
tags:
  - documentazione/changelog
date: 2026-09-23
tipo: changelog-index
status: finale
aliases: ["changelog"]
cssclasses: []
---

# Changelog Wiki — Index

Storico modifiche tracciato per **mese** in `wiki/changelog/YYYY-MM.md`. Un
mese lungo si divide per **settimana** (`YYYY-MM-settN.md`, lunedì-domenica):
il file del mese ne diventa l'indice. Settembre 2026 è diviso così (DOC-20).

## Mesi disponibili

- [[changelog/2026-09]] — Secondo fattore richiesto al login, recupero
  password, registro delle versioni legali, release 0.3.0 (audit append-only,
  tre utenti DB, motivazione obbligatoria), curriculum e sezioni dal dataset
  MIUR, release 0.4.0 (scenari ADR-032, credenziale di classe, hash nei
  registri, risdoc cifrato), revisione architetturale e prima fase del piano.
- [[changelog/2026-08]] — Contrasto in tema chiaro, riscrittura include TeX
  e anteprima multi-file, AI Act, suite a verde senza barare (`TEST_ROT`),
  deploy resiliente, console operativa.
- [[changelog/2026-07]] — Configurazione auto-rigenerante al boot, accesso
  SSH Zero Trust, rendering markdown delle pagine di fiducia, `release.sh`.
- [[changelog/2026-06]] — Hardening client (WAF JSON-per-XHR, CSRF
  centralizzato, de-jQuery, CSS-in-JS zero); PDF-Import a parità col legacy;
  editor risdoc (tabelle, ADR-030 valori per terna, ADR-031 formule);
  ADR-029 decomposizione dei God-controller; audit FND-008…011; accesso
  pubblico; WCAG 2.2 AA in CI; codice pubblico dal 23 giugno.
- [[changelog/2026-05]] — Phase G20.0 refactor verifiche
  multi-file (texCommon/versioni/griglie con override per istituto,
  ZIP flat vs VSC distribuito). Phase G19.48 sync triplet
  (Drive/Local/GitHub) + path mirror verifiche su Drive.
- [[changelog/2026-04]] — Phase 25 quality hardening completa
  (envelope encryption + GDPR self-service + minori + observability +
  pentest + DPA hosting). Phase 26 OpenAPI 3.1.

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
