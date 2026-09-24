---
tags:
  - documentazione/adr
  - dominio/core
date: 2026-09-23
tipo: adr
status: accettato
aliases: ["ADR-049", "confini degli strati", "SQL nei repository", "cricchetto architetturale", "dove sta il SQL"]
cssclasses: []
---

# ADR-049 — Il SQL sta nei repository, `php://input` ha un solo lettore, l'ambiente si legge in un posto

## Stato

**ACCETTATO** (2026-09-23). Nasce dalla revisione architetturale di
settembre 2026, scheda R-8 «Cricchetti sui confini dichiarati»
(A-10, A-11, A-12, A-32, A-34).

## Contesto

Il primer dichiarava già la regola in una riga («il SQL sta in
`app/Repositories/<Dominio>/`... mai `php://input` nei controller... mai
`$_ENV` fuori da `app/Config`»), ma nessun controllo la sorvegliava. Misurato
il 23/9/2026: 45 controller eseguono SQL direttamente (`Database::connection()`
o un metodo di `PDO`), 20 file leggono `php://input` fuori dall'helper di
[[decisions/ADR-034-helper-condivisi-controller]], 15 file leggono l'ambiente
fuori da `app/Config`. La regola non distingueva nemmeno i servizi con `PDO`
iniettato — di fatto accettati, è il modo in cui un repository riceve la
connessione nei test — dai controller, che sono il problema vero (A-10); e
convivevano due convenzioni d'iniezione non scritte, PDO iniettato e classi
statiche con SQL raggiungibili solo per riflessione (A-32).

Questo ADR scrive la regola per esteso e la fa sorvegliare da un cricchetto:
un controllo che fissa gli elenchi di oggi e fallisce se crescono. Non
introduce un contenitore di dipendenze — restare senza è una decisione del
manutentore, fuori da questo ADR — e non pretende di correggere i 45
controller in un colpo solo: quello è lavoro separato (R-8, passo 4), che
questo cricchetto rende visibile invece di lasciarlo ricrescere in silenzio.

## Decisione

**Il SQL** sta in `app/Repositories/<Dominio>/` (metodi nominati per intento)
e nei servizi che ricevono `PDO` per costruttore con la forma
`private ?PDO $pdo = null` e un metodo privato `db(): PDO` che ricade su
`Database::connection()` quando il test non ne inietta uno. È vietato nei
controller, nelle viste e in `app/Core`, **salvo** `Auth`, `Database` e
`Migrator`: i tre punti che devono poter aprire una connessione senza passare
da un repository, perché sono loro stessi l'infrastruttura che i repository
usano. Niente classi statiche nuove con SQL: quelle esistenti
(`TeacherContextResolver`, `RigheDellaBarra`, `StatoDelDocumento`, A-32) non
si moltiplicano; una dipendenza nuova si inietta, non si chiama per nome
statico.

**Il corpo della richiesta** si legge solo con l'helper condiviso di
[[decisions/ADR-034-helper-condivisi-controller]] (`$req->json()`,
`$req->body()`, `$req->rawBody()`). Un controller nuovo non chiama
`file_get_contents('php://input')` per conto suo.

**L'ambiente** (`$_ENV`, `getenv()`, `$_SERVER` quando sta al posto di una
variabile d'ambiente — non per dati della richiesta HTTP come `HTTPS` o
`REQUEST_URI`, che restano dati di contesto, non configurazione) si legge
solo in `app/Config/*.php`, **salvo** `app/Core/Config.php` (il caricatore:
deve leggere `PANTEDU_DATA_PATH` prima ancora che `app/Config/` esista come
concetto caricato; dal 23/9/2026 ospita anche `booleanoDallAmbiente()` e
`testoDallAmbiente()`, le due letture che i file di `app/Config/` chiamano
per gli interruttori e per i testi con un predefinito — una regola sola per
«vuoto», «sconosciuto» e `1/0`, `true/false`, `yes/no`, `on/off`, A-35) e `app/Core/Database.php` (le credenziali di manutenzione
e del migratore, tenute fuori dalla configurazione caricata in memoria e
leggibile da tutti, per la stessa ragione per cui non sono in `Config::get`
già oggi). Un controller o un servizio nuovo passa da `Config::get()`.

## Il cricchetto

`tools/ci/confini-degli-strati.json` fissa tre elenchi — controller con SQL,
file che leggono `php://input` fuori dall'helper, file fuori da `app/Config`
che leggono l'ambiente — con il criterio di riconoscimento usato per
calcolarli. `tests/Unit/Architettura/ConfiniDegliStratiTest.php` li
ricalcola a ogni corsa e confronta: un file **nuovo** in uno dei tre insiemi
fa fallire la prova, con il nome del file e la regola che viola; un file
**tolto** dall'elenco (perché corretto) fa fallire l'altro verso, per
ricordare di abbassare l'elenco — altrimenti il cricchetto smette di stringere
e un file corretto lascerebbe spazio a uno nuovo con lo stesso peso.

Le dimensioni hanno il loro cricchetto separato: `tools/ci/dimensioni.json` e
`tests/Unit/Architettura/DimensioniDeiFileTest.php`, sui file PHP di `app/`
oltre 600 righe e JS di `js/` oltre 1.000, con un margine del 5% prima di
fallire in crescita (un file può oscillare di poche righe senza che ogni PR
tocchi il censimento) e lo stesso obbligo di abbassare il numero quando un
file scende.

## Conseguenze

- I 45 controller con SQL, i 20 file con `php://input` diretto e i file che
  leggono l'ambiente fuori da `app/Config` restano quello che sono oggi: non
  si correggono qui, ma non possono più crescere senza che un test lo dica.
  (Lo stesso 23/9/2026, più tardi: i 20 file con `php://input` sono scesi a
  zero, A-52, e quelli che leggono l'ambiente da dieci a sei, A-35; i numeri
  di oggi stanno nel cricchetto.)
- Un controller o un servizio nuovo che introduce uno di questi tre difetti
  vede rosso alla prima corsa della suite `unit`, che è già nel cancello su
  `main` (`wiki/dev-workflow.md`).
- `app/Config/spid.php` e `app/Config/cie.php` (23/9/2026) sono la prima
  applicazione della regola sull'ambiente: `SpidController` e `CieController`
  leggevano `$_ENV['SPID_ENABLED']` / `$_ENV['CIE_ENABLED']` direttamente,
  dopo un `Config::get('auth.spid.enabled')` che non ha mai trovato niente
  perché quella chiave non è mai esistita (A-34). Corretto con
  `spid.enabled` / `cie.enabled`, letti una sola volta nel file di
  configurazione.
- `wiki/_llm-primer.md` rimanda qui invece di ripetere la regola: i numeri
  di oggi vivono nel cricchetto, non nel primer, perché lì invecchierebbero
  senza avviso.

## Rimandi

- [[decisions/ADR-034-helper-condivisi-controller]]: l'helper del corpo
  della richiesta.
- `tools/ci/confini-degli-strati.json`,
  `tests/Unit/Architettura/ConfiniDegliStratiTest.php`: gli elenchi di oggi
  e il cricchetto.
- `tools/ci/dimensioni.json`, `tests/Unit/Architettura/DimensioniDeiFileTest.php`:
  il cricchetto sulle dimensioni.
- [[technical-debt]] voci 17 (A-10), 18 (A-11), 180 (A-32), 181 (A-34).
- La revisione architetturale del 23/9/2026, scheda R-8.
