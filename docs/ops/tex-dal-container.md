# Il servizio TeX e il container dell'applicazione

Come l'applicazione, che gira in un container, raggiunge il servizio TeX, che
gira sull'host; come si controlla che ci arrivi; cosa si è fatto a mano sul
server e come si torna indietro.

## In breve

- **Il servizio TeX è sull'host**: l'unità systemd `tex-compile`, utente
  `texcompile`, codice in `/opt/tex-compile`. Non è nel container, e non passa
  da nginx.
- **L'applicazione è nel container** `pantedu-app-a` o `pantedu-app-b`, sulla
  rete bridge predefinita di Docker.
- **Si incontrano sul gateway del bridge Docker.** È l'unico indirizzo valido
  in tutti e due i posti: sull'host è l'indirizzo di `docker0` (lo usano i
  lavori a orario, come la coda delle compilazioni), nel container è il
  gateway (lo usa l'applicazione). Ne serve uno solo perché `.env.local` è uno,
  montato nel container e letto dall'host.
- **Tre pezzi devono essere d'accordo**: dove ascolta uvicorn, la regola del
  firewall su `docker0`, e `TEX_COMPILE_ENDPOINT` in `.env.local`.
- **Chi lo controlla**: `GET /health/tex` (l'applicazione ci arriva?), il
  controllo `tex` della diagnostica, il passo 8 del rilascio.

## Che cosa è successo dall'8 al 19 settembre 2026

L'8 settembre l'applicazione è passata nel container. Il servizio TeX è
rimasto sull'host, in ascolto su `127.0.0.1:8001`, e `TEX_COMPILE_ENDPOINT` è
rimasto `http://127.0.0.1:8001`. Dentro il container `127.0.0.1` è il container
stesso: ogni chiamata falliva subito con «connessione rifiutata» (errno 7 di
cURL). Per undici giorni nessuna compilazione avviata dal sito ha funzionato:
PDF delle verifiche, anteprime, esportazione PDF degli esercizi, risdoc, TikZ
non in cache, editor TikZ, SyncTeX, formattazione, GeoGebra in PDF. Funzionava
solo quello che gira sull'host: il prewarm TikZ di notte e la coda delle
compilazioni.

Nessun controllo se n'è accorto, e il motivo è la lezione:

- tutti guardavano **dall'host**, da cui il TeX si raggiunge sempre;
- in CI il container gira senza TeX e le spec `@tex` sono escluse;
- il [VPS in locale](../dev/sviluppo-in-wsl.md#il-vps-in-locale) ha il TeX in
  un container con nome sulla rete dell'applicazione: un'altra topologia, che
  passava;
- l'errore arrivava al docente — con dentro l'indirizzo interno — e a nessun
  altro: nessuna riga nei registri, nessuna anomalia.

## Il gateway: come si ricava

Non si scrive: si chiede a Docker.

```bash
sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}'
```

La rete del bridge, che serve alla regola del firewall:

```bash
sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Subnet}}'
```

Restano gli stessi finché `/etc/docker/daemon.json` non imposta `bip`. Se un
giorno lo si cambia, cambiano tutti e due, e vanno rifatti i tre passi qui
sotto: il controllo `tex` e `/health/tex` diventano rossi e lo dicono.

## I passi a mano sul server

Applicati il 19/9/2026 fra le 19:29 e le 19:31 UTC (misurato dalle date dei
file e dall'avvio dell'unità e del container). Da PowerShell
`ssh pantedu-tunnel`, poi sul server un comando per volta. Fra il riavvio del TeX e il rilascio forzato i lavori
dell'host trovano la porta chiusa per qualche minuto: la coda riprova, non si
perde niente.

**1. Il gateway**, come sopra. Nei passi seguenti si usa quello che stampa.

**2. La regola del firewall**: in entrata da `docker0`, dalla rete del bridge,
verso il gateway e la porta del servizio. Niente di più largo: un
`ufw allow 8001` lascerebbe come unica barriera il firewall del fornitore.
Con rete e gateway presi da Docker:

```bash
sudo ufw allow in on docker0 from "$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Subnet}}')" to "$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')" port 8001 proto tcp comment 'TeX per i container'
```

**3. Dove ascolta uvicorn**: un drop-in, così l'unità installata resta com'è
(il drop-in sul server si chiama `indirizzo-docker.conf`).

```bash
sudo mkdir -p /etc/systemd/system/tex-compile.service.d
```

```bash
printf '[Unit]\nAfter=docker.service\n\n[Service]\nExecStart=\nExecStart=/opt/tex-compile/venv/bin/uvicorn app.main:app --host %s --port 8001 --workers 2 --no-access-log\n' "$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')" | sudo tee /etc/systemd/system/tex-compile.service.d/indirizzo-docker.conf
```

`After=docker.service` perché il gateway esiste solo dopo che Docker ha creato
`docker0`: partito prima, uvicorn non riesce a legarsi (Errno 99) e
`Restart=on-failure` riprova ogni tre secondi.

```bash
sudo systemctl daemon-reload
```

```bash
sudo systemctl restart tex-compile
```

```bash
sudo ss -ltn 'sport = :8001'
```

Deve mostrare il gateway, non `127.0.0.1`.

**4. L'endpoint in `.env.local`.** Il file è immutabile (`chattr +i`): si
sblocca, si cambia una riga, si riblocca. La riga giusta la stampa questo
comando, da copiare **così com'è** — niente virgolette, niente `< >`, niente
barra finale. Un segnaposto copiato alla lettera fa fallire la lettura del file
e il sito risponde 500 subito (successo il 15/9/2026).

```bash
echo "TEX_COMPILE_ENDPOINT=http://$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}'):8001"
```

```bash
sudo chattr -i /var/www/pantedu/.env.local
```

```bash
sudo nano /var/www/pantedu/.env.local
```

```bash
sudo chattr +i /var/www/pantedu/.env.local
```

**5. Un rilascio forzato**: il container monta `.env.local` come file singolo,
e un editor che lo sostituisce (inode nuovo) lascia il container attivo a
leggere quello vecchio. Il container nuovo monta quello giusto.

```bash
sudo FORZA=1 /usr/local/bin/pantedu-deploy-container.sh
```

Sono attese le mail di AIDE (`.env.local`, il drop-in) e la riga
`audit_segreti_letti`: si segnano come viste scrivendo il motivo
([diagnostica](diagnostica.md#dire-visto)).

## Le verifiche

Il sito risponde (dopo ogni modifica a `.env.local`):

```bash
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' https://127.0.0.1/health
```

L'applicazione in servizio raggiunge il TeX — è la domanda che conta, fatta dal
container attraverso nginx:

```bash
curl -sk -H 'Host: pantedu.eu' -H 'X-Forwarded-For: 127.0.0.1' https://127.0.0.1/health/tex
```

Risposte possibili:

| Risposta | Che cosa vuol dire |
|---|---|
| `{"tex":true}` (200) | ci arriva |
| `{"tex":false,"errore":"connessione rifiutata"}` (503) | nessuno ascolta a quell'indirizzo: uvicorn ascolta altrove, o l'endpoint è sbagliato |
| `{"tex":false,"errore":"tempo scaduto"}` (503) | i pacchetti si perdono: di solito manca la regola del firewall |
| `{"tex":false,"errore":"nome non risolto"}` (503) | l'endpoint usa un nome che il container non conosce |
| `{"tex":"non_configurato"}` (200) | il container non ha `TEX_COMPILE_ENDPOINT` o `TEX_COMPILE_SECRET` |

`/health/tex` è pubblico come `/health`, e per questo non dice mai indirizzi,
porte o segreti. Il dettaglio lo danno la diagnostica e i registri. La classe
dell'errore resta — «connessione rifiutata» e «tempo scaduto» mandano a
guardare due cose diverse, ed è l'esito del nostro cURL, non un pezzo della
topologia del server.

**La risposta vale trenta secondi.** Dal 20/9/2026 l'esito della sonda sta in
`{PANTEDU_DATA_PATH}/storage/cache/health-tex.json`, e mentre un processo sonda
gli altri si accontentano della risposta di prima (un lucchetto sul file
`.lock` accanto). Il motivo: l'endpoint è pubblico e fuori dal WAF, e una
chiamata sincrona di tre secondi a ogni richiesta bastava a tenere occupati i
dieci processi di php-fpm — sessanta richieste da un indirizzo, con i pacchetti
scartati, e il sito non risponde più a nessuno. Per avere una risposta fresca
si butta il file (è quello che fa il rilascio al passo 8):

```bash
sudo rm -f /var/lib/pantedu-data/storage/cache/health-tex.json
```

Se l'indirizzo del servizio cambia, la risposta di prima non vale: nel file c'è
l'impronta dell'endpoint a cui si riferisce.

Il middleware `rate` **non** servirebbe: misurato il 20/9/2026, agisce solo su
`POST`, `PUT`, `PATCH` e `DELETE` e lascia passare ogni `GET`
(`RateLimitMiddleware::handle`, prima riga). Metterlo su `/health/tex`
sarebbe una protezione che non protegge — un verde che non misura. Quello che
tiene è la cache.

La coda delle compilazioni dall'host:

```bash
sudo systemctl start pantedu-compile-jobs.service
```

```bash
journalctl -u pantedu-compile-jobs -n 5 --no-pager
```

senza «non risponde». Infine, dal browser: una pagina con figure TikZ non in
cache, un'anteprima PDF di una verifica, l'esportazione PDF di un esercizio.

## Chi lo controlla, e da dove

- **`GET /health/tex`** (`HealthController::tex`): la sonda del client
  (`TexCompileClient::sonda()`, tetto di tre secondi alla connessione) fatta
  dall'applicazione. È nel bypass del WAF, come `/health`.
- **La diagnostica, controllo `tex`** (`tools/ops/diagnostica.php`,
  `App\Services\TexCompile\ControlloTex`). Nel container (passo 8-bis del
  rilascio, come `www-data`) sonda il TeX direttamente. Sull'host (il timer
  delle 07:00 e delle 19:00) sonda direttamente, che vale per i lavori a
  orario, **e** chiede `/health/tex` attraverso nginx: la sonda diretta
  dall'host passerebbe anche con il container rotto, cioè nel guasto di
  settembre. Se non regge, mail.
- **Il rilascio, passo 8**: chiede `/health/tex` attraverso nginx dopo lo
  scambio. Solo un avviso: un TeX irraggiungibile non deve impedire di
  rilasciare una correzione.
- **I client** (`TexCompileClient`, `TikzRenderClient`, `SvgToPdfClient`,
  `TexFormatClient`): quando cURL non arriva al servizio scrivono una riga in
  `error_log` (nel container: `docker logs`) e un'anomalia
  `tex_irraggiungibile`; al docente dicono «Il servizio di compilazione non
  risponde», senza indirizzi. **Una compilazione più lunga del tetto del client
  non è quel caso** (20/9/2026): si riconosce da `CURLINFO_CONNECT_TIME`, letto
  prima di `curl_close()`, resta solo la riga in `error_log`, al docente si dice
  che il documento è troppo pesante, e chi chiama non ritenta. Vedi «I tetti»
  qui sotto.
- **La CI** (`immagine.yml`, «Il controllo del TeX guarda davvero»): con
  l'immagine appena costruita, il guasto di produzione riprodotto deve far
  uscire 1 il controllo, un servizio finto che risponde deve farlo uscire 0.
  Prova il controllo, non la topologia di produzione.

## I tetti: chi scade per primo

Ogni chiamata ha due tetti, quello del client (PHP, `CURLOPT_TIMEOUT`) e quello
del servizio (Python, `asyncio.wait_for` sul sottoprocesso). Misurati il
20/9/2026, con i valori predefiniti:

| Chiamata | Tetto del servizio | Tetto del client | Chi scade per primo |
|---|---|---|---|
| `/compile` | `TEX_COMPILE_TIMEOUT=30` **per passata**, `TEX_COMPILE_PASSES=2` → fino a ~60 s | 60 s (`tex_compile.timeout`) nelle verifiche e negli export; **35 s** con `TexCompileClient::tryDefault()` senza argomento (compilazione estemporanea) | quasi sempre il client |
| `/render-tikz` | `TIKZ_RENDER_TIMEOUT=20` per pdflatex **e** altrettanti per dvisvgm → fino a ~40 s | 25 s (`tikz_render.timeout`) | il client |
| `/svg-to-pdf` | `SVG_TO_PDF_TIMEOUT=10` | 15 s | il servizio |
| `/format-tex` | `TEX_COMPILE_LATEXINDENT_TIMEOUT=5` | 12 s | il servizio |

Dove scade prima il client si perde il log di LaTeX: il servizio sta ancora
lavorando, la connessione si chiude, e al docente non arriva niente da leggere.
Dal 20/9/2026 almeno **si chiama con il suo nome** — «la compilazione ha
superato il tempo massimo» invece di «il servizio non risponde» — non scrive
un'anomalia di irraggiungibilità e non si ritenta (`TexIrraggiungibile`,
`VerificaCompileController`).

**Perché i tetti non sono stati allineati adesso.** Alzare quelli dei client è
la strada sbagliata: sono i secondi in cui un processo di php-fpm resta
occupato, e quei processi sono dieci. La strada giusta è **abbassare quelli del
servizio** sotto quelli del client, così il documento pesante torna indietro con
il log di LaTeX invece che con una connessione chiusa. Si fa in
`/opt/tex-compile/.env`, che si installa a mano e che il rilascio non tocca, e
vuole una misura vera di quanto impiegano i documenti pesanti in produzione:
tagliare a occhio farebbe fallire figure che oggi funzionano. Da fare, non
urgente.

Attenzione al nome: `TEX_COMPILE_TIMEOUT` vale **per passata** in
`/opt/tex-compile/.env` (il servizio) e **totale per richiesta** nel `.env`
dell'applicazione (il client). Sono due file diversi e due cose diverse.

## Il codice del servizio lo porta il rilascio

`deploy-container.sh` porta in `/opt/tex-compile/app` i `.py` di
`tools/tex-compile-vps/app` — **sottocartelle comprese**, dal 20/9/2026:
`--exclude='*'` senza `--include='*/'` escludeva anche le cartelle, e rsync non
ci scendeva (misurato: con `main.py` e `sub/router.py`, in `/opt` arrivava solo
il primo, e il passo diceva «aggiornato»). Reinstalla le dipendenze se
`requirements.txt` non è quello installato, riavvia `tex-compile` e lo sonda sul
gateway. Se qualcosa non va: avviso, una riga `tex_sincronizzazione` fra le
anomalie, e il rilascio prosegue. Prima del passaggio ai container lo faceva
`deploy.sh` (passo 5); passando, il passo era rimasto indietro (rimesso il
19/9/2026).

**Confronta i file, non i commit** (20/9/2026). Al primo giro decideva con
`git diff PRIMA COMMIT`. Ma il `git reset --hard` sta al passo 1, cioè prima di
ogni uscita anticipata — il rinvio al rilascio differito del passo 2, il build
fallito, il container che non si dichiara mai sano al passo 6: un rilascio che
porta codice del TeX e poi esce da una di quelle porte lascia `/opt` indietro, e
il rilascio dopo confronta due commit fra cui del servizio non c'è più niente.
Nessun avviso, nessuna anomalia. Adesso il confronto è `cmp` fra i file del
repository e quelli installati, come fa `deploy.sh` per le unità systemd dal
31/8/2026.

**E un confronto che non si è potuto fare non è «sono uguali».** `cmp` esce 0
se i file combaciano, 1 se differiscono e 2 o più se non ha potuto leggere: i
tre casi restano distinti, e il terzo — come una cartella `app/` che nel
sorgente non c'è — si dice e fa sincronizzare lo stesso, invece di passare per
un «tutto a posto».

**Una sincronizzazione fallita si riprova** al rilascio dopo: il fallimento
lascia `/opt/tex-compile/.risincronizza`, e finché quel file c'è il passo
rifà copia e riavvio anche se i file combaciano. Serve al caso in cui la copia
è riuscita e il riavvio no: i file dicono di sì, ma il servizio gira ancora con
il codice vecchio in memoria. Il file si toglie da solo al primo giro riuscito.

**Sta dopo la verifica del passo 8**, non prima. Con la sincronizzazione prima,
uno scambio annullato (`rimetti_upstream` / `ferma_il_nuovo`) rimetteva in
servizio il container vecchio mentre in `/opt` c'era il codice TeX nuovo, già
riavviato: due versioni che non si erano mai viste insieme, e nessuno lo
diceva. Resta comunque **prima** della domanda a `/health/tex`, che così guarda
il servizio già riavviato.

Il rilascio **non** porta l'unità systemd, i pacchetti Debian né
`/opt/tex-compile/.env`: quelli si installano a mano. Il passo 5b di
`deploy.sh` (i modelli verso `/var/lib/pantedu-data/storage/templates`) non si
porta: la cartella non esiste più, il TeX compila i pacchetti che riceve e il
PHP legge i modelli dall'immagine.

## Allineare l'unità a quella del repository (da fare, non urgente)

Sul server c'è l'unità vecchia più il drop-in del passo 3. Quella del
repository (`tools/tex-compile-vps/systemd/tex-compile.service`) ha già
`After=docker.service` e legge l'indirizzo da `TEX_COMPILE_HOST` in
`/opt/tex-compile/.env`; differisce anche in `CPUQuota`, `MemoryHigh` e
`LimitNOFILE`. Si allinea guardando prima la differenza:

```bash
diff /etc/systemd/system/tex-compile.service /var/www/pantedu/tools/tex-compile-vps/systemd/tex-compile.service
```

poi, **in quest'ordine**: aggiungere `TEX_COMPILE_HOST=` seguito dal gateway a
`/opt/tex-compile/.env` (solo il valore, niente virgolette né commenti sulla
riga), installare l'unità, togliere il drop-in, `daemon-reload`, riavviare,
verificare come sopra. Senza `TEX_COMPILE_HOST` l'unità del repository **non
parte**, di proposito: uvicorn con un indirizzo vuoto ascolterebbe su tutte le
interfacce.

## Tornare indietro

Rimette il TeX su `127.0.0.1`, cioè il guasto: ha senso solo se questo assetto
ne crea uno peggiore. Un comando per volta:

```bash
sudo systemctl revert tex-compile
```

```bash
sudo systemctl restart tex-compile
```

In `.env.local` la riga `TEX_COMPILE_ENDPOINT` torna com'era (con `chattr -i`
e `chattr +i` come al passo 4), poi il rilascio forzato del passo 5. Infine la
regola del firewall, con gli stessi valori con cui è stata aggiunta:

```bash
sudo ufw delete allow in on docker0 from "$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Subnet}}')" to "$(sudo docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')" port 8001 proto tcp
```

## Cose da sapere

- **Chi raggiunge il TeX adesso**: oltre ai processi dell'host, ogni container
  sulla rete bridge predefinita — oggi solo `pantedu-app-*`. Le rotte che
  compilano vogliono la firma HMAC; `/health` non espone niente.
- **Il traffico non esce dalla macchina**: passa su `docker0`, in chiaro ma
  dentro l'host, autenticato. Contiene i sorgenti dei documenti del docente,
  compresi i risdoc BES/DSA.
- **Il VPS in locale non prova questo indirizzo**: lì il TeX è un container con
  nome. Il verde locale dice che l'applicazione sa compilare, non che in
  produzione raggiunge il servizio.

## Alternative scartate

- **Un socket Unix montato nel container**, come per MariaDB: più isolato, ma
  vuole codice in quattro client, un'unità `.socket` e una cartella montata, e
  porta con sé la trappola dell'inode (un socket ricreato cambia inode, e un
  montaggio del file singolo resta attaccato al vecchio).
- **Il TeX in un container** su una rete comune con l'applicazione: servirebbero
  due endpoint diversi (il nome nel container, `127.0.0.1` per i lavori
  dell'host), che con un solo `.env.local` non si possono avere; e andrebbe
  costruita sul VPS un'immagine da 6 GB.
