# DEPLOY — tex-compile-vps

Procedura step-by-step dal momento in cui hai un VPS Debian 13 fresco
fino al primo PDF compilato end-to-end.

## Prerequisiti

- VPS Debian 13 (trixie) con accesso root via SSH
- Sottodominio già puntato all'IP del VPS (DNS A record)
  Es. `tex.tuosito.it → 1.2.3.4`
- Email valida per registrazione Let's Encrypt
- App pantedu funzionante su hosting condiviso

## Step 1 — Configurazione DNS (lato registrar)

Nel pannello DNS del tuo dominio aggiungi un record A:

```
Tipo:  A
Nome:  tex
Valore: <IP pubblico del VPS>
TTL:   3600
```

Verifica con:
```bash
dig +short tex.tuosito.it    # deve restituire IP del VPS
```

DNS può richiedere 5-60 min per propagazione.

## Step 2 — Copia codice sul VPS

Da locale (Windows PowerShell o WSL):

```powershell
# Copia ricorsiva via scp (su Windows usa OpenSSH integrato o WinSCP)
scp -r tools/tex-compile-vps/ root@1.2.3.4:/root/
```

Oppure via git se preferisci:
```bash
ssh root@1.2.3.4
git clone <tua-repo> /root/pantedu
cd /root/pantedu/tools/tex-compile-vps
```

## Step 3 — Provisioning automatico

```bash
ssh root@1.2.3.4
cd /root/tex-compile-vps    # o /root/pantedu/tools/tex-compile-vps
chmod +x provision.sh
./provision.sh tex.tuosito.it admin@tuosito.it
```

Lo script:
1. Aggiorna sistema
2. Installa TeX Live `scheme-medium` (~2GB, 5-10 min download)
3. Crea utente di sistema `texcompile`
4. Setup Python venv + dipendenze FastAPI
5. **Genera segreto HMAC e lo stampa a video — COPIALO**
6. Installa systemd unit + autostart
7. Configura nginx + firewall (ufw)
8. Richiede certificato TLS Let's Encrypt automaticamente

Tempo totale: ~10-15 minuti.

## Step 4 — Verifica servizio

```bash
# Stato servizi
systemctl status tex-compile
systemctl status nginx

# Logs in tempo reale
journalctl -u tex-compile -f

# Test health pubblico (no auth)
curl https://tex.tuosito.it/health
# Output atteso: {"status":"ok","service":"tex-compile-vps"}
```

Se `/health` risponde 200 sei a posto.

## Step 5 — Smoke test compile end-to-end

Dal VPS o da locale, salva uno script di test:

```bash
cat > /tmp/smoke.sh <<'EOF'
#!/bin/bash
SECRET="<incolla qui segreto HMAC stampato dal provisioning>"
ENDPOINT="https://tex.tuosito.it"

TEX='\documentclass{article}\begin{document}Hello $E=mc^2$\end{document}'
TEX_B64=$(printf '%s' "$TEX" | base64 -w0)

PAYLOAD=$(printf '{"tex_b64":"%s","doc_id":"smoke","engine":"pdflatex","passes":1}' "$TEX_B64")
TIMESTAMP=$(date +%s)
SIGNATURE=$(printf '%s.%s' "$TIMESTAMP" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -i -X POST "$ENDPOINT/compile" \
    -H "Content-Type: application/json" \
    -H "X-Timestamp: $TIMESTAMP" \
    -H "X-Signature: $SIGNATURE" \
    -d "$PAYLOAD" \
    --output /tmp/smoke.pdf
echo ""
file /tmp/smoke.pdf
EOF
chmod +x /tmp/smoke.sh
bash /tmp/smoke.sh
```

Output atteso:
```
HTTP/2 200
content-type: application/pdf
x-compile-duration-ms: 850
...
/tmp/smoke.pdf: PDF document, version 1.5
```

Se vedi `PDF document` il flow funziona end-to-end.

## Step 6 — Configurazione lato hosting legacy (app pantedu)

### 6a — Salva client PHP nel progetto

Copia `client/TexCompileClient.php` in:
```
app/Services/TexCompile/TexCompileClient.php
```

(crea cartella se manca; namespace già `App\Services\TexCompile`)

### 6b — Variabili d'ambiente

Aggiungi al `.env` di produzione (hosting legacy):

```ini
TEX_COMPILE_ENDPOINT=https://tex.tuosito.it
TEX_COMPILE_SECRET=<stesso segreto HMAC del VPS>
```

