# Install Guide — accessibility-agents (Claude Code workstation)

> ⚠️ Eseguire **dopo** aver letto
> [`accessibility-agents-audit-2026-05-23.md`](accessibility-agents-audit-2026-05-23.md)
> e accettato le conditions APPROVE CONDITIONAL.

Questa guida installa `Community-Access/accessibility-agents` v5.4.0
nell'ambiente Claude Code di Operatore (Windows workstation).
La scansione CI/CD pantedu è già configurata in
[`.github/workflows/a11y.yml`](../../../.github/workflows/a11y.yml) e
**non richiede questa installazione locale**.

L'install locale serve per:
- Usare i 81 agenti Claude Code specialisti durante development
- Avere MCP server locale per scan on-demand su file aperti
- Cross-platform support (VS Code Copilot, Gemini CLI, Codex)

## Pre-condizioni

- Node.js 18+ installato (`node --version`)
- Git installato
- Claude Code CLI installata e funzionante
- Politica pantedu: NO `curl | bash` install. Clone first.

## Procedura step-by-step

### 1. Clone in directory dedicata

```powershell
$INSTALL_DIR = "$env:USERPROFILE\tools\accessibility-agents"
New-Item -ItemType Directory -Force -Path $INSTALL_DIR | Out-Null
git clone --depth 1 --branch v5.4.0 https://github.com/Community-Access/accessibility-agents.git $INSTALL_DIR
cd $INSTALL_DIR
```

### 2. Verifica integrità

```powershell
git log -1 --format="%H %s" v5.4.0
# Verifica che combaci con https://github.com/Community-Access/accessibility-agents/releases/tag/v5.4.0
Get-Content mcp-server/package.json | Select-String "node"
# Expected: "engines": { "node": ">=18.0.0" }
```

### 3. Dry-run installer

```powershell
bash install.sh --dry-run --no-auto-update
# Windows pure-PowerShell:
# .\install.ps1 -DryRun -NoAutoUpdate
```

Osserva l'output. Verifica:
- Target paths sono `.claude/` (project) o `~/.claude/` (user)
- NON tocca path di sistema
- NON modifica file pantedu

Se qualcosa non torna → STOP, segnala via issue al repo originale.

### 4. Install (solo dopo dry-run OK)

#### Opzione A: PROJECT-level (consigliato per pantedu)

```powershell
cd C:\Users\vitto\progetti_vscode\pantedu
bash $INSTALL_DIR/install.sh --project --no-auto-update
```

I 81 agenti disponibili SOLO in sessioni Claude Code nel working dir
pantedu. NO bleed verso altri progetti.

#### Opzione B: USER-level (globale)

```powershell
bash $INSTALL_DIR/install.sh --no-auto-update
```

Installa in `~/.claude/agents/`. Più comodo multi-progetto.

### 5. Configura MCP server (opzionale, scan on-demand)

```powershell
cd $INSTALL_DIR/mcp-server
npm ci
node server.js &
netstat -an | findstr 3100
# Atteso: 127.0.0.1:3100 (NON 0.0.0.0:3100)
```

Aggiungi a `~/.claude/settings.json`:

```json
{
  "mcpServers": {
    "a11y-agent-team": {
      "type": "http",
      "url": "http://127.0.0.1:3100/mcp"
    }
  }
}
```

Riavvia Claude Code. Verifica con `/mcp` che il server appaia connesso.

### 6. Sandbox first-run

```powershell
cd C:\Users\vitto\progetti_vscode\pantedu
git worktree add C:\Users\vitto\sandbox\pantedu-a11y-test main
cd C:\Users\vitto\sandbox\pantedu-a11y-test
# Apri Claude Code, attiva accessibility-lead agent per audit
```

Monitoring durante primo run:
- `netstat -an | findstr 3100` periodico — verifica solo 127.0.0.1
- `Get-Process node` per CPU/RAM anomalo
- Process Monitor (Sysinternals) per file/network activity sospetta

## Disinstall

```powershell
cd $INSTALL_DIR
bash uninstall.sh --project   # project-level
# o:
bash uninstall.sh             # user-level

# Cleanup MCP: edita ~/.claude/settings.json, rimuovi "a11y-agent-team"
```

## Re-audit policy

Verifica nuova versione ogni 30 giorni:

```powershell
cd $INSTALL_DIR
git fetch --tags
git tag --sort=-v:refname | Select-Object -First 1
```

Se nuovo major (es. v6.x) → re-audit completo seguendo checklist
in `accessibility-agents-audit-2026-05-23.md`.

## Troubleshooting

### MCP server non si avvia

Verifica Node version: `node --version` deve essere >= 18.

### Agent files non visibili in Claude

- Project-level: verifica `.claude/agents/` esiste in working dir
- User-level: verifica `~/.claude/agents/`
- Restart Claude Code dopo install

### Network egress sospetto

Se Process Monitor mostra connessioni a domini fuori da `localhost`,
`github.com`, `npmjs.com`:
1. Stop immediato del processo
2. `bash uninstall.sh`
3. Apri issue su <https://github.com/Community-Access/accessibility-agents/issues>
4. Aggiungi finding in `docs/security/third-party-tools/`

## CI integration (già attiva, no install richiesto)

La scansione automatizzata in GitHub Actions è già attiva via:
- `.github/workflows/a11y.yml` (push/PR su main)
- `a11y-baseline.sarif` (baseline checked-in)
- accessibility-agents/action@v5.4.0 (pinned version)

Questo NON richiede install locale: gira sui runner GitHub.

## Riferimenti

- Audit completo: [`accessibility-agents-audit-2026-05-23.md`](accessibility-agents-audit-2026-05-23.md)
- Repo: <https://github.com/Community-Access/accessibility-agents>
- CHANGELOG: <https://github.com/Community-Access/accessibility-agents/blob/main/CHANGELOG.md>
- Issue tracker: <https://github.com/Community-Access/accessibility-agents/issues>
