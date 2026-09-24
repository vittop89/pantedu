# Suite E2E (Playwright)

Stato al 14 settembre 2026: 136 file di spec, 645 test, un solo worker. Tutte le
spec sono state riscritte sull'infrastruttura in `support/` (7 settembre 2026):
nella cartella non resta nessun file fuori dalle aree, e i due moduli di
accesso scritti a mano (`helpers.js`, `studio-eser-helpers.js`) sono stati
eliminati.

Il piano del refactoring è `docs/plans/e2e-refactor-blueprint.md`, l'esito con
la scorecard e le venti domande di chiusura `wiki/e2e-refactoring-esito.md`, lo
stato e i numeri `wiki/testing.md`, i prerequisiti per eseguire la suite
`docs/ops/e2e-runner-prerequisiti.md`.

## Prerequisiti

- L'ambiente di sviluppo in WSL (dal 10 settembre 2026, al posto di XAMPP): il
  repository in `~/pantedu`, il database di sviluppo nel contenitore
  `pantedu-mariadb-dev`, il server con `bash tools/dev/wsl/server.sh` su
  `http://127.0.0.1:8765`, che è anche l'indirizzo predefinito della suite.
  Come si monta: `docs/dev/sviluppo-in-wsl.md`. La suite non crea né azzera il
  database, usa tre utenti reali (docente, secondo docente, amministratore).
- Il Node di `.nvmrc`, 22 dal 23 settembre 2026 come la CI (lo installa
  `sudo bash tools/dev/wsl/pacchetti.sh`); `npm ci` e
  `npx playwright install chromium` li fa `tools/dev/wsl/prepara.sh`.
- L'editor drawio in `public/drawio-app/` (non versionato: lo installa
  `tools/install-drawio.sh`). In una copia di lavoro nuova non c'è, e senza di
  lui le sei prove `drawio_native` di
  `area-docente/creazione-e-modifica-per-sidepage.spec.js` falliscono lì e solo
  lì: si collega con `ln -sfn ~/pantedu/public/drawio-app public/drawio-app`
  (vedi `docs/dev/sviluppo-in-wsl.md`, «Le trappole»).
- Microservizio TeX locale per le spec che compilano davvero:
  `node tools/dev/tex-service-local.mjs` (uvicorn su 127.0.0.1:8001).
- `pdflatex` (TeX Live) e `pdftoppm` (poppler) nel PATH per le spec con il tag
  `@pdflatex`, che compilano e rasterizzano davvero: li installa
  `sudo bash tools/dev/wsl/pacchetti.sh`.
- `.env.local` (ignorato da git) con: `E2E_TEACHER_USER`, `E2E_TEACHER_PASS`,
  `FM_E2E_ADMIN_USERNAME`, `FM_E2E_ADMIN_PASSWORD`, `TEX_COMPILE_ENDPOINT`,
  `TEX_COMPILE_SECRET`. Facoltative: `E2E_TEACHER2_USER` e `E2E_TEACHER2_PASS`
  (default `docente.due` con la password del primo docente), `FM_E2E_BASE_URL`.
  I valori non vanno mai nelle spec né nei log.