### 6c — Smoke test PHP

Crea uno script di prova `tools/diag/test_tex_compile.php`:

```php
<?php
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Services/TexCompile/TexCompileClient.php';

use App\Services\TexCompile\TexCompileClient;

$client = new TexCompileClient(
    endpoint: getenv('TEX_COMPILE_ENDPOINT') ?: 'https://tex.tuosito.it',
    secret:   getenv('TEX_COMPILE_SECRET')   ?: '',
);

$tex = '\documentclass{article}\begin{document}Hello \(E=mc^2\)\end{document}';
$res = $client->compile($tex, 'smoke-' . time());

echo $res['ok'] ? "OK ({$res['duration_ms']}ms, " . strlen((string)$res['pdf']) . " bytes)\n"
                : "FAIL [{$res['http_status']}]:\n{$res['log']}\n";
```

Esegui da CLI hosting legacy (se hai accesso shell) o via endpoint diagnostico
temporaneo.

## Step 7 — Integrazione nei controller esistenti

Dove l'app oggi genera `.tex` ma non può compilarlo (hosting legacy shared),
sostituisci con chiamata al client:

```php
$texSource = $texBuilder->build($context);   // logica esistente
$result = $this->texCompile->compile(
    texSource: $texSource,
    docId: "verifica_{$verificaId}",
);
if (!$result['ok']) {
    return $this->errorResponse('Compile fallito', $result['log']);
}
file_put_contents($pdfStoragePath, $result['pdf']);
```

Vedi `client/IntegrationExample.md` per pattern dettagliato di
integrazione (controller `VerificaController`, fallback, error handling).

## Operazioni post-deploy

### Rotazione segreto HMAC

```bash
# Sul VPS
NEW_SECRET=$(openssl rand -hex 32)
sudo sed -i "s|^TEX_COMPILE_SECRET=.*|TEX_COMPILE_SECRET=$NEW_SECRET|" /opt/tex-compile/.env
sudo systemctl restart tex-compile
echo "Nuovo segreto: $NEW_SECRET"
```

Aggiorna immediatamente lo stesso valore lato hosting legacy `.env` per evitare
401 in produzione. Considera un breve "doppio segreto" se vuoi
rotazione zero-downtime.

### Aggiornamento codice

```bash
# Sul VPS, dopo aver aggiornato il codice in /root/...
sudo cp -r /root/tex-compile-vps/app/* /opt/tex-compile/app/
sudo chown -R texcompile:texcompile /opt/tex-compile/app
sudo systemctl restart tex-compile
```

### G22.S4.B.3 — Aggiornamento per `/compile-bundle`

Il microservizio v1.2.0 introduce l'endpoint multi-file `/compile-bundle`
(in aggiunta a `/compile` legacy single-file, che resta funzionante).
Scopo: ricevere un bundle JSON con tutti i file del .tex (verifica.sty
+ intestazione + esercizi + griglie + main_*.tex) e compilare main_*.tex
con `\input{...}` risolti via filesystem.

**Deploy update**:
```bash
# Da locale, push del codice aggiornato:
scp -r tools/tex-compile-vps/app root@<IP-VPS>:/root/tex-compile-vps/

# Sul VPS:
sudo cp /root/tex-compile-vps/app/main.py    /opt/tex-compile/app/
sudo cp /root/tex-compile-vps/app/compile.py /opt/tex-compile/app/
sudo chown texcompile:texcompile /opt/tex-compile/app/main.py /opt/tex-compile/app/compile.py
sudo systemctl restart tex-compile

# Verifica versione:
curl -s https://tex.pantedu.eu/health
# atteso: {"status":"ok","service":"tex-compile-vps","version":"1.2.0"}
```

**Smoke test bundle**:
```bash
# su hosting condiviso, da PHP:
php -r '
$client = new \App\Services\TexCompile\TexCompileClient(
    getenv("TEX_COMPILE_ENDPOINT"),
    getenv("TEX_COMPILE_SECRET"),
);
$result = $client->compileBundle(
    files: [
        ["path"=>"versioni/main_NOR.tex", "content"=>"\\documentclass{article}\\begin{document}\\input{../texCommon/x}\\end{document}"],
        ["path"=>"texCommon/x.tex",        "content"=>"hello bundle"],
    ],
    mainPath: "versioni/main_NOR.tex",
    docId: "smoke_bundle_test",
);
var_dump($result["ok"], strlen((string)$result["pdf"]));
'
```

