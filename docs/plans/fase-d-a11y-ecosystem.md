# Fase D — Ecosystem accessibility tools (proposal)

> Status: **proposal**, in attesa di approvazione. Nessuna delle fasi
> D.0–D.4 è stata avviata (allineamento 2026-06-18).
> Created: 2026-05-23 — autore: Vittorio Pantaleo + Claude Code session.
>
> ⚠️ **Verifiche licenza ancora da fare**: i marcatori "TODO verify"
> nella tabella stack (AccessLint, CI/CD Accessibility Agent, WCAG
> Accessibility Compliance) **non sono stati verificati**. La licenza
> reale di quei tool va confermata sulla fonte ufficiale prima di
> qualsiasi installazione (vedi checklist audit più sotto). Da non
> dare per acquisita.

## TL;DR

Integrare **accessibility-agents** (Community-Access, MIT, v5.4.0) come
sistema principale di audit WCAG 2.2 AA + MCP server con 24 strumenti.
Affiancato da **AccessLint reviewer agent** per cross-check su 2.1 AA.
Integrare nel CI/CD via **CI/CD Accessibility Agent** skill (baseline
intelligente: fa fallire pipeline solo su REGRESSIONI, non issue
legacy).

Tutto OPEN SOURCE, MIT/Apache, gratis. Adesione community-driven.

---

## Stack proposto (filtrato per fit con pantedu)

| Tool | Tipo | Licenza | WCAG | Ruolo nel nostro flow |
|---|---|---|---|---|
| **accessibility-agents** | 11 agenti + MCP HTTP server con 24 tool | MIT, v5.4.0, 282⭐ | 2.2 AA + AAA preview + WCAG 3.0 preview | **PRIMARY** — Daily audit + agentic deep-dive |
| **AccessLint** | 4 skill + reviewer agent + MCP color contrast | MIT (TODO verify) | 2.1 AA | **SECONDARY** — Cross-check colore, design audit |
| **CI/CD Accessibility Agent** (MCP Market) | Pipeline integration skill | TODO verify | 2.2 AA | **CI/CD** — GitHub Actions gate su regressioni |
| **WCAG Accessibility Compliance** (MCP Market) | Knowledge skill | TODO verify | 2.2 AA + ADA + Section 508 | **REFERENCE** — Knowledge base per nuove feature |
| `axe-core/playwright` (already installed) | Library | MPL-2.0 | 2.1/2.2 AA | **BASELINE** — Test gate, regression detection |
| `pa11y-ci` (devDep configured) | CLI | LGPL-3.0 | 2.1/2.2 AA | **BATCH SCAN** — Cron quotidiano + report HTML |
| `lighthouse` (devDep configured) | CLI + Chrome built-in | Apache-2.0 | a11y subset | **SCORE BASELINE** — Lighthouse 90/100 gate |

### Tool SCARTATI dopo valutazione

- **Accessibility Audit & Compliance** (MCP Market) — funzionalità
  sovrapposte a accessibility-agents senza vantaggi distintivi. Skip.
- Tool commerciali (SiteImprove, Tenon.io, AccessiBe) — costo
  prohibitive per progetto solo-mantainer + overlay anti-pattern
  (AccessiBe lawsuit + community disabilità contraria).

---

## Pre-installazione: SECURITY AUDIT obbligatorio

Ogni skill/agent **esegue codice nel nostro ambiente**. Prima di
installare in ambiente di lavoro:

### Checklist audit per CIASCUN tool

1. **Source repo inspection**
   - Clona repo in worktree isolato (`/tmp/audit-<tool>`)
   - `git log --oneline --since "6 months ago"` → verifica commit
     attivi, no abandoned project
   - Verifica firma commit (GPG-signed?) maintainer
   - Cerca pattern sospetti: `eval(`, `exec(`, `child_process`,
     `fs.write` su path outside-repo

2. **Dependency review**
   - `npm audit --omit=dev` su node deps
   - `pip-audit` su python deps
   - Verifica nessuna dipendenza unmaintained (last update > 1 year)

3. **MCP server endpoint scrutiny**
   - HTTP server: quale porto, quale interfaccia (127.0.0.1 only?)
   - Auth method (API key? bearer token? plain access?)
   - Network egress: il tool fa chiamate verso domini esterni?
     (es. axe-core OK, ma se chiama dominio sospetto STOP)

4. **Sandbox test**
   - Worktree git separato (`git worktree add /tmp/pantedu-a11y-test`)
   - VS Code instance separata, profile temp
   - Senza accesso a `.env.local` o secrets reali
   - Run audit completo, osserva network, observa file mutati

5. **Sign-off documentato**
   - File `docs/security/third-party-tools/<tool>-audit-YYYY-MM-DD.md`
   - Hash commit auditato + verdict (APPROVE / REJECT / CONDITIONAL)

---

## Roadmap fasi

### Fase D.0 — Tool audit + setup (1-2 sessioni)

- [ ] Audit accessibility-agents (Community-Access)
  - Clone + git log review + dependency audit + sandbox test
  - Decision: install in main env OR keep in sandbox
- [ ] Audit AccessLint
- [ ] Audit CI/CD Accessibility Agent (MCP Market verify publisher)
- [ ] Audit WCAG Accessibility Compliance skill
- [ ] Documentation: `docs/security/third-party-tools/*.md`

