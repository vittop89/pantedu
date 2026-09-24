# `docs/` — indice della documentazione

Documentazione di progetto che non appartiene al codice sorgente. Per la
documentazione di runtime e di architettura del codice vedi la
**[wiki/](../wiki/)** (ingresso: [`wiki/_llm-primer.md`](../wiki/_llm-primer.md)).

## Struttura

| Cartella o file | Contenuto | Stato |
|----------|-----------|-------|
| [`INSTALL.md`](INSTALL.md) | installazione self-host **in produzione** (nginx, PHP-FPM, MariaDB, microservizio TeX, timer) | live |
| [`dev/`](dev/) | come si monta l'ambiente di **sviluppo** ([`sviluppo-in-wsl.md`](dev/sviluppo-in-wsl.md)) e la mappa della catena di integrazione e rilascio ([`ci-cd.md`](dev/ci-cd.md)) | live |
| [`SUPERADMIN.md`](SUPERADMIN.md) | creazione e ruolo del super-admin | live |
| [`ROUTES.md`](ROUTES.md) | inventario delle 532 rotte, **generato** da `tools/dev/gen_routes_md.php` | generato |
| [`SERVICES.md`](SERVICES.md) | mappa di `app/Services/` per feature | a mano |
| [`PHASES.md`](PHASES.md) | indice dei marker `Phase N` nel codice, **generato** da `tools/dev/gen_phases_md.php` | generato |
| [`VALIDATION.md`](VALIDATION.md) | convenzioni di validazione input | reference |
| [`api/`](api/) | OpenAPI 3.1 (`openapi.full.yaml`, 208 path, rigenerata il 2026-09-02) e workflow | live |
| [`legal/`](legal/) | ToS, AUP, DPA, takedown, AI Act, accessibilità; `versions.json` è il registro delle versioni con check CI | live, versionato |
| [`privacy/`](privacy/) | informativa (anche per Istituto), registro trattamenti, DPIA, runbook breach, checklist | live |
| [`dpo/`](dpo/) | pacchetto per il DPO di un istituto sui tre scenari (in parte escluso dal repo pubblico) | live |
| [`security/`](security/) | pentest (esclusi dal pubblico), runbook operativi, audit dei tool terzi, hardening CSP/CSRF | live |
| [`ops/`](ops/) | runbook operativi del VPS (in parte esclusi dal pubblico) | live |
| [`analysis/`](analysis/) | revisioni architetturali (`revisione-architetturale-AAAA-MM.md`), triage test-rot, analisi legacy | live; dati derivati esclusi dal pubblico |
| [`plans/`](plans/) | piani e roadmap di fasi passate (G23-G26, sidebar, CSS, SPID/CIE, a11y) | storico, non aggiornati |
| [`todo/`](todo/) | prompt degli audit di sicurezza, standard della wiki (`prompt_wiki_llm.md`), backlog interni | interno, escluso dal pubblico |
| [`audits/`](audits/) | audit dei flussi docente/admin del 2026-05-09 | storico |
| [`architecture/`](architecture/) | strategia di sincronizzazione (Drive/locale/GitHub) | reference |
| [`conventions/`](conventions/), [`guides/`](guides/), [`glossary/`](glossary/), [`specs/`](specs/) | naming HTML, guide git e performance, method-map dei controller grandi, spec dei blocchi PT | reference |
| [`curriculum/`](curriculum/) | `miur_alias.json`: alias delle descrizioni MIUR | live |
| [`publication/`](publication/) | procedura di pubblicazione dello snapshot (escluso dal pubblico) | interno |

## Cosa trovi dove

### Sicurezza e audit

- [`security/operations/`](security/operations/) — recovery KMS, Shamir, cooperazione con l'autorità, incident response.
- [`security/track6-7-csp-csrf-hardening.md`](security/track6-7-csp-csrf-hardening.md) — CSP a nonce e bonifica inline.
- `security/pentest/` — report 2026-04-29 e 2026-05-18 (esclusi dal pubblico).
- [`todo/prompt_security.md`](todo/prompt_security.md) — prompt degli audit assistiti (interno).

### API

- [`api/api-index.md`](api/api-index.md) — workflow OpenAPI (`composer openapi:build`, `openapi:validate`).

### Legale e privacy

- [`legal/versions.json`](legal/versions.json) — fonte di verità per versioni e date; `node tools/ci/check-legal-versions.mjs` verifica anche che le tre informative (una per scenario) dichiarino una versione coerente col corpo e stiano nell'immagine: è la versione che i consensi registrano.
- [`privacy/informativa.md`](privacy/informativa.md), [`privacy/informativa-istituto.md`](privacy/informativa-istituto.md), [`privacy/registro-trattamenti.md`](privacy/registro-trattamenti.md), [`privacy/dpia.md`](privacy/dpia.md).

### Analisi e piani

- [`analysis/revisione-architetturale-2026-09-completa.md`](analysis/revisione-architetturale-2026-09-completa.md) — revisione completa del 23/9/2026, con piano in tre fasi e confronto con quella del 4/9; la wiki si aggiorna da qui (skill `aggiorna-wiki`), e i rilievi che il suo capitolo 8.2 porta nel registro del debito sono le voci dalla 109.
- [`analysis/revisione-architetturale-2026-09-risdoc.md`](analysis/revisione-architetturale-2026-09-risdoc.md) — revisione di perimetro di `app/Services/Risdoc` (22-23/9/2026), da due letture indipendenti.
- [`analysis/revisione-architetturale-2026-09.md`](analysis/revisione-architetturale-2026-09.md) — revisione completa del 4/9/2026, la precedente.
- [`analysis/test-rot-tex-2026-08-31.md`](analysis/test-rot-tex-2026-08-31.md) — triage dei test sospesi.

## Convenzioni

- I documenti generati si correggono nel generatore, non a mano.
- I documenti legali e privacy hanno un registro di versioni proprio: non si
  modificano senza alzare la versione e rigenerare il PDF.
- Un documento «live» diventato obsoleto si elimina (la storia resta in git)
  o si marca superato in testa; `plans/` e `audits/` sono storici per definizione.
- La documentazione di architettura e le decisioni stanno nella wiki, non qui.
