# Runbook — il sito non risponde

**Documento pubblico** (commit OK). Nessun segreto qui.

> Scritto dopo l'incidente del **27-30 agosto 2026**, in cui `pantedu.eu` è
> rimasto irraggiungibile per **3 giorni e 19 ore** senza che nulla lo
> segnalasse. La cronologia completa è in fondo (§ 6): è l'esempio pratico di
> come si percorre questo runbook.

---

## 1. Diagnosi dall'esterno — 30 secondi

Si parte da fuori e si scende. Il primo comando dice già quasi tutto:

```bash
curl -sS -o /dev/null -w "http=%{http_code}\n" https://pantedu.eu/health
```

| Risposta | Significato | Vai a |
|---|---|---|
| `200` + JSON `{"ok":true,...}` | **Il sito è sano.** Il problema è altrove (browser, DNS locale, rete tua) | § 1.1 |
| `503` | nginx e PHP vivi, **database giù** | § 4.1 |
| `521` | Cloudflare sta bene, **l'origin non risponde** | § 2 |
| `522` / `523` | l'origin risponde a intermittenza o è irraggiungibile in rete | § 2 |
| `525` / `526` | problema di **TLS** fra Cloudflare e origin (certificato origin scaduto) | § 4.4 |
| timeout / nessuna risposta | problema **DNS o dominio** | § 1.2 |

Il corpo di `/health` va guardato, non solo il codice:

```json
{"ok":true,"db":true,"migrations":{"applied":96,"pending":0},"time":"..."}
```

`db:false` o `pending` diverso da zero sono informazioni utili anche con un 200.

### 1.1 Il sito risponde ma tu non lo vedi

Quasi sempre è la challenge del WAF: da browser senza JavaScript, o da `curl`,
ogni pagina normale restituisce `200` con `<title>Verifica…</title>`. **Non è
un guasto**, è il Proof-of-Work anti-bot.

Attenzione a non farsi ingannare: quella pagina risponde `200` su
**qualunque** URL, comprese quelle inesistenti. Per capire se una pagina esiste
davvero serve guardare il `<title>`, non il codice HTTP.

`/health`, `/version` e `/metrics` sono esclusi dal WAF apposta
(`WafMiddleware::shouldBypass`).

### 1.2 DNS

```bash
nslookup pantedu.eu
```

Deve rispondere con IP Cloudflare (`188.114.96.x`, `188.114.97.x`,
`2a06:98c1::…`). Se non risolve: dominio scaduto o zona DNS rotta → dashboard
Cloudflare, non il server.

---

## 2. L'origin non risponde (521/522)

Cloudflare funziona, il tuo server no. Prima domanda: **la macchina è accesa?**

```bash
ping -n 3 198.51.100.10
```

- **Risponde** → la VM è viva, sono i servizi. Vai al § 3.
- **Non risponde** → VM spenta o problema di rete Hetzner. Console Hetzner (§ 3.2).

> **Non allarmarti se le porte 80/443 non rispondono dal tuo PC.** UFW le apre
> **solo ai range IP di Cloudflare**: un timeout da casa è il comportamento
> corretto, non un sintomo. Vedi `vps-info.md` § Firewall.

---

## 3. Entrare nel server

### 3.1 Tunnel SSH (via preferita)

```bash
SSH_AUTH_SOCK=~/.ssh/agent/sock ssh pantedu-tunnel
```

Il tunnel esce **dal** VPS verso Cloudflare: è indipendente da 80/443 e
funziona anche con nginx giù.

**Il banner del server è lo spartiacque diagnostico:**

| | |
|---|---|
| Il banner **compare** poi `Permission denied (publickey)` | Access e tunnel OK. È l'**agent SSH** morto → rimedio [A] in `ssh-cloudflare-tunnel.md` |
| Il banner **non compare** | Non sei arrivato a sshd → token Access scaduto, o `cloudflared` giù sul VPS |

