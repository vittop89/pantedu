---
title: "Termini di Servizio — Docente pantedu"
subtitle: "Click-acceptance obbligatorio al primo accesso post-onboarding"
version: "1.3"
date: "3 settembre 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Termini di Servizio (ToS) — Docente

**Versione**: 1.3 · **In vigore dal**: 3 settembre 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-09-03)**: documento attivo e legalmente
> applicabile per ogni docente che si registri su `pantedu.eu`.
> L'accettazione è raccolta al momento della registrazione (checkbox
> obbligatoria) e registrata in `user_tos_acceptance` con versione,
> timestamp, IP e User-Agent.
> Il blocco all'accesso per chi non ha accettato la versione vigente è
> implementato in `TosAcceptanceMiddleware` ed è governato dal flag di
> configurazione `TOS_ENFORCE`, **disattivato di default**: finché resta
> tale l'accesso non viene impedito. Il preavviso di cui al §8 è attivo
> in ogni caso, via banner in-app e notifica email.
> Versione, AUP e procedure di takedown sono linkate in footer, modale
> licenza e form di registrazione.
> Versione 1.3 (3 settembre 2026): tolte le formule che presentavano
> l'Applicativo come strumento dell'Istituto in cui il docente insegna;
> nuovi §2.4 e §2.5. Registro delle versioni in `docs/legal/versions.json`.

---

## Preambolo

L'accesso e l'utilizzo dell'applicativo didattico **pantedu.eu**
(di seguito "Applicativo") in qualità di docente comporta l'accettazione
integrale dei presenti Termini di Servizio. Tale accettazione è richiesta
**al primo accesso** mediante click esplicito (con registrazione di
identità, timestamp, indirizzo IP e User-Agent del dispositivo) e ad
ogni successivo aggiornamento sostanziale dei Termini.

I presenti Termini si applicano in aggiunta — e non in sostituzione —
all'Informativa privacy ex art. 13 GDPR dell'Applicativo
(`/privacy/informativa`), di cui è Titolare l'operatore tecnico, e
all'Acceptable Use Policy (AUP) di pantedu.

---

## 1. Identità del docente e natura dell'iscrizione

Accedendo a pantedu in qualità di docente, l'utente dichiara:

a. Di essere docente in servizio presso l'Istituto scolastico indicato
   in fase di registrazione;
b. Di iscriversi **a titolo personale e volontario**: l'iscrizione non è
   disposta dall'Istituto presso cui presta servizio, e l'Applicativo non
   è uno strumento di tale Istituto. Titolare del trattamento dei dati
   conferiti con l'iscrizione è l'operatore tecnico, come indicato
   nell'Informativa privacy (§1);
c. Di accedere all'Applicativo per finalità coerenti con la propria
   funzione docente e nel rispetto del Codice di Comportamento dei
   dipendenti pubblici (DPR 62/2013).

## 2. Divieti di contenuto

L'utente si impegna a **NON caricare** sull'Applicativo:

### 2.1 Contenuti coperti da diritto d'autore senza autorizzazione

#### 2.1.1 Cosa NON è ammesso pubblicare/condividere

Sono **vietati il caricamento o la condivisione** (con studenti o
altri docenti tramite la piattaforma) di:

- Tracce + soluzioni complete di esercizi del libro di testo o di
  banche dati commerciali, **se resi visibili agli studenti** o
  **condivisi con altri docenti** sull'applicativo;
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

Ai sensi dell'**art. 70-bis L. 633/1941** (introdotto da D.Lgs. 177/2021,
attuazione Direttiva UE 2019/790 sul diritto d'autore nel mercato unico
digitale), è **consentito** al docente salvare nell'applicativo, ad **uso
strettamente personale** e per finalità illustrative nell'ambito
della propria attività di insegnamento:

- Tracce, soluzioni o svolgimenti di esercizi del libro di testo, per
  costruire materiale didattico derivato (es. verifiche, esercitazioni
  proprie);
- Brani o parti di opere a fini di studio personale del docente o di
  preparazione di lezioni;
- Note di consultazione di banche dati editoriali, sempre per uso
  personale e non commerciale.

**Condizioni** (cumulative, art. 70-bis):

1. L'utilizzo avviene nell'ambito dell'attività di insegnamento del
   docente e, come richiede l'art. 70-bis, **sotto la responsabilità
   dell'istituto di istruzione** presso cui presta servizio, quale che sia;
2. L'uso è **esclusivamente non commerciale** (didattico);
3. Il contenuto **NON è reso visibile agli studenti** in formato
   integrale;
4. Il contenuto **NON è condiviso con altri docenti** sull'applicativo
   in modo sistematico (la condivisione casuale di un singolo riferimento
   tra colleghi resta ammissibile come citazione);
5. Viene riconosciuta la **fonte** (riferimento bibliografico: autore,
   titolo, editore, pagina).

#### 2.1.3 Protezione tecnica delle tracce/soluzioni ad uso personale

L'applicativo implementa **envelope encryption per-docente** (KMS + KEK
+ AES256-GCM): le tracce/soluzioni salvate dal singolo docente sono
**cifrate con chiave esclusiva del docente** e **non sono in alcun caso
accessibili ad altri docenti** dell'applicativo.

