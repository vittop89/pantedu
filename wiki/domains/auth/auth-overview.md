---
tags:
  - documentazione/architettura
  - dominio/auth
date: 2026-09-04
tipo: architettura
status: finale
aliases: ["auth", "autenticazione", "2fa", "credenziale di classe"]
cssclasses: []
---

# Dominio: auth

> [!abstract] Scopo
> Login in due passaggi, secondo fattore (app o email), recupero password, registrazione secondo lo scenario, ruoli, credenziale di classe per gli studenti senza account, scaffold SPID/CIE.

## Confini del dominio

- **In**: credenziali, codici 2FA, token di recupero, credenziali di classe, richieste HTTP
- **Out**: sessione autenticata o grant di classe, redirect, JSON user info

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| AuthController | `app/Controllers/AuthController.php` | `showLogin`, `login` (password → `pending_2fa` o sessione), `show2fa`, `verify2fa`, `logout`, `csrf`, `userInfo`, `showClassAccess`, `classAccessLogout` |
| TotpController | `app/Controllers/TotpController.php` | `/me/2fa`: `page`, `setup`, `enable`, `setupEmail`, `enableEmail`, `disable` |
| PasswordResetController | `app/Controllers/PasswordResetController.php` | `/password/forgot|reset` |
| UserProfileController | `app/Controllers/UserProfileController.php` | cambio password (anche forzato) |
| RegistrationController | `app/Controllers/RegistrationController.php` | `showForm`, `submit`, `listPending`, `approve`, `reject` |
| TeacherCredentialController | `app/Controllers/TeacherCredentialController.php` | credenziali di classe: `index`, `create`, `labelPreview`, `relabel`, `delete`, `toggle`, `rotate`, `expiry`, `studentLogin`, `studentLogout`, `studentStatus` |
| TeacherCredentialRepository, EtichettaCredenziale | `app/Repositories/TeacherCredentialRepository.php`, `app/Domain/EtichettaCredenziale.php` | regole di username, password e scadenza; etichetta composta dal server (ADR-044) |
| SpidController, CieController | `app/Controllers/Auth/` | scaffold: 503 finché non esiste la registrazione come SP (`docs/plans/d2-spid-cie-integration.md`) |
| Auth | `app/Core/Auth.php` | `attempt()` con `establishSession: false` per il login in due passaggi, `establishSession()`, claim in sessione, istituto corrente |
| TotpService, EmailSecondFactor, TwoFactorPolicy, QrCode | `app/Services/Security/` | TOTP RFC 6238, codici via email (`two_factor_email_codes`), politica per ruolo, QR generato in locale |
| PasswordResetService, PasswordPolicy, HibpService | `app/Services/Security/` | link monouso (`password_resets`), regole password, controllo compromissioni (fail-open) |
| TwoFactorEnforcement | `app/Support/TwoFactorEnforcement.php` | modo e ruoli obbligati, override dal pannello |
| ClassAccessGrant | `app/Support/ClassAccessGrant.php` | grant in sessione: `current()`, `isActive()`, `teacherId()`, `instituteId()`, `mapContext()` |
| RegistrationService, RegistrationPolicy, RegistrationMailer | `app/Services/` | pipeline di iscrizione, classi ammesse (ADR-028), email |
| StudentRegistration, DeploymentScenario | `app/Support/` | modalità dati studente (completa/ridotta/anonima) e scenario che decide chi può iscriversi |
| UserRepository, User, Role | `app/Repositories/UserRepository.php`, `app/Domain/User.php`, `Role.php` | accesso a `users`, entità, enum ruoli |
| BlockList, RateLimiter, WafBruteforceGuard | `app/Services/BlockList.php`, `RateLimiter.php`, `app/Services/Waf/WafBruteforceGuard.php` | blocchi legacy JSON, rate limit di sessione, lockout per username e ban IP |
| AclPolicy, TeacherCapabilityPolicy | `app/Services/AclPolicy.php`, `TeacherCapabilityPolicy.php` | super-admin, ownership, capability per docente |

## Flusso login (2026-09-01)

```mermaid
flowchart TD
    A[POST /login + csrf + rate:login,10] --> B[Auth::attempt establishSession=false]
    B --> C{Rate limit / blocchi / password}
    C -- fail --> D[redirect /login?error=…]
    C -- ok --> E{2FA richiesta per ruolo o attivata?}
    E -- sì --> F[Session pending_2fa → GET /login/2fa]
    F --> G[POST /login/2fa: TotpService o EmailSecondFactor]
    G -- codice errato --> F
    G -- ok --> H[Auth::establishSession: regenerate id + claim]
    E -- no --> H
    H --> I{must_change_password? must_enrol_2fa?}
    I -- sì --> J[/me/change-password?force=1 oppure /me/2fa?force=1]
    I -- no --> K[landing: docente → /area-docente/dashboard, admin → /admin/dashboard]
```

