# Sviluppare Pantedu in WSL

Dal **10 settembre 2026** si sviluppa in WSL2 (Ubuntu), non più su Windows con
XAMPP: codice, prove, build, suite end-to-end. Questo documento serve a
montare l'ambiente su un computer nuovo, e dice che cosa passa ancora da
Windows. La vecchia copia del repository su Windows non si usa più. È in
dismissione dal 22/9/2026: eliminazione decisa, la fa l'utente.

## Perché

Misurato sulla stessa macchina, la notte del 10 settembre:

| | Windows + XAMPP | WSL, repository in ext4 |
|---|---:|---:|
| suite end-to-end | 557 passate, 59 saltate — **12,7 min** | 582 passate, 0 saltate — **6,3 min** |
| confronto a pixel | non gira mai | 59 su 59 in 24,6 s |
| PHPStan | ~30 s | 1 s |
| `npm run ci` | ~90 s | 8 s |
| prove PHP | una saltata (permessi POSIX) | nessuna saltata |

Metà del tempo, e con dentro le prove che su Windows si saltano. E l'ambiente
è quello della CI e della produzione — Linux, MariaDB, gli stessi binari —,
quindi spariscono le differenze che su Windows costavano ore: i CRLF, i binari
nativi di `node_modules`, i rossi «solo in locale».

**Il repository vive dentro il filesystem di Linux (`~/pantedu`), mai in
`/mnt/c`.** Da `/mnt/c` le stesse operazioni sono da 8 a 19 volte più lente di
Windows nativo, e fino a 97 volte più lente di ext4. Chi prova WSL tenendo il
progetto su C: misura quella colonna e conclude, sbagliando, che WSL è lento.

## Una volta per macchina

**1. WSL2 con Ubuntu, e Docker Desktop che lo vede.** Docker Desktop →
Settings → Resources → WSL Integration → spuntare Ubuntu → Apply. Da Ubuntu,
`docker info` deve rispondere.

**2. I pacchetti** (PHP 8.3 con le estensioni della CI, il Node di `.nvmrc`, Composer, il
client MariaDB, shellcheck, le librerie del browser, TeX Live come in
produzione):

```bash
sudo bash tools/dev/wsl/pacchetti.sh
```

Riusa `tools/ci/prepara-runner.sh --solo-pacchetti`: l'elenco dei pacchetti
della CI sta in un posto solo. TeX Live pesa qualche gigabyte.

**3. git che si autentica attraverso Windows.** Il Git Credential Manager di
Windows riusa l'accesso a GitHub già fatto lì; nessuna credenziale passa di
mano:

```bash
git config --global credential.helper "/mnt/c/Program\ Files/Git/mingw64/bin/git-credential-manager.exe"
```

## Il repository

**Si clona dentro WSL.** Non si copia la cartella di Windows: con
`core.autocrlf=true` il checkout di Windows scrive CRLF, e portato in WSL git
vede centinaia di file modificati senza che nessuno li abbia toccati (613, il 10
settembre).

```bash
git clone <indirizzo-del-repository> ~/pantedu
cd ~/pantedu
bash tools/dev/wsl/prepara.sh
```

`prepara.sh` installa le dipendenze **qui** (`node_modules` contiene binari
nativi: copiati da Windows non funzionano), costruisce il front-end e scarica il
browser di Playwright. Con `--dati-da <cartella storage>` copia anche i dati
d'istanza di sviluppo, che in git non ci sono.

Scrive anche il **marcatore `.pantedu-dev-repo`**, se manca. È ignorato da
git, quindi un clone nuovo non ce l'ha; e dove non c'è,
`tools/publish/sanitize-for-publication.php --apply` riscrive e cancella i file
di sviluppo invece di rifiutarsi. La copia in WSL ne è rimasta senza dal 10 al
22 settembre 2026, senza che nessuno se ne accorgesse. Lo script è
`tools/dev/wsl/marca-sviluppo.sh`, la prova `tests/ops/marca-sviluppo.test.sh`.

## Il database

```bash
bash tools/dev/wsl/database.sh
```

Un contenitore `pantedu-mariadb-dev`: MariaDB 10.11 come la CI, dati in un
volume con un nome (sopravvivono a `docker rm`), porta `127.0.0.1:3307` —
non 3306, che su una macchina con XAMPP ancora acceso collide. Parte da solo al
riavvio.

In `.env.local` (mai versionato), le righe della macchina:

```ini
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=pantedu_dev
DB_USER=root
DB_PASS=root
APP_URL=http://127.0.0.1:8765
SESSION_COOKIE_SECURE=false
TEX_COMPILE_ENDPOINT=http://127.0.0.1:8001
```

