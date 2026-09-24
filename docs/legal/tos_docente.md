---
title: "Termini di Servizio — Docente pantedu"
subtitle: "Accettazione obbligatoria al momento dell'iscrizione"
version: "1.6"
date: "25 settembre 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Termini di Servizio (ToS) — Docente

**Versione**: 1.6 · **In vigore dal**: 25 settembre 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-09-25)**: documento attivo e legalmente
> applicabile per ogni docente che si registri su `pantedu.eu`.
> L'accettazione è raccolta al momento della registrazione (casella
> obbligatoria) e registrata in `user_tos_acceptance` (vedi «Accettazione»,
> in fondo).
> Il blocco all'accesso per chi non ha accettato la versione vigente si
> accende e si spegne dal pannello di amministrazione (`TosEnforcement`;
> senza una scelta vale `TOS_ENFORCE`, spento per impostazione predefinita):
> finché è spento l'accesso non viene impedito. Il preavviso del §8 si dà con
> un avviso nell'interfaccia, che compare da solo a chi entra quando è
> registrata una versione sostanziale non ancora in vigore, e con un messaggio
> email che l'operatore invia con `tools/legal/notify_policy_update.php`:
> quell'invio non è pianificato, si lancia a mano per ogni versione con
> preavviso.
> Versione, AUP e procedure di takedown sono linkate in footer, modale
> licenza e form di registrazione.
> Versione 1.6 (25 settembre 2026): il salvataggio del materiale dei libri
> si fonda sull'uso personale del docente, non più sull'art. 70-bis e sulla
> responsabilità dell'istituto (§2.1.2); detto che cosa è cifrato e che cosa
> no (§2.1.3, §3(c)); il DPR 62/2013 vale solo per chi vi è soggetto;
> descritti i campi veri del registro degli eventi (§3), la cancellazione
> delle compilazioni (§2.5) e quella degli account inutilizzati da 24 mesi,
> dopo tre avvisi (§7), che prende il posto dell'anonimizzazione a 730
> giorni.
> Versione 1.3 (3 settembre 2026): tolte le formule che presentavano
> l'Applicativo come strumento dell'Istituto in cui il docente insegna;
> nuovi §2.4 e §2.5. Registro delle versioni in `docs/legal/versions.json`.

---

## Preambolo

L'accesso e l'utilizzo dell'applicativo didattico **pantedu.eu**
(di seguito "Applicativo") in qualità di docente comporta l'accettazione
integrale dei presenti Termini di Servizio. Tale accettazione è richiesta
**al momento dell'iscrizione**, con una spunta esplicita, e di nuovo a ogni
aggiornamento sostanziale dei Termini (§8). Che cosa si registra è detto in
fondo, alla voce «Accettazione».

I presenti Termini si applicano in aggiunta — e non in sostituzione —
all'Informativa privacy ex art. 13 GDPR dell'Applicativo
(`/privacy/informativa`), di cui è Titolare l'operatore tecnico, e
all'Acceptable Use Policy (AUP) di pantedu.

---

## 1. Identità del docente e natura dell'iscrizione

Accedendo a pantedu in qualità di docente, l'utente dichiara:

a. Di essere docente in servizio e, **se ne ha indicato uno**, di prestare
   servizio presso l'Istituto scolastico dichiarato. Dal 22 settembre 2026
   l'indicazione della scuola è facoltativa (ADR-047): chi non la indica
   dichiara soltanto di essere docente;
b. Di iscriversi **a titolo personale e volontario**: l'iscrizione non è
   disposta dall'Istituto presso cui presta servizio, e l'Applicativo non
   è uno strumento di tale Istituto. Titolare del trattamento dei dati
   conferiti con l'iscrizione è l'operatore tecnico, come indicato
   nell'Informativa privacy (§1);
c. Di accedere all'Applicativo per finalità coerenti con la propria
   funzione docente e nel rispetto, ove applicabile, del Codice di
   Comportamento dei dipendenti pubblici (DPR 62/2013). L'iscrizione è
   aperta ai docenti di qualunque scuola, anche paritaria: il Codice vale
   per chi vi è soggetto.

