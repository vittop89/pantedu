---
tags:
  - documentazione/adr
  - dominio/core
date: 2026-09-04
tipo: adr
status: accettato
aliases: ["ADR-034", "helper condivisi", "Request::json", "Response::ok", "currentTeacherId"]
cssclasses: []
---

# ADR-034 — Corpo della richiesta, forma delle risposte e contesto docente hanno un solo helper

**Status**: accettato (2026-09-04). Nasce dalla revisione architetturale di
settembre 2026 (intervento P4, debito 21 in [[technical-debt]]).

## Contesto

I controller si erano portati dietro tre gesti ripetuti a mano:

- **leggere il corpo JSON** — `readJsonBody()` copiato in otto controller in
  quattro varianti, più 37 letture dirette di `php://input` con
  `json_decode(...) ?: []`;
- **rispondere** — tre forme di JSON (`{"ok": ...}` in 577 siti,
  `{"error": ...}` in 517, `{"success": ...}` in 26), e la scelta cambiava
  da file a file;
- **capire chi è il docente** — `teacherId()` privato in sedici controller,
  in tre varianti equivalenti (id di sessione, oppure risoluzione dallo
  username con una query per chiamata), più due varianti con semantica
  propria (ruolo obbligatorio, eccezione se assente).

Ogni copia era un posto in cui un difetto poteva vivere da solo, e un
nuovo controller partiva copiando l'ultima che l'autore aveva sotto mano.

## Decisione

Tre helper, nel nucleo, e una regola per il codice nuovo.

1. **`Request`** espone il corpo: `rawBody()` (letto una volta),
   `isJson()` (Content-Type dichiarato), `json()` (decodifica tollerante:
   `[]` se vuoto o non JSON, come faceva `?: []`) e `body()` (JSON se
   dichiarato, altrimenti i campi form, altrimenti il corpo se «sembra»
   JSON). Le API JSON usano `json()`; le rotte che accettano anche il form
   usano `body()`. Il costruttore accetta il corpo grezzo solo per i test.
2. **`Response::ok(array $data = [], int $status = 200)`** emette
   `{"ok": true, ...}` e **`Response::fail(string $error, int $status = 400,
   array $extra = [])`** emette `{"ok": false, "error": ..., ...}`. La chiave
   `ok` viene per prima; `$data` non può sovrascriverla.
3. **`TeacherContextResolver::currentTeacherId()`** dà l'id dell'utente
   autenticato (0 se nessuno): preferisce l'id in sessione e ricade sulla
   risoluzione dallo username solo per sessioni che non lo portano. Non fa
   controlli di ruolo: chi li vuole continua a usare
   `AuthHelpers::teacherUsernameOrThrow()` o `Auth::hasRole()`.

Regola: un controller nuovo non legge `php://input`, non compone a mano
`['ok' => ...]` e non ridefinisce `teacherId()`.

## Migrazione fatta il 2026-09-04

A parità di JSON emesso e di comportamento:

- sedici `teacherId()` privati rimossi (ContentExport, ContentPublish,
  ContentTemplate, Group, Institute, Quesito, TeacherCategoryLabel,
  TeacherContent, TeacherCredential, ImportBundle, TeacherDrawioLibrary,
  TeacherGitHub, TeacherRecovery, TeacherSyncCleanup, TeacherVerificaFiles,
  LatexShortcuts); `MapsController` (variante `?int`) e
  `ContentStudyController` delegano; restano con semantica propria
  `TikzRenderController` (solo ruolo docente) e `VerificaSharedHelpersTrait`
  (eccezione se non autenticato);
- quattro `readJsonBody()` identici rimossi (GeoGebraCatalog,
  LatexShortcuts, TeacherTemplate, TeacherWorkspace → `$req->body()`),
  `ShareGrantsController` delega a `Request`; quattordici letture inline di
  `php://input` → `$req->json()`; restano le varianti strette con limite di
  dimensione ed eccezioni (`PrintInfo`, `TeacherPrint`, il trait delle
  verifiche) e le letture di corpi binari o non JSON;
- 24 `Response::json(['ok' => true])` → `Response::ok()`; 234
  `Response::json(['ok' => false, 'error' => X], N)` su una riga →
  `Response::fail(X, N)`.

## Il 23 settembre 2026: un lettore solo, con un limite

La revisione architetturale del 23/9/2026 (A-52) ha trovato ancora quattro
`readJsonBody()` privati (PrintInfo, TeacherPrint, il trait delle verifiche,
ShareGrants), `AdminPrintController::readBody()` e una ventina di letture di
`php://input` che erano semplici decodifiche JSON; `json()` non aveva limite,
e un corpo troppo grande rispondeva 413 o 400 a seconda del controller.
Adesso:

- `json()`, `body()` e il nuovo `jsonObbligatorio()` prendono un limite di
  byte (predefinito `Request::LIMITE_CORPO`, 128 MB, il tetto di nginx);
  oltre, lanciano `App\Core\CorpoNonValido` con 413 `payload_too_large`,
  guardando prima `Content-Length`;
- `jsonObbligatorio()` è la lettura rigorosa che sostituisce le copie:
  vuoto → 400 `empty_payload`, non JSON o uno scalare → 400 `invalid_json`;
  `Request::decodificaJson()` applica la stessa regola a un campo di form
  (il `selection` di `/teacher/print`);
- l'eccezione porta la risposta: se il controller non la prende la dà il
  Kernel (`{"ok": false, "error": ...}` con lo stato), e un
  `catch (Throwable)` intorno alla lettura chiede lo stato con
  `CorpoNonValido::statoPer($e, $altrimenti)` invece di rispondere 400 a
  tutto;
- nessun controller legge più `php://input`: l'elenco del cricchetto di
  [[decisions/ADR-049-confini-degli-strati]] è vuoto. I corpi binari
  (il PDF di una verifica) passano da `rawBody()`.

## Cosa resta

Circa 500 risposte `['error' => ...]` senza `ok` e 26 `['success' => ...]`:
migrarle aggiunge la chiave `ok` (o la rinomina), quindi va fatto rotta per
rotta guardando chi le legge.

## Conseguenze

- Un difetto nella lettura del corpo o nella forma della risposta si
  corregge in un punto.
- `Request` con corpo iniettato rende testabili i controller JSON senza
  toccare `php://input`.
- Il contratto delle API non cambia: le migrazioni fatte emettono lo stesso
  JSON di prima, chiave per chiave.

## Riferimenti

- `app/Core/Request.php`, `app/Core/Response.php`,
  `app/Support/TeacherContextResolver.php`
- `tests/Unit/Core/RequestBodyTest.php`, `tests/Unit/Core/ResponseShapesTest.php`
- [[technical-debt]] voce 21; `docs/analysis/revisione-architetturale-2026-09.md` (A9, P4)