### Fase D.1 — accessibility-agents integration (1 sessione)

- [ ] Install via `claude code install Community-Access/accessibility-agents`
- [ ] Configure MCP server in `.claude/settings.json`
  - Verifica HTTP server bind 127.0.0.1 only
  - Stabilisci budget di network egress
- [ ] First full audit run su pantedu staging
  - Genera report HTML + JSON
  - Categorizza findings: critical / serious / moderate / minor
- [ ] Triage findings: quali fixare subito vs roadmap

### Fase D.2 — AccessLint cross-check (mezza sessione)

- [ ] Install AccessLint skill
- [ ] Run reviewer agent su moduli CSS appena modularizzati
- [ ] Color contrast deep audit via MCP tool
- [ ] Cross-reference findings vs accessibility-agents (sanity check)

### Fase D.3 — CI/CD pipeline gate (1 sessione)

- [ ] Install CI/CD Accessibility Agent skill
- [ ] Configura GitHub Actions workflow `.github/workflows/a11y-ci.yml`
  - Run on PR open + push to main
  - Baseline da release tag corrente (no false positive legacy)
  - Fail solo su regressione (issue introdotto da PR)
- [ ] Setup `accessibility-baseline.json` checked in repo
- [ ] Documentare in CONTRIBUTING.md: "PR deve passare a11y-ci gate"

### Fase D.4 — Roadmap continuation (cronologia, già pianificata)

- [ ] **D.4.1** SPID/CIE integration (Q4 2026)
- [ ] **D.4.2** WCAG 2.2 AA upgrade — adesso supportato da
  accessibility-agents automaticamente
- [ ] **D.4.3** EN 301 549 v3.3 audit esterno indipendente (Q2 2027)

---

## Decision flow per installare un tool a11y

```
                   Tool candidato
                        │
                        ▼
            ┌───────────────────────┐
            │ Repo MIT/Apache/EUPL? │
            └───────────────────────┘
                  │YES        │NO → SKIP
                  ▼
        ┌──────────────────────────┐
        │ Last commit < 3 mesi fa? │
        └──────────────────────────┘
              │YES        │NO → SKIP (abandonware)
              ▼
    ┌────────────────────────────┐
    │ npm audit / pip-audit clean?│
    └────────────────────────────┘
            │YES        │NO → patch o SKIP
            ▼
    ┌─────────────────────────────┐
    │ MCP server bind 127.0.0.1 ? │
    └─────────────────────────────┘
          │YES        │NO → STOP, review code
          ▼
    ┌──────────────────────────────┐
    │ Sandbox test passes ?         │
    │ (file mutations, network)     │
    └──────────────────────────────┘
        │YES        │NO → reject
        ▼
    ┌────────────────────┐
    │ INSTALL + doc audit │
    └────────────────────┘
```

---

## Budget tempo realistico

| Fase | Effort | Note |
|---|---|---|
| D.0 audit 4 tools | 4-6h | Una sessione |
| D.1 accessibility-agents | 2-3h | Una sessione |
| D.2 AccessLint cross-check | 1-2h | Mezza sessione |
| D.3 CI/CD integration | 2-3h | Una sessione |
| D.4 long-term (SPID/CIE, audit esterno) | settimane | Calendario futuro |

**Totale Fase D.0-D.3 (auto-driven):** 9-14 ore = **2-3 sessioni**.

---

## Disclaimer pacchetti terzi

> ⚠️ Le skill/agent eseguono codice nel nostro ambiente di sviluppo.
> Verifica sempre l'identità del publisher, controlla i commit recenti
> e prova in ambienti isolati prima del deployment in produzione.
>
> Le scansioni automatiche di sicurezza sui marketplace MCP rilevano
> vulnerabilità comuni ma non garantiscono sicurezza completa.
>
> **Politica pantedu**: ogni nuovo tool MCP/skill che esegue codice
> richiede un audit documentato firmato dal maintainer
> (`docs/security/third-party-tools/`) prima di entrare in
> `~/.claude/settings.json` produzione.

---

## Riferimenti

- **accessibility-agents repo**: <https://github.com/Community-Access/accessibility-agents>
  (v5.4.0, MIT, 282⭐, last update 2026-05-06)
- **AccessLint**: <https://github.com/accesslint/accesslint> (TODO verify URL)
- **MCP Market a11y catalog**: <https://mcpmarket.com/categories/accessibility>
- **WCAG 2.2 spec**: <https://www.w3.org/TR/WCAG22/>
- **AgID Linee Guida accessibilità**: <https://www.agid.gov.it/it/design-servizi/accessibilita>
- **EN 301 549 v3.2.1**: <https://www.etsi.org/deliver/etsi_en/301500_301599/301549/03.02.01_60/en_301549v030201p.pdf>

---

## Prossima azione

Una volta approvato questo proposal:
1. Allochiamo una sessione per Fase D.0 (audit) — 4-6 ore
2. Crea `docs/security/third-party-tools/` dir + template audit
3. Procediamo D.0 → D.1 → D.2 → D.3 in ordine
4. Re-evaluate compatibilità prima di Fase D.4 (SPID/CIE, audit esterno)

**Aspetto tuo go/no-go per partire con D.0.**
