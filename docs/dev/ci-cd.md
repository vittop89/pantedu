# La catena: dal commit alla produzione

Questa pagina è una **mappa**, non un manuale: dice che cosa gira, quando,
che cosa blocca e dove guardare quando è rosso. I dettagli stanno altrove e
sono linkati — non vengono ripetuti qui, così non possono divergere.

| serve | sta in |
|---|---|
| montare l'ambiente di sviluppo | [`docs/dev/sviluppo-in-wsl.md`](sviluppo-in-wsl.md) |
| il cancello su `main`, le migrazioni, il rilascio nel dettaglio | [`wiki/dev-workflow.md`](../../wiki/dev-workflow.md) |
| i runner in casa | [`docs/ops/runner-self-hosted.md`](../ops/runner-self-hosted.md) |
| la suite end-to-end | [`docs/ops/e2e-runner-prerequisiti.md`](../ops/e2e-runner-prerequisiti.md) |
| perché la pipeline è fatta così | [`docs/analysis/pipeline-ci-cd-2026-09-08.md`](../analysis/pipeline-ci-cd-2026-09-08.md) |
| installare Pantedu su un server | [`docs/INSTALL.md`](../INSTALL.md) |

## Che cosa gira, e quando

| workflow | quando | blocca? | quanto ci mette |
|---|---|---|---|
| **CI** (`ci.yml`) | su ogni pull request verso `main` | **sì**, due contesti su quattro lavori | ~1 min; il lavoro con il database ~1 min in più |
| **Immagine dell'applicazione** (`immagine.yml`) | push su `main`; pull request che toccano `docker/`, `.dockerignore`, `composer.*`, `package*.json`, l'editor drawio (`tools/install-drawio.sh`, `tools/drawio-patches/`) o il workflow stesso — **nessun'altra** (vedi sotto) | no | 2–7 min di lavoro |
| **E2E dopo l'unione e di notte** (`e2e.yml`) | push su `main`; domenica 02:00 UTC; a mano | no | ~7 min di suite, più l'attesa dell'immagine del rilascio (a mano da un ramo: il suo build) |
| **A11y WCAG 2.2 AA** (`a11y.yml`) | pull request; domenica 05:00 UTC | no | 1–5 min |
| **lighthouse** (`lighthouse.yml`) | domenica 03:00 UTC | no | pochi minuti |
| **Compliance** (`compliance.yml`) | domenica 04:00 UTC | no | pochi minuti |
| **Avviso di guasto** (`avvisa-guasto.yml`) | chiamato dagli altri quando falliscono | — | — |

I tempi sono misurati sugli ultimi giri (12 settembre 2026) e sono **di
lavoro**: il tempo che passa da quando il giro compare a quando finisce può
essere molto più lungo, perché i runner sono quattro e i giri aspettano il
proprio turno. Un `immagine.yml` che dalla lista risulta di 35 minuti non ha
lavorato 35 minuti: ne ha passati quasi tutti in coda.

**I giri programmati sono settimanali, non notturni.** Tutti hanno `cron` che
finisce per `* * 0`, cioè domenica. Il nome del workflow E2E dice «e di notte»
ed è rimasto dal periodo in cui lo era: quello che gira davvero dopo ogni
unione è l'innesco `push: main`, non la pianificazione.

Girano tutti sui **runner in casa** — quattro, dentro la stessa istanza WSL in
cui si sviluppa — quando la variabile `RUNNER` del repository è impostata;
altrimenti su `ubuntu-latest`. Ogni workflow ha `runs-on: ${{ vars.RUNNER || 'ubuntu-latest' }}`,
quindi l'interruttore è uno solo ed è quella variabile.

## I due controlli che bloccano

Sono `Front-end: lint, test, build, guardie` e `PHP: analisi statica e test`.
Dentro ci sono molti passi; GitHub li conta per lavoro, non per passo. Gli
altri due lavori di `ci.yml` girano ma **non** bloccano:

- `Sicurezza: dipendenze, semgrep, segreti`, di proposito: diventa rosso quando
  pubblicano una CVE su una dipendenza che nessuno ha toccato;
- `PHP: prove d'integrazione (MariaDB)`, dal 14 settembre 2026, **per ora**:
  fa girare `tests/Unit` e `tests/Integration` su un MariaDB 11.8 (la versione
  della produzione) con schema, migrazioni e la semina della suite end-to-end,
  e un salto conta come fallimento. Il lavoro PHP qui sopra lancia solo la
  suite `unit`, senza database: l'integrazione prima non girava da nessuna
  parte. Se è rosso, il registro elenca le prove saltate con il motivo; la
  più probabile è il database che non ha risposto.

Il resto — chi può unire lo stesso, perché `strict` è falso e che cosa vuol
dire — è in [`wiki/dev-workflow.md`](../../wiki/dev-workflow.md), sezione «Il
cancello su `main`». È l'unico posto dove sta scritto: se cambia, cambia lì.

## Dal commit alla produzione

1. si unisce su `main`;
2. GitHub chiama il webhook sul VPS, che verifica la firma HMAC, controlla che
   sia `refs/heads/main` e posa un marcatore;
3. `pantedu-deploy.path` vede il marcatore e avvia `pantedu-deploy.service`,
   che esegue `tools/webhook/deploy-container.sh`;
4. il rilascio **aspetta l'immagine** che `immagine.yml` sta costruendo:
   `docker pull` 28 volte ogni 15 secondi, sette minuti in tutto;
5. se l'immagine non arriva, **la costruisce sul VPS** e va avanti lo stesso.
   Lo dice nel registro: «costruisco l'immagine QUI: è il ripiego, non il modo
   giusto»;
6. se `composer.json` o `composer.lock` sono cambiati aggiorna il `vendor/`
   dell'host, poi fa l'istantanea del database e applica le migrazioni;