Le credenziali del contenitore valgono solo per lui, che ascolta sulla loopback
di WSL. I segreti veri (`KMS_MASTER_KEY`, `STORAGE_SIGNING_SECRET`, le
password degli utenti di prova…) restano quelli di prima.

`APP_URL` e `TEX_COMPILE_ENDPOINT` vanno qui (23/9/2026): il `.env`
versionato le lascia vuote, perché è anche il file che il rilascio monta in
produzione e un indirizzo di questa macchina lì è sbagliato. Senza le righe
i collegamenti generati ripiegano su quello che il codice ha a disposizione
(l'host della richiesta, o in alcuni punti il dominio di produzione scritto
nel codice: rilievo A-13 della revisione del 23/9) e la compilazione TeX
dice «non configurata». Che cosa va in quale file:
[variabili d'ambiente](../../wiki/environment-variables.md), «Quale file
vale dove».

**Il limitatore delle richieste lo spegne `.env.local`, e ce lo mette
`server.sh`** (23/9/2026). Il `.env` versionato lo tiene acceso, perché il
rilascio lo monta anche in produzione (gli altri posti dove vale `1`:
[variabili d'ambiente](../../wiki/environment-variables.md)). `server.sh`, a
ogni avvio, aggiunge `RATE_LIMIT_DISABLED=1` in fondo a `.env.local` se la
chiave non c'è (`tools/dev/wsl/limitatore-sviluppo.sh`, prova
`tests/ops/limitatore-sviluppo.test.sh`). Se c'è, con qualunque valore, non
tocca niente: con `0` si prova il limitatore dal browser. Il server integrato
rilegge i `.env` a ogni richiesta, quindi basta rilanciare `server.sh` anche
con il server già acceso. Senza la chiave la suite end-to-end gira col
limitatore acceso, e le spec cominciano a ricevere 429 che non c'entrano con
quello che provano.

## Tutti i giorni

```bash
bash tools/dev/wsl/server.sh             # http://127.0.0.1:8765, anche dal browser di Windows
bash tools/dev/wsl/server.sh --ferma
node tools/dev/tex-service-local.mjs     # il servizio TeX, per le prove @tex
npx playwright test                      # la suite: punta da sola a 127.0.0.1:8765
php vendor/phpunit/phpunit/phpunit
php -d memory_limit=1G vendor/phpstan/phpstan/phpstan.phar analyse --memory-limit=1G
npm run ci
```

**Una spec nuova si controlla anche nei tipi.** `npm run ci` NON comprende
`npm run e2e:typecheck`, che in CI è un passo a sé («Tipi del supporto E2E»):
le spec sono JavaScript ma `tsc` le legge lo stesso, e due cose che in JS
passano lì no — `offsetParent` su un `Element` (sta su `HTMLElement`) e il
risultato di `querySelector`, che può essere `null`. Il 21 settembre 2026 mi ha
fermato due volte di fila, dopo aver spinto:

```bash
npm run e2e:typecheck
```

**Dopo ogni modifica al CSS, rifare il fascio.** Le pagine caricano
`css/main.bundle.css` — un file generato, non versionato, che
`app/Support/CriticalCss.php` preferisce a `main.css` quando esiste. Finché non
lo si rigenera il browser serve la versione vecchia, e si guarda una pagina che
non è quella del repository:

```bash
php tools/build-css-bundle.php
```

`npm run build` non lo tocca, e nemmeno `npm run ci`. Chi lancia la suite
end-to-end se ne accorge (il `global-setup` confronta le date e si ferma);
chi guarda la pagina a mano no, e il 21 settembre 2026 ci ho perso un giro
credendo che una regola nuova non funzionasse. In alternativa si cancella
`css/main.bundle.css` e si lascia servire `main.css`, che legge i moduli.

Il server è quello integrato di PHP con `tools/ci/router-php-server.php`, che
risponde come il nginx del container (`docker/nginx.conf`):
- instrada all'applicazione anche le rotte con un punto nel nome
  (`/api/study/content.json`);
- serve `/css/`, `/js/`, `/img/` dalle cartelle del repository, e
  `/vendor/` da `public/vendor`;
- nega `/views/`, le cartelle di codice e i file nascosti;
- risponde 404 a un `.php` che non esiste.

Mai i sorgenti PHP. Dal 14 settembre 2026 la suite in CI gira contro l'immagine
del rilascio, e il router diverge da nginx solo se lo si lascia divergere:
l'elenco delle risposte da tenere uguali è `tests/ops/rotte-come-nginx.test.sh`
(`docs/dev/ci-cd.md`, «La suite contro l'immagine del rilascio»).

**Porta 8765, e né 8000 né 8080.** I runner della CI stanno nella stessa istanza
WSL. Fino al 14 settembre i workflow aprivano `127.0.0.1:8000`, e il 10 un server
di prova rimasto acceso lì ha fatto fallire un job della CI, che interrogava lui
invece del proprio. Ora ogni job si fa dare dal sistema una porta libera (fra le
effimere, sopra la 32768) e si ferma se non la prende (`docs/dev/ci-cd.md`, «La
trappola dei runner in casa»); la 8765 resta fuori da quelle. La 8080 è la prima porta che viene in mente a chiunque — sulla
macchina di riferimento l'occupava il contenitore di prova dell'immagine. Se la
porta è presa da qualcun altro, `server.sh` si rifiuta di partire invece di far
finta di essere già acceso: «già acceso» lo decide dal proprio file PID, non dal
fatto che qualcuno risponda.

**Un secondo server su un'altra porta, per una copia di lavoro, non è isolato
del tutto.** Si avvia con `PORTA=87xx bash tools/dev/wsl/server.sh` dalla copia,
e le spec si lanciano con `FM_E2E_BASE_URL`. Ma `APP_URL` resta quello di
`.env.local`, cioè la 8765, e `.env.local` vince anche su una variabile
d'ambiente: gli indirizzi assoluti che l'applicazione genera portano al server di
`~/pantedu`. Le sessioni sono file della copia (`storage/sessions`), quindi lì
la richiesta arriva senza sessione. Misurato il 15/9/2026: le spec risdoc che
scaricano il pacchetto TeX (`/api/risdoc/exports/…`) ricevevano la pagina di
accesso, con la stessa prova verde da `~/pantedu` e l'archivio creato
regolarmente nella copia. Una spec che segue un indirizzo generato dal server si
prova dalla copia servita sulla 8765, o si lascia alla CI, dove `APP_URL` è la
porta del job.

**E la 8765 può servire un'altra copia.** `server.sh` serve la copia da cui lo
si lancia, e resta acceso finché non lo si ferma: se l'ha avviato una sessione
da una copia di lavoro, la 8765 serve quella, e la suite lanciata da
`~/pantedu` prova il codice dell'altra. Il controllo del pacchetto di
`tests/e2e/global-setup.js` guarda la copia che lancia Playwright, non quella
servita, quindi non se ne accorge. Misurato il 24/9/2026: la 8765 serviva
`.claude/worktrees/errori-tex`, sei prove della barra dell'editor sono passate
sul codice di quella copia, e se ne è accorta solo la settima, che cercava una
voce di menu nuova. Prima di lanciare la suite si guarda chi serve la porta:

```bash
ss -ltnp | grep ':8765 '
```

e con il `pid` che stampa, `readlink /proc/<pid>/cwd`. Se non è la copia su cui
si lavora, e il server non è nostro da spegnere, se ne avvia uno dalla propria
(`PORTA=8766 bash tools/dev/wsl/server.sh`) e si lancia la suite con
`FM_E2E_BASE_URL=http://127.0.0.1:8766`, con i limiti del paragrafo sopra.

Le prove PHP usano un database a parte, `pantedu_test` (le impone
`phpunit.xml` con `APP_ENV=testing`): `database.sh` lo crea vuoto; si popola con
`php tools/setup_test_db.php` o importando un dump con `--importa-test`.

## Il VPS in locale

`server.sh` serve per lavorare. Per vedere il codice **come girerà in
produzione** c'è `vps-locale.sh`: l'immagine costruita da questa copia, con
intorno i servizi del VPS. Dal menu di `tools/postazione` è la voce 14.

```bash
bash tools/dev/wsl/vps-locale.sh avvia          # controlla il ramo, costruisce, avvia, prova
bash tools/dev/wsl/vps-locale.sh ricostruisci   # dopo una modifica: scambio blu/verde
bash tools/dev/wsl/vps-locale.sh stato          # cosa gira, da quale commit, gli indirizzi
bash tools/dev/wsl/vps-locale.sh ramo           # solo il controllo del ramo, senza costruire
bash tools/dev/wsl/vps-locale.sh prova          # i controlli, di nuovo
bash tools/dev/wsl/vps-locale.sh lavori         # i lavori a orario, subito
bash tools/dev/wsl/vps-locale.sh registro [app|nginx|tex|db|lavori]
bash tools/dev/wsl/vps-locale.sh ferma          # spegne, tiene database e dati
bash tools/dev/wsl/vps-locale.sh pulisci [--tutto]
```

Il sito è **https://127.0.0.1:8443**: attraverso un nginx con i file di
`infra/nginx`, quindi con TLS, limiti di frequenza e intestazioni come sul
server. Il certificato è locale e il browser avvisa. Gli utenti di prova e le
loro password li stampa `avvia` alla fine, e stanno in
`~/.local/state/pantedu-vps-locale/segreti.env`: valgono solo lì.

Che cosa riproduce, misurato sul VPS il 14 settembre 2026 (l'elenco completo è
in cima allo script):

- **MariaDB 11.8.6** con lo stesso `sql_mode`, raggiunta attraverso un socket
  come sul server, e con i **tre utenti** `pantedu_app`, `pantedu_migrator` e
  `pantedu_maint` con i permessi di produzione e i trigger append-only. Le
  concessioni degli ultimi due si leggono dagli script di `tools/security`,
  non se ne tiene una copia;
- il **servizio TeX** (`tools/tex-compile-vps/Dockerfile`), con gli stessi
  pacchetti Debian di `provision.sh`: i controlli compilano un PDF vero
  attraverso l'applicazione. **Con un'altra topologia**, però: qui il TeX è un
  container con nome sulla stessa rete dell'applicazione, che lo chiama per
  nome; in produzione è un'unità systemd sull'host, raggiunta sul gateway del
  bridge Docker con una regola del firewall
  ([`tex-dal-container.md`](../ops/tex-dal-container.md)). Quindi **un verde
  qui non prova l'indirizzo di produzione**: dall'8 al 19 settembre 2026 il
  container vero non raggiungeva il TeX, e qui tutto passava. Lo guardano
  `/health/tex` e il controllo `tex` della diagnostica, sul VPS;
- il container dell'applicazione con **le opzioni di `deploy-container.sh`**:
  memoria, processi, capacità tolte, porte 8090 e 8091;
- **le migrazioni fatte dal rilascio**, prima del container nuovo;
- **i lavori a orario** delle compilazioni e dei temporanei.

Che cosa **non** c'è: Cloudflare davanti (e quindi il paese del visitatore), la
posta, Grafana, il webhook, i salvataggi, AIDE e auditd.

Prima di costruire, `avvia` e `ricostruisci` dicono **che cosa si sta
provando**: il ramo, i commit avanti e indietro rispetto a `main`, le modifiche
non committate (che finiscono nell'immagine anche se `/version` dice il
commit), le migrazioni che il ramo porta e `main` no, e i cambi a `docker/`,
`infra/nginx` o al servizio TeX. Il vhost di `infra/nginx` in produzione non
arriva da solo con il rilascio; del servizio TeX il rilascio porta il codice
(`tools/tex-compile-vps/app`, dal 19/9/2026), non l'unità systemd né i
pacchetti.

La prima volta ci vuole: il 14/9/2026 l'immagine del servizio TeX ha
richiesto sei minuti (quattro solo per scaricare e installare TeX Live) e occupa
6 GB; quella dell'applicazione diciotto secondi con la cache calda. Poi la
cache regge, e `ricostruisci` ha preso nove secondi di costruzione.

`ricostruisci` fa lo scambio del rilascio vero: il container nuovo parte
accanto al vecchio, si dichiara sano, nginx passa a lui con un reload, e solo
allora il vecchio si ferma. Misurato lo stesso giorno con una richiesta al
secondo a `/health` per tutto il giro: 28 su 28 con risposta 200.

Due cose da sapere:

- **le porte 8443, 8090 e 8091**: se le usa qualcun altro, `avvia` si ferma e
  dice chi, invece di credere di essere già acceso;
- **`pulisci` non tocca la cache di costruzione di Docker**, che usano anche i
  runner della CI. L'immagine del servizio TeX resta, a meno di `--tutto`.

## Portare qui quello che c'era su Windows + XAMPP

Fatto una volta, il 10 settembre 2026. XAMPP non c'è più, e la copia su
Windows è in dismissione dal 22/9/2026: eliminazione decisa, la fa l'utente. La
procedura resta come riferimento, non si ripete.

Da PowerShell, il database di sviluppo. `--result-file` scrive direttamente il
file senza passare dalla console di Windows, che deforma i caratteri non ASCII:

```powershell
& C:\xampp\mysql\bin\mysqldump.exe --user=root --default-character-set=utf8mb4 --single-transaction --routines --triggers --events --hex-blob --result-file=C:\temp\pantedu_dev.sql pantedu_dev
```

Poi in WSL:

```bash
bash tools/dev/wsl/database.sh --importa /mnt/c/temp/pantedu_dev.sql
bash tools/dev/wsl/prepara.sh --dati-da /mnt/c/<percorso-del-repository-windows>/storage
cp /mnt/c/<percorso-del-repository-windows>/.env.local ~/pantedu/.env.local
chmod 600 ~/pantedu/.env.local
```

e in `.env.local` si aggiungono le righe della sezione «Il database». Il 10
settembre: 483 MB di database in 17 secondi, i dati d'istanza (6229 oggetti,
385 verifiche e 81 mappe cifrate) in 31.

La copia su Windows è rimasta come riserva fino al 22 settembre 2026. Quel
giorno un inventario ha misurato che di commit, dati d'istanza e `.env.local`
non conteneva niente che non fosse già qui o su un remoto, e si è deciso di
eliminarla. Restano lì alcuni file non versionati, su cui decide l'utente.
Quello che serviva ancora da Windows sta in «Che cosa si fa ancora da
Windows».

## Lanciare comandi dentro WSL da Windows

Quando i comandi partono da Windows — da Git Bash, da PowerShell, o da uno
strumento che li lancia al posto tuo — il modo che regge è **passare lo script
su stdin**:

```bash
MSYS_NO_PATHCONV=1 wsl.exe -d Ubuntu bash -s <<'EOF'
cd ~/pantedu || exit 1
git status --short
echo "ramo: $(git rev-parse --abbrev-ref HEAD)"
EOF
```

Oppure scrivere lo script in un file e lanciare `wsl.exe -d Ubuntu bash
/mnt/c/percorso/script.sh`.

**Perché non `wsl.exe -d Ubuntu -e bash -lc "cd ~/pantedu && …"`.** La stringa
passa per due shell: quella di Windows la interpreta prima di consegnarla a
bash. `$(…)`, le parentesi e le virgolette doppie vengono toccate due volte, e
il comando muore con `syntax error near unexpected token '('` — oppure, peggio,
gira con un pezzo mancante. Successo tre volte il 12 settembre 2026. Con
`<<'EOF'` fra apici singoli Windows non tocca niente, e bash riceve lo script
com'è scritto.

Due dettagli dello stesso genere:

- **`MSYS_NO_PATHCONV=1`**, da Git Bash: senza, un argomento che somiglia a un
  percorso Unix (`/mnt/c/…`, `/home/…`) viene riscritto come percorso Windows
  prima di arrivare a `wsl.exe` — `/mnt/c/x.sh` diventa
  `C:/Program Files/Git/mnt/c/x.sh`, che non esiste;
- **da PowerShell 5.1** le virgolette doppie dentro gli argomenti passati a un
  programma esterno vengono storpiate: stesso rimedio, lo script su file.

E una regola che vale sempre: **un comando che non stampa niente non è un
comando riuscito.** Uno script che non parte proprio non stampa nemmeno le sue
righe `echo`; si guarda l'esito (`echo "esito: $?"`), non il silenzio.

## Che cosa si fa ancora da Windows

Il repository si usa solo qui. La copia su Windows è in dismissione dal
22/9/2026: eliminazione decisa, la fa l'utente. Alcune cose però passano ancora
da Windows, perché gli strumenti stanno lì. Sono queste, misurate il 22
settembre 2026 da una sessione di Claude Code che gira dentro WSL.

**Le sessioni di Claude Code girano dentro WSL.** L'app desktop apre la
sessione dentro Linux: shell `bash`, cartella di lavoro `/home/<utente>/pantedu`
(`pwd` la mostra, e `WSL_DISTRO_NAME` è impostata). Da qui:

- lo **stato del repository** che l'app mostra all'inizio della sessione viene
  dal git di Linux, ed è affidabile: il 22/9 diceva lo stesso ramo e lo stesso
  albero pulito di `git status`;
- `CLAUDE.md`, le skill del progetto e la memoria delle sessioni si caricano.
  La memoria sta dentro Linux, in
  `/home/<utente>/.claude/projects/-home-<utente>-pantedu/memory`: il nome
  della cartella viene dal percorso di lavoro;
- gli strumenti Write ed Edit scrivono sul filesystem di Linux e **conservano il
  bit di esecuzione** (misurato: `-rwxr-xr-x` prima e dopo una modifica). La
  trappola dei permessi più sotto riguarda solo chi scrive da Windows;
- l'**hook che rigenera `docs/ROUTES.md`** prende il ramo Linux e usa il php di
  WSL. Misurato con una rotta finta aggiunta a `routes/web.php`: è comparsa in
  `ROUTES.md`; tolta la rotta, `ROUTES.md` è tornato identico. La prova dei
  due rami è `tests/ops/rigenera-routes.test.sh`;
- i programmi di Windows si lanciano da qui attraverso l'interoperabilità di
  WSL: `gh.exe`, `powershell.exe`, il `bash.exe` di Git, `python.exe`. Partono
  con cartella corrente `\\wsl.localhost\Ubuntu\home\<utente>\pantedu`, quindi i
  **percorsi relativi** funzionano. `cmd.exe` invece non accetta una cartella
  UNC e ripiega su `C:\Windows`.

Una sessione la cui cartella di lavoro non è `/home/<utente>/pantedu` né una
sua copia di lavoro (compare in `git -C ~/pantedu worktree list`) si ferma e
chiede (`CLAUDE.md`, controllo di inizio sessione). I due casi:

- **aperta da Windows sulla cartella
  `\\wsl.localhost\Ubuntu\home\<utente>\pantedu`** (non su quella che la
  contiene: da lì non si caricano `CLAUDE.md` né le skill). Cambiano quattro
  cose: lo stato che l'app mostra viene dal git di Windows, che su quella
  cartella non funziona (paragrafo qui sotto); l'hook prende il ramo UNC e
  lavora dentro WSL con `wsl.exe`; Write ed Edit tolgono il bit di esecuzione;
  la memoria non è quella delle sessioni in WSL, ma sta su Windows, in
  `C:\Users\<utente>\.claude\projects\`, in una cartella con il nome
  ricavato dal percorso di lavoro;
- **aperta sulla vecchia copia su Windows**: è sulla copia sbagliata, e legge
  un `CLAUDE.md` e una memoria vecchi. Quel `CLAUDE.md` è quello del 16/9/2026
  (#141): non ha la regola sulla cartella di lavoro né quelle della #231. La
  sessione non si ferma da sola, se ne deve accorgere chi la apre.

**git: solo quello di WSL.** Sulla cartella
`\\wsl.localhost\Ubuntu\home\<utente>\pantedu` il git di Windows si ferma con
«dubious ownership» (esito 128). **Non si aggiunge `safe.directory` alla sua
configurazione** per farlo passare: forzato, vede circa centocinquanta file
«modificati» che nessuno ha toccato — cambi di modo `100755 → 100644`, perché da
Windows il bit di esecuzione non si vede — e un commit fatto da lì toglierebbe
l'eseguibile a quegli script. Rami, commit, spinte e stato si fanno da WSL,
anche quando il comando parte da Windows («Lanciare comandi dentro WSL da
Windows»). L'unica eccezione è una lettura, con l'eccezione passata al solo
comando: `git -c 'safe.directory=*' rev-parse HEAD` (così fa
`tools/admin/cold_backup.ps1`).

**GitHub CLI.** In WSL `gh` non è installato. Si usa quello di Windows,
`gh.exe`, **sempre con il repository esplicito**: dalla cartella non lo può
ricavare, perché per farlo chiama il git di Windows, che si ferma su «dubious
ownership» (esito 1).

```bash
cd ~/pantedu && gh.exe -R vittop89/pantedu-dev pr list --limit 5 </dev/null
```

Il corpo di una pull request si passa con un file. Come lo trova `gh.exe`,
misurato il 22/9:

- un percorso **relativo** alla cartella corrente: sì;
- un percorso assoluto di Linux (`/tmp/…`, `/home/…`): sì, ma **solo se la
  cartella corrente sta in WSL** — Windows lo risolve sulla condivisione
  `\\wsl.localhost\Ubuntu` della cartella corrente; da `/mnt/c/…` risponde
  «The system cannot find the path specified»;
- un percorso `/mnt/c/…`: **no** («Access is denied»);
- un percorso tradotto con `wslpath -w`: sì, sempre. È la forma da preferire.

**I PDF dei documenti legali e del pacchetto per il DPO.**
`tools/legal/build_pdf.sh` usa pandoc e lo xelatex di MiKTeX,
`docs/dpo/pacchetto-scuola/_gen_pdf.py` Python ed Edge: stanno su Windows. In
WSL xelatex c'è, ma pandoc, Edge e i font no (Calibri e Consolas per i
documenti legali, Segoe UI e Consolas per il Pacchetto). Lanciati col bash e il
python di WSL, i due script si fermano senza toccare file: `build_pdf.sh`
riconosce WSL da `WSL_DISTRO_NAME`, rimanda qui ed esce con 3; `_gen_pdf.py`
controlla per prima cosa che Edge ci sia (senza, rimanda qui ed esce con 3),
fa stampare Edge su un file temporaneo accanto al PDF e lo sostituisce solo se
ne è uscito uno valido (comincia con `%PDF`, ha `%%EOF` in coda). Questo
comportamento lo provano, in CI, `tests/ops/build-pdf-wsl.test.sh` e
`tests/ops/gen-pdf-pacchetto.test.sh`.

I due script si lanciano con gli interpreti di Windows, **da una sessione in
WSL** con la cartella sul repository e **percorsi relativi**. Il bash di Git e
`python.exe` non vedono `WSL_DISTRO_NAME`, perché `WSLENV` è vuota (misurato
il 22/9):

```bash
cd ~/pantedu && "/mnt/c/Program Files/Git/bin/bash.exe" -lc 'bash tools/legal/build_pdf.sh docs/privacy/dpia.md' </dev/null
```

```bash
cd ~/pantedu && python.exe docs/dpo/pacchetto-scuola/_gen_pdf.py docs/dpo/pacchetto-scuola/Pacchetto-DPO-pantedu.md </dev/null
```

Misurato il 22/9 su `aup.md` (Calibri e Consolas incorporati) e sul Pacchetto
(Segoe UI e Consolas): stesso testo e stesse pagine del PDF versionato, nessun
cambio di modo. Da Git Bash aperto su Windows vale lo stesso, dopo
`cd //wsl.localhost/Ubuntu/home/<utente>/pantedu`. Con un percorso UNC
**assoluto** pandoc lo prende invece per un indirizzo web.

In tutti i casi: il PDF **si legge dentro** (`pdftotext`, `pdffonts`) prima di
crederci — l'«ok» dello script dice che un file è uscito, non che cosa
contiene —, e il commit si fa da WSL, dove `git status` deve mostrare i soli
PDF, senza cambi di modo. Che i PDF non restino indietro rispetto ai sorgenti
lo controlla `tools/ci/check-pdf-aggiornati.mjs` (`npm run legal:pdf`), che dal
23/9/2026 gira anche sulle pull request: un sorgente toccato senza rigenerare
il PDF fa rosso il lavoro «Front-end».

**L'SSH al VPS.** Da WSL `ssh pantedu-tunnel` non si risolve («Could not
resolve hostname», esito 255): in WSL non ci sono né la configurazione di
`pantedu-tunnel` né l'agente con la chiave, e nemmeno `cloudflared`. Stanno su
Windows, e dalla sessione si usa il suo client:

```bash
/mnt/c/Windows/System32/OpenSSH/ssh.exe -n -o BatchMode=yes -o ConnectTimeout=40 pantedu-tunnel 'echo raggiunto' </dev/null
```

Misurato il 22/9: esito 0. Il tunnel e il client su Windows stanno in
`docs/ops/ssh-cloudflare-tunnel.md`; che cosa vuol dire se il banner del server
non compare, in `docs/ops/runbook-sito-irraggiungibile.md`. Se il banner
compare e poi l'accesso è negato, con questo client il colpevole è l'agente di
Windows, non quello di Git Bash di cui parla il runbook: deve elencare la
chiave (misurato il 23/9: esito 0, una chiave).

```bash
/mnt/c/Windows/System32/OpenSSH/ssh-add.exe -l </dev/null
```

**I comandi da dare a chi lavora:** la regola sta in `CLAUDE.md`. Da
PowerShell, un comando singolo arriva al repository con
`wsl -d Ubuntu --cd ~/pantedu -e <comando>` (misurato con `git rev-parse`); per
qualcosa di più lungo vale la sezione «Lanciare comandi dentro WSL da Windows».

**Il collegamento «Postazione»** sul Desktop punta a
`\\wsl.localhost\Ubuntu\home\<utente>\pantedu\tools\postazione\postazione.cmd`,
con cartella di lavoro la home di Windows: `cmd.exe` non accetta una cartella
UNC come cartella corrente, e lo script trova `postazione.ps1` accanto a sé.

## Le trappole, tutte trovate provando

- **Una copia rsync con `--exclude 'vendor/'` si porta via anche
  `public/vendor/`**, che è versionata: senza la barra iniziale lo schema vale
  a qualunque profondità. 342 prove rosse per un foglio di stile servito come
  404. Le esclusioni si scrivono `/vendor/`.
- **Modificare i file di WSL da Windows attraverso `\\wsl.localhost\…` toglie il
  bit eseguibile**: il file viene riscritto coi permessi predefiniti. Dopo aver
  toccato uno script, `chmod +x`, e prima di committare
  `git diff --summary | grep 'mode change'`. La CI ha una guardia che lo prende.
- **`pkill -f <schema>` dentro `bash -lc "…"` uccide la shell stessa**, perché
  la sua riga di comando contiene lo schema. I processi si fermano col file
  PID (lo fa `server.sh --ferma`).
- **«Risponde qualcuno» non vuol dire «sono io».** La prima versione di
  `server.sh` si è fatta ingannare da un altro server sulla stessa porta, e
  quattro controlli dopo sono passati misurando quello.
- **puppeteer con una cache vuota** lasciata da un tentativo interrotto rifiuta
  di riscaricare il browser («the executable is missing»): si toglie
  `~/.cache/puppeteer` e si rilancia (`prepara.sh` lo fa da solo).
- **La semina della CI vuole le credenziali prima**: `seed_e2e_database.php`
  senza `E2E_TEACHER_PASS` e compagne non crea gli utenti e lo dice in una riga
  sola.
- **In una copia di lavoro `vendor/` si copia, non si collega.** Con
  `ln -s ~/pantedu/vendor vendor`, l'autoload di composer calcola la radice dal
  percorso vero di `vendor/` e carica `App\` da `~/pantedu/app`: PHPUnit prova
  il codice di `main`, non quello del ramo. Misurato il 24 settembre 2026: una
  prova nuova falliva «con la correzione» perché la correzione non la vedeva.
  Le prove che leggono file per percorso non se ne accorgono, quelle che
  caricano classi di `app/` sì. Una volta per copia:

  ```bash
  cp -a ~/pantedu/vendor vendor && composer dump-autoload --no-plugins
  grep "'App" vendor/composer/autoload_psr4.php    # $baseDir . '/app', con vendor/ non collegato
  ```

  Mai `dump-autoload` su un `vendor/` collegato: riscriverebbe quello di
  `~/pantedu`. `.env.local` e `node_modules` invece si collegano.
- **Una copia di lavoro nuova (`git worktree`) non ha l'editor drawio**, che non
  è versionato (`public/drawio-app/`, 145 MB, lo installa
  `tools/install-drawio.sh` e nell'immagine ci pensa il Dockerfile). Senza,
  l'editor non si apre e le sei prove `drawio_native` di
  `tests/e2e/area-docente/creazione-e-modifica-per-sidepage.spec.js` falliscono
  **solo lì**: misurato il 20 settembre 2026 su tre rami diversi, tutti e tre
  con le stesse sei rosse, e verdi appena collegato l'editor. Si evita così,
  una volta per copia:

  ```bash
  ln -sfn ~/pantedu/public/drawio-app public/drawio-app
  ```

  Come `node_modules` e `.env.local`, che si collegano allo stesso modo, e
  `composer install --no-plugins` per `vendor/`. Il foglio di stile va rifatto
  in ogni copia (`php tools/build-css-bundle.php`): `npm run build` non lo
  tocca.

- **Un processo che finisce la memoria fa cadere tutta Ubuntu**, non solo sé
  stesso. Misurato il 23 settembre 2026 dal giornale di systemd: un `php` di
  una prova arrivato a 14 GB (la VM ne ha 15,5) è stato ucciso dal kernel, e
  subito dopo systemd ha fermato l'intero `init.scope`, dove stanno anche
  l'`init` di WSL, il ponte verso Windows e le sessioni di Claude Code: il
  gruppo ha `OOMPolicy=stop` (`systemctl show init.scope -p OOMPolicy`).
  L'app ha mostrato «Riconnessione a Ubuntu» e ha riavviato la distribuzione
  al risveglio del PC; `/tmp` era vuoto, e Docker Desktop si è ricollegato da
  solo dopo qualche minuto. La VM invece non era ripartita: `uptime` conta
  dalla VM, il PID 1 della distribuzione dal riavvio. Un comando che può
  crescere senza freno (mutazioni, input XML costruiti apposta: la memoria
  di libxml `memory_limit` di PHP non la conta) si lancia in un gruppo suo,
  con un tetto del kernel; se lo supera muore solo lui, con esito 137:

  ```bash
  systemd-run --user --scope --quiet -p MemoryMax=4G -p MemorySwapMax=0 -- php vendor/phpunit/phpunit/phpunit tests/Unit
  ```

  Provato nei due versi: sotto il tetto il comando gira, sopra muore da solo
  e Ubuntu resta in piedi. Senza `%USERPROFILE%\.wslconfig` la VM prende metà
  della RAM del PC; dal 23/9 quel file dà a WSL 20 GB su 31, 8 di swap e
  `autoMemoryReclaim=gradual`, e vale dal primo avvio della VM dopo (un
  `wsl --shutdown` chiude anche le sessioni aperte). Più memoria dà margine ai
  lavori normali, non ferma un processo fuori controllo: quello lo ferma il
  tetto per comando. La cura di sistema (`DefaultOOMPolicy=continue` in
  `/etc/systemd/system.conf`, o un tetto su `init.scope`) cambia la
  configurazione della distribuzione, e la decide chi la usa.

## Cosa non cambia

Il repository pubblico, il rilascio, i runner della CI (già in WSL dall'8
settembre, vedi `docs/ops/runner-self-hosted.md`), e le regole di
`wiki/dev-workflow.md`. Cambia il posto dove si lavora.
