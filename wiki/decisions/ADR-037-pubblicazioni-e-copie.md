---
tags:
  - documentazione/adr
date: 2026-09-13
tipo: adr
status: accettato
aliases: ["ADR-037", "pubblicazioni", "collocazioni", "vale per più classi", "copia indipendente", "quattro etichette"]
cssclasses: []
---

# ADR-037 — Un documento, più pubblicazioni: la collocazione esce dalla riga

## Stato

**ACCETTATO** dall'utente la sera del 13 settembre 2026 («implementa adr»),
**in esecuzione**: fase 0 fatta; le correzioni che la precedono, le fasi 1, 2
e 3 e i primi due tempi della fase 4 in produzione dalla notte del 14
settembre 2026, unite nell'ordine e ognuna dopo che la precedente era in
produzione dove lo chiedeva la regola dei due passi. Il terzo tempo della fase
4 (le etichette della riga) è deciso: il 14/9 l'utente ha scelto che la
visibilità del documento resti come interruttore (decisione 5) e cadano solo le
etichette, in tre rilasci: il primo (4c-1) e il secondo (4c-2) in produzione
dalla notte del 14/9, il terzo (4c-3, migrazione 123) in produzione lo stesso
giorno. Per le verifiche la materia resta nella riga: prima come scostamento
misurato (4c-2), poi come decisione dell'utente del 14/9 (decisione 8). Com'è andata, con le
misure e con i punti in cui la misura ha cambiato il piano, nella sezione
«Esecuzione» in fondo.

Proposto la stessa sera, prima di qualunque riga di codice. Nasce dalla
rilettura sui tre scenari
([scenari-e-condivisione-2026-09-13](../../docs/analysis/scenari-e-condivisione-2026-09-13.md))
e da tre richieste dell'utente: una mappa che vale per più classi della
stessa scuola o di scuole diverse e si corregge una volta; una mappa che si
duplica in una o più classi come copia indipendente; tutto scelto dal sito,
non da uno strumento sul server. Presuppone ADR-035 (vocabolario per
istituto, spunte) e si applica dopo la sua fase 3.

## Contesto

Un documento del docente (`teacher_content_data`; le verifiche,
`verifica_documents_data`, hanno la stessa forma) porta `teacher_id` e tre
etichette — `indirizzo_id`, `classe_id`, `subject_id` — che sono id del
vocabolario di **una** scuola, quella in cui è nato. La scuola non è scritta:
è implicita nelle etichette. Un documento ha quindi **un posto solo**.

Chi lo vede lo trova per sigle, non per scuola (misurato il 13/9):

- la barra del docente elenca per `teacher_id` più le sigle
  (`SCI`, `2`, `MAT`) che la vista `teacher_content` ricava dagli id, senza
  istituto (`ContentStudyController::viewerContext`: amministratore e docenti
  senza vincolo di scope; allora anche il collaboratore, ruolo tolto il 15/9/2026);
- gli studenti con account filtrano per istituto, ma l'istituto è
  «il docente appartiene alla scuola» (`teacher_institutes`), non «il
  documento sta in questa scuola» (`TeacherContentRepository::search`), più
  le sigle e gli incarichi;
- le credenziali di classe filtrano per docente e sigle (`grants`).

Quindi un documento nato al Esempio come `SCI / 2 / MAT` compare anche al
Musicale se lì esistono le stesse sigle — a studenti e credenziali compresi —
e non compare mai se il Musicale chiama la seconda `2A` e matematica `MATE`.
Nessuno l'ha deciso, e in scenario 3 le due scuole sono due titolari.

La «pubblicazione per più classi» esiste già a metà: `publish_scope =
'classes'` con `content_target_classes (content_id, indirizzo, classe)`,
coppie di **sigle**, della stessa scuola e della stessa materia. E c'è un
altro mezzo modello, mai usato: `published_content_data` con le chiavi di
classe (`classe_keys_data`, `ClasseKeyService`, Phase 25.D6), una copia
cifrata per classe pensata perché cancellare il docente (art. 17) non tolga
agli studenti quanto già pubblicato. In produzione: zero righe e zero chiavi
(fase 0 di ADR-035, 13/9); gli studenti leggono `teacher_content` cifrato con
la chiave del docente.

«Sposta di classe» (13/9) sposta l'unico posto del documento dentro una
scuola: è il primo pezzo di questa decisione, fatto prima di prenderla.

## Decisione

