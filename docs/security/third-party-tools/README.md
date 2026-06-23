# Third-party tools security audits

Cartella per gli audit di sicurezza dei tool/skill MCP che integriamo
nell'ambiente di sviluppo pantedu.

## Policy

Ogni tool MCP, skill Claude Code, agent, browser extension o pacchetto
npm/python che **esegue codice nel nostro ambiente** richiede un audit
documentato firmato dal maintainer prima di entrare in produzione.

Vedi [Fase D plan](../../plans/fase-d-a11y-ecosystem.md#pre-installazione-security-audit-obbligatorio)
per la checklist completa e il decision flow.

## File naming

`{tool-name}-audit-YYYY-MM-DD.md` — un file per tool, datato.

Se lo stesso tool ha bump major version → nuovo audit con data update.

## Status board

| Tool | Verdict | Data audit | Next re-audit |
|---|---|---|---|
| [accessibility-agents](accessibility-agents-audit-2026-05-23.md) | ✅ **APPROVE CONDITIONAL** | 2026-05-23 | Al prossimo major (v6.x) |
| [AccessLint](accesslint-audit-2026-05-23.md) | ❌ **REJECT** (no license) | 2026-05-23 | Solo se LICENSE viene aggiunto |
| [CI/CD Accessibility Agent](cicd-accessibility-agent-audit-2026-05-23.md) | ⏸️ **HOLD — REPLACE** | 2026-05-23 | Solo se source pubblico disponibile |
| [WCAG Accessibility Compliance](wcag-accessibility-compliance-audit-2026-05-23.md) | ⏸️ **HOLD — REPLACE** | 2026-05-23 | Solo se source pubblico disponibile |

## Risultato Fase D.0

**Solo 1 tool su 4 approvato per installazione**: `accessibility-agents`
(MIT, source pubblico, MCP server bind localhost, community attiva,
SECURITY.md disclosure policy presente).

I 3 tool restanti hanno blocchi (no license OR no source pubblico
verificabile). Replacement strategy interna:
- Per CI/CD pipeline → `accessibility-agents/action@v5.4.0` + custom
  jq baseline shim (~30 LOC)
- Per WCAG knowledge → 11 agenti specialisti integrati in
  accessibility-agents (ARIA, contrast, keyboard, forms, ecc.)
- Per Section 508 (futuro USA target) → `pa11y --standard Section508`

## Riassunto azioni successive

1. **Fase D.1** — Install accessibility-agents
   - Clone + ispeziona + dry-run
   - MCP server in `~/.claude/settings.json` bind 127.0.0.1:3100
   - First full audit pantedu, triage findings
2. **Fase D.2** — Cross-check skip (AccessLint rejected, no
   secondary tool da auditare)
3. **Fase D.3** — CI/CD pipeline gate via accessibility-agents action
   + custom baseline shim
4. **Fase D.4** — Long-term SPID/CIE Q4 2026, audit esterno Q2 2027
