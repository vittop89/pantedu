---
tags:
  - documentazione/adr
date: 2026-09-14
tipo: adr
status: accettato
aliases: ["ADR-040", "amministratore di istituto", "institute_admin", "admin_institute_id", "zona istituto"]
cssclasses: []
---

# ADR-040 — L'amministratore di istituto: un ruolo suo, e solo nello scenario 3

## Stato

**ACCETTATO** dall'utente il 14 settembre 2026: «Amministratore istituto
approvato, ma solo per scenario 3».

**Fase 1 eseguita**: l'identità, la zona, lo scenario, la creazione e i
confronti allineati. Le funzioni da aprire all'amministratore di istituto
(fase 2) sono elencate più sotto: ognuna tocca dati personali dell'Istituto, e
si apre una alla volta, su decisione.

## Contesto

Misurato il 14 settembre 2026.

- **Il wizard creava un ruolo che nessuna zona conosceva.**
  `/admin/institutes/new` inseriva l'amministratore iniziale con
  `role='admin'` e `admin_institute_id`. In `app/Config/roles.php` nessuna zona
  contiene `admin`: quell'utente non entrava da nessuna parte.
- **Nel frattempo `Role::tryFromString('admin')` rispondeva `ADMINISTRATOR`.**
  Il codice che decide la visibilità dei contenuti (`ContentStudyController`,
  `TeacherContentController`, `ContentExportController`) lo avrebbe trattato
  da amministratore della piattaforma, mentre le zone lo trattavano da
  nessuno. Due risposte diverse alla stessa domanda.
- **Anche `Auth` confrontava `'admin'`**, in `currentInstitute`,
  `setCurrentInstitute` e `isAdminOfInstitute`, e così le query dei termini
  d'uso pendenti (`role IN ('teacher','admin')`).
- **La guida diceva un'altra cosa ancora.** Per
  `wiki/domains/admin/admin-overview.md` l'amministratore di istituto era
  `role=administrator` con `admin_institute_id`. Fatto così, sarebbe entrato
  nella zona `admin`. Nel gruppo principale di `routes/web.php` (`role:admin`)
  **60 rotte su 90** non passano dal middleware del super-amministratore;
  qualcuna lo controlla nel controller, le altre no. Fra queste:
  - utenti: attiva, cambia ruolo, cancella, **di tutta la piattaforma**;
  - sicurezza: blocchi e configurazione;
  - file: salva e cancella;
  - stampa, adozioni, file delle verifiche, generazione di hash.

  Con un elenco di rotte ammesse, ogni rotta nuova del gruppo sarebbe stata
  aperta anche a lui finché qualcuno non se ne ricordava.
- **In produzione** c'è un solo amministratore (il super-amministratore),
  nessuna riga `admin`, nessun `admin_institute_id` valorizzato. Stesso
  quadro sui database di sviluppo e di prova. Sulla tabella `users` c'è un solo
  vincolo CHECK, quello sul JSON dei codici di recupero.

## Decisione

### 1. Un ruolo suo: `institute_admin`

`App\Domain\Role::INSTITUTE_ADMIN`, «Amministratore di istituto», livello 90 in
`roles.php`. Non insegna (`canTeach()` falso) e non è amministratore della
piattaforma (`isAdmin()` falso).

### 2. Una zona sua: `istituto`

`'istituto' => ['institute_admin', 'administrator']`. L'amministratore di
istituto non sta in nessun'altra zona oltre a quella pubblica: non in
`student`, `teacher`, `admin` (e non stava in `collaborator`, zona tolta il
15/9/2026 con il suo ruolo). Quindi **tutto quello che oggi
è riservato a quelle zone gli resta chiuso per costruzione**, comprese le rotte
che verranno aggiunte domani. Le sue funzioni si aprono dichiarandole nella
zona `istituto`, non togliendole da un'altra.

### 3. Solo nello scenario 3

Nello scenario 3 il Titolare è l'Istituto, e l'Istituto ha chi lo amministra.
Negli scenari 1 e 2 non c'è un Istituto Titolare, e il ruolo non ha senso.

- **L'accesso si rifiuta** (`Auth::REASON_SCENARIO`) se lo scenario non è il 3.
- **Le zone si negano** anche a una sessione aperta prima che lo scenario
  cambiasse: `Auth::hasAccess` non concede niente a `institute_admin` fuori
  dallo scenario 3.
- **Il wizard crea l'amministratore iniziale solo nello scenario 3.** Negli
  altri crea l'istituto e basta, e lo dice.

### 4. L'identità la garantisce il database

Migrazione 125:
- le righe `admin` diventano `institute_admin` se hanno un istituto e non sono
  super-amministratori, `administrator` se lo sono;
- le altre, un ruolo senza significato, diventano docenti non attivi (misurate:
  zero);
- il vincolo `chk_users_ruolo`: il ruolo è uno di `student`, `teacher`,
  `collaborator`, `administrator`, `institute_admin`. Dal 15/9/2026 (migrazione
  128) senza `collaborator`, ruolo tolto;
- due trigger (`trg_users_amm_istituto_bi`, `_bu`): `admin_institute_id` c'è
  **se e solo se** il ruolo è `institute_admin`, e un amministratore di
  istituto non è super-amministratore.

Un codice che scrivesse `admin`, o un amministratore di istituto senza
istituto, prende un errore dal database invece di creare un utente a metà.

