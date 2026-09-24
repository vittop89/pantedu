---
tags:
  - documentazione/workflow
date: 2026-09-23
tipo: workflow
status: finale
aliases: ["dev-workflow", "setup", "sviluppo"]
cssclasses: []
---

# Dev Workflow

## Prerequisiti

| Tool | Versione | Note |
|------|---------|------|
| PHP | 8.3 o 8.4 | estensioni (`composer.json`): zip, mbstring, curl, dom, fileinfo, iconv, openssl, pdo, pdo_mysql; `pdo_sqlite` serve alla suite (vedi Test) |
| Composer | 2.x | |
| MariaDB | 10.11 in sviluppo e in CI, 11.x in produzione | in sviluppo: il contenitore `pantedu-mariadb-dev` (`tools/dev/wsl/database.sh`) |
| Node.js | 22 (`.nvmrc`) | Vite, Vitest, Playwright, tool CI; la stessa versione nei workflow, sul runner di casa e nell'immagine |
| pdflatex | TeX Live | solo per compilare in locale ciò che in produzione compila il microservizio |
| Web server | `php -S` col router della CI in WSL (dev) · nginx + PHP-FPM nel container (CI e prod) | `tools/dev/wsl/server.sh`, `docker/nginx.conf` |

**Dal 10 settembre 2026 lo sviluppo si fa in WSL, non su Windows + XAMPP**: il
repository sta in `~/pantedu` (filesystem di Linux, mai `/mnt/c`), il database
in un contenitore, il server è quello integrato di PHP col router della CI, che
fa gli alias di nginx. Misurato sulla stessa macchina: suite end-to-end in 6,3
minuti invece di 12,7, con dentro il confronto a pixel che su Windows si
saltava. Come si monta, anche su un computer nuovo: `docs/dev/sviluppo-in-wsl.md`.

Che cosa gira in integrazione continua, quando, che cosa blocca e cosa fare
quando è rosso: `docs/dev/ci-cd.md`. Questa pagina resta il posto dove sta
scritto **come si lavora** (cancello, migrazioni, rilascio nel dettaglio).

## Setup iniziale

```bash
composer install                      # incl. dev: phpstan, phpcs, phpunit
npm install
# .env è versionato: non si copia e non si modifica (è anche il file che il
# rilascio monta in produzione). Quello della macchina va in .env.local (mai
# versionato): APP_URL, DB_*, TEX_COMPILE_ENDPOINT, KMS_MASTER_KEY,
# STORAGE_SIGNING_SECRET, WAF_HMAC_SECRET, RESEND_API_KEY,
# TEX_COMPILE_SECRET, E2E_*. La regola: wiki/environment-variables.md.
bash tools/dev/wsl/database.sh        # contenitore MariaDB 10.11 su 127.0.0.1:3307;
                                      # crea (o riusa) il database pantedu_dev
bash tools/dev/wsl/database.sh --importa database/schema.sql   # schema iniziale
php tools/migrate.php                 # applica le migrazioni mancanti; --status, --dry-run (numero: ls database/migrations | wc -l)
php tools/generate_password_hash.php "password"   # hash per il primo super-admin (vedi docs/INSTALL.md e docs/SUPERADMIN.md)
npm run build                         # bundle Vite in public/build (il manifest attiva ViteManifest::script)
php tools/build-css-bundle.php        # css/main.bundle.css (in produzione lo fa la costruzione dell'immagine, docker/Dockerfile)
```

Il server di sviluppo: `bash tools/dev/wsl/server.sh`, su
`http://127.0.0.1:8765` (e in `.env.local` `APP_URL=http://127.0.0.1:8765`).
Niente vhost e niente `hosts`: il router `tools/ci/router-php-server.php`
risponde come il nginx del container, con un elenco di prova in comune
(`tests/ops/rotte-come-nginx.test.sh`).

Variabili utili in sviluppo: `APP_ENV=development`, `APP_DEBUG=true`,
`SESSION_COOKIE_SECURE=false`, `RATE_LIMIT_DISABLED=1` (in `.env.local`,
mai nel `.env` tracciato: [[environment-variables]]),
`AUDIT_REASON_MODE=enforce` (come in produzione, ed è il predefinito),
`SECURITY_TOTP_ENABLED` a piacere. Elenco completo in [[environment-variables]].

## Build frontend

```bash
npm run dev        # Vite HMR su :5173 (APP_VITE_DEV=1 fa usare il dev server a ViteManifest)
npm run build      # public/build/ + manifest.json
npm run preview
```

