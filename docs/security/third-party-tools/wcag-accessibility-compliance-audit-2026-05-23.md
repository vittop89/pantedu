# Audit: "WCAG Accessibility Compliance" (MCP Market skill)

| Campo | Valore |
|---|---|
| Tool | "WCAG Accessibility Compliance" |
| Source originale | MCP Market catalog page |
| Repository pubblico | **❓ NON IDENTIFICATO** |
| Licenza | **❓ Non verificabile (no source)** |
| Data audit | 2026-05-23 |
| Auditor | {{OPERATORE_NOME}} (via Claude Code session) |
| Verdict | **HOLD — REPLACE con accessibility-agents WCAG-guide agent** |

## 1. Source repo identification

Stesso problema di [cicd-accessibility-agent](cicd-accessibility-agent-audit-2026-05-23.md):

1. **MCP Market direct URL** — HTTP 429 rate-limited.
2. **GitHub search** (`"WCAG accessibility compliance MCP skill"`):
   2 risultati ma entrambi di `justice8096` (Dyslexia/Dyscalculia
   Support Skill), focus diverso da WCAG generico, 0 stars,
   creati negli ultimi 6 giorni — community traction nulla.
3. **NPM registry**: nessun match noto.

## 2. Funzionalità promesse vs alternative

Il bisogno funzionale:
> "Framework completo per costruire e auditare applicazioni web
> secondo gli standard WCAG 2.2 AA, ADA e Section 508, con
> conoscenza specializzata per HTML semantico, gestione di stati
> di focus complessi, rapporti di contrasto colore e attributi ARIA."

### Replacement interno: accessibility-agents

I 11 agenti specialisti di **accessibility-agents** (Community-Access,
MIT, auditato APPROVE) coprono direttamente questi domini:

| Agent specialista | Copertura |
|---|---|
| `Accessibility-lead` | Orchestratore strategie audit |
| `ARIA-specialist` | Attributi ARIA, ruoli, stati |
| `Modal-specialist` | Dialog, focus trap, modal patterns |
| `Contrast-master` | Color contrast WCAG 1.4.3 / 1.4.11 |
| `Keyboard-navigator` | Navigation WCAG 2.1 + focus visible |
| `Live-region-controller` | aria-live, status messages WCAG 4.1.3 |
| `Forms-specialist` | Label, fieldset, aria-required, errors |
| `Alt-text-headings` | Alt text WCAG 1.1.1 + heading hierarchy 1.3.1 |
| `Tables-data-specialist` | th/scope, table semantics WCAG 1.3.1 |
| `Link-checker` | Link text context, link distinguishability |
| `Testing-coach` | Strategy advisor per test manuali |
| `WCAG-guide` | Reference knowledge base (gia' incluso) |

→ **Sovrapposizione completa** con quanto promesso da "WCAG
Accessibility Compliance".

### ADA + Section 508

USA-specific compliance. **Non rilevante per pantedu** (target Italia +
EU, EN 301 549 + Direttiva 2016/2102).

Quando/se pantedu volesse target US market, valutare:
- accessibility-agents copre 95% delle overlap WCAG 2.1 AA ↔ Section 508
- ADA delta gestito separatamente (es. via Pa11y custom config con
  standard `Section508`)

## 3. Verdict

### ⏸️ HOLD — REPLACE strategy

**NON installeremo "WCAG Accessibility Compliance"** fino a
quando non sarà source-verifiable.

**Useremo invece**:
- `accessibility-agents` (MIT, auditata) — copre tutti i domini
- `pa11y-ci` con standard `WCAG2AA` (gia' configurato in `.pa11yci.json`)
- Per Section 508 (futuro USA target):
  ```bash
  pa11y --standard Section508 https://pantedu.eu
  ```

### Re-evaluation trigger

Se "WCAG Accessibility Compliance" pubblica source pubblico + licenza:
- Re-audit vs criteri checklist
- Considerare come knowledge-skill di terza linea

### Sign-off

| Data | Auditor | Verdict |
|---|---|---|
| 2026-05-23 | {{OPERATORE_NOME}} (via Claude Code session) | HOLD — replace con accessibility-agents (specialisti) + pa11y-ci |
