---
tags:
  - documentazione/adr
date: 2026-04-23
tipo: adr
status: accettato
aliases: ["ADR-001"]
cssclasses: []
---

# ADR-001: MVC PHP Custom (no framework)

**Status**: accettata

## Contesto

Il progetto nasce come sito PHP legacy (file PHP, no routing). Con la modernizzazione (Phase 11+), si è valutato se adottare un framework (Laravel, Symfony, Slim) o costruire MVC custom.

## Decisione

Costruire MVC custom leggero: `Router`, `Kernel`, middleware pipeline, `View`, `Config`, `Session` — senza dipendere da un framework.

Unica dipendenza esterna PHP di runtime: `vlucas/phpdotenv` (env), `justinrainbow/json-schema` (validazione schema risdoc), `psr/log`.

## Motivazioni verificate dal codice

1. **Controllo totale**: nessuna dipendenza da versioni framework. Il codebase vive su hosting condiviso (legacy) con PHP 8.3 ma senza estensioni esotiche.
2. **Hosting constraint**: hosting legacy shared non supporta CLI Artisan, Symfony Console, ecc.
3. **Semplicità**: ~8 classi Core coprono tutto il necessario. Nessun overhead.
4. **Migrazione progressiva**: poter aggiungere feature senza breaking changes da upgrade framework.

## Conseguenze

- **Pro**: zero magic, debug lineare, deploy semplice (FTP + composer install).
- **Contro**: nessun ORM (PDO raw), nessuna DI automatica (costruttori con `new` diretti), nessun template engine (PHP puro in views/).
- **Debito**: il DI container (`app/Core/Container.php`) esiste ma non è usato pervasivamente — istanziazione manuale nei controller.
