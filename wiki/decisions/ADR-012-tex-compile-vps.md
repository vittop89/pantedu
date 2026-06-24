---
tags:
  - documentazione/decisione
date: 2026-05-05
tipo: architectural-decision
status: accettato
phase: G21
---

# ADR-012 — Server-side LaTeX compile via VPS dedicato (tex-compile-vps)

## Status

✅ **Accepted** — implementato in Phase G21 + G21.1 (2026-05-05).
- G21:   PoC server-side compile validato end-to-end
- G21.1: Preview modal full-screen con TeX↔PDF + SyncTeX bidirezionale +
         CodeMirror 6 editing inline + compare mode + auto-rebuild

VPS: Hetzner CAX11 (ARM64, Debian 13), 5,48 €/mese, no commitment.

## Contesto

Pantedu gira su hosting condiviso (legacy) Linux che **non dispone di TeX
Live**. Il workflow di generazione PDF era quindi delegato a flow manuali:
- **Overleaf**: docente manda .tex a Overleaf, compila lì, scarica PDF
- **VSCode locale**: docente apre .tex in VSCode con MiKTeX/TeX Live
  installati localmente, compila, ottiene PDF

Limitazioni:
1. **UX frammentata**: il PDF non è disponibile dentro l'app, l'utente
   deve uscire dal sito → compilare altrove → caricare manualmente.
2. **Setup oneroso lato docente**: Overleaf richiede account, VSCode +
   MiKTeX richiedono installazione client di alcuni GB.
3. **Niente PDF in archivio pantedu**: la verifica salvata nel DB ha
   solo il sorgente .tex (cifrato), non il PDF compilato.
4. **Impossibile previeware**: l'admin non vede mai come appare la
   verifica stampata senza ricompilare manualmente.
5. **Dipendenza da terze parti**: Overleaf è gratis ma con quote.

## Decisione

Adottare un'**architettura ibrida**:
- Sito pantedu resta su **hosting legacy shared** (no migrazione, zero rischio
  produzione)
- Compile LaTeX delegato a un **VPS dedicato** minimale
  (`tex.pantedu.eu`, Hetzner CAX11) che espone un microservizio HTTP
  con endpoint `POST /compile` HMAC-protected
- Il VPS è **stateless**: riceve `.tex` + `doc_id`, ritorna PDF binario;
  nessun database, nessun storage persistente, nessuna PII trattenuta

### Stack scelto

| Layer | Tecnologia | Motivo |
|-------|-----------|--------|
| OS VPS | Debian 13 (trixie) | LTS, TeX Live ufficiale, ARM64 supportato |
| Provider | Hetzner CAX11 (ARM Ampere) | 5,48 €/mese, no commitment, pay-per-hour |
| Runtime | Python 3.13 + FastAPI 0.115 | async I/O nativo, validazione Pydantic |
| HTTP server | uvicorn (--workers 2) | sufficiente per carico atteso |
| Reverse proxy | nginx + Let's Encrypt | TLS gratis, rate limit, HSTS |
| Engine | TeX Live curato (~2GB apt) | scheme-medium copre 99% dei template scolastici (tikz, fontspec, physics, mathtools, ecc.) |
| Auth | HMAC-SHA256 + timestamp window 300s | semplice, sicuro, no JWT/OAuth overhead |
| Hardening | systemd `PrivateTmp`, `User=texcompile`, ufw firewall | minimal attack surface |

### Flow integrazione

```
[Docente clicca TEX/PDF in topbar]
  ↓
[topbar-modern.js doSalvaTex]
  ↓ POST /api/verifica/save-tex-batch (PHP, hosting legacy)
[VerificaController::saveTexBatch] → salva 4-8 varianti in DB cifrate
  ↓ ritorna {docs: [{id, variant}, ...]}
[topbar-modern.js compilePdfForDocs(docs)]
  ↓ Promise pool max 4 concorrenti
  for each doc:
    POST /api/verifica/{id}/compile (PHP, hosting legacy)
      ↓ legge .tex via VerificaDocumentService::readTex
      ↓ POST /compile (PHP TexCompileClient → VPS HMAC)
        ↓ pdflatex sandbox tmpdir + nonstopmode
      ← application/pdf binary
      ↓ VerificaDocumentService::attachPdf (cifra + salva)
    ← {ok:true, doc:{...}, compile:{duration_ms, engine}}
  ↓
[Toast: "8/8 PDF compilati e salvati"]
```

## Alternative considerate

### A. Migrare tutto su VPS (sito + compile)

**Pro**: una sola infrastruttura, niente latenza rete tra app e compile.
**Contro**: migrazione completa one-shot, downtime, rischio produzione,
abbandono investimento hosting legacy esistente.
**Verdetto**: scartata. L'opzione resta valida per il futuro se hosting legacy
dovesse limitare ulteriormente l'app.

### B. Estensione VSCode dedicata (`pantedu-verifica.vsix`)

**Pro**: CPU compile sul client, server scala lineare.
**Contro**: ogni docente deve installare VSCode + MiKTeX/TeX Live +
estensione (3 step di setup), gestione aggiornamenti complessa,
incompatibile con utenti che non usano VSCode.
**Verdetto**: scartata per priorità adozione (target docenti scolastici
con basso skill IT).

