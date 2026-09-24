---
tags:
  - documentazione/adr
date: 2026-09-14
tipo: adr
status: accettato
aliases: ["ADR-041", "sezioni dei docenti", "solo incaricati", "sezioni_docenti", "organigramma"]
cssclasses: []
---

# ADR-041 — Le sezioni delle classi per i docenti: tutti, solo incaricati, nessuno

## Stato

**ACCETTATO** dall'utente il 14 settembre 2026: «VA BENE, imposta le tre
modalità e metti adesso solo incaricati». **Eseguito**, con «solo incaricati»
su tutti gli istituti.

**Aggiornato il 15 settembre 2026** da [[decisions/ADR-043-anni-con-incarico]]: con «solo
incaricati» anche gli anni di corso seguono gli incarichi; la regola «gli anni
sono sempre ammessi» qui sotto vale ora solo per «tutti» e «nessuno».

Resta da decidere, a parte, se e quando **convertire** sull'anno i legami con le
sezioni già scritti (sezione «Che cosa non fa»).

## Contesto

**La domanda che può arrivare.** L'utente ha scritto alla Dirigente, in copia
alla nota per il DPO, di chiedere «una presa d'atto scritta dell'uso con le
classi dell'Istituto», e il riscontro non è ancora arrivato. Se non arrivasse, o
se la risposta chiedesse di non trattare l'organizzazione delle classi della
scuola, la piattaforma deve saperlo fare: prima della risposta, non dopo.

**Che cosa è dato personale e che cosa no.**
- **L'elenco delle sezioni di una scuola no.** «Al Esempio c'è la 2A dello
  scientifico» arriva dal dataset pubblico MIUR delle adozioni.
- **Il legame «il docente X insegna in 2A» sì**, del docente. Oggi lo scrivono
  quattro cose:
  - la spunta del profilo (`curriculum_teacher` su una sezione);
  - l'incarico (`teacher_sections`);
  - la classe di un contenuto o di una pubblicazione (`content_publications`);
  - la credenziale di classe.

  Messi insieme per molti docenti, questi legami ricostruiscono l'organigramma
  della scuola.
- **Il numero dei docenti** cambia quanto se ne ricostruisce (il rischio), non la
  natura: anche un solo legame docente-sezione è un dato personale. La domanda
  utile è se serve (art. 5, par. 1, lett. c): se si lavora bene per anni, non
  va raccolto.

**In produzione, misurato il 14 settembre 2026:**

| | |
|---|---|
| istituti | 3; docenti: 2 |
| vocabolario delle classi | 5 anni e 28 sezioni; l'istituto 108 ha solo sezioni (5), nessun anno |
| spunte sulle classi | 5 anni e 12 sezioni |
| incarichi docente-sezione | 12 |
| pubblicazioni | 664: 411 su un anno, 251 su una sezione, 2 senza classe |
| sezioni spuntate coperte da un incarico | 12 su 12 |
| pubblicazioni su sezione coperte da un incarico | 251 su 251 |

Quindi «solo incaricati» oggi non cambia niente di visibile ai due docenti: lo
si è misurato prima di sceglierlo.

**Il modello c'era già.** L'anno («2») vale per tutte le sue sezioni (2A, 2B…)
nel filtro dei contenuti e negli incarichi (`App\Domain\ClassCode::covers`).
Uno studente con la credenziale della 2A vede i contenuti pubblicati su «2».

## Decisione

### 1. Una modalità per istituto

`institutes.sezioni_docenti` (migrazione 126):

| modalità | chi dei docenti può legarsi a una sezione |
|---|---|
| `tutti` | ogni docente dell'istituto, com'era prima |
| `solo_incaricati` | solo il docente che l'amministratore ha incaricato di quella sezione (`/admin/sections`); gli altri usano gli anni |
| `nessuno` | nessuno: solo gli anni |

**Di partenza vale `solo_incaricati`**, per tutti gli istituti esistenti e nuovi.

**Chi la sceglie:**
- negli scenari 1 e 2, l'amministratore della piattaforma, da `/admin/institutes`;
- nello scenario 3, dove il Titolare è l'Istituto, sarà una delle funzioni
  dell'amministratore di istituto (ADR-040, fase 2).

### 2. Si governa il legame, non l'elenco

Il vocabolario resta com'è, pubblico, e serve anche all'iscrizione degli
studenti. La regola è `App\Services\SezioniDeiDocenti::ammessa()`, e si applica
dove un legame con una sezione nasce o si mostra al docente:

| punto | comportamento |
|---|---|
| spunta dal profilo (`CurriculumService::add`) | rifiutata: `sezione_non_ammessa` |
| riaccendere una spunta (`CurriculumService::updateById`) | rifiutata, se la sezione non è ammessa |
| voci offerte nel profilo (`CurriculumController::index`) | le sezioni non ammesse non compaiono |
| classe di un contenuto salvato, importato, di un incarico (`CurriculumLookup::idFromCodeForTeacher`, `ensureEntryForTeacher`) | **ripiega sull'anno** (2A → 2). Dall'interfaccia quelle sezioni non si scelgono: ci arrivano pacchetti importati o chiamate dirette, per cui l'anno è la classe giusta che vale anche per quella sezione. Se l'istituto non ha l'anno, la classe resta vuota |
| credenziale di classe (`TeacherCredentialRepository::create`) | rifiutata: `sezione_non_ammessa` |
| incarico dato o tolto (`TeacherSectionService`) | riallinea le spunte di quel docente |

### 3. Le spunte già scritte si sospendono, non si cancellano

`curriculum_teacher.sospesa_dalla_scuola`. Una spunta su una sezione che la
modalità non ammette diventa `active = 0` con `sospesa_dalla_scuola = 1`. Se la
modalità torna a permetterla, si riprende (`SezioniDeiDocenti::riallinea`).

- **Il docente non la vede nel profilo e non la riaccende.** Le spunte che il
  docente ha spento da sé (`sospesa_dalla_scuola = 0`) restano sue: allargare
  la modalità non le riaccende.
- **Tutto ciò che legge le spunte attive la ignora da sé:** pubblicare
  (`DoveVale::verificaLuogo`), spostare di classe, i selettori della barra.
  Per questo si è scelta la sospensione sui dati e non un filtro in ognuna
  delle query che leggono le spunte.
- Una query leggeva le spunte anche spente: la categorizzazione dei contenuti
  (`TeacherContentRepository`). Ora chiede le attive.

### 4. La pagina degli incarichi, in ogni scenario

«Sezioni e incarichi» compariva nella barra dell'amministrazione solo nello
scenario 3, e l'utente non la trovava. Ora gli incarichi decidono anche le
sezioni dei docenti, in ogni scenario, e la voce c'è sempre (`AdminAreas`). La
pagina dice la modalità dell'istituto scelto.

### 5. I materiali rimasti su una sezione che il docente non può più usare

Aggiunto il 14 settembre 2026, su scelta dell'utente: «pulsante a mano»,
«avviso con data facoltativa».

**Il problema, misurato prima di correggerlo.** Tolto l'incarico della 2A dopo
che il docente ci aveva salvato un contenuto:
- il contenuto restava sulla 2A;
- la 2A spariva dai menù del docente e da «Sposta di classe»;
- lo spostamento verso l'anno era rifiutato (`classe_non_spuntata`).

L'unica strada era ridare l'incarico, far spostare il materiale al docente e
toglierlo di nuovo.

**Che cosa sono i materiali su una sezione.** Le pubblicazioni del docente su
quella classe:
- la principale di un contenuto o di una verifica;
- i posti in più di «Dove vale».

In produzione, il 14/9/2026, le 251 pubblicazioni su sezione erano **tutte posti
in più**, con la principale sull'anno: guardare solo la principale non avrebbe
trovato niente. I bersagli di «per più classi» vengono dalle classi scelte nel
contenuto e non si spostano da qui: si contano, e si tolgono dal contenuto.

**Tre strade, nessuna automatica** (`App\Services\Contenuti\MaterialiSuSezioniNonAmmesse`):

| chi | dove | che cosa |
|---|---|---|
| il docente | «Sposta di classe» | la sezione compare come classe **di partenza**, marcata «senza incarico», finché ci sono suoi materiali; l'elenco comprende i contenuti che lì hanno solo un posto in più (dal 15/9/2026 da qualunque classe di partenza, non più solo da quella senza incarico: vedi sotto). Come arrivo restano solo le classi spuntate |
| l'amministratore | «Sezioni e incarichi», riquadro «Materiali su sezioni senza incarico» | per docente e sezione: contenuti, verifiche, posti, credenziali ancora sulla sezione, anno di arrivo. «Sulla «2»» li porta sull'anno, con il motivo a registro (`audit_reason`); l'anno diventa una classe spuntata del docente |
| l'area docente | cruscotto e «Sposta di classe» | l'avviso: quanti materiali, su quali sezioni, e la data se l'amministratore ne ha messa una (`institutes.sezioni_scadenza`, migrazione 127) |

