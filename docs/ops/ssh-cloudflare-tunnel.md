# Accesso SSH via Cloudflare Tunnel (Zero Trust)

Guida completa per configurare l'accesso SSH amministrativo al VPS **senza
esporre alcuna porta su Internet**. Sostituisce il vecchio modello "SSH porta
2222 + whitelist IP nel Cloud Firewall", che si rompeva a ogni cambio dell'IP
dinamico Telecom (incidente 2026-07-07).

## Architettura

```
Tuo PC ──ssh pantedu-tunnel──> cloudflared (ProxyCommand)
                                   │
                                   ▼
                          Cloudflare Access (auth email + MFA)
                                   │
                                   ▼ (tunnel in USCITA dal VPS)
                          cloudflared sul VPS ──> sshd su 127.0.0.1:2222
```

- **sshd ascolta SOLO su localhost** (`127.0.0.1:2222` + `[::1]:2222`) → non
  raggiungibile dall'esterno nemmeno se il firewall fosse aperto.
- Il VPS apre un **tunnel in uscita** verso Cloudflare (nessuna porta in
  ingresso). Ci si connette *attraverso* Cloudflare.
- **Cloudflare Access** autentica (solo email autorizzate; MFA/OTP se il
  browser non è già loggato).

## Prerequisiti
- Dominio gestito da Cloudflare (già il caso di `pantedu.eu`).
- Account Cloudflare con Zero Trust attivo (piano Free, fino a 50 utenti).

---

## Parte A — Configurazione sul VPS

> Da eseguire come root sul server. Eseguito una tantum (già fatto in prod
> 2026-07-07; qui per riferimento / ricostruzione).

### 1. Installa cloudflared
```bash
cd /tmp
curl -fsSL https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o cloudflared.deb
dpkg -i cloudflared.deb
cloudflared --version
```
> NB: su Debian 13 il repo apt di Cloudflare può fallire la verifica GPG
> (errore `sqv`/permessi). Il `.deb` diretto è più affidabile.

### 2. Autentica cloudflared (apre il browser)
```bash
cloudflared tunnel login
```
Copia l'URL stampato, aprilo nel browser, seleziona il dominio `pantedu.eu`,
autorizza. Salva `~/.cloudflared/cert.pem`.

### 3. Crea il tunnel
```bash
cloudflared tunnel create pantedu-ssh
cloudflared tunnel list          # annota lo UUID
```

### 4. Config ingress (SSH → localhost:2222)
```bash
mkdir -p /etc/cloudflared
cat > /etc/cloudflared/config.yml <<CFG
tunnel: <UUID>
credentials-file: /root/.cloudflared/<UUID>.json

ingress:
  - hostname: ssh.pantedu.eu
    service: ssh://localhost:2222
  - service: http_status:404
CFG
```

### 5. DNS route
```bash
cloudflared tunnel route dns pantedu-ssh ssh.pantedu.eu
```

### 6. Installa come servizio systemd (parte al boot)
```bash
cloudflared service install
systemctl enable --now cloudflared
cloudflared tunnel info pantedu-ssh    # verifica connessioni edge attive
```

### 7. Blindatura: sshd solo su localhost
```bash
cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup-$(date +%Y%m%d)
sed -i '/^ListenAddress/d' /etc/ssh/sshd_config
printf 'ListenAddress 127.0.0.1\nListenAddress ::1\n' >> /etc/ssh/sshd_config
sshd -t && systemctl restart ssh       # sshd -t valida PRIMA di riavviare
ss -tlnp | grep 2222                    # deve mostrare solo 127.0.0.1 e ::1
```
> **Sicurezza**: prima di riavviare sshd conviene armare un rollback
> automatico (`at now + 10 minutes` che ripristina il backup), da cancellare
> con `atq`/`atrm` una volta verificato che il tunnel funziona. Così non ci si
> taglia fuori se qualcosa va storto.

---

## Parte B — Configurazione sul PC client (Windows)

### 1. Installa cloudflared
```powershell
# metodo diretto (winget a volte fallisce):
$dir = "$env:LOCALAPPDATA\cloudflared"
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Invoke-WebRequest "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe" -OutFile "$dir\cloudflared.exe"
# aggiungi $dir al PATH utente (poi riapri il terminale)
```

### 2. `~/.ssh/config`
```
Host pantedu-tunnel
    HostName ssh.pantedu.eu
    User root
    IdentityFile ~/.ssh/id_ed25519
    ProxyCommand "C:/Users/<utente>/AppData/Local/cloudflared/cloudflared.exe" access ssh --hostname %h --log-level fatal
    AddKeysToAgent yes
    ServerAliveInterval 60
    ServerAliveCountMax 3
```
> ⚠️ **`--log-level fatal` è essenziale**: senza, cloudflared scrive messaggi
> INFO sullo stream e OpenSSH-for-Windows fallisce con
> `ssh_dispatch_run_fatal: Connection to UNKNOWN port 65535`.

### 3. Uso
```powershell
ssh pantedu-tunnel "hostname"
```
La prima volta ogni ~24h apre il browser per Cloudflare Access. Se sei già
loggato nel dashboard Cloudflare con l'email autorizzata, ti autentica in
silenzio (nessuna OTP email). L'OTP email arriva solo da browser non loggati.

---

## Parte C — Cloudflare Access (nel dashboard, una tantum)

1. **Zero Trust** → https://one.dash.cloudflare.com/ (crea team `pantedu`, Free).
2. **Access → Applications → Add an application → Self-hosted**.
3. Application name `SSH pantedu`; Session `24h`; hostname `ssh` . `pantedu.eu`.
4. Policy: name `Solo operatore`, Action `Allow`, Include → **Emails** → la
   tua email.
5. Save.

Verifica: `https://ssh.pantedu.eu/` deve fare **302 → login Access**
(`*.cloudflareaccess.com`), non 200 diretto.

---

## Recovery / troubleshooting

| Sintomo | Causa | Fix |
|---------|-------|-----|
| `Connection to UNKNOWN port 65535` | cloudflared sporca lo stream | aggiungi `--log-level fatal` al ProxyCommand |
| `ssh.pantedu.eu` risponde 200 diretto | policy Access mancante | crea l'app Access (Parte C) |
| tunnel non parte al boot | servizio non abilitato | `systemctl enable --now cloudflared` |
| **accesso totale perso** | tunnel/cloudflared KO | **Console Hetzner** (bypassa la rete) → login root |

## Ripristinare l'accesso diretto (se serve in futuro)
```bash
# sul VPS (via tunnel o Console Hetzner):
sed -i '/^ListenAddress 127.0.0.1/d;/^ListenAddress ::1/d' /etc/ssh/sshd_config
systemctl restart ssh                  # sshd torna su 0.0.0.0
```
Poi riapri la porta 2222 nel Cloud Firewall Hetzner col tuo IP Telecom
attuale. Il tunnel resta comunque attivo come canale primario.