## Test

```bash
# PHPUnit: unit + integration. pdo_sqlite deve essere caricata, altrimenti i
# test SQLite si saltano in silenzio e la suite sembra verde.
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --testsuite unit
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --display-skipped   # mostra i test saltati

# I test di integrazione usano il DB `pantedu_test` (APP_ENV=testing da
# phpunit.xml), da migrare a parte:
APP_ENV=testing php tools/migrate.php

npm test                       # Vitest (tests/js-unit)
npm run e2e                    # Playwright contro http://127.0.0.1:8765, il server di sviluppo in WSL (credenziali E2E_* in .env.local)
npx playwright test tests/e2e/<spec>.spec.js --reporter=line
php tools/dev/render_page.php /login   # una pagina attraverso il router, senza browser
```

Dettagli, coverage e test sospesi in [[testing]].

## Quality gate (specchio della CI `.github/workflows/ci.yml`)

```bash
composer stan            # PHPStan livello 6 con baseline (phpstan-baseline.neon), come PHP 8.4 (phpVersion)
composer cs              # PSR-12: in CI un errore ferma il lavoro PHP, gli avvisi no
composer audit --locked
npm run lint             # ESLint, soglia degli avvisi al valore misurato (package.json): si abbassa, non si alza
npm run css:lint         # Stylelint, non in CI
npm run audit:js         # npm audit --omit=dev --audit-level=moderate
npm run build && npm run bundle:budget
npm run css:no-injection && npm run csp:no-inline-handlers
npm run db:migrations    # migrazioni distruttive senza dichiarazione
node tools/ci/check-legal-versions.mjs     # documenti legali ↔ versions.json; le tre informative dichiarano la versione
php tools/wiki/strip_code_links.php --check
npm run ci               # sequenza npm-side
```

**`npm run ci` è l'elenco delle guardie, non il giro della CI.** Nessun
workflow lo esegue: una guardia gira sulle pull request solo se un passo di
`.github/workflows/ci.yml` la nomina. Fino al 23/9/2026 `legal:pdf`,
`moduli:fascio` e `percorsi:dati` non le nominava nessuno. Da quel giorno
`tools/ci/check-workflows.mjs` (`npm run ci:workflows`) fallisce se uno script
lanciato da `npm run ci` non compare, fuori dai commenti, in nessun workflow:
chi aggiunge una guardia allo script aggiunge anche il suo passo nel lavoro
«Front-end». Prova: `tests/js-unit/workflow-guardie-del-ci.test.js`.

**Un controllo della CI può fallire.** Lo stesso script rifiuta, fuori dai
commenti, tre forme che rendevano verde un controllo fallito (23/9/2026):
`continue-on-error` diverso da `false`, su un passo o su un lavoro intero; un comando che installa, costruisce o
prova (`npm`, `npx`, `composer`, `vendor/bin/…`, `phpunit`, `playwright`…)
seguito da `||` che non esce con un errore (`npm ci || npm install`,
`npm run build || echo …`); e `|| echo` fuori da una cattura `$( … )`, tranne
nei passi di sola diagnostica (`if:` con `failure()`). Se un fallimento non
deve bloccare l'unione, lo si decide nella protezione del ramo, non nel passo.
Prova: `tests/js-unit/workflow-fallimenti-ingoiati.test.js`.

**Il Node della CI lo decide `.nvmrc`, anche sui runner di casa.** Ogni lavoro
che lancia `node`, `npm` o `npx` passa da `actions/setup-node` senza `if:` e
con `node-version-file: ".nvmrc"` (regola 8 dello stesso script, dal
23/9/2026; prova `tests/js-unit/workflow-node-da-nvmrc.test.js`): cambiare
versione è un commit, senza passi a mano sulle macchine. Il perché e la cache
degli strumenti: [`docs/ops/runner-self-hosted.md`](../docs/ops/runner-self-hosted.md).