Dal 23/9/2026 un codice dell'app vale una volta (`users.totp_last_counter`), e
una sessione aperta rilegge ruolo, account attivo e super-admin al più ogni
`Auth::CLAIMS_TTL_SECONDS` (60 s) passando da `AuthMiddleware`: chi viene
disattivato esce entro un minuto, e chi cambia ruolo o flag di super-admin
pure, così al login dopo ripassa dal secondo fattore che il ruolo nuovo chiede. Le regole e le prove stanno in
[[security-notes]] («Sessione e login», «Secondo fattore»).

## Credenziale di classe (scenari 1 e 2)

Il docente crea una credenziale per classe (`/api/teacher/credentials`,
tabella `teacher_access_credentials`). Lo studente, senza account, la
inserisce su `/accesso-classe` → `POST /api/access/student-login` (csrf +
rate) → `ClassAccessGrant` in sessione → i soli contenuti pubblicati da quel
docente per quella classe (`ContentStudyController`, `MapPermissionService`
leggono il grant). `/accesso-classe/esci` lo rimuove. Negli scenari
`personal` e `colleagues` non esistono account studente
([[decisions/ADR-032-deployment-scenarios]]).

### Regole (dal 19/9/2026, [[decisions/ADR-044-etichetta-e-regole-delle-credenziali]])

Le regole stanno in costanti di `TeacherCredentialRepository` e di
`App\Domain\EtichettaCredenziale`: la vista del profilo le stampa negli
attributi dei campi e nel testo d'aiuto, il server le applica con lo stesso
testo. Non si riscrivono altrove: chi le cambia, le cambia lì.

| campo | regola | codice del rifiuto |
|---|---|---|
| username | 3–64 caratteri: lettere senza accenti, cifre, `.` `-` `_`; **unico su tutta la piattaforma** (indice `uq_tac_access_username`, migrazione 134); maiuscole e minuscole equivalenti, anche all'accesso | `invalid_username`, `username_in_uso` |
| password | 6–64 caratteri ASCII stampabili, senza spazi (sotto i 72 byte di bcrypt); vale alla creazione e alla rotazione | `weak_password`, `password_troppo_lunga`, `password_non_valida` |
| scadenza | vuota = 31 agosto dell'anno scolastico; altrimenti da oggi in poi, alla creazione e dalla riga; riportando in vita una scaduta si ricontrolla l'etichetta | `invalid_expiry`, `scadenza_passata`, `etichetta_in_conflitto` |
| aggiunta | facoltativa, 1–12 caratteri: lettere senza accenti, cifre, `-`; portata in maiuscolo | `aggiunta_non_valida` |

Username e aggiunta portano l'invito a non usare nomi di persone. Un errore
imprevisto del database resta nel registro del server: al client arriva solo
`persist_failed`.

### Etichetta

La compone il server: `{CLASSE}_{INDIRIZZO}_{MATERIE}[_{AGGIUNTA}]`.

- CLASSE e INDIRIZZO sono quelli del perimetro risolto («3_SCI», «3B_SCI»); una
  credenziale per tutte le classi comincia con `TUTTE`, una sul solo indirizzo
  con l'indirizzo.
- MATERIE: sigle scelte dal docente fra le sue materie spuntate nell'istituto
  della credenziale (`TeacherSubjectService::forTeacher`), tutte proposte nel
  modulo, **in ordine alfabetico**, separate da `-`; una sigla non spuntata è
  `materia_non_tua`. Se la credenziale non ha istituto (le righe nate prima del
  13/9/2026) valgono le materie dell'istituto in cui il docente sta lavorando.
- Un `label` mandato dal client si ignora.
- Fra le credenziali non scadute dello stesso docente l'etichetta non si ripete
  (`etichetta_gia_usata`): si distingue con l'aggiunta. Rimettere una scadenza
  futura a una scaduta ricontrolla l'etichetta (`etichetta_in_conflitto`).
- Le parti stanno nelle colonne `materie` (sigle separate da virgola; `NULL` =
  etichetta libera di prima del 19/9/2026) e `aggiunta`.
- Anteprima: `GET /api/teacher/credentials/anteprima-etichetta`. Ricomposizione
  dalla riga: `POST /api/teacher/credentials/{id}/etichetta` («Rigenera
  etichetta»).
- `ClassAccessGrant::revalidate` rilegge etichetta e nomi delle materie a ogni
  richiesta: una rigenerazione arriva anche agli studenti già entrati. Nel
  portachiavi l'etichetta ha un `title` con i nomi delle materie del catalogo
  della scuola.

## Registrazione

`/register` è aperta solo negli scenari che lo prevedono
(`DeploymentScenario::allowedRegistrationRoles()`); l'indirizzo si sceglie
dal curriculum della scuola; le classi ammesse sono un'allowlist
(`registration_allowed_classes`); i dati dello studente seguono la modalità
completa/ridotta/anonima; l'admin approva o rifiuta (`/admin/registrations`,
super-admin, con `sadmin_audit`). Consenso genitoriale per i minori
(`/parent-consent/{token}`, `ParentConsentService`).

