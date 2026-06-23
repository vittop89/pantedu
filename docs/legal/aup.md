---
title: "Acceptable Use Policy (AUP) — pantedu"
subtitle: "Politica di utilizzo accettabile per docenti e studenti"
version: "1.0"
date: "20 maggio 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Acceptable Use Policy (AUP)

**Versione**: 1.0 · **Data**: 20 maggio 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-05-20)**: l'AUP è attiva e vincolante. È
> linkata in: footer pubblico, modale licenza, modale cookie, form di
> registrazione. Le restrizioni copyright (art. 70-bis L. 633/1941)
> sono enforced automaticamente: contenuti classificati come
> `book_textbook` o `mixed` NON possono essere condivisi nel pool
> (vedi `app/Services/Sharing/SharedContentPolicy.php`).
>
> ⚠️ La sezione "limiti upload" (max 5 MB, antivirus ClamAV, EXIF strip,
> watermark) descrive la **specifica di progetto** di un sistema di
> upload file: **NON è ancora implementata** (Phase 26). Attualmente
> pantedu non accetta upload di file binari dagli utenti; l'AUP
> applica solo a contenuti testuali/markdown e mappe `.drawio` generate
> tramite editor integrato.

---

## Premessa

La presente Acceptable Use Policy (AUP) integra i Termini di Servizio (ToS)
e specifica nel dettaglio **cosa è ammesso e cosa è vietato** caricare,
condividere o produrre nell'applicativo pantedu.

L'AUP si applica a tutti gli utenti — docenti, studenti, amministratori —
con specifiche differenti in funzione del ruolo.

---

## 1. Cosa È AMMESSO

### 1.1 Per i docenti

| Categoria | Contenuto | Note |
|-----------|-----------|------|
| Esercizi (visibili agli studenti) | Riferimenti bibliografici (fonte, pagina, numero, difficoltà) + svolgimento di propria produzione | Allo studente NON vengono mostrate traccia/soluzione del libro |
| Esercizi (uso privato docente — ex art. 70-bis L. 633/1941) | Tracce + soluzioni del libro di testo SALVATE PER USO PRIVATO del docente, per costruire verifiche/esercitazioni proprie | Cifrate con chiave esclusiva del docente; **NON visibili agli studenti** né condivise con altri docenti |
| Esercizi | Esercizi originali creati ex novo dal docente | Sì |
| Mappe concettuali | Mappe proprie create con editor drawio | Sì |
| Verifiche | Template di verifiche prodotte dal docente in proprio | Esportate come PDF |
| Risdoc | Programmazione iniziale, relazione finale, scheda recuperi | Con dati didattici quantitativi e osservazioni qualitative |
| Materiale didattico | Slide proprie, riassunti propri, schede operative | Sì |
| Riferimenti web | Link a risorse online lecite | Sì |
| Foto della lavagna | Foto delle proprie lezioni | Solo se non contengono dati personali di studenti identificabili |

### 1.2 Per gli studenti

| Categoria | Contenuto | Note |
|-----------|-----------|------|
| Mappe concettuali proprie | Create con drawio integrato | **Salvate su Google Drive personale** — NON sul server pantedu |
| Foto svolgimenti propri | (**Funzionalità futura**) Foto di esercizi del libro svolti a mano | Da implementare; limite 5MB/file |
| Username pseudonimo | Codice random + mapping offline da docente | Sì |

---

## 2. Cosa NON È AMMESSO

### 2.1 Violazioni del diritto d'autore (L. 633/1941)