## 2. Divieti di contenuto

L'utente si impegna a **NON caricare** sull'Applicativo:

### 2.1 Contenuti coperti da diritto d'autore senza autorizzazione

#### 2.1.1 Cosa NON è ammesso pubblicare/condividere

Sono **vietati il caricamento o la condivisione** (con studenti o
altri docenti tramite la piattaforma) di:

- Tracce + soluzioni complete di esercizi del libro di testo o di
  banche dati commerciali, **se rese visibili agli studenti**,
  **pubblicate in rete** o **condivise con altri docenti** sull'applicativo;
- Intere unità didattiche, capitoli, sezioni di libri di testo;
- Scansioni di pagine intere o parti sostanziali di libri, manuali,
  dispense protette da copyright;
- Verifiche, test, quiz tratti da repository commerciali (es. test di
  ammissione, prove di ingresso editoriali);
- Software, immagini, video, audio coperti da licenza non compatibile
  con uso didattico.

L'utente conferma di essere titolare o autorizzato all'utilizzo di
qualunque contenuto caricato.

#### 2.1.2 Cosa È ammesso ad uso strettamente personale del docente

L'Applicativo è uno strumento personale del docente, non l'ambiente di un
istituto: l'Istituto in cui il docente insegna non ha alcun ruolo in ciò che
il docente vi conserva, e non ne risponde (§1(b)). Il docente può salvarvi,
**per suo uso personale** e nell'ambito della propria attività di
insegnamento:

- Tracce, soluzioni o svolgimenti di esercizi del libro di testo, per
  costruire materiale didattico proprio (es. verifiche, esercitazioni);
- Brani o parti di opere, per studio personale o per preparare le
  lezioni;
- Note di consultazione di banche dati editoriali, sempre per uso
  personale e non commerciale.

**Condizioni** (tutte insieme):

1. Il materiale viene da testi che il docente usa legittimamente. Della
   liceità della copia risponde il docente (§4(a)): l'Applicativo non la
   verifica;
2. L'uso è **esclusivamente non commerciale** (didattico);
3. La traccia **non si mostra agli studenti né si pubblica in rete**: di un
   esercizio tratto dal libro gli studenti vedono solo il riferimento e la
   soluzione scritta dal docente (§2.1.3);
4. Il contenuto **NON è condiviso con altri docenti** sull'applicativo
   (resta ammesso indicare a un collega un singolo riferimento
   bibliografico);
5. Viene riconosciuta la **fonte** (riferimento bibliografico: autore,
   titolo, editore, pagina).

#### 2.1.3 Che cosa vedono gli altri, e che cosa è cifrato

**Gli studenti** entrano solo con la credenziale di classe del docente. Di
un esercizio tratto da un libro l'interfaccia mostra loro **soltanto** il
riferimento all'esercizio — numero, pagina, difficoltà e fonte (il libro) —
e la soluzione **elaborata dal docente**. **Mai la traccia.** Il riferimento
con la soluzione serve solo a chi possiede il libro. La soluzione mostrata
deve essere scritta dal docente, non copiata dal libro.

**Gli altri docenti** non vedono i contenuti di un docente, salvo che chi
li ha creati li condivida. L'applicativo **blocca la condivisione** dei
contenuti classificati come tratti dal libro (`book_textbook` o `mixed`),
sia con il gruppo dei colleghi dell'istituto sia con singoli colleghi.

**Cifratura.** Le tracce e le soluzioni salvate come esercizi **non sono
cifrate con la chiave del docente**: il testo di esercizi e laboratori è
conservato sul server senza cifratura per docente (§3(c)). La loro
protezione è il controllo degli accessi descritto qui sopra.

### 2.2 Dati di categoria particolare (art. 9 GDPR)

L'utente si impegna a **NON caricare** dati di categoria particolare,
in particolare:
- Documentazione PEI (Piano Educativo Individualizzato);
- Documentazione PDP (Piano Didattico Personalizzato);
- Diagnosi o documentazione DSA, BES, ADHD, autismo;
- Certificati medici, dati sanitari, anamnesi;
- Dati genetici, biometrici, dati sulla salute fisica o mentale;
- Dati relativi a origine etnica, opinioni religiose, politiche,
  filosofiche o appartenenza sindacale.