Quanto all'operatore tecnico, nessuna funzione dell'applicativo gli mostra
in chiaro i contenuti di un docente; egli custodisce però la chiave master
da cui le chiavi dei docenti sono derivate, ed è quindi tecnicamente in
grado di decifrarli attivando la procedura di accesso amministrativo
descritta al §3(c). Non si tratta quindi di una impossibilità tecnica, ma
di un accesso circoscritto a casi tassativi e integralmente tracciato.

L'interfaccia espone agli **studenti** unicamente:
- Riferimento bibliografico (fonte, pagina, numero, difficoltà);
- Lo svolgimento del docente fornito come esempio.

**Le tracce/soluzioni complete del libro di testo non vengono mai
visualizzate dagli studenti tramite l'applicativo.**

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
dati: l'accesso ai contenuti pubblicati avviene con una credenziale del
docente, non nominativa. L'utente si impegna a **non inserire dati
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

## 3. Audit log e tracciabilità

L'utente prende atto che:

a. **Ogni operazione di upload, modifica, eliminazione** dei propri
   contenuti viene registrata in audit log persistente con i seguenti
   metadata: identità utente, timestamp, indirizzo IP e User-Agent **in
   forma di hash**, tipo operazione, hash SHA256 del contenuto, dimensione
   file;
b. Tali log sono conservati per almeno **365 giorni** e possono essere
   utilizzati in caso di indagine interna, richiesta di autorità
   competenti, o cooperazione su procedure di Notice & Takedown;
c. L'operatore tecnico ({{OPERATORE_NOME}}) **non accede ai contenuti**
   caricati: l'applicativo non espone alcuna funzione che glieli mostri
   in chiaro, e i metadata di cui alla lettera (a) restano invece
   ordinariamente consultabili.

   Va però dichiarato con precisione che **non si tratta di una
   impossibilità tecnica**. L'architettura di envelope encryption
   per-teacher KEK deriva le chiavi dei singoli docenti dalla chiave
   master (`KMS_MASTER_KEY`), che è custodita dall'operatore tecnico:
   chi detiene quella chiave è in grado, attivando una procedura
   amministrativa, di decifrare i contenuti. La cifratura protegge
   pienamente da un accesso di terzi — altri docenti, chi ottenesse una
   copia del database, il fornitore di hosting — ma non dall'operatore
   stesso.

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
   - **DPR 62/2013** Codice di Comportamento dei dipendenti pubblici
     (art. 13 obbligo di segnalazione);
   - **D.Lgs. 165/2001** art. 53 (incompatibilità cumulo impieghi);

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
   sospendere l'account dell'utente, con segnalazione al Dirigente
   Scolastico e — ove ricorrano gli estremi — alle autorità competenti.

## 6. Obbligo di segnalazione (DPR 62/2013 art. 13)

L'utente, in qualità di docente dipendente pubblico, si impegna a
**segnalare** all'operatore tecnico (canale email `{{OPERATORE_EMAIL}}`
o equivalente) qualsiasi violazione dei presenti Termini di cui dovesse
venire a conoscenza, anche commessa da altri docenti che utilizzano
l'Applicativo.

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
c. Procedere, quando la violazione attiene all'attività di servizio,
   alla **segnalazione al Dirigente Scolastico** dell'Istituto di
   appartenenza dell'utente;
d. Procedere alla **segnalazione alle autorità competenti** (Garante
   Privacy, autorità giudiziaria) ove ricorrano gli estremi di reato
   o violazione di norme imperative.

## 8. Modifiche ai Termini

L'operatore tecnico si riserva il diritto di modificare i presenti
Termini in qualsiasi momento. Le modifiche sostanziali saranno
comunicate agli utenti registrati con un anticipo minimo di **30
giorni** mediante notifica via email e nell'interfaccia dell'Applicativo,
con richiesta di nuova accettazione click esplicita prima del successivo
accesso operativo.

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
controversia è competente in via esclusiva il **Foro di Verbania**.

---

## Accettazione

**Cliccando sul pulsante "Accetto i Termini di Servizio" l'utente
conferma**:

- Di aver letto integralmente e compreso i presenti Termini;
- Di accettarne incondizionatamente il contenuto;
- Di assumersi la piena responsabilità per i contenuti che caricherà;
- Di sollevare l'operatore tecnico da responsabilità per i contenuti
  altrui;
- Di cooperare in buona fede su procedure di takedown e indagini interne.

Dati registrati al momento dell'accettazione:
- Identità utente (user_id, nome, cognome, istituto)
- Data e ora UTC dell'accettazione
- Versione dei Termini accettata
- Indirizzo IP di origine e User-Agent del dispositivo, conservati in
  chiaro quale prova dell'accettazione: è l'unica registrazione in cui
  non sono ridotti a hash (§3)

---

*Per chiarimenti contattare: {{OPERATORE_EMAIL}}*

*Versione documento: 1.3 — in vigore dal 3 settembre 2026.*
