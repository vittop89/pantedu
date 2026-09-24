---
tags:
  - documentazione/adr
date: 2026-09-06
tipo: adr
status: accettato
aliases: ["ADR-035", "catalogo istituto", "curriculum centralizzato", "vocabolario e spunte"]
cssclasses: []
---

# ADR-035 — Il catalogo è dell'istituto: un vocabolario, e i docenti lo spuntano

## Stato

**ACCETTATO ED ESEGUITO** il 2026-09-13 per le fasi 0, 1, 2 e 4 (migrazioni
113, 114 e 115, ramo `feat/catalogo-istituto`); la fase 3 — cancellare le
copie e la colonna `owner_user_id` — sta nel ramo successivo con la migrazione
116, per la regola dei due passi (il codice smette di usare una colonna in un
rilascio, la colonna cade in quello dopo). Com'è andata, con le misure, nella
sezione «Esecuzione» in fondo. Sostituisce il modello introdotto dalle
migrazioni 042 e 044 senza tornare al pivot che quelle avevano tolto.

## Contesto

`curriculum_entries` è l'unica tabella del vocabolario: indirizzi, classi
(cioè sezioni e anni) e materie di ogni istituto. Ma le sue righe sono due cose
diverse, e nessuna colonna dice quale sia quale:

| | riga | significato | chi la scrive |
|---|---|---|---|
| **vocabolario** | `owner_user_id IS NULL` | «qui si insegna Matematica», «qui esiste la 3A dello scientifico» | l'importatore MIUR, l'amministratore |
| **attivazione** | `owner_user_id = docente` | «questo docente insegna Matematica» | il docente, dal proprio profilo |

L'attivazione non è un riferimento al vocabolario: è una **copia** della riga,
con il proprio `label`, `grp`, `indirizzo`, `active`, `shared_with_pool`. Una
volta creata vive per conto suo, e da quel momento la stessa materia della
stessa scuola esiste in tante versioni quanti sono i docenti che la usano.

`TeacherSubjectService` descrive a parole il modello giusto — «una riga con
`owner_user_id` NULL è il VOCABOLARIO dell'istituto; una riga con
`owner_user_id` = docente è l'ATTIVAZIONE» — e poi la implementa con una copia.
Il resto del codice non è nemmeno d'accordo su quale sia il modello: dentro
`CurriculumService`, il commento di `all()` dice che indirizzi e classi sono
per istituto e le materie per docente, quello di `loadFromDb()` dice che tutti
e tre i kind sono per docente. Quando due commenti nello stesso file si
contraddicono, il modello non è più nella testa di nessuno.

### Cosa produce, in numeri

Database di sviluppo, **tre docenti**:

| kind | righe di vocabolario | copie dei docenti | codici distinti |
|---|---|---|---|
| classi | 27 | 10 | 17 |
| indirizzi | 9 | 6 | 7 |
| materie | 22 | 16 | 11 |

Trentadue righe su novanta sono ripetizioni dello stesso `(kind, code,
istituto)`. Con tre docenti. In una scuola vera i docenti sono settanta.

I riferimenti dei contenuti seguono le copie:

| tabella | colonna | punta al vocabolario | punta a una copia |
|---|---|---|---|
| `teacher_content_data` | `classe_id` | 37 | 366 |
| `teacher_content_data` | `subject_id` | 37 | 378 |
| `verifica_documents_data` | `classe_id` | 0 | 530 |
| `verifica_documents_data` | `materia_id` | 0 | 580 |
| `print_info_data` | `materia_id` | 0 | 30 |
| `risdoc_compilations_data` | `classe_id` | 0 | 5 |
| `exercises_data` | `classe_id` | 57 | 0 |
| `teacher_access_credentials_data` | `classe_id` | 1 | 0 |

2.256 riferimenti su 2.425 puntano a una copia. Le due eccezioni sono
istruttive: gli esercizi non hanno un docente e hanno sempre usato il
vocabolario, e la credenziale di classe scritta la settimana scorsa risolve la
classe con `CurriculumLookup::classeAnchorForIndirizzo`, cioè sul vocabolario.
Dove il modello centralizzato è già in uso, funziona.

