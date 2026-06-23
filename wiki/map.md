---
tags:
  - documentazione/architettura
date: 2026-04-29
tipo: architettura
status: finale
aliases: ["map", "indice", "index", "wiki-index"]
cssclasses: []
---

# Map — Wiki pantedu

> [!abstract] Indice master di tutta la documentazione tecnica.
> Punto di partenza per navigare la wiki. Leggi prima [[_llm-primer]].

## Per nuovo dev

1. [[_llm-primer]] — contesto LLM-first (5 min lettura)
2. [[dev-workflow]] — setup locale XAMPP + comandi essenziali
3. [[architecture]] — stack + pattern MVC + flowchart end-to-end
4. [[glossary]] — termini dominio (risdoc, collex, drift, ...)

## Core Wiki

| File | Tipo | Descrizione |
|------|------|-------------|
| [[_llm-primer]] | primer | Contesto rapido per LLM: stack, pattern, zone protette |
| [[domain-map]] | domain-map | 7 domini identificati, coverage test, accoppiamenti |
| [[architecture]] | architettura | Stack, pattern MVC, flowchart end-to-end, bottleneck |
| [[entrypoints]] | architettura | Entrypoint HTTP, CLI, Vite |
| [[routing-and-api]] | api | Tutte le route con controller, middleware, input/output |
| [[database-schema]] | database | ERD, tabelle, dizionario dati, migrations, repository |
| [[environment-variables]] | environment | Tutte le variabili .env con modulo ed effetto |
| [[security-notes]] | security | Auth, CSRF, RBAC, blocklist, rate limit, superfici attacco |
| [[testing]] | testing | PHPUnit + Playwright, comandi, coverage per dominio |
| [[dev-workflow]] | workflow | Setup XAMPP, DB, Vite, comandi copy-pasteable |
| [[glossary]] | glossario | Termini dominio: risdoc, collex, compilation, drift, … |
| [[user-flows]] | user-flow | 6 flussi Mermaid: login, registrazione, export TeX, editor, studente, admin |
| [[technical-debt]] | debt | 15 item debito tecnico con rischio e file |
| [[changelog]] | changelog | Index dispatcher → mensili in `wiki/changelog/YYYY-MM.md` |

## Decisioni architetturali (ADR)

| File | Titolo | Phase |
|------|--------|-------|
| [[decisions/ADR-001-mvc-php-custom]] | MVC PHP Custom (no framework) | 1 |
| [[decisions/ADR-002-lit3-web-components]] | Lit 3 Web Components per risdoc Plan B | 2 |
| [[decisions/ADR-003-tex-pipeline]] | Pipeline TeX/pdflatex server-side | 3 |
| [[decisions/ADR-004-csrf-auto-rotate]] | CSRF Auto-rotate su TTL | 14 |
| [[decisions/ADR-005-schema-driven-risdoc]] | Schema-driven rendering risdoc | 24 |
| [[decisions/ADR-006-envelope-encryption]] | Envelope encryption AES-256-GCM + HKDF + crypto-shredding | 25.D |
| [[decisions/ADR-007-gdpr-compliance]] | GDPR self-service + Art. 17 oblio + minori Art. 8 | 25.C |
| [[decisions/ADR-008-audit-reason]] | Audit reason obbligatoria su mutazioni admin cross-teacher | 25.B4 |

## Domini

| Dominio | Overview | Moduli |
|---------|----------|--------|
| core | [[domains/core/core-overview]] | Router, Kernel, Auth, Csrf, Session, Config, Database, View |
| auth | [[domains/auth/auth-overview]] | AuthController, Auth, UserRepository, BlockList, RateLimiter |
| risdoc | [[domains/risdoc/risdoc-overview]] | TemplateController, CompilationController, ExportController, TexBuilder, WC Lit3 |
| risdoc/tex-pipeline | [[domains/risdoc/tex-pipeline]] | processLegacyTex, TexBuilder, ZipArchive, main.tex, risdoc.sty |
| esercizi | [[domains/esercizi/esercizi-overview]] | ExerciseController, TeacherContentController, ContractAggregate, editor-system |
| verifiche | [[domains/verifiche/verifiche-overview]] | VerificheController, VerificaBuilderController, print-export |
| mappe | [[domains/mappe/mappe-overview]] | google-apps, google-apps-script, SidepageController |
| admin | [[domains/admin/admin-overview]] | AdminController, UsersAdminController, SecurityAdminController, RisdocAdminController |
| frontend | [[domains/frontend/frontend-overview]] | bootstrap.js, Lit3 WC, fm-router, Vite, editor modules |

