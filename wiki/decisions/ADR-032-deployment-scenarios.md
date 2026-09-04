---
tags:
  - decisions
  - architecture
  - deployment
  - gdpr
date: 2026-09-03
status: accettato
deciders: {{OPERATORE_NOME}}
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
  per id solo ciò che è davvero pubblico (`publish_public` del super-admin):
  prima bastava l'id per qualunque contenuto pubblicato di qualunque docente.
- `MapsController::signedUrl`: vista (mai copia) delle mappe pubblicate del
  docente della credenziale per la sua classe.
- layout e sidebar: curriculum dell'istituto della credenziale, sezioni
  visibili agli studenti, endpoint `/api/study/*`, banner con «Esci»
  (`POST /accesso-classe/esci`).
- `StudyHeaderController` e `VerificaController::listForStudent` non
  rispondono più 401 all'ospite con credenziale (intestazione del docente;
  lista verifiche vuota: la condivisione fra colleghi non è una pubblicazione).

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
condivisione fra colleghi usata come pubblicazione — da decidere.

## Conseguenze

- Il pannello non ha più "SINGLE/INSTITUTE" come concetto primario: mostra lo
  scenario, cosa cambia, i documenti attivi, e un form per scenario con
  motivazione.
- Lo scenario 1 resta bloccato finché ci sono altri utenti attivi (stessa
  protezione del vecchio down-switch): non si fingono inesistenti account che
  esistono.
- Nessuna migrazione dati: cambiare scenario non tocca account né contenuti.
- Test: `tests/Unit/Support/DeploymentScenarioTest.php`.
