---
title: "Termini di Servizio — Docente pantedu"
subtitle: "Click-acceptance obbligatorio al primo accesso post-onboarding"
version: "1.0"
date: "20 maggio 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Termini di Servizio (ToS) — Docente

**Versione**: 1.0 · **Data**: 20 maggio 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-05-20)**: documento attivo e legalmente
> applicabile per ogni docente che si registri su `pantedu.eu`.
> Il click-acceptance è enforced via middleware (`TOS_ENFORCE=true`) e
> registrato in `user_tos_acceptance` (timestamp + IP + User-Agent).
> Versione, AUP e procedure di takedown sono linkate in footer, modale
> licenza e form di registrazione.

---

## Preambolo

L'accesso e l'utilizzo dell'applicativo didattico **pantedu.eu**
(di seguito "Applicativo") in qualità di docente comporta l'accettazione
integrale dei presenti Termini di Servizio. Tale accettazione è richiesta
**al primo accesso** mediante click esplicito (con registrazione di
identità, timestamp, indirizzo IP e User-Agent del dispositivo) e ad
ogni successivo aggiornamento sostanziale dei Termini.

I presenti Termini si applicano in aggiunta — e non in sostituzione —
all'informativa privacy ex art. 13 GDPR fornita dall'Istituto e
all'Acceptable Use Policy (AUP) di pantedu.

---

## 1. Identità del docente Autorizzato

Accedendo a pantedu in qualità di docente, l'utente dichiara:

a. Di essere docente in servizio presso l'Istituto scolastico indicato
   in fase di registrazione;
b. Di essere nominato dalla Scuola come **Autorizzato al trattamento di
   dati personali ex art. 29 GDPR** per finalità istituzionali didattiche;
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
dell'attività di insegnamento dell'Istituto:

- Tracce, soluzioni o svolgimenti di esercizi del libro di testo, per
  costruire materiale didattico derivato (es. verifiche, esercitazioni
  proprie);
- Brani o parti di opere a fini di studio personale del docente o di
  preparazione di lezioni;
- Note di consultazione di banche dati editoriali, sempre per uso
  personale e non commerciale.

**Condizioni** (cumulative, art. 70-bis):

1. Il contenuto è **sotto la responsabilità dell'Istituto di istruzione**
   (Liceo "di Esempio" — copre l'attività didattica);
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
**cifrate con chiave esclusiva del docente** e **non sono accessibili**
né all'operatore tecnico né ad altri docenti dell'applicativo.

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

## 3. Audit log e tracciabilità

L'utente prende atto che:

a. **Ogni operazione di upload, modifica, eliminazione** dei propri
   contenuti viene registrata in audit log persistente con i seguenti
   metadata: identità utente, timestamp, indirizzo IP, User-Agent,
   tipo operazione, hash SHA256 del contenuto, dimensione file;
b. Tali log sono conservati per almeno **365 giorni** e possono essere
   utilizzati in caso di indagine interna, richiesta di autorità
   competenti, o cooperazione su procedure di Notice & Takedown;
c. L'operatore tecnico ({{OPERATORE_NOME}}) **non può accedere ai
   contenuti** caricati in formato decifrato grazie all'architettura
   di envelope encryption per-teacher KEK; può tuttavia accedere ai
   suddetti metadata.

## 4. Responsabilità per i contenuti caricati

L'utente riconosce e accetta che:

a. **La responsabilità civile, penale e disciplinare per i contenuti
   caricati ricade esclusivamente sull'utente medesimo**, in qualità
   di Autorizzato al trattamento dalla Scuola;

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
   responsabilità per i contenuti caricati**, riconoscendo che
   l'architettura tecnica dell'Applicativo (envelope encryption
   per-teacher KEK) gli impedisce di accedere ai contenuti decifrati
   degli altri docenti. L'operatore tecnico resta responsabile
   unicamente delle misure tecniche e organizzative infrastrutturali
   ex art. 32 GDPR.

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

## 7. Sospensione e cessazione

L'operatore tecnico si riserva il diritto di:

a. **Sospendere temporaneamente** l'account dell'utente in caso di
   violazione sospetta dei presenti Termini, per il tempo necessario
   alla valutazione;
b. **Espellere definitivamente** l'utente in caso di violazione
   accertata e grave, con conservazione dell'audit log per le
   tempistiche di legge;
c. Procedere alla **segnalazione al Dirigente Scolastico** dell'Istituto
   di appartenenza dell'utente per i procedimenti disciplinari del
   caso;
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
- Indirizzo IP di origine
- User-Agent del browser/dispositivo

---

*Per chiarimenti contattare: {{OPERATORE_EMAIL}}*

*Versione documento: 1.0 — 20 maggio 2026.*