7. avvia il container nuovo sulla porta libera (8090 o 8091), aspetta che si
   dichiari sano, riscrive l'upstream di nginx, ricarica, verifica
   **attraverso nginx**, e solo allora ferma il vecchio. In mezzo, se il codice
   del servizio TeX è cambiato, lo porta sull'host e lo riavvia (vedi
   [Cosa resta fuori dal container](#cosa-resta-fuori-dal-container-e-come-ci-si-arriva)),
   e allinea nel database le versioni legali di `docs/legal/versions.json`
   (vedi [Il `vendor/` dell'host e le versioni legali](#il-vendor-dellhost-e-le-versioni-legali)).
   Dal 24/9/2026 porta anche nella biblioteca TikZ dell'istanza i modelli
   versionati in `storage/templates/tikz/`, dentro il container nuovo come
   `www-data` (passo 8-quater, [ADR-050](../../wiki/decisions/ADR-050-modelli-tikz-versionati.md)):
   un guasto avvisa e scrive l'anomalia `modelli_tikz`, senza fermare niente.

Perché si va in produzione così, e che cosa ne consegue:
[ADR-048](../../wiki/decisions/ADR-048-rilascio-a-container.md).

Il container nuovo si dichiara sano solo se passa `docker/verifica-avvio.php`,
e dal 23/9/2026, con `APP_ENV=production`, questo vuol dire anche le guardie
sulla configurazione: limitatore, motivazione, debug, indirizzo, posta,
archivio, chiave del WAF, scenario (elenco e ragioni in
[variabili d'ambiente](../../wiki/environment-variables.md), «Le guardie
all'avvio del container»). Se una scatta, il rilascio si ferma con «il
container nuovo non è mai stato sano: niente scambio», il vecchio continua a
servire, e nelle righe del registro del nuovo che lo script stampa c'è
`[avvio] ERRORE: [guardia]` con la riga «guarda:». Si corregge `.env.local`
sul server, non la guardia: come, e come si guardano le guardie prima di un
riavvio, nel [runbook del sito irraggiungibile](../ops/runbook-sito-irraggiungibile.md),
§ 4.7 e § 4.8.

**Conseguenza pratica, che vale la pena sapere prima di rincorrere la CI: un
workflow dell'immagine rosso non è un rilascio fermo.** Il 12 settembre 2026
`immagine.yml` è morto in otto secondi per `docker: command not found`, e alla
stessa ora il sito girava sul commit giusto, costruito dal ripiego. Prima di
indagare, chiedere alla produzione che cosa sta servendo:

```bash
ssh <vps> 'curl -s http://127.0.0.1:8091/version'
```

(8090 o 8091: è la porta del container attivo, la dice `docker ps`.) Dal
browser non si può: il WAF risponde 403 agli strumenti automatici, e quel 403
non è un guasto.

C'è anche un timer di recupero, `pantedu-deploy-differito.timer`, che ogni
dieci minuti rilascia quello che era rimasto «in attesa»: quello che la finestra
oraria aveva rimandato, e **le unioni arrivate mentre un rilascio era già in
corso**.

### Più unioni di fila

Un rilascio alla volta: mentre ne gira uno, l'unità che guarda il segnale del
webhook non ne accoda un secondo. Quindi, se unisci tre pull request in pochi
secondi, parte un rilascio solo, e prende il `main` di quel momento — cioè la
prima.

Alla fine del rilascio lo script chiede a GitHub dov'è arrivato `main` davvero
(`git ls-remote`). Se è andato avanti lascia un biglietto, e il timer di
recupero fa partire un altro rilascio **entro dieci minuti**. Nel registro
compare:

```
[ATTENZIONE] main è andato avanti mentre rilasciavamo: 02f85ebb invece di 4c4a06b9.
[ATTENZIONE]   biglietto lasciato: il differito lo prende entro dieci minuti.
```

Fino al 12 settembre 2026 quel controllo esisteva ma **non poteva scattare**:
guardava la copia locale del riferimento remoto, che nessuno aggiornava fra
l'inizio e la fine del rilascio. Il 9 settembre è rimasto fuori dalla
produzione un commit, il 12 settembre tre, e in nessuno dei due casi è comparso
un avviso. Se GitHub non risponde, adesso lo dice invece di tacere. Provato dal
vivo la notte del 12: la seconda unione, arrivata sei secondi dopo la partenza
del rilascio della prima, è entrata in servizio da sola col biglietto.

**Il rilascio non aspetta un'immagine annullata.** `immagine.yml` ha
`cancel-in-progress`: una spinta nuova su `main` annulla il build dell'immagine
precedente, che quindi non arriverà mai. Fino al 12 settembre il rilascio la
aspettava lo stesso per tutti i sette minuti e poi la costruiva sul VPS: 487
secondi invece di 130, con un build pesante sulla macchina che serve il sito.
Adesso, mentre aspetta, chiede a GitHub dov'è `main`: se è andato avanti, lascia
il biglietto ed esce **prima** di istantanea e migrazioni, e nel registro
compare:

```
[ATTENZIONE] main è già a 2931e715: l'immagine di 14781bb5 non arriverà (la CI annulla il build quando arriva una spinta nuova).
[ATTENZIONE]   non la aspetto e non la costruisco: biglietto lasciato, il differito rilascia 2931e715 entro dieci minuti.
=== FINE, rimandato al rilascio di 2931e715, niente è cambiato ===
```

Se invece l'immagine arriva lo stesso, si rilascia quel commit e il biglietto lo
lascia la fine del rilascio, come sopra.

### Cosa resta fuori dal container, e come ci si arriva

Il rilascio mette in servizio un'immagine, ma tre cose restano sull'host, e il
container le raggiunge ognuna a modo suo. Sono i punti dove un rilascio verde
può lasciare una funzione rotta: il 19 settembre 2026 si è scoperto che dall'8
nessuna compilazione avviata dal sito funzionava, perché il container cercava
il TeX sul proprio `127.0.0.1`.

| Che cosa | Dove sta | Come ci arriva il container | Chi lo controlla |
|---|---|---|---|
| MariaDB | unità `mariadb` sull'host | il socket `/run/mysqld/mysqld.sock`, montato con `--mount` e non con `-v` (lo impone `check-deploy-units.mjs`) | `/health` (`"db":true`) al passo 8, che annulla lo scambio |
| il servizio TeX | unità `tex-compile` sull'host | HTTP sul **gateway del bridge Docker**, con una regola del firewall su `docker0`: [tex-dal-container](../ops/tex-dal-container.md) | `/health/tex` al passo 8 (solo avviso), la diagnostica `tex` dentro il container e sull'host |
| i dati d'istanza | `/var/lib/pantedu-data` | montati | la diagnostica `permessi` e `registro` |

Del servizio TeX il rilascio porta **il codice**: `deploy-container.sh`, dal
19/9/2026, copia in `/opt/tex-compile/app` i `.py` di
`tools/tex-compile-vps/app`, reinstalla le dipendenze se `requirements.txt` non
è quello installato, riavvia e sonda; se qualcosa non va avvisa e scrive
un'anomalia, senza annullare niente. Dal 20/9/2026 decide **confrontando i file
installati con quelli del repository** (`cmp`) e non `git diff` fra due commit:
il `reset --hard` del passo 1 viene prima di ogni uscita anticipata, quindi un
rilascio che porta codice del TeX e poi esce lasciava `/opt` indietro per
sempre — lo stesso difetto che `deploy.sh` si è tolto il 31/8/2026 per le unità
systemd. Una sincronizzazione fallita lascia un segno e si riprova al rilascio
dopo, e il passo sta **dopo** la verifica del passo 8, perché uno scambio
annullato non lasci in servizio il container vecchio con il TeX nuovo. Lo prova
`tests/ops/sincronizza-tex.test.sh` (46 verifiche), e `check-deploy-units.mjs`
impedisce che il passo sparisca. L'unità systemd, i pacchetti Debian e
`/opt/tex-compile/.env` invece si installano a mano. Il passo 5b del vecchio `deploy.sh` (i modelli
verso `/var/lib/pantedu-data/storage/templates`) è superato e non è stato
portato: il TeX compila i pacchetti che riceve, e il PHP legge la **base
versionata** dei modelli dall'immagine. Dal 20/9/2026 gli scostamenti del
docente e dell'istituto, invece, si scrivono e si leggono in
`/var/lib/pantedu-data/storage/templates` (vedi sotto): la cartella torna a
esistere, ma la crea l'applicazione quando qualcuno salva, non il rilascio.

In CI il TeX non c'è (servirebbe un'immagine da 6 GB sul runner) e le spec
`@tex` sono escluse; `immagine.yml` prova però il **controllo**: con l'immagine
appena costruita, il guasto di produzione riprodotto deve far scattare la
diagnostica `tex`, un servizio finto che risponde deve farla tacere.

### Il `vendor/` dell'host e le versioni legali

Il passaggio ai container aveva perso, senza che niente lo dicesse, tre passi
del vecchio `deploy.sh` (A-14 della revisione architetturale del 23/9/2026). Il
23/9 ne sono tornati due; il terzo, le unità systemd, resta a mano (vedi
[Che cosa il rilascio non installa](#che-cosa-il-rilascio-non-installa)). I due
tornati:

- il **`vendor/` dell'host**. Il container ha il suo, costruito nell'immagine;
  quello del sorgente sull'host lo caricano le migrazioni, i lavori a orario e
  l'avviso di guasto. Il primo aggiornamento di una dipendenza lo avrebbe
  lasciato indietro;
- le **versioni legali**. Il cancello dei Termini legge dalla tabella
  `legal_document_versions` quale versione è vincolante, e la tabella si
  allinea a `docs/legal/versions.json` con `tools/legal/sync_versions.php
  --apply`. Il 23/9 in produzione era ferma a Termini e AUP 1.3, mentre il
  registro portava dal 22/9 i Termini alla 1.5 e l'AUP alla 1.4.

Da allora `deploy-container.sh` le rifà, con lo stesso utente e nella stessa
cartella delle migrazioni:

| passo | quando | se fallisce |
|---|---|---|
| 2-bis: `composer install --no-dev --no-interaction --prefer-dist`, con un tetto di 60 secondi | solo se fra il commit in servizio e quello nuovo cambiano `composer.json` o `composer.lock` (quelli della radice) | avviso, righe `[composer]` nel registro, anomalia `vendor_host`; il rilascio prosegue, e il rilascio dopo ci riprova. Lo stesso se composer supera il tetto: `timeout` lo ferma con tutti i processi che ha lanciato |
| 8-ter: `sync_versions.php --apply` | a ogni rilascio: confronta e scrive solo quello che diverge | avviso, righe `[legal]` nel registro, anomalia `legal_versioni`; lo scambio resta |

Quattro scelte che la tabella non dice:

- il vendor si aggiorna **prima** delle migrazioni, come faceva `deploy.sh`:
  `tools/migrate.php` carica il `vendor/` dell'host, e una dipendenza nuova le
  farebbe fallire con quello vecchio. Siccome le migrazioni fallite fermano il
  rilascio, un passo composer messo dopo non girerebbe mai;
- le versioni legali si allineano **dopo** la verifica del passo 8, quando lo
  scambio non si annulla più: uno scambio annullato rimetterebbe in servizio i
  testi vecchi accanto a un database che conosce già la versione nuova, e il
  cancello chiederebbe di accettare un testo che la pagina non mostra;
- il confronto fra i due commit si fa **prima** del `reset --hard` e lascia un
  segno, che toglie solo un install riuscito. Il reset viene prima di ogni
  uscita anticipata (il rinvio al differito, il build fallito): senza segno, un
  rilascio che porta un `composer.lock` nuovo e poi esce lascerebbe il vendor
  indietro, e il rilascio dopo non vedrebbe più il cambiamento. È lo stesso
  difetto che il passo del TeX si è tolto il 20/9. Se il confronto non si può
  fare, si aggiorna lo stesso. Il segno sta in una cartella di root, 0700
  (`/var/lib/pantedu-rilascio`), non in quella del webhook, dove scrive
  `www-data`; ed è fuori dal perimetro di AIDE, le cui regole vanno
  reinstallate a mano ([diagnostica](../ops/diagnostica.md#le-regole-del-perimetro-si-installano-a-mano));
- composer ha un **tetto di tempo**, 60 secondi: il rilascio intero ne ha 900
  (`TimeoutStartSec` di `pantedu-deploy.service`), e un composer appeso lo
  farebbe uccidere da systemd a metà, magari durante le migrazioni. Un install
  da zero ci mette pochi secondi (misurato in WSL: 2,6 con la cache piena, 7,1
  con la cache vuota). Il percorso peggiore — sette minuti di attesa
  dell'immagine, quattro di build di ripiego, uno di composer più i quindici
  secondi di `--kill-after`, fino a due di attesa della salute — fa
  quattordici minuti e un quarto, e lascia circa quarantacinque secondi a
  istantanea e migrazioni: il tetto non si alza senza rifare il conto.
  Un'uscita 137 prima del tetto non si attribuisce al tetto: è un SIGKILL
  arrivato da fuori (per esempio l'OOM killer), e l'avviso lo dice.

Il ritocco dei permessi di `vendor/` che `deploy.sh` faceva dopo composer non è
stato portato: sull'host nessuno lo legge come `www-data` (il webhook non
carica l'autoload, e le unità di `tools/systemd` che lo usano girano come il
proprietario del sorgente), e gruppo e modi li riallinea comunque il passo 1.bis
a ogni rilascio.

Lo prova `tests/ops/vendor-e-versioni-legali.test.sh`, con le funzioni vere
dello script e comandi finti, nei due versi: la posizione dei tre punti dello
script in cui girano, il tetto con un composer finto che si appende, il
percorso e i permessi del segno e la sua esclusione da AIDE.

**Da quando vale: dal rilascio dopo quello che lo porta.** Il rilascio gira
dalla copia installata in `/usr/local/bin`, e il passo 1.ter la sostituisce con
quella del commit nuovo mentre il rilascio è in corso; il processo continua con
lo script di prima. Il registro lo dice: «questo script è stato aggiornato:
la versione nuova vale dal prossimo rilascio». Il rilascio dell'unione che porta
questi passi, quindi, non li esegue: le versioni legali nuove (Termini 1.5 e
AUP 1.4, in vigore dal 22/9) arrivano nel database con il rilascio dell'unione
successiva. Chi non vuole aspettare le allinea a mano, come l'utente
proprietario del sorgente, nella sua cartella:

```bash
sudo -u pantedu php tools/legal/sync_versions.php            # a secco: dice che cosa scriverebbe
sudo -u pantedu php tools/legal/sync_versions.php --apply
```

Il `vendor/` non ha niente da recuperare: quell'unione non toccava
`composer.json` né `composer.lock`. Vale per ogni modifica a
`deploy-container.sh`: il rilascio che la porta gira ancora con lo script
vecchio.

### Che cosa il rilascio non installa

Il terzo passo perso con il passaggio ai container non è tornato. I passi 7 e
7b di `deploy.sh` installavano le unità di `tools/systemd` e gli script
dell'infrastruttura del rilascio; `deploy-container.sh` installa solo sé stesso
(passo 1.ter). Che cos'altro resta a mano, e perché, sta nell'[ADR-048](../../wiki/decisions/ADR-048-rilascio-a-container.md). Una correzione a un timer o al contenimento di un'unità, unita e
verde, arriva in produzione solo se qualcuno la installa.

**Dal 23/9/2026 lo scarto si vede.** Il controllo `unita` della diagnostica
confronta, due volte al giorno sull'host, ogni unità e ogni drop-in di
`tools/systemd` con quelli in `/etc/systemd/system`, e va in guasto — quindi
manda la mail — se un'unità è diversa, non installata, installata ma spenta,
rimasta sul server dopo che il repository l'ha tolta, o se non ha potuto
confrontarla ([diagnostica](../ops/diagnostica.md#unita-le-unità-installate-sono-quelle-del-repository)).
Prima nessuno confrontava: `check-deploy-units.mjs` legge solo il repository.
Dopo un'unione che tocca `tools/systemd`, quindi, il giro della diagnostica
successivo al rilascio dice che cosa installare. Per saperlo subito, come root
sull'host, si fa girare la diagnostica da systemd e non da una shell:

```bash
systemd-run --uid=pantedu --gid=pantedu -p UMask=0007 --working-directory=/var/www/pantedu --wait --pipe /usr/bin/php tools/ops/diagnostica.php --solo=unita
```

`-p UMask=0007` come in `pantedu-diagnostica.service`: se il giro crea il
registro delle anomalie, il container (che ci scrive come `www-data`, solo
nel gruppo) deve poterci scrivere ([diagnostica](../ops/diagnostica.md#chi-scrive-il-registro)).

Non `sudo -u pantedu php …`: la diagnostica legge `.env.local`, e auditd
annota ogni lettura di quel file fatta da una sessione di login; il giro di
AIDE della notte dopo la segnalerebbe come `audit_segreti_letti`. Un processo
avviato da systemd la sessione non ce l'ha
([diagnostica](../ops/diagnostica.md#il-crontab-di-root-è-vuoto-e-non-per-ordine)).

Si installano a mano, come root sul VPS, dalla cartella del sorgente:

```bash
install -m 644 -o root -g root tools/systemd/<unità> /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now <unità>.timer     # solo per un timer nuovo o spento
```

Un drop-in va nella sua cartella, che `install -D` crea se manca:
`install -D -m 644 -o root -g root tools/systemd/<unità>.service.d/<file>.conf /etc/systemd/system/<unità>.service.d/<file>.conf`,
poi `systemctl daemon-reload`.

**Un'unità nuova si installa dopo il rilascio che la porta**, e fino ad allora
il controllo `unita` la dà «non installata». È voluto: una pulizia dichiarata
che non gira è un guasto. Il 23/9/2026 è arrivata
`pantedu-rate-limit-cleanup` (`.service` e `.timer`): ogni giorno alle 03:15
toglie da `rate_limits` le righe più vecchie di un'ora, cioè la pulizia
giornaliera che registro dei trattamenti (B.6) e DPIA dichiaravano senza che
nessuno la facesse. Il primo giro toglie tutto l'arretrato, ed è piccolo:
misurato in produzione il 23/9/2026, in sola lettura, `rate_limits` aveva
6.380 righe, tutte più vecchie di un'ora, la più vecchia del 19/4. Una
DELETE di quella misura, sull'indice `idx_rl_ts`, sta larga nei cinque
minuti di `TimeoutStartSec`. Prima di accendere il timer si lancia una volta
il servizio e se ne legge l'esito:

```bash
install -m 644 -o root -g root tools/systemd/pantedu-rate-limit-cleanup.service tools/systemd/pantedu-rate-limit-cleanup.timer /etc/systemd/system/
systemctl daemon-reload
systemctl start pantedu-rate-limit-cleanup.service
journalctl -u pantedu-rate-limit-cleanup.service -n 5 --no-pager    # «Removed N rate_limits rows older than 3600s.»
systemctl enable --now pantedu-rate-limit-cleanup.timer
```

L'infrastruttura del rilascio (`pantedu-deploy.path` e `.service`,
`pantedu-avviso@.service`, lo script del trigger, quelli della finestra oraria
e del differito, il drop-in di PHP-FPM `pantedu-auto-deploy.conf`) si
reinstalla invece con `bash tools/webhook/install_auto_deploy.sh`, che è
idempotente.

**La sandbox di PHP-FPM** (`php8.4-fpm.service.d/pantedu-sandbox.conf`) non la
installa nessuno script del rilascio. Sull'host PHP-FPM serve solo il webhook
(`/_hooks/github`), che dal passaggio all'«Opzione 4» non chiama né `sudo` né
`exec`: scrive un file di segnale e basta. La decisione di maggio di non
installarla («skip PHP-FPM sandbox», in `docs/todo/waf_security_prompt.md`)
valeva per il webhook di prima, che faceva `sudo` e `git`. Fino al 24/9/2026
il file aveva nove direttive su quattordici con un commento sulla stessa riga,
e systemd le scartava; la prova
`tests/Unit/Ops/UnitaSenzaCommentiInLineaTest.php` ora lo impedisce in ogni
unità. Misurato il 24/9, prima di installarla: `systemd-analyze security
php8.4-fpm.service` dava 9,6 («UNSAFE»), e il webhook rispondeva 401 a una
firma sbagliata. Si installa e si prova sul webhook, non sulla home del sito,
che risponde dal container comunque:

```bash
install -D -m 644 -o root -g root tools/systemd/php8.4-fpm.service.d/pantedu-sandbox.conf /etc/systemd/system/php8.4-fpm.service.d/pantedu-sandbox.conf
systemctl daemon-reload
systemd-analyze verify php8.4-fpm.service; echo "esito=$?"     # nessuna riga «Failed to parse»
systemctl restart php8.4-fpm; systemctl is-active php8.4-fpm
curl -sk -o /dev/null -w '%{http_code}\n' -X POST -H 'X-Hub-Signature-256: sha256=00' --resolve pantedu.eu:443:127.0.0.1 https://pantedu.eu/_hooks/github -d '{}'    # 401: PHP gira e legge il segreto
systemd-analyze security php8.4-fpm.service --no-pager | tail -1
```

Se `is-active` non dice `active` o il webhook non risponde 401, si torna
indietro subito: `rm /etc/systemd/system/php8.4-fpm.service.d/pantedu-sandbox.conf`,
`systemctl daemon-reload`, `systemctl restart php8.4-fpm`. La prova piena è il
rilascio dopo: l'unione successiva deve arrivare in produzione.

**Un'unità tolta dal repository si toglie anche dal server**, se no il
controllo `unita` la dà «orfana». Il 24/9/2026 è uscita `pantedu-tmp-cleanup`
con il suo script: dalla #185 del 21/9 il pacchetto TeX non si scrive più su
disco, e il timer, spento a mano quella sera, non aveva più niente da pulire.
Sul server:

```bash
systemctl disable --now pantedu-tmp-cleanup.timer
rm /etc/systemd/system/pantedu-tmp-cleanup.service /etc/systemd/system/pantedu-tmp-cleanup.timer
systemctl daemon-reload
systemctl reset-failed pantedu-tmp-cleanup.service pantedu-tmp-cleanup.timer 2>/dev/null
rm -f /var/lib/systemd/timers/stamp-pantedu-tmp-cleanup.timer
```

**Git sull'host, solo come proprietario del sorgente.** Per sapere se il
sorgente dell'host contiene già il commit che porta un'unità:
`sudo -u pantedu git -C /var/www/pantedu merge-base --is-ancestor <commit> HEAD; echo "esito=$?"`
(0: c'è). Mai `git` da root su `/var/www/pantedu`, nemmeno un `git status`:
riscrive `.git/index` come root, e il rilascio dopo fallisce sui permessi.

### Che cosa si scrive, e dove

`docker/Dockerfile` lo dice in una riga: «L'immagine è immutabile: tutto ciò
che si scrive sta sotto `PANTEDU_DATA_PATH`». Dentro il container la radice del
repository (`/var/www/pantedu`) è l'immagine più uno strato scrivibile di
overlayfs, e **quello strato riparte vuoto a ogni rilascio**. Una scrittura lì
riesce — `mkdir` e `file_put_contents` rispondono `true`, l'interfaccia dice
«salvato» — e sparisce qualche giorno dopo, senza un errore e senza una traccia
nei registri. La faccia gemella è la lettura: chi cerca nella radice del codice
un file che sta nei dati non trova niente e tira avanti in silenzio.

**Chi scrive nei dati, e con quali permessi.** Il PHP del container scrive come
`www-data`; le unità dell'host (cancellazioni, conservazione, diagnostica)
girano come `pantedu`, che sta nel gruppo `www-data`. Perché le une possano
toccare i file delle altre, tutti e due creano con **umask 007**: le unità
dell'host con `UMask=0007`, il PHP del container con `umask = 007` nella
sezione di php-fpm di `docker/supervisord.conf` (dal 24/9/2026; la prova è
`tests/Unit/Ops/UmaskDelContainerTest.php`). Prima il container creava
cartelle 0755, e il giro delle cancellazioni dell'art. 17 non poteva toglierne
i file (misurato in produzione il 24/9: 81 cartelle).

La regola, quindi:

- **si scrive** solo sotto la cartella dei dati, e lì solo sotto `storage/`.
  In PHP la radice la si chiede a
  `\App\Support\PercorsiDati::base(dirname(__DIR__, N))`, che legge
  `app.paths.data_base` e ricade sulla radice del repository quando
  `PANTEDU_DATA_PATH` è vuota — cioè in sviluppo locale, dove tutto continua a
  funzionare come prima. Chi chiama può passare una radice esplicita, che
  vince: serve agli strumenti da riga di comando e alle prove;
- **si legge** dai dati e, per gli alberi che hanno una base versionata,
  anche dal codice, con la cascata di `PercorsiDati::inCascata()`: prima i
  dati, poi l'immagine. Se un albero lo si scrive, TUTTI i suoi lettori vanno
  in cascata: metà dentro e metà fuori vuol dire che il pannello mostra una
  cosa e il documento generato ne usa un'altra, per sempre e senza un errore.

**Sotto `storage/`, non accanto.** In produzione `/var/lib/pantedu-data`
appartiene a root: il ripristino dà a www-data il solo `storage/`
(`docs/ops/ripristino.md`), il salvataggio notturno archivia il solo
`storage/` (`tools/backup/encrypted_backup.sh`) e `docker/verifica-avvio.php`,
prima che il container prenda traffico, prova a scrivere in ognuna delle
radici dichiarate in `app/Config/filesystem.php` — e si ferma se una sta fuori
da `storage/` o se non ci riesce. Una cartella fratello non è protetta, non è
salvata e non è controllata: il 20/9/2026 si è misurato un `POST
/api/admin/security/config` che rispondeva `200 {"ok":true}` con il file mai
creato.

Restano di sola lettura dall'immagine, **di proposito**, gli alberi versionati
che il rilascio porta con sé e che nessuno modifica a runtime:

| Albero | Perché resta nell'immagine |
|---|---|
| `views/`, `css/`, `js/`, `public/build/` | codice e asset: li costruisce la build |
| `docs/legal/`, `docs/privacy/`, `docs/curriculum/` | documenti versionati (`.dockerignore` li rimette dentro) |
| `database/migrations/`, `schemas/` | versionati; il Migrator lì dentro solo legge |
| `storage/templates/risdoc/` | 115 file in git: è la **base** dei modelli risdoc. Gli override del docente stanno nel database; gli scostamenti dell'amministratore (pannello «SORGENTE OPZIONI») stanno nei dati e vincono in cascata |
| `storage/templates/verifiche/_default/` | 20 file in git (8 griglie, 5 texCommon, 4 versioni, 3 badge_styles): la base dei modelli delle verifiche. Gli scostamenti (`t_<id>/`, `<codice istituto>/`) stanno nei dati e vincono in cascata |
| `storage/data/latex_shortcuts_default.json` | il riferimento di serie delle scorciatoie. Se l'amministratore lo cambia, la copia nuova va nei dati e vince |

A tenere ferma la regola c'è una guardia in `npm run ci`,
`tools/ci/no-scritture-nel-repository.mjs`. Non cerca di capire se una riga
scrive — seguire un valore attraverso funzioni e oggetti è un'analisi di flusso
che in uno script non si fa bene, e una guardia che sbaglia la si spegne
entro la settimana. Guarda il **primo segmento del percorso**: se un file di
`app/` costruisce `storage/`, `log/`, `logs/`, `temp/`, `verifiche/` o
`tex_pdf/` partendo dalla radice del codice (`dirname(__DIR__, N)`,
`app.paths.base`, `$_SERVER['DOCUMENT_ROOT']`), fallisce. Le poche letture
legittime della tabella qui sopra si dichiarano nello script, una per file, con
il motivo e con **quanti** riscontri ci si aspetta: il conto è esatto, così un
percorso nuovo infilato accanto a uno già dichiarato non passa inosservato, e
il totale ha un tetto che può solo scendere.

Le forme che riconosce sono cinque, e non è un dettaglio: una guardia vale
quanto le forme che vede. La concatenazione diretta, quella **spezzata su due
righe** (che il linter chiede appena il percorso è lungo), `sprintf('%s/…',
…)`, il passaggio per una variabile e `$_SERVER['DOCUMENT_ROOT']` — con il
nome della classe scritto sia corto sia qualificato
(`\App\Core\Config::get`). Le prime tre le ha scoperte una revisione
indipendente il 20/9/2026, misurando che sfuggivano; la quarta, qualificata,
era la forma che `WafAdminController` usava davvero.

Provata nei due versi il 20/9/2026: sul codice di prima segnalava 71 punti in
21 file; su quello di adesso tace. Una sonda con la forma sbagliata la fa
scattare, la stessa scrittura fatta bene la lascia muta, il ripiego
documentato (anche andando a capo) non la smuove, e nemmeno una lettura di
`views/`, `css/` o `schemas/`. Le prove stanno in
`tests/js-unit/percorsi-guardia.test.js` (undici, su file finti, con
`PERCORSI_DIR` a spostare la cartella esaminata), in `tests/Unit/Percorsi/` e
in `tests/Unit/Support/PercorsiDatiTest.php`: le quarantotto prove di PHPUnit,
girate contro l'`app/` di prima, ne sbagliano ventotto (21 fallimenti e 7
errori).

Quando il rilascio ripiega sul build locale, il registro dice **perché**: mancano
le credenziali del registro, sono state rifiutate, o l'immagine non è arrivata in
sette minuti. Prima diceva sempre la prima cosa, anche quando le credenziali
c'erano.

## La suite contro l'immagine del rilascio

Dal 14 settembre 2026 la suite end-to-end in CI non gira più su `php -S`: avvia
**l'immagine che va in produzione** e la prova attraverso il suo nginx e il suo
PHP-FPM. Il perché sono le differenze che nessuna prova poteva vedere, e che il
primo giro contro l'immagine ha trovato in un colpo:

- in produzione le sessioni stavano nella /tmp del container, e ogni rilascio
  buttava fuori tutti (ADR-039);
- `/vendor/quill/…` e `/vendor/mathjax/…` rispondevano 403: le pagine degli
  esercizi arrivavano senza editor e senza formule dall'8 settembre (#97);
- due esportazioni costruivano l'indirizzo del pacchetto da `HTTP_HOST`, che il
  nginx del container passa senza porta (`App\Support\IndirizzoPubblico`);
- un `.php` che non esiste lo ferma nginx con 404, e la suite si aspettava il
  410 dell'applicazione, che la produzione non ha mai risposto.

**Quale immagine** (`tools/ci/immagine-e2e.sh`, prova `tests/ops/immagine-e2e.test.sh`):

| giro | immagine |
|---|---|
| dopo un'unione, la domenica, a mano da `main` | `ghcr.io/<proprietario>/pantedu:<commit>`. Se non è ancora pubblicata la **aspetta**, fino a quindici minuti, come il rilascio. Se non arriva il giro si ferma dicendolo, e non ne costruisce un'altra al suo posto |
| a mano da un altro ramo | quella del commit, se c'è; altrimenti **la costruisce nel job**. Il riepilogo del giro dice quale delle due |

Il riepilogo del giro («Immagine provata») scrive etichetta e origine. Il passo
«Il container è quello del commit delle prove» confronta `/version` con il
commit delle spec: prende il posto, in CI, del controllo del `global-setup` sul
pacchetto più vecchio dei sorgenti, che resta per lo sviluppo.

**Come si avvia** (`tools/ci/container-app.sh`, lo stesso di `immagine.yml`):

- sulla rete dei servizi del job, quella del **suo** database;
- sulla porta scelta da `server-php.sh scegli`, perché `APP_URL` deve essere
  quella vera prima di partire;
- con i dati d'istanza in `RUNNER_TEMP`, montati come sul VPS, e due file di
  ambiente: uno per il runner, dove girano migrazioni, semina e `global-setup`,
  e uno per il container.

I modelli segnaposto dell'Istituto e i loro schemi, che la semina scrive nella
radice del progetto perché è lì che l'applicazione li cerca, si **copiano nel
container** (`docker cp`): l'immagine non cambia, il container muore col giro.

**La diagnostica di un giro rosso.** nginx nel container non tiene un registro
degli accessi, di proposito (Informativa §3.4), e non lo si accende per la CI.
Al posto delle righe `[esito]` del router ci sono:

- le **risposte 4xx/5xx delle API** nell'allegato `diagnostica.txt` di ogni
  prova rossa, accanto a quelle della pagina: le annota `HttpClient`, con
  `[api, <ruolo>]`. Le chiamate fatte direttamente con `http.request` ne restano
  fuori;
- «Errori PHP del container», dal registro del container e da
  `storage/logs/php_errors.log`, con la fonte scritta;
- le code dei registri del container e dell'applicazione, e il WAF dal database
  del giro.

**Sviluppo e CI rispondono uguale per costruzione.** Il router di `php -S`
(`tools/ci/router-php-server.php`) riproduce il nginx del container: divieti,
`/vendor/`, alias senza ripiego, `.php` che non c'è. L'elenco è uno solo,
`tests/ops/rotte-come-nginx.test.sh`: lo prova `ci.yml` sul router e
`immagine.yml` sul container. Una regola nuova in `docker/nginx.conf` va anche
lì, o la prova diventa rossa da una delle due parti.

**Quello che il rilascio installa sull'host non arriva nel container.**
L'immagine si costruisce da git: un file che non è versionato ci entra solo se
lo mette uno stadio del `Dockerfile`, e solo una prova in `immagine.yml` dice
che c'è. L'editor drawio (`/drawio-app/`, 145 MB, non versionato) lo installava
soltanto `tools/webhook/deploy.sh` sull'host: dall'8 al 15 settembre 2026 ogni
«Apri editor drawio» in produzione apriva una pagina che non esiste. Adesso lo
installa lo stadio `drawio`, con l'impronta del file verificata, e lo prova il
passo «L'editor drawio c'è».

**Restano su `php -S`**: `a11y.yml` e `lighthouse.yml`. Misurano le pagine, non
il servizio, e girano sulle pull request, dove l'immagine del commit non c'è:
passarli al container vorrebbe dire costruirla a ogni spinta.

**Lighthouse vuole il Chrome di Linux detto per nome.** Sul runner di casa
chrome-launcher si accorge di essere in WSL e apre il Chrome di Windows, con il
profilo nella cartella dell'utente Windows; il giro del 20/9/2026 è morto con
«Unable to connect to Chrome» ed è risultato verde, perché il passo aveva
`continue-on-error`. Dal 23/9 `lighthouse.yml` scarica il Chromium di
Playwright e lo passa in `CHROME_PATH`, e il passo può fallire: un giro rosso
apre la segnalazione della domenica. Con `CHROME_PATH` chrome-launcher crea
comunque il profilo con un nome in forma Windows (`C:\Users\…`), come
cartella nella cartella di lavoro: la toglie il checkout del giro dopo.

**Un'immagine del rilascio rossa lascia la suite senza niente da provare.** Se
`immagine.yml` fallisce, il rilascio ripiega sul build locale del VPS e va
avanti; la suite no, e dopo quindici minuti si ferma. È voluto: provare
un'immagine diversa da quella in servizio darebbe un verde che non dice niente
sulla produzione.

## Quando è rosso

**Prima domanda, sempre la stessa: è rotto il codice o è rotta la macchina che
lo prova?** I runner sono in casa, quindi la seconda possibilità è reale.

| sintomo | quasi sempre è |
|---|---|
| `docker: command not found` | Docker Desktop non è avviato, oppure l'integrazione WSL non si è installata perché la distro è partita prima di Docker |
| la suite E2E fallisce **tutta** insieme | il contenitore del database o il server sono giù — non è l'applicazione |
| il passo del server si ferma con «il server PHP non è partito sulla porta …» | la porta scelta è stata presa da un altro processo fra la scelta e l'avvio. È raro, e il job si ferma apposta invece di interrogare il server di un altro: si rilancia. Fino al 14/9/2026 la porta era la 8000 per tutti, e lo stesso caso dava centinaia di `ERR_CONNECTION_REFUSED` o, peggio, un verde |
| molti test con `error=invalid_credentials` | il giro sta parlando con un **altro** server rimasto acceso sulla stessa porta |
| un solo test rosso, con un errore di console | probabile difetto vero: guardare l'artefatto `playwright-report-<sha>-<frammento>` |
| «il pacchetto servito è più vecchio dei sorgenti» | solo in sviluppo: `npm run build` e rilancia. È una rete del `global-setup`, non un guasto. In CI il pacchetto è dentro l'immagine, e il suo commit lo verifica `/version` |
| «il registro rifiuta l'accesso a ghcr.io/…: unauthorized» nel passo «L'immagine del rilascio» | il gettone del job non legge il pacchetto, oppure le credenziali non stanno più nella cartella del job (`tools/ci/immagine-e2e.sh`). Fino al 14/9/2026 era il logout di `immagine.yml` sul `config.json` comune |
| «l'immagine … non è arrivata nel registro in 15 minuti» | il giro di `immagine.yml` di quel commit è rosso, annullato da una spinta nuova, o ancora in coda. Si guarda quello; la suite non prova un'immagine diversa da quella del rilascio |
| «/version non dichiara …» | il container non è l'immagine del commit delle spec: un'etichetta sbagliata, o un build che ha preso un altro commit |
| il passo «Il container non serve quello che non deve» di `immagine.yml`, o «Il router dello sviluppo risponde come nginx» di `ci.yml` | `docker/nginx.conf` e `tools/ci/router-php-server.php` rispondono diverso su una rotta dell'elenco (`tests/ops/rotte-come-nginx.test.sh`): si allinea quello rimasto indietro |
| il passo «I font del browser» fallisce | manca uno dei pacchetti di font dichiarati, o fontconfig non legge la configurazione: il messaggio dice quale. Il browser vede solo i font con cui sono state generate le immagini attese, quindi installare altro in WSL non cambia più il confronto a pixel (il 12/9/2026 TeX Live ne aveva fatti diventare rossi 31) |
| confronti a pixel rossi dopo un'unione che cambia una pagina di proposito | si guardano **prima** le immagini di differenza: stanno nella cartella del runner in WSL (`~/actions-runner*/_work/pantedu-dev/pantedu-dev/tests/e2e-results/`), finché il giro dopo non la ripulisce. Se ogni differenza è voluta, si lancia `e2e.yml` a mano con `aggiorna_immagini` e si versionano le immagini rigenerate. La tolleranza del 2% può nascondere una differenza vera finché un'altra la supera: il 14/9/2026 la voce «Sposta di classe» ha fatto emergere anche i selettori della barra vuoti, voluti dalla #63 e fino ad allora sotto soglia |
| 401 o 302 su più richieste della stessa pagina nello stesso secondo, e nella console «Unexpected token '<'» (una risposta HTML dove si aspettava JSON) | fino al 14/9/2026 era la rotazione dell'id di sessione (voce 101 del debito), tolta: l'id cambia solo a login, secondo fattore e cambio di ruolo. Se torna non è un falso rosso: si guarda la traccia (le risposte 4xx/5xx sono nell'allegato `diagnostica.txt`) prima di rilanciare |
| un 403 al posto di un 401 o di un 200 dopo aver preso il gettone da `/auth/csrf` («403 — CSRF invalid» nella traccia), a caso | fino al 14/9/2026 era la sessione su database senza blocco: in CI la tabella `sessions` esiste (il database nasce da `schema.sql`) e una richiesta della pagina finita dopo `/auth/csrf` cancellava il gettone. Corretto in `DbSessionHandler` (blocco per sessione). Dallo stesso giorno la CI usa i file come sviluppo e produzione (`SESSION_DRIVER`, ADR-039): se torna, non è il gestore su database, e si guarda la traccia della prova prima di rilanciare. Il 14/9, con il gestore su database: senza il blocco 7 fallite su 200, con il blocco nessuna |
| PHPStan rosso con «Ignored error pattern … was not matched in reported errors», o con «Invalid entry in ignoreErrors: Path … is neither a directory, nor a file path» | non è la macchina: un errore della baseline è stato corretto (il primo messaggio), o il suo file spostato o cancellato (il secondo), e la voce di `phpstan-baseline.neon` va tolta, abbassando il tetto in `tools/ci/phpstan-tetto.json`. Non si spegne `reportUnmatchedIgnoredErrors`, anche se PHPStan lo suggerisce ([`wiki/dev-workflow.md`](../../wiki/dev-workflow.md), «Quality gate») |
| `LaBaselineDiPhpstanPuoSoloScendereTest` rossa: «la baseline è cresciuta» o «abbassa il tetto» | nel primo caso un errore nuovo è finito in baseline: si corregge; nel secondo una correzione ha tolto errori, e il tetto in `tools/ci/phpstan-tetto.json` scende allo stesso totale |
| il passo «Nessuna prova saltata senza dichiararlo» della suite E2E | una prova è finita saltata: il registro dice file, titolo e motivo. Se manca un dato, si semina in `tools/ci/seed_e2e_database.php` e il salto diventa un `expect` ([`wiki/testing.md`](../../wiki/testing.md), «Regole»); se il passo dice che `results.json` non c'è, la suite non è arrivata a scriverlo |
| «item focused with '.only' is not allowed due to the 'forbidOnly' option» | è rimasto un `test.only` in una spec: in CI è vietato dal 23/9/2026 |
| «Node v20… da …, ma .nvmrc chiede 22: il passo setup-node non è girato…» nel lavoro «Front-end» | il Node che gira non è quello di `.nvmrc`. Lo mette davanti nel `PATH` `setup-node`, su tutti i runner, anche su quelli di casa, dal 23/9/2026 (prima in casa si saltava e girava il Node di sistema): si guarda nel registro il passo `actions/setup-node`, saltato o fallito, e il percorso che il messaggio stampa. Sui runner di casa non si aggiorna niente a mano ([`docs/ops/runner-self-hosted.md`](../ops/runner-self-hosted.md), «Il Node dei workflow non è quello della macchina») |
| PHPCS rosso nel lavoro PHP | un errore PSR-12 nuovo in `app/`: `composer cs:fix` lo corregge quasi sempre, poi `composer cs` deve uscire 0. Gli avvisi non fermano il passo |
| «I documenti generati corrispondono al codice» rosso, con un `git diff` su `docs/ROUTES.md`, `docs/api/openapi*.yaml` o `docs/PHASES.md` | una modifica a `routes/web.php` o al codice con marker `Phase N` non è arrivata al documento generato (dal 23/9/2026, DOC-13; prima solo `docs/ROUTES.md` restava allineato, e solo grazie al gancio delle sessioni di Claude Code). Si rilancia in locale il comando che il messaggio del `git diff` indica (`php tools/dev/gen_routes_md.php > docs/ROUTES.md`, `composer openapi:build` o `php tools/dev/gen_phases_md.php > docs/PHASES.md`) e si committa il file rigenerato |
| Security audit rosso | una CVE nuova su una dipendenza: non blocca, si guarda con calma |

### La trappola dei runner in casa

I quattro runner girano **nella stessa istanza WSL in cui si sviluppa**: stessa
rete, stessa `/tmp`. Fino al 14 settembre 2026 i workflow `a11y`, `e2e` e
`lighthouse` usavano tutti `127.0.0.1:8000` e lo stesso `/tmp/php-server.log`.
**Ora ogni job ha le sue**, e un controllo lo impone:

- **il server PHP** lo avvia `tools/ci/server-php.sh`. `scegli` fa assegnare
  al sistema una porta libera e scrive `URL_SERVER` e `REGISTRO_SERVER` (in
  `RUNNER_TEMP`); `avvia` considera partito il server solo se **il suo**
  processo resta vivo e dice di aver preso la porta. Che qualcuno risponda su
  quella porta non basta: era proprio così che un job interrogava il server di
  un altro. Prova: `tests/ops/server-php.test.sh`, in `ci.yml`, con un altro
  server sulla porta (rossa con uno script alla vecchia maniera);
- **il container di prova dell'immagine** ha nome e etichetta del giro
  (`pantedu-prova-<run>-<tentativo>`), la porta sull'host la sceglie Docker
  (`127.0.0.1::8080`, letta con `docker port`), e a fine giro si toglie;
- **la cache dei livelli** dei build è una per runner
  (`~/.cache/pantedu-buildx-<runner>`): lo scambio di cartelle a fine build
  pestava un altro build in corso;
- **Lighthouse** apre Chrome con `--remote-debugging-port=0` e legge la porta dal
  file `DevToolsActivePort` del suo profilo: era la 9222 fissa;
- **le credenziali del registro** della suite end-to-end stanno in una
  cartella di Docker del job (`DOCKER_CONFIG` in `RUNNER_TEMP`), non nel
  `~/.docker/config.json` che i runner hanno in comune. Il 14/9/2026 il primo
  giro dopo l'unione della #98 è morto con «unauthorized» nel momento in cui
  l'immagine è arrivata: `immagine.yml`, finendo, aveva fatto `docker logout
  ghcr.io` anche per lui;
- **il gancio d'inizio lavoro** di un runner si riprende i file della **sua**
  cartella di lavoro, non di tutte: si prendeva quelli del container di prova
  acceso su un altro runner, e quel container perdeva la cartella delle
  sessioni (`docs/ops/runner-self-hosted.md`, «Il gancio prima di ogni
  lavoro»);
- **`tools/ci/check-workflows.mjs`** (in `npm run ci`) rifiuta nei workflow una
  porta fissa su `127.0.0.1`/`localhost` e un percorso in `/tmp`, fuori dai
  commenti e da `APP_URL=`. Misurato: sui workflow di prima 29 problemi, su
  quelli nuovi nessuno;
- **`tools/ci/no-scritture-nel-repository.mjs`** (in `npm run ci`) rifiuta in
  `app/` un percorso dei dati costruito dalla radice del codice: nel container
  quella radice è l'immagine, la scrittura riesce e sparisce al rilascio dopo
  ([Che cosa si scrive, e dove](#che-cosa-si-scrive-e-dove)). Misurato: sul
  codice di prima 71 punti in 21 file, su quello di adesso nessuno;
- **un valore generato che entra in `$GITHUB_ENV` si maschera prima**
  (`echo "::add-mask::$valore"`): GitHub stampa l'ambiente in testa a ogni
  passo, e maschera da solo solo ciò che viene da `secrets.*`. Fino al 15
  settembre 2026 il registro della E2E aveva in chiaro, diciotto volte a giro,
  le password degli utenti di prova e il gettone delle metriche. Lo controlla
  `check-workflows.mjs` (prova: `tests/js-unit/workflow-segreti-mascherati.test.js`).
  Un segreto scritto solo in `.env`, un file, non passa dall'ambiente e resta
  libero.

Come si è arrivati qui, con i costi misurati:

- **un server di sviluppo lasciato acceso sulla 8000 dirotta la CI.** È
  successo due volte: il giro E2E post-unione ha parlato per 55 minuti col
  database di sviluppo invece che con quello seminato — 567 rossi, 187
  `invalid_credentials`, e due account di sviluppo bloccati per brute force.
  Per questo il server di sviluppo sta sulla **8765** e rifiuta di partire se
  la porta è occupata;
- **due workflow insieme si scontravano fra loro.** Il 12 settembre 2026 il
  controllo a11y di una pull request (21:27:45–21:28:51) ha preso la 8000 un
  minuto prima della suite E2E: il server della suite non è partito, il suo
  controllo d'avvio ha avuto risposta **dal server di a11y**, e quando a11y ha
  finito il server è sparito. 569 rossi, 1111 connessioni rifiutate. Il 14
  settembre il contrario, e peggio: il controllo a11y della #92 (giro
  34846303891) è girato tutto dentro la E2E di `main` e ha interrogato quel
  server, **verde senza aver provato il codice della pull request**; il giro
  dopo, da solo, ha dato 500 su ogni pagina;
- **anche la suite lanciata a mano in WSL si scontrava con quella della CI**,
  pur stando sulla 8765 con il suo database: le prove Lighthouse aprivano
  Chrome sulla porta di debug fissa 9222. Il 14/9/2026 tre
  `ConnectionClosedError`. Misurato dopo la correzione: con un altro processo
  in ascolto sulla 9222 la spec passa; quella di prima, nelle stesse
  condizioni, fallisce.

Prima di dare la colpa al codice, in WSL:

```bash
ss -ltnp | grep -E ':8765|php|chrome'
```

## Dove arrivano gli allarmi

- **CI e workflow**: notifiche GitHub, per posta; i giri della domenica e la
  suite end-to-end dopo ogni unione su `main` aprono anche una segnalazione
  «Giro rosso: …» (`avvisa-guasto.yml`), che si chiude da sola al giro verde;
- **produzione**: ogni unità systemd del VPS ha un `OnFailure=` che manda una
  mail via `tools/ops/avvisa_guasto.php`. C'è una briglia
  (`app/Support/BrigliaAvvisi.php`): un avviso per unità all'ora, con il conto
  di quelli soppressi;
- **integrità e audit**: il giro di AIDE delle 04:00 e le regole di auditd
  scrivono nel registro delle anomalie, e la diagnostica
  (`tools/ops/diagnostica.php`) va in `failed` finché non sono state guardate.
  Dopo averle guardate: il comando che la diagnostica scrive nel messaggio (`PANTEDU_DATA_PATH=… php tools/ops/visto.php --tutto`, vedi [diagnostica](../ops/diagnostica.md#dire-visto)).

Gli allarmi si **correggono**, non si silenziano: un rapporto che non è mai
pulito è un rapporto che dopo un mese non si apre più.

## Aggiungere un workflow

Quattro cose che qui non sono opzionali:

1. `runs-on: ${{ vars.RUNNER || 'ubuntu-latest' }}`, come tutti gli altri;
2. se avvia un server, **una porta sua** e un file di registro suo:
   `tools/ci/server-php.sh`, o `-p 127.0.0.1::PORTA` per un container. Una porta
   fissa o un file in `/tmp` li rifiuta `check-workflows.mjs` (vedi la trappola
   qui sopra);
3. le azioni di terze parti si fissano al **digest**, non al tag: negli altri
   workflow si vede la forma (`uses: owner/azione@<sha>  # v3`);
4. se un lavoro lancia `node`, `npm` o `npx`, ha un passo
   `actions/setup-node` **senza `if:`** e con `node-version-file: ".nvmrc"`,
   anche per i runner di casa (la cache di npm solo su quelli di GitHub:
   `cache: ${{ !vars.RUNNER && 'npm' || '' }}`). Lo controlla
   `check-workflows.mjs` (regola 8, dal 23/9/2026); il perché sta in
   [`docs/ops/runner-self-hosted.md`](../ops/runner-self-hosted.md), «Il Node
   dei workflow non è quello della macchina».

E se deve avvisare quando fallisce, si chiama `avvisa-guasto.yml` con
`uses: ./.github/workflows/avvisa-guasto.yml`. Il lavoro dell'avviso ha in
`needs` **tutti** gli altri lavori del workflow e ne combina l'esito
(`needs.*.result`), e il suo `if:` nomina ogni innesco senza nessuno davanti
che il workflow ha, `schedule` e `push`: lo controlla `check-workflows.mjs`
(regola 7, dal 23/9/2026). Fino a quel giorno l'avviso di `compliance.yml`
guardava solo `reuse-lint`, e quello di `e2e.yml` solo il giro della domenica.
