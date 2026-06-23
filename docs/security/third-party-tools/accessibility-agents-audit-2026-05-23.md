# Audit: Community-Access/accessibility-agents

| Campo | Valore |
|---|---|
| Tool | `accessibility-agents` |
| Publisher | [Community-Access](https://github.com/Community-Access) (founded by Taylor Arndt + Jeff Bishop) |
| Repository | <https://github.com/Community-Access/accessibility-agents> |
| Versione auditata | v5.4.0 (release 2026-05-06) |
| Licenza | **MIT** ✓ |
| Stars / Last commit | 282 ⭐ / activity continua (release 2026-05-06) |
| Data audit | 2026-05-23 |
| Auditor | Vittorio Pantaleo + Claude Code session |
| Verdict | **APPROVE CONDITIONAL** |

## 1. Source repo inspection

### Struttura repo
```
LICENSE (MIT) ✓
SECURITY.md ✓ (vuln disclosure documentata)
README.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md ✓
CHANGELOG.md ✓ (versioning attivo)
ROADMAP.md ✓
manifest.json, plugin.yaml (config metadata)
install.sh, install.ps1 (installer multi-OS)
.github/workflows/ ✓ (CI)
docs/ ✓
mcp-server/ (Node.js, descritto sotto)
.claude/, .claude-plugin/ (Claude Code agents)
.codex/, .gemini/ (cross-platform support)
claude-code-plugin/, vscode-extension/ (IDE integrations)
go-cli/ (native Go CLI)
action/ (GitHub Actions)
scripts/, templates/, example/
```

### Commit history
- Repo attivo, ultimo release v5.4.0 il 2026-05-06 (17 giorni fa).
- Maintainer noti pubblicamente (non anonimi).
- Nessun commit suspect rilevato in spot-check head.

## 2. Dependency review

Dipendenze MCP server (`mcp-server/package.json`):

| Pacchetto | Tipo | Note |
|---|---|---|
| `@modelcontextprotocol/sdk` | required | SDK ufficiale Anthropic MCP |
| `zod` | required | Validation library mainstream |
| `@axe-core/playwright` | optional | Stessa che usiamo noi |
| `playwright` | optional | Browser automation, ufficiale Microsoft |
| `pdf-lib` | optional | PDF parsing, mainstream JS |

Nessuna dipendenza obscure/unmaintained nel manifest principale.

## 3. MCP server endpoint scrutiny

### Bind address default
**`127.0.0.1:3100`** — solo localhost. ✅ Sicuro by default.

Override possibile via env var `A11Y_MCP_HOST`. **Policy pantedu:
NON cambiare a `0.0.0.0` o IP esterno**.

### Transport
- HTTP (default, su 127.0.0.1:3100)
- stdio (fallback per CLI usage)

### Auth
**Nessuna auth** sul server MCP locale.
- Accettabile perché bind localhost-only.
- Mitigazione: nessun altro user dovrebbe poter accedere a 127.0.0.1
  sulla macchina di sviluppo (multi-user Windows = rischio se altri
  user su stessa workstation; non il nostro caso).

### Network egress
Le scansioni `axe-core` lavorano in-browser, no network esterno.
Playwright può navigare URL pubblici se richiesto, ma è opt-in.
`pdf-lib` è offline.

**Conclusione: minima superficie network egress.**

## 4. Installer security

### install.sh / install.ps1
- Supporta `--dry-run` (preview senza modifiche) ✓
- Supporta `--no-auto-update` (disabilita auto-update) ✓
- Scope project-level (`.claude/`) o globale (`~/.claude/`) ✓
- Validates Node 18+ ✓
- Rollback metadata + backup manifests ✓

### Warning policy
- **Non installare via `curl | bash` pipe**: scarica dinamicamente
  source da GitHub. Politica pantedu: clonare prima il repo,
  ispezionare, poi installare da local clone.

## 5. Disclaimer ufficiale del progetto

Citazione literal dal README:

> "AI and automated tools are not perfect. They miss things, make
> mistakes, and cannot replace testing with real screen readers and
> assistive technology."

> "Always verify with VoiceOver, NVDA, JAWS, and keyboard-only
> navigation. This tooling is a helpful starting point, not a
> substitute for real accessibility testing."

Questo è IL disclaimer corretto. Il maintainer è onesto sui limiti.

## 6. Verdict

### ✅ APPROVE CONDITIONAL

**Conditions per installazione in ambiente di lavoro pantedu:**

1. **Clone first, install second** — NO `curl | bash` pipe:
   ```bash
   git clone --depth 1 --branch v5.4.0 \
       https://github.com/Community-Access/accessibility-agents.git \
       /tmp/a11y-agents-audit
   cd /tmp/a11y-agents-audit
   # Verifica integrita': git log --show-signature -1 (se firmati GPG)
   bash install.sh --dry-run     # Preview
   bash install.sh --no-auto-update  # Install effettivo
   ```

2. **MCP server bind localhost-only** — non override `A11Y_MCP_HOST`.

3. **Disable auto-update** — `--no-auto-update`, ricontrolla version
   bumps manualmente prima di aggiornare.

4. **Sandbox primo run** — primo audit completo in worktree git
   separato (`git worktree add /tmp/pantedu-a11y-sandbox`),
   monitora con `netstat`/`tcpdump` per verificare network egress.

5. **Settings esplicit** — `~/.claude/settings.json` deve avere
   l'MCP server WHITELISTED esplicitamente, no auto-discovery.

6. **Re-audit policy** — re-audit ogni major bump (es. v5 → v6).

### Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Compromised release via maintainer account hijack | Bassa | Alto | Pin version + manual upgrade |
| Network egress leak via Playwright misuse | Bassa | Medio | Sandbox primo run + audit log |
| Localhost MCP server reached da altri user su Win shared | N/A | — | Single-user workstation |
| Auto-update fetches new code without review | Media (se enabled) | Alto | `--no-auto-update` flag |

### Sign-off

| Data | Auditor | Hash repo head auditato | Verdict |
|---|---|---|---|
| 2026-05-23 | Vittorio Pantaleo (via Claude Code session) | v5.4.0 release tag | APPROVE CONDITIONAL |

---

**Prossimo step**: Audit AccessLint, poi CI/CD Accessibility Agent, poi
WCAG Accessibility Compliance. Dopo cumulative review, decisione di
install / skip per ciascuno.