Tale documentazione deve restare nei sistemi ufficiali della Scuola.

### 2.3 Contenuti illegali, offensivi, diffamatori

Sono vietati contenuti:
- Illegali (ad es. materiale pedopornografico, istigazione a reati);
- Diffamatori o lesivi della dignità di terzi;
- Discriminatori per genere, razza, religione, orientamento sessuale,
  disabilità;
- Promozionali di prodotti/servizi commerciali estranei alla didattica.

### 2.4 Dati personali di studenti

L'Applicativo **non prevede account per studenti** e non ne raccoglie
dati identificativi (i soli dati tecnici della connessione sono descritti
nell'Informativa privacy): l'accesso ai contenuti pubblicati avviene con
una credenziale del docente, non nominativa. L'utente si impegna a **non inserire dati
personali di studenti in alcun campo** dell'Applicativo — titoli, note,
testi e immagini compresi: nomi, elenchi di classe, valutazioni,
elaborati, fotografie in cui gli studenti siano identificabili. I
marcatori BES/DSA sugli esercizi descrivono l'esercizio, non uno
studente. I dati degli studenti restano nei sistemi ufficiali della
Scuola.

### 2.5 Atti formali dell'Istituto

**La piattaforma è strumento di redazione e preparazione. Non
costituisce luogo di conservazione degli atti formali dell'Istituto.**
I documenti che costituiscono atti dell'Istituto di appartenenza —
piani, programmazioni, relazioni, verbali — vanno depositati nei
sistemi documentali della Scuola (DPR 445/2000; CAD, artt. 40-44);
l'Applicativo ne conserva al più la bozza di lavoro del docente, come per
ogni altro materiale preparatorio.

Anche la bozza non resta per sempre (ADR-046). La compilazione salvata di un
modello di documento si cancella **15 giorni** dopo l'ultima fra lo
scaricamento del documento e l'ultima modifica; ogni modifica fa ripartire il
conto. In ogni caso, anche se non è mai stata scaricata, una compilazione
ferma dal 1° giugno si cancella il **31 agosto**. Il docente ne è avvisato
per email una settimana prima, all'indirizzo del suo account. Si cancella
solo la compilazione: il modello resta.

## 3. Audit log e tracciabilità

L'utente prende atto che:

a. Il registro degli eventi sui contenuti annota le operazioni sui propri
   contenuti — per esempio creazione, modifica, pubblicazione,
   condivisione, copia, esportazione, eliminazione — con: il docente
   titolare e l'utente che agisce, data e ora, tipo di operazione, il
   contenuto interessato e i dettagli dell'operazione (per esempio il
   titolo alla creazione, la visibilità prima e dopo), l'indirizzo IP e lo
   User-Agent **come impronta con chiave**, non in chiaro (un pseudonimo:
   resta un dato personale). Il registro non conserva il testo degli
   esercizi né degli altri contenuti;
b. Tali registrazioni sono conservate per **cinque anni** (Informativa
   privacy, §5) e possono essere utilizzate in caso di indagine interna,
   richiesta di autorità competenti, o cooperazione su procedure di Notice
   & Takedown;