## Privacy / GDPR (Phase 25.C)

| Documento | Path | Contenuto |
|-----------|------|-----------|
| Informativa Art. 13 | `docs/privacy/informativa.md` | Trasparenza utenti finali (v2.0+) |
| Registro trattamenti Art. 30 | `docs/privacy/registro-trattamenti.md` | 8 trattamenti documentati |
| DPIA Art. 35 | `docs/privacy/dpia.md` | 14 rischi matrice |
| DPA Aruba (sub-processor) | `docs/privacy/contracts/contracts-index.md` | Mappatura Art. 23 vs Art. 28 §3 |
| Accountability archive | `docs/privacy/aruba-accountability/aruba-archive-index.md` | T&C + ordini + fatture |
| Breach drill semestrale | `docs/privacy/breach_notification_template.md` | Template Art. 34 |

ADR correlati: [[decisions/ADR-007-gdpr-compliance]], [[decisions/ADR-006-envelope-encryption]] (crypto-shredding O(1) per Art. 17).

## Sicurezza / Pentest

| Documento | Path | Contenuto |
|-----------|------|-----------|
| Security notes | [[security-notes]] | Meccanismi auth + CSRF + envelope crypto + production checklist |
| ADR audit reason | [[decisions/ADR-008-audit-reason]] | Free-text X-Audit-Reason su admin POST/DELETE |
| Pentest 2026-04-29 | `docs/security/pentest/2026-04-29/audit-index.md` | Whitebox AI-assisted (CONFIDENZIALE) |
| KMS recovery runbook | `docs/security/operations/kms-recovery.md` | 3 location backup + scenarios |

## API

| Documento | Path |
|-----------|------|
| OpenAPI 3.1 spec | `docs/api/openapi.full.yaml` (146 path / 187 operations) |
| API index + workflow | `docs/api/api-index.md` |

## Dataview

```dataview
TABLE tipo, status, date FROM "wiki"
SORT date DESC
```

## Quick navigation per domanda

| Domanda | File da leggere |
|---------|----------------|
| Come funziona il routing? | [[routing-and-api]], [[domains/core/core-overview]] |
| Come funziona il login? | [[domains/auth/auth-overview]], [[security-notes]] |
| Come si genera un PDF risdoc? | [[domains/risdoc/tex-pipeline]], [[user-flows]] |
| Quali tabelle DB esistono? | [[database-schema]] |
| Quali .env variabili ci sono? | [[environment-variables]] |
| Come si fa il setup dev? | [[dev-workflow]] |
| Quali test esistono? | [[testing]] |
| Cos'è un "collex-item"? | [[glossary]] |
| Quali classi HTML non toccare? | [[domains/esercizi/esercizi-overview]], [[_llm-primer]] |
| Perché non si usa un framework? | [[decisions/ADR-001-mvc-php-custom]] |
| Cos'è Plan A vs Plan B risdoc? | [[domains/risdoc/risdoc-overview]], [[decisions/ADR-002-lit3-web-components]] |
| Cosa non funziona su Aruba? | [[decisions/ADR-003-tex-pipeline]], [[technical-debt]] |
| Qual è il debito tecnico principale? | [[technical-debt]] |
| Come funziona la cifratura body? | [[decisions/ADR-006-envelope-encryption]] |
| Come funziona il diritto all'oblio? | [[decisions/ADR-007-gdpr-compliance]] |
| Perché audit reason obbligatoria? | [[decisions/ADR-008-audit-reason]] |
| Dove sta il DPA Aruba? | `docs/privacy/contracts/contracts-index.md` (Art. 23 T&C v4.4) |
| Come si usa OpenAPI spec? | `docs/api/api-index.md` |

## Convenzioni link wiki

- **Wiki interna**: usa wikilink Obsidian `[[file]]` (no estensione)
- **Riferimento codice**: NO link markdown — usa backtick `app/path/file.php`
  (mantiene grafo Obsidian pulito; vedi [[changelog]] entry 2026-04-29)
- **Riferimento documento `docs/`**: link markdown standard
  `[label](../docs/path/file.md)` (Obsidian indicizza solo .md)

CI guard: `php tools/wiki/strip_code_links.php --check` blocca commit con
link diretti al codice nelle pagine wiki.