**VIETATO RENDERE PUBBLICO** (visibile agli studenti tramite UI o
condividere con altri docenti tramite l'applicativo):

- Scansioni o foto di pagine intere o sostanziali di **libri di testo,
  manuali, dispense** protetti da copyright;
- **Tracce + soluzioni complete** di esercizi tratti da libri commerciali,
  banche dati editoriali, libri di esami (se rese visibili a terzi);
- **Verifiche, test, quiz** tratti da repository commerciali (test di
  ammissione, prove INVALSI in modalità coperta da copyright, prove
  d'autore);
- **Soluzioni esercizi** dell'editore (esercizi propri OK; soluzioni
  altrui NO se distribuite/condivise);
- Software, immagini, video, audio coperti da licenza incompatibile
  con uso didattico (es. clipart commerciale, foto stock a pagamento,
  video YouTube protetti);
- Materiale generato da AI **senza disclosure** (es. testi prodotti da
  ChatGPT spacciati come propri senza indicazione);
- Citazioni eccedenti i limiti del **diritto di critica e cronaca**
  (art. 70 L. 633/1941: brevi citazioni per scopi didattici OK; copia
  estesa NO).

**AMMESSO ad uso strettamente privato del docente** (ex art. 70-bis
L. 633/1941, D.Lgs. 177/2021):

- Salvataggio di **tracce + soluzioni del libro di testo** nella propria
  area privata del docente, per costruire materiale didattico derivato
  (es. verifiche, esercitazioni proprie), purché:
  - **Cifrato con chiave esclusiva del docente** (envelope encryption);
  - **Non visibile agli studenti** in formato integrale tramite UI;
  - **Non condiviso con altri docenti** sull'applicativo;
  - Sotto la responsabilità dell'Istituto di istruzione;
  - Uso esclusivamente non commerciale.

**Distinzione chiave**: il copyright protegge la **distribuzione/comunicazione
al pubblico** dell'opera. La mera **conservazione privata** del docente, per
finalità illustrative nell'attività di insegnamento, è coperta dall'eccezione
didattica art. 70-bis.

### 2.2 Dati di categoria particolare (GDPR art. 9)

**VIETATO** caricare:

- **Documentazione PEI** (Piano Educativo Individualizzato);
- **Documentazione PDP** (Piano Didattico Personalizzato);
- Diagnosi o certificazioni **DSA, BES, ADHD, autismo, disturbi
  dell'apprendimento**;
- **Certificati medici**, anamnesi, dati sanitari di studenti o
  colleghi;
- **Dati genetici, biometrici**, dati sulla salute fisica/mentale;
- **Origine etnica o razziale**;
- **Opinioni religiose, politiche, filosofiche** o appartenenza
  sindacale;
- **Orientamento sessuale**, vita sessuale;
- Dati relativi a **condanne penali e reati** (art. 10 GDPR).

Tali categorie di dati restano **esclusivamente nei sistemi ufficiali
della Scuola** (registro elettronico, fascicoli amministrativi,
piattaforme PA dedicate).

### 2.3 Contenuti illegali

**VIETATO** caricare:

- Materiale **pedopornografico** o di sfruttamento di minori;
- Materiale che **istighi a reati** (terrorismo, violenza, droga,
  ecc.);
- Materiale **discriminatorio** per razza, religione, genere,
  orientamento sessuale, disabilità;
- **Apologia di reati** o di regimi totalitari;
- Contenuti che violino la **dignità della persona**.

### 2.4 Contenuti offensivi o inappropriati per contesto scolastico

**VIETATO** caricare:

- Materiale pornografico o sessualmente esplicito;
- Contenuti violenti gratuiti, gore, splatter;
- Materiale che esponga al ludopatia, gioco d'azzardo;
- Promozione di sostanze illegali;
- Linguaggio gravemente volgare o offensivo;
- Bullismo, cyberbullismo, molestie verso colleghi/studenti.

### 2.5 Spam e abusi tecnici

**VIETATO**:

- Promuovere prodotti/servizi **commerciali estranei alla didattica**;
- Caricamento **massivo automatizzato** di file (scraping);
- Tentativi di **eludere i limiti tecnici** (es. spezzare file grandi
  in molti piccoli per aggirare quota);
- Uso dell'Applicativo come **storage personale** non didattico;
- Tentativi di **accesso non autorizzato** a contenuti altrui;
- Bypass di meccanismi di **autenticazione o controllo accesso**;
- Distribuzione di **malware** o codice malevolo.

---

## 3. Limiti tecnici upload

Le seguenti limitazioni tecniche sono in vigore (o saranno applicate
quando la funzionalità upload sarà attiva):

| Parametro | Limite |
|-----------|--------|
| Dimensione massima file singolo | **5 MB** |
| Tipi file ammessi per studenti | JPG, PNG, HEIC, PDF (max 10 pagine) |
| Tipi file ammessi per docenti | JPG, PNG, HEIC, PDF, DRAWIO (export mappe) |
| Upload massimi al giorno per utente | 50 file |
| Storage massimo totale per docente | 500 MB |
| Storage massimo totale per studente | 100 MB |
| Antivirus scan su upload | Sì (ClamAV) |
| Validazione MIME server-side | Sì (ImageMagick + PDF parser) |
| Rate limit upload | 10 upload / minuto |

I limiti sono soggetti a revisione annuale in base all'uso effettivo.

---

## 4. Conseguenze violazioni — Escalation

L'individuazione di violazioni dell'AUP comporta le seguenti
**conseguenze graduate**:

### 4.1 Primo livello — Warning

- **Trigger**: violazione lieve (es. caricamento singolo errato per
  disattenzione)
- **Azione**: rimozione del contenuto entro 24h + comunicazione
  scritta all'utente + obbligo di formazione AUP

### 4.2 Secondo livello — Sospensione

- **Trigger**: violazione ripetuta o di media gravità (es. ripetuto
  upload contenuti coperti copyright nonostante warning)
- **Azione**: sospensione account 7 giorni + segnalazione informale
  al Dirigente Scolastico + rimozione contenuti

### 4.3 Terzo livello — Espulsione

- **Trigger**: violazione grave (categorie particolari art. 9 GDPR,
  contenuti illegali, ripetuta non cooperazione)
- **Azione**: espulsione permanente dall'Applicativo + segnalazione
  formale al Dirigente Scolastico per procedimento disciplinare +
  conservazione audit log per le tempistiche di legge

### 4.4 Quarto livello — Autorità

- **Trigger**: reato (es. pedopornografia, frode, hate speech penalmente
  rilevante)
- **Azione**: blocco account immediato + segnalazione obbligatoria
  alle **autorità giudiziarie** (Polizia Postale) + segnalazione
  **Garante Privacy** ove ricorra violazione GDPR + cooperazione con
  indagini

---

## 5. Procedura Notice & Takedown

Chi ritiene che un contenuto presente sull'Applicativo violi i propri
diritti (copyright, privacy, ecc.) può segnalarlo via:

**Email**: `{{OPERATORE_EMAIL}}` (forwarded a {{OPERATORE_EMAIL}})

**Form pubblico**: <https://beta.pantedu.eu/segnalazione-contenuti>

La segnalazione deve includere:
- Identità del segnalante (può essere anonima solo se cittadino privato);
- Identificazione precisa del contenuto contestato (URL, ID, descrizione);
- Tipo di violazione (copyright / GDPR art. 9 / illegalità penale /
  inappropriata);
- Motivazione e — ove disponibili — allegati a supporto.

**SLA risposta**:

| Tipo segnalazione | Tempo massimo rimozione |
|-------------------|--------------------------|
| Ordine autorità giudiziaria | Immediato (entro 24h) |
| Segnalazione Garante Privacy | 24 ore |
| Notice editore per copyright (con prova legittimazione) | 48-72 ore |
| Segnalazione privato cittadino (richiesta valutazione) | 72 ore |

---

## 6. Accettazione

L'AUP è accettata automaticamente al **primo accesso** all'Applicativo
contestualmente ai Termini di Servizio.

Le modifiche sostanziali all'AUP saranno comunicate con anticipo minimo
di 30 giorni e richiederanno nuova accettazione esplicita.

---

*Versione documento: 1.0 — 20 maggio 2026.*

*Per segnalazioni: {{OPERATORE_EMAIL}}*
*Per chiarimenti: {{OPERATORE_EMAIL}}*
