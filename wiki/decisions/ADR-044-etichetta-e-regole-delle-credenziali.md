---
tags:
  - documentazione/adr
date: 2026-09-19
tipo: adr
status: accettato
aliases: ["ADR-044", "etichetta delle credenziali", "regole delle credenziali", "username unico"]
cssclasses: []
---

# ADR-044 — Credenziali di classe: etichetta composta dal server, regole scritte in un posto

## Stato

**ACCETTATO** dall'utente il 19 settembre 2026, seguendo le raccomandazioni
dell'analisi su due segnalazioni: «il modulo delle credenziali non dice le
regole di username e password» e «l'etichetta scritta dal docente nel
portachiavi può confondere». Nella stessa risposta l'utente ha chiesto
l'invito a non usare nomi di persone.

Completa [[decisions/ADR-032-deployment-scenarios]] (la credenziale di classe
negli scenari 1 e 2) e il piano classi-credenziali-scenari, passo C (il
portachiavi).

## Contesto

**Le regole.** Esistevano solo negli attributi dei campi e nel server. Il
modulo non le diceva: i segnaposto sparivano appena si scriveva, e il browser
rispondeva «Please match the requested format». Client e server non
coincidevano (misurato il 19/9/2026 sul server di sviluppo e in Chromium):

- il browser contava le unità UTF-16, e «🙂🙂🙂» passava il minimo di sei
  caratteri che il server rifiutava;
- nessun massimo per la password: bcrypt considera i primi 72 byte, e una
  password di 73 caratteri con la coda diversa apriva lo stesso;
- l'username era unico solo per docente: un doppione dava un 500 con il testo
  SQL nella risposta; fra docenti diversi era accettato, e con la stessa coppia
  lo studente entrava nella credenziale che la query trovava per prima;
- una scadenza passata era accettata: la credenziale nasceva spenta;
- la nuova password e la nuova scadenza mostravano il codice grezzo
  («Non cambiata: weak_password»).

**L'etichetta.** Era testo libero del docente fino a 128 caratteri, copiato nel
grant di sessione e mostrato agli studenti come testo principale del
portachiavi. Niente la legava al perimetro vero: in produzione le due etichette
esistenti dichiaravano una sezione mentre la credenziale valeva per l'anno, e in
una la cifra iniziale non era nemmeno l'anno. Il testo libero può anche
contenere nomi (R20 della DPIA). La riverifica del portachiavi rileggeva solo
`id`, `active` ed `expires_at`: un'etichetta corretta non arrivava agli
studenti già entrati.

## Decisione

### Regole

1. **Le regole stanno in costanti** di `TeacherCredentialRepository` e di
   `EtichettaCredenziale`. La vista del profilo le stampa negli attributi
   (`pattern`, `minlength`, `maxlength`) e nel testo d'aiuto sotto ogni campo,
   collegato con `aria-describedby`; il server applica lo stesso testo come
   `/^(?:…)$/`. In JavaScript non c'è nessun numero: il fumetto del browser
   (`setCustomValidity`) e i messaggi d'errore leggono il testo d'aiuto.
2. **Username**: da 3 a 64 caratteri fra lettere senza accenti, cifre, punto,
   trattino e trattino basso; **unico su tutta la piattaforma** (migrazione 134,
   indice `uq_tac_access_username`), maiuscole e minuscole equivalenti come
   all'accesso. Un doppione è `username_in_uso` (400).
3. **Password**: da 6 a 64 caratteri **ASCII stampabili, senza spazi**. Si detta
   in classe, non cambia da un dispositivo all'altro (niente emoji né lettere
   accentate in forme diverse) e resta sotto i 72 byte di bcrypt. Vale alla
   creazione e alla rotazione; le password già date restano valide.
4. **Scadenza**: da oggi in poi; una data passata è `scadenza_passata`, alla
   creazione e dalla riga.
5. **Nessun testo del database nella risposta**: un errore imprevisto va nel
   registro del server, al client arriva `persist_failed`.
6. Nel testo d'aiuto di username e aggiunta: **«Non usare nomi di persone»**.

### Etichetta

7. **La compone il server**: `{CLASSE}_{INDIRIZZO}_{MATERIE}[_{AGGIUNTA}]`
   (`App\Domain\EtichettaCredenziale`).
   - CLASSE e INDIRIZZO sono quelli del perimetro risolto nel catalogo: «3_SCI»
     per l'anno, «3B_SCI» per la sezione. L'etichetta non può più dichiarare una
     sezione che la credenziale non ha.
   - Una credenziale per tutte le classi comincia con **«TUTTE»**; una
     delimitata al solo indirizzo (possibile via API) con l'indirizzo.
   - MATERIE: sigle **scelte dal docente fra le sue materie spuntate**
     nell'istituto della credenziale, tutte preselezionate nel modulo, in
     **ordine alfabetico**, separate da «-». Senza materie la parte si omette.
     Una sigla non spuntata è `materia_non_tua`.
   - AGGIUNTA facoltativa: lettere senza accenti, cifre e trattino, al massimo
     12, portata in maiuscolo. Niente spazi né «_», così le parti si separano
     senza ambiguità.
   - Un `label` mandato dal client si ignora.