### I tre sintomi

**Un'aula, molte identità.** «2A» del Esempio è una riga per ogni docente che
la usa, più l'ancora dell'istituto. Solo l'ancora porta il corso: l'importatore
MIUR scrive `indirizzo` esclusivamente sulle righe `owner_user_id IS NULL`,
perché è lì che il dataset delle adozioni deposita l'accoppiata sezione↔corso.
Le copie nascono senza corso e non lo ricevono mai. È esattamente il bug visto
il 5 settembre: uno studente entrato in 2A si vedeva «2A Artistico» perché il
corso arrivava dal contesto e non dalla classe.

**Le etichette divergono.** `SCN` è già scritto in due modi, «Scienze» e
«Scienze naturali», su tre righe. Nessuno ha sbagliato: ognuno ha rinominato la
propria copia. Non esiste un posto dove correggere l'etichetta della scuola,
perché la scuola non ha un'etichetta.

**La lettura ripara a valle.** Poiché il vocabolario non è interrogabile,
almeno tre percorsi deduplicano per `code` a ogni richiesta:
`CurriculumService::listActiveForInstitute` (usata dalla registrazione e dalla
sidebar dello studente), `CurriculumService::allActiveForInstitute`,
`CurriculumController::dedupActiveByCode`. Ognuna sceglie una riga a caso fra
le copie e butta le altre. Funziona finché le copie sono d'accordo.

### Come ci siamo arrivati

Prima della migrazione 042 il modello era quello giusto: vocabolario per
istituto più un pivot `curriculum_users` che diceva quali voci ogni docente
usasse. Le migrazioni 042 (materie) e 044 (indirizzi e classi) hanno clonato le
righe per docente e hanno cancellato il pivot.

Il motivo era legittimo: serviva attaccare al legame docente↔voce degli
attributi che il pivot non aveva — `shared_with_pool` per la condivisione fra
colleghi, e la possibilità di disattivare una voce per un docente senza
toglierla agli altri. La conclusione sbagliata è stata che, per dare attributi
a una relazione, bisognasse duplicare l'entità. Gli attributi di una relazione
stanno sulla relazione.

## Decisione

**Il catalogo è dell'istituto. Il docente non possiede voci: le spunta.**

1. **`curriculum_entries` torna a essere solo vocabolario.** Identità di una
   voce: `(kind, code, institute_id)`. `code`, `label`, `grp` e — per le
   classi — `indirizzo` appartengono alla scuola. `owner_user_id` sparisce.

2. **Il legame docente↔voce diventa una riga di relazione**, `curriculum_teacher`:

   | colonna | ruolo |
   |---|---|
   | `curriculum_id`, `user_id` | la coppia, unica |
   | `active` | il docente può togliersi una voce senza toglierla alla scuola |
   | `shared_with_pool` | la condivisione è una proprietà del legame, non della materia |
   | `label_override` | nullable: come il docente vuole vederla nei propri menù |
   | `created_at` | quando l'ha presa |

   È `curriculum_users` con le colonne che ne giustificavano la rimozione.

3. **I contenuti puntano alla voce dell'istituto.** Il filtro per docente esiste
   già: ogni contenuto porta il proprio `teacher_id`. La riga di categoria per
   docente non ha mai aggiunto un'informazione che il contenuto non avesse.

4. **Promuovere non è inventare.** Alla migrazione, una copia il cui codice non
   esiste nel vocabolario dell'istituto **diventa** voce di vocabolario,
   marcata `origine = 'docente'`, e finisce in un elenco che l'amministratore
   rivede. La riga esisteva già in quell'istituto: non si sta aggiungendo alla
   scuola un corso che non ha, si sta dicendo ad alta voce ciò che era già
   scritto. Il caso opposto — inventare un'ancora che nessuno aveva mai creato
   — resta vietato, come già stabilito in `prune_cloned_curriculum.php`.

