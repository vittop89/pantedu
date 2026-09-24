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
| `502` | nginx vivo, **dietro non risponde nessun container**: se riparte in continuazione, una guardia dell'avvio | § 4.8, § 4.6 |
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
(l'IP non sta nel repository — 23/9/2026, DOC-22: risolvilo da `~/.ssh/config`,
dal pannello Hetzner, o da `dig +short pantedu.eu` se il DNS risponde ancora)

```bash
ping -n 3 <ip-del-server>
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
| Il banner **compare** poi `Permission denied (publickey)` | Access e tunnel OK. È l'**agent SSH** morto → rimedio qui sotto (Git Bash); con l'`ssh.exe` di Windows, `docs/dev/sviluppo-in-wsl.md`, «Che cosa si fa ancora da Windows» |
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

### 4.6 Dopo un riavvio: il container è `Exited` e MariaDB è `failed`

Il segno: `docker ps -a` mostra `pantedu-app-*` in `Exited`, con l'errore
`error mounting "/run/mysqld/mysqld.sock" … not a directory`; `systemctl
is-active mariadb` dice `failed`, e il suo giornale `Bind on unix socket:
Address already in use`; `/run/mysqld/mysqld.sock` è una **cartella**.

Quello che è successo: Docker è partito prima che MariaDB avesse creato il
socket, ha riavviato il container, e il montaggio con `-v` ha creato una
cartella al posto del socket mancante. MariaDB poi non può creare il socket. La
cartella è vuota e sta in `/run`, che è in memoria.

```bash
ls -A /run/mysqld/mysqld.sock          # deve essere vuota: se non lo è, fermarsi e guardare
rmdir /run/mysqld/mysqld.sock
systemctl start mariadb
test -S /run/mysqld/mysqld.sock && echo socket
docker start pantedu-app-a             # il nome che mostra docker ps -a
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8090/health   # la porta del container
```

Successo il 15 settembre 2026 (§ 7). Da allora non dovrebbe più: Docker parte
dopo MariaDB (`tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf`) e il
rilascio monta il socket con `--mount`, che se il socket manca si ferma invece
di creare la cartella. Il container creato **prima** di quel rilascio ha ancora
il montaggio con `-v`: fino al rilascio successivo conta solo l'ordine.

### 4.7 Ogni pagina risponde 500: `.env.local` non si legge

Il segno: `/health` e tutte le pagine rispondono 500; il rilascio si ferma su
«migrazioni fallite» con `Dotenv\Exception\InvalidFileException: Failed to
parse dotenv file`, e il messaggio dice dove. Nel frattempo falliscono i timer,
**e con loro gli avvisi**: anche `tools/ops/avvisa_guasto.php` legge
`.env.local`, quindi non parte nessuna email.

Succede nell'istante in cui si salva, prima di qualunque rilascio: il container
monta `.env.local`, e PHP lo rilegge a ogni richiesta e a ogni timer. Lo rompono
un valore con spazi (un segnaposto come `<id copiato>`) o una virgoletta non
chiusa.

Dalla cartella del sorgente sull'host (`vps-info.md`):

```bash
chattr -i .env.local
nano .env.local                         # correggere o togliere la riga dell'errore
chattr +i .env.local
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8090/health   # la porta del container attivo
systemctl --failed                      # i timer falliti nel frattempo
systemctl reset-failed 'pantedu-avviso@*'
```

I servizi falliti si rilanciano con `systemctl start <unità>`, o si aspetta il
loro giro.

**Per modificare `.env.local` senza fermare il sito:**

- con `nano`, che riscrive lo stesso file: il container vede la modifica subito
  (misurato il 15 settembre 2026, nei due versi). Un comando che sostituisce il
  file, come `sed -i`, può lasciare al container la copia vecchia fino al
  rilascio;
- valori veri, senza `<` `>`, senza virgolette e senza spazi;
- una chiave che accende una funzione va insieme a quelle che le servono
  (`DRIVE_ENABLED=true` con le credenziali, ADR-038);
- subito dopo il salvataggio, `/health`: se non è 200 si toglie la riga, prima
  di `chattr +i` e prima di qualunque rilascio;
- poi le **guardie dell'avvio**, che `/health` non guarda. Il container che
  serve le ha superate quando è partito: una riga che ne viola una (per
  esempio `AUDIT_REASON_MODE=warn` o `RATE_LIMIT_DISABLED=1`) vale subito,
  `/health` risponde 200, e il container si rifiuta di ripartire al prossimo
  riavvio o rilascio (§ 4.8). Si guardano dall'host, con il blocco qui sotto;
  se esce 1 si corregge la riga che dice, prima di `chattr +i`, e si rilancia.

```bash
C=$(docker ps -a --filter name=pantedu-app- --format '{{.Names}}' | head -n1)
DATI=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/lib/pantedu-data"}}{{.Source}}{{end}}{{end}}' "$C")
echo "container: $C, dati: $DATI"       # tutti e due pieni, altrimenti fermarsi
systemd-run --uid=pantedu --gid=www-data --wait --pipe --quiet --collect \
    --setenv=PANTEDU_DATA_PATH="$DATI" \
    /usr/bin/php -d variables_order=EGPCS "$PWD/docker/verifica-avvio.php" --solo-configurazione