- `tools/ci/seed_e2e_database.php` è per i database usa-e-getta della CI (la
  suite end-to-end e il lavoro «PHP: prove d'integrazione (MariaDB)» di
  `ci.yml`), non per `pantedu_dev` né per il `pantedu_test` locale: vuole
  `PANTEDU_SEED_CI=1` e anche `E2E_TEACHER2_PASS` (qui non c'è ripiego), crea
  utenti con password note, e oltre alle righe nel database scrive i modelli
  risdoc nel checkout (`schemas/risdoc/`, `storage/templates/risdoc/`, non
  tracciati).
- **I contenuti del docente di prova in una scuola sola.** Da ADR-037 (13
  settembre 2026) un contenuto sta nella scuola in cui è pubblicato, e la
  scuola attiva è uno stato della sessione. Il setup sceglie le pagine da
  provare nella scuola in cui il docente ha più contenuti
  (`tools/dev/e2e_urls.php`, chiave `scuola_id`), e la fixture del docente
  riporta la sessione in quella scuola all'inizio di ogni prova. Il dump di
  sviluppo aveva 37 verifiche del docente con le etichette dell'istituto 108 e
  tutto il resto nel 106 (in produzione è tutto nel 106): prima della fase 1
  la coincidenza di sigle le mostrava lo stesso, adesso no. Se su un database
  di sviluppo le prove di studio non trovano le verifiche con le figure, si
  guarda con la prova a secco, e solo in sviluppo si allinea:

  ```bash
  php tools/dev/e2e_allinea_scuola.php --docente=docente.uno --da=108 --a=106
  ```

  Con `--apply` scrive; su un'installazione vera si rifiuta.
- **Gli anni della terna vogliono l'incarico.** Da ADR-043 (migrazione 133,
  15 settembre 2026), con «solo incaricati» un docente usa un anno solo se ne ha
  l'incarico. Il database di sviluppo non ne aveva per il docente di prova nella
  scuola 106: la migrazione gli ha sospeso gli anni, il selettore «Classe» della
  home resta vuoto e ogni spec che sceglie la terna (`scegliTerna`) si ferma lì
  con «did not find some options». La semina della CI gli dà gli incarichi; in
  sviluppo si danno dall'amministrazione (Sezioni dei docenti) o con
  `TeacherSectionService::assign`, che riprende da sé le spunte sospese.
- Una spec che cambia istituto attivo lo rimette alla fine con la pulizia
  (`contenuti-per-scuola.spec.js`, `profilo-e-istituti.spec.js`): la sessione
  la condividono tutte le prove.

## Comandi (in WSL, da `~/pantedu`)

```bash
npm run e2e
```

```bash
npx playwright test tests/e2e/<spec>.spec.js --repeat-each 2 --reporter=line
```

```bash
npm run e2e:typecheck
```

Giro completo con il JSON conservato fuori da `tests/e2e-results/` (che
Playwright svuota a ogni giro):

```bash
PLAYWRIGHT_JSON_OUTPUT_NAME=storage/_tmp/e2e-baseline/giro-<data>.json npx playwright test --reporter=line,json
```

Non passare `--workers` più alti né `--retries`: un solo DB e tre utenti
condivisi (blueprint, decisione B.2).

Giro mirato sulle aree che condividono dati e factory (5-10 minuti), da usare
a fine lavoro al posto del giro intero:

```bash
npx playwright test tests/e2e/studio tests/e2e/verifiche tests/e2e/condivisione tests/e2e/risdoc tests/e2e/admin tests/e2e/area-docente tests/e2e/editor tests/e2e/pubblico tests/e2e/sicurezza tests/e2e/osservabilita tests/e2e/qualita --reporter=line
```

Il giro completo resta obbligatorio prima di unire su `main`, perché ogni push
su `main` va in produzione.

Dopo un giro interrotto a metà conviene ripulire i dati che restano nel
database di sviluppo — a giro finito non ne restano, perché le factory
cancellano quel che creano (elenca per difetto, cancella solo con `--apply`):

```bash
php tools/dev/e2e_cleanup_residues.php
```

Senza pulizia il docente di prova accumula verifiche: il 7 settembre 2026, con
le spec storiche, erano arrivate a 611 e la suite aveva cominciato a fallire per
il manifesto del pacchetto in timeout e per le richieste di figure rifiutate.

## Struttura

