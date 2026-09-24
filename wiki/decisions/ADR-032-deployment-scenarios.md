---
tags:
  - documentazione/adr
  - dominio/auth
date: 2026-09-05
tipo: adr
status: accettato
aliases: ["ADR-032", "scenari", "deployment scenarios"]
cssclasses: []
---

# ADR-032 — Scenari di esercizio: uso personale (1), colleghi (2), Istituto (3)

## Stato

**ACCETTATO / IMPLEMENTATO** — `App\Support\DeploymentScenario`, pannello
`/admin/system/deployment`, pagina `/accesso-classe`, informativa per scenario.
Estende ADR-017, che resta come asse legacy `single | institute` allineato
automaticamente dallo scenario.

## Contesto

Il pacchetto consegnato al DPO (settembre 2026) descrive tre scenari, e il
Regolamento cloud ACN (Decreto direttoriale n. 21007/24) ha reso evidente che
non sono tre gradi della stessa cosa ma tre configurazioni giuridiche diverse:

| Scenario | Chi | Titolare | Account studenti | Infrastruttura |
|---|---|---|---|---|
| 1 `personal` | solo l'autore | il gestore dell'istanza | nessuno: credenziale del docente | qualunque |
| 2 `colleagues` | docenti di qualunque scuola | il gestore (art. 4(7) GDPR) | nessuno: credenziale del docente | qualunque |
| 3 `institute` | docenti e studenti di un Istituto | l'Istituto; il gestore è Responsabile ex art. 28 | Completa / Ridotta / Anonima | **solo qualificata ACN** |

Il modo legacy `single | institute` (ADR-017) non rappresentava lo scenario 2
— iscrizione dei docenti aperta senza account studente — e non sapeva che lo
scenario 3 è illecito sull'infrastruttura di un privato non qualificato.

## Decisione

1. **Lo scenario è la sorgente di verità.** `DeploymentScenario::current()`
   legge `storage/config/deployment_scenario.json` (pannello), poi
   `DEPLOYMENT_SCENARIO` in `.env`, poi deduce dal modo legacy
   (institute → 3, single → 1). Ogni cambio scrive anche `deployment.json`
   (institute ⇔ 3) e `student_registration.json` (anonima fuori dal 3), così
   tutto il codice che interroga `DeploymentMode` e `StudentRegistration`
   continua a funzionare.
2. **Politiche derivate, non flag sparsi.** `teacherSelfSignupOpen()`,
   `studentAccountsEnabled()`, `allowedRegistrationRoles()`,
   `controllerName()`, `informativaFile()`, `legalDocuments()`: chi ha bisogno
   di sapere "cosa vale in questo scenario" chiede a un solo posto.
3. **Lo scenario 3 è protetto da un fatto, non da un click.** Si attiva solo se
   `.env` dichiara `INSTANCE_ACN_QUALIFIED=true`, e chi preme il pulsante
   conferma infrastruttura qualificata e DPA sottoscritto. Sull'istanza
   dell'autore il flag è falso e il pannello spiega perché.
4. **Ogni cambio va a registro** (`privileged_access_log`, azione
   `deployment_scenario_switch`) con motivazione obbligatoria, come per il
   gate ToS e l'obbligo 2FA.
5. **Pagine di accesso per scenario.** `/login` mostra iscrizioni aperte o
   chiuse, il link alla credenziale di classe (1 e 2) e SPID/CIE (3).
   `/accesso-classe` è la porta degli studenti negli scenari 1 e 2: posta
   sull'endpoint esistente `/api/access/student-login`, nessun account.
   `/register` ammette i ruoli dello scenario e rifiuta gli altri anche su
   POST diretta.
6. **Documenti per scenario.** `/privacy/informativa` serve
   `docs/privacy/informativa.md` (1 e 2) o `docs/privacy/informativa-istituto.md`
   (3, Titolare = Istituto, con token `{{INSTITUTE_LEGAL_NAME}}`,
   `{{DPO_CONTACT}}`, `{{INSTANCE_OPERATOR_NAME}}`). Il DPA compare nei link
   solo nel 3 e, negli altri, si apre con la premessa che non si applica.

## Credenziale di classe: enforcement (2026-09-04)