**La baseline di PHPStan non tiene voci morte e non cresce.** Due controlli,
dal 23/9/2026. Il primo è di PHPStan: `phpstan.neon` ha
`reportUnmatchedIgnoredErrors: true`, e una voce di `phpstan-baseline.neon` che
non trova più il suo errore o un `count` più alto del vero fanno fallire
`composer stan` con «Ignored error pattern … was not matched» (o «is expected
to occur N times»); una voce il cui file è stato spostato o cancellato con
«Invalid entry in ignoreErrors: Path … is neither a directory, nor a file path»
(PHPStan stesso suggerisce di spegnere il controllo: non si fa, si toglie la
voce). Il secondo è un tetto: la somma dei `count` della baseline deve essere
uguale al numero in `tools/ci/phpstan-tetto.json`
(`LaBaselineDiPhpstanPuoSoloScendereTest`, nella suite `unit`), così un errore
nuovo messo in baseline insieme al codice che lo introduce fa fallire la prova.
Chi corregge un errore della baseline toglie la sua voce e abbassa il tetto
allo stesso totale; alzarlo è una decisione di una persona, scritta nel file.
`composer stan:baseline` riscrive la baseline da capo con gli errori di oggi,
compresi quelli nuovi: si usa solo se il diff toglie righe e non ne aggiunge.
Prima di quel giorno 66 voci su 161 erano morte, e alcune coprivano il ritorno
di difetti già corretti. Quante voci e quanti errori restano non si scrive
nelle guide, perché invecchia: si misura.

```bash
grep -c 'path:' phpstan-baseline.neon                          # voci
awk '/count:/ { s += $2 } END { print s }' phpstan-baseline.neon # errori
```

La CI gira **solo sulle pull request** verso `main`. Il giro su `push: main` è
stato tolto perché duplicava esattamente quello della pull request, ma ha una
conseguenza che vale la pena sapere: dopo un'unione **niente rifà i controlli
sul risultato**. Insieme a `strict = false` sulla protezione del ramo (vedi «Il
cancello»), vuol dire che due pull request verdi separatamente possono rompere
`main` insieme.

Il workflow a11y (`a11y.yml`) gira su PR e di notte; la suite E2E dopo ogni
unione e di notte.

## Workflow di modifica

1. Branch da `main` con nome descrittivo (`feat/`, `fix/`, `refactor/`).
2. Un intervento = un commit, Conventional Commits in italiano; suite verde
   prima del commit.
3. Se cambia `routes/web.php`: rigenera `docs/ROUTES.md`
   (`php tools/dev/gen_routes_md.php > docs/ROUTES.md`); se cambia l'API
   pubblica: `composer openapi:build && composer openapi:validate`.
4. Se cambia una migrazione: `php tools/migrate.php --dry-run`, poi
   `--status`; i prefissi numerici devono essere unici.