Rimedio agent (il socket orfano va rimosso **prima**, o l'agent non riparte):

```bash
rm -f ~/.ssh/agent/sock; ssh-agent -a ~/.ssh/agent/sock >/dev/null 2>&1; SSH_AUTH_SOCK=~/.ssh/agent/sock ssh-add ~/.ssh/id_ed25519
```

Se `cloudflared` sul VPS è giù, il tunnel non esiste: passa alla console.

### 3.2 Console web Hetzner (bypassa tutto)

Hetzner Cloud Console → server `server-esempio-1` → **`>_` Console** → `root`.

Funziona anche con Access, cloudflared, sshd e firewall tutti fuori uso. È la
via di emergenza definitiva.

> L'alias `pantedu-vps` (porta 2222 diretta) **non funziona** dall'esterno:
> sshd ascolta solo su `127.0.0.1:2222`. Usa sempre `pantedu-tunnel`.

---

## 4. Dentro il server

Comando unico di ricognizione:

```bash
df -h /; systemctl is-active mariadb php8.4-fpm nginx cloudflared; systemctl --failed
```

### 4.1 Servizi `inactive (dead)` ma `enabled`

**Questo è il caso dell'incidente del 27 agosto e merita attenzione**, perché
non assomiglia a un guasto.

`dead` ≠ `failed`. Se un servizio fosse crashato sarebbe `failed`, comparirebbe
in `systemctl --failed` e avrebbe log d'errore. `dead` + `enabled` significa che
**systemd non ha mai provato ad avviarlo**: ha cancellato il job.

Il motivo tipico è un **ciclo di dipendenze**. Cercalo così:

```bash
journalctl -b -1 --no-pager | grep -i "ordering cycle"
```

(`-b -1` = il boot precedente; il ciclo si manifesta all'avvio.)

Rimedio immediato:

```bash
systemctl start mariadb php8.4-fpm nginx cloudflared
```

Poi va trovata e rimossa la dipendenza circolare, altrimenti **si ripete a ogni
riavvio**. Vedi § 6.

### 4.2 Disco pieno

```bash
df -h /; journalctl --vacuum-size=200M; du -xh / --max-depth=2 2>/dev/null | sort -rh | head -20
```

Causa classica di servizi che non ripartono. Nell'incidente del 27 agosto il
disco era al 28%: **non era questo**, ed escluderlo subito ha risparmiato tempo.

### 4.3 nginx non parte

```bash
nginx -t; journalctl -u nginx -n 30 --no-pager
```

Config invalida dopo un rinnovo certbot o un deploy: `nginx -t` lo dice in una
riga. Il deploy fa già `nginx -t` con rollback automatico
(`deploy.sh` § Step nginx config sync), quindi è raro.

### 4.4 Errori TLS Cloudflare→origin (525/526)

Certificato origin scaduto. Controlla la scadenza e rinnova con certbot.
UptimeRobot sorveglia anche questo (§ 5).

### 4.5 Unità fallite dopo il ripristino

I job periodici (backup, prewarm TikZ, sync threat-intel, logrotate) falliscono
"di riflesso" mentre lo stack è giù. Dopo il ripristino:

```bash
systemctl reset-failed
```

Azzera i contatori. Se dopo 24h qualcosa torna `failed`, **quello** è un
problema vero.

---

## 5. Monitoraggio

**UptimeRobot** controlla `https://pantedu.eu/health` ogni 5 minuti e avvisa via
email `{{OPERATORE_EMAIL}}`. Sorveglia anche la scadenza del certificato TLS.

Perché `/health` e non la home: la home restituisce la challenge del WAF, cioè
`200` anche a sistema malato. `/health` risponde `503` quando il database è giù
— è la differenza fra sapere che «nginx è vivo» e sapere che «il sito funziona».

Perché UptimeRobot e non i Cloudflare Health Check: il guardiano non deve stare
dentro la casa che sorveglia. Un provider indipendente vede anche i guasti di
Cloudflare.

Perché l'indirizzo per gli avvisi è su un provider esterno e non sul dominio
del progetto: gli indirizzi del dominio passano da **Cloudflare Email
Routing**. Un allarme non deve condividere il destino di ciò che sorveglia —
un problema su Cloudflare o sul DNS toglierebbe sito **e** avviso insieme.

**Privacy**: `/health` restituisce solo booleani e contatori, nessun dato
personale. Nessuna voce nel registro art. 30, nessun DPA art. 28, nessuna base
di trasferimento art. 44. Vale finché il monitor punta a `/health`: se lo si
puntasse a una pagina con dati reali, l'analisi cambierebbe.

---

## 6. Incidente 27-30 agosto 2026 — cronologia

**Durata**: 3 giorni e 19 ore. **Causa**: ciclo di dipendenze systemd.
**Rilevazione**: nessuna. Scoperto aprendo il browser.

### Cosa è successo

`pantedu-deploy.path` dichiarava `After=multi-user.target`. Una `.path` unit ha
per default `Before=paths.target`, e `paths.target` sta dentro `basic.target`:
chiedere di partire alla **fine** del boot a un'unità che per natura parte
all'**inizio** crea un ciclo.

```
pantedu-deploy.path → paths.target → basic.target
  → pantedu-ensure-config.service (Before=nginx)
  → nginx.service → multi-user.target → pantedu-deploy.path
```

systemd non può ordinare un ciclo: lo rompe **cancellando job**. Al riavvio del
27 agosto alle 03:00 le vittime sono state nginx, php-fpm e cloudflared, che non
sono mai stati avviati.

### Perché nessuno se n'è accorto

I servizi erano `inactive (dead)`, **mai `failed`**. Nessun errore, nessun log
anomalo, `systemctl --failed` puliti: dal punto di vista di systemd non era
successo nulla. E nessun controllo esterno esisteva.

### Traccia nel journal

```
Aug 27 03:00:01 systemd[1]: multi-user.target: Found ordering cycle on pantedu-deploy.path/stop
Aug 27 03:00:01 systemd[1]: Job pantedu-deploy.path/stop deleted to break ordering cycle
Aug 27 03:00:01 systemd[1]: Stopping nginx.service ...
-- Boot 8150c2a46ff24670b5d9dc128edf4bdf --
Aug 30 22:40:55 systemd[1]: Starting nginx.service ...     ← avvio manuale
```

### Risoluzione

| Commit | Intervento |
|---|---|
| `bc48975` | rimossa `After=multi-user.target` da `pantedu-deploy.path`, con commento che spiega perché non va reintrodotta |
| `e4341df` | `deploy.sh` allinea anche le unit `.path`, non solo `.service` e `.timer` — prima erano sincronizzate solo dallo step 7b, diff-gated, quindi potevano divergere per sempre |
| `dc7eab3` | il bypass WAF diceva `/healthz` ma la rotta è `/health`: l'endpoint di monitoraggio riceveva la challenge e rispondeva `200` **anche a database spento** |

Il fix è **verificato**, non presunto: la unit corretta è stata installata alle
22:45:14, il riavvio è avvenuto alle 22:51:12 e tutti i servizi sono risaliti da
soli.

### Cosa insegna

1. **`dead` non è `failed`.** Il silenzio di systemd non è una buona notizia: un
   ciclo di dipendenze non produce errori, produce assenza.
2. **La rottura di un ciclo non è deterministica.** Al primo riavvio dopo la
   scoperta il sito risalì lo stesso, pur col file ancora rotto: systemd aveva
   sacrificato un job diverso. Un sintomo che sparisce non è un bug risolto.
3. **Un file installato può divergere dal repo per sempre** se la sincronizzazione
   è diff-gated invece che idempotente. È lo stesso difetto già corretto in
   `deploy.sh` il 2026-05-24 per i `.timer`; le `.path` erano rimaste indietro.
4. **Un endpoint di health mai verificato dall'esterno non è un endpoint di
   health.** Il bug del bypass WAF era lì dall'inizio e nessuno poteva accorgersene
   senza provarlo.
5. **Senza controllo esterno, il tempo di rilevazione è illimitato.** Quattro
   giorni non erano il caso peggiore: erano il caso in cui è capitato di aprire
   il browser.

---

## Riferimenti

- `vps-info.md` — server, firewall, servizi, comandi comuni
- `ssh-cloudflare-tunnel.md` — accesso SSH, runbook diagnostico dell'agent
- `waf-hardening-2026-06.md` — WAF, scoring, challenge
