---
tags:
  - documentazione/adr
date: 2026-09-14
tipo: adr
status: accettato
aliases: ["ADR-038", "stati di Drive", "Drive spento", "da ricollegare", "DRIVE_ENABLED"]
cssclasses: []
---

# ADR-038 — Drive ha uno stato: quello dell'installazione e quello di ogni collegamento

## Stato

**ACCETTATO** dall'utente il 14 settembre 2026 («2. ok», sulla proposta di
rendere esplicito lo stato di Drive). Aggiorna [[ADR-009-drive-integration]],
che resta valido per il flusso OAuth e per la direzione server → Drive.

## Contesto

Misurato il 14 settembre 2026.

- **In produzione le credenziali OAuth di Google non c'erano**, almeno dal 20
  maggio, ma un docente aveva ancora il collegamento: a notti alterne le sue
  130 mappe finivano tutte in errore. Lo script usciva con zero; corretto con
  la #83, usciva con 1, e l'avviso andava all'amministratore ogni notte.
- **Drive non aveva uno stato.** Si offriva ai docenti anche senza
  credenziali, e «Collega Drive» finiva in un errore 500.
- **Un collegamento rifiutato da Google restava uguale a uno buono**: accesso
  revocato dall'account del docente, token scaduto. Il giro ci riprovava ogni
  notte, e ogni notte l'avviso andava all'amministratore, che non può
  ricollegare al posto del docente. Con cento docenti collegati, gli avvisi
  sarebbero cresciuti con i docenti.
- **La cache delle cartelle sopravviveva allo scollegamento**
  (`teacher_drive_folder_cache`), e un collegamento nuovo teneva la cartella
  radice di prima. Chi ricollegava con un altro account Google caricava in
  cartelle che quell'account non vede. In produzione, lo stesso giorno: 42
  righe di cache di un docente già scollegato.
- **Come risponde Google**, misurato con la libreria (`google/apiclient`) e
  risposte simulate:
  - il rinnovo rifiutato arriva come `ClientException` 400, con
    `invalid_grant` nel corpo;
  - le credenziali sbagliate dell'installazione arrivano come 401
    `invalid_client`;
  - un consenso senza la casella di Drive dà un token valido, il cui `scope`
    non contiene `drive.file`, e la prima chiamata a Drive prende 403.

## Decisione

### L'installazione

`DRIVE_ENABLED` (falso se manca) dice se Drive si offre ai docenti.

| Stato | Quando | Che cosa vedono i docenti | Il giro notturno |
|---|---|---|---|
| spento | `DRIVE_ENABLED` non è vero | nessun comando Drive; chi ha un collegamento di prima vede solo «Disconnetti» | esce con 0, e lo dice |
| guasto | `DRIVE_ENABLED` vero, credenziali mancanti | la sezione dice che Drive non è disponibile | esce con 1: parte l'avviso |
| acceso | `DRIVE_ENABLED` vero, credenziali presenti | tutto | sincronizza |

**Perché una variabile, e non le sole credenziali.** Senza variabile,
«credenziali assenti» vuol dire due cose opposte: un'installazione che non usa
Drive, e una che le ha perse. Il giro non può distinguerle. Con la variabile:
- chi spegne Drive lo dichiara, e non riceve avvisi;
- chi lo accende e perde le credenziali riceve l'avviso ogni notte, finché non
  le rimette o non spegne Drive.

### Il collegamento del docente

Tre colonne in `teacher_drive_oauth` (migrazione 124):
- `stato`: `attivo` o `da_ricollegare`;
- `stato_motivo`;
- `stato_dal`.

**Quando il collegamento va rifatto.** Lo segna `DriveClient::getDriveFor` in
due casi:
- Google rifiuta il rinnovo con `invalid_grant`: motivo `accesso_revocato`;
- il token rinnovato non ha il permesso su Drive: motivo
  `permessi_insufficienti`.

Ogni altro errore resta un errore.

**Che cosa succede dopo.**
- Il collegamento non chiama più Google. Il giro lo salta, e i pulsanti di
  sincronizzazione si fermano con un messaggio che dice dove ricollegare.
- Il docente lo vede nel cruscotto, con il motivo e la data, e con i comandi
  «Ricollega Drive» e «Disconnetti».
- Ogni nuovo consenso lo rimette attivo. Il consenso si chiede con
  `prompt=consent`, che fa rilasciare a Google un `refresh_token` nuovo anche a
  chi aveva già autorizzato l'app. Fino al 15 settembre 2026 si usava
  `approval_prompt=force`, che Google ignora: dopo «Disconnetti», ricollegarsi
  falliva con `drive_oauth_no_refresh_token`.
- Un consenso senza la casella di Drive non si salva: il docente torna al
  cruscotto, con il perché.

### Gli avvisi all'amministratore

Partono solo per quello che può correggere lui, o il codice:
- Drive acceso senza credenziali;
- un errore su una mappa, o un'eccezione;
- il giro interrotto dal freno sul tempo.

**Un caso in più.** In un giro Google può rifiutare tutti i collegamenti
provati, almeno due. Allora è più probabile un guasto generale che dei docenti
che revocano insieme. Per esempio:
- l'app OAuth in modalità di prova, dove i token durano sette giorni;
- il client cambiato in Google Cloud.

Con un docente solo non si distingue, e lo vede lui.

### Le cartelle

- **Lo scollegamento, e ogni collegamento con un token nuovo**, svuotano la
  cache delle cartelle e dimenticano la radice. Se l'account è lo stesso, la
  radice si ritrova da sola:
  - prima per il marcatore `fm_root` che l'app le mette, **dovunque il docente
    l'abbia spostata** (dal 15 settembre 2026: prima si cercava solo per nome in
    «Il mio Drive», e una radice spostata non si ritrovava);
  - poi, per le radici di prima del marcatore, per nome in «Il mio Drive».

  Le sottocartelle si ritrovano per nome dentro la radice.