### C. Browser extension + Native Messaging Host

**Pro**: trigger nel browser senza nuovo runtime server.
**Contro**: doppia install (estensione + host nativo + registry entry),
warnings antivirus, friction massima per setup.
**Verdetto**: scartata.

### D. Server-side compile (scelta adottata)

**Pro**: zero install lato client, server-side scaling indipendente,
servizio universale (qualunque template pantedu funziona uguale per
tutti).
**Contro**: richiede VPS dedicato (~5€/mese), due sistemi da mantenere
(hosting legacy + VPS), latenza rete ~250ms aggiuntiva per compile.
**Verdetto**: ✅ scelta. Trade-off ottimale per UX docente + costo.

## Conseguenze

### Positive

- **UX semplificata**: un click sul button TEX/PDF salva il sorgente
  E genera tutti i PDF (4-8 varianti) automaticamente
- **Zero install lato utente**: nessun setup TeX Live/Overleaf richiesto
- **PDF in archivio pantedu**: cifrato envelope ADR-006, sfogliabile,
  esportabile via /api/verifica/{id}/pdf
- **Scaling indipendente**: il VPS si può potenziare (CPX21 → CPX31)
  senza toccare hosting legacy
- **Rollback istantaneo**: se il VPS ha problemi, basta lasciare
  `TEX_COMPILE_ENDPOINT` vuoto in `.env` per disabilitare l'integrazione;
  il TEX resta sempre salvato (PDF non bloccante per il save)
- **Pacchetto TeX completo**: tutti i pacchetti scolastici disponibili
  (tikz, physics, mathtools, fontspec, circledsteps, ecc.)

### Negative / Trade-off

- **Costo runtime aggiuntivo**: 5,48 €/mese (~66 €/anno)
- **Due sistemi da mantenere**: aggiornamenti TeX Live, rinnovo cert TLS
  (auto via certbot), monitoring uptime VPS
- **Latenza rete**: ~250ms round-trip hosting legacy ↔ Hetzner DE per ogni compile;
  trascurabile per compile sincrono di 1-5s, percepibile per batch grosso
- **Single point of failure**: se VPS down, nessun PDF (ma TEX comunque
  salvato; mitigation futura: fallback a job queue + retry async)
- **Segreto HMAC da custodire**: rotabile via `provision.sh` step 5,
  richiede aggiornamento simultaneo di `.env.local` su hosting condiviso

## Sicurezza

- **TLS Let's Encrypt** obbligatorio (HSTS, redirect HTTP→HTTPS automatico)
- **HMAC-SHA256** sul body con timestamp anti-replay window 300s
- **Rate limit nginx**: 20 req/min per IP, body max 5 MB
- **pdflatex sandbox**: `-no-shell-escape` blocca `\write18`,
  `-interaction=nonstopmode` evita blocking interactive prompts,
  tmpdir isolato cleanup post-compile
- **systemd hardening**: `User=texcompile` no-shell, `PrivateTmp=yes`,
  `ProtectKernelModules`, `MemoryMax=2G`, `CPUQuota=350%`
- **ufw firewall**: solo SSH (key auth) + HTTP/HTTPS aperti
- **Niente PII persistita lato VPS**: i .tex sono ricevuti, compilati,
  PDF tornato, tmpdir wipato. Log applicativo include solo metadata
  (doc_id, duration_ms, engine), MAI contenuto .tex.

## Note operative

### Disabilitare integrazione (rollback rapido)

Lasciare `TEX_COMPILE_ENDPOINT` vuoto in `.env` (commit safe) o in
`.env.local`. Il `compilePdfForDocs` JS continuerà a chiamare l'endpoint
PHP che risponderà 503 `tex_compile_disabled`. Il SalvaTEX resta
funzionante (TEX salvato), solo il PDF non viene generato.

### Monitoring base

```bash
# Uptime VPS
curl -fsS https://tex.pantedu.eu/health || alert

# Logs servizio
journalctl -u tex-compile -n 50 -f

# Cert TLS expiry
echo | openssl s_client -connect tex.pantedu.eu:443 2>/dev/null | openssl x509 -noout -dates
```

### Rotazione segreto HMAC

```bash
# Sul VPS
NEW_SECRET=$(openssl rand -hex 32)
sudo sed -i "s|^TEX_COMPILE_SECRET=.*|TEX_COMPILE_SECRET=$NEW_SECRET|" /opt/tex-compile/.env
sudo systemctl restart tex-compile

# su hosting condiviso: aggiorna .env.local con stesso valore (zero downtime se fatto entro 1 min)
```

## Riferimenti

- Sorgente: `tools/tex-compile-vps/`
- Deploy guide: `tools/tex-compile-vps/DEPLOY.md`
- Client PHP: `app/Services/TexCompile/TexCompileClient.php`
- Config: `app/Config/tex_compile.php`
- Integration example: `tools/tex-compile-vps/client/IntegrationExample.md`
- Changelog entry: `wiki/changelog/2026-05.md` (2026-05-05)
- Hetzner CAX11 specs: https://www.hetzner.com/cloud/
