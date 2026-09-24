# Prerequisiti per eseguire la suite end-to-end

Questo documento elenca che cosa serve a una macchina per far girare
`npx playwright test` su Pantedu, e quanto ci mette. Serve a chi prepara un
computer nuovo e a chi volesse mettere la suite su un runner che la esegue di
notte.

La suite non gira su GitHub Actions: parla con un'installazione vera —
database, PHP, LaTeX — e nessuno di quei pezzi è disponibile su un runner
ospitato. Le due strade praticabili sono il computer di chi sviluppa e un
runner self-hosted preparato come qui sotto.

## Software

| Cosa | Versione | Perché serve |
|------|----------|--------------|
| PHP | 8.3 (8.2 basta) | l'applicazione |
| MariaDB | 10.11 (MariaDB, non MySQL) | il database dell'applicazione: in sviluppo il contenitore `pantedu-mariadb-dev` |
| Server dell'applicazione | `bash tools/dev/wsl/server.sh` | il server integrato di PHP col router della CI, su `http://127.0.0.1:8765` |
| Node.js | 20 | Playwright e gli strumenti della suite |
| TeX Live | con `pdflatex` nel PATH | le spec marcate `@pdflatex` compilano davvero i pacchetti |
| Servizio TeX locale | `node tools/dev/tex-service-local.mjs` | le spec marcate `@tex` chiedono all'applicazione di compilare: senza, l'applicazione risponde 503 |

Dal 10 settembre 2026 la configurazione di riferimento è **WSL**, non più
Windows + XAMPP: come si monta sta in `docs/dev/sviluppo-in-wsl.md`. Se il
contenitore del database o il server sono giù, la suite fallisce tutta insieme
con errori che sembrano bug dell'applicazione: prima di indagare su un giro
rosso, controllare che rispondano (`docker ps`, `curl
http://127.0.0.1:8765/.well-known/security.txt`).

## Dati

Il database dev'essere popolato con il dump di sviluppo. La suite non crea
utenti né istituti: si aspetta di trovarli, e crea solo i contenuti che le
servono, cancellandoli alla fine.

Servono tre utenti, con i ruoli che il nome dice:

- un docente;
- un secondo docente, senza poteri di amministrazione (serve a tutti i test di
  isolamento: senza, non si può verificare che le cose di uno non si vedano
  dall'altro);
- un amministratore, che porta anche il flag `users.is_super_admin`: senza,
  le rotte di amministrazione delle risorse rispondono 403 (il ruolo e il flag
  sono due cose diverse, vedi `Auth::role()`).

Il primo docente dev'essere collegato ad **almeno due istituti**, altrimenti il
test che verifica che le classi non si sommino fra istituti non ha niente da
confrontare.

L'Istituto dev'essere popolato con i modelli TeX: senza, l'area delle risorse
docente non ha niente da provare.

## Chiavi in `.env.local`

Il file non è versionato. La suite ne legge queste:

| Chiave | A che serve |
|--------|-------------|
| `E2E_TEACHER_USER`, `E2E_TEACHER_PASS` | credenziali del docente |
| `E2E_TEACHER2_USER`, `E2E_TEACHER2_PASS` | credenziali del secondo docente (se assente la seconda, si riusa quella del primo) |
| `FM_E2E_ADMIN_USERNAME`, `FM_E2E_ADMIN_PASSWORD` | credenziali dell'amministratore |
| `TEX_COMPILE_ENDPOINT`, `TEX_COMPILE_SECRET` | il servizio TeX locale |
| `METRICS_BEARER_TOKEN` | l'esposizione Prometheus, che le spec di osservabilità leggono |

Una la legge l'applicazione, non la suite: `RATE_LIMIT_DISABLED=1`, senza la
quale le spec ricevono 429 a catena ([dove vale 1](../../wiki/environment-variables.md)).

`FM_E2E_BASE_URL` cambia l'indirizzo dell'applicazione (di suo
`http://127.0.0.1:8765`, il server di sviluppo in WSL; in CI `127.0.0.1:8000`).

Le password non compaiono mai nelle spec: le legge l'infrastruttura in
`tests/e2e/support/`, che non le restituisce a chi chiama. Un commento in linea
dopo il valore va bene — il caricatore lo taglia, come fa la libreria che legge
lo stesso file dal lato dell'applicazione.

## Come si esegue

```bash
npx playwright test
```

Un'area sola:

```bash
npx playwright test tests/e2e/verifiche
```

Un caso solo, per nome:

```bash
npx playwright test --grep "il pacchetto si scarica"
```

Le spec che compilano LaTeX sono marcate nel titolo e si possono saltare:

```bash
npx playwright test --grep-invert "@tex|@pdflatex"
```

## Un worker solo

`playwright.config.js` dichiara `workers: 1`, e non è una limitazione da
togliere: è una conseguenza dell'ambiente. C'è un solo database e ci sono tre
utenti condivisi; due file che girano insieme si sovrascrivono i contenuti e i
permessi a vicenda. Con più worker la suite diventa rossa a caso.

Per la stessa ragione non ha senso dividere la suite in blocchi paralleli su
più macchine: servirebbero tanti database e tante terne di utenti quante sono
le macchine.

## Quanto ci mette

In WSL, col repository dentro ext4, i 582 test senza LaTeX durano **6,3
minuti** (misurato il 10 settembre 2026), confronto a pixel compreso. Sulla
vecchia configurazione — Windows 11, XAMPP, MiKTeX — lo stesso giro chiedeva
12,7 minuti e il confronto a pixel si saltava. Le spec che compilano LaTeX per
davvero restano la parte più lenta.

Un runner che la esegua di notte dovrebbe prevedere mezz'ora, per avere
margine su una macchina più lenta o con la cache del servizio TeX fredda.

## In integrazione continua

Dal 7 settembre 2026 la suite gira anche in CI, su un database seminato da
zero: `.github/workflows/e2e.yml` mette in fila MariaDB, `database/schema.sql`,
`php tools/migrate.php`, `tools/ci/seed_e2e_database.php`, l'accettazione dei
Termini, il build, `php -S` con più worker e Playwright. Di notte alle 2, o a
mano.

Il seme crea il minimo che la suite si aspetta di trovare — due istituti con
classi diverse, tre utenti con i loro ruoli, le voci di curriculum, sedici
modelli dell'Istituto con i sorgenti su disco — e le password se le genera a
ogni giro: il database vive quanto il job, non c'è niente da tenere segreto.
Lo stesso seme è il modo più corto per ricreare l'ambiente da zero anche
altrove.

Restano fuori, per ragioni d'ambiente e non di codice:

- le spec `@tex`, che parlano col microservizio TeX, e le `@pdflatex`, che
  invocano `pdflatex` sul runner: `--grep-invert "@tex|@pdflatex"`;
- niente: dall'8 settembre 2026 il confronto delle immagini gira **qui e solo
  qui**. Prima c'era `--ignore-snapshots`, che non salta la prova ma ne butta
  via il risultato: cinquantanove test verdi che non guardavano niente. Ora le
  immagini attese sono generate su questa piattaforma, contro il database
  seminato da zero — quindi deterministico — e versionate come
  `-chromium-linux.png`. Sulla macchina di sviluppo la spec si salta dicendo
  perché: lì il database è quello su cui si lavora, e le pagine riservate non
  sarebbero mai uguali due volte.

  Per rigenerarle: workflow «E2E dopo l'unione e di notte», lanciato a mano con
  la spunta `aggiorna_immagini`; poi si scarica l'artefatto
  `immagini-attese-linux` e se ne versiona il contenuto. Per confrontare in
  locale, quando serve davvero (una pulizia dei fogli di stile):
  `FM_E2E_VISUAL=1` e, la prima volta, `--update-snapshots` — quella serie è
  personale e non va versionata.

Due cose che l'ambiente di prova deve avere, e che in produzione stanno
giustamente al contrario: `SESSION_COOKIE_SECURE=false`, perché il server di
prova parla http e altrimenti il browser butta via il cookie e ogni richiesta
arriva anonima; e `PANTEDU_DATA_PATH` valorizzata, perché vuota fa scrivere
l'applicazione in `/storage/…`, cioè nella radice del filesystem.

### Dove arriva oggi

Il 7 settembre 2026, primo giorno in cui la suite gira in CI: **552 test su
557 in 12,7 minuti**, contro 592 su 592 in locale (i tre `@istanza` restano
fuori da entrambi i conteggi in CI).

La progressione dei tentativi dice quanto costa ogni pezzo mancante
dell'ambiente, ed è utile a chiunque debba rifare un'installazione da zero,
perché gli ostacoli arrivano nello stesso ordine:

| Verdi | Che cosa mancava |
|------:|------------------|
| 301 | (primo giro) |
| 368 | il cookie di sessione era `Secure` e il server di prova parla http |
| 499 | `PANTEDU_DATA_PATH` e i modelli dell'Istituto |
| 522 | il flag `users.is_super_admin` |
| 533 | lo schema dei campi dei modelli |
| 552 | il router per `php -S`, le immagini della radice, il catalogo degli esercizi, le fonti, la biblioteca TikZ, le cinque classi |

Lungo la strada sono venuti fuori **cinque difetti veri dell'applicazione**,
tutti sul percorso «installazione da zero» e invisibili sul portatile: il
migratore che non arriva in fondo (voce 68), la sintassi MariaDB non
dichiarata (69), l'elenco delle fonti sempre vuoto per un metodo che non
esiste più nella classe che lo chiama (70), una chiave vuota di `.env` che
batte il valore predefinito (71) e un collegamento distinto solo dal colore
nel pannello del WAF spento (72).

I cinque rossi che restano, con nome e cognome:

- `verifiche/cancellazione-dal-pannello` e
  `verifiche/informazioni-di-stampa-dalla-pagina`: **il pannello non si
  ridisegna dopo una scrittura** su un server più lento (voce 73 del debito).
  Il server fa la cosa giusta — la verifica è cancellata, il dato è salvato —
  ma la riga resta e la scheda mostra il valore vecchio;
- `studio/punteggi-vero-falso`, `studio/pagine-di-studio` (l'indirizzo vecchio
  della classe) e `risdoc/documento-personalizzabile` (il corpo scritto dal
  server): passano in locale, anche nell'ambiente della CI ricreato sul
  portatile, e ballano sul runner, dove tutto è più lento. Vanno guardati con
  calma: o sono attese che tengono per un pelo, o sono corse vere.

Altri tre sono marcati `@istanza` ed esclusi di proposito: verificano il
contenuto dei modelli e degli esercizi di questa installazione, e su un
database seminato da zero quel contenuto non c'è.

Per questo la suite in CI **non è un cancello**: gira di notte e si guarda. I
due controlli obbligatori che bloccano il merge sono altri
(`wiki/dev-workflow.md`).

### Rifare questo ambiente sul proprio computer

Serve quando un rosso non si riproduce in locale, e costa dieci minuti:

```bash
cd ~/pantedu && git worktree add --detach ../pantedu-ci HEAD
```

Dentro la copia: `vendor/` e `node_modules/` collegati con `ln -s` a quelli
veri, `.env` copiato da `.env.example` con le stesse sostituzioni del
workflow, un database usa-e-getta, schema, migrazioni, seme, e
`php -S 127.0.0.1:8099 -t public/ tools/ci/router-php-server.php` — una porta
diversa dall'8765 dello sviluppo. (Fino al 22 settembre 2026 questa procedura
era scritta per PowerShell, con la copia accanto alla vecchia cartella di
Windows: vedi `docs/dev/sviluppo-in-wsl.md`.) Poi
Playwright si lancia **dalla copia**, con `FM_E2E_BASE_URL` e le credenziali
nell'ambiente. Il giro di prova diventa di trenta secondi invece che di un
quarto d'ora, ed è così che sono stati trovati i difetti qui sopra.