Verifica pre-push sui contenuti (creazione, pubblicazione per classe,
condivisione fra docenti) nei tre scenari. Trovato che il grant
`fm_teacher_access`, scritto in sessione da `TeacherCredentialController`,
non veniva letto da nessun endpoint di studio: un ospite con la credenziale
restava un guest puro (`__deny__`) e la sidebar lo trattava come tale. La
"modalità Anonima" dichiarata a DPO e informativa non mostrava nulla.

Corretto con `App\Support\ClassAccessGrant`, unico lettore del grant:

- `ContentStudyController`: viewer "studente senza account", filtro
  `teacher_id` del docente della credenziale, classe della credenziale se
  delimitata, sezioni nascoste agli studenti rispettate, lettura per id
  limitata ai contenuti di quel docente. Un ospite senza credenziale legge
  per id solo ciò che è davvero pubblico (`publish_public` del docente che
  pubblica in rete, `PublicContentPolicy`; fino al 15/9/2026 era per regola il
  super-admin docente):
  prima bastava l'id per qualunque contenuto pubblicato di qualunque docente.
- `MapsController::signedUrl`: vista (mai copia) delle mappe pubblicate del
  docente della credenziale per la sua classe.
- layout e sidebar: curriculum dell'istituto della credenziale, sezioni
  visibili agli studenti, endpoint `/api/study/*`, banner con «Esci»
  (`POST /accesso-classe/esci`). Dal 19/9/2026 nel selettore delle materie
  solo quelle con materiali visibili: vedi «Che cosa vede chi studia».
- `StudyHeaderController` e `VerificaController::listForStudent` non
  rispondono più 401 all'ospite con credenziale (intestazione del docente;
  lista verifiche vuota: la condivisione fra colleghi non è una pubblicazione).

Le rotte, però, non lasciavano passare l'ospite: vedi «Le rotte dello studio
per l'ospite (2026-09-14)».

Condivisione fra docenti, stesso giro:

- i grant mirati (`content_shares`) aggiravano il blocco copyright che
  valeva solo per `shared_with_pool`: ora `ShareGrantsController::setGrants`
  applica lo stesso `shareBlockReason` (ToS §2.1.1, art. 70-bis);
- i membri di un gruppo devono essere colleghi dello stesso istituto, come
  i grant diretti (la lettura era già bloccata dal gate cross-istituto).

Risdoc, stesso giro: `risdoc_compilations.data_json` era l'unico contenuto
del docente in chiaro nel database, e il Modulo di autorizzazione aveva
campi per nome, cognome e data di nascita dello studente. Ora le
compilazioni sono cifrate con la chiave del docente (migration 101,
`CompilationRepository`, conversione con
`tools/gdpr/encrypt_risdoc_compilations.php`); il modulo è cancellato con
le sue compilazioni (migration 102, file schema rimossi); il selettore
«studente» è tolto dalla scheda di recupero e dallo schema dei modelli;
e senza account studente `CompilationScrubber` svuota, prima del
salvataggio, ogni campo che per nome si riferisce a studenti o genitori,
restituendo i nomi al client.

Gestione documentale (2026-09-04, sera): i modelli della categoria `modelli`
producono atti dell'Istituto e parlano di studenti per natura. Tre risposte
tecniche, in ordine di forza: la clausola nei ToS §2.5; `institutes.
compilation_storage` (migration 103), per Istituto e su indicazione del suo
DPO — con 0, `CompilationController::save` rifiuta il salvataggio per i
docenti di quell'Istituto (`CompilationStoragePolicy`) e il client tiene la
bozza in `localStorage`, esportando il PDF: sul server resta il solo
modello; `RISDOC_INSTITUTIONAL_TEMPLATES=false`, che nasconde i modelli a
tutti. I modelli personali (categoria `risorse`, `altro`, `bes`) non sono
toccati. Lo stile TeX comune non incorpora più alcun Istituto: intestazione
da `texCommon/risdoc-istituto.tex` (override del docente → file d'istanza
`<storage>/risdoc/istituti/<id>.tex` → generato dal profilo) e logo da
`<storage>/risdoc/istituti/<id>.png` (`InstituteAssets`).

