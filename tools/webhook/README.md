# GitHub Webhook + Auto-deploy (Phase 25.R.21 — Opzione 4)

**Aggiornato 2026-05-22**: auto-deploy ATTIVO via systemd Path unit.
Push su `master_vps` → deploy automatico entro ~15s. Nessun comando manuale richiesto.

## Architettura corrente (Phase 25.R.21)

```
┌──────────┐                                      ┌───────────┐
│ Laptop   │ git push origin master_vps           │  GitHub   │
│ Vittorio │ ───────────────────────────────────► │ master_vps│
└──────────┘                                      └─────┬─────┘
                                                        │ webhook
                                                        │ HMAC sha256
                                                        │ POST /_hooks/github
                                                        │ (porta 443 HTTPS)
                                                        ▼
                              ┌──────────────────────────────────────────┐
                              │ VPS (tex.pantedu.eu)                  │
                              │                                          │
                              │ ┌──────────────────────────────────────┐ │
                              │ │ nginx → PHP-FPM (sandboxed)          │ │
                              │ │   tools/webhook/github.php           │ │
                              │ │   1. HMAC verify (secret root-only)  │ │
                              │ │   2. event=push + ref=master_vps     │ │
                              │ │   3. rate-limit (max 1/30s)          │ │
                              │ │   4. atomic write trigger file       │ │
                              │ └────────────┬─────────────────────────┘ │
                              │              │                            │
                              │              ▼                            │
                              │ /var/lib/pantedu-deploy/trigger         │
                              │  (www-data:www-data, 0640)                │
                              │              │                            │
                              │              ▼ inotify PathChanged        │
                              │ ┌──────────────────────────────────────┐ │
                              │ │ systemd: pantedu-deploy.path        │ │
                              │ │   triggers: pantedu-deploy.service  │ │
                              │ └────────────┬─────────────────────────┘ │
                              │              │                            │
                              │              ▼                            │
                              │ ┌──────────────────────────────────────┐ │
                              │ │ ExecStartPre:                         │ │
                              │ │  /usr/local/bin/pantedu-deploy-     │ │
                              │ │    trigger.sh                         │ │
                              │ │  - replay protection (delivery UUID)  │ │
                              │ │ ExecStart:                            │ │
                              │ │  /usr/local/bin/pantedu-deploy.sh   │ │
                              │ │  (gira come root)                     │ │
                              │ │  - git reset --hard origin/master_vps │ │
                              │ │  - migrations + systemd sync          │ │
                              │ │  - reload php-fpm                     │ │
                              │ └──────────────────────────────────────┘ │
                              └──────────────────────────────────────────┘
```

## Sicurezza

**Privilege separation a 3 stadi:**

1. PHP-FPM (utente `www-data`, sandbox systemd) può scrivere SOLO 1 file in 1 dir dedicata (`/var/lib/pantedu-deploy/`).
2. Il file viene letto da systemd (kernel inotify).
3. Il deploy script viene eseguito come root, ma da systemd, **non** da PHP.

**Cosa l'attaccante NON ottiene anche se buca PHP (RCE):**

- ❌ Niente escalation a root (sandbox PHP-FPM intatta: `NoNewPrivileges`, `ProtectSystem=strict`, `ReadWritePaths` ristretti)
- ❌ Niente modifica del deploy script (`/usr/local/bin/pantedu-deploy.sh` resta root-owned, fuori dalle scritture di www-data)
- ❌ Niente deploy di codice malevolo (`deploy.sh` pulla da `master_vps` su GitHub → serve ANCHE push access)
- ❌ Replay attack (`delivery` UUID confrontato con `last-uuid` file, skip se identico)

**Cosa l'attaccante PUÒ fare (mitigato):**

- ⚠️ Triggerare deploy spurii (con UUID diversi) → DoS leggero CPU/IO
- **Mitigazione**: rate-limit lato PHP (max 1 trigger ogni 30s su `filemtime(trigger)`)

**Confronto vs alternative valutate:**