**Perché niente di automatico.** Uno spostamento automatico renderebbe definitivo
anche un incarico tolto per sbaglio: ridandolo, i materiali non tornerebbero da
soli, perché dopo lo spostamento non si sa più che erano sulla 2A. La
sospensione della spunta resta la sola reazione automatica, ed è reversibile.
La data non fa partire niente: alla data l'amministratore preme il pulsante.

**Quello che lo spostamento sull'anno cambia**, scritto anche nella pagina:
- sull'anno i materiali li vedono tutte le classi di quell'anno del docente;
  le sue credenziali della sezione continuano a vederli;
- un posto in più che finisce accanto alla principale si unisce a lei quando
  non cambia chi vede che cosa (la regola di «Sposta di classe», ADR-037);
- se nel catalogo dell'istituto l'anno non c'è, l'amministratore si ferma e lo
  dice: prima si aggiunge l'anno.

**Riportarli sulla sezione**, se l'incarico torna: a mano, da «Sposta di
classe». Non è l'inverso esatto: i posti uniti non si ridividono, e
l'elenco non distingue i contenuti nati sull'anno da quelli arrivati dalla
sezione.

**I posti in più da una classe con incarico (15/9/2026).** L'elenco di «Sposta
di classe» comprendeva i posti in più solo da una sezione senza incarico. Da una
classe spuntata guardava la sola principale. In produzione le principali
dell'utente stanno tutte sugli anni, e 187 posti in più stanno su 8 sezioni con
incarico. Scelta la 2A, la pagina diceva «Nessun materiale», e la strada
consigliata per portare tutto sugli anni non esisteva. Ora l'elenco e lo
spostamento li comprendono da qualunque classe di partenza. Spostato sull'anno
dove sta già la principale, il posto in più si unisce a lei quando non cambia
chi vede che cosa (la regola di ADR-037).

**Prima di spostare, un riepilogo (15/9/2026).** Non c'è un «annulla»: finiti i
materiali, una sezione senza incarico sparisce dall'elenco e non ci si torna.
Il 15/9 l'utente ha spostato la «1» intera nella «3» credendo di partire dalla
3A, perché il selettore diceva 3A mentre elenco e modulo erano ancora della «1».
I 55 contenuti e la verifica sono stati ripristinati a mano (registro, id 2589).
Ora:
- cambiare la classe di partenza ricarica subito l'elenco, e quello vecchio non
  si invia più (`sposta-partenza.js`);
- prima di inviare, una finestra dice da dove a dove, quanti e quali
  (`riepilogo-spostamento.js`), e mette in guardia quando cambia l'anno, quando
  dall'anno si passa a una sua sezione, quando la classe si svuota e quando la
  partenza è una sezione senza incarico;
- il messaggio dopo lo spostamento dice partenza e arrivo, e il registro
  (`content_moved_class`) ha anche i nomi delle classi e gli id degli elementi.

### 6. Togliere un incarico: l'avviso e l'email (15/9/2026)

Richiesta dell'utente: una finestra che dica se sulle sezioni ci sono materiali,
per tipo e numero, con il nome del docente, e che metta in guardia
l'amministratore. Scelta dell'utente sull'email: solo quando si tolgono
incarichi.

- **Prima di confermare**, sia in «Salva incarichi» sia nella revoca dalla
  tabella, una finestra (`js/modules/features/avviso-incarichi.js`) chiede a
  `GET /admin/sections/anteprima-revoca` che cosa ha il docente su ogni sezione
  (`MaterialiSuSezioniNonAmmesse::sulleSezioni`):
  - contenuti per tipo, verifiche generate, posti in più;
  - credenziali di classe attive;
  - studenti con account.

  Mostra il nome del docente, le conseguenze del caso e se partirà l'email.
- **La conferma di prima era vera a metà.** Diceva «gli studenti di quelle
  sezioni non vedranno più i suoi contenuti».
  - Chi entra con una credenziale di classe continua a vederli: la visibilità
    segue la credenziale (`Pubblicazioni::perimetroDiClasse`).
  - Li perdono solo gli studenti con account, per i quali conta l'incarico
    (`student_scope`).
  - La revoca dalla tabella non chiedeva nemmeno conferma.