```
tests/e2e/
├── support/                    infrastruttura in TypeScript strict (tsc: npm run e2e:typecheck)
│   ├── test.ts                 unico ingresso delle spec: test, expect, waitForFmEvent, ApiError, tipi
│   ├── env.ts                  variabili d'ambiente validate una volta, dati scoperti dal setup
│   ├── fixtures/               diagnostics (auto), sessioni per ruolo + registro di pulizia, factory
│   ├── auth/                   cache delle sessioni (tests/e2e/.auth) e login dal modulo
│   ├── api/                    HttpClient (CSRF, JSON tipizzato) e client di dominio
│   ├── factories/              dati di prova con nome unico e cancellazione registrata
│   └── sync/                   registratore degli eventi fm:* e attese sui segnali dell'app
│   ├── pages/                  pagine con flussi stabili (studio esercizio, home con la barra laterale, banco dell'editor)
│   └── components/             componenti riusati (gruppo di quesiti, barra degli strumenti, barra dei filtri)
├── studio/ verifiche/ risdoc/ condivisione/ admin/ area-docente/
│   editor/ pubblico/ sicurezza/ osservabilita/ qualita/     le spec, per area
│   ├── editor/moduli/          prove del modulo dell'editor dentro al browser
│   └── risdoc/moduli/          prove dei convertitori e dei comandi dell'editor del corpo strutturato
├── global-setup.js             ToS dei tre utenti, terne con contenuti, coppia condivisibile
├── .auth/                      cache delle sessioni (ignorata)
└── qualita/regressione-visiva.spec.js-snapshots/
                                immagini attese del confronto visivo
```

## Fixture disponibili

| Fixture | Cosa dà |
|---------|---------|
| `teacherPage`, `teacher2Page`, `adminPage` | pagina già autenticata in un contesto proprio; nessuna navigazione automatica |
| `teacherApi`, `teacher2Api` | `http` (CSRF incluso), `content`, `verifica`, `share`, `curriculum`, `maps`, `bundle`, `printInfo`, `risdoc`, `sources`, `access`, `osservabilita` |
| `adminApi` | `http`, `notifications()`, `risdoc`, `tikz`, `access` |
| `contentFactory`, `verificaFactory`, `shareFactory` | dati di prova del docente, cancellati nel teardown anche se il test fallisce |
| `teacher2ContentFactory`, `teacher2ShareFactory` | le stesse per il secondo docente: chi recupera dal pool cancella la propria copia |
| `studioEsercizio`, `homeDocente`, `studioEsercizioCollega` | pagine con le attese sui segnali dell'app già dentro |
| `strumenti` | dichiara i prerequisiti esterni (`pdflatex`) e fallisce con un messaggio esplicito se mancano |
| `naming` | nomi unici per giro, con il prefisso che lo strumento di bonifica riconosce |
| `bancoEditor` | pagina con il bundle dell'editor caricato e i ganci di prova pronti (`editor/moduli/`) |
| `cleanup` | registro di pulizia: `cleanup.add("teacher", "descrizione", async () => …)` |
| `env` | nomi utente, URL di base, `terna`, `discovered.*` (mai le password) |
| `diagnostics` | errori JS, errori console, risposte 4xx/5xx; **fa fallire il test** se la pagina ha prodotto errori JavaScript, e allega il riepilogo al report |
| `page` | pagina anonima, osservata dalla diagnostica |

## Gli errori JavaScript fanno fallire il test

`support/fixtures/diagnostics.fixture.ts` osserva ogni pagina aperta dalle
fixture di ruolo e la `page` anonima. Se il test ha fatto il suo lavoro ma la
pagina ha lasciato errori JavaScript in console, il test **fallisce lo stesso**:
quegli errori l'utente li avrebbe avuti.

Non c'è modo di zittirlo per un singolo test, ed è voluto: una tolleranza per
test è la stessa cosa di un errore ingoiato, scritta meglio. L'unico posto dove
si dichiara «questo non è un errore dell'applicazione» è il filtro `CONSOLE_NOISE`
in quel file, che sta in un posto solo e si legge tutto insieme.

Quando salta fuori un errore ci sono tre esiti, in quest'ordine:

