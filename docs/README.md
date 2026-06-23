# `docs/` — indice della documentazione

Documentazione di progetto organizzata per area tematica. Questa cartella contiene **tutti i documenti progettuali** che non appartengono al codice sorgente. Per la documentazione di runtime e di architettura del codice vedi la **[wiki/](../wiki/)**.

## Struttura

| Cartella | Contenuto | Stato |
|----------|-----------|-------|
| [`api/`](api/) | OpenAPI 3.1 specification (146 paths / 187 ops) e workflow auto-extract + manual overlay | live |
| [`conventions/`](conventions/) | Convenzioni di codice, naming, pattern adottati nel progetto | reference, da seguire |
| [`guides/`](guides/) | Guide rapide operative (git workflow, ecc.) | reference |
| [`privacy/`](privacy/) | Documentazione GDPR: informativa, registro trattamenti, DPIA, breach runbook, compliance checklist | live, da aggiornare ai cambi normativi |
| [`security/`](security/) | Audit di sicurezza, operations docs (KMS recovery), pentest deliverable | live |
| [`todo/`](todo/) | Backlog operativo: prompt template, working tracker remediation post-audit | live, da aggiornare durante esecuzione |

## Cosa trovi dove

### Sicurezza e audit

- [`security/pentest/2026-04-29/`](security/pentest/2026-04-29/) — ultimo audit completo AI-assisted whitebox + DAST autorizzato. Sostituisce baseline `pentest-2026-04` (rimossa). Deliverable PDF firmato PAdES BES + TSA AgID Aruba: `report-final-signed.pdf` (sha256:`364409f1...`)
- [`security/operations/kms-recovery.md`](security/operations/kms-recovery.md) — procedura di recupero KMS_MASTER_KEY in caso di emergenza
- [`todo/REMEDIATION-pentest-2026-04-29.md`](todo/REMEDIATION-pentest-2026-04-29.md) — working backlog post-audit con step concreti P0-P3
- [`todo/prompt_security.md`](todo/prompt_security.md) — prompt template per audit AI-assisted (versione 1.1, con lessons learned dal pentest 2026-04-29)

### API

- [`api/README.md`](api/README.md) — workflow OpenAPI 3.1 (`generate_openapi.php` + `merge_openapi.php` + overlay manuale)
- `api/openapi.yaml` — scheletro auto-generato
- `api/openapi.overlay.yaml` — annotazioni manuali sui ~30 endpoint critici
- `api/openapi.full.yaml` — spec finale (input per Swagger UI / Redoc)

### Privacy GDPR

- [`privacy/informativa.md`](privacy/informativa.md) — informativa privacy in lingua italiana
- [`privacy/registro-trattamenti.md`](privacy/registro-trattamenti.md) — registro trattamenti Art. 30 GDPR
- [`privacy/dpia.md`](privacy/dpia.md) — Data Protection Impact Assessment
- [`privacy/data_breach_runbook.md`](privacy/data_breach_runbook.md) — procedura runbook breach Art. 33-34
- [`privacy/breach_notification_template.md`](privacy/breach_notification_template.md) — template notifica al Garante
- [`privacy/compliance_checklist.md`](privacy/compliance_checklist.md) — checklist compliance per release

### Convenzioni e guide

- [`conventions/html-naming-conventions.md`](conventions/html-naming-conventions.md) — naming HTML (classi + ID), riferimento per refactor
- [`guides/git-branch-guida.md`](guides/git-branch-guida.md) — comandi git essenziali da VS Code

### Storia archiviata

I documenti storici di fasi completate (roadmap Phase 25, refactor Phase 1-22,
risdoc modernization, threat model Phase 18, prompt history) **non sono più
versionati** in `docs/`: il loro contenuto resta consultabile nella **history
git** del repository.

## Convenzioni di organizzazione

I documenti seguono questa logica di posizione:

- **Live e da aggiornare**: `api/`, `privacy/`, `security/`, `todo/`, `conventions/`, `guides/`
- **Working in progress**: `todo/`

Quando un documento "live" diventa obsoleto, viene **eliminato** (il contesto
storico resta nella history git) per mantenere `docs/` snello e coerente.

## Riferimenti incrociati

Per documentazione di runtime/architettura del codice vedi la **wiki**:

- [`wiki/_llm-primer.md`](../wiki/_llm-primer.md) — overview stack + pattern (10 righe)
- [`wiki/architecture.md`](../wiki/architecture.md) — architettura applicativa
- [`wiki/security-notes.md`](../wiki/security-notes.md) — meccanismi di sicurezza implementati + stato remediation post-audit
- [`wiki/changelog.md`](../wiki/changelog.md) — changelog cronologico delle fasi di sviluppo
- [`wiki/decisions/`](../wiki/decisions/) — Architecture Decision Records (ADR)
- [`wiki/domains/`](../wiki/domains/) — documentazione per dominio funzionale (auth, risdoc, esercizi, admin, frontend)

Per le regole di progetto e direttive operative vedi:

- [`.claude/CLAUDE.md`](../.claude/CLAUDE.md) — istruzioni di progetto per AI assistant
