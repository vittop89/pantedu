# Security — toolchain e procedure

Documentazione single-source per security audit di pantedu. Allineato con [`docs/todo/prompt_security.md`](../docs/todo/prompt_security.md) §12.

## Pentest 2026-05-18 — Quick scan via Burp MCP

Primo audit assistito Claude + Burp MCP Server (PortSwigger). 4 finding rilevati, 4 risolti o mitigati.

| ID | Severity | Status | Azione |
|----|----------|--------|--------|
| FND-001 polyfill.io in CSP | Medium | ✅ FIXED | Rimosso da middleware + 2 `.htaccess` + 15 HTML legacy |
| FND-002 unsafe-inline/eval CSP | Medium | ✅ FIXED (Track 7, 2026-06-03) | `'unsafe-eval'` rimosso (nessun eval/`Function` nel sorgente). Tutti gli `on*=` inline bonificati (view `.php` + template editor) → delegation/data-*. CSP `strict` (nonce + strict-dynamic) pronta, attivabile da `/admin/waf/config` o `CSP_MODE`; resta `'unsafe-inline'` per `<script>` finché non si flippa a strict. CI guard anti-regressione. |
| FND-003 X-Powered-By + Server | Low (info disc.) | ✅ FIXED | `header_remove()` PHP + `Header unset` .htaccess + `ServerTokens Prod` |
| FND-004 Cookie Secure | Info | ✅ ALREADY-CORRECT | Auto-detect HTTPS in `app/Config/session.php:10-13` |

Validation: re-scan via `mcp__burp__send_http1_request` pre/post fix. Dettagli in [`docs/todo/REMEDIATION-pentest-2026-04-29.md`](../docs/todo/REMEDIATION-pentest-2026-04-29.md).

## Toolchain installata

Tutti i tool sono in `C:\security_tools\` riorganizzata per categoria (vedi `C:\security_tools\_README.md`).

### Layout

```
C:\security_tools\
├─ sast\        (Semgrep, njsscan, Bandit, PHPStan, Psalm — Python in venv-py)
├─ sca\         (Trivy, osv-scanner, Grype, Syft)
├─ secrets\     (gitleaks, trufflehog, detect-secrets)
├─ dast\        (Nuclei + templates, Wapiti, Burp Community)
├─ recon\       (ffuf, httpx, subfinder, naabu, gobuster, feroxbuster)
├─ tls\         (testssl.sh, hexdump, sslyze)
├─ api\         (Schemathesis, Newman, Bruno, HTTPie)
├─ proxy\       (mitmproxy)
├─ pdf\         (Pandoc, qpdf)
├─ utility\     (jq, mailpit, gh)
├─ venv-py\     (Python virtualenv condiviso)
└─ Zed Attack Proxy\  (OWASP ZAP — non spostata, installer-based)
```

### PATH user

Tutti i bin sono in PATH user (vedi `C:\security_tools\_PATH-setup.ps1`). Per attivare modifiche: aprire nuovo terminale.

### Procedura audit completo

Sequenza tipica per audit pantedu (vedi `prompt_security.md` §13):

```bash
# Fase 0 — setup
git tag audit-$(date +%F)-baseline
mysqldump pantedu_dev > storage/backups/pre-pentest-$(date +%FT%H%M).sql
php tools/seed-pentest-users.php

# Fase 3 — SAST
semgrep --config=p/owasp-top-ten --config=p/php --config=p/javascript --json -o reports/semgrep.json
phpstan analyse --level=max src/
psalm --show-info=true

# Fase 4 — SCA + Secrets
composer audit
npm audit
osv-scanner -r .
trivy fs --scanners vuln,secret,misconfig .
gitleaks detect --log-opts="--all" --report-format json --report-path reports/gitleaks.json
detect-secrets scan --baseline .secrets.baseline

# Fase 5 — Config
trivy config .

# Fase 6 — DAST (su staging autorizzato)
testssl.sh https://beta.pantedu.eu
nuclei -u https://beta.pantedu.eu -t cves/ -t exposures/
# ZAP passive scan via proxy mentre navighi manualmente
# Burp Suite Community con MCP server (vedi sotto)

# Fase 7 — E2E con simulazione cliente reale (Playwright)
npx playwright test annex-5-e2e/playwright/cross-user-state-leak.spec.js