c. L'operatore tecnico ({{OPERATORE_NOME}}) **non accede ai contenuti**
   caricati: l'applicativo non espone alcuna funzione che glieli mostri
   in chiaro, e i metadata di cui alla lettera (a) restano invece
   ordinariamente consultabili.

   Va però dichiarato con precisione che **non si tratta di una
   impossibilità tecnica**. Ogni docente ha una chiave casuale (KEK),
   conservata avvolta con una chiave derivata dalla chiave master
   (`KMS_MASTER_KEY`), che è custodita dall'operatore tecnico:
   chi detiene quella chiave è in grado, attivando una procedura
   amministrativa, di decifrare i contenuti. La cifratura protegge
   pienamente i contenuti cifrati da un accesso di terzi — altri docenti,
   chi ottenesse una copia del database, il fornitore di hosting — ma non
   dall'operatore stesso.

   **Non tutti i contenuti sono cifrati.** Lo sono i file delle mappe, i
   file TeX e PDF delle verifiche salvate, le compilazioni dei modelli di
   documenti e i token dei servizi collegati (Drive, GitHub). Non lo sono il
   testo di esercizi e laboratori e la composizione delle verifiche, con le
   loro versioni precedenti, i titoli, gli argomenti e gli altri dati
   descrittivi dei contenuti: questi stanno sul server senza cifratura per
   docente. Per essi la protezione è il controllo degli accessi
   dell'Applicativo, non la cifratura: chi amministra il server, o chi ne
   ottenesse una copia, può leggerli senza la procedura descritta qui sotto.

   Tale procedura è ammessa nei **soli** casi elencati al §11-bis
   dell'Informativa privacy (richiesta motivata di autorità giudiziaria o
   di controllo, successione, recupero dell'accesso su richiesta del
   docente interessato) e **ogni attivazione produce una riga immutabile**
   nel registro `crypto_custody_events`, che il docente può chiedere di
   consultare per la parte che lo riguarda. Il frazionamento della chiave
   master con schema di Shamir *k*-su-*n*, attivabile su richiesta
   all'operatore tecnico, protegge la **copia di sicurezza** della chiave —
   nessun custode può ripristinarla da solo — ma **non elimina** l'accesso
   operativo di chi amministra il server, dove la chiave deve risiedere
   perché l'Applicativo funzioni.

## 4. Responsabilità per i contenuti caricati

L'utente riconosce e accetta che:

a. **La responsabilità civile, penale e disciplinare per i contenuti
   caricati ricade esclusivamente sull'utente medesimo**, quale autore
   dei contenuti;