| Approccio | Trade-off |
|---|---|
| GHA SSH (port 2222) | Hetzner Cloud Firewall bloccava IP runner Azure |
| Webhook + sudo www-data | Avrebbe richiesto `NOPASSWD` su www-data → PHP RCE = root |
| **Opzione 4 (corrente)** | **No port aggiuntive, sandbox intatta, privilege sep 3 stadi** |

## Componenti

| File | Ruolo |
|---|---|
| `tools/webhook/github.php` | Endpoint webhook GitHub. HMAC verify + atomic write trigger file. |
| `tools/webhook/install_auto_deploy.sh` | Setup idempotente di tutta l'infrastruttura. |
| `tools/systemd/pantedu-deploy.path` | inotify watcher sul trigger file. |
| `tools/systemd/pantedu-deploy.service` | Service oneshot eseguito al cambio del trigger. |
| `tools/systemd/pantedu-deploy-trigger.sh` | Pre-exec script: replay protection via delivery UUID. |
| `tools/systemd/php8.4-fpm.service.d/pantedu-auto-deploy.conf` | Drop-in che aggiunge `/var/lib/pantedu-deploy` allo whitelist PHP-FPM. |

## Workflow developer

Semplicemente:

```bash
git push origin master_vps
```

Atteso: ~15s dopo, VPS al nuovo commit. Verifica:

```bash
# Locale
git rev-parse HEAD

# VPS
ssh pantedu-vps "cd /var/www/pantedu && git rev-parse HEAD"
```

Se diversi (oltre 30s dopo), guarda log:

```bash
ssh pantedu-vps "sudo tail -n 30 /var/log/pantedu-deploy.log"
```

## Deploy manuale (fallback)

Se l'auto-deploy non parte (es. webhook GitHub down, systemd unit fermo):

```bash
ssh pantedu-vps 'sudo /usr/local/bin/pantedu-deploy.sh'
```

Funziona sempre indipendentemente dallo stato del webhook.

## Troubleshooting

### Auto-deploy non parte

1. **Webhook arrivato?** Verifica access log nginx:
   ```bash
   ssh pantedu-vps "sudo tail -n 20 /var/log/nginx/pantedu-webhook.access.log"
   ```
2. **HMAC verify ok?** Log webhook:
   ```bash
   ssh pantedu-vps "sudo tail -n 20 /var/log/pantedu-deploy.log | grep webhook"
   ```
3. **Trigger file scritto?**
   ```bash
   ssh pantedu-vps "sudo ls -la /var/lib/pantedu-deploy/ && sudo cat /var/lib/pantedu-deploy/trigger"
   ```
4. **Path unit attivo?**
   ```bash
   ssh pantedu-vps "sudo systemctl status pantedu-deploy.path"
   ```
5. **Service eseguito?**
   ```bash
   ssh pantedu-vps "sudo systemctl status pantedu-deploy.service && sudo journalctl -u pantedu-deploy.service -n 50"
   ```

### Reinstalla infrastruttura

Idempotente:

```bash
ssh pantedu-vps "sudo bash /var/www/pantedu/tools/webhook/install_auto_deploy.sh"
```

(Eseguito automaticamente da `deploy.sh` quando i file `tools/systemd/*` o `install_auto_deploy.sh` cambiano nel commit corrente.)

### Rollback completo

Per tornare a "audit-only" (Phase 25.N):

```bash
ssh pantedu-vps "sudo systemctl disable --now pantedu-deploy.path && sudo rm /etc/systemd/system/pantedu-deploy.{path,service} /etc/systemd/system/php8.4-fpm.service.d/pantedu-auto-deploy.conf /usr/local/bin/pantedu-deploy-trigger.sh && sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm"
```

Deploy manuale via `ssh pantedu-vps 'sudo /usr/local/bin/pantedu-deploy.sh'` resta sempre disponibile.

## Hetzner Cloud snapshot pre-deploy

Lo script [hetzner_snapshot.sh](hetzner_snapshot.sh) crea uno snapshot Hetzner Cloud del VPS PRIMA di ogni deploy (no-op se config mancante). Costo: ~€0.95/snapshot/mese, retention default 2 → ~€1.90/mese.