8. **Stessa etichetta, stesso docente**: fra le sue credenziali non scadute,
   accese o spente, è rifiutata (`etichetta_gia_usata`) con l'invito a scrivere
   un'aggiunta. Le scadute non contano: a fine anno la stessa classe riprende
   la stessa etichetta. Il controllo è dell'applicazione, non un indice: un
   indice unico su `(teacher_id, label)` bloccherebbe anche le scadute non
   cancellate. Per la stessa ragione **rimettere una scadenza futura a una
   credenziale scaduta ricontrolla l'etichetta** (`etichetta_in_conflitto`, la
   scadenza non si sposta): mentre era scaduta un'altra ha potuto prendersi la
   sua, e due credenziali vive con la stessa etichetta nel portachiavi sono due
   voci identiche. Prima si rigenera l'etichetta di una delle due, poi si
   sposta la scadenza.
9. **Le parti si salvano** in due colonne nuove (migrazione 134): `materie`
   (sigle separate da virgola; `''` = composta senza materie; `NULL` = etichetta
   libera di prima) e `aggiunta`. Servono a ricomporre l'etichetta e a
   ricalcolarla se una migrazione del catalogo ripunta le classi.
10. **«Rigenera etichetta»** nella riga (`POST /api/teacher/credentials/{id}/etichetta`):
    materie e aggiunta scelte ora, sul perimetro della credenziale. Le
    credenziali esistenti **non si migrano**: le due in produzione hanno lo
    stesso perimetro e rigenerate uguali si scontrerebbero; le rigenera il
    docente dalla riga, scegliendo l'aggiunta. Le materie ammesse sono quelle
    spuntate dal docente nell'istituto della credenziale; **se la credenziale
    non ha istituto** — la forma di quasi tutte quelle nate prima del 13
    settembre 2026, cioè proprio quelle a etichetta libera — valgono le materie
    dell'istituto in cui il docente sta lavorando, lo stesso ripiego della
    creazione e le stesse caselle che il modulo mostra nella riga. Il ripiego
    non allarga niente: una sigla che il docente non ha spuntato resta
    `materia_non_tua`.
11. **Anteprima nel modulo**, chiesta al server
    (`GET /api/teacher/credentials/anteprima-etichetta`): la composizione sta in
    un posto solo. Dice anche se l'etichetta è già usata.
12. **Il portachiavi rilegge l'etichetta**: `ClassAccessGrant::revalidate` legge
    anche `label` e i nomi delle materie; un'etichetta rigenerata arriva agli
    studenti già entrati alla richiesta successiva.
13. **Nel portachiavi** l'etichetta ha un `title` con i nomi delle materie dal
    catalogo della scuola («Materie: Fisica, Matematica»): sigle come DPA o STG
    non dicono niente a uno studente. Il nome che il docente dà a una voce per
    sé non esce.
14. **A parità di etichetta fra docenti diversi nessun identificativo del
    docente**: per minimizzazione. L'unico modo di distinguerle è l'aggiunta.

## Conseguenze

- **Contratto dell'API**: chi manda `label` non la vede più usata; la risposta
  della creazione restituisce `label`. `GET /api/teacher/credentials` restituisce
  anche `materie_disponibili` (le spunte del docente nell'istituto chiesto o in
  quello in cui lavora). Aggiornati nella stessa PR il modulo, la suite
  end-to-end (fabbrica, client, componente, spec) e le prove PHP.
- **Enumerazione**: con l'username unico un docente autenticato può sapere che
  un username esiste già presso un collega. Da solo non apre niente; servono
  account, gettone CSRF e limite di richieste. L'invito a non usare nomi riduce
  ciò che se ne può dedurre.
- **Transizione**: il portachiavi mescola etichette libere e composte finché il
  docente non le rigenera o finché scadono (31 agosto 2027).
- **Fotografia**: se il docente toglie la spunta a una materia, l'etichetta la
  conserva finché non la rigenera.
- **Privacy**: la parte strutturata non contiene nomi per costruzione; resta
  l'aggiunta di 12 caratteri, con l'invito. Il legame docente-classe-materie è
  già nel perimetro della credenziale e nelle spunte, e chi lo vede è lo
  studente di quel docente. Nessun dato nuovo né destinatario nuovo. DPIA 1.9
  (§1: «etichetta composta dal sistema»); informativa e registro non cambiano.
- **Non cambia**: chi vede che cosa con la credenziale, il QR, il codice a
  tempo, il cookie «ricorda».

## Prove

- `tests/Unit/Domain/EtichettaCredenzialeTest.php`: composizione e aggiunta.
- `tests/Unit/Repositories/TeacherCredentialRulesTest.php` e
  `tests/js-unit/credenziali-regole.test.js`: lo stesso corpus
  (`tests/Fixtures/credenziali-regole.json`) contro le regole del server e contro
  i pattern compilati come fa il browser (flag `v`); ogni codice d'errore del
  repository ha una frase nel modulo.
- `tests/Integration/CredenzialeRegoleTest.php`, `EtichettaCredenzialeTest.php`,
  `PortachiaviTest.php` (etichetta rigenerata nel portachiavi).
- End-to-end: `tests/e2e/area-docente/credenziali-di-classe.spec.js`,
  `tests/e2e/pubblico/accesso-classe-portachiavi.spec.js`.

## Riferimenti

- Migrazione `database/migrations/134_credenziali_username_unico_ed_etichetta.sql`.
- [[domains/auth/auth-overview]], sezione «Credenziale di classe».
- `docs/privacy/dpia.md` 1.9, §1 «Ambito di accesso degli studenti».