- **Il consenso che aggiorna solo i permessi** (stesso account) le tiene.
- **La migrazione 124** toglie la cache di chi è già scollegato.

### Le mappe su Drive

Misurato il 15 settembre 2026, il primo giro con Drive acceso in produzione.

- **Il tipo del file.** Estensione e tipo vengono da `map_mime`; dove manca, dai
  primi byte del contenuto (XML → `.drawio`, poi PDF, PNG, JPEG), e il tipo
  riconosciuto si registra. Quel giorno 56 mappe importate senza tipo arrivavano
  su Drive senza estensione, come dati binari. Alla sincronizzazione successiva
  i file si rinominano da soli.
- **Le mappe senza file** (la riga c'è, il blob cifrato no) sono **orfane**, non
  errori del giro: il resoconto le conta (`orphan=N`) e il giro non fallisce per
  loro. Il segnaposto `blob_orphan`, che le esclude dai giri successivi, va solo
  dove `map_drive_id` è vuoto. Un identificativo vero è l'unico riferimento alla
  copia su Drive, e non si cancella. Fino a quel giorno c'erano due difetti:
  - si riconoscevano con il messaggio di un altro deposito
    (`blob_store_not_found`, che è quello delle verifiche), quindi risultavano
    errori e facevano partire l'avviso;
  - il segnaposto non si scriveva mai, perché la condizione chiedeva una colonna
    che la tabella non ha, e l'errore si ingoiava.
- **Recuperarle.** `tools/migrations/recupera_mappe_da_drive.php`: prima la
  prova, poi `--apply`. Serve il consenso di migrazione del docente
  (`/teacher/drive/connect-migration`, aggiunge `drive.readonly`), perché i file
  creati da un altro client OAuth il permesso `drive.file` non li vede. Una
  mappa recuperata prende `map_origin = drive_legacy`, e la sincronizzazione
  successiva la crea nella cartella dell'app. Dopo il recupero, per tornare al
  solo `drive.file` il docente revoca l'app dal proprio account Google e si
  ricollega dal cruscotto. In produzione il comando si lancia da systemd, come
  utente dell'applicazione **e con il gruppo e la maschera dei blob**:
  `systemd-run --uid=pantedu --gid=www-data -p UMask=0007 …`. Misurato il 15
  settembre: con il solo `--uid` i blob recuperati nascevano
  `pantedu:pantedu 644`, mentre gli altri sono `pantedu:www-data 660` o `664`, e
  si sono dovuti allineare a mano.
- **Il giro notturno carica solo le mappe nuove o cambiate** dall'ultima
  sincronizzazione del docente (mai caricate, o `updated_at` dopo
  `last_sync_at`). Fino al 15 settembre ricaricava tutte le mappe ogni notte:
  130 mappe, 641 s per un docente solo, oltre il freno di 300 s che con più
  docenti avrebbe fermato il giro dopo il primo. Il prezzo: un file cancellato
  a mano su Drive torna alla prossima modifica della mappa, non la notte dopo.
- **Le prove**: `DriveSincronizzazioneMappeTest` (tipo, orfane e radice, con un
  Google simulato), `RecuperoMappeDaDriveTest`, `MapSyncNomeETipoTest`.

## Conseguenze

- **In produzione** Drive è **acceso dal 15 settembre 2026**. Fino ad allora
  `DRIVE_ENABLED` non c'era e Drive risultava spento: il riquadro nel cruscotto
  non compariva, e zero collegamenti. Per accenderlo sono servite due cose:
  - le credenziali OAuth in `.env.local`, con l'URI di ritorno
    `<APP_URL>/teacher/drive/callback` registrato in Google Cloud e l'app
    pubblicata (in modalità di prova i token durano sette giorni);
  - `DRIVE_ENABLED=true`, insieme alle credenziali e non prima: da solo è il
    guasto, con l'avviso ogni notte.

  Come si modifica `.env.local` senza fermare il sito:
  [runbook § 4.7](../../docs/ops/runbook-sito-irraggiungibile.md). Le
  credenziali identificano l'app, non un Drive: ogni docente collega il proprio
  account, e il permesso è solo suo.
- **In sviluppo e in CI** Drive è spento finché non si mette
  `DRIVE_ENABLED=true`.
  - La suite end-to-end vede lo stato spento.
  - Gli altri stati li provano, nei due versi: `DriveStatiTest` (con un Google
    simulato), `StatoDiDriveTest`, `SincronizzazioneNotturnaTest`,
    `drive-stato.test.js` e `barra-drive.test.js`.
- **Privacy.** Nessun dato nuovo, nessuna finalità nuova.
  - Le tre colonne descrivono un collegamento che esisteva già, e spariscono
    con lui.
  - L'informativa promette che il docente può disconnettere «in qualsiasi
    momento»: resta vero in ogni stato, anche con Drive spento.
  - Nessun documento legale cambia.

## Cosa non fa

- **Non revoca il token presso Google allo scollegamento.** L'informativa dice
  che scollegare revoca l'autorizzazione dal lato di pantedu; il docente la
  toglie dal suo account Google.
- **Non controlla che una cartella in cache esista ancora su Drive** mentre il
  collegamento è attivo: voce 103 del registro del debito.

## Riferimenti

- [[ADR-009-drive-integration]]
- `app/Services/Drive/StatoDiDrive.php`, `app/Services/Drive/DriveClient.php`,
  `app/Services/Drive/SincronizzazioneNotturna.php`
- `database/migrations/124_drive_stato_del_collegamento.sql`
- `docs/ops/diagnostica.md`, il giro notturno di Drive
