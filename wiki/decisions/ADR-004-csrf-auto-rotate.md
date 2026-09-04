---
tags:
  - documentazione/adr
date: 2026-04-23
tipo: adr
status: accettato
aliases: ["ADR-004"]
cssclasses: []
---

# ADR-004: CSRF Auto-rotate su TTL

**Status**: accettata

## Contesto

La gestione CSRF richiedeva una scelta tra: token per-form (single-use), token per-sessione, o token TTL-based (rigenerato alla scadenza).

## Decisione

**Token TTL-based** conservato in sessione. Il token viene rigenerato automaticamente solo quando:
1. Non esiste ancora in sessione.
2. Il TTL (`CSRF_TOKEN_LIFETIME`, default 7200s) è scaduto.

Non è single-use: lo stesso token è valido per tutte le richieste entro il TTL. Rotazione manuale via `Csrf::rotate()` quando necessario.

Implementazione: `app/Core/Csrf.php` — ~30 LOC, puro PHP session.

## Motivazioni

1. **Semplicità**: nessun DB o store separato per tracciare token usati.
2. **UX**: evita invalidazioni al back-button del browser (problema comune con single-use).
3. **Hosting**: soluzione stateless su sessione PHP, funziona su hosting condiviso shared senza Redis.
4. **Sufficiente per il threat model**: il rischio principale è CSRF da siti terzi, mitigato dal SameSite=Lax cookie. Il replay attack entro TTL è accettato.

## Conseguenze

- **Pro**: semplice, nessuna dipendenza, UX fluida.
- **Contro**: non single-use → possibile replay attack entro 7200s se il token viene intercettato (improbabile con HTTPS + SameSite).
- **Debito**: documentato in [[technical-debt]] #7. La migrazione a single-use richiede store (Redis/DB) per tracciare token usati.