Le domande in attesa stanno nel file `auth.paths.registrations`
(`registrations.json`), non nel database; l'account nasce nella tabella `users`
all'approvazione, e solo lì. Dal 24/9/2026
(`App\Services\Gdpr\ConservazioneDelleIscrizioni`):

- una domanda non decisa si cancella dopo 30 giorni
  (`retention.pending_registration_days`), dal giro notturno di
  `pantedu-gdpr-retention` o dalla prima scrittura del file, quale arrivi prima;
- la domanda non tiene IP né User-Agent: il verbale di accettazione dei Termini
  degli account nati da una domanda ha data e versioni, senza IP;
- dopo la decisione nel file non resta niente: esito, chi ha deciso e
  motivazione stanno nel registro delle attività (`registration_approved`,
  `registration_rejected`);
- nome utente ed email si confrontano con la tabella `users` e l'approvazione è
  un `INSERT` semplice: una domanda col nome utente di un account esistente
  non ne prende più la password (prima c'era `ON DUPLICATE KEY UPDATE`, e il
  controllo guardava solo la copia in `users.json`);
- `users.json` non la scrive e non la legge più nessuno (nemmeno il cambio
  password, che senza database risponde `database_unavailable`): il giro
  notturno cancella la copia rimasta, anche se non si legge (lo segnala quella
  notte, esce con 1, e la notte dopo non c'è più);
- chi legge le domande legge solo quelle nel termine
  (`ConservazioneDelleIscrizioni::domandeInTermine()`): l'elenco da decidere,
  il cruscotto dell'infrastruttura, il riepilogo dell'amministratore e i
  controlli di nome utente ed email. Una domanda scaduta che il giro non ha
  ancora cancellato non si approva né si rifiuta (`registration_expired`);
- l'approvazione è tutta o niente: account, verbale dei Termini e scuole del
  docente si confermano nel database solo dopo che la domanda è uscita dal
  file. Se la scrittura del file fallisce non resta niente, e l'amministratore
  può riprovare; l'email al genitore di un minore, l'evento
  `registration_approved` e l'email all'iscritto vengono dopo la conferma;
- il file si scrive con un temporaneo dal nome unico nella stessa cartella
  (`tempnam`) e una rinomina; i temporanei abbandonati da più di un'ora li
  toglie il giro notturno;
- il modulo non dice quali account esistono. Dopo una domanda si torna a
  `/register?ok=1`, senza il nome utente assegnato, che arriva nell'email di
  approvazione. Un'email già usata da un account o da una domanda in attesa
  riceve la stessa risposta di una domanda nuova, e la spiegazione va solo
  all'indirizzo: con un account, come recuperare la password; con una domanda,
  che vale quella. Al più un avviso all'ora per indirizzo: il secchio
  `avviso_iscrizione:<sha256 dell'email>` in `rate_limits`, senza IP. L'email
  si controlla dopo l'hash della password, perché la risposta non arrivi prima.

## Role hierarchy

```
guest(0) < student(10) < teacher(40) < administrator(100)
```

Il collaboratore (50) non esiste più dal 2026-09-15: la sua zona aveva solo
tre indirizzi vecchi che rispondono 410 (ora nella zona `admin`) e un gruppo
di API vuoto. La migrazione 128 lo toglie dai ruoli che il database accetta.

`is_super_admin`: flag ortogonale che apre la zona admin e le operazioni di
governo; nei registri compare come `super_admin` (`Auth::actorRole()`). Lo
decide solo `AclPolicy::isSuperAdmin()` (`Auth::isSuperAdmin()` delega, dal
23/9/2026).

## API pubblica

- `Auth::check()`, `attempt()`, `establishSession()`, `role()`, `hasRole()`, `hasAccess()`, `isSuperAdmin()`, `actorRole()`, `refreshCurrentUserClaims()`, `chiudiSeRevocata()`, `logout()`; `Auth::CLAIMS_TTL_SECONDS`
- `AclPolicy::isSuperAdmin()`
- `TwoFactorPolicy::verifyTotp()` (consuma il codice), `TotpService::matchingCounter()`
- `ClassAccessGrant::current()`, `isActive()`, `teacherId()`
- `TwoFactorEnforcement::isRequiredFor(role)`

## Dipendenze

- **core**: Session, Config, Database, Csrf, Response
- **gdpr**: `TosAcceptanceService` (gate ToS dopo il login), `ConsentService`
- **waf**: `WafBruteforceGuard`, `WafSecurityRepository::isCredentialBlocked`

## Link correlati

[[security-notes]] · [[routing-and-api]] · [[user-flows]] · [[domains/core/core-overview]] · [[decisions/ADR-032-deployment-scenarios]] · [[decisions/ADR-044-etichetta-e-regole-delle-credenziali]]