b. Le norme applicabili includono in particolare:
   - **D.Lgs. 70/2003** art. 16 (responsabilità per contenuti immessi
     dai destinatari del servizio);
   - **L. 633/1941** e successive modifiche (Diritto d'autore);
   - **Regolamento UE 2016/679 (GDPR)** artt. 5, 9, 24;
   - **D.Lgs. 196/2003** modificato dal **D.Lgs. 101/2018** (Codice
     Privacy);
   - **DPR 62/2013** Codice di Comportamento dei dipendenti pubblici, per
     chi vi è soggetto;

c. L'utente **solleva l'operatore tecnico ({{OPERATORE_NOME}}) da
   responsabilità per i contenuti caricati**, riconoscendo che questi
   non seleziona, non sceglie e non sorveglia in via preventiva il
   materiale immesso dai docenti, e che l'Applicativo non gliene mostra
   il contenuto in chiaro (§3(c)): l'esonero opera nei limiti e alle
   condizioni dell'art. 16 del D.Lgs. 70/2003, e viene quindi meno
   qualora l'operatore, venuto a conoscenza di un contenuto illecito,
   ometta di attivarsi per rimuoverlo secondo la procedura di Notice &
   Takedown. L'operatore tecnico resta inoltre responsabile delle
   misure tecniche e organizzative infrastrutturali ex art. 32 GDPR e
   del corretto uso della procedura di accesso amministrativo alle
   chiavi di cui al §3(c).

## 5. Notice & Takedown — Cooperazione

L'utente si impegna a:

a. **Cooperare in buona fede** con eventuali procedure di Notice &
   Takedown attivate dall'operatore tecnico in seguito a segnalazione
   di violazione;

b. **Rimuovere tempestivamente** (entro 24 ore dalla notifica) i
   contenuti contestati su richiesta motivata dell'operatore tecnico
   o dell'autorità competente;

c. Riconoscere che, in caso di mancata cooperazione, l'operatore tecnico
   procederà alla rimozione d'ufficio del contenuto contestato e potrà
   sospendere l'account dell'utente e, ove ricorrano gli estremi,
   segnalare alle autorità competenti.

## 6. Canale di segnalazione

Chi rilevi un uso dell'Applicativo contrario ai presenti Termini — proprio o
altrui — **può** segnalarlo all'operatore tecnico scrivendo a
`{{OPERATORE_EMAIL}}`. La segnalazione è una facoltà, non un obbligo.

> **Modificato nella versione 1.4 (22 settembre 2026).** Fino alla 1.3 questo
> paragrafo si intitolava «Obbligo di segnalazione (DPR 62/2013 art. 13)» e
> impegnava l'utente «in qualità di docente dipendente pubblico» a segnalare
> all'operatore le violazioni altrui. Era sbagliato due volte. L'art. 13 del
> DPR 62/2013 reca le disposizioni particolari per i **dirigenti** e a un
> docente non si applica; e i doveri del Codice di comportamento corrono verso
> l'amministrazione di appartenenza, non verso il gestore privato di uno
> strumento. Un obbligo di riferire su un collega, fondato su una norma che non
> lo prevede e diretto a un pari grado, non può stare in un contratto di
> servizio.

## 6-bis. Funzioni di intelligenza artificiale — Regolamento (UE) 2024/1689

### 6-bis.1 Inquadramento

L'Applicativo include funzioni basate su modelli di intelligenza artificiale
(**PDF-Import**). Ai sensi del Regolamento (UE) 2024/1689 ("AI Act"),
l'operatore tecnico è **fornitore** di un sistema di IA a **rischio limitato**.
La classificazione e le sue motivazioni sono in
[assessment AI Act](/legal/ai-act).

### 6-bis.2 Obblighi dell'utente

L'utente che utilizza le funzioni di IA si impegna a:

a. **Rivedere** il contenuto generato prima di pubblicarlo. La revisione non è
   una formalità: il modello produce risposte ben formate anche quando sono
   errate, in particolare sulle soluzioni matematiche. La responsabilità del
   contenuto pubblicato resta dell'utente, e la marcatura "generato da IA"
   non la trasferisce;
b. **Non caricare elaborati, compiti o verifiche svolte dagli studenti**, né
   elenchi classe, valutazioni o altri dati personali di studenti. L'uso
   ammesso è quello dei libri di testo (vedi [AUP](/legal/aup) § 2.1);
c. **Non rimuovere né alterare** la marcatura di provenienza applicata al
   contenuto generato (art. 50(2) AI Act);
d. **Prendere visione** della scheda di alfabetizzazione
   [`ai-literacy.md`](/legal/ai-literacy), predisposta in attuazione dell'art. 4
   del Regolamento.

### 6-bis.3 Modifica della finalità prevista — art. 25(1)(c)

Le funzioni di IA dell'Applicativo hanno una finalità prevista definita:
produrre materiale didattico per il docente. **Non** valutano gli studenti,
non ne orientano il percorso di apprendimento, non li sorvegliano durante le
prove.

Ai sensi dell'**art. 25(1)(c) del Regolamento (UE) 2024/1689**, chiunque —
istituto scolastico, ente o singolo utente — modifichi la finalità prevista di
un sistema di IA dell'Applicativo in modo tale da renderlo un sistema **ad alto
rischio** ai sensi dell'art. 6 e dell'Allegato III **è considerato fornitore**
di quel sistema e assume in proprio gli obblighi dell'art. 16, ivi compresi
valutazione di conformità, documentazione tecnica, marcatura CE e registrazione
nella banca dati dell'Unione.

Rientrano in tale ipotesi, a titolo esemplificativo: l'impiego delle funzioni
di IA per correggere o valutare automaticamente il lavoro degli studenti, per
orientare il percorso di apprendimento di uno specifico studente, per
sorvegliare comportamenti durante le prove, o per assegnare studenti a classi,
indirizzi o livelli.

L'operatore tecnico **non autorizza** tali usi e non fornisce documentazione a
supporto degli stessi. L'elenco completo dei casi di escalation è al § 8
dell'[assessment AI Act](/legal/ai-act).

## 7. Sospensione e cessazione

L'operatore tecnico si riserva il diritto di:

a. **Sospendere temporaneamente** l'account dell'utente in caso di
   violazione sospetta dei presenti Termini, per il tempo necessario
   alla valutazione;
b. **Espellere definitivamente** l'utente in caso di violazione
   accertata e grave, con conservazione dell'audit log per le
   tempistiche di legge;
c. Procedere alla **segnalazione alle autorità competenti** (Garante
   Privacy, autorità giudiziaria) ove ricorrano gli estremi di reato
   o violazione di norme imperative.

**Account inutilizzati.** Un account senza accessi da **24 mesi** si
cancella, con la stessa procedura della cancellazione chiesta dall'utente
(Informativa privacy, §5). Prima l'utente riceve **tre avvisi via email**,
60, 30 e 7 giorni prima, ciascuno con la data della cancellazione; basta un
accesso per azzerare il conto. L'account non si cancella se l'ultimo avviso
non è partito almeno 7 giorni prima. Sono esclusi gli account di
amministrazione della piattaforma.

> **Modificato nella versione 1.4 (22 settembre 2026).** È stata tolta la
> lettera che prevedeva la **segnalazione al Dirigente Scolastico**
> dell'Istituto di appartenenza dell'utente quando la violazione attenesse
> all'attività di servizio. L'operatore è un docente, e i docenti che si
> iscrivono sono suoi pari: riservarsi di riferire di loro al loro Dirigente
> non serve a erogare il servizio, e crea fra pari un rapporto che il servizio
> non deve creare. Restano la sospensione, l'espulsione e la segnalazione alle
> autorità, che bastano a difendere la piattaforma. Nulla impedisce a chiunque,
> a titolo personale e fuori da questo contratto, di rivolgersi a chi ritiene.

## 8. Modifiche ai Termini

L'operatore tecnico si riserva il diritto di modificare i presenti
Termini in qualsiasi momento. Le modifiche sostanziali saranno
comunicate agli utenti registrati con un anticipo minimo di **30
giorni**, con un avviso nell'interfaccia dell'Applicativo e con un
messaggio all'indirizzo email dell'account. La nuova versione si accetta
dalla pagina di accettazione, con una conferma esplicita; l'operatore può
subordinare a quell'accettazione l'accesso operativo dopo l'entrata in
vigore.

Durante il periodo di preavviso resta vigente la versione precedente:
l'accesso non è impedito e la nuova versione può essere accettata in
anticipo. Chi non intende accettare la nuova versione può esercitare il
diritto alla portabilità dei dati (art. 20 GDPR) prima della data di
entrata in vigore.

Le correzioni che non incidono su obblighi o diritti delle parti
(refusi, recapiti, riformulazioni neutre) non costituiscono modifiche
sostanziali e non comportano preavviso né nuova accettazione.

## 9. Foro competente e legge applicabile

I presenti Termini sono regolati dalla **legge italiana**. Per qualsiasi
controversia è competente in via esclusiva il **Foro di {{OPERATORE_FORO}}**.

---

## Accettazione

**Spuntando la casella di accettazione al momento dell'iscrizione, o con
il pulsante «Accetto e continuo» della pagina di accettazione, l'utente
conferma**:

- Di aver letto integralmente e compreso i presenti Termini;
- Di accettarne incondizionatamente il contenuto;
- Di assumersi la piena responsabilità per i contenuti che caricherà;
- Di sollevare l'operatore tecnico da responsabilità per i contenuti
  altrui;
- Di cooperare in buona fede su procedure di takedown e indagini interne.

Dati registrati al momento dell'accettazione:
- Identità utente (user_id)
- Data e ora dell'accettazione
- Versioni dei Termini e dell'AUP accettate
- Quando si accetta dalla pagina di accettazione, con l'accesso fatto:
  l'indirizzo IP da cui la richiesta arriva al server e lo User-Agent,
  conservati in chiaro quale prova dell'accettazione

Nei registri del §3 IP e User-Agent sono ridotti a impronta; restano in
chiaro, per la sicurezza e con una conservazione breve, anche nei registri
elencati nell'Informativa privacy. Alla cancellazione dell'account (§7), IP
e User-Agent del verbale si cancellano: restano la data e le versioni
accettate.

---

*Per chiarimenti contattare: {{OPERATORE_EMAIL}}*

*Versione documento: 1.6 — in vigore dal 25 settembre 2026.*