1. è un difetto dell'applicazione → si corregge l'applicazione;
2. non è nostro (una libreria di terzi che urla per conto suo) → si allarga
   `CONSOLE_NOISE`, scrivendo lì la ragione;
3. non è nessuno dei due e non si chiude subito → voce nel registro del debito
   (`wiki/technical-debt.md`), non un cerotto nella spec.

Il controllo è arrivato il 7 settembre 2026, quando si è visto che l'asserzione
`expect(diagnostics.jsErrors).toEqual([])` era scritta in sessantasette test su
cinquecentottantanove: negli altri cinquecento un errore in pagina passava
inosservato. Il primo giro con il controllo attivo ha trovato due difetti reali
(voci 63 e 64 del debito), uno dei quali rendeva vuota da mesi la verifica
WCAG del testo ingrandito.

## Il pacchetto del front-end deve essere aggiornato

`public/build/` e `css/main.bundle.css` sono generati e non versionati: possono
restare indietro rispetto a `js/` e `css/` senza che nulla lo dica. Il 7
settembre 2026 lo erano di quattro commit, e la suite girava verde contro
JavaScript vecchio.

Da allora `global-setup.js` confronta le date prima di cominciare e ferma il
giro dicendo quale file è più recente e quale comando ricostruisce:

```bash
npm run build
```

```bash
php tools/build-css-bundle.php
```

## I marcatori nei titoli

Tre marcatori dicono che cosa serve a un test oltre all'applicazione, e
servono a escluderlo dove quella cosa non c'è (`--grep-invert`):

| Marcatore | Vuol dire | Chi lo esclude |
|-----------|-----------|----------------|
| `@tex` | compila LaTeX davvero, passando dal microservizio TeX | il workflow E2E |
| `@pdflatex` | invoca `pdflatex` sulla macchina | il workflow E2E |
| `@istanza` | verifica il **contenuto** dei modelli o degli esercizi di questa installazione — «Classe eterogenea» dentro un modello, una figura in un esercizio storico | il workflow E2E |

`@istanza` è il più delicato: quei test non sono sbagliati, ma su un database
seminato da zero non hanno l'oggetto della verifica, e riempire il seme con le
stringhe che si aspettano li farebbe passare senza verificare più niente.

## Le cartelle `moduli/`: prove del modulo, non percorsi dell'utente

Le spec di questa cartella non ripercorrono quel che fa un docente: costruiscono
un pezzo di pagina con i ganci di prova dell'applicazione
(`window.FM.__buildSectionForTest` e simili), chiamano una funzione dell'editor
e guardano il risultato nel DOM o negli stili calcolati. Il browser serve perché
quelle funzioni lavorano su selezione, `contenteditable` e `getComputedStyle`:
fuori da un browser non esistono. L'applicazione intorno, invece, non c'entra.

Stanno qui perché è quello che sono, e perché sono la copertura più fitta che
l'editor abbia: novantaquattro test sulle liste, sui formati in linea, sulle
tabulazioni. Chiamarle end-to-end le farebbe sembrare una garanzia che non
danno, e cancellarle butterebbe via la garanzia che danno davvero.

`risdoc/moduli/` è la stessa cosa per il corpo strutturato dei documenti: i
convertitori fra schema e blocchi, e i comandi dell'editor Tiptap.

Regola: una prova di modulo sta qui; se il caso si può premere dall'interfaccia,
va scritto come spec dell'area, con la fixture della pagina.

## Regole per le spec nuove o riscritte

1. `// @ts-check` in testa e `const { test, expect } = require("./support/test")`:
   nient'altro da `support/` (regola ESLint).
2. Niente login nella spec: fixture per ruolo.
3. Dati di prova solo dalle factory (nome `e2e-<spec>-…`, cancellazione
   registrata); i contenuti scoperti dal setup (`env.discovered`) sono di
   sola lettura.
4. Niente `page.waitForTimeout` e niente `networkidle`: asserzione web-first,
   `waitForURL`, `waitForResponse` sull'endpoint reale, `waitForFmEvent`
   su un evento `fm:*` (blueprint §6.6).
