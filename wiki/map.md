---
tags:
  - documentazione/architettura
date: 2026-09-23
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
2. [[dev-workflow]] — setup locale + comandi essenziali
3. [[architecture]] — stack + pattern MVC + flowchart end-to-end
4. [[glossary]] — termini dominio (risdoc, collex, scenario, terna, ...)

## Core Wiki

| File | Tipo | Descrizione |
|------|------|-------------|
| [[_llm-primer]] | primer | Contesto rapido per LLM: stack, pattern, zone protette |
| [[domain-map]] | domain-map | Domini, sottodomini di `app/Services`, coverage test |
| [[architecture]] | architettura | Stack, pattern MVC, strati (bordo, WAF, Kernel, dati), bottleneck |
| [[entrypoints]] | architettura | Entrypoint HTTP, CLI, cron/systemd, webhook, Vite |
| [[routing-and-api]] | api | Gruppi di rotte, middleware, forme di risposta; rimanda a `docs/ROUTES.md` |
| [[database-schema]] | database | Tabelle, viste, trigger append-only, migrazioni, repository |
| [[environment-variables]] | environment | Tutte le variabili `.env` con file che le legge |
| [[security-notes]] | security | Auth, 2FA, CSRF, RBAC, WAF, audit append-only, crypto, superfici |
| [[testing]] | testing | PHPUnit + Vitest + Playwright, comandi, TEST_ROT, lacune |
| [[e2e-refactoring-esito]] | testing | Esito del refactoring E2E del 6-7/9/2026: scorecard, prima/dopo, venti domande di chiusura |
| [[dev-workflow]] | workflow | Setup, DB di test, Vite, quality gate, migrazioni, crypto, GDPR |
| [[glossary]] | glossario | Termini dominio: risdoc, collex, scenario, terna, PT, … |
| [[user-flows]] | user-flow | Flussi: login con 2FA, registrazione, accesso classe, export risdoc, editor, admin |
| [[technical-debt]] | debt | Registro unico del debito, con le risolte barrate: voci delle fasi storiche, della revisione del 4/9 e del refactoring E2E; dalla 109 i rilievi della revisione completa del 23/9/2026, ognuno col suo ID (A-…, D-…, DOC-…) |
| [[changelog]] | changelog | Index dispatcher → mensili in `wiki/changelog/YYYY-MM.md` (2026-04 … 2026-09) |
| [[waf]] | security | WAF applicativo: architettura, pannello admin, quick start |
| [[tex-pipeline]] | modulo | Pipeline TeX delle verifiche (contract → HTML → TeX → PDF) |
| [[_security]] | security | Toolchain e procedure per gli audit di sicurezza |
| [[db-legacy-tables]] | database | Tabelle legacy censite a maggio 2026 (storico, superata) |

## Decisioni architetturali (ADR)

