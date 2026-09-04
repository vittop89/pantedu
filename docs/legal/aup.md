---
title: "Acceptable Use Policy (AUP) — pantedu"
subtitle: "Politica di utilizzo accettabile per i docenti"
version: "1.3"
date: "4 settembre 2026"
mainfont: "Calibri"
fontsize: 10pt
geometry: "margin=2cm"
---

# Acceptable Use Policy (AUP)

**Versione**: 1.3 · **Data**: 4 settembre 2026
**Applicativo**: pantedu.eu · **Operatore tecnico**: {{OPERATORE_NOME}}

> **Stato operativo (2026-09-04)**: l'AUP è attiva e vincolante. È
> linkata in: footer pubblico, modale licenza, modale cookie, form di
> registrazione. Le restrizioni copyright (art. 70-bis L. 633/1941)
> sono applicate dal software: i contenuti classificati come
> `book_textbook` o `mixed` non possono essere condivisi né con il pool
> dell'istituto né con singoli colleghi
> (`app/Services/Sharing/SharedContentPolicy.php`).
>
> ⚠️ La sezione «Limiti tecnici upload» (§3) descrive la **specifica di
> progetto** di un sistema di caricamento file non ancora implementato.
> Oggi l'unico caricamento di file da parte dell'utente è quello delle
> pagine PDF di libri di testo della funzione PDF-Import, disattivata per
> impostazione predefinita (§2.1 e assessment AI Act); mappe e contenuti
> nascono negli editor integrati.
>
> Versione 1.3 (4 settembre 2026): corrette queste note di stato, ferme al
> 20 maggio; nessun obbligo o diritto modificato. Registro delle versioni
> in `docs/legal/versions.json`.

---

## Premessa

La presente Acceptable Use Policy (AUP) integra i Termini di Servizio (ToS)
e specifica nel dettaglio **cosa è ammesso e cosa è vietato** caricare,
condividere o produrre nell'applicativo pantedu.

L'AUP si applica a tutti gli utenti registrati — docenti e amministratori.
Gli studenti non hanno account (dal 3 settembre 2026): consultano i
contenuti pubblicati con la credenziale del docente, non nominativa, e
nulla di ciò che vedono proviene da un loro caricamento.

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

### 1.2 Studenti

Nessun caricamento: gli studenti non hanno account né spazio di
archiviazione sull'Applicativo. Le funzioni per studenti descritte nelle
versioni precedenti di questa AUP (mappe proprie, foto di svolgimenti,
username pseudonimo) non sono attive.

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
  ChatGPT spacciati come propri senza indicazione). Il contenuto generato
  **dalla piattaforma stessa** (soluzioni, argomenti e traduzioni prodotti da
  PDF-Import) è marcato automaticamente come tale — attributi
  `data-ai-generated` nel codice della pagina e sigla "IA" accanto
  all'esercizio — in attuazione dell'art. 50(2) del Regolamento (UE) 2024/1689.
  Vedi [assessment AI Act](/legal/ai-act). Rimuovere o alterare tale
  marcatura per far passare per proprio un contenuto generato ricade in questo
  divieto;
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

### 2.6 Dati personali di studenti

**VIETATO** inserire dati personali di studenti **in qualunque campo**
dell'Applicativo — titoli, note, testi, immagini compresi: nomi, elenchi
di classe, valutazioni, elaborati, fotografie in cui siano identificabili
(vedi anche la riga "Foto della lavagna" in §1.1). I marcatori BES/DSA
sugli esercizi descrivono l'esercizio, non uno studente. I dati degli
studenti restano nei sistemi ufficiali della Scuola. Stesso divieto nei
Termini di Servizio, §2.4.

---

## 3. Limiti tecnici upload

Le seguenti limitazioni si applicheranno quando la funzionalità di
caricamento file sarà attiva (vedi la nota di stato in testa):

| Parametro | Limite |
|-----------|--------|
| Dimensione massima file singolo | **5 MB** |
| Tipi file ammessi per docenti | JPG, PNG, HEIC, PDF, DRAWIO (export mappe) |
| Upload massimi al giorno per utente | 50 file |
| Storage massimo totale per docente | 500 MB |
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
- **Azione**: sospensione account 7 giorni + rimozione contenuti;
  segnalazione al Dirigente Scolastico se la violazione attiene
  all'attività di servizio

### 4.3 Terzo livello — Espulsione

- **Trigger**: violazione grave (categorie particolari art. 9 GDPR,
  contenuti illegali, ripetuta non cooperazione)
- **Azione**: espulsione permanente dall'Applicativo + conservazione
  audit log per le tempistiche di legge; segnalazione formale al
  Dirigente Scolastico se la violazione attiene all'attività di servizio

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

**Email**: `{{OPERATORE_EMAIL}}`

**Form pubblico**: <https://pantedu.eu/segnalazione-contenuti>

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

L'AUP è accettata **esplicitamente**, contestualmente ai Termini di
Servizio, mediante spunta obbligatoria al momento della registrazione.
L'accettazione è registrata con versione del documento, data e ora,
indirizzo IP e User-Agent del dispositivo.

Le modifiche sostanziali all'AUP saranno comunicate con anticipo minimo
di 30 giorni e richiederanno nuova accettazione esplicita.

Il preavviso è dato per posta elettronica all'indirizzo associato
all'account e mediante avviso nell'interfaccia dell'Applicativo. Durante
il periodo di preavviso resta vigente la versione precedente e la nuova
può essere accettata in anticipo. Chi non intende accettare la nuova
versione può esercitare il diritto alla portabilità dei dati (art. 20
GDPR) prima della data di entrata in vigore.

---

*Versione documento: 1.3 — 4 settembre 2026.*

*Per segnalazioni: {{OPERATORE_EMAIL}}*
*Per chiarimenti: {{OPERATORE_EMAIL}}*