5. Locator per ruolo e nome, etichetta o testo; `#id` e classi `fm-*` solo
   dentro pagine e componenti; niente `dispatchEvent("click")` né
   `page.evaluate` per interagire.
6. Niente `console.log` né screenshot manuali: trace, screenshot e video
   arrivano dal config in caso di fallimento. Gli errori JavaScript non si
   asseriscono più a mano: li controlla la fixture della diagnostica per ogni
   test (sezione «Gli errori JavaScript fanno fallire il test»).
7. Niente `test.setTimeout` nella spec: il tempo massimo è nel config
   (90 secondi, quasi cinque volte il test più lento misurato). Se un test ha
   bisogno di più tempo, o aspetta la cosa sbagliata o il config va cambiato
   per tutti.
8. Un test gira da solo (`--grep`) e due volte di fila (`--repeat-each 2`).
9. Quando serve aspettare la fine di un'animazione (il controllo di
   accessibilità misura colori mescolati a metà dissolvenza, un comando si
   preme mentre si sta ancora muovendo), si chiama `attendiAnimazioniFerme`
   del supporto: guarda `document.getAnimations()` saltando quelle senza fine,
   e non è mai un tempo.
10. Se l'applicazione fa qualcosa da sola dopo il caricamento — il ripristino
   automatico delle scelte della verifica riscrive i campi seicento
   millisecondi dopo — il test aspetta che sia successo prima di scrivere.
   Altrimenti passa quasi sempre e fallisce quando la macchina è carica.
11. **Una prova che guarda una pagina intera — la fotografa, la misura, ne
   conta gli elementi — apre con `apriPaginaVera` del supporto, non con
   `page.goto`.** La guardia pretende un 200 e nessuna pagina d'errore prima
   di lasciar proseguire. Serve perché quel genere di asserzione non
   distingue la pagina giusta dalla pagina d'errore: un 404 confrontato a
   pixel con un altro 404 è verde, e una pagina d'errore non ha mai problemi
   di contrasto. Il 20 settembre 2026 quindici immagini attese della
   regressione visiva — cinque pagine per tre misure — ritraevano il «404 —
   Not Found»: `/privacy` invece di `/privacy/informativa`,
   `/area_docente/...` (il nome della cartella delle viste) invece di
   `/area-docente/...`, e `/cookie-policy`, che non è mai esistita. Nessuno
   se n'era accorto per una settimana.
   Quando si aggiunge una rotta a un elenco di pagine, la si prende da
   `docs/ROUTES.md` (o da `php tools/dev/gen_routes_md.php`), e la si prova
   con `php tools/dev/render_page.php --route=<rotta>`: stampa il codice vero
   senza bisogno del browser.
12. **Non si fotografa il contenuto di un riquadro che non serve
   l'applicazione.** La guardia del punto 11 guarda il documento principale, e
   deve: la pagina sotto esame è quella esterna. Dentro un `<iframe>` servito
   da altri non c'è niente da confrontare a pixel — o il servizio non c'è
   (in CI `/grafana/` è una `location` dell'nginx di produzione, non una rotta
   dell'app: la richiesta arriva al router PHP e torna un 404, fotografato per
   dodici giorni in `osservazione-desktop` e `osservazione-tablet`), o c'è ed
   è vivo, e cambia a ogni secondo. Si nasconde il contenuto tenendo la
   cornice — così la geometria resta sotto confronto — e quel che
   l'incorporamento ha di verificabile si misura in una prova sua: dove punta
   il riquadro, con quali permessi di sandbox, e che la CSP gli conceda
   `frame-ancestors 'self'` negandolo a tutto il resto
   (`admin/osservazione-e-il-riquadro-di-grafana.spec.js`). Nascondere e
   basta, senza quella prova, non è una cura: è lo stesso verde cieco
   spostato di un metro.
