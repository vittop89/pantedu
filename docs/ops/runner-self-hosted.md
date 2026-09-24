# I runner in casa

Dall'8 settembre 2026 i workflow girano su runner **self-hosted** su una
macchina personale, non sui computer di GitHub.

## Perché

Non per capriccio: per il conto. Il piano gratuito dà 3000 minuti al mese, e a
inizio settembre ne erano stati consumati 2708 con 23 giorni davanti. Una
giornata da tre unioni, con la configurazione di allora, ne costava 177 — meno
di due giorni di autonomia.

Due interventi, in ordine di efficacia:

1. **Consolidare i job**: la fatturazione è per job, arrotondata al minuto
   intero, e la preparazione (checkout, setup, `npm ci`, `composer install`) si
   pagava nove volte invece di due. Da 11 a 5 minuti per giro, misurati.
2. **Portare i giri in casa**: i minuti su ferro proprio non si contano.

Il secondo ha anche restituito una cosa che il primo aveva dovuto togliere: la
**suite E2E su ogni unione**, la rete che dice se un'unione ha rotto qualcosa
*dopo* che il codice è già in produzione — il webhook rilascia entro due minuti.

## L'interruttore

Ogni `runs-on` nei workflow è:

```yaml
runs-on: ${{ vars.RUNNER || 'ubuntu-latest' }}
```

`RUNNER` è una **variabile del repository** (Settings → Secrets and variables →
Actions → Variables).

| `RUNNER` | dove girano i workflow |
|---|---|
| `pantedu` | sui runner in casa, minuti non contati |
| non impostata | sui computer di GitHub, come prima |

Cambiare quella variabile è l'unico gesto necessario. Nessuna modifica al
codice, nessun ramo, nessuna attesa.

**Il caso scomodo da conoscere**: variabile impostata e macchina spenta vuol
dire lavori **in coda**, non falliti. La pull request non diventa verde e basta,
finché non accendi o togli la variabile.

## Montarlo su una macchina nuova

Tre mosse. Su Windows si fa **dentro WSL2 (Ubuntu)**: i workflow sono scritti
per Linux — `bash`, `apt`, servizi Docker — e le immagini del confronto visivo
sono generate su Linux. Un runner Windows non li eseguirebbe.

```bash
# 1. Installa la catena e scarica il runner (verifica l'impronta da sé)
sudo bash tools/ci/prepara-runner.sh

# 2. I comandi che stampa, uno per runner, col gettone che generi tu su
#    Settings → Actions → Runners → New self-hosted runner (Linux / x64).
#    Il gettone scade in circa un'ora e serve solo a registrare.

# 3. Avviali come servizio, così ripartono da soli
sudo bash tools/ci/prepara-runner.sh --servizi

# 4. Il gancio prima di ogni lavoro e la sua regola di sudoers (vedi sotto)
sudo bash tools/ci/prepara-runner.sh --gancio
```

Il passo 4 va fatto **prima** di riavviare i servizi del passo 3 la prima volta,
o i runner vanno riavviati dopo: leggono il `.env` all'avvio.

Poi la variabile `RUNNER`, una volta sola.

Lo script installa PHP 8.3 con le estensioni che la CI **dichiara** — comprese
`pdo_sqlite` e `sqlite3`, senza cui settantasei test si saltano in silenzio e la
suite sembra verde — più il Node di `.nvmrc` (22 dal 23/9/2026: prima era 20, fuori
supporto), Composer, shellcheck, il client MariaDB e le librerie di sistema del
browser di Playwright.

Quelle ultime servono perché `playwright install --with-deps` invoca `sudo
apt-get`: su un runner che gira senza sudo automatico fallirebbe a ogni giro.
Installandole una volta, nei workflow basta `playwright install` — la scelta la
fa il workflow guardando `vars.RUNNER`.