**Rollback**: se il deploy v1.2.0 dovesse rompersi, il backend hosting legacy
include un fallback automatico a `/compile` (single-file) per le row
con override TEX manuale (preview modal). Per disabilitare il bundle
path globale, il client switcha a `/compile` quando `tex_files` e' null
(row legacy pre-S4.B.2 — nessuna manifest disponibile).

### Logs e monitoring

```bash
# Live tail
journalctl -u tex-compile -f

# Ultimi 100 errori
journalctl -u tex-compile -p err -n 100

# Statistiche compile (custom: parse log)
journalctl -u tex-compile --since "today" | grep "compile ok" | wc -l
```

### Backup

Il servizio è **stateless** — niente da backuppare salvo:
- `/opt/tex-compile/.env` (segreto HMAC) → custodisci offline
- Eventuali snapshot VPS dal pannello provider (1-2 €/mese, opzionale)

In caso di disastro VPS:
1. Ricrea VPS da zero
2. Rilancia `provision.sh`
3. Sostituisci segreto HMAC con il vecchio (per non dover aggiornare hosting legacy)

## Troubleshooting

| Sintomo | Causa probabile | Fix |
|---------|-----------------|-----|
| `/health` non risponde | nginx down | `systemctl status nginx`, `nginx -t` |
| 502 Bad Gateway | servizio FastAPI down | `journalctl -u tex-compile -n 50` |
| 401 dal client PHP | segreto disallineato o clock skew | Verifica `.env` e `date` su entrambi |
| 422 con log "File `xxx.sty' not found" | TeX Live `scheme-medium` non basta | `apt install texlive-full` (~7GB) |
| Compile lento (>10s) | scheme di default non ottimizzato | Verifica RAM libera, considera upgrade VPS |
| Cert TLS scaduto | rinnovo cron rotto | `certbot renew --dry-run`, verificare timer |
| 503 Service Temporarily Unavailable in pagine con tanti TikZ | nginx rate-limit superato | Verifica `limit_req_zone` in `/etc/nginx/sites-available/tex-compile.conf`. Versione corrente: `compile=60r/m`, `tikz=120r/m`. Pre-warm cache notturno (`pantedu-tikz-prewarm.timer`) riduce drasticamente le compile-on-demand. |
| Pre-warm timer non gira | `systemctl status pantedu-tikz-prewarm.timer` | Verifica `enable --now`, controlla `journalctl -u pantedu-tikz-prewarm.service -n 100` |

## TikZ cache prewarm (G22.S15.bis)

**Problema**: una pagina con N disegni TikZ uncached scatena N richieste
parallele a `/render-tikz` → burst supera nginx rate-limit → 503 visibile.

**Soluzione**: cron notturno che compila tutti i blocchi mancanti dalla
cache lentamente (delay=2s tra una compile e l'altra), così la mattina
le pagine servono SVG pre-cached istantaneamente.

### Setup automatico (via deploy.sh)

I file unit (`tools/systemd/pantedu-tikz-prewarm.{service,timer}`) e la
config nginx aggiornata vengono propagati automaticamente dal webhook al
prossimo `git push` su `master_vps`. Lo script `deploy.sh`:

1. Sincronizza `tools/systemd/*.{service,timer}` → `/etc/systemd/system/`
2. `daemon-reload` + `systemctl enable --now <timer>` (idempotente)
3. Sincronizza `tools/tex-compile-vps/nginx/tex-compile.conf` (preserva
   `server_name` esistente), `nginx -t`, reload (rollback automatico se
   `nginx -t` fallisce)

### Verifica setup

```bash
systemctl list-timers pantedu-tikz-prewarm.timer
# NEXT                        LEFT  LAST  UNIT
# Wed 2026-05-13 03:15:42 ... 4h... pantedu-tikz-prewarm.timer
```

### Trigger manuale (utile dopo grossa modifica contenuti)

```bash
sudo systemctl start pantedu-tikz-prewarm.service
journalctl -u pantedu-tikz-prewarm.service -f
```

### Disabilitare temporaneamente

```bash
sudo systemctl disable --now pantedu-tikz-prewarm.timer
```

## Costo runtime

Una volta in produzione:
- VPS: 5-7 €/mese (a seconda del provider scelto)
- TLS: 0 €
- DNS: 0 € (riusi quello esistente)
- **Totale: ~5-7 €/mese**

Nessun costo aggiuntivo per compile/utente.