echo "esito $?"                         # 0, con «[avvio] produzione: … a posto»
```

Perché così, e non con `docker exec`:

- gira **sull'host**, sulla copia del sorgente che il container monta: stessi
  `.env` e `.env.local`, la cartella dei dati presa dal montaggio del
  container, lo stesso codice (il rilascio riporta la copia al commit che
  mette in servizio) e il `vendor/` dell'host, che le migrazioni e le unità a
  orario usano già;
- con `systemd-run`, come le unità a orario (`pantedu`, `/usr/bin/php`): è
  un lavoro dell'host, non un ingresso nel container. `docker exec` lo
  sarebbe, e il controllo della notte lo segnala con un'anomalia e una mail
  (`diagnostica.md`, «Il punto cieco dei container»); `docker ps` e
  `docker inspect` si contano e basta;
- `variables_order=EGPCS` perché nel container è quello (l'immagine non ha un
  `php.ini`); il PHP dell'host da riga di comando dice `GPCS`, e
  `PANTEDU_DATA_PATH` non arriverebbe all'applicazione;
- `--solo-configurazione` e non la verifica intera: dall'host, come `pantedu`,
  le prove di scrittura e la cartella delle sessioni (di `www-data`, 0700)
  direbbero il falso. Non scrive niente e non apre il database; si ferma se la
  cartella dei dati non c'è o se `.env.local` non si legge, invece di guardare
  il solo `.env`. `--collect`: se esce 1, l'unità non resta fra le fallite.

Il blocco è scritto il 23/9/2026 da quello che dicono `deploy-container.sh`,
le unità di `tools/systemd` e questa guida, ed è provato in locale
(`tests/ops/verifica-avvio.test.sh`); sul server non è ancora stato lanciato.
La prima volta si confronta l'esito con la riga `[avvio] produzione` di
`docker logs` del container attivo, e il rapporto del mattino dopo dice se
auditd ha contato fra le letture di `.env.local` anche quella del controllo.

Aprire il file da una sessione ssh è una lettura per auditd, e salvarlo è una
differenza per AIDE: il rapporto del mattino dopo le segnala, ed è previsto
(`diagnostica.md`).

### 4.8 Il container riparte in continuazione: una guardia dell'avvio

Il segno: dopo un riavvio della macchina o del container, `/health` risponde
502 (nginx è vivo, dietro non c'è nessuno); `docker ps -a --filter
name=pantedu-app-` mostra il container in `Restarting (1)`, e il suo registro
finisce con una guardia e la riga «guarda:»:

```bash
docker logs --tail 20 pantedu-app-a     # il nome che mostra docker ps -a
```

```
[avvio] ERRORE: [motivazione] produzione con AUDIT_REASON_MODE «warn», non «enforce»: …
[avvio]         guarda: AUDIT_REASON_MODE in .env.local e nel .env montato: …
[avvio] ERRORE: i controlli di configurazione non passano (sopra c'è il motivo).
```

Che cosa vuol dire: dal 23 settembre 2026, con `APP_ENV=production`, il
controllo d'avvio del container (`docker/verifica-avvio.php`) non parte con
una configurazione che spegne una difesa. Quali sono le guardie, e perché:
`wiki/environment-variables.md`, «Le guardie all'avvio del container». La
riga sbagliata è quasi sempre in `.env.local`, cambiata mentre il container
girava: lì vale subito, `/health` non se ne accorge, e la guardia la vede
alla ripartenza. Il `.env` versionato lo prova la CI prima dell'unione
(`tests/ops/verifica-avvio.test.sh`) e sul server non si modifica.

Nel rilascio a scambio lo stesso errore non spegne il sito: il rilascio si
ferma con «il container nuovo non è mai stato sano: niente scambio», stampa
il registro del nuovo con la stessa riga, e il vecchio continua a servire. Il
sito resta giù quando un container vecchio non c'è: al riavvio della
macchina, o dopo un `docker restart`.

Dalla cartella del sorgente sull'host:

```bash
chattr -i .env.local
nano .env.local                         # la chiave che dice la riga «guarda:»
# le guardie, dall'host: il blocco del § 4.7, finché dice «esito 0»
chattr +i .env.local
docker restart pantedu-app-a            # il nome che mostra docker ps -a
docker logs pantedu-app-a 2>&1 | grep -F '[avvio] produzione' | tail -n 1   # «… a posto»
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8090/health   # la porta del container
```

Non si corregge la guardia, e non si cambia `APP_ENV` per spegnerle: fuori
da `production` l'applicazione smette di comportarsi da produzione (la riga
di `APP_ENV` in `wiki/environment-variables.md`), e un valore che il codice
non conosce ferma l'avvio da sé (guardia `[ambiente]`).

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

## 7. Incidente 15 settembre 2026 — il riavvio per le regole di audit

**Cinque minuti** di sito e database giù, dalle 23:19 alle 23:24 UTC.

### Cosa è successo

Il riavvio serviva a caricare le regole di auditd nuove (immutabili, `-e 2`).
Prima di chiederlo era stato verificato che il container avesse `--restart
unless-stopped` e che Docker partisse all'avvio: **non** che partisse dopo
MariaDB. Al riavvio i due sono partiti nello stesso secondo.

```
23:19:21 container: error mounting "/run/mysqld/mysqld.sock" to rootfs … not a directory
23:19:23 mariadbd: [ERROR] Can't start server : Bind on unix socket: Address already in use
23:19:23 systemd: Failed to start mariadb.service
```

`/run/mysqld/mysqld.sock` era una cartella vuota, creata da Docker con `-v` al
posto del socket che ancora non c'era.

### Come se n'è accorti

La prova delle regole nuove (`tools/ops/prova-ingressi-container.sh`) si è
fermata su «nessun container pantedu-app- in esecuzione». Un «fallita» che
sembrava della prova era il sito spento.

### Risoluzione

A mano i passi del § 4.6, poi `/health` 200 da nginx e la prova delle regole
riuscita. Perché non ricapiti:

- `tools/systemd/docker.service.d/pantedu-dopo-mariadb.conf`: `After=` e
  `Wants=mariadb.service` per Docker. Nessun ciclo: MariaDB non ordina niente
  dopo Docker;
- il socket nel `docker run` con `--mount`: misurato sul VPS (Docker Engine
  29.8.0), con il socket assente `-v` crea la cartella ed esce 0, `--mount` esce
  125 e non crea niente;
- `tools/ci/check-deploy-units.mjs` impedisce che `-v` torni sul socket e che il
  file di ordine sparisca.

### Cosa insegna

1. **«Riparte da solo» va provato sul servizio intero, non sul pezzo.** La
   politica di riavvio del container era giusta; mancava l'ordine rispetto a
   ciò da cui dipende.
2. **`-v` crea quello che non trova.** Per un file o un socket che deve già
   esistere si usa `--mount`, che si ferma.
3. **Una prova che fallisce può dire più di quello che prova.** Questa si è
   fermata sul container mancante invece di proseguire con numeri a zero.

---

## 8. Incidente 15 settembre 2026 — un segnaposto in `.env.local`

**Due minuti** di sito in 500, dalle 00:02 alle 00:04 UTC.

### Cosa è successo

Per accendere Drive, le istruzioni date dall'assistente contenevano righe con
un segnaposto al posto del valore (`GOOGLE_DRIVE_CLIENT_ID=<id copiato da
Google>`), e sono state copiate così. phpdotenv ha rifiutato il file
(«unexpected whitespace»). Il container in servizio rispondeva già 500; il
rilascio lanciato subito dopo si è fermato alle migrazioni. Alle 00:03 sono
falliti due timer (`pantedu-tmp-cleanup`, `pantedu-waf-export-blocked`) e i
loro avvisi, senza email.

### Risoluzione

I passi del § 4.7: righe tolte con nano, `/health` 200 senza rilascio, poi
`reset-failed` sugli avvisi. Drive acceso poco dopo con i valori veri e un
rilascio.

### Cosa insegna

1. **`.env.local` non ha un banco di prova**: il salvataggio è già il rilascio.
2. **Un segnaposto in un blocco da copiare viene copiato.** Negli esempi si
   mette la forma del valore vero (`...apps.googleusercontent.com`), e subito
   dopo il controllo di `/health`.
3. **Un `.env.local` illeggibile spegne anche gli avvisi.** Se n'è accorti il
   rilascio, con l'errore sul terminale: da solo, il guasto non avrebbe mandato
   niente.

---

## Riferimenti

- `vps-info.md` — server, firewall, servizi, comandi comuni
- `ssh-cloudflare-tunnel.md` — accesso SSH, runbook diagnostico dell'agent
- `waf-hardening-2026-06.md` — WAF, scoring, challenge