- **L'email al docente** parte quando gli si tolgono incarichi, non quando se
  ne assegnano (`App\Services\AvvisoIncarichiTolti`). Dice:
  - le sezioni tolte e i materiali per sezione;
  - dove spostarli;
  - le credenziali che restano attive.

  Se non parte (posta non configurata, indirizzo non valido, invio fallito),
  l'incarico resta tolto e il messaggio all'amministratore lo dice.
- **La risposta del docente** arriva all'amministratore che ha tolto l'incarico,
  perché decidere è suo. Senza un suo indirizzo valido va a `APP_MAIL_REPLY_TO`,
  in ultimo a `DPO_EMAIL`. Fino al 15/9/2026 andava sempre a dpo@: una
  contestazione organizzativa finiva nella casella delle richieste privacy.

## Che cosa non fa

- **Non cancella e non converte.**
  - le 251 pubblicazioni su sezione restano, e gli studenti le vedono come prima;
  - gli incarichi e le spunte coperte da un incarico restano.

  È l'opzione «nascondere e tenere», scelta finché il DPO non risponde.
- **La conversione sull'anno** (spostare i legami da 2A a 2 e cancellare quello
  con la sezione) **di tutta la piattaforma** è un passo a parte, da decidere
  dopo la risposta. Quella di un docente su una sezione di cui non ha
  l'incarico c'è dal punto 5: la fa lui, o l'amministratore a mano.
  - **Non è reversibile:** per poterla rifare all'indietro bisognerebbe tenere
    da qualche parte quale sezione c'era, e allora il dato resterebbe sul
    server, cioè si tornerebbe a nascondere e tenere.
  - **Tenerla reversibile solo nei salvataggi** vale per la durata dei backup
    (un anno, dall'informativa), e non è un modo di lavorare.
- **Il limite per i docenti:** senza sezioni, un docente con due seconde non dà
  materiali diversi alla 2A e alla 2B, perché la stessa credenziale «2» vede
  tutto.
- **I documenti di conformità non cambiano ora.** La modalità è una misura di
  privacy by default (art. 25): la si potrà citare nella DPIA quando arriva il
  riscontro del DPO, se l'utente lo decide.

## Conseguenze

- **Prove:**
  - `tests/Unit/Services/SezioniDeiDocentiTest.php`: la regola;
  - `tests/Integration/SezioniDeiDocentiTest.php`: sospensione e ripresa,
    profilo, ripiego sull'anno, pubblicazione, credenziale, incarichi, voci
    offerte. Controprova: tolto uno qualunque dei sei controlli, una prova
    diventa rossa.
  - `tests/Integration/MaterialiSuSezioniNonAmmesseTest.php` (punto 5):
    partenza dalla sezione sospesa con i posti in più, mai arrivo in una
    sezione sospesa, l'amministratore sull'anno e i suoi tre arresti, la data.
    Controprova: tolta una qualunque delle sei regole, una prova diventa rossa;
  - `tests/Integration/AdminSezioniMaterialiTest.php`: le due azioni del
    pannello e i loro messaggi;
  - accessibilità: con un caso vero axe ha trovato due collegamenti distinti
    solo dal colore e un pulsante con poco contrasto, corretti; «Sposta di
    classe» e «Sezioni e incarichi» sono ora negli elenchi della spec riservata.
- **Le prove che provano altro** (credenziali, catalogo, filtro studenti,
  pubblicazioni) creano i loro istituti con `tutti`, e lo scrivono.
- **La semina della suite end-to-end** dà al docente di prova l'incarico delle
  sezioni che spunta nel secondo istituto, come in produzione.

## Riferimenti

- `app/Services/SezioniDeiDocenti.php`, `database/migrations/126_sezioni_dei_docenti.sql`
- `app/Services/Contenuti/MaterialiSuSezioniNonAmmesse.php`, `app/Services/Contenuti/SpostamentoDiClasse.php` (`classiDiPartenza`), `database/migrations/127_scadenza_materiali_sezioni.sql`, `views/partials/_avviso_sezioni_da_spostare.php`
- `app/Controllers/Admin/AdminInstitutesController.php` (`impostaSezioniDocenti`), `views/admin/institutes_index.php`, `views/admin/sections.php`
- [[ADR-035-catalogo-istituto-centralizzato]] · [[ADR-037-pubblicazioni-e-copie]] · [[ADR-040-amministratore-di-istituto]] · [[ADR-032-deployment-scenarios]]
