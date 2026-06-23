# Audit: "CI/CD Accessibility Agent" (MCP Market skill)

| Campo | Valore |
|---|---|
| Tool | "CI/CD Accessibility Agent" |
| Source originale | MCP Market catalog page |
| Repository pubblico | **❓ NON IDENTIFICATO** |
| Licenza | **❓ Non verificabile (no source)** |
| Data audit | 2026-05-23 |
| Auditor | {{OPERATORE_NOME}} (via Claude Code session) |
| Verdict | **HOLD — REPLACE con accessibility-agents/action** |

## 1. Source repo identification

### Tentativi di trovare la source

1. **MCP Market direct URL** (`mcpmarket.com/server/ci-cd-accessibility-agent`):
   HTTP 429 rate-limited durante audit — non ho potuto verificare la pagina ufficiale.
2. **GitHub search** (`"ci cd accessibility agent mcp"`):
   1 risultato unico (`Krishcalin/Agentic-AI-Cyber-Security`, 1⭐) che NON corrisponde
   (è un security analyzer non-accessibility).
3. **NPM registry search** (`@*/cicd-accessibility-*`): nessun match certo.

**Conclusione**: il tool non è source-verifiable dalle informazioni
pubbliche disponibili in questa sessione. Potrebbe essere:
- Skill closed-source su MCP Market (paywall o hosted-only)
- Naming generico riferito a feature pubblicate da altri tool
- Tool nuovo non ancora indicizzato dai search engine GitHub/NPM

## 2. Policy pantedu vs unauditable tools

Per la nostra `docs/plans/fase-d-a11y-ecosystem.md`:

> Ogni skill/agent **esegue codice nel nostro ambiente**. Prima di
> installare in ambiente di lavoro: source repo inspection,
> dependency review, sandbox test, sign-off doc.

Senza source visibile, **non possiamo soddisfare nessun criterio
dell'audit checklist**. Default action: **REJECT/HOLD**.

## 3. Replacement strategy

Il bisogno funzionale espresso era:
> "Integra la scansione automatizzata WCAG 2.2 AA nelle pipeline
> CI/CD con gestione intelligente della baseline; supporta GitHub
> Actions, GitLab e Jenkins, e fa fallire le pipeline solo sulle
> nuove regressioni anziché sui problemi legacy."

**Solution alternative interamente auditable** (gia' nel nostro stack post-audit accessibility-agents):

### A. CI/CD scan via `accessibility-agents/action`
Già auditata + APPROVE in
[`accessibility-agents-audit-2026-05-23.md`](accessibility-agents-audit-2026-05-23.md).

```yaml
# .github/workflows/a11y.yml
name: A11y WCAG 2.2 AA
on: [pull_request]
jobs:
  scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: Community-Access/accessibility-agents/action@v5.4.0
        with:
          scan-type: web
          profile: strict
          fail-on: serious
          paths: |
            views/**/*.php
            css/**/*.css
            js/**/*.js
```

### B. Baseline "fail only on regression" — custom shim

accessibility-agents/action genera SARIF output ma NON ha baseline
regression nativa. Implementiamo in 30 righe di script:

```bash
# .github/workflows/a11y.yml step
- name: Compare SARIF vs baseline
  run: |
    # Estrai count nuovi finding (assenti in baseline)
    NEW=$(jq -r --slurpfile bl baseline.sarif '
      .runs[0].results
      | map(.ruleId + ":" + .locations[0].physicalLocation.artifactLocation.uri)
      | unique
      | [.[] | select(. as $r | $bl[0].runs[0].results | map(.ruleId + ":" + .locations[0].physicalLocation.artifactLocation.uri) | index($r) | not)]
      | length
    ' a11y-results.sarif)
    echo "New regressions: $NEW"
    test $NEW -eq 0
```

Baseline checked-in in repo (`a11y-baseline.sarif`), aggiornato
manualmente quando deliberatamente accettiamo nuovi finding.

## 4. Verdict

### ⏸️ HOLD — REPLACE strategy

**NON installeremo "CI/CD Accessibility Agent" (MCP Market skill)**
fino a quando non sarà source-verifiable.

**Useremo invece**:
- `accessibility-agents/action@v5.4.0` (MIT, auditata)
- Custom `jq`-based baseline shim (~30 LOC checked-in nel workflow)

Vantaggi del replacement:
- Tutta source code visibile e auditata
- Zero dipendenze opache
- Nessun rischio di vendor lock-in (jq + GitHub Actions standard)
- Baseline file checked-in = audit trail completo

### Re-evaluation trigger

Se "CI/CD Accessibility Agent" pubblica:
- Repository GitHub pubblico con licenza chiara
- Sorgente code in TypeScript/Python/Go
- Maintainer pubblico identificabile

→ Re-audit considerando come **second-line cross-check**.

### Sign-off

| Data | Auditor | Verdict |
|---|---|---|
| 2026-05-23 | {{OPERATORE_NOME}} (via Claude Code session) | HOLD — replace con accessibility-agents/action + custom shim |