Restano dichiarati e non toccati: `institute_pool_policy` (tabella mai
letta) e `map_is_public` (mai impostato); il legacy
`ExerciseStudyController` (tabella `exercises`) non conosce il grant;
`/api/study/verifica/list` mostra agli studenti con account (scenario 3) le
verifiche `shared_with_pool` dei docenti dell'istituto, cioè una
condivisione fra colleghi usata come pubblicazione — da decidere. **Deciso
con [[decisions/ADR-037-pubblicazioni-e-copie]], fase 3 (13/9/2026):** agli
studenti arriva ciò che è pubblicato, con lo stato della pubblicazione; la
condivisione resta fra colleghi, e le verifiche esistenti nascono in bozza.

## Anno e sezioni nel filtro dei contenuti (2026-09-05)

Piano [classi-credenziali-scenari](../../docs/plans/classi-credenziali-scenari.md), passo A.
Il codice anno («3») è la granularità con cui i docenti etichettano i
contenuti; la sezione («3A») è lo strato dell'Istituto (sezione dello
studente, incarichi). Il filtro dei contenuti confrontava la classe alla
lettera, quindi una credenziale delimitata a «3A», o uno studente con
account in 3A, non vedeva i contenuti «3»: solo i «generali».

La regola degli incarichi, «l'anno copre le sue sezioni, la sezione vale
solo per sé», ora vive in `App\Domain\ClassCode` ed è condivisa da
`TeacherSectionService`, dal gate `ContentVisibilityPolicy::studyListFilters`
(che emette `classi`, l'insieme delle etichette che raggiungono chi guarda)
e da `TeacherContentRepository::search` (clausola `classe IN (...)` sia per
`publish_scope='class'` sia per i bersagli del fan-out). Da «3A» si vedono
«3A» e «3»; da «3» solo «3». Le query del docente non passano di lì.
`classi` è il punto d'aggancio degli anni frequentati (passo D).

Test: `tests/Unit/Domain/ClassCodeTest.php`, `tests/Integration/AnnoCopreSezioniTest.php`.

## Aree del pannello per scenario (2026-09-05)

Piano [classi-credenziali-scenari](../../docs/plans/classi-credenziali-scenari.md), passo B.
Il pannello mostrava lo stesso menu in ogni scenario: «Sezioni» in scenario 1
faceva credere che le sezioni contassero. Ora un solo punto decide,
`App\Support\AdminAreas`: per ogni area dipendente dallo scenario dice se è
attiva, perché altrove è inerte e con quale scenario si attiva. Menu
(`page_head`), schede degli strumenti, riquadri della dashboard, avvisi in
testa alle pagine (`_partials/inert_area.php`) e l'elenco «Aree inattive» nel
pannello Deployment leggono tutti da lì.

| Area | 1 | 2 | 3 |
|------|---|---|---|
| Registrazioni docenti (scheda Tools, riquadro) | inerte | attiva, in evidenza | attiva |
| Sezioni e incarichi | inerte | inerte | attiva |
| Registrazione studenti: modalità e classi ammesse | inerte | inerte | attiva |
| Riquadro «Credenziali di classe» | attiva | attiva | inerte |
| Riquadro «Studenti senza sezione» | inerte | inerte | attiva |

«Inerte» = fuori dal menu, raggiungibile per URL con l'avviso in testa; una
richiesta di registrazione arrivata comunque non si nasconde mai. Lo
scenario compare come badge nella barra di ogni pagina del pannello.
Test: `tests/Unit/Support/AdminAreasTest.php`.

## Classi frequentate (2026-09-05)

Piano [classi-credenziali-scenari](../../docs/plans/classi-credenziali-scenari.md), passo D.
Lo studente, con account o con credenziale di classe, può guardare anche gli
anni già fatti. L'insieme lo deriva `App\Domain\ClassiFrequentate` dalla
classe attuale: l'anno in corso con la sua sezione, gli anni precedenti al
livello di anno, le sezioni passate solo se note dallo storico del profilo
(`student_class_history`, scritto dal sistema quando l'amministratore cambia
la classe di uno studente), nessun anno futuro.

- Il gate `ContentVisibilityPolicy::studyListFilters` riceve la classe
  chiesta dall'URL: se è una classe frequentata sposta lo scope lì e marca il
  frammento `archivio`; altrimenti riporta alla classe propria. La libertà è
  nel selettore, mai nell'URL.
- Nell'archivio le verifiche restano fuori (`content_type <> 'verifica' OR
  archive_visible = 1`): un docente le riusa con la classe più giovane. La
  singola verifica si marca «visibile anche dopo l'anno» nel modale del
  contenuto (`teacher_content_data.archive_visible`, migrazione 104, che
  ricrea la vista a colonne esplicite della 079).
- Sidebar: per lo studente il selettore Classe torna visibile con «Terza · 3A
  (la tua classe)», «Seconda · 2B», «Prima»; l'indirizzo resta fisso.
- Scenario 3, anni passati: valgono gli incarichi dell'anno guardato
  (`TeacherSectionService::teachersForStudent` riceve la classe effettiva).
- Mappe: `MapsController::grantCanViewMap` applica la stessa regola (anno
  copre sezioni, anni passati ammessi). Verifiche condivise di Istituto
  (`/api/study/verifica/list`): solo per la classe propria o il suo anno.
- Non toccato: `ExerciseAccessPolicy` (tabella legacy `exercises`, che non
  conosce né il grant né l'archivio).

Test: `tests/Unit/Domain/ClassiFrequentateTest.php`, gate esteso in
`ContentVisibilityPolicyTest`, archivio in `tests/Integration/AnnoCopreSezioniTest.php`.

## Portachiavi di credenziali (2026-09-05)

Piano [classi-credenziali-scenari](../../docs/plans/classi-credenziali-scenari.md), passo C.
Una credenziale valeva per un docente e una sola era attiva: con più
colleghi sulla stessa classe lo studente faceva più accessi, uno alla
volta. Ora la sessione tiene un portachiavi (`App\Support\ClassAccessGrant`,
lista di grant con `credential_id`): lo studente inserisce una volta la
credenziale di ogni docente e le vede insieme.

- **Un perimetro per credenziale.** `ViewerContext::forKeychain` porta i
  grant; il gate emette `grants` (per ciascuno: docente, indirizzo,
  etichette, archivio) e il repository li mette in OR. Una credenziale
  delimitata a «3» autorizza l'anno e non la sezione; a «3A» la sezione e
  il suo anno; non delimitata segue la classe chiesta. Gli anni precedenti
  aprono l'archivio per ogni credenziale che li ammette.
- **Riverifica a ogni richiesta.** I grant si confrontano con
  `teacher_access_credentials_data`: disattivata, scaduta o eliminata, la
  credenziale cade e resta un avviso («chiedi al docente quella nuova»),
  mostrato nella pagina di accesso e nel widget della sidebar.
- **Scadenza.** Per default il 31 agosto dell'anno scolastico
  (`TeacherCredentialRepository::defaultExpiry`); `verify` e i QR la
  rispettano. Contatori `use_count` e `last_used_at`: numeri, mai chi.
- **QR.** Permanente (`qr_token`, rigenerabile: il vecchio smette di valere)
  e a tempo (`temp_token`, dieci minuti, da proiettare in classe), entrambi
  su `/accesso-classe/qr/{token}` con lo stesso limite di tentativi della
  password; più codici separati da virgola sono il «pacchetto di classe»
  (`/accesso-classe/pacchetto.svg`), un foglio che gira come le password.
- **«Ricorda su questo dispositivo».** Cookie `fm_keychain` firmato con
  `storage.signing_secret` (`App\Support\ClassKeychainCookie`), facoltativo e
  non spuntato, fino alla scadenza della credenziale; al ritorno il
  portachiavi si ricostruisce dal DB, quindi una credenziale revocata non
  rientra. Senza segreto configurato la casella non compare. Informativa 2.4.
- **Docente.** Sezione «Credenziali di classe» in `/area-docente/profilo`
  (`js/modules/features/teacher-credentials.js`): crea, spegni, nuova
  password (l'username resta), scadenza, QR, codice a tempo, nuovo QR.
- Migrazione 105 (colonne e vista `teacher_access_credentials` ricreata).
  Niente estensioni del browser: bloccate sui PC della scuola, assenti su
  Chrome per Android, onerose per un software rivolto a minori.
- **Scenario 1 (19/9/2026, corretto il 20/9).** C'è solo l'autore, quindi «la
  credenziale di un altro docente» non si propone: la barra tiene la scritta
  «Credenziale del docente, nessun account» senza «➕ Aggiungi», e
  `/accesso-classe`, col portachiavi già aperto, intitola «➕ Aggiungi
  un'altra credenziale» invece di «la credenziale di un altro docente». Lo
  decide `DeploymentScenario::consentePiuDocenti()`.
  **Il modulo però c'è in ogni scenario.** Lo scenario dice quanti docenti ci
  sono, non quante credenziali: il portachiavi ne tiene più d'una anche dello
  stesso docente (`ClassAccessGrant::add()` deduplica per `credential_id`, non
  per docente), e sono legittime — la classe e il gruppo di recupero, o quella
  rifatta dopo una fuga mentre la vecchia è ancora valida. Nasconderlo lasciava
  una pagina col portachiavi, «Esci», «Vai ai materiali» e il QR del pacchetto,
  e nessun campo dove digitare: un vicolo cieco, e l'unica strada restava il QR.
  Il link della barra resta legato allo scenario perché parla di un altro
  docente; la pagina, no.

Test: `tests/Unit/Support/ClassKeychainCookieTest.php`,
`tests/Unit/Repositories/TeacherCredentialExpiryTest.php`, gate in
`ContentVisibilityPolicyTest`, `tests/Integration/PortachiaviTest.php`.

## Un'informativa per scenario (2026-09-06)

Fino a qui `informativa.md` copriva insieme gli scenari 1 e 2 e non
dichiarava per quale valesse: lasciava in dubbio se le iscrizioni fossero
aperte e non nominava fra gli interessati gli studenti con credenziale di
classe. Ora `DeploymentScenario::informativaFile` serve tre testi:

| Scenario | File | Titolare | Interessati |
|----------|------|----------|-------------|
| 1 personale | `docs/privacy/informativa-personale.md` (1.0) | il gestore | studenti con credenziale di classe (nessun dato identificativo), visitatori, contatti |
| 2 colleghi | `docs/privacy/informativa.md` (2.5) | il gestore | docenti iscritti, studenti con credenziale, amministratori |
| 3 Istituto | `docs/privacy/informativa-istituto.md` (1.1, modello) | l'Istituto | docenti, studenti con account, genitori, amministratori |

Ogni testo dichiara in testa lo scenario per cui vale e rimanda agli altri.
I consensi (`gdpr.text_version`) seguono `informativa.md`, il solo testo che
un utente accetta alla registrazione fuori dallo scenario 3.

*Superato il 23/9/2026 (revisione architetturale, DOC-19).* La riga qui sopra
lasciava gli scenari 1 e 3 con consensi che citavano la versione di un testo
che l'utente non aveva visto (2.16 contro 1.2 e 1.3). Adesso i consensi
registrano la versione del testo servito nello scenario attivo, letta dal
suo file (`DeploymentScenario::versioneInformativa()`); `gdpr.text_version`
e `GDPR_TEXT_VERSION` non esistono più, e `check-legal-versions.mjs` guarda
tutti e tre i file.

## Le rotte dello studio per l'ospite (2026-09-14)

La correzione del 4 settembre (sopra) insegnava ai controller a servire
l'ospite con la credenziale, ma le rotte che li raggiungono stavano nel gruppo
`auth` + `role:student` fin dal primo import: il router rispondeva 401 alle API
e mandava al login la pagina prima di arrivare ai controller, e nessuna prova
passava dal router. La modalità con la credenziale continuava a non mostrare
niente. Misurato con la suite end-to-end il 13/9 (fase 3 di
[[decisions/ADR-037-pubblicazioni-e-copie]]), corretto il 14/9 su richiesta
dell'utente.

- **Il gruppo `studio`** in `routes/web.php` raccoglie le rotte di sola lettura
  con cui si studia: le pagine `/studio/{type}/{ind}/{cls}/{subj}[/{topic}]`,
  `/api/study/topics.json`, `content.json`, `content/{id}.json`,
  `verifica/list`, `header-page.json`, `related-verifiche.html`,
  `/api/sidebar/config` e la lettura dalla cache `GET /tikz/render`.
- **`StudioMiddleware`**: chi ha un account passa alle condizioni di prima
  (`auth` e `role:student`, con i confinamenti a cambio password e 2FA); senza
  account passa solo chi ha nel portachiavi almeno una credenziale valida,
  riverificata sul database a ogni richiesta (attiva, non scaduta, non
  cancellata). Gli altri ricevono 401 in JSON o il rinvio al login, come prima.
- **Resta chiuso all'ospite** tutto ciò che scrive o compila (`POST
  /tikz/render`, `/tex/format`, workspace) e lo studio legacy su `exercises`:
  se il primo segmento non è un tipo valido, `ContentStudyController` passa a
  `ExerciseStudyController` solo a chi ha un account.
- **La barra laterale**: `/api/sidebar/config` risponde all'ospite con le
  sezioni dell'istituto della credenziale, come la barra disegnata dal server;
  il client monta i controlli per rinominare le categorie solo a docenti e
  amministratori.
- **Nessun dato in più**: `AccessLogMiddleware` scrive solo per chi ha un
  account, e il registro delle operazioni del Kernel copre già tutte le
  richieste, pubbliche comprese. Informativa e registro non cambiano: la
  modalità la descrivono già.

Prove: `StudioConCredenzialeTest` (5: senza account né credenziale no; con una
credenziale valida sì, spenta, scaduta o cancellata no; con un account le porte
di prima; da ospite la pagina legacy non esiste e la barra prende la scuola
della credenziale; il gruppo è di sola lettura e il suo alias è registrato,
perché il Kernel salta in silenzio un middleware che non conosce). Sette
mutazioni del middleware, del gruppo e dei due controller le fanno diventare
rosse. End-to-end: `pubblico/studio-con-credenziale.spec.js` (il pubblicato sì
e la bozza no, dall'API e dalla pagina; 401 senza credenziale, dopo l'uscita e
con la credenziale spenta dal docente) e la seconda prova di
`verifiche/dove-vale-verifiche.spec.js` (la verifica compare alla classe solo
mentre è pubblicata).

## Che cosa vede chi studia (2026-09-19)

Misurato in locale e in produzione, da ospite con la credenziale di classe;
corretto il 19/9/2026 con le scelte dell'utente.

- **Il selettore delle materie.** Mostrava tutto il vocabolario attivo della
  scuola: in produzione, alla credenziale di una terza, diciassette materie,
  quindici senza nessun materiale; e senza una materia scelta ogni pannello
  chiedeva i contenuti una volta per materia. Adesso, per lo studente con
  account e per l'ospite con la credenziale, solo le materie con almeno un
  materiale che può vedere (`App\Services\Study\MaterieConMateriali`),
  calcolate con lo stesso gate dell'elenco: per ogni materia una
  `TeacherContentRepository::search` con i filtri di
  `App\Services\Study\ChiStudia` (estratto da `ContentStudyController`
  senza cambiare comportamento) e `limit 1`. Nessuna query parallela: una
  `SELECT DISTINCT` dovrebbe ripetere il perimetro e leggere la materia della
  pubblicazione, e prima o poi direbbe altro dall'elenco. Cache per richiesta
  e in sessione per 60 secondi, la finestra di `topics.json`. Senza nessuna
  materia, un avviso al posto del selettore. Al cambio di classe (anni
  frequentati) il selettore si ricalcola da `GET /api/study/materie.json`
  (gruppo `studio`) con `js/modules/features/materie-di-chi-studia.js`.
  Docenti e amministratori tengono il selettore delle loro materie.
  Due cose che il filtro si è portato dietro, corrette il 20/9/2026:
  - **la materia dell'URL non si perde.** Arrivando da segnalibro, o con F5,
    su una pagina di studio di un anno già fatto, `js/fm-url-state.js` cambia
    la classe (il selettore si ricalcola, asincrono) e subito dopo prova a
    scegliere la materia contro le opzioni ancora vecchie, dove quella
    dell'URL non c'è più. Adesso lascia il segno `data-fm-valore-atteso`, che
    `materie-di-chi-studia.js` legge e consuma una volta sola. Senza, la barra
    tornava a «Scegli la materia:» e i pannelli chiedevano i contenuti di
    tutte le materie della classe.
  - **il ripiego non è muto.** Se il calcolo fallisce resta il vocabolario
    intero — cioè il difetto di prima — e `MaterieConMateriali::perLaBarra()`
    scrive l'anomalia `materie_di_chi_studia_non_filtrate`, che
    `tools/ops/diagnostica.php` legge a timer. `filtrate: false` toglie alla
    barra il segno `data-fm-materie-di-chi-studia`, così i pannelli non si
    fidano di un elenco non calcolato.
- **«Un altro docente».** Vedi «Portachiavi», punto «Scenario 1».
- **I comandi di modifica.** Il «+» di BES/DSA e Risorse nasceva per
  chiunque (`risdoc-sidepage.js`), e `section-edit-mode.js` collegava a
  chiunque le azioni sulle voci e la finestra di creazione. Adesso si danno
  solo con il segno di permesso `.js-edit-section` e `body.fm-can-edit`;
  le chiamate ai modelli e alle istanze del docente non partono più per chi
  studia. Le scritture erano già chiuse dal server (401 a chi non ha un
  account, 403 allo studente con account) e la regola adesso è scritta:
  `tests/Unit/Core/CoperturaRuoliTest.php`, che ha trovato cinque `PUT` delle
  preferenze del docente nel gruppo degli studenti (spostate). Per i docenti
  il «+» si vedeva solo dopo ✎, come dicevano i commenti: la regola che lo
  nascondeva stava in un layer del CSS che perdeva contro `.fm-btn`.
  **Corretto il 20/9/2026, per scelta dell'utente:** il «+» si nasconde a chi
  studia, non al docente — pretendere ✎ prima voleva dire due clic per ogni
  contenuto nuovo. La difesa non era comunque quella regola: il bottone nasce
  solo dove c'è il segno di permesso, e a chi studia non arriva nel DOM. Le
  azioni della singola voce (✎🗑👁📥) restano dentro la modifica.

Informative e registro non cambiano: nessun dato nuovo, nessun registro in
più; le richieste per pannello diminuiscono.

Prove: `tests/Integration/MaterieDiChiStudiaTest.php`,
`tests/Integration/AltroDocentePerScenarioTest.php`,
`tests/Unit/Support/DeploymentScenarioTest.php`,
`tests/Unit/Core/CoperturaRuoliTest.php`,
`tests/Unit/Services/MaterieConMaterialiRipiegoTest.php` (il ripiego che parla),
`tests/js-unit/materie-di-chi-studia.test.js` (anche la materia dell'URL),
`tests/js-unit/risdoc-sidepage-permessi.test.js`; end-to-end
`pubblico/cosa-vede-chi-studia.spec.js`, `pubblico/accesso-classe-portachiavi.spec.js`
(scenario 1), `risdoc/modifica-per-sezione.spec.js` (dal 20/9: il «+» subito, le azioni delle voci dopo ✎).

## Scenario e modo scritti a mano (2026-09-23)

Il punto 1 della Decisione dice che ogni cambio di scenario allinea il modo
legacy. È vero per il pannello: lo fa `DeploymentScenario::persist()`. Uno
scenario scritto a mano in `DEPLOYMENT_SCENARIO`, o un file di
`storage/config` modificato, lascia il modo com'era: con `colleagues` e
`institute`, per esempio, l'informativa segue lo scenario e il contatto del
DPO e il Titolare seguono il modo. E lo scenario 3 scritto a mano salta la
dichiarazione `INSTANCE_ACN_QUALIFIED` che il pannello chiede (rilievo A-8
della revisione del 23/9).

Non si allinea da sé fuori dal pannello: un allineamento in lettura
sceglierebbe in silenzio quale dei due valori vale. Con `APP_ENV=production`
il container si rifiuta di partire se la coppia non combacia (scenario 3 con
modo `institute`, gli altri con `single`) o se lo scenario 3 non ha la
dichiarazione ACN, e dice da dove arriva ciascun valore
(`docker/verifica-avvio.php`; le guardie in [[environment-variables]]). Prova
nei due versi: `tests/ops/verifica-avvio.test.sh`, anche con i file del
pannello.

## Conseguenze

- Il pannello non ha più "SINGLE/INSTITUTE" come concetto primario: mostra lo
  scenario, cosa cambia, i documenti attivi, e un form per scenario con
  motivazione.
- Lo scenario 1 resta bloccato finché ci sono altri utenti attivi (stessa
  protezione del vecchio down-switch): non si fingono inesistenti account che
  esistono.
- Nessuna migrazione dati: cambiare scenario non tocca account né contenuti.
- Test: `tests/Unit/Support/DeploymentScenarioTest.php`.
