# Audit: AccessLint/accesslint

| Campo | Valore |
|---|---|
| Tool | `AccessLint` (`@accesslint/mcp`, `@accesslint/core`) |
| Publisher | `AccessLint` org su GitHub |
| Repository | <https://github.com/AccessLint/accesslint> |
| Versione auditata | v0.8.0 (release 2026-05-02) |
| Licenza | **❌ NESSUNA / non specificata** |
| Stars | **3 ⭐** (community traction molto bassa) |
| Data audit | 2026-05-23 |
| Auditor | {{OPERATORE_NOME}} (via Claude Code session) |
| Verdict | **REJECT** |

## 1. Source repo inspection

- Repo esiste e accessibile pubblicamente.
- Stack: **TypeScript 98.7%**, Bun + Turborepo monorepo, Node runtime.
- 29 release pubblicate, ultimo v0.8.0 il 2026-05-02.
- 146 commit su `main`.

### Pacchetti workspace identificati

- `@accesslint/core` — engine audit
- `@accesslint/cli` — CLI
- `@accesslint/mcp` — wrapper MCP del core
- `@accesslint/playwright`, `@accesslint/jest`, `@accesslint/vitest` — test integrations

## 2. Blocking issue: **NO LICENSE**

```bash
$ curl -I https://raw.githubusercontent.com/AccessLint/accesslint/main/LICENSE
HTTP/2 404
```

**Nessun file LICENSE nel repo.**

Implicazioni legali:
- Default copyright = **All Rights Reserved** dell'autore.
- Senza licenza esplicita, l'uso, modifica, distribuzione **NON è permesso**.
- Anche se il codice è pubblico su GitHub, l'integrazione in pantedu
  (EUPL-1.2) creerebbe contaminazione di licenza non-compatibile.

> Cfr. <https://choosealicense.com/no-permission/>:
> *"When you make a creative work [...] the work is under exclusive
> copyright by default. Unless you include a license that specifies
> otherwise, nobody else can copy, distribute, or modify your work
> without being at risk of take-downs, shake-downs, or litigation."*

Anche `SECURITY.md` mancante → no policy disclosure → no vendor channel.

## 3. Signal aggiuntivi negativi

- **3 stars** — community molto piccola, peer review limitato.
- npm page `@accesslint/mcp` ritorna 403 — può essere normale ma combinato col resto è red flag.
- README mostra errori "There was an error while loading. Please reload this page" → metadata loading parziale.

## 4. Verdict

### ❌ REJECT

**Motivazione primaria**: assenza di licenza esplicita rende l'uso
legalmente rischioso. Anche se il maintainer intendeva rilasciarlo
open source, fino a quando non aggiunge `LICENSE` (es. MIT/Apache/EUPL)
non possiamo:

- Includerlo in dipendenze pantedu
- Citarlo come tool integrato nel nostro CI/CD
- Anche solo importarne le idee/codice (rischio derivative work)

**Motivazioni secondarie**:
- Mancanza SECURITY.md (no canale disclosure)
- Community molto piccola (3⭐, peer review limitato)
- accessibility-agents (auditato in parallelo) copre già lo stesso
  scope (WCAG 2.1 AA + 2.2 AA) con licenza MIT chiara e community
  più ampia (282⭐).

**Alternativa**: accessibility-agents fornisce reviewer agent +
ARIA-specialist + Contrast-master che coprono i feature unique di
AccessLint senza issue di licenza.

### Re-evaluation trigger

Se AccessLint aggiunge `LICENSE` MIT/Apache/EUPL in futuro:
- Re-audit con verifica licenza compat con EUPL-1.2
- Se OK e supera altri criteri, considerare integrazione come
  secondary cross-check tool.

### Sign-off

| Data | Auditor | Verdict |
|---|---|---|
| 2026-05-23 | {{OPERATORE_NOME}} (via Claude Code session) | REJECT — re-evaluate se aggiungono LICENSE |