**Il Node dei workflow non è quello della macchina.** Dal 23/9/2026 ogni lavoro
che usa Node passa da `actions/setup-node` con `node-version-file: ".nvmrc"`
anche sui runner di casa: setup-node cerca la versione nella cache degli
strumenti del runner (`~/actions-runner*/_work/_tool/node/`), la scarica solo
se non c'è e la mette davanti nel `PATH`, senza sudo. Il primo lavoro di ogni
runner la scarica, gli altri la trovano: misurato il 23/9 con setup-node v4 e
una cache vuota, il primo giro scarica Node 22.23.2 dal `.nvmrc`, il secondo
dice «Found in cache» e finisce in mezzo secondo, senza rete. Con `cache: ''`
(così la dà l'espressione dei workflow in casa) non salva niente di npm. Quando `.nvmrc` cambia non serve nessun passo a
mano prima dell'unione: la versione la decide il commit che gira. Il Node che
lo script installa nel sistema serve allo sviluppo nella stessa WSL (`npm
test`, il server, `tools/dev/wsl/prepara.sh`); si allinea rilanciando lo
script **dalla copia che ha il `.nvmrc` nuovo**, cioè da `~/pantedu` dopo
l'unione:

```bash
cd ~/pantedu && git pull --ff-only && sudo bash tools/ci/prepara-runner.sh --solo-pacchetti
```

Fino al 23/9 in casa setup-node si saltava (`if: ${{ !vars.RUNNER }}`, dalla
notte dell'8 settembre 2026) e girava il Node di sistema: il passaggio a Node
22 avrebbe fatto rosso il lavoro obbligatorio «Front-end» finché qualcuno non
avesse aggiornato ogni macchina, e con la copia dello script del ramo, perché
quella di `main` aveva «20» scritto nel codice. Il salto era nato per il
tempo: quella notte i primi giri in casa morivano sul tetto dei venti minuti,
e reinstallare PHP con `setup-php`, che in casa resta saltato, costava da solo
più di venti minuti per lavoro (lo dice il commento sul passo in `ci.yml`).
setup-node invece la cache la usa: le copie di Node 20.20.2 scaricate quella
notte sono ancora, il 23/9, in `_work/_tool` dei tre runner che l'avevano
fatto girare. Lo controlla `check-workflows.mjs` (regola 8): un lavoro che
lancia `node`, `npm` o `npx` deve avere setup-node senza `if:` e con la
versione da `.nvmrc`. Nel lavoro «Front-end» il passo «Node è quello di
.nvmrc» misura la versione che gira davvero.

La cache degli strumenti non la pota nessuno: una versione vecchia di Node
resta lì (167 MB Node 20, 204 MB Node 22, per ogni runner) finché non la si toglie a mano, a lavori
fermi (vedi «Riavviare i runner mentre stanno lavorando»):

```bash
rm -rf ~/actions-runner*/_work/_tool/node/20.*
```

**Docker deve rispondere** all'utente del runner: serve ai `services:` (MariaDB)
e al build dell'immagine. Su Windows si abilita in Docker Desktop → Settings →
Resources → WSL Integration. Lo script lo verifica e lo dice se manca.

## Quanti runner

Uno esegue **un lavoro alla volta**. Con uno solo, i cinque controlli di una
pull request girano in fila: l'attesa passa da un paio di minuti a dieci. Lo
script ne prepara quattro (`QUANTI=n` per cambiarne il numero), che su una
macchina moderna non si notano.

Per la stessa ragione la suite E2E resta su **un frammento solo**: dividerla in
quattro metterebbe i pezzi in coda sullo stesso runner, allungando l'attesa
invece di accorciarla. Se un giorno i runner diventassero molti di più, quella
scelta si rivede in due righe di `e2e.yml`.

## Riavviare i runner mentre stanno lavorando

Un `systemctl restart` sui servizi dei runner **uccide i lavori in corso**, e
il risultato su GitHub non dice che è stato un riavvio: dice

```
##[error]The runner has received a shutdown signal.
##[error]The operation was canceled.
```

sotto il nome del job. Da fuori è indistinguibile da una suite che fallisce.
La sera dell'8 settembre 2026 è successo davvero: la suite E2E su `main`
risultava rossa due giri di fila e sembrava un guasto del codice. Uno era il
`.git` di proprietà di root (quello vero, corretto); l'altro era lo script di
riparazione che riavviava i quattro servizi mentre uno stava girando.

Prima di riavviare, guardare se c'è lavoro in corso:

```bash
wsl -d Ubuntu -e bash -lc 'ps -ef | grep -c "[R]unner.Worker"'
```

Zero vuol dire che i runner sono fermi e si può riavviare. Un numero diverso
da zero vuol dire che quel numero di lavori sta girando: aspettarli costa
qualche minuto, ammazzarli costa un rosso da spiegare e un giro da rifare.

Vale anche per lo spegnimento del PC. Un lavoro interrotto non si riprende da
solo: va rilanciato dall'interfaccia di GitHub.

## La pulizia mensile

Su un runner in casa niente si butta via da solo. Dopo **una sola sera** di
lavoro c'erano quattordici volumi anonimi (2,4 GB) lasciati dai MariaDB di
servizio, immagini orfane per 3 GB, e dieci di cache dei build.

`tools/ci/pulizia-runner.sh` gira il primo del mese con un timer:

```bash
sudo install -m 755 tools/ci/pulizia-runner.sh /usr/local/bin/pantedu-pulizia-runner.sh
sudo install -m 644 tools/ci/pantedu-pulizia-runner.service /etc/systemd/system/
sudo install -m 644 tools/ci/pantedu-pulizia-runner.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now pantedu-pulizia-runner.timer
```

È prudente per scelta: **non** usa `docker image prune -a`, che porterebbe via
anche le immagini di base (php, node, mariadb) — sono vecchie per definizione, e
riscaricarle a ogni giro è tempo buttato: sono proprio loro a far stare i job
sotto il minuto. Toglie i container fermi, i volumi **anonimi** (quelli con un
nome se li è scelti qualcuno), le immagini orfane, quelle di pantedu oltre le
ultime dieci, e la cache oltre un tetto.

Mensile e non settimanale perché il disco è grande. Sul VPS, che ha
settantacinque giga in tutto, la potatura la fa il rilascio a ogni giro.

Il timer ha `Persistent=true`: se il primo del mese il PC è spento, recupera al
primo avvio utile invece di saltare il giro.

## Cosa cambia per la sicurezza

Un runner esegue il codice delle pull request. Su un repository privato dove le
pull request le apre una persona sola il rischio è teorico; sarebbe diverso con
collaboratori esterni, e **non va fatto su una macchina di produzione**.

### Lo stesso utente dello sviluppo, nei gruppi `docker` e `sudo`

**Rischio accettato per iscritto (revisione architetturale del 23/9/2026,
A-60).** I quattro runner girano come `User=operatore` nei loro file di unità
(`/etc/systemd/system/actions.runner.*.service`) — lo stesso account con cui
si sviluppa, non un utente dedicato al CI. Misurato lo stesso giorno con `id`:
è nei gruppi `sudo` e `docker`, e possiede `~/pantedu/.env.local` (600, i
segreti di questa istanza).

**Perché si accetta.** Un utente dedicato, senza `sudo` e senza `.env.local`
in lettura, sembrerebbe più sicuro ma non lo è quanto sembra: **il gruppo
`docker` equivale a root**. Chi può parlare al socket Docker può montare
`/` dell'host dentro un container e leggere o scrivere qualunque file con i
permessi di root — `docker run -v /:/host alpine cat /host/etc/shadow`
funziona per chiunque sia nel gruppo, utente CI dedicato compreso. Togliere
`docker` al runner lo isolerebbe davvero, ma la suite (MariaDB di prova,
l'immagine del rilascio, `immagine.yml`) ha bisogno di Docker per girare: non
è una scelta libera, è un compromesso fra due rischi, e si sceglie quello che
lascia la CI utilizzabile. Il gancio prima di ogni lavoro e la regola di
sudoers stretta (sotto) riducono che cosa un job può *toccare per sbaglio*,
non che cosa potrebbe fare un'azione **deliberatamente** ostile: quella
protezione sta nel fissare le azioni di terzi al commit (sotto), non nel
contenimento dell'utente.

**Gli audit di CI non guardano le dipendenze che girano su questi runner.**
`composer audit --locked --no-dev` e `npm run audit:js` (che è `npm audit
--omit=dev`), nel lavoro «Sicurezza», guardano solo le dipendenze di
produzione — ma npm, Composer, Playwright, i pacchetti che semgrep e gli
altri strumenti installano sono tutti dipendenze **di sviluppo**, ed è
proprio quel codice a girare, con questi permessi, sul computer di casa.
Dal 23/9/2026 (D-20) un secondo passo per ecosistema (`npm audit
--audit-level=none`, `composer audit --locked` senza `--no-dev`) guarda
anche l'albero di sviluppo, ma è pensato per restare visibile senza
bloccare: quello npm non fallisce mai da solo (`--audit-level=none`), e il
lavoro «Sicurezza» nel suo insieme non è fra i controlli obbligatori (sopra,
«Cosa cambia per la sicurezza»). Una vulnerabilità in uno strumento di
sviluppo compare nel registro del giro, non ferma niente.

### Le azioni di terzi

Al di là di chi è l'utente, una cosa cambia sul serio: le **azioni di
terzi**. `uses: tizio/azione@v3`
non è una versione, è un puntatore che il proprietario può spostare. Finché
girava su una macchina usa-e-getta di GitHub era un problema contenuto; ora
girerebbe sul computer di chi sviluppa, con i suoi file intorno.

Per questo tutte le azioni non-`actions/*` sono **fissate al commit**, con
l'etichetta in un commento accanto perché resti leggibile:

```yaml
uses: shivammathur/setup-php@f3e473d1…  # v2
```

Lo controlla `tools/ci/check-workflows.mjs`, dentro `npm run ci` e fra le
guardie — insieme a un secondo controllo, sulle chiavi ripetute dentro un passo:
due `if:` sullo stesso passo sono YAML valido per quasi tutti i parser ma GitHub
rifiuta il workflow intero, e l'unico segno è che nell'elenco dei giri compare
col nome del **file** invece che col suo `name`.

### Le azioni Docker lasciano file di root

Un'azione dichiarata `using: docker` gira **come root** con lo spazio di lavoro
montato dentro: tutto ciò che scrive resta di root sull'host. Su una macchina
usa-e-getta non si nota; su un runner che resta, il giro dopo
`actions/checkout` non riesce ad aggiornare i riferimenti e il job muore con
«fatal: cannot update the ref», che non dice niente di utile.

È successo con `returntocorp/semgrep-action`, che esegue `git` per capire cosa
è cambiato e lasciava `.git/HEAD` e `.git/logs/refs/heads/main` di root.
Adesso semgrep si lancia da noi con `docker run --user "$(id -u):$(id -g)"`,
così quello che scrive resta nostro.

**2026-09-09 — anche `fsfe/reuse-action` in `compliance.yml` è passata dalla
stessa cura**, prima che desse il problema invece che dopo. Era dello stesso
tipo, e in più aveva un difetto suo: l'azione ha un `Dockerfile`, non
un'immagine, quindi ogni esecuzione faceva `docker build`, scaricava
`fsfe/reuse:5` e ci costruiva sopra. `:5` è un'etichetta mobile — non si
sapeva nemmeno quale versione avesse girato. Adesso è un `docker run` col
digest, e il giro dura due secondi invece di ventisei.

Nessuna azione `using: docker` di terze parti resta nei workflow.

**E ogni lavoro che usa Docker ha una guardia** che verifica che risponda
prima di provarci:

```bash
command -v docker >/dev/null 2>&1 \
    || { echo '::error::docker non è nel percorso: avvia Docker Desktop e rilancia.'; exit 1; }
docker info >/dev/null 2>&1 \
    || { echo '::error::Docker non risponde: apri Docker Desktop, aspetta «Engine running», rilancia.'; exit 1; }
```

Fallisce, non salta: un controllo di sicurezza che si auto-salta quando manca
uno strumento è un'altra spunta verde che non misura niente. Il messaggio dice
cosa fare, invece di lasciare un errore che parla d'altro trecento righe più in
basso.

**Se Docker Desktop non riparte** — succede, e l'errore parla di «engine.sock»
o «Secrets Engine» — la cura *non* è il ripristino di fabbrica: sono socket
rimasti appesi. Si rinominano le due cartelle di stato e si fa
`wsl --shutdown`, poi riparte.

### Il gancio prima di ogni lavoro

C'è anche una rete: un **gancio** eseguito prima di ogni lavoro
(`ACTIONS_RUNNER_HOOK_JOB_STARTED`) che, se trova nella cartella di lavoro
file non del runner, se li riprende. Il codice è `tools/ci/gancio-inizio-lavoro.sh`
(prova: `tests/ops/gancio-runner.test.sh`, in `ci.yml`); lo installa, con la
riga di `.env` di ogni runner e la regola di sudoers, `sudo bash
tools/ci/prepara-runner.sh --gancio`. Si rilancia quando cambia il gancio: la
copia installata non segue il repository da sola.

Due regole, tutte e due imparate a proprie spese:

- **Il gancio tocca solo il suo runner.** Lo riconosce risalendo i processi fino
  a `actions-runner*/bin/Runner.Worker`; se non lo trova non tocca niente. Fino
  al 14 settembre 2026 girava su tutte le `actions-runner*/_work` della macchina:
  un lavoro che cominciava su un runner si riprendeva i file del container di
  prova ancora acceso su un altro. La cartella delle sessioni di quel container
  (www-data, 0700) passava all'utente del runner, PHP-FPM non ci scriveva più, e
  il job dell'immagine è morto «unhealthy» dopo che l'avvio aveva verificato
  tutto. Si riconosce così: `sessioni: la cartella … non esiste o non è
  scrivibile` nel registro del container, **dopo** `[avvio] … a posto`, con un
  altro workflow partito in quei minuti.
- **La regola di sudoers ha un percorso esatto per runner**, senza asterischi.
  Quella montata a mano il 9 settembre (`chown -R … actions-runner*/_work`) si
  diceva strettissima e non lo era: in sudoers l'asterisco negli argomenti prende
  anche spazi e barre. Misurato il 14 settembre eseguendo davvero con `sudo -n`,
  su cartelle proprie: ammetteva senza password `chown -R` di altre cartelle
  qualsiasi, purché l'ultima finisse in `/_work`. Cioè il possesso di qualunque
  file per il codice di qualunque job. `--gancio` controlla nei due versi che il
  gancio passi e che gli argomenti in più chiedano la password. Per misurarlo
  **non serve `sudo -l`**: risponde di sì anche per la regola generale con
  password.

**Sulla macchina di casa è installata dal 14 settembre 2026** (sera), con
`--gancio`. Controllato dall'utente dei runner, eseguendo davvero:
- il `chown` del gancio sulla `_work` di ciascuno dei quattro runner passa senza
  password;
- lo stesso comando con una cartella in più, e la vecchia forma che l'asterisco
  lasciava passare, rispondono «a password is required».

Si ricontrolla così, su cartelle proprie. `-k` ignora una password data da poco,
che farebbe passare tutto:

```bash
sudo -k -n /usr/bin/chown -R "$USER:$USER" ~/actions-runner-2/_work; echo $?        # 0
sudo -k -n /usr/bin/chown -R "$USER:$USER" ~/actions-runner-2/_work /tmp/x/_work; echo $?   # 1
```

Una misura di contorno, non una barriera: l'utente dei runner può usare Docker,
e secondo la documentazione di Docker il gruppo `docker` vale quanto root. La
regola stretta toglie una strada in più, non l'unica.

Allo stesso modo ogni job ha un **`timeout-minutes`**: su GitHub un job appeso
muore dopo sei ore e la macchina si butta via, ma qui occuperebbe uno dei
quattro posti bloccando tutto il resto.

E ogni workflow dichiara **`permissions: contents: read`**: senza, vale il
predefinito del repository, che può essere più largo del necessario e cambiare
da un'impostazione altrove. Chi ha bisogno di più se lo dichiara — `immagine.yml`
chiede `packages: write` perché pubblica, `lighthouse.yml` `pull-requests:
write` perché commenta.

## Come si toglie

```bash
# Smetti di usarli: cancella la variabile RUNNER dalle impostazioni.
# Poi, se vuoi rimuoverli davvero dalla macchina:
cd ~/actions-runner && sudo ./svc.sh stop && sudo ./svc.sh uninstall
./config.sh remove --token GETTONE   # lo stesso tipo di gettone della registrazione
```

Ripetuto per ogni cartella `~/actions-runner*`.