Accanto girano i controlli che non hanno bisogno di un browser: ESLint,
PHPStan, PHPCS, PHPUnit (suite `unit`, nei due `DEPLOYMENT_MODE`), vitest, i
tipi del supporto E2E, il build con il budget del pacchetto, le quattro guardie
di progetto, l'audit delle dipendenze e il secret scan. Sette di questi sono
obbligatori per unire su `main` (vedi `wiki/dev-workflow.md`); la suite E2E no,
perché un quarto d'ora è troppo per ogni pull request.

## Che cosa lascia dietro

Niente, se il giro finisce. Le factory registrano la cancellazione di quel che
creano, e la pulizia gira anche quando il test fallisce.

Se il giro viene interrotto a metà — il computer si spegne, si preme Ctrl+C —
restano i contenuti creati fino a quel momento. Si ripuliscono con:

```bash
php tools/dev/e2e_cleanup_residues.php
```

che elenca e non tocca niente; con `--apply` cancella. Riconosce i contenuti
dai prefissi che le factory usano, lavora su un solo docente e passa dai
servizi di dominio, così spariscono anche i file cifrati.

## Se il giro è rosso

Nell'ordine:

1. **Apache e MySQL rispondono?** Se sono giù, fallisce tutto insieme.
2. **Il servizio TeX locale gira?** Se no, falliscono le spec `@tex` e nessun'altra.
3. **`pdflatex` è nel PATH?** Se no, le spec `@pdflatex` falliscono con un
   messaggio che lo dice — non con l'errore di un processo che non parte.
4. **Il database si è riempito di residui?** Con centinaia di verifiche di
   prova, alcune pagine vanno in timeout. Vedi sopra.
5. Solo dopo, guardare la traccia del fallimento: `tests/e2e-results/` contiene
   traccia, schermata, video e il rapporto della diagnostica (errori JavaScript,
   errori di console, risposte 4xx e 5xx) di ogni test fallito.