5. Se cambia un documento legale: alza `version:`, aggiorna
   `docs/legal/versions.json`, rigenera il PDF, `php tools/legal/sync_versions.php --apply`
   sul database locale. In produzione lo esegue il rilascio
   ([`docs/dev/ci-cd.md`](../docs/dev/ci-cd.md#il-vendor-dellhost-e-le-versioni-legali)).
6. Voce nel changelog wiki del mese (`wiki/changelog/AAAA-MM.md`, o del file della settimana se il mese è diviso: vedi [[changelog]]).
7. Se aggiungi un file che l'applicazione **legge mentre gira** e che non sta
   in `app/`, `views/`, `public/`, `routes/` — un documento in `docs/`, un JSON
   di configurazione, un modello — **ammettilo nel `.dockerignore`**. Quel file
   esclude `docs/`, `wiki/` e ogni `*.md`, e riammette a mano solo quello che
   serve (`!docs/legal`, `!docs/privacy/informativa.md`…). Un file dimenticato
   funziona in sviluppo e nella suite, e in produzione la pagina risponde 404.
   La pull request non se ne accorge: `immagine.yml`, che prova le rotte sul
   container, sulle PR parte solo se si toccano `docker/`, `.dockerignore`, i
   file delle dipendenze o il workflow stesso. È già successo con tutte le
   pagine legali all'arrivo dei container (vedi il commento in testa al
   `.dockerignore`), e di nuovo il 12 settembre 2026 con l'informativa dello
   scenario 1. Per l'informativa privacy, che è una per scenario, dal 13
   settembre 2026 c'è una rete: `docker/verifica-avvio.php` si rifiuta di far
   partire il container se manca quella di uno qualunque dei tre scenari, e
   dice quale riga del `.dockerignore` manca. Per gli altri file la regola
   resta questa riga.
8. Un ramo **già pubblicato** si aggiorna con `git merge origin/main`, non
   con `rebase`. Il rebase riscrive commit che esistono già su GitHub: serve
   una spinta forzata, la pull request perde il riferimento ai commit su cui
   c'erano i commenti, e chi ha una copia del ramo si ritrova una storia che
   non combacia più. Il rebase va bene solo su un ramo che non hai ancora
   spinto.
9. Pull request verso `main`, anche unita da soli: dal 2026-09-07 `main` è
   protetto e i due controlli obbligatori devono essere verdi prima del merge
   (vedi «Il cancello» qui sotto). **Ogni push su `main` va in produzione**
   tramite il webhook (`tools/webhook/`): dall'8 settembre 2026 il rilascio
   costruisce un container a fianco, lo controlla e scambia l'upstream di
   nginx in un istante solo (vedi «Il codice entra in servizio in un istante
   solo» qui sotto).

## Il cancello su `main`

Dal 2026-09-07 `main` ha la protezione con i controlli obbligatori. La ragione
è che il webhook deploya qualunque cosa arrivi su `main` mentre la CI gira a
fianco, senza che nessuno la aspetti: era l'unico punto della catena senza
rete, perché tutto il resto — snapshot del database prima delle migrazioni,
unità systemd che va in `failed`, comando di rollback nel log — c'è già.

Obbligatori (cinque minuti, deterministici) sono **due contesti**, non sei:
GitHub li conta per lavoro, non per passo.

| contesto obbligatorio | cosa contiene |
|---|---|
| `Front-end: lint, test, build, guardie` | ESLint, Vitest, tipi della suite E2E, guardie del progetto, build e bundle budget |
| `PHP: analisi statica e test` | PHPStan, PHPCS, PHPUnit (single) e PHPUnit (institute), le guardie all'avvio del container (`tests/ops/verifica-avvio.test.sh`, dal 23/9/2026) |

Verificato il 9 settembre 2026 con
`gh api repos/:owner/:repo/branches/main/protection`: qui erano elencati sei
punti e altrove se ne dichiaravano sette, mentre i contesti configurati sono
due. Non è una differenza di sostanza — dentro quei due lavori girano tutti i
passi elencati — ma un elenco che non corrisponde a quello che la piattaforma
applica è un elenco che prima o poi si crede al posto della piattaforma.

**Due cose di quella configurazione**, verificate lo stesso giorno:

- `enforce_admins` è **falso**: chi amministra può unire senza aspettare. È la
  via d'uscita dichiarata, e va bene finché chi amministra è uno solo e sa di
  averla;
- `strict` è **falso**: un ramo può unirsi senza essere aggiornato a `main`. E
  `ci.yml` gira **solo sulle pull request**, non su `push: main`. Messe
  insieme, le due cose vogliono dire che due pull request verdi separatamente
  possono rompere `main` insieme, e ad accorgersene sarebbero solo i giri
  notturni. Con un manutentore solo che unisce una cosa alla volta il rischio
  è piccolo, ma è reale e non lo copre niente.

**Non** obbligatori di proposito: `Security audit`, che diventa rosso quando
pubblicano una CVE su una dipendenza non toccata da nessuno — bloccherebbe
lavoro per colpa di altri; `E2E notturna`, che dura un quarto d'ora; il
workflow a11y, che gira su PR e di notte.

**Non ancora obbligatorio:** `PHP: prove d'integrazione (MariaDB)`, aggiunto
il 14 settembre 2026 (unit e integrazione su MariaDB 11.8, un salto è un
fallimento). Resta fuori dal cancello per una settimana di giri sulle pull
request: un controllo nuovo che dà falsi rossi in un cancello insegna ad
aggirare il cancello. Se in quella settimana non ha dato rossi che non fossero
guasti veri, si aggiunge come terzo contesto obbligatorio. **Lo decide
l'utente**: è una modifica alle impostazioni del repository, e la si verifica
dopo con `gh api repos/:owner/:repo/branches/main/protection`, aggiornando la
tabella qui sopra.

**La via d'emergenza è dichiarata**, non nascosta: `enforce_admins` è
disattivato, quindi come proprietario puoi unire lo stesso. Si fa per un
guasto in produzione, e si scrive nel messaggio del commit perché. Un cancello
senza uscita d'emergenza dichiarata viene aggirato di nascosto, che è peggio
che non averlo.

### Che cosa controlla il deploy, e cosa no

`tools/webhook/deploy.sh` fa già più di quanto sembri: copia il database prima
di migrare (ne tiene cinque), esce non-zero se le migrazioni falliscono — così
l'unità systemd risulta `failed` — e si aggiorna da sé, applicando la versione
nuova al deploy **successivo**.

Dal 7 settembre 2026 fa anche due cose in più:

- se la copia del database non riesce, il deploy prosegue ma **si dichiara
  fallito**: senza quella copia, una migrazione distruttiva andata male non si
  può più annullare, e non è una cosa da lasciare in un warning;
- alla fine chiede a quattro rotte pubbliche di rispondere
  (`tools/ops/smoke_after_deploy.php`). Non passa da HTTP — il WAF risponde
  403 ai client automatici, e un `curl` direbbe «rotto» a ogni giro — ma
  dispaccia il Kernel dentro un processo PHP: stessi controller, stessa
  configurazione, stesso database. Dice che il sito sta in piedi, non che
  funziona: quello lo dice la suite.

### Dall'8 settembre 2026: il codice entra in servizio in un istante solo

Fino a quel giorno il rilascio faceva `git reset --hard` sulla directory
servita: il codice nuovo cominciava a rispondere a richieste vere **mentre le
migrazioni dovevano ancora girare**, e un errore a metà lasciava l'albero
aggiornato per metà. Adesso l'applicazione gira in un container, e il rilascio
è: costruisci a fianco, controlla, scambia.

**Le tappe** (`tools/webhook/deploy-container.sh`), e cosa succede se una
fallisce:

| | passo | se fallisce |
|---|---|---|
| 1 | quale commit | niente da rilasciare, si esce |
| 2 | l'immagine (registro, o costruita sul posto) | niente cambia |
| 2-bis | il `vendor/` dell'host, se `composer.json` o `composer.lock` sono cambiati | avviso e anomalia, si prosegue; ci riprova il rilascio dopo |
| 3 | istantanea del database | non si prosegue |
| 4 | migrazioni | non si prosegue |
| 5 | container nuovo su una porta **senza traffico** | si ferma, il vecchio serve |
| 6 | si aspetta che si dichiari sano | si ferma, il vecchio serve |
| 7 | si scambia l'upstream di nginx | si rimette com'era |
| 8 | verifica **attraverso nginx** | si rimette com'era |
| 8-ter | versioni legali nel database (`sync_versions.php --apply`) | avviso e anomalia, lo scambio resta |
| 9 | via il container vecchio | si segnala, ma è già fatto |
| 10 | `main` è andato avanti nel frattempo? | si lascia il biglietto al differito |

**Fino al passo 7 la produzione non si accorge di niente.** È tutto il punto.

**Il passo 10 esiste per un guasto vero, del 9 settembre 2026.** Unite due
pull request a pochi secondi di distanza: la prima ha fatto partire il
rilascio, la seconda ha scritto il suo segnale, e non è successo niente.
L'unità `.path` di systemd **non accoda mentre il servizio gira**. Nessun
errore, nessuna riga nel registro — solo `main` a un commit e la produzione a
un altro, con `/version` che rispondeva con sicurezza quello sbagliato.

C'era già una rete, il timer `pantedu-deploy-differito`, ma partiva solo
trovando `/var/lib/pantedu-deploy/in-attesa`, e quel file lo scriveva soltanto
il trigger quando la finestra oraria era chiusa. Il percorso «rilascio già in
corso» non lo scriveva. Adesso lo scrivono tutti e due: chi trova il lucchetto
occupato, e il passo 10 se si accorge che `main` si è mosso durante il giro.

Lo scambio è una riga in `/etc/nginx/conf.d/pantedu-upstream.conf` più un
`reload`, che non chiude le connessioni in corso. Il container di prima resta
in piedi finché la verifica non è passata: tornare indietro è riscrivere quella
riga.

**Perché la verifica del passo 8 passa da nginx e non dal container.** L'8
settembre 2026, provando la struttura a release, uno scambio ha dato un sito a
404 per tre minuti: il codice era leggibile dall'utente che verificava, ma non
da `www-data`, che serve le pagine. Un controllo che non passa da chi serve
davvero non se ne sarebbe accorto. Lo stesso giorno un secondo scambio ha
lasciato il sito **in servizio senza configurazione** per dieci minuti, con
`/health/backup` a 503 come unico sintomo: per questo il passo 8 non si
accontenta di un 200, ma pretende `"db":true` da `/health`.

**Cosa resta fuori dal container**, e perché: MariaDB (non cambia mai, e
metterlo dentro vorrebbe dire migrare dati veri di docenti), i dati d'istanza
(montati), il servizio TeX (in sei mesi è cambiato 8 volte contro le 863
dell'applicazione), TLS e i limiti di frequenza — le zone `limit_req` vivono in
memoria condivisa, e se stessero nel container ogni scambio azzererebbe i
contatori.

**Il sorgente in `/var/www/pantedu` non sparisce**: gli strumenti a riga di
comando, i lavori a orario e soprattutto l'avviso di guasto devono funzionare
anche quando il container è morto.

### Che cosa può fare chi entra nel container

Il container non è la difesa contro l'intrusione — quella sta davanti: WAF,
nginx, fail2ban. È la difesa contro **cosa succede dopo**, dato per scontato
che prima o poi qualcuno esegua codice dentro il PHP.

Rivisto il 9 settembre 2026, e il pezzo peggiore era un montaggio di troppo:
`/var/lib/pantedu-deploy` era montato **scrivibile**, e systemd sorveglia il
file `trigger` dentro quella cartella per far partire il rilascio, che gira
**come root sull'host**. Il webhook che scrive quel file gira sull'host, non
qui dentro, e nessuna riga dell'applicazione leggeva quella cartella: era una
leva verso root che non serviva a niente. Tolta.

Il resto:

| | perché |
|---|---|
| `no-new-privileges` | php-fpm e nginx partono root e scaricano i figli su `www-data`. Questo non lo impedisce (è una `setuid()` di un processo già root); impedisce l'opposto — risalire da `www-data` con un binario setuid dell'immagine |
| `--pids-limit 256` | dentro ci sono sedici processi in condizioni normali: sedici volte di margine, e una fork bomb non porta giù la macchina |
| sei capacità tolte | `NET_RAW` è la più importante — socket grezzi, cioè annusare e falsificare traffico. Poi `MKNOD`, `SYS_CHROOT`, `AUDIT_WRITE`, `SETFCAP`, `SETPCAP`: strumenti da attaccante e da nessun altro |
| i due `.env` in sola lettura | erano già così |

Restano `SETUID`, `SETGID`, `CHOWN`, `DAC_OVERRIDE` e `FOWNER`: servono a
php-fpm e nginx per fare esattamente il lavoro descritto qui sopra.

**E l'utente del database.** `pantedu_app` — quello con cui il sito serve ogni
richiesta — aveva `CREATE`, `DROP` e `ALTER`: un'iniezione SQL in una pagina
qualunque avrebbe potuto cancellare tabelle. Li aveva perché il pannello delle
migrazioni eseguiva con la connessione dell'applicazione invece che con
`Database::migrationConnection()`. Spostato quello, revocati quelli.

Attenzione a una trappola scoperta togliendoli: `Migrator::pending()` comincia
con `ensureTrackingTable()`, che è un `CREATE TABLE IF NOT EXISTS`. Chi legge e
basta — `/health`, la diagnostica — deve usare `pendingSenzaCreare()`,
altrimenti `/health` risponde 503 e il passo 8 annulla ogni rilascio.

**Per passare a questo assetto** (una volta sola, su un'installazione che è
ancora a php-fpm): `tools/webhook/passa-ai-container.sh`, con `--prova` per
costruire e controllare senza toccare nginx. Le istruzioni per tornare indietro
sono scritte in testa a quello script.

**Dove si costruisce l'immagine.** In CI, e viene pubblicata su GHCR con
l'etichetta del commit (`.github/workflows/immagine.yml`): il VPS scarica gli
stessi byte che sono stati provati. Perché funzioni servono `GHCR_USER` e
`GHCR_TOKEN` in `/etc/pantedu-deploy.env`; senza, il rilascio costruisce sul
posto e lo dice — funziona lo stesso, ma il build torna a dipendere da quella
macchina e da quella rete.

Per rimuovere la protezione:

```bash
gh api -X DELETE repos/vittop89/pantedu-dev/branches/main/protection
```

## Migrazioni

```bash
php tools/migrate.php            # applica le pendenti (utente DB_MIGRATOR_* se configurato)
php tools/migrate.php --status
php tools/migrate.php --dry-run
php tools/migrate.php --cartella=DIR   # un'altra cartella: serve alle prove
```

**Quando una migrazione fallisce** lo strumento esce con 1 e scrive su stderr:
- il file e il messaggio del database;
- quali migrazioni ha applicato e registrato prima dell'errore.

Quella fallita non è registrata, e al giro dopo si riprova. Le sue istruzioni
precedenti all'errore però possono essere già state eseguite, perché MariaDB non
annulla il DDL: si guarda il database prima di rilanciare.

Fino al 14 settembre 2026 usciva con 255 senza scrivere niente. Il messaggio
stava solo in `storage/logs/php_errors.log`, dove `bootstrap.php` manda gli
errori non gestiti. Prova: `tests/Integration/MigrazioneCheFallisceTest.php`.

**«Già applicato» vuol dire che l'oggetto c'è già**: colonna, indice, tabella,
chiave primaria o esterna (1060, 1061, 1050, 1068, 1826, 121). Quelle istruzioni
si saltano, la migrazione si registra e lo strumento le elenca come «saltati»:
sono ipotesi, e `php tools/dev/check_migrations_applied.php` le verifica. **Un
conflitto di dati no**: `Duplicate entry` (1062), cioè un `ADD UNIQUE` su una
colonna con doppioni o un INSERT su una chiave già presente, fa fallire la
migrazione, che non si registra. Fino al 23 settembre 2026 si saltava anche
quello, e una chiave unica poteva mancare con il database dichiarato allineato.
Un seme che deve poter girare due volte si scrive con `INSERT IGNORE` o `ON
DUPLICATE KEY UPDATE`. Prova: `tests/Integration/MigrazioneConDoppioniTest.php`.

Un file per migrazione, `NNN_nome.sql`, statement separati da `;`
(`DELIMITER` supportato per i trigger). Il nome del file è la chiave in
`schema_migrations`: non rinominare file già applicati.

Il database di riferimento è **MariaDB 10.11+, non MySQL**: cinquantaquattro
istruzioni usano `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS`, che
MySQL non conosce (voce 69 del debito). 10.11 è la versione minima che si
prova davvero (sviluppo, E2E); la produzione gira su 11.8.

### Le viste non vedono le colonne aggiunte dopo

Diverse tabelle stanno dietro una vista (`teacher_content` sopra
`teacher_content_data`, `teacher_access_credentials` e altre). **Chi aggiunge
una colonna alla tabella deve ricreare, nella stessa migrazione, le viste che le
stanno sopra.** Altrimenti la colonna esiste nel database e l'applicazione non
la vede.

Vale anche per le viste scritte con `tc.*`. MariaDB espande l'asterisco **quando
crea la vista** e salva l'elenco delle colonne di quel momento. Misurato il 12
settembre 2026 su MariaDB 10.11:

```
CREATE VIEW v AS SELECT t.* FROM t;   -- colonne della vista: id, a
ALTER TABLE t ADD COLUMN b ...;       -- colonne della vista: id, a   (ancora)
CREATE OR REPLACE VIEW v AS SELECT t.* FROM t;   -- adesso: id, a, b
```

Il commento in testa alla migrazione `073_teacher_content_view_section_id.sql`
dice che con `tc.*` la vista «include qualunque colonna futura»: **non è così**.
Include le colonne che la tabella aveva quando la 073 è girata. Il file non si
tocca, perché è già applicato; vale questa sezione.

Ricreando una vista conviene anche guardare le colonne che non vengono dalla
tabella principale ma dai `JOIN`: sono quelle che si perdono più facilmente
riscrivendo la definizione. E si ricrea con `CREATE OR REPLACE VIEW`, che è
atomico: fra la vecchia definizione e la nuova non c'è un istante in cui la
vista manca.

### Migrazioni distruttive: due righe obbligatorie

Dal 2026-09-07, una migrazione che **elimina o rinomina** qualcosa — `DROP
TABLE`, `DROP COLUMN`, `RENAME`, `CHANGE`, `MODIFY … NOT NULL`, `TRUNCATE`,
`DELETE FROM` — deve dichiararlo in testa al file:

```sql
-- SICUREZZA: perché è sicura con il codice della versione precedente
-- ROLLBACK: come si torna indietro se il deploy va male
```

Lo verifica `npm run db:migrations` (guardia in `tools/ci/check-migrations-safety.mjs`,
dentro `npm run ci`), e vale dalla migrazione 107 in avanti: le precedenti sono
già in produzione e riscriverne le intestazioni non renderebbe nessuno più
sicuro.

Perché: `deploy.sh` fa `git reset --hard` — quindi il codice nuovo è vivo
subito — e **poi** lancia le migrazioni. Per qualche secondo il codice nuovo
gira sullo schema vecchio; e se la migrazione ha eliminato una colonna,
tornare al commit precedente non basta più: l'unica via è ripristinare il dump
che il deploy salva prima di migrare, perdendo quel che è stato scritto nel
frattempo.

### La regola dei due passi

Dal 2026-09-08. Le due righe qui sopra dicono *che cosa* hai considerato; questa
regola dice *come* fare in modo che non ci sia niente di pericoloso da
considerare.

> **Una migrazione non toglie mai niente nello stesso rilascio in cui il codice
> smette di usarlo.** Prima si smette di usarlo (rilascio N), poi si toglie
> (rilascio N+1).

In pratica, per eliminare una colonna:

| | Codice | Migrazione |
|---|---|---|
| **Rilascio N** | smette di leggere e scrivere la colonna | nessuna |
| **Rilascio N+1** | invariato | `DROP COLUMN` |

E per rinominarne una, che è la stessa cosa fatta due volte:

| | Codice | Migrazione |
|---|---|---|
| **N** | scrive su entrambe, legge dalla nuova con ripiego sulla vecchia | aggiunge la colonna nuova, copia i valori |
| **N+1** | legge e scrive solo la nuova | nessuna |
| **N+2** | invariato | elimina la vecchia |

**Perché vale la pena.** Con i due passi, il codice del rilascio N funziona sia
sullo schema vecchio sia sul nuovo. Questo rende innocue tutte e tre le cose che
oggi fanno male:

- i secondi fra il `reset --hard` e la fine delle migrazioni, in cui il codice
  nuovo risponde a richieste vere sullo schema vecchio;
- il ritorno indietro, che torna a essere `git reset --hard <vecchio HEAD>` e
  basta, senza ripristinare il dump e senza perdere quel che è stato scritto
  nel frattempo;
- una migrazione che fallisce a metà, che lascia uno schema intermedio su cui
  il codice sa comunque girare.

Senza i due passi, il ritorno indietro dopo una migrazione distruttiva **non è
il comando che il log del deploy suggerisce**: quel comando riporta il codice,
non lo schema, e il codice vecchio su uno schema mutilato non parte.

**Quando si può saltare.** Se la cosa che togli non è mai stata in produzione —
una colonna aggiunta e rimossa fra due rilasci dello stesso giorno, una tabella
di appoggio creata e distrutta dentro la stessa migrazione — non c'è nessuna
versione precedente da proteggere. Scrivilo nella riga `-- SICUREZZA:` e vai.

**Chi lo controlla.** Nessuna guardia automatica: `npm run db:migrations`
verifica che le due righe ci siano, non che il ragionamento sia giusto. Questa
regola è quello che quelle due righe devono raccontare.

La strada preferita resta non distruggere: **aggiungi ora, migra i dati,
togli il vecchio in un deploy successivo**. Le aggiunte non distruttive —
tabella nuova, colonna nullable, indice — non hanno bisogno di nessuna
cerimonia.

## Crypto

```bash
php tools/crypto/generate_kms_key.php            # KMS_MASTER_KEY, da mettere in .env.local
php tools/crypto/rewrap_master.php --dry-run     # cambio della master: --apply, --verify
php tools/crypto/backfill_teacher_content.php --dry-run
php tools/crypto/rotate_kek.php --teacher=ID --reencrypt
php tools/crypto/audit_report.php [--json]
php tools/gdpr/encrypt_risdoc_compilations.php   # compilazioni risdoc (migration 101)
```

In locale la cifratura può fallire per docenti creati da dump con chiavi
non coerenti: non è un bug del codice.

Il cambio della chiave master ha una procedura sola, in
`docs/security/operations/kms-recovery.md` («Cambiare la chiave master»):
eseguirla in produzione lo decide il manutentore. `rotate_kek.php
--reencrypt` ricifra solo `body_html` e `body_pt`, e dal 23/9/2026
`--prune-old-kv` si rifiuta: togliere le versioni vecchie rendeva illeggibile
il resto (misurato in `tests/Integration/Crypto/PotaturaDelleVersioniTest.php`;
stessa guida, «Eventuale»).

## GDPR e audit

```bash
php tools/gdpr/execute_deletions.php [--apply]    # timer pantedu-gdpr-deletions (senza --apply elenca soltanto)
php tools/gdpr/anonymize_expired.php --dry-run   # timer pantedu-gdpr-retention; senza --dry-run APPLICA se GDPR_RETENTION_ENABLED è accesa (in produzione lo è)
php tools/gdpr/breach_drill.php                  # drill semestrale
php tools/gdpr/spazza_bozze_scadute.php          # timer pantedu-risdoc-scadenze (prova; --applica per agire)
php tools/audit/export_audit_chain.php           # timer pantedu-audit-chain
```

## Wiki

`aggiorna-wiki` (skill di progetto) parte dall'ultimo report in
`docs/analysis/` e dai commit dopo l'ultima voce di changelog. Guard:
`php tools/wiki/strip_code_links.php --check`.