**Trigger e non CHECK, per il secondo**: MariaDB non accetta in un CHECK una
colonna la cui chiave esterna la modifica (errore 1901, provato sul database di
prova), e `fk_users_admin_institute` ha `ON DELETE SET NULL`. La chiave esterna
resta com'è. Se un istituto viene cancellato, la cascata toglie l'istituto al
suo amministratore (i trigger non scattano sulle cascate), e un
`institute_admin` senza istituto non amministra niente: `Auth` risponde di no a
ogni domanda d'ambito.

### 5. L'alias `admin` non esiste più

`Role::tryFromString('admin')` risponde `null`, `Role::fromString('admin')`
lancia. L'alias dava i poteri dell'amministratore della piattaforma a un valore
che nessuna zona riconosceva.

### 6. Il resto, allineato

- **`Auth`**: ambito d'istituto, cambio d'istituto e `isAdminOfInstitute` usano
  `institute_admin`, e fuori dallo scenario 3 rispondono di no.
- **Secondo fattore**: obbligatorio anche per l'amministratore di istituto,
  come per gli amministratori.
- **Termini d'uso pendenti**: si contano per docenti, amministratori e
  amministratori di istituto, esclusi i super-amministratori, che il
  middleware esenta.
- **Cambio di ruolo** (`/api/admin/users/{id}/role`): un amministratore di
  istituto non si trasforma in un altro ruolo da lì, perché il suo istituto
  resterebbe appeso. Si gestisce dalla pagina degli istituti.
- **Dopo l'accesso** atterra su `/istituto`, la sua pagina: chi è, quale
  istituto amministra, che cosa può fare oggi.

## Fase 2 — le funzioni, una alla volta

Da decidere con l'utente, perché ognuna mostra o modifica dati personali di
docenti o studenti dell'Istituto. Per ognuna:
- la rotta sta nella zona `istituto`;
- l'ambito passa da `Auth::isAdminOfInstitute($iid)`;
- una prova d'integrazione nei due versi: il proprio istituto sì, un altro no,
  lo scenario 2 no.

| funzione | oggi | che cosa serve |
|---|---|---|
| registrazioni dei docenti del proprio istituto | solo super-amministratore (IDOR chiuso con l'audit 25.R.31) | elenco e approvazione filtrati per istituto |
| elenco dei docenti del proprio istituto | non c'è | sola lettura, da `teacher_institutes` |
| classi ammesse all'iscrizione (ADR-028 §4) | dal pannello generale | la regola per istituto |
| dove si salvano le compilazioni dei modelli | `/admin/institutes/{id}/compilation-storage`, super-amministratore | la stessa azione, sul proprio istituto |
| profili di capacità dei docenti (ADR-028) | tabella senza istituto | una colonna `institute_id`, prima di aprirla |
| file delle verifiche dell'istituto | ramo `role_at_inst='admin'` di `VerificaFilesAdminController`, che nessuno ha | l'ambito per codice meccanografico |
| modalità delle sezioni dei docenti (ADR-041) | `/admin/institutes/{id}/sezioni-docenti`, super-amministratore | la stessa azione, sul proprio istituto |
| incarichi docente-sezione e materiali rimasti su sezioni senza incarico (ADR-041, punto 5) | «Sezioni e incarichi», super-amministratore | la stessa pagina, limitata al proprio istituto |

**Rimandata** (15/9/2026, scelta dell'utente: «Non ora»). Lo scenario 3 non è
attuabile a breve: richiede un'infrastruttura qualificata ACN o un'istanza
condotta dall'Istituto (nota al DPO del 4/9/2026, §8). Costruire adesso queste
funzioni vorrebbe dire codice non usato da tenere allineato.

**Quando si riprende, l'ordine proposto**, dal rischio più basso a quello più alto
e dalle funzioni da cui dipendono le altre:

1. elenco dei docenti del proprio istituto, in sola lettura;
2. registrazioni dei docenti, con l'approvazione;
3. classi ammesse all'iscrizione;
4. dove si salvano le compilazioni;
5. modalità delle sezioni e incarichi, con i materiali rimasti;
6. profili di capacità, dopo la migrazione che dà loro l'istituto;
7. file delle verifiche dell'istituto.

## Conseguenze

- **Nessun effetto in produzione oggi**: nessuna riga da convertire, e lo
  scenario non è il 3.
- **La guida dell'amministrazione cambia**: l'amministratore di istituto non è
  un `administrator` con un istituto.
- **Le prove**: gli ADR-032 e ADR-028 restano validi. Le prove della zona e
  dello scenario sono unitarie; quelle di vincoli, accesso e wizard sono
  d'integrazione, su MariaDB.
- **Nessuna prova end-to-end**: lo scenario è dell'intera istanza, e la suite
  condivide un database e un'istanza con tutte le altre prove. Cambiarlo per
  una prova le cambierebbe tutte.

## Riferimenti

- `app/Domain/Role.php`, `app/Config/roles.php`, `app/Core/Auth.php`
- `database/migrations/125_amministratore_di_istituto.sql`
- `app/Services/Institutes/AmministratoreDiIstituto.php`,
  `app/Controllers/Admin/AdminInstitutesController.php`
- `app/Controllers/IstitutoController.php`, `views/istituto/index.php`
- [[ADR-032-deployment-scenarios]] · [[ADR-028-institute-governance-teacher-capabilities]] · [[domains/admin/admin-overview]] · [[security-notes]]