### Setup iniziale (manuale, una sola volta)

1. **Genera API token** su https://console.hetzner.cloud → Security → API Tokens
   - Permissions: **Read + Write**
   - Scope: progetto del VPS pantedu
2. **Trova nome server**: `hcloud server list` (se hcloud CLI installato) o leggi dalla console Hetzner.
3. **Crea config sul VPS**:
   ```bash
   ssh pantedu-vps
   sudo cp /var/www/pantedu/tools/webhook/hetzner-api.env.example /etc/pantedu/hetzner-api.env
   sudo nano /etc/pantedu/hetzner-api.env  # sostituisci placeholder
   sudo chmod 600 /etc/pantedu/hetzner-api.env
   sudo chown root:root /etc/pantedu/hetzner-api.env
   ```
4. **Installa lo script** (one-shot, poi deploy.sh lo aggiorna automaticamente):
   ```bash
   sudo install -m 700 -o root -g root /var/www/pantedu/tools/webhook/hetzner_snapshot.sh /usr/local/sbin/hetzner_snapshot.sh
   ```

### Snapshot manuale

```bash
ssh pantedu-vps "sudo /usr/local/sbin/hetzner_snapshot.sh manual-$(date +%Y%m%d-%H%M%S)"
```

### Verifica snapshot creati

```bash
ssh pantedu-vps 'source /etc/pantedu/hetzner-api.env && curl -sf -H "Authorization: Bearer $HETZNER_API_TOKEN" https://api.hetzner.cloud/v1/images?type=snapshot | python3 -m json.tool'
```

O via Hetzner Console → server → Snapshots tab.

### Rotation automatica

Lo script tiene gli ultimi `SNAPSHOT_RETENTION_COUNT` snapshot con label `auto-*` o `pre-deploy-*`. Gli snapshot `manual-*` (label custom) sono preservati indefinitamente — vanno cancellati a mano se non più necessari.

## Cloudflare cache purge (optional, recommended)

Setup `/etc/pantedu-deploy.env` su VPS per auto-purge CF cache ad ogni deploy.

Senza setup: cache CF (max-age 7 giorni su CSS/JS) può servire content stantio
per giorni dopo push. Sintomo: VPS rendering ≠ locale anche dopo `git push`.

**Setup one-time:**

```bash
# CF Dashboard → Profile → API Tokens → Create
# Scope: Zone:Cache Purge / Purge / Zone: pantedu.eu

ssh pantedu-vps
sudo tee /etc/pantedu-deploy.env > /dev/null <<EOF
CF_API_TOKEN=<your token from CF dashboard>
CF_ZONE_ID=<your-cloudflare-zone-id>
EOF
sudo chmod 600 /etc/pantedu-deploy.env
sudo chown root:root /etc/pantedu-deploy.env
```

Vedi `docs/ops/vps-info.md` sezione "Cloudflare deploy sync" per dettagli completi.

`deploy.sh` rileva file env via `[[ -f /etc/pantedu-deploy.env ]]` e fa purge
automatico di `main.bundle.css` post-build. No-op silent se file non esiste.

## Storia

- **Phase 25.N** (Opzione E, 2026-05-19): webhook solo audit log per non rompere PHP sandbox. Deploy manuale via SSH alias.
- **Phase 25.R.20** (rejected): tentativo GHA SSH bloccato da Hetzner Cloud Firewall su port 2222.
- **Phase 25.R.21** (corrente, 2026-05-22): webhook + systemd Path unit. Auto-deploy senza compromettere sandbox.
- **Phase 25.R.22** (2026-05-23): hetzner_snapshot.sh path fix `/etc/fismapant/` → `/etc/pantedu/` + commit script in repo + auto-install via deploy.sh.
- **Phase 25.R.23** (2026-05-24): VPS sync fix definitivo. PHP `clearstatcache` + `systemctl restart` (no reload) + CF API purge tramite `/etc/pantedu-deploy.env`. Root cause: PHP-FPM workers pool con realpath_cache_ttl per-worker servivano URL `?v=OLD_MTIME` post-build → CF cachava response → browser stale.