# Fase 11 — Cleanup
mysql pantedu_dev -e "DELETE FROM users WHERE username LIKE 'pentest-%'"
rm storage/backups/pre-pentest-*.sql
```

## Burp Suite Community + MCP integration

Burp Community ha lo scanner manuale ma non quello automatico. Con l'estensione **MCP Server** (rilasciata da PortSwigger) si trasforma l'agente AI (Claude Code) in scanner automatico per business logic.

### Setup

1. **Avviare Burp Community** (prima volta): `C:\security_tools\dast\burp\BurpSuite.exe`
   - Accetta Temporary Project (Community)
   - Use default settings
2. **Installare estensione MCP**:
   - Tab `Extensions` → `BApp Store` → cerca `MCP`
   - Click `Install` su "MCP Server" (PortSwigger official)
   - Conferma se richiede Jython/Python (Burp lo gestisce)
3. **Abilitare il server MCP**:
   - Tab `Extensions` → `Installed` → click `MCP Server`
   - Tab dell'estensione (sotto) → check `Start MCP server`
   - Annota porta (default `9876` o random)
4. **Configurare Claude Code** (vedi sotto `.claude/settings.json`)

### Configurazione Claude Code MCP

File `.claude/settings.json` del progetto:

```json
{
  "mcpServers": {
    "burp": {
      "type": "http",
      "url": "http://127.0.0.1:9876/sse"
    }
  }
}
```

Riavvia Claude Code per attivare. Verifica con prompt:
> "Lista le richieste recenti nella HTTP History di Burp"

### Esempi d'uso

**IDOR test su API endpoint**:
> "Vedi la HTTP History di Burp, prendi l'ultima richiesta verso `/api/v1/risdoc/view/16` e genera 20 tentativi con `id` da 1 a 20 nel Repeater di Burp. Riporta quale ID dà 200 OK vs 403/404."

**Test cross-user state isolation §4.5**:
> "Usa Burp per intercettare le richieste mentre faccio login come `pentest-teacher-a`, poi logout, poi login come `pentest-teacher-b`. Confronta i cookie + i payload `localStorage` setItem nelle response per identificare leak cross-user."

**JWT manipulation**:
> "Estrai il JWT bearer dalla richiesta più recente, decodificalo, e prova le 5 manipolazioni classiche: alg:none, key confusion HS256/RS256, exp esteso, scope escalation, sub swap. Riporta quali sono accettate dal server."

### Note legali

L'integrazione Burp Community + MCP è perfetta per **audit interno difensivo** (profilo `internal-only` o `gdpr-accountability` del prompt §15). **Non sostituisce** un report di pentest manuale firmato da professionista certificato per profilo `dpo-onboarding` (controparte scolastica) — vedi prompt §10.2.2 + Sezione K disclaimer.

## Filtri di sicurezza Claude/AI cloud

Claude (e altri LLM cloud commerciali) può rifiutarsi di generare payload pesanti se rileva attacco esplicito. Workaround:
- Quadrare la richiesta come audit difensivo (es. "sto auditando il MIO SaaS")
- Per payload weaponizzati: usare modello locale via **Ollama** (Qwen2.5-coder, DeepSeek-coder, Llama 3) attraverso `Burp AI Agent` (open source) invece di `MCP Server` PortSwigger ufficiale. Procedura completa: prompt **§12.20** (setup Ollama, collegamento Burp, loop operativi, vincoli)
- Limitarsi a PoC concettuali (vedi prompt §7.2)

## Registro versioni prompt (§17.4)

Hash SHA-256 da rigenerare a ogni modifica di `prompt_security.md` (entra nell'attestation §14.4 e nell'Allegato 1 §14.3 di ogni report firmato).

| Versione | Data | File | SHA-256 |
|----------|------|------|---------|
| 1.15 | 2026-06-10 | `prompt_security.md` (§12.20 red-team autonomo) | `153bda84e301ab61c6845868f5565915b8548cea9b5c12cac295df4777e40d13` |
| 1.14 | 2026-06-10 | `prompt_security.md` (split, snello) | `fb59930668a4cfee997223df790ccb61665e333ded19c7eda1519046f97f6eb1` |
| 1.13 | 2026-06-10 | `prompt_security_full.md` (backup pre-split) | `d5f6d79419733ff3c96db11ce6d7c76861a34eaf46d02c236e03e332ed47846f` |

File correlati (non allegati firmabili, riferimento operativo):
- `security-history.md` — `30de395eaa94335177b2c11472dab79624cd3bed7e47c9de48dac106b29512cb`
- `pantedu-context.md` — `b11c98aafedc1d08e42c921decf39e30e1085b1bd1f43d8964ac08688ffdb3f8`

Rigenerazione: `sha256sum docs/todo/prompt_security.md`.

## Riferimenti

- Prompt master: [`docs/todo/prompt_security.md`](../docs/todo/prompt_security.md)
- Archivio storico (lessons learned + test matrix): [`docs/todo/security-history.md`](../docs/todo/security-history.md)
- Context pack pantedu: [`docs/todo/pantedu-context.md`](../docs/todo/pantedu-context.md)
- Backup pre-split: `docs/todo/prompt_security_full.md`
- Inventario tool: `C:\security_tools\_README.md`
- PATH setup: `C:\security_tools\_PATH-setup.ps1`
- Burp Suite MCP docs: [portswigger.net/burp/documentation/desktop/extend-burp/mcp-server](https://portswigger.net/burp/documentation/desktop/extend-burp/mcp-server)
- Claude Code MCP docs: [docs.anthropic.com/claude-code/mcp](https://docs.anthropic.com/en/docs/claude-code/mcp)