| File | Titolo | Stato |
|------|--------|-------|
| [[decisions/ADR-001-mvc-php-custom]] | MVC PHP custom (no framework) | accettato |
| [[decisions/ADR-002-lit3-web-components]] | Lit 3 Web Components per risdoc Plan B | accettato (Lit è nel bundle da `package.json`, non da CDN; debito 16, chiusa) |
| [[decisions/ADR-003-tex-pipeline]] | Pipeline TeX/pdflatex server-side | accettato |
| [[decisions/ADR-004-csrf-auto-rotate]] | CSRF auto-rotate su TTL | accettato |
| [[decisions/ADR-005-schema-driven-risdoc]] | Schema-driven rendering risdoc | accettato |
| [[decisions/ADR-006-envelope-encryption]] | Envelope encryption AES-256-GCM + HKDF + crypto-shredding | accettato |
| [[decisions/ADR-007-gdpr-compliance]] | GDPR self-service + Art. 17 oblio + minori Art. 8 | accettato |
| [[decisions/ADR-008-audit-reason]] | Audit reason obbligatoria su mutazioni admin cross-teacher | accettato |
| [[decisions/ADR-009-drive-integration]] | Integrazione Google Drive (G1-G7) | accettato |
| [[decisions/ADR-010-modern-topbar]] | Topbar moderna + `verifica_documents` (G8) | accettato |
| [[decisions/ADR-011-verifiche-multifile]] | Verifiche multi-file (G20) | accettato |
| [[decisions/ADR-012-tex-compile-vps]] | Compilazione TeX sul microservizio (G21) | accettato |
| [[decisions/ADR-013-tikz-server-render]] | Render TikZ server-side + GDPR (G22.S15) | accettato |
| [[decisions/ADR-014-kms-strategy]] | Strategia KMS per la chiave master | differito |
| [[decisions/ADR-015-xss-sanitization]] | Sanitizzazione XSS server-side autoritativa | accettato |
| [[decisions/ADR-016-editor-modular-architecture]] | Architettura modulare dell'editor esercizi | accettato |
| [[decisions/ADR-017-deployment-mode-switch]] | Switch `single` ↔ `institute` | accettato (esteso da ADR-032) |
| [[decisions/ADR-018-css-modernization-strategy]] | Strategia di modernizzazione CSS | accettato |
| [[decisions/ADR-019-css-hot-classes-full-bem-migration]] | Migrazione BEM delle classi calde | accettato |
| [[decisions/ADR-020-page-doc-block-types]] | Tipi di blocco dei documenti pagina | proposto |
| [[decisions/ADR-021-pt-document-centralization]] | Centralizzazione documento PT | superseded (ADR-022) |
| [[decisions/ADR-022-fm-pt-document-unified]] | `<fm-pt-document>` unificato | accettato |
| [[decisions/ADR-023-deterministic-css-cascade]] | Cascata CSS deterministica (`@layer`) | accettato |
| [[decisions/ADR-024-pt-document-topbar-unification]] | Topbar e modal unici per i documenti PT | accettato |
| [[decisions/ADR-025-risdoc-curriculum-data-dynamic]] | Dati curricolari risdoc dinamici e istituzionali | accettato |
| [[decisions/ADR-026-models-as-custom-unification]] | Modelli risdoc resi come documenti custom | accettato |
| [[decisions/ADR-027-dynamic-sidebar-config]] | Sidebar data-driven da DB | accettato |
| [[decisions/ADR-028-institute-governance-teacher-capabilities]] | Governance istituto e capability per docente | accettato |
| [[decisions/ADR-029-decomposizione-god-controller]] | Decomposizione dei God-controller | accettato |
| [[decisions/ADR-030-document-terna-scoped-values]] | Un documento, valori per terna | accettato |
| [[decisions/ADR-031-table-formulas]] | Formule nelle tabelle | accettato |
| [[decisions/ADR-032-deployment-scenarios]] | Scenari di esercizio: personale, colleghi, Istituto | accettato |
| [[decisions/ADR-033-frontend-deps-in-bundle]] | Librerie frontend da npm e nel bundle, non da CDN a runtime | accettato |
| [[decisions/ADR-034-helper-condivisi-controller]] | Corpo JSON, forma delle risposte e id docente: un solo helper | accettato |
| [[decisions/ADR-035-catalogo-istituto-centralizzato]] | Il catalogo è dell'istituto: un vocabolario, e i docenti lo spuntano | accettato |
| [[decisions/ADR-036-catalogo-adozioni]] | I libri in adozione sono un catalogo dell'istituto, e le fonti del docente ci attingono | accettato |
| [[decisions/ADR-037-pubblicazioni-e-copie]] | Un documento, più pubblicazioni: la collocazione esce dalla riga; copie indipendenti | accettato, in esecuzione |
| [[decisions/ADR-038-drive-stati]] | Drive ha uno stato: quello dell'installazione e quello di ogni collegamento | accettato |
| [[decisions/ADR-039-dove-stanno-le-sessioni]] | Dove stanno le sessioni si sceglie in configurazione, e sono uguali ovunque | accettato |
| [[decisions/ADR-040-amministratore-di-istituto]] | L'amministratore di istituto: un ruolo suo, e solo nello scenario 3 | accettato, fase 1 eseguita |
| [[decisions/ADR-041-sezioni-dei-docenti]] | Le sezioni delle classi per i docenti: tutti, solo incaricati, nessuno | accettato |
| [[decisions/ADR-042-anni-per-indirizzo]] | Gli anni di corso appartengono a un indirizzo | accettato |
| [[decisions/ADR-043-anni-con-incarico]] | Anche gli anni di corso seguono gli incarichi | accettato |
| [[decisions/ADR-044-etichetta-e-regole-delle-credenziali]] | Credenziali di classe: etichetta composta dal server, regole scritte in un posto, username unico | accettato |
| [[decisions/ADR-045-overleaf-esce]] | Overleaf esce dall applicazione: nessun percorso era vivo, e un bottone diceva di funzionare | accettato |
| [[decisions/ADR-046-bozze-a-scadenza]] | Le bozze di compilazione si cancellano: 15 giorni dallo scaricamento, e comunque a fine anno scolastico | accettato |
| [[decisions/ADR-047-la-scuola-e-facoltativa]] | La scuola e facoltativa all iscrizione, tranne dove e la scuola a gestire la piattaforma | accettato |
| [[decisions/ADR-048-rilascio-a-container]] | Si va in produzione con un'immagine: container nuovo a fianco, scambio in un istante, l'host tiene il resto | accettato (retroattivo, decisione dell'8/9/2026) |

## Domini

| Dominio | Overview | Moduli |
|---------|----------|--------|
| core | [[domains/core/core-overview]] | Router, Kernel, Auth, Csrf, Session, Config, Database, Migrator, View |
| auth | [[domains/auth/auth-overview]] | AuthController (login in due passaggi), 2FA, recupero password, registrazione, credenziale di classe |
| risdoc | [[domains/risdoc/risdoc-overview]] · [[domains/risdoc/tex-pipeline]] | TemplateController, compilazioni cifrate, override, editor PT, export TeX |
| esercizi | [[domains/esercizi/esercizi-overview]] · [[domains/esercizi/editor-architecture]] · [[domains/esercizi/rm-table-rendering]] | contract, ContentStudy/Quesito/Group controller, editor modulare |
| verifiche | [[domains/verifiche/verifiche-overview]] | VerificaController, VerificaDocumentService, TexBuilder, compile via microservizio |
| mappe | [[domains/mappe/mappe-overview]] | MapsController, MapBlobStore, Drive sync |
| admin | [[domains/admin/admin-overview]] | strumenti, utenti, istituti, sezioni, GDPR, WAF, backup, log, scenari |
| frontend | [[domains/frontend/frontend-overview]] | bootstrap.js, Web Component Lit, entry Vite, sidebar |
| sicurezza | [[security/xss-policy]] · [[waf]] · [[security-notes]] | sanitizzazione, WAF, audit |

## Privacy / GDPR

| Documento | Path | Contenuto |
|-----------|------|-----------|
| Informativa Art. 13 | `docs/privacy/informativa.md` | Trasparenza utenti finali (versione nel frontmatter del file, non qui) |
| Informativa per Istituto | `docs/privacy/informativa-istituto.md` | Scenario 3, con token da compilare dall'adottante |
| Registro trattamenti Art. 30 | `docs/privacy/registro-trattamenti.md` | versione nel frontmatter del file |
| DPIA Art. 35 | `docs/privacy/dpia.md` | versione nel frontmatter del file |
| Documenti contrattuali | `docs/legal/versions.json` | ToS, AUP, DPA, takedown: registro delle versioni, check CI |
| Pacchetto per il DPO | `docs/dpo/` | tre scenari, in parte escluso dal repo pubblico |
| Breach drill semestrale | `docs/privacy/breach_notification_template.md` | Template Art. 34 |

ADR correlati: [[decisions/ADR-007-gdpr-compliance]], [[decisions/ADR-006-envelope-encryption]], [[decisions/ADR-032-deployment-scenarios]].

## Sicurezza / Pentest

| Documento | Path | Contenuto |
|-----------|------|-----------|
| Security notes | [[security-notes]] | Meccanismi e checklist di produzione |
| WAF | [[waf]] | Architettura e pannello |
| Toolchain audit | [[_security]] | Strumenti e procedura degli audit assistiti |
| Pentest 2026-04-29 e 2026-05-18 | `docs/security/pentest/` | Report (esclusi dal repo pubblico) |
| Runbook operativi | `docs/security/operations/` | Recovery KMS, Shamir, autorità, incident response (in parte esclusi dal pubblico) |

## API

| Documento | Path |
|-----------|------|
| OpenAPI 3.1 spec | `docs/api/openapi.full.yaml` (generata da `tools/api/generate_openapi.php`; copertura reale sotto quella dichiarata, [[technical-debt]] voce 36) |
| API index + workflow | `docs/api/api-index.md` |
| Inventario rotte | `docs/ROUTES.md` (generato da `routes/web.php` con `tools/dev/gen_routes_md.php`; totale nella prima riga del file) |

## Dataview

```dataview
TABLE tipo, status, date FROM "wiki"
SORT date DESC
```

## Quick navigation per domanda

| Domanda | File da leggere |
|---------|----------------|
| Come funziona il routing? | [[routing-and-api]], [[domains/core/core-overview]], `docs/ROUTES.md` |
| Come funziona il login e il secondo fattore? | [[domains/auth/auth-overview]], [[security-notes]] |
| Che cos'è uno scenario di esercizio? | [[decisions/ADR-032-deployment-scenarios]], [[glossary]] |
| Come entra uno studente senza account? | [[domains/auth/auth-overview]] (credenziale di classe), [[user-flows]] |
| Come si genera un PDF risdoc? | [[domains/risdoc/tex-pipeline]], [[user-flows]] |
| Come si compila una verifica? | [[tex-pipeline]], [[domains/verifiche/verifiche-overview]] |
| Quali tabelle DB esistono? | [[database-schema]] |
| Quali .env variabili ci sono? | [[environment-variables]] |
| Come si fa il setup dev? | [[dev-workflow]] |
| Quali test esistono e quali sono saltati? | [[testing]] |
| Cos'è un "collex-item"? | [[glossary]] |
| Quali classi HTML non toccare? | [[domains/esercizi/esercizi-overview]], [[_llm-primer]] |
| Perché non si usa un framework? | [[decisions/ADR-001-mvc-php-custom]] |
| Cos'è Plan A vs Plan B risdoc? | [[domains/risdoc/risdoc-overview]], [[decisions/ADR-002-lit3-web-components]] |
| Qual è il debito tecnico principale? | [[technical-debt]] |
| Come funziona la cifratura? | [[decisions/ADR-006-envelope-encryption]] |
| Come funziona il diritto all'oblio? | [[decisions/ADR-007-gdpr-compliance]] |
| Perché audit reason obbligatoria? | [[decisions/ADR-008-audit-reason]] |
| Come sono protetti i registri di audit? | [[security-notes]] (append-only, catena di impronte) |
| Come funziona il WAF? | [[waf]] |
| Come si usa OpenAPI spec? | `docs/api/api-index.md` |
| Cosa è cambiato di recente? | [[changelog]] → mese corrente |

## Convenzioni link wiki

- **Wiki interna**: usa wikilink Obsidian (doppie parentesi quadre, percorso dalla radice della wiki, senza estensione)
- **Riferimento codice**: NO link markdown — usa backtick `app/path/file.php`
  (mantiene grafo Obsidian pulito)
- **Riferimento documento `docs/`**: link markdown standard
  `[label](../docs/path/file.md)` (Obsidian indicizza solo .md)

CI guard: `php tools/wiki/strip_code_links.php --check` blocca commit con
link diretti al codice nelle pagine wiki.