5. **Il vocabolario ha una provenienza dichiarata**, colonna `origine`:
   `miur` (l'importatore delle adozioni, unico scrittore), `istituto`
   (l'amministratore: laboratori, potenziamento, progetti, tutto ciò che nel
   dataset MIUR non c'è), `docente` (promosso dalla migrazione o proposto e poi
   approvato). Serve a due cose concrete: un nuovo import non deve toccare ciò
   che la scuola ha aggiunto a mano, e l'amministratore deve poter distinguere
   il proprio catalogo dai residui.

6. **Il docente rinomina solo per sé.** `label_override` cambia ciò che vede
   lui; il codice e l'etichetta della scuola non si toccano da un profilo
   personale. È l'unico uso legittimo che le copie avevano, e non si perde.

### Cosa non cambia

Lo scoping per istituto (migrazione 036), la governance di ADR-028 — è
l'amministratore che decide indirizzi e classi, il docente sceglie fra quelli —
l'importatore MIUR, gli esercizi, le credenziali di classe, il pool di
condivisione dei contenuti. Il modello proposto è quello che ADR-028 già
descrive: qui viene solo reso vero nello schema.

### L'alternativa scartata

Tenere le copie e aggiungere `parent_id` verso l'ancora, leggendo etichetta e
corso dal genitore. Costa molto meno: nessun riferimento da ri-puntare. Ma
lascia due righe per ogni coppia (docente, codice) per sempre, lascia la
deduplicazione per `code` in ogni lettura, e lascia la stessa aula con N
identità — cioè la causa del bug del 5 settembre. Compra una settimana e la
restituisce ogni volta che qualcuno deve chiedersi quale riga sia la classe.

## Migrazione in quattro fasi

Nessuna fase è un big-bang, e ognuna è verificabile da sola.

**Fase 0 — la verità, prima di toccare.** Uno script di sola lettura che
riporta: gruppi duplicati per `(kind, code, istituto)`; codici presenti come
copia e assenti dal vocabolario; etichette divergenti; righe con
`institute_id IS NULL` (i «globali legacy» della 036) e chi le usa; **chiavi di
classe agganciate a copie** (vedi sotto). È un cancello: la fase 2 non parte
finché questo elenco non è vuoto o spiegato.

**Fase 1 — la relazione, senza rimuovere niente.** Si crea
`curriculum_teacher` e la si riempie dalle copie esistenti. Le letture passano
alla relazione; le copie restano dove sono e continuano a essere scritte.
Reversibile cancellando una tabella.

**Fase 2 — il ri-puntamento.** Per ogni tabella di contenuti, il riferimento
passa dalla copia alla voce di istituto con lo stesso `(kind, code, istituto)`.
È l'operazione inversa e simmetrica di quella che la migrazione 044 ha già
eseguito nella direzione opposta, quindi la forma della query è nota. La
garanzia è un conteggio per `(docente, code)` prima e dopo, che non deve
cambiare in nessuna tabella: un contenuto non cambia classe, cambia la riga che
la rappresenta.

**Fase 3 — la rimozione.** Si cancellano le copie e la colonna
`owner_user_id`. Escono di scena `dedupActiveByCode` e le due letture
deduplicanti di `CurriculumService`, che a quel punto starebbero deduplicando
un insieme già unico.

**Fase 4 — l'anagrafica.** Colonna `origine`, pagina di revisione per
l'amministratore, importatore allineato, e riempimento di `indirizzo` sulle
classi del vocabolario dai dati delle adozioni: oggi ne hanno il corso 7 su 27
in un istituto e 0 su 10 nell'altro, ed è quel campo a far scegliere il liceo
giusto in registrazione e nelle credenziali.

## Il rischio da chiarire prima della fase 2: le chiavi di classe

`classe_keys_data` ha un indice unico su
`(indirizzo_id, classe_id, anno_scolastico, key_version)`, e quelle sono
colonne di **id**, non di codice. Se in produzione esiste una chiave creata
contro l'id di una copia, il ri-puntamento la fa collidere con l'unico. E se
due docenti hanno prodotto due chiavi per la stessa aula reale, il problema non
è l'indice: i contenuti pubblicati con la prima chiave non si decifrano con la
seconda, e ri-puntare significherebbe perderli.

In locale la tabella è vuota, quindi la domanda è aperta per il solo ambiente
che conta. La fase 0 deve rispondere. Se le chiavi su copie esistono, non si
ri-punta: si passa dalla rotazione di `ClasseKeyService`, che ri-avvolge il
materiale sulla chiave giusta, e solo dopo si ri-punta.

## Conseguenze

**Per il docente.** Il profilo smette di essere un editor del catalogo e
diventa un elenco di caselle: queste sono le materie della scuola, spunta le
tue. Chi ha bisogno di un nome diverso lo scrive in `label_override`. Chi ha
bisogno di una voce che la scuola non ha la propone, e l'amministratore la
approva — che è già come funzionano le sezioni.

**Per l'amministratore.** Esiste finalmente un posto dove l'etichetta si
corregge una volta per tutti, e un elenco di ciò che è entrato nel catalogo
senza passare dal dataset.

**Per lo studente.** Nessun cambiamento visibile, ma la sidebar e la
registrazione smettono di scegliere una riga a caso fra le copie.

**Per gli scenari (ADR-032).** Nello scenario 1 il vocabolario è di chi gestisce
l'istanza e la relazione è una formalità, ma il codice resta lo stesso nei tre
scenari. Nello scenario 2 il vocabolario resta per istituto: due docenti della
stessa scuola lo condividono, due di scuole diverse no — come già oggi. Nello
scenario 3 è la scuola a possederlo, che è quanto ADR-028 prescrive.

**Sul rischio.** La fase 2 tocca i riferimenti di tutti i contenuti esistenti.
È la ragione per cui le fasi 0 e 1 esistono, e per cui la fase 2 va eseguita
con una copia del database fresca e un conteggio prima/dopo per tabella.

## Domande aperte — chiuse il 2026-09-13 dalla fase 0

1. **Le chiavi di classe in produzione** stanno su copie? **No**: la tabella è
   vuota (0 chiavi), quindi nessuna collisione possibile sull'indice unico.
2. **Le righe con `institute_id IS NULL`**: **non ce ne sono**, né in
   produzione né in sviluppo. La domanda decade.
3. **`grp`** resta della scuola: nessuna copia in produzione aveva un `grp`
   diverso dalla sua ancora (0 etichette divergenti, 0 gruppi con più testi).

## Esecuzione (2026-09-13)

La fase 0 è `tools/curriculum/catalogo_fase0.php` (nel repository fino alla
fase 3, poi nella storia di git), di sola lettura, eseguita
sul database di produzione e su quello di sviluppo; il rapporto è in
[catalogo-istituto-fase0-2026-09-13](../../docs/analysis/catalogo-istituto-fase0-2026-09-13.md).
Le sue risposte hanno reso la fase 2 un'operazione senza sorprese: ogni copia
ha la propria voce di istituto (0 da promuovere), nessuna etichetta diverge,
nessuna chiave di classe, nessun riferimento orfano.

Che cosa è stato fatto, e dove diverge dal piano:

- **Fase 1** — migrazione 113: `curriculum_teacher` (`curriculum_id`,
  `user_id`, `active`, `shared_with_pool`, `label_override`), riempita dalle
  copie; le copie che non avessero un'ancora vengono promosse a vocabolario
  con `origine = 'docente'` (in produzione: nessuna). Diversamente dal piano,
  anche `shared_with_pool` passa alla relazione: è il docente che condivide,
  non la voce.
- **Fase 2** — migrazione 114: i riferimenti di tutte le tabelle che puntano a
  `curriculum_entries` passano dalla copia all'ancora con lo stesso
  `(kind, code, istituto)`. Il conteggio per tabella prima/dopo è nel
  rapporto: nessun contenuto cambia classe, cambia la riga che la rappresenta.
- **Letture e scritture** — `CurriculumService`, `TeacherSubjectService`,
  `CurriculumLookup`, `PoolRepository`, `TeacherContentRepository`,
  `TeacherUncategorizedController` e l'importatore MIUR leggono e scrivono
  la relazione. Le copie **non vengono più scritte** da nessuna parte: da
  questo rilascio in poi `owner_user_id` è solo letto, ed è il presupposto
  della fase 3. Spuntare una voce dal profilo è una riga di relazione, e
  l'etichetta personale è `label_override`. Una lettura era rimasta indietro:
  il pool dei colleghi leggeva «condividi tutta la materia» dalla voce della
  scuola invece che dalla spunta — corretto la sera dello stesso giorno
  (`fix/pool-materia-condivisa`, vedi
  [scenari-e-condivisione-2026-09-13](../../docs/analysis/scenari-e-condivisione-2026-09-13.md)).
- **Fase 4** — colonna `origine` (`miur` · `istituto` · `docente`), pagina
  `/admin/institutes/{id}/catalogo` con aggiunta, rinomina per tutti,
  accensione, accettazione delle voci promosse ed eliminazione (mai di una
  voce con spunte o contenuti sopra), ogni mossa con motivazione a registro.
  Il riempimento di `indirizzo` sulle classi del vocabolario non è stato
  toccato in questo passaggio.
- **Le sezioni «A»** — i contenuti dell'autore in produzione stanno sugli anni
  secchi (1…5) e l'istituto ha la sezione A per ognuno: lo spostamento è
  `tools/curriculum/sposta_anni_in_sezione.php`, uno strumento con prova a
  secco da eseguire in produzione dopo il rilascio, non una migrazione, perché
  è una scelta su dati e non sullo schema. *Dal 14/9/2026 lo fa la pagina
  «Sposta di classe» (#64), dal sito; lo strumento è uscito con la fase 4c-2 di
  [[decisions/ADR-037-pubblicazioni-e-copie]], perché scriveva colonne che non
  si leggono più.*
- **Il profilo a caselle** (ramo `feat/catalogo-spunte-ui`, la sera del
  13/9): la conseguenza «per il docente» scritta qui sotto, che la fase 4 non
  aveva ancora realizzato. Le tre schede di «Curriculum dell'istituto attivo»
  sono l'elenco del vocabolario della scuola con una casella per voce
  (`institute_vocabolario` nella risposta di `/api/teacher/curriculum`); le
  classi stanno sotto il loro corso e solo per gli indirizzi spuntati, le
  materie si spuntano dopo almeno una classe; togliere la spunta spegne la
  voce e conserva il nome personale. I selettori della barra laterale seguono
  lo stesso ordine — indirizzo, classe, materia — e restano chiusi finché
  quello a monte è vuoto; con una sola voce la scelgono, con più voci
  aspettano (`js/modules/core/sidebar-cascade.js`), e si ricaricano da soli
  quando il profilo cambia le spunte (`fm:curriculum-changed`). Le materie non
  sono filtrate per classe: il vocabolario non lega materie a classi.
- **Fase 3** — nel ramo successivo `feat/catalogo-istituto-fase3`, da
  rilasciare dopo il precedente: migrazione 116 (cancella le copie, toglie
  `owner_user_id` e `owner_key`, indice unico su `(kind, code, institute_id)`,
  con le righe `SICUREZZA` e `ROLLBACK`), il codice non nomina più la colonna,
  e gli strumenti che esistevano solo per il modello a copie
  (`backfill_classe_indirizzo.php`, `catalogo_fase0.php`) escono dal
  repository — il rapporto della fase 0 resta in `docs/analysis`.

Il catalogo dei libri in adozione, che riusa lo stesso dataset MIUR, è in
[[decisions/ADR-036-catalogo-adozioni]].

## Riferimenti

- [[decisions/ADR-028-institute-governance-teacher-capabilities]] — chi decide che cosa
- [[decisions/ADR-025-risdoc-curriculum-data-dynamic]] — dati curricolari dinamici
- [[decisions/ADR-032-deployment-scenarios]] — i tre scenari
- `database/migrations/036_curriculum_institute_scope.sql` — lo scoping per istituto
- `database/migrations/042_materie_per_teacher.sql`, `044_indirizzi_classi_per_teacher.sql` — le copie e la rimozione del pivot
- `database/migrations/100_classi_indirizzo.sql` — il corso sulla classe
- `app/Services/TeacherSubjectService.php` — il modello giusto, descritto a parole
- `app/Services/MiurAdozioniImporter.php` — l'unico scrittore del vocabolario
- `tools/institutes/prune_cloned_curriculum.php` — «attivare non è inventare»