1. **La pubblicazione è un oggetto.** Un documento è un artefatto del
   docente; una **pubblicazione** è quell'artefatto in una scuola, in una
   terna, con uno stato. Tabella `content_publications`:
   `source` (`teacher_content` | `verifica_documents`), `content_id`,
   `institute_id`, `indirizzo_id`, `classe_id`, `subject_id` (id del
   vocabolario **di quella** scuola, tutti e tre), `is_primary`,
   `visibility` (bozza · pubblicato · archiviato), `archive_visible`,
   `created_at`, `updated_at`. Chiave unica su `(source, content_id,
   institute_id, indirizzo_id, classe_id)`; chiavi esterne a cascata sul
   documento e sull'istituto; indice su `(institute_id, classe_id,
   subject_id)` per gli elenchi.
2. **Una fonte di verità.** Tutte le pubblicazioni stanno nella tabella, una
   sola è la principale. Le tre colonne della riga non vengono più lette da
   nessuno: restano scritte per un rilascio (cache della principale) e
   cadono nel successivo, regola dei due passi. La vista `teacher_content`
   ricava indirizzo, classe e materia dalla principale, così chi la legge
   oggi continua a funzionare. Il modello «principale nella riga, le altre in
   tabella» è stato scartato: due posti per un concetto, la stessa trappola
   delle copie di ADR-035.
3. **Chi vede cosa, per scuola.** Ogni elenco chiede «c'è una pubblicazione
   in questa scuola, in questa terna, con questo stato?»:
   - barra del docente nella scuola S: le pubblicazioni sue in S;
   - studente con account: pubblicazioni «pubblicato» nel suo istituto, la
     cui classe copre la sua (l'anno copre le sezioni, `ClassCode`), di un
     docente con incarico (`teacher_sections`); nell'archivio degli anni
     passati vale `archive_visible`;
   - credenziale di classe: pubblicazioni «pubblicato» del docente della
     credenziale, nella scuola della credenziale, per la classe coperta;
   - pool dei colleghi: documenti con una pubblicazione in una scuola che
     l'attore e il proprietario hanno in comune, più le condizioni di oggi
     (condiviso, materia condivisa, grant);
   - `publish_scope` resta con `class` e `general`; `classes` sparisce,
     sostituito da più pubblicazioni. «Generale» vale scuola per scuola,
     solo dove il documento ha una pubblicazione.
   La coincidenza di sigle non fa più vedere niente.
4. **La copia indipendente.** «Duplica in…» crea un documento nuovo dello
   stesso docente, con le pubblicazioni scelte, agganciato all'originale con
   `source_content_id` (esiste già, è il recupero dal pool). Passa dalla
   creazione normale del repository: il corpo si decifra in memoria e si
   ricifra con la versione di chiave corrente, mai copiando le colonne
   cifrate; i file (contratti, mappe) si copiano con proprietario e ACL
   nuovi. Da lì in poi le due vite sono separate, e il modale lo dice.
5. **Lo stato è della pubblicazione, e il documento ha un interruttore.**
   Bozza al Musicale e pubblicato al Esempio sono due righe con due stati.
   La `visibility` della riga resta, come stato del documento (scelta
   dell'utente del 14/9/2026, fase 4c): in bozza o archiviato non lo vede
   nessuno, in nessun posto; pubblicato, lo vede ogni posto il cui stato è
   «pubblicato». La principale di un contenuto ha lo stato della riga (in
   bozza se era «per più classi»), e nel modale un posto «pubblicato» di un
   documento che non lo è si mostra «sospeso». Il piano diceva che la colonna
   seguiva la principale e poi cadeva: toglierla avrebbe voluto dire o perdere
   l'interruttore, e riportare in bozza i posti di ogni documento in bozza, o
   tenerlo altrove. Cadono solo le etichette (fase 4c); per le verifiche la
   materia resta anche nella riga, chiave del loro indice unico (4c-2,
   decisione 8).
6. **`published_content_data` e `classe_keys_data` si pensionano.**
   L'obiettivo che avevano — gli studenti continuano a leggere dopo la
   cancellazione del docente — resta scritto qui come opzione futura (una
   chiave avvolta per pubblicazione), non si realizza adesso: oggi gli
   studenti perdono l'accesso con la cancellazione del docente, e questa
   decisione non lo cambia. Le due tabelle escono con una migrazione con
   `SICUREZZA` e `ROLLBACK`, dopo che la fase 0 ha confermato le zero righe.
7. **Le verifiche** entrano nella stessa tabella (`source`), in una fase
   propria, con le stesse regole.
8. **La materia di una verifica sta nella verifica** (decisione dell'utente,
   14 settembre 2026, «3. va bene scrivi»). Definitiva, non un passaggio.
   - **Perché.** La materia fa parte di *che cos'è* una verifica: una
     verifica di Matematica sulle frazioni e una di Fisica sulle frazioni
     sono due verifiche diverse, anche con lo stesso titolo. La scuola, la
     classe e l'indirizzo dicono *dove* è pubblicata, e stanno nelle
     pubblicazioni.
   - **L'indice unico resta nel database.** `uq_verif_doc_title_fk`
     (docente, materia, titolo, variante, versione) ferma due salvataggi
     simultanei della stessa verifica. Nel database regge anche contro un
     secondo processo, uno strumento o un errore nel codice; un blocco nel
     salvataggio reggerebbe solo contro chi passa da lì.
   - **La principale porta la stessa materia**, e la verifica di
     allineamento (`pub_verifica_allineamento`) lo controlla ogni giorno.
   - **Misurato in produzione il 14/9, prima di scriverla:** 25 verifiche,
     23 con la materia; 23 pubblicazioni; nessuna verifica pubblicata in più
     di una scuola; nessuna con la materia diversa da quella della sua
     principale.
   - **Cosa non cambia per il docente.** Una verifica pubblicata in un'altra
     scuola porta la sua materia; dove la sigla della materia è diversa,
     conta la voce del vocabolario di quella scuola sulla pubblicazione,
     come per i contenuti.

## Perché non quattro etichette nella riga

È la domanda naturale: aggiungere `institute_id` accanto alle tre etichette.
Renderebbe esplicita la scuola, che oggi è implicita — ed è utile come
controllo — ma la riga direbbe ancora **un posto solo**: «questa mappa sta
qui, in questa scuola». La richiesta è che la stessa mappa stia in tre posti
(Esempio `SCI/2/MAT`, Esempio `SCI/2B/MAT`, Musicale `SCI/2A/MATE`) e si
corregga una volta: tre quadruple per un documento, e in una riga ce ne sta
una. Per averne tre servono tre righe: o tre documenti (che è la copia, e
perde «correggo una volta») o una tabella accanto con una riga per posto.
Ogni riga di `content_publications` è esattamente la quadrupla — scuola,
indirizzo, classe, materia — con in più lo stato. La quarta etichetta c'è;
cambia dove vive.

Altre alternative valutate:

- **Equivalenze fra vocabolari** («la 2A del Musicale è la 2 del Esempio»):
  un'equivalenza è una decisione della scuola, non del docente, e farebbe
  passare materiali fra due titolari per una tabella di corrispondenze.
  Scartata.
- **Solo l'elenco delle scuole in cui vale** (senza terna per scuola): non
  regge le sigle diverse. Scartata.
- **Principale nella riga, le altre in tabella**: meno lettori da toccare
  subito, due fonti di verità per sempre. Scartata (punto 2).

## Invarianti di sicurezza

Da provare nei due versi, ognuno, con due scuole vere nel seed della suite:

- **S1 Proprietà.** Solo il proprietario del documento aggiunge, toglie,
  sposta o cambia di stato una pubblicazione, e solo lui duplica.
- **S2 Appartenenza.** Una pubblicazione si crea solo in una scuola a cui il
  docente è collegato (`teacher_institutes`).
- **S3 Vocabolario.** Le tre voci sono di **quella** scuola, attive e
  spuntate dal docente; la classe, se ha un corso, ha quel corso. Tutto lato
  server: gli id dal client non si credono mai (è la falla dell'istituto
  della credenziale non verificato, rapporto §4.1, da chiudere prima della
  fase 1).
- **S4 Isolamento.** Uno studente, una credenziale, un collega o un
  amministratore di istituto non arrivano a un documento — nemmeno per id —
  che non ha una pubblicazione nella loro scuola; `ContentVisibilityPolicy`
  ne è l'unico giudice e le prove lo dicono per ogni pubblico.
- **S5 Copie.** La copia non tocca le colonne cifrate; i file cambiano
  proprietario; la copia di un documento che non si può condividere (blocco
  per diritto d'autore, `shareBlockReason`) resta del docente e non
  aggira il blocco.
- **S6 Registro e dati.** Ogni pubblicazione aggiunta, tolta, spostata o
  cambiata di stato e ogni copia vanno a registro con gli id (mai il
  contenuto); l'esportazione del docente (`TeacherContentExporter`) include
  pubblicazioni e copie; cancellare il docente porta via le pubblicazioni
  (cascata). Nessun dato di studenti entra nel modello.
- **S7 Pannello.** L'amministratore di un istituto vede le pubblicazioni
  della sua scuola, non le altre scuole dello stesso docente.

## Fasi

Nessuna è un big-bang; ognuna si misura da sola.

**Fase 0 — la misura.** Uno script di sola lettura: documenti per scuola
(dedotta dalle etichette); righe di `content_target_classes` e quante si
risolvono nel vocabolario della loro scuola; righe di `published_content` e
`classe_keys`; e — la cifra che conta — quanti documenti compaiono oggi in
più scuole per coincidenza di sigle, cioè quanti spariranno da una scuola
con la fase 1. Il rapporto va in `docs/analysis`.

**Fase 1 — la tabella e i lettori.** Migrazione: `content_publications`,
riempita con la principale da ogni riga e con le pubblicazioni non
principali da `content_target_classes` risolte nella stessa scuola (le non
risolvibili si elencano, non si inventano); vista `teacher_content` che
legge dalla tabella; filtri di studenti, credenziali, colleghi e barra sulla
tabella; `publish_scope='classes'` letto come «più pubblicazioni». Le tre
colonne restano scritte. Nessun cambiamento visibile, tranne uno: la
coincidenza di sigle non fa più vedere un documento in un'altra scuola —
la fase 0 dice a chi e quanto.

**Fase 2 — l'interfaccia.** Nel modale del contenuto la sezione «Dove vale»
(principale, altre, aggiungi con la catena scuola → indirizzo → classe →
materia fra le proprie spunte, togli, stato per pubblicazione) e «Duplica
in…»; «Sposta di classe» sposta una pubblicazione, principale o no.

**Fase 3 — le verifiche**, stesso trattamento.

**Fase 4 — la rimozione.** Cadono `indirizzo_id`, `classe_id` e `subject_id`
dalle righe (la `visibility` resta: decisione 5), `content_target_classes`,
`published_content_data` e `classe_keys_data`; migrazioni con `SICUREZZA` e
`ROLLBACK`, in un rilascio successivo alla fase 3.

## Conseguenze per scenario

| | Scenario 1 (personale) | Scenario 2 (colleghi) | Scenario 3 (Istituto) |
|---|---|---|---|
| Un documento in più scuole | scelta esplicita, non più per sigle | idem; il pool lo vede solo dove è pubblicato | idem; due titolari, nessun passaggio per caso |
| Copia indipendente | dello stesso docente | idem | idem |
| Studenti / credenziali | credenziali per scuola e classe della pubblicazione | idem | studenti per istituto, incarico e stato della pubblicazione |
| Verifiche condivise fra colleghi | — | resta condivisione, non pubblicazione | agli studenti conta lo **stato della pubblicazione**, non la condivisione: chiude il punto aperto in ADR-032 dal 4/9 |

## Gli scenari, uno per uno: i problemi e le risposte

La tabella qui sopra dice cosa cambia; qui sotto, per ogni scenario, i
problemi che la rilettura del 13/9 ha trovato o che questa decisione crea, e
come si risponde. Dove la risposta è «resta aperto», è detto.

**Scenario 1 — personale** (un gestore, magari in due scuole; studenti solo
con credenziale di classe).

- *Un documento in due scuole per coincidenza di sigle.* Finisce con la fase
  1: compare dove ha una pubblicazione. La fase 0 dice quanti documenti
  spariranno da una scuola, e il gestore li ripubblica dal modale (fase 2)
  o li duplica.
- *La credenziale sull'anno («2») non copre le sezioni.* Con le
  pubblicazioni si pubblica lo stesso documento sia in «2» sia in «2A»
  senza copiarlo; la regola della credenziale non cambia (l'anno copre le
  sezioni, la sezione vale per sé). Il modale delle credenziali deve dire
  che una credenziale delimitata alla sezione vede anche l'anno.
- *L'istituto della credenziale arriva dal client e non è verificato*
  (rapporto §4.1). Va chiuso **prima** della fase 1: è l'invariante S2
  applicato a un oggetto che esiste già.
- *«Sposta di classe»* resta il modo di correggere in blocco anno → sezione;
  con le pubblicazioni sposta la principale o una pubblicazione, e i
  bersagli non esistono più come cosa a parte.

**Scenario 2 — colleghi** (docenti iscritti della stessa scuola; studenti
solo con credenziale).

- *Il pool era «tutta la scuola del docente»*, cioè ogni documento di un
  docente iscritto, per il fatto di appartenere alla scuola. Diventa «i
  documenti che il proprietario ha pubblicato in una scuola che abbiamo in
  comune»: un documento tenuto solo nell'altra scuola del collega non
  compare. Resta per scuola e non per classe: chi condivide, condivide con la
  scuola, e va detto nel modale e nell'informativa 2.5 (rapporto §3.2).
- *Il recupero clona* (`source_content_id`): la copia è del collega, con le
  pubblicazioni sue; ritirare la condivisione non tocca le copie fatte — già
  così oggi, e l'informativa lo deve dire.
- *La credenziale è di un docente*: non dà i documenti dei colleghi, anche
  se pubblicati nella stessa classe. Il portachiavi resta la risposta; una
  «credenziale del consiglio di classe» sarebbe un'altra decisione.
- *La regressione del 13/9* (una colonna spostata, un lettore dimenticato)
  è il rischio tipico di questa migrazione: la fase 1 parte da un elenco
  misurato dei lettori, non dai file che si stanno toccando (sotto).

**Scenario 3 — Istituto** (docenti e studenti con account; titolare
l'Istituto; solo su infrastruttura qualificata ACN; non attivo).

- *Due scuole, due titolari.* Oggi un documento passa dall'una all'altra
  per sigle; con S4 uno studente arriva solo a ciò che ha una pubblicazione
  nel suo istituto. Il docente che pubblica il proprio materiale in una
  scuola compie un atto suo, registrato; l'Istituto non tratta dati nuovi.
  Da verificare con il DPO solo la formulazione nell'informativa 1.1
  («contenuti pubblicati per classe» → «per scuola e classe»).
- *Spunta ≠ incarico.* Pubblicare in 2A non basta senza l'incarico
  dell'amministratore: già così, e il modale «Dove vale» deve mostrarlo
  accanto alla classe (rapporto §1.4), altrimenti si pubblica nel vuoto.
- *Verifiche condivise visibili agli studenti* (ADR-032, aperto dal 4/9):
  agli studenti conta lo stato della pubblicazione; la condivisione resta
  fra colleghi. Questo ADR lo chiude nella fase 3.
- *Archivio degli anni passati* (`student_class_history`,
  `ClassiFrequentate`): `archive_visible` sta sulla pubblicazione; una
  verifica pubblicata in due sezioni può restare visibile in una e non
  nell'altra.
- *Cancellazione del docente* (art. 17): gli studenti perdono l'accesso,
  come oggi; la chiave per pubblicazione è l'opzione futura (domanda 1).
- *Amministratore di istituto*: vede solo le pubblicazioni nella sua scuola
  (S7); il conteggio «contenuti che puntano a una voce» del pannello del
  catalogo (ADR-035) conta le pubblicazioni, non più le righe.
- *Esportazione e registro*: pubblicazioni e copie nell'esportazione del
  docente, id nel registro, cascata alla cancellazione (S6).

**Trasversali.**

- *«Generale»* vale scuola per scuola (domanda 2).
- *Limiti* su pubblicazioni e copie (domanda 3), perché venti pubblicazioni
  non rendano illeggibile la barra.
- *I lettori da migrare, misurati il 13/9 sera*: 17 file leggono le tre
  colonne direttamente (`app/Services` 6, `app/Repositories` 6,
  `app/Controllers` 4, una vista), 6 gestiscono `publish_scope` e i bersagli
  (`TeacherContentController`, `TeacherContentRepository`,
  `ExerciseInserter`, `TeacherCapabilityPolicy`, `teacher-caps.js`,
  `sidepage-modal-content.js`), 41 leggono le sigle dalla vista. La fase 1
  li elenca uno per uno nel rapporto e ne spunta la migrazione, con la
  prova che li copre; un lettore senza prova è un lettore che si dimentica.

## Domande aperte

1. **Chiave per pubblicazione** (art. 17: gli studenti leggono anche dopo la
   cancellazione del docente): adesso no; se sì, in quale fase e con quale
   costo per le copie e le rotazioni.
2. **«Generale»** per scuola: basta la pubblicazione, o serve una
   pubblicazione «per tutte le classi» esplicita?
3. **Limiti**: quante pubblicazioni e quante copie per documento, e cosa
   mostrare quando un documento è in venti classi.
4. **La barra**: quando lo stesso documento ha due pubblicazioni nella stessa
   terna con stati diversi (non dovrebbe: l'unicità lo vieta), o in due
   scuole con lo stesso nome — come lo si distingue nell'elenco.

## Esecuzione

Le fasi entrano in pull request separate e nell'ordine. L'unione su `main`,
cioè la produzione, resta dell'utente.

### Fase 0 — la misura (fatta)

`tools/curriculum/pubblicazioni_fase0.php` (uscito con la migrazione 120, che
ha tolto le tabelle che interrogava), di sola lettura, eseguito su
produzione e sviluppo la sera del 13 settembre 2026; il rapporto è
[pubblicazioni-fase0-2026-09-13](../../docs/analysis/pubblicazioni-fase0-2026-09-13.md).
Tre risposte cambiano il piano:

- **In produzione nessun documento compare in un'altra scuola per
  coincidenza di sigle.** La fase 1 lì non toglie niente a nessuno. Il
  database di sviluppo invece ne ha 241 contenuti e 56 verifiche: è il banco
  per provarla.
- **252 contenuti su 396 sono `classes`**, quasi tutti con un solo bersaglio,
  che è la sezione dell'anno scritto nella riga («SCI/2 → SCI/2A»), e la
  terna della riga non è mai fra i bersagli. Per loro la terna della riga è
  dove il docente li tiene, non dove li vedono gli studenti. Se la principale
  prendesse lo stato della riga, l'anno aprirebbe il documento a tutte le sue
  sezioni: 41 contenuti pubblicati per la 2A comparirebbero in 2B. Quindi la
  principale di un documento `classes` nasce **in bozza**, e i bersagli nello
  stato della riga.
- **Zero righe** in `published_content_data` e `classe_keys_data`: escono
  nella fase 4 senza trasferire niente.

### Prima della fase 1 — #67

Le tre correzioni chieste qui sopra (la sezione sulla scuola attiva, la
scuola della credenziale verificata, i bersagli validati contro il catalogo),
e una quarta trovata preparando la fase 1: il dettaglio per id
(`/api/study/content/{id}.json`) lasciava leggere a **qualunque docente** il
contenuto di un collega, bozze e corpo decifrato compresi, e a uno studente
con account un pubblicato di un'altra scuola. È l'invariante S4 applicata
prima della tabella, con le regole di oggi.

### Fase 1 — come la si fa, e dove si scosta dal piano

- **La sincronizzazione la fanno trigger, in una direzione sola.** Le colonne
  della riga restano l'interfaccia di scrittura: le scrivono quattordici file
  fra applicazione e strumenti, e durante il rilascio il container vecchio
  serve ancora mentre le migrazioni girano, scrivendo righe che delle
  pubblicazioni non sanno niente. Le pubblicazioni sono l'interfaccia di
  lettura. Due trigger sulla riga e tre sui bersagli richiamano una procedura
  che ricalcola le pubblicazioni del contenuto dallo stato corrente, quindi
  nessuno scrittore dimenticato le lascia indietro. È il ragionamento della
  migrazione 038, che tenne allineati sigle e id finché le sigle non caddero.
  I trigger escono con la fase 4, quando le colonne cadono e le pubblicazioni
  si scrivono direttamente.
- **Due chiavi esterne invece di `source`.** `teacher_content_id` e
  `verifica_document_id`, una sola valorizzata (vincolo `CHECK`), ognuna a
  cascata sul suo documento: una colonna polimorfica non può avere una chiave
  esterna, e la cascata serve all'Art. 17. `primary_of_tc` vale l'id del
  documento sulla principale e `NULL` sulle altre, con chiave unica: una
  principale per documento, garantita dal database.
- **La migrazione verifica se stessa.** Il migratore registra come eseguita
  una migrazione i cui errori contengono «Duplicate entry» o «already
  exists», trattandoli come «già applicato»: un ricalcolo fallito passerebbe
  in silenzio. (Dal 23/9/2026 «Duplicate entry» fa fallire la migrazione,
  revisione A-68; «already exists» resta, e la verifica finale pure.) La 117 finisce con `pub_verifica_allineamento()`, che fallisce
  con un messaggio proprio se una sola riga non coincide con le sue
  pubblicazioni.
- **La vista `teacher_content` resta sulle colonne** fino alla fase 4.
  Finché i trigger le tengono uguali alla principale, le due letture danno
  le stesse righe, e ricreare una vista con `DEFINER` (la trappola del
  ripristino) non darebbe niente in cambio. Cambia quando le colonne cadono.
- **Credenziali senza scuola.** In produzione ce n'è una: vale per le scuole
  del docente, senza migrazione dei dati. Dalla #67 le credenziali nuove
  nascono con la scuola.

Misurato sulla copia del database di sviluppo, con il migratore vero: 409
principali e una pubblicazione di bersaglio, cioè i numeri della fase 0;
cinque trigger e tre procedure presenti in `information_schema`, non solo
nel registro delle migrazioni. Un primo tentativo si è fermato al terzo
contenuto su «Unknown column 't.indirizzo'»: una tabella derivata con
sottoquery correlate, rieseguita dentro una procedura, MariaDB 10.11 non la
risolve più. Il calcolo dei bersagli usa ora due join semplici, possibili
perché dalla 116 una sigla ha al più una voce per scuola.

Lo stesso su MariaDB 11.8 (la versione di produzione, in un contenitore di
prova): stessi numeri. In produzione l'utente delle migrazioni ha `CREATE
ROUTINE` e `TRIGGER`, il binlog è spento, e l'utente dell'applicazione ha i
privilegi a livello di database (compreso `EXECUTE`): la tabella nuova è
leggibile al sito senza toccare i permessi.

**I lettori** sono elencati uno per uno, con la prova che copre ciascuno, in
[pubblicazioni-fase1-lettori-2026-09-13](../../docs/analysis/pubblicazioni-fase1-lettori-2026-09-13.md).
Tutti chiedono alle pubblicazioni attraverso `App\Support\Pubblicazioni`.
Preparandoli sono uscite due porte che l'invariante S4 chiude qui:

- le **verifiche correlate** (`/api/study/related-verifiche.html`) cercavano
  per materia e titolo in tutte le scuole, senza classe né scuola, anche per
  studenti e ospiti: ora passano dallo stesso perimetro degli elenchi;
- il **dettaglio per id** leggeva le regole di oggi (#67); ora chiede anche
  che il contenuto sia pubblicato nella scuola dello studente o della
  credenziale.

**Le prove.** Due classi nuove: `PubblicazioniSincronizzazioneTest` (i
trigger, scrivendo come ogni scrittore: repository, UPDATE diretto, bersagli
con INSERT, UPDATE IGNORE e DELETE, cancellazione) e
`PubblicazioniPerScuolaTest` (due scuole con le stesse sigle, ogni pubblico
nei due versi). Controprove: su una copia del database senza trigger otto
prove di sincronizzazione su dieci diventano rosse (le due restanti erano
passate a vuoto e sono state rafforzate); con i lettori di prima otto prove
per scuola su dodici, e le quattro verdi sono nel verso «non deve cambiare».
In più la spec end-to-end `contenuti-per-scuola.spec.js`, che con i lettori
di prima fallisce sull'altra scuola. La diagnostica quotidiana richiama
`pub_verifica_allineamento()` e conta i trigger; l'esportazione dei dati del
docente include le pubblicazioni (S6).

**La suite end-to-end** ha trovato una conseguenza che le prove unitarie non
potevano vedere: sceglieva le pagine da provare sommando le terne per sigle
fra le scuole del docente, e sul database di sviluppo prendeva una verifica
dell'istituto 108 mentre la sessione stava nel 106. Prima della fase 1 la
pagina la mostrava per coincidenza di sigle; dopo no, ed era giusto così. Il
dump di sviluppo aveva infatti 37 verifiche del docente con le etichette del
108 e tutto il resto nel 106, mentre in produzione è tutto nel 106. Adesso la
scoperta sceglie le pagine in una scuola, la fixture del docente riporta la
sessione lì a ogni prova, le due spec che cambiano istituto lo rimettono, e
`tools/dev/e2e_allinea_scuola.php` (solo sviluppo, con prova a secco) ha
riallineato il dump. Giro completo dopo le correzioni: 602 verdi; le 32 rosse
sono i 31 confronti a pixel, identici su `main` con lo stesso database, e la
prova di SyncTeX già rossa prima.

**Che cosa cambia per chi usa il sito.** In produzione niente: la fase 0 non
ha trovato coincidenze di sigle, e i contenuti «per più classi» restano
visibili ai loro bersagli e a nessun altro. Cambia che un contenuto senza
nessuna etichetta non arriva più a studenti e ospiti per le vie laterali
(elenchi senza materia, dettaglio per id): una riga senza scuola non è
pubblicata da nessuna parte. Resta visibile al proprietario in ogni scuola.

### Fase 2 — «Dove vale» e «Duplica in…»

Nel modale di modifica di un contenuto c'è la sezione «Dove vale»: il posto
principale e gli altri, ognuno con il suo stato; «Pubblica anche qui» con la
catena scuola → indirizzo → classe → materia sulle voci spuntate; «Togli»;
«Duplica qui». Le regole stanno in `App\Services\Contenuti\DoveVale` e
`CopiaIndipendente`, le API in `TeacherPublicationsController`.

- **Migrazione 118.** Le pubblicazioni derivate dai bersagli diventano
  pubblicazioni del docente (`origine = 'docente'`), con la scuola, la terna e
  lo stato che avevano: chi le vedeva le vede ancora, e da adesso il docente le
  governa una per una. La procedura ricalcola solo la principale; la verifica
  segnala come difetto una pubblicazione derivata da un bersaglio. I bersagli
  restano in tabella fino alla fase 4, senza effetto.
- **I lettori confrontano indirizzo, classe e materia sulla pubblicazione**
  quando la domanda è per scuola (studenti, credenziali, barra e studio del
  docente): una pubblicazione in un'altra scuola può chiamare la materia
  diversamente («MATE»). Con la sigla della riga, in quella scuola, non si
  trova niente.
- **«Per più classi» non si crea più.** Un contenuto nuovo con quello scope
  riceve 400 `usa_dove_vale`, e il modale lo spiega; i contenuti che lo avevano
  lo tengono (la principale resta in bozza) e il modale dice che cosa vuol dire
  adesso. Tolti la lista delle classi del modale, l'API `my-classes` e il
  validatore dei bersagli della #67: non c'è più niente da validare.
- **Invarianti.** S1 (solo il proprietario: per un altro docente il contenuto
  «non esiste», 404), S2 (solo nelle scuole del docente), S3 (voci di quella
  scuola, attive, spuntate, con il corso giusto), S5 (la copia rilegge e
  ricifra il corpo, scrive il contratto in un file suo con la scuola di arrivo
  nello scope, ricifra il disegno della mappa, non copia il collegamento a
  Drive, eredita la classificazione per il diritto d'autore e
  `source_content_id`), S6 (ogni aggiunta, rimozione, cambio di stato e copia
  a registro con gli id).
- **Risposte alle domande aperte.** Limite: 40 pubblicazioni per contenuto
  (domanda 3). Pubblicare in più posti è la visibilità «più classi» di ADR-028:
  un profilo di docente limitato a una classe, in modalità Istituto, non
  aggiunge posti.

**Dove si scosta dal piano.**

- *Lo stato della riga fa da interruttore generale.* Il piano diceva che
  `visibility` della riga segue la principale. Nella fase 2 la riga resta la
  visibilità del documento: in bozza o archiviato non lo vede nessuno in nessun
  posto; pubblicato, lo vede ogni posto il cui stato è «pubblicato». È quello
  che i lettori facevano già (riga pubblicata e pubblicazione pubblicata), ed è
  una regola che il docente capisce. Che cosa ne resta quando la colonna cade è
  una decisione della fase 4.
- *«Sposta di classe» non è toccato.* Sposta la principale, attraverso la
  riga; una pubblicazione in più si sposta togliendola e aggiungendola da «Dove
  vale». Il piano voleva lo spostamento di qualunque pubblicazione: si fa
  quando la #64 è unita. *Fatto il 14/9 con la #64*: i posti in più che stanno
  nella classe di partenza seguono la principale, due posti nella stessa classe
  diventano uno solo quando non cambia chi vede che cosa (l'anno «per più
  classi» con il suo bersaglio nella sezione torna un posto solo, pubblicato),
  altrimenti restano e la pagina lo dice; la verifica si sposta intera
  (`SpostamentoDiClasse`).

**Le prove.** `DoveValeTest` (10: gli invarianti nei due versi, il registro,
il profilo limitato, i lettori con una sigla di materia diversa, il controller
che rifiuta «più classi» nuovo e accetta quello esistente) e
`CopiaIndipendenteTest` (5, con il deposito dei contratti in memoria). Contro
il codice della fase 1 le prove dei lettori e della copia falliscono. La spec
`dove-vale.spec.js` pubblica in un altro posto e lo ritrova nella barra di
quel posto, poi usa il modale vero: pubblica, toglie e duplica; il registro
delle attività conferma le azioni e non restano dati. La 118 è provata con il
migratore vero su MariaDB 10.11 e 11.8 e da schema vuoto. Giro completo della
suite: 604 verdi; le 32 rosse sono le stesse della fase 1 (i 31 confronti a
pixel e SyncTeX), e le due prove nuove non si saltano.

### Fase 3 — le verifiche

Le verifiche (`verifica_documents_data`, il TEX e il PDF di ogni variante)
stanno nella stessa tabella dei contenuti, con la loro colonna, e le leggono
le stesse domande: `App\Support\Pubblicazioni` prende la fonte
(`Pubblicazioni::VERIFICA`), e le regole restano in un posto solo.

- **Migrazione 119.** La principale di una verifica viene dalle etichette della
  riga, con due trigger (`trg_pub_vd_ai`, `trg_pub_vd_au`) e la procedura
  `pub_ricalcola_verifica`: si sposta con le etichette, se ne va senza, cade con
  la verifica. Lo **stato** invece non si deriva da niente (la riga di una
  verifica non ha visibilità): lo sceglie il docente, e i trigger non lo toccano.
  `pub_verifica_allineamento` controlla anche le verifiche.
- **Lo stato iniziale è «in bozza» per tutte, anche per le condivise.** Fino a
  qui una verifica arrivava agli studenti con account se era condivisa con i
  colleghi: ereditare la condivisione come stato l'avrebbe trasformata in un
  consenso a pubblicare, cioè la confusione che ADR-032 teneva aperta e che la
  DPIA dichiara esclusa. Misurato in produzione prima di decidere, con
  `tools/curriculum/pubblicazioni_fase3.php` (sola lettura, provato nei due
  versi su sviluppo): 25 varianti in 13 pacchetti, nessuna condivisa, e i due
  studenti con account non ne vedevano nessuna. Nessuno perde niente.
- **I lettori.** Studenti con account: pubblicate nella loro scuola, per la loro
  sezione o il loro anno, da un docente con un incarico nella sezione — prima
  bastava la condivisione, per sigle, con la scuola del primo collegamento e
  senza incarichi. Credenziali di classe: pubblicate del docente della
  credenziale, nella sua scuola, per la classe coperta (prima: lista vuota per
  costruzione). Barra del docente (`/api/verifica/list`): nella scuola attiva,
  con le sigle sulla pubblicazione. Pool, lettura fra colleghi e grant di
  istituto: la scuola in comune è una in cui la verifica è pubblicata. I
  docenti che chiamano l'elenco di studio vedono le verifiche della scuola
  attiva filtrate dall'ACL fra colleghi. Le operazioni del proprietario su
  tutte le sue verifiche (esportazioni, sincronizzazione, pacchetti) restano
  senza scuola, come nel censimento della fase 1.
- **«Dove vale» nel modale della verifica** (`DoveValeVerifica`), sulla
  verifica come la mostra il modale: tutte le varianti e tutte le versioni con
  lo stesso titolo e la stessa materia. Un posto aggiunto vale per tutte, uno
  stato cambiato cambia per tutte, principale compresa (è l'interruttore degli
  studenti); la principale non si toglie. Una versione salvata dopo nasce con la
  sola principale in bozza, e il posto si mostra «in parte».
- **«Duplica qui»** (`CopiaVerifica`) copia il pacchetto da cui si parte con
  tutte le sue varianti. Ogni file e il PDF si rileggono e si riscrivono in blob
  nuovi con la chiave corrente; i file condivisi fra varianti restano condivisi
  nella copia, nessuno con l'originale. La copia nasce privata e in bozza, con
  le etichette del posto scelto (anche nella selezione salvata) e la
  classificazione per il diritto d'autore dell'originale.
- **Pubblicare non è condividere.** Il blocco per il diritto d'autore resta
  sulla condivisione con i colleghi; pubblicare ai propri studenti non lo
  richiede, come per i contenuti.
- **Esportazione e diagnostica.** L'esportazione del docente include le
  pubblicazioni delle verifiche (S6); la diagnostica quotidiana attende sette
  trigger quando esiste la procedura delle verifiche, cinque prima.

**Dove si scosta dal piano.**

- *L'unità di una verifica è il titolo, non la riga.* Il piano parlava di
  documenti; per chi usa il sito una verifica sono le sue varianti e versioni,
  e il modale le mostra insieme. La regola del titolo base è quella del modale
  (il suffisso «— A_SOL»), rifatta sul server.
- *Nessuna colonna di provenienza per le copie delle verifiche.* I contenuti
  hanno `source_content_id` per il recupero dal pool, che per le verifiche non
  esiste: il legame con l'originale va a registro (`verifica_duplicata`).
- *«Sposta di classe»* resta com'era, come nella fase 2.

**Trovato strada facendo, fuori da questa fase.** Un ospite entrato con la
credenziale di classe riceve 401 da tutte le API di studio e la pagina di
studio lo manda al login: le rotte stanno nel gruppo `auth`, che la credenziale
non soddisfa, mentre i controller di ADR-032 l'ospite lo gestiscono. Misurato
con una sonda sulla suite end-to-end il 13/9. Non esce niente (il guasto è
chiuso), ma la modalità con la credenziale oggi non mostra contenuti. Aprire
quelle rotte è una modifica della superficie di autenticazione, da decidere a
parte: qui le regole delle credenziali sono provate sul repository, e valgono
dal giorno in cui le rotte le lasciano passare. *Corretto il 14/9/2026*, deciso
dall'utente: il gruppo `studio` (ADR-032, «Le rotte dello studio per
l'ospite»), e la spec `dove-vale-verifiche.spec.js` entra ora con una
credenziale vera.

**Le prove.** `VerifichePubblicazioniTest` (12: i trigger, la verifica di
allineamento che scatta, la condivisione che non pubblica, studenti di due
scuole e di due sezioni con e senza incarico, credenziali, barra, pool,
permessi, grant di istituto, il controller dell'elenco di studio per lo studente
e per il collega) e `DoveValeVerificaTest` (7: le varianti insieme, S1, S2, S3,
lo stato della principale, la versione «in parte», il profilo limitato, la copia
con un deposito in memoria e il suo annullamento). Controprove: dieci regole
rotte a turno (scuola, stato, incarichi, barra, pool, permessi, grant, unità
della verifica, blob della copia), ognuna fa diventare rossa almeno una prova;
senza i due trigger diventano rosse 16 prove su 19, e le tre verdi controllano
rifiuti che non dipendono dalle pubblicazioni. La spec
`dove-vale-verifiche.spec.js` prova le API (bozza iniziale per tutte le
varianti, la condivisione che non cambia lo stato, 404 per un altro docente) e il
modale vero (stato e copia), senza lasciare dati; studenti e credenziali non
sono raggiungibili dalla suite (nessuno studente con account, e il 401 qui
sopra). La 119 è provata con il migratore vero sulla copia di sviluppo (62
principali, tutte in bozza), su MariaDB 11.8 e da `schema.sql` più tutte le
migrazioni su 10.11 e 11.8. Giro completo della suite: 606 verdi; le 32 rosse
sono le stesse delle fasi 1 e 2 (i 31 confronti a pixel e SyncTeX).

### Fase 4 — la rimozione, in tre tempi

La regola dei due passi (`wiki/dev-workflow.md`) vuole che il codice smetta di
usare una cosa in un rilascio e che una migrazione la tolga nel successivo.
Misurato il 14/9 che cosa usa ancora ciò che la fase 4 toglie, la fase si divide
in tre tempi.

**4a — il codice smette di usare le tabelle in pensione** (sopra la fase 3,
nessuna migrazione).

- `published_content_data` e `classe_keys_data`, con le loro viste: zero righe
  in produzione (fase 0). Escono `ClasseKeyService` con la sua prova, le due
  esportazioni (anche da quella per le autorità, dove entra la sezione delle
  pubblicazioni, che mancava) e l'opzione `--classe` del banco di prova della
  cifratura.
- `content_target_classes`: senza effetto dalla 118. Il repository non scrive
  e non legge più i bersagli, anche se un chiamante li passa ancora; il
  dettaglio del contenuto non li espone. Esce
  `tools/curriculum/porta_contenuti_sulle_sezioni.php`, che dopo la 118 avrebbe
  fatto il contrario di quel che dice: passare un contenuto a «per più classi»
  ne mette la principale in bozza, e i bersagli non pubblicano più niente.
- La diagnostica attende i trigger per nome, secondo gli oggetti che esistono:
  il controllo vale prima e dopo la 120.
- Due classi di prova sondavano `content_target_classes` per decidere se
  saltarsi: con la tabella tolta si sarebbero saltate in silenzio. Sondano
  `content_publications`.
- **Trovato modificando il banco di prova della cifratura**: cancella le
  chiavi (`teacher_keys`) del docente di prova prima e dopo la misura. Su
  un'installazione vera, dove quel docente esiste, renderebbe illeggibili tutti
  i suoi contenuti. Ora si rifiuta di partire quando i dati stanno fuori dal
  repository, con la prova della diagnostica.

La misura su cui si appoggia la migrazione della 4b è
`tests/Unit/TabelleInPensioneTest.php`: nessun file dell'applicazione, delle
rotte o delle viste usa le tre tabelle in SQL o le classi che le servivano.
Controprova: rimettendo `ClasseKeyService` o il repository della fase 3, la
prova diventa rossa e dice file e riga d'uso; la prova del repository che non
scrive bersagli diventa rossa con il repository della fase 3.

**Tre documenti di conformità descrivevano ancora il meccanismo che non è mai
partito.** Corretti il 14 settembre 2026 su decisione dell'utente («4. va bene
modifica documenti legali»): registro 1.6, informativa 2.6, DPIA 1.6, con i
PDF; la bozza della nota al DPO è in `docs/dpo/pacchetto-scuola/Email-al-DPO.md`,
e la manda l'utente. `TabelleInPensioneTest` controlla ora anche che i documenti
di `docs/privacy/` e `docs/legal/` non le descrivano più. Com'erano:

- `docs/privacy/registro-trattamenti.md`, B.4: «Copia cifrata del body docente
  in `published_content`», conservazione con «rotation classe_key annuale» e
  «classe_keys decoupled da teacher KEK — sopravvive ad Art. 17 docente».
  Nella realtà gli studenti leggono il contenuto del docente dove è pubblicato
  (`content_publications`), e con la cancellazione del docente perdono l'accesso
  (decisione 6).
- `docs/privacy/informativa.md`, §5: la riga di conservazione «classe_keys
  (pubblicazione studenti) | 1 anno scolastico». Toccarla vuol dire una versione
  nuova dell'informativa e del testo registrato nei consensi.
- `docs/privacy/dpia.md`, §1: lo schema cita `classe_keys + published_content`.

Allora non si erano corretti qui perché cambiano ciò che è dichiarato agli
interessati e al DPO: la decisione e la formulazione erano dell'utente. Nessuno
dei tre descriveva un trattamento in più rispetto alla realtà: descrivevano una
copia che non esisteva.

**4b — la migrazione 120 toglie le tre tabelle** (sopra la 4a, da unire solo
quando la 4a è in produzione): i trigger dei bersagli, le viste
`published_content` e `classe_keys` (due viste con `DEFINER` in meno, la
trappola del ripristino), le tre tabelle. Si ferma da sola, prima di togliere
qualunque cosa, se una delle due tabelle cifrate non è vuota. Con le tabelle
escono le prove che scrivevano bersagli a mano (la sincronizzazione ne tiene il
senso: una pubblicazione derivata da un bersaglio è un difetto, e la verifica
di allineamento lo dice) e lo strumento della fase 0, che le interrogava; il
suo rapporto resta in `docs/analysis`.

Misurato con il migratore vero: su una copia del database di sviluppo con una
chiave di classe inserita la migrazione si ferma con il suo messaggio e non
toglie niente (cinque oggetti e sette trigger ancora lì); sulla copia vuota
toglie le cinque tabelle e viste, restano i quattro trigger delle righe e delle
verifiche, la verifica di allineamento regge e una seconda esecuzione non fa
niente. Lo stesso su MariaDB 11.8 e da `schema.sql` con tutte le migrazioni su
10.11 e 11.8. Con la 120 applicata al database di sviluppo (è la misura della
riga `SICUREZZA`: il codice della 4a senza le tabelle) il giro completo della
suite dà 606 verdi e le stesse 32 rosse delle fasi precedenti; PHPUnit 1346
verdi, nessuna saltata.

**4c — le tre etichette della riga, in tre rilasci.** `indirizzo_id`,
`classe_id` e `subject_id` (`materia_id` per le verifiche) le scrivevano
undici file e le leggevano trenta attraverso le viste; i trigger ne derivavano
le principali. Toglierle senza una finestra in cui il container vecchio e il
nuovo si contraddicono chiede tre rilasci in produzione, ognuno dopo il
precedente:

1. **4c-1** — l'applicazione scrive anche la principale, in modo idempotente
   con i trigger, e le viste ricavano le sigle dalla principale;
2. **4c-2** — l'applicazione smette di scrivere le colonne, e una migrazione
   toglie i trigger, di cui il rilascio 1 non ha più bisogno;
3. **4c-3** — una migrazione toglie le colonne.

Il 14/9 l'utente ha scelto la seconda delle due strade per la `visibility`
(«vai con la 2»): resta come interruttore del documento, e la colonna non cade
(decisione 5). Quando la colonna cadesse, un documento in bozza con un posto
pubblicato diventerebbe visibile lì, e la migrazione dovrebbe riportare in
bozza quei posti perdendo lo stato scelto.

**4c-1 — l'applicazione scrive la principale** (migrazione 121).

- **`App\Support\PostoPrincipale`** è il punto unico in cui l'applicazione
  scrive la principale, con le regole delle procedure: la scuola dalla materia,
  poi dalla classe, poi dall'indirizzo; senza etichette niente principale; per
  un contenuto lo stato dalla riga (bozza se «per più classi»), per una
  verifica lo stato del docente, che uno spostamento conserva. Sposta la
  pubblicazione che c'è invece di cancellarla e ricrearla (i trigger dei
  contenuti la ricreano ancora finché ci sono: dal rilascio 2 il suo id resta). Lo chiamano tutti gli scrittori delle etichette o dello stato:
  `TeacherContentRepository` (creazione, modifica, «Da categorizzare»),
  `VerificaDocumentRepository::create`, l'importazione dei pacchetti e
  «Sposta di classe». Con i trigger ancora al loro posto il risultato è lo
  stesso: i trigger scrivono prima, l'applicazione riscrive uguale.
- **Chi leggeva le colonne direttamente** legge le viste: «Da categorizzare»,
  le righe di una verifica (`righeDellaVerifica`), il controllo del titolo nella
  copia di una verifica, gli elenchi di «Sposta di classe». «Da categorizzare»
  guarda sulla principale anche «solo se vuoto».
- **Le viste** `teacher_content` e `verifica_documents` prendono etichette e
  sigle dalla principale: stesse colonne, stesso ordine, stesso `DEFINER`. La
  scrittura attraverso la vista (la categoria, in `TeacherContentController`)
  funziona ancora: provato su sviluppo e prova.
- **La guardia delle voci** (`trg_curriculum_no_orphan`) conta anche
  `content_publications`: quando le colonne non saranno più scritte, una voce
  usata solo da una principale sarebbe stata cancellabile, e la chiave esterna a
  `SET NULL` avrebbe tolto il posto in silenzio. La ricrea la 121, con lo stesso
  elenco di `tools/curriculum/apply_no_orphan_guard.php`.
- **La migrazione verifica se stessa**: prima di cambiare le viste confronta,
  riga per riga, le etichette con quelle della principale e si ferma se una non
  coincide; dopo, rifà il confronto attraverso le viste e chiama
  `pub_verifica_allineamento()`.
- **«Sospeso»**: in «Dove vale» un posto «pubblicato» di un contenuto in bozza
  o archiviato si mostra sospeso, con una riga che dice perché, e il pannello
  segue il selettore della visibilità del modale mentre lo si cambia. Le
  verifiche non hanno un interruttore.

Misurato prima di scrivere la 121, in produzione e in sviluppo (sola lettura):
396 contenuti, 390 con etichette, **nessuno** con etichette ma senza scuola; 25
verifiche, 23 con etichette, nessuna senza scuola; `curriculum_entries.institute_id`
è `NOT NULL`, quindi un'etichetta trova sempre la sua scuola e le viste nuove
danno le stesse sigle per tutte le righe.

**Le prove.** `PostoPrincipaleTest` (6) non si appoggia ai trigger: le righe
nascono senza etichette, e la principale la scrive solo l'applicazione; la vista
deve dare le sigle della principale con le colonne vuote. Controprove: sei regole
rotte a turno in `PostoPrincipale` (lo scope «per più classi», lo spostamento
che ricrea, lo stato della verifica, lo stato del contenuto, la principale che
resta senza etichette, l'ordine della scuola), le viste della 104 e della 058
rimesse, la guardia senza pubblicazioni: ognuna fa diventare rossa almeno una
prova. La migrazione nei due versi, con il migratore vero su una copia del
database di prova: con una principale spostata a mano si ferma (esito 255) e la
vista resta quella di prima; riallineata, passa. La spec `dove-vale.spec.js`
guarda il «sospeso» comparire, sparire con «Pubblicato» e tornare con «Bozza».

**La misura del rilascio 2, fatta adesso.** Su `pantedu_test` senza i quattro
trigger (come sarà dopo la 4c-2), con il codice della 4c-1: 1380 prove PHPUnit,
23 rosse, tutte in prove che scrivono le colonne a mano o che provano i trigger
stessi (`PubblicazioniSincronizzazioneTest` 8, `SpostamentoDiClasseTest` 9 per
le righe create con un INSERT, `VerifichePubblicazioniTest` 3, e una ciascuna
`AnnoCopreSezioniTest`, `PubblicazioniPerScuolaTest`,
`PublishScopeVisibilityTest` per un UPDATE diretto dello scope). Nessuna passa
da uno scrittore dell'applicazione: sono le prove da adattare nella 4c-2.
Trigger rimessi come nella 117 e nella 119.

**4c-2 — le colonne non si scrivono più, e i trigger escono** (migrazione
122, sopra la 4c-1 in produzione).

- **Gli scrittori** non mettono più le etichette nella riga: la creazione e la
  modifica dei contenuti, «Da categorizzare», la creazione delle verifiche,
  l'importazione, «Sposta di classe». Il posto lo scrive solo
  `PostoPrincipale`; della riga cambia `updated_at`.
- **La materia delle verifiche resta nella riga — scostamento dal piano.**
  Preparando la 4c-3 è emerso che `verifica_documents_data.materia_id` è la
  chiave dell'indice unico `uq_verif_doc_title_fk` (docente, materia, titolo,
  variante, versione). L'applicazione controlla i doppioni prima di salvare
  (`findExistingForBatch`), ma l'indice è la rete contro due salvataggi
  simultanei della stessa verifica, e con la colonna vuota un indice unico non
  ferma più niente (i NULL sono tutti diversi). La materia è parte di che
  cos'è una verifica (anche l'unità di «Dove vale» la usa), non solo di dove
  sta: resta scritta alla creazione, la principale ne porta la stessa, e la
  verifica di allineamento controlla ogni giorno che coincidano e che una
  verifica con la materia abbia la principale (in produzione: 25 verifiche,
  zero differenze). Toglierla avrebbe voluto dire spostare prima quella
  protezione, per esempio in un blocco con nome nel salvataggio; il 14/9
  l'utente ha deciso che resta (decisione 8). La 4c-3 toglie quindi `indirizzo_id`, `classe_id`, `subject_id`
  dai contenuti e `indirizzo_id`, `classe_id` dalle verifiche. Una modifica delle sole
  etichette, che nel repository non avrebbe più avuto colonne da scrivere, ha
  la sua scrittura di `updated_at`: la prima versione tornava `false` e, dal
  modale, 404; l'ha presa la prova riscritta della sincronizzazione.
- **Migrazione 122**: ricrea `pub_verifica_allineamento` senza le colonne della
  riga (lo stato di ogni principale di contenuto uguale a quello della riga,
  nessuna pubblicazione dai bersagli, ogni pubblicazione nella scuola delle sue
  voci, la materia di ogni verifica uguale a quella della sua principale) e la
  esegue **prima** di togliere qualunque cosa; poi toglie i quattro
  trigger e le tre procedure di ricalcolo, e la riesegue. Misurato in
  produzione prima di scriverla: 664 pubblicazioni, zero su tutti e tre i
  controlli.
- **La diagnostica** si aspetta i trigger solo finché c'è la loro procedura, e
  segnala un trigger rimasto senza (ogni scrittura della riga fallirebbe).
- **Gli strumenti.** Tre scrivevano solo le colonne e dopo la 122 avrebbero
  risposto «fatto» senza fare niente: `tools/curriculum/archivia_per_sezione.php`
  e `sposta_anni_in_sezione.php`, sostituiti dalla pagina «Sposta di classe»
  (#64), e `restore_indirizzo_from_snapshot.php`, il ripristino del 2 settembre.
  Sono usciti. `tools/contenuti/etichetta.php`,
  `tools/curriculum/deduci_categorie_da_titolo.php`,
  `tools/dev/e2e_allinea_scuola.php`, `tools/dev/seed_e2e_fixtures.php` e
  `tools/migrate_terna_consolidate.php` leggono le viste e scrivono con
  `PostoPrincipale`.
- **Le prove.** `PubblicazioniSincronizzazioneTest` prova l'applicazione invece
  dei trigger (ogni scrittura dal repository, la verifica di allineamento nei
  due versi sullo stato e sulla scuola); le prove che scrivevano le colonne a
  mano scrivono la principale come l'applicazione. Tre classi si saltavano se
  mancava la procedura `pub_ricalcola_verifica`, che la 122 toglie: si
  sarebbero saltate in silenzio. Ora guardano il registro delle migrazioni.
- **`SenzaEtichetteNellaRigaTest`** è la misura della riga `SICUREZZA` della
  4c-3: fallisce se un file dell'applicazione, delle rotte, delle viste o degli
  strumenti (tranne quelli d'archivio) scrive o legge le tre colonne della riga,
  anche con il nome della tabella in una variabile. Controprove: rimettendo
  l'INSERT con le etichette, la lettura della verifica dalla tabella,
  l'UPDATE della classe nello spostamento (tabella in una variabile, che la
  prima versione del riconoscitore non vedeva) o la lettura con l'alias in
  `etichetta.php`, diventa rossa e dice file e uso.

Misurato: la 122 con il migratore vero su una copia del database di prova, nei
due versi (una principale nello stato sbagliato: si ferma, i quattro trigger
restano; riallineata: li toglie); la diagnostica nei due versi (regge senza
trigger; con un trigger rimasto senza procedura, «ROTTO»). PHPUnit con la 122
applicata: 1380 verdi.

**4c-3 — le colonne escono** (migrazione 123, sopra la 4c-2 in produzione).

- **Escono** `indirizzo_id`, `classe_id` e `subject_id` da `teacher_content_data`,
  e `indirizzo_id` e `classe_id` da `verifica_documents_data`, con le loro chiavi
  esterne. La materia delle verifiche resta (4c-2).
- **La guardia delle voci** si ricrea **prima** delle colonne, senza di loro:
  con la guardia della 121 sul database senza colonne ogni cancellazione di una
  voce di curriculum cade («Unknown column 'indirizzo_id'»), misurato.
  `tools/curriculum/apply_no_orphan_guard.php` ha lo stesso elenco.
- **Le prove** che leggevano le colonne per dire che erano vuote
  (`PostoPrincipaleTest`, `PubblicazioniSincronizzazioneTest`) guardano che
  non ci siano più; quella della fusione di istituti controlla che la
  principale di un contenuto passi alla voce e all'istituto canonici.

Misurato per la riga `SICUREZZA`: tolte le colonne dal database di prova, la
suite PHPUnit con il codice della 4c-2 dà 1383 prove e 4 errori, tutti nelle
tre classi che leggevano o scrivevano le colonne a mano (adattate qui).

## Riferimenti

- [pubblicazioni-fase0-2026-09-13](../../docs/analysis/pubblicazioni-fase0-2026-09-13.md) — la misura della fase 0
- [[decisions/ADR-035-catalogo-istituto-centralizzato]] — il vocabolario per istituto e le spunte
- [[decisions/ADR-032-deployment-scenarios]] — i tre scenari, le credenziali, gli incarichi
- [[decisions/ADR-028-institute-governance-teacher-capabilities]] — chi decide che cosa
- [scenari-e-condivisione-2026-09-13](../../docs/analysis/scenari-e-condivisione-2026-09-13.md) — la rilettura che ha portato qui
- `database/migrations/069_*` — `publish_scope` e `content_target_classes`; Phase 25.D6 — `published_content` e chiavi di classe
