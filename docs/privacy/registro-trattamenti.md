---
tags:
    - documentazione/gdpr
    - phase/25.C8
date: 2026-09-25
tipo: registro-trattamenti
status: vigente
versione: 1.20
classification: pubblico — è anche nella copia pubblica del codice; esibibile al Garante su richiesta
aliases: ["registro", "ROPA", "art-30"]
---

# Registro delle attività di trattamento — Art. 30 GDPR

**Versione:** 1.20
**Ultima revisione:** 25 settembre 2026

> **Registro dovuto.** L'esenzione dell'Art. 30 §5 (meno di 250 dipendenti)
> non si applica: vale solo per trattamenti occasionali, e la gestione delle
> utenze e i registri di sicurezza non lo sono. Serve inoltre all'accountability
> (Art. 5 §2), alla gestione delle violazioni e all'esibizione al Garante su richiesta.
>
> **Non sono trattati dati identificativi di studenti**: registrazione disattivata
> dal 3 settembre 2026; gli account studente esistiti erano account di prova
> creati dall'autore, ora cancellati. Nessuno studente reale si è mai registrato. Delle sessioni con
> credenziale di classe restano i dati tecnici di connessione (B.6). Motivo: il Regolamento cloud ACN (Decreto
> direttoriale n. 21007/24) consente all'Istituto, Titolare di quei dati, di
> avvalersi solo di infrastrutture qualificate. Dettaglio in DPIA §1.
>
> ## NOTA su BES/DSA — NON dato sanitario Art. 9 in Pantedu
>
> Verificato sul codice il 27 aprile 2026: Pantedu NON traccia "studente X
> ha DSA". La spunta DSA su un esercizio è un dato del contenuto del docente
> (variante adattata dell'esercizio), e non identifica uno studente.
> I contatori `nPrintDSA`/`nPrintDIS` sono numeri di copie da stampare,
> non dati di studenti. Trattamento Art. 9 NON applicabile a Pantedu.

## Sezione A — Identificazione del Titolare

| Campo | Valore |
|-------|--------|
| Denominazione | {{OPERATORE_NOME}}, persona fisica che agisce a titolo personale e gratuito |
| Sede | Italia; domicilio digitale (PEC): <pec operatore> |
| Email contatto | {{OPERATORE_EMAIL}} |
| Responsabile della protezione dei dati | Non designato: non obbligatorio ex Art. 37 (persona fisica, nessun monitoraggio su larga scala, nessun dato Art. 9). {{OPERATORE_EMAIL}} è il recapito privacy del titolare, per l'esercizio dei diritti |
| Telefono | Comunicato su richiesta motivata (non pubblicato) |
| Tipo organizzazione | Persona fisica — piattaforma didattica per docenti di qualunque scuola |

## Sezione B — Trattamenti effettuati

### Note comuni alle schede

Tre regole valgono per tutte le schede. Stanno qui per non ripeterle in ognuna.

- **Transito da Cloudflare.** Ogni richiesta al sito passa da Cloudflare
  (Sezione D), che termina la connessione cifrata e vede la richiesta in
  chiaro, compreso l'indirizzo IP di chi visita. È un trasferimento extra-UE
  possibile, coperto da clausole contrattuali tipo e dalla certificazione
  EU-US Data Privacy Framework. La riga «Trasferimenti extra-UE» di ogni
  scheda dice gli altri.
- **Impronte di IP e User-Agent.** Dove una scheda dice «impronta», dal 24
  settembre 2026 è un HMAC-SHA256 con una chiave derivata con HKDF da un
  segreto del server, come l'impronta di sessione del registro di
  navigazione. L'indirizzo non compare in chiaro, ma l'impronta è un dato
  pseudonimo e resta dato personale. Le righe scritte prima hanno uno SHA-256
  senza chiave, che per un indirizzo IPv4 si ritrova provando tutti i valori
  possibili: si cancellano con il termine ordinario della loro tabella.
- **Cancellazione dell'account.** Avviene su richiesta dell'interessato
  (art. 17), dopo trenta giorni per ripensarci, o per un account inutilizzato
  (B.1); la stessa procedura la usa il pannello di amministrazione. Prima si
  cancellano i file del docente e i suoi contenuti conservati in chiaro
  (contratti di esercizi e laboratori, preferenze di stampa, titoli e
  argomenti, librerie delle mappe, figure in cache); poi la riga dell'account
  resta come segnaposto, per l'integrità dei registri: nome utente
  sostituito; email, nome, cognome, scuola, indirizzo, classe e data di
  nascita svuotati. Nel verbale di accettazione dei Termini restano data e
  versione; IP e User-Agent si svuotano. Per ultima si distrugge la chiave
  del docente, e quello che resta cifrato diventa illeggibile: un passo che
  non riesce non lascia niente di irreversibile, e il giro successivo
  riprende. Una cancellazione confermata non si annulla più quando è
  cominciata. I registri di B.5 e B.5-bis conservano il nome utente di allora
  per il loro termine, e quello degli eventi sui contenuti anche titolo,
  classe, indirizzo e materia dei contenuti creati (art. 17 §3, lett. b ed
  e). **Le copie fatte prima
  della cancellazione** contengono ancora il database e la chiave com'erano:
  i salvataggi giornalieri delle chiavi dei docenti, sul server, per 30
  giorni; le copie del database fatte prima di ogni rilascio, sul server,
  fino al quinto rilascio successivo; le copie cifrate fuori dal server, fino
  a un anno. La cancellazione è completa alla loro scadenza, e dopo un
  ripristino si riesegue.

### B.1 — Registrazione e gestione utenze

| Campo | Valore |
|-------|--------|
| **Finalità** | Autenticazione, autorizzazione, gestione ruoli (docente / admin) |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto registrazione (TOS) |
| **Categorie interessati** | Docenti (di qualunque scuola; il Titolare è il gestore della piattaforma, non l'Istituto), admin. **Studenti: nessuno** (registrazione disattivata dal 2026-09-03; gli account studente esistiti erano account di prova creati dall'autore, ora cancellati) |
| **Categorie dati** | nome utente, nome, cognome, email, password (come hash bcrypt), ruolo; per la verifica in due passaggi, il segreto dell'app di autenticazione (conservato così com'è, non cifrato) e i codici di riserva (come hash bcrypt); la scuola o le scuole dichiarate dal docente (`teacher_institutes`), **facoltative** dal 2026-09-22 fuori dallo Scenario 3 — si aggiungono e si tolgono dal profilo, e senza restano spenti catalogo, fonti, adozioni, credenziali di classe, incarichi, pubblicazione in rete e condivisione con i colleghi (`App\Services\SenzaScuola`); l'indirizzo e la classe **dichiarati dal docente al momento dell'iscrizione** (`users.indirizzo`, `users.classe`); indirizzi e classi insegnati (`curriculum_teacher`) con gli incarichi sulle classi dati dall'amministratore (`teacher_sections`: su pantedu.eu una classe, anno di un indirizzo o sezione, si usa solo con l'incarico, ADR-041 e ADR-043); scelta come docente che pubblica in rete (`users.pubblica_in_rete`, B.4) |
| **Destinatari** | Solo Titolare, e i fornitori della Sezione D: Hetzner (hosting, Germania), Cloudflare (transito), Backblaze (copie cifrate), Resend (email di servizio) |
| **Trasferimenti extra-UE** | Hosting no (Hetzner, Germania). Le email di servizio passano da Resend (USA) con clausole contrattuali tipo (Sezione D). Transito da Cloudflare: note comuni |
| **Tempi conservazione** | Per la durata dell'account. Un account senza accessi da 24 mesi si cancella, dopo tre avvisi per email 60, 30 e 7 giorni prima della cancellazione, ciascuno con la data; basta un accesso per azzerare il conto. Nessun account si cancella se l'ultimo avviso non è partito almeno 7 giorni prima. Esclusi gli account di amministrazione della piattaforma. Che cosa fa la cancellazione: note comuni |
| **Misure sicurezza** | Password come hash bcrypt (costo 12); HTTPS; protezione CSRF; al massimo 10 tentativi di accesso al minuto per indirizzo IP |

### B.1-bis — Domande di iscrizione

| Campo | Valore |
|-------|--------|
| **Stato** | Su pantedu.eu le iscrizioni dei colleghi non sono ancora aperte: la scheda descrive il trattamento dal giorno in cui si aprono |
| **Finalità** | Ricevere e valutare la domanda di iscrizione di un docente, e tenere traccia della decisione |
| **Base giuridica** | Art. 6(1)(b) — misure precontrattuali adottate su richiesta dell'interessato |
| **Categorie interessati** | Docenti che chiedono di iscriversi |
| **Categorie dati** | Domanda in attesa: nome utente proposto, nome, cognome, email, password come hash bcrypt, scuola o scuole, indirizzo e classe dichiarati, data della domanda e dell'accettazione dei Termini: stanno in un file sul server (`registrations.json`), non nel database, finché la domanda non è decisa. Esito: nel registro delle operazioni (B.5-bis), con nome utente, ruolo, esito, motivo di un rifiuto, chi ha deciso e quando, e le impronte di IP e User-Agent di chi ha presentato la domanda |
| **Destinatari** | Solo Titolare. Le email sulla domanda (ricevuta, approvazione, rifiuto) passano da Resend (Sezione D); una copia del loro testo resta nel registro della posta (B.11) |
| **Trasferimenti extra-UE** | Sì, per i soli messaggi di posta: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | Domanda non decisa: 30 giorni, poi si cancella (lavoro notturno). Domanda approvata: diventa l'account (B.1) e si toglie dal file; respinta: si toglie dal file. Esito nel registro delle operazioni: 2 anni, il suo termine |
| **Misure sicurezza** | Password mai in chiaro. Nel file non si conservano l'IP e lo User-Agent di chi presenta la domanda. Il modulo non rivela se un nome utente o un'email hanno già un account: la risposta è la stessa, e all'indirizzo arriva un avviso, al più uno all'ora |
| **Correzione (2026-09-24)** | Il termine di 30 giorni era dichiarato e non si applicava a niente: il lavoro che doveva applicarlo cancellava da una tabella del database che il codice non scrive, mentre le domande stanno nel file. Il file teneva anche uno storico delle decisioni senza termine, e le domande conservavano IP e User-Agent in chiaro. Ora la pulizia lavora sul file, lo storico non si scrive più (l'esito sta nel registro delle operazioni) e IP e User-Agent non si scrivono più. Tolta anche la copia degli account approvati in `users.json`, con l'hash della password, che nessuna cancellazione toccava. **Correzione di sicurezza**: approvare una domanda con il nome utente di un account esistente ne sostituiva la password; con le iscrizioni chiuse non è mai stato raggiungibile su pantedu.eu |

### B.2 — Varianti DSA/DIS degli esercizi (NON Art. 9)

| Campo | Valore |
|-------|--------|
| **Finalità** | Permettere al docente di gestire varianti adattate dello stesso esercizio (es. formula esplicita per copia DSA, font dyslexia-friendly per copia DIS). Conta numeri di copie da stampare per ogni variante. |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto, parte della funzione core piattaforma |
| **Categorie interessati** | Docenti (autori dell'esercizio adattato) |
| **Categorie dati** | La spunta DSA sugli elementi di un esercizio (`dsa-checkbox`, un dato del contenuto), i contatori `nPrintDSA`/`nPrintDIS` (numero di copie da stampare per variante, nelle preferenze di stampa di B.3), i marcatori `(*F*)` / `(*GF*)` nel testo |
| **NON trattati** | Identificativi studente (l'app non sa "Mario Rossi è DSA"), certificazioni mediche, PEI/PDP. Questi dati sono gestiti dalla scuola via registro elettronico esterno. |
| **Destinatari** | Solo docente proprietario |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | Per la durata dell'account del docente; si cancellano con lui (note comuni) |
| **Misure sicurezza** | Accesso riservato al docente proprietario. **Non sono cifrati**: il corpo degli esercizi, con la spunta, e le preferenze di stampa, con i contatori, si conservano in chiaro (B.3) |

### B.3 — Contenuti didattici docenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Creazione, archiviazione, condivisione esercizi/verifiche/mappe/risdoc per attività didattica |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente |
| **Categorie interessati** | Docenti |
| **Categorie dati** | Corpo dei contenuti (HTML e Portable Text), titoli, argomenti e metadati; nessun file caricato dall'utente, salvo le pagine PDF di libri di testo di PDF-Import (B.9, disattivata); compilazioni dei modelli risdoc (cifrate con la chiave del docente dal 2026-09-04, migration 101). Senza account studente, i campi dei modelli riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio. Per Istituto, le compilazioni dei modelli istituzionali possono non essere salvate affatto (`institutes.compilation_storage`, migration 103): restano nel browser del docente. La configurazione la decide il Titolare della piattaforma; nessun soggetto esterno la determina |
| **Destinatari** | Docente proprietario; sessioni con credenziale del docente, non nominative (per i contenuti pubblicati, B.4) |
| **Trasferimenti extra-UE** | NO dalla piattaforma, salvo il transito da Cloudflare (note comuni). Le copie che il docente sceglie di tenere nel proprio Google Drive o GitHub sono in B.3-bis |
| **Tempi conservazione** | Per la durata dell'account; con la cancellazione (note comuni) la chiave del docente si distrugge e i contenuti in chiaro si cancellano. **Eccezione, dal 2026-09-22 (ADR-046): le compilazioni dei modelli risdoc non seguono la vita dell'account.** Si cancellano 15 giorni dopo l'ultima fra lo scaricamento del documento e l'ultima modifica, e comunque il 31 agosto se ferme dal 1° giugno precedente; il docente è avvisato per email, di regola sette giorni prima; se l'avviso parte in ritardo, la bozza si cancella non prima di tre giorni dopo l'avviso, e senza un indirizzo valido si cancella senza avviso. Ogni modifica fa ripartire il conto. Il modello resta, si cancella la compilazione. Regola in `App\Services\Risdoc\ScadenzaDelleBozze`, esecuzione `tools/gdpr/spazza_bozze_scadute.php` (timer `pantedu-risdoc-scadenze`), sorveglianza dall'invariante `bozze` di `tools/ops/diagnostica.php`. Eventi sui contenuti (`content_action_log`) e uso delle chiavi di recupero (`teacher_recovery_audit`): 5 anni come gli accessi privilegiati, termine di prescrizione degli abusi che servono a ricostruire, con IP e User-Agent come impronta (note comuni; `tools/audit/purge_old_logs.php`). Il testo di un documento passa dal server per generare il PDF o il pacchetto TeX e **non viene conservato**: si trasforma e si cancella nel corso della stessa richiesta (Informativa §5.1) |
| **Preferenze di stampa** | `print_info_data`: per ogni terna indirizzo-classe-materia del docente, le scelte con cui stampa (istituto e indirizzo dichiarati nel profilo, sezione, anno, titolo della verifica, numero di copie, spunte DSA/DIS). Sono dati del docente, in chiaro, e servono a non ridigitarli a ogni stampa. **Non contengono nomi di alunni**: i due campi della «verifica nominativa» che il pannello offre per stamparli sul foglio restano nel browser, e dal 2026-09-22 il server non li accetta più (prima sì, in chiaro; misurato: zero righe li contenevano) |
| **Misure sicurezza** | Cifratura AES-256-GCM con una chiave casuale per docente (KEK), avvolta con una chiave derivata dalla chiave master con HKDF-SHA256. **Cifrati**: i file delle mappe, i file TeX e PDF delle verifiche salvate, le compilazioni dei modelli risdoc, i token di Drive e GitHub, il corpo dei contenuti nelle colonne cifrate. **In chiaro**: i contratti di esercizi, verifiche e laboratori con le loro versioni precedenti, una copia del corpo HTML accanto a quella cifrata, titoli, argomenti e metadati, le preferenze di stampa. La chiave master è stata cambiata il 24 settembre 2026 (A-88, Sezione E): il cambio riavvolge le KEK e non le cambia |

### B.3-bis — Copia dei contenuti in un servizio del docente (Google Drive, GitHub)

| Campo | Valore |
|-------|--------|
| **Stato** | Google Drive attivo su pantedu.eu dal 2026-09-15; GitHub disponibile, nessun docente l'ha configurato (misurato il 2026-09-21) |
| **Finalità** | Tenere una copia dei propri contenuti (mappe e verifiche) nel proprio Google Drive o nel proprio repository GitHub, su scelta del singolo docente |
| **Base giuridica** | Art. 6(1)(b) — funzione del servizio attivata dal docente |
| **Categorie interessati** | Docenti che attivano il collegamento |
| **Categorie dati** | Sul server: token di aggiornamento Google e token personale GitHub, cifrati con la chiave del docente; ambito concesso; indirizzo email dell'account Google; cartella radice; repository e ramo; data e ultimo errore della sincronizzazione (`teacher_drive_oauth`, `teacher_github_sync`). Verso il servizio: i file delle mappe e delle verifiche del docente, **in chiaro** |
| **Destinatari** | L'account del docente presso Google LLC o GitHub, Inc. |
| **Trasferimenti extra-UE** | Sì, verso l'account del docente, nel rapporto fra il docente e il fornitore (Informativa §9.2). Google è comunque elencato in Sezione D |
| **Tempi conservazione** | Sul server fino alla disconnessione, che cancella la riga. Con la cancellazione dell'account (note comuni) la riga si cancella, con il token e l'indirizzo email dell'account Google (dal 24/9/2026; prima la riga restava, con il token reso illeggibile dalla distruzione della chiave). Le copie restano nell'account del docente, che ne dispone |
| **Misure sicurezza** | Google Drive con ambito `drive.file`: la piattaforma vede solo i file che ha creato. **Eccezione**: la rotta `/teacher/drive/connect-migration`, aperta a ogni docente, chiede anche `drive.readonly`, cioè la lettura di tutto il Drive, per recuperare mappe già presenti nel Drive e create da un'altra applicazione; il gettone ottenuto così vale anche per quell'ambito. La disconnessione cancella il gettone dal server; il consenso dato a Google si revoca dall'account Google. Token cifrati con la KEK del docente. **Limite dichiarato**: le condivisioni del Drive e la visibilità del repository le decide il docente, non la piattaforma (DPIA R21) |

### B.4 — Pubblicazione contenuti agli studenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Distribuzione alla classe dei contenuti pubblicati dal docente, per consultazione: mappe, esercizi propri e, per gli esercizi tratti da libri di testo, il solo riferimento bibliografico con l'eventuale svolgimento del docente |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del contratto con il docente |
| **Categorie interessati** | Docenti pubblicatori. Le sessioni di consultazione avvengono con la credenziale del docente (`fm_teacher_access`), delimitabile per classe e **non nominativa**: nessun dato identificativo dello studente, sessione non associata a una persona; restano i dati tecnici di connessione (B.6) |
| **Categorie dati** | Nessuna copia per la classe: lo studente legge il contenuto del docente dove il docente lo ha pubblicato (`content_publications`: scuola, indirizzo, classe, materia, stato), conservato come dice B.3: cifrato con la chiave del docente o in chiaro, secondo il tipo di contenuto |
| **Destinatari** | Sessioni con la credenziale del docente, non nominative: vedono solo i contenuti pubblicati di quel docente e, se la credenziale è delimitata, della sua classe (`ClassAccessGrant`) |
| **Contenuti visibili senza accesso** | I contenuti pubblicati di **un solo docente** per installazione sono leggibili anche senza credenziale, solo nelle sezioni della barra laterale (per esempio «Mappe concettuali») che l'amministratore rende pubbliche, per tutte le sue classi, con o senza sezione: chi visita le sceglie con i selettori di indirizzo e classe. Il docente lo sceglie l'amministratore, con motivazione a registro (`users.pubblica_in_rete`, un indice unico ne ammette uno; migration 129); senza scelta non è visibile niente. Su pantedu.eu il docente scelto è il Titolare. Dei visitatori restano i dati tecnici di connessione (B.6) |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | Nessun dato proprio: le pubblicazioni seguono il contenuto e si cancellano con lui. Con la cancellazione del docente (note comuni) il contenuto diventa illeggibile o si cancella, e gli studenti perdono l'accesso |
| **Misure sicurezza** | Nessuna copia per classe e nessuna chiave di classe. La copia cifrata per classe con chiavi proprie, prevista dalla Phase 25.D6 per sopravvivere alla cancellazione del docente, non è mai stata attivata (tabelle vuote) ed è stata rimossa il 2026-09-14 (ADR-037, migration 120). La credenziale si riverifica a ogni richiesta: disattivata o scaduta, l'accesso cade |

### B.5 — Audit log accessi privilegiati

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento degli abusi dei privilegi di amministrazione; accountability (art. 5 §2, art. 24) e sicurezza (art. 32) |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo alla sicurezza e alla dimostrabilità degli accessi di amministrazione |
| **Categorie interessati** | Amministratori; i docenti i cui contenuti o account sono oggetto dell'operazione (`resourceType`, `resourceId`) |
| **Categorie dati** | username, action, resourceType, resourceId, **reason** (obbligatoria), data e ora, impronte di IP e User-Agent (note comuni). Il nome utente resta per il termine del registro anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | 1825g (5 anni — termine prescrizione abusi amministrativi) |
| **Misure sicurezza** | **Append-only a livello di database** dal 2026-09-02: trigger che rifiutano modifiche e cancellazioni; l'utenza applicativa non può rimuoverli. Purga delle righe scadute riservata a `pantedu_maint`. Impronte di IP e User-Agent; middleware RequiresAuditReason. Impronta giornaliera a blocchi (`tools/audit/export_audit_chain.php`), conservata fuori dal server con il backup cifrato e verificabile. Dettaglio e limite residuo: DPIA §4, nota sulle tre utenze |

### B.5-bis — Registro delle operazioni (tutti i ruoli)

| Campo | Valore |
|-------|--------|
| **Finalità** | Poter ricostruire chi ha fatto cosa sulla piattaforma: accountability Art. 5 §2, rilevamento abusi, gestione dei reclami |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo sicurezza; Art. 6(1)(c) per la parte di accountability |
| **Categorie interessati** | Tutti gli utenti autenticati (docenti, amministratori) e i tentativi non autenticati respinti. Le sessioni con credenziale del docente compaiono come anonime. Le righe riferite agli account studente di prova, creati dall'autore e ora cancellati, non riguardano studenti reali |
| **Categorie dati** | username, ruolo effettivo, metodo HTTP, percorso, stato della risposta, esito, evento di dominio (iscrizione, consenso genitoriale), soggetto dell'operazione, dettagli, data e ora, impronte di IP e User-Agent (note comuni), identificativo della richiesta. Il nome utente resta per i due anni del registro anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | 2 anni (`tools/audit/purge_old_logs.php`) |
| **Misure sicurezza** | Append-only a livello di database (trigger `trg_append_only_audit_activity_log_*`); IP come impronta, mai in chiaro; **minimizzazione: le letture andate a buon fine non vengono registrate**, solo le scritture, i tentativi respinti e gli eventi di dominio. Dal 2026-09-23 il percorso si registra senza query e con i gettoni mascherati (`PercorsoSenzaGettoni`); le righe precedenti, che non si modificano, possono contenere gettoni: quelli di conferma sono monouso e oggi scaduti, e il codice QR di una credenziale di classe vi compare solo se la sua rotta ha risposto «troppe richieste» prima del 23 settembre 2026 |

**Perché esiste (2026-09-02).** Fino a questa data le operazioni di studenti e
docenti vivevano solo in `access_log.json`, un file riscritto per intero a ogni
richiesta e troncato alle ultime mille voci: in produzione copriva sette
giorni, e i tre mesi precedenti erano stati scartati senza che nessuno potesse
accorgersene. Nessuna tabella registrava le azioni di uno studente. Il
registro dichiarava una capacità di ricostruzione che il sistema non aveva.

### B.5-ter — Registro delle operazioni sulle chiavi dei docenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Tracciare ogni operazione sulla chiave di un docente (cifratura, decifratura, distruzione, cambio) e chi l'ha fatta; dimostrare gli accessi di un amministratore ai contenuti cifrati di un docente |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo alla sicurezza e alla dimostrabilità degli accessi (art. 5 §2, art. 32) |
| **Categorie interessati** | Docenti, per ogni operazione sui propri contenuti cifrati; amministratori che vi accedono |
| **Categorie dati** | Identificativo di chi opera e del docente, tabella e riga, operazione, motivazione quando chi opera non è il docente, esito, data e ora (`crypto_access_log`). Nessun indirizzo IP |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | 5 anni, come gli accessi privilegiati (`tools/audit/tabelle_da_purgare.php`) |
| **Misure sicurezza** | L'applicazione vi aggiunge solo righe; a differenza di B.5 e B.5-bis nessun trigger del database lo impone |

### B.6 — Logging IP / User-Agent (rilevamento anomalie)

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento brute-force, account takeover, anomalie comportamentali |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo (sicurezza); valutazione dell'interesse legittimo in DPIA §2 |
| **Categorie interessati** | Docenti e amministratori, compresi i tentativi di accesso falliti; studenti che entrano con la credenziale di classe, per lo più minorenni; visitatori non autenticati (registro del filtro di sicurezza e limitatore di frequenza) |
| **Categorie dati** | Indirizzo IP e User-Agent: come impronta nei registri su database (note comuni), in chiaro negli archivi della riga «In chiaro, per la sola sicurezza»; data e ora; azione o percorso richiesto |
| **Destinatari** | Solo Titolare. Quando nel pannello del filtro è configurata la chiave del servizio, l'amministratore può chiedere a CrowdSec (Francia, UE) la reputazione di un singolo IP del registro (Sezione D) |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | Registro di navigazione (`access_log.json`): **ultime mille voci**, pochi giorni secondo il traffico (`app/Config/audit.php`, `access_log_max_entries`). Registro delle operazioni (`audit_activity_log`) 2 anni; accessi privilegiati, eventi sui contenuti, uso delle chiavi di recupero e operazioni sulle chiavi dei docenti (`privileged_access_log`, `content_action_log`, `teacher_recovery_audit`, `crypto_access_log`) 5 anni. **Correzione (2026-09-22)**: qui si leggeva «365g», il valore di una chiave di configurazione che nessun codice leggeva, tolta lo stesso giorno |
| **Misure sicurezza** | Impronte con chiave nei registri su database (note comuni). IP in chiaro solo negli archivi della riga «In chiaro, per la sola sicurezza», con il termine indicato per ciascuno |
| **Correzione (2026-09-03)** | `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent **in chiaro**, `audit_activity_log` lo User-Agent, contro quanto dichiarato qui e nell'Informativa. Logica di hash unificata (`RequestFingerprint`), righe esistenti convertite (migration 100). Log di accesso del server web disattivato dalla stessa data |
| **In chiaro, per la sola sicurezza** | `waf_logs`, il registro del filtro di sicurezza: IP, percorso richiesto, referer, User-Agent ed esito di ogni richiesta, per 30 giorni, cancellati ogni giorno. `rate_limits` (pulizia giornaliera): IP e, per chi ha fatto l'accesso, nome utente; ogni notte si cancellano le righe più vecchie di un'ora, quindi restano al più fino al giorno dopo. L'ingresso con credenziale di classe, sia con la password sia con il QR, passa dal limitatore di frequenza: anche l'IP degli studenti resta quindi in `rate_limits` fino alla pulizia del giorno dopo. `waf_login_failures`, i tentativi di accesso falliti: IP e nome tentato, per un giorno, cancellati ogni notte (restano al più circa due giorni). `access_log.json`, il registro di navigazione degli utenti autenticati: nome utente, ruolo, percorso senza query, IP e User-Agent, impronta della sessione; le ultime mille voci. `debug.log`: a ogni uscita una riga con nome utente, ruolo, IP e impronta della sessione; ruota oltre 5 MB tenendo cinque copie, senza un termine di tempo. `user_tos_acceptance`: IP e User-Agent quale prova dell'accettazione dei Termini, dieci anni dall'accettazione (con la cancellazione dell'account si svuotano; restano data e versione). Le sessioni attive sono file sul server e non contengono l'indirizzo IP; valgono 30 minuti di inattività e al massimo 12 ore |
| **Altri archivi tecnici** | Blocchi del filtro (`waf_blocked_ips`, `waf_blocked_credentials`): l'IP o il nome utente bloccato, con il motivo. I blocchi automatici scadono, ma la riga resta: oggi nessun lavoro la cancella. Consultazioni di CrowdSec (`waf_cti_cache`): l'IP consultato e la risposta; nessuna cancellazione automatica. Copie del database fatte prima di ogni rilascio: le ultime cinque, sul server, non cifrate; contengono tutto il database. `access_stats.json`: dal 24 settembre 2026 solo il numero di accessi di ogni giorno, senza nomi |
| **Correzione (2026-09-24)** | `rate_limits`: la pulizia giornaliera era dichiarata dal 4 settembre 2026 e non girava, perché nessuna unità lanciava lo script; la tabella conteneva righe dal 19 aprile. Gira ogni giorno alle 03:15 dal 24 settembre, e il primo giro ha tolto 6.536 righe. `waf_logs`: la cancellazione dei trenta giorni girava il primo di ogni mese, quindi una riga poteva restare fino a circa due mesi; ora gira ogni giorno, come le altre pulizie con un termine in giorni. `access_stats.json`, un file accanto al registro di navigazione che nessun documento dichiarava, teneva per sempre i nomi utente entrati ogni giorno e, per ciascuno, il primo e l'ultimo accesso; dal 24 settembre tiene solo i totali giornalieri |
| **Credenziali fuori dal registro (2026-09-22)** | Il codice del QR della credenziale di classe viaggia nel percorso (`/accesso-classe/qr/{token}`) e apre il perimetro pubblicato di un docente: finiva quindi in chiaro in `waf_logs.request_uri` per trenta giorni. Dal 2026-09-22 il percorso viene mascherato prima della scrittura (`WafLogService::senzaSegreti`), e nel registro resta che un QR è stato usato, non quale |

### B.6-bis — Recupero password (link monouso via email)

| Campo | Valore |
|-------|--------|
| **Finalità** | Consentire a un utente che ha perso le credenziali di rientrare senza intervento manuale dell'amministratore |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del servizio; Art. 6(1)(f) per la parte di prevenzione abusi |
| **Categorie interessati** | Docenti e amministratori registrati |
| **Categorie dati** | Riferimento all'utente, hash SHA-256 del token (mai il token), impronta dell'IP richiedente (note comuni), marche temporali di emissione/scadenza/uso |
| **Destinatari** | Solo Titolare. L'email col link transita dal fornitore di posta della Sezione D (Resend) |
| **Trasferimenti extra-UE** | Sì, per il solo messaggio di posta: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | Riga conservata dopo l'uso (`used_at` valorizzato) come traccia verificabile di "chi ha reimpostato la mia password e quando"; purgata dopo un anno (`tools/audit/tabelle_da_purgare.php`). **Correzione (2026-09-15)**: la purga era dichiarata qui ma non applicata, la tabella mancava dal job |
| **Misure sicurezza** | Token valido un'ora e una sola volta, conservato come hash: un dump del database non contiene link utilizzabili. IP come impronta (note comuni). Intervallo minimo fra due invii. Risposta identica per indirizzo noto e sconosciuto, così da non poter scoprire chi ha un account. La reimpostazione **non** disattiva la verifica in due passaggi |

### B.6-ter — Secondo fattore di autenticazione via email

| Campo | Valore |
|-------|--------|
| **Finalità** | Verificare l'identità al momento dell'accesso, per gli utenti che scelgono l'email invece di un'app di autenticazione |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo alla sicurezza degli account; Art. 6(1)(b) per l'erogazione del servizio |
| **Categorie interessati** | Utenti che attivano la verifica in due passaggi con metodo «email» |
| **Categorie dati** | Riferimento all'utente, hash SHA-256 del codice (mai il codice), scopo (accesso o attivazione), marche temporali, numero di tentativi |
| **Destinatari** | Solo Titolare. Il messaggio col codice transita dal fornitore di posta della Sezione D (Resend) |
| **Trasferimenti extra-UE** | Sì, per il solo messaggio di posta: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | Riga conservata dopo l'uso come traccia dell'accesso; purgata dopo un anno (`tools/audit/tabelle_da_purgare.php`). **Correzione (2026-09-15)**: come per B.6-bis, la purga era dichiarata e non applicata |
| **Misure sicurezza** | Codice a sei cifre valido dieci minuti e una sola volta, conservato come hash; cinque tentativi errati lo bruciano; scopi separati fra attivazione e accesso; non finisce in alcun log. **Limite dichiarato**: anche il recupero password passa dall'email, quindi chi controlla la casella ha entrambi i fattori — la pagina di scelta lo scrive e consiglia l'app |

### B.6-quater — Cambio dell'email dell'account (link di conferma)

| Campo | Valore |
|-------|--------|
| **Finalità** | Consentire all'utente di cambiare l'email del proprio account senza che basti una sessione aperta: l'email è il canale del recupero password (B.6-bis) e del secondo fattore via email (B.6-ter) |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del servizio (rettifica, Art. 16); Art. 6(1)(f) per la prevenzione della presa dell'account |
| **Categorie interessati** | Docenti e amministratori registrati |
| **Categorie dati** | Riferimento all'utente, nuovo indirizzo da confermare, hash SHA-256 del token (mai il token), impronta dell'IP richiedente (note comuni), marche temporali di emissione/scadenza/uso (`email_change_requests`, migration 131). Nel registro delle operazioni solo l'esito e il dominio degli indirizzi |
| **Destinatari** | Solo Titolare. Il link al nuovo indirizzo e l'avviso al vecchio transitano dal fornitore di posta della Sezione D (Resend) |
| **Trasferimenti extra-UE** | Sì, per i soli messaggi di posta: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | Riga conservata dopo l'uso come traccia di "chi ha cambiato la mia email e quando"; purgata dopo un anno (`tools/audit/tabelle_da_purgare.php`) |
| **Misure sicurezza** | Serve la password attuale. L'email cambia solo aprendo il link mandato al nuovo indirizzo e confermando con un bottone, entro un'ora e una volta sola; al vecchio indirizzo arriva un avviso con il nuovo mascherato. Token come hash, IP come impronta, un invio ogni due minuti, indirizzo già usato da un altro account rifiutato. Dal 2026-09-15 `POST /me/profile` non cambia più l'email |

### B.6-quinquies — Verifica anti-bot (filtro di sicurezza)

| Campo | Valore |
|-------|--------|
| **Finalità** | Distinguere una persona da un programma automatico prima di servire la pagina: difesa da scansioni, credential stuffing e raccolta automatica dei contenuti |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo alla sicurezza del servizio; per l'accesso all'apparecchiatura terminale, misura strettamente necessaria a erogare il servizio richiesto (art. 122 del Codice, che per questa ipotesi non richiede consenso) |
| **Categorie interessati** | Chiunque visiti il sito: docenti, sessioni con credenziale di classe (quindi anche studenti minorenni), visitatori non autenticati |
| **Categorie dati** | Capacità del browser dichiarate dal dispositivo: dimensioni di schermo e finestra, numero di processori e memoria dichiarati, lingua, piattaforma, presenza di grafica, audio e plugin, se il mouse si è mosso o la pagina è stata fatta scorrere. **Non** i valori che identificano una macchina: dal 2026-09-22 impronta del canvas, modello della scheda video, impronta audio, elenco dei plugin e fuso orario non vengono più raccolti (prima sì, e nessuna regola li leggeva) |
| **Destinatari** | Nessuno: il punteggio si calcola sul server e non esce |
| **Trasferimenti extra-UE** | NO, salvo il transito da Cloudflare (note comuni) |
| **Tempi conservazione** | Il punteggio e l'esito restano in `waf_logs` per trenta giorni (B.6). Le capacità raccolte **non vengono conservate**: servono a calcolare il punteggio e si perdono con la richiesta. Il cookie `waf_session` dura un'ora |
| **Misure sicurezza** | Cookie firmato (HMAC-SHA256), `HttpOnly`, `Secure`, `SameSite=Strict`; proof-of-work per alzare il costo delle richieste automatiche. Dal 2026-09-22 una scrittura mancata nel registro non sparisce più in silenzio: finisce nel registro delle anomalie |

### B.7 — Misure di navigazione — **cessato il 2026-09-15**

| Campo | Valore |
|-------|--------|
| **Stato** | **Nessun trattamento**: nessun cookie di analytics o di marketing, nessun banner di consenso, nessuna misura di prestazione delle pagine |
| **Che cosa c'era** | Questa voce descriveva «cookie analytics opt-in, aggregati 90 giorni», un trattamento che non esisteva. Esistevano invece: un banner con la casella dei cookie «funzionali» già attiva alla prima apertura, che non bloccava davvero diagrams.net; per gli utenti autenticati che sceglievano «Accetta tutti», consensi ad `analytics` e `marketing` registrati senza mostrare quelle categorie; le misure web-vitals (metrica, percorso, finestra, connessione, IP come hash giornaliero) inviate senza consenso a `/api/vitals` e scritte dentro il container, senza volume, quindi perse a ogni rilascio e mai lette |
| **Correzione** | Banner e misure tolti dal codice; consensi `analytics` e `marketing` attivi revocati con l'evento in `consent_audit` (migration 130; in produzione uno per tipo, dello stesso utente, del 2026-05-19) |
| **Resta** | Il beacon di navigazione degli utenti autenticati (`/analytics/nav`) scrive nel log di navigazione, su base di interesse legittimo (B.6) |

### B.8 — Consenso parentale per minori (Art. 8) — **cessato il 2026-09-03**

| Campo | Valore |
|-------|--------|
| **Stato** | **Trattamento cessato il 2026-09-03**: nessun account studente, nessun consenso genitoriale. Mai attivato per studenti reali: solo prove interne dell'autore; tabella `parent_consents` vuota. Il codice resta nel software, disattivato: riattivabile solo in un'istanza condotta da un Istituto Titolare |
| **Finalità (storica)** | Conformità Art. 8 GDPR + art. 2-quinquies Codice (Italia: < 14 richiede consenso parentale) |
| **Base giuridica (storica)** | Art. 8 §1 — consenso parentale via doppio opt-in email |
| **Categorie interessati (storiche)** | Studenti < 14 anni + genitori |
| **Categorie dati (storiche)** | parent_email, parent_name (opzionale), token, timestamp confirm, IP/UA hash |
| **Tracce residue** | `consent_audit`: eventi pseudonimi (identificativo numerico dell'utente anonimizzato, tipo di evento, data, impronta dell'IP), scritti solo in aggiunta (nessun trigger del database lo impone), senza nomi né email; conservati dieci anni dall'evento (prescrizione ordinaria, `tools/audit/purge_old_logs.php`), poi purgati |

### B.9 — Estrazione esercizi da PDF tramite modelli di IA (PDF-Import)

> **Stato**: funzione **disattivata per impostazione predefinita**
> (`PDF_IMPORT_ENABLED=false`). Questa voce descrive il trattamento che si
> attiva **solo** se il flag viene abilitato. Inquadramento AI Act in
> [`../legal/ai-act-assessment.md`](../legal/ai-act-assessment.md).

| Campo | Valore |
|-------|--------|
| **Finalità** | Estrarre esercizi da pagine di libri di testo caricate dal docente e generarne soluzioni, argomento e traduzione, per la produzione di materiale didattico |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del contratto con il docente (funzione della piattaforma su sua iniziativa) |
| **Categorie interessati** | Docenti (autori del caricamento). **Non** studenti: il materiale ammesso sono libri di testo, non elaborati |
| **Categorie dati** | Immagini delle pagine del PDF caricato; testo estratto; identificativo sessione e docente; metadati operazione (modello, token, durata) in `LlmAuditLog` |
| **Dati NON trattati** | Elaborati, compiti o verifiche svolte dagli studenti; elenchi classe; valutazioni. L'uso è vincolato dall'[AUP](../legal/aup.md) e da [`ai-literacy.md`](../legal/ai-literacy.md) |
| **Destinatari** | Il fornitore di modelli configurato: Anthropic PBC (USA) oppure OpenAI L.L.C. (USA); **oppure** un'istanza Ollama locale, che non comporta alcun destinatario esterno (impostazione predefinita) |
| **Trasferimenti extra-UE** | **SÌ** con fornitore cloud — clausole contrattuali tipo art. 46(2)(c). **NO** con Ollama locale. Fornitori in Sezione D. Per un'istanza dello Scenario 3 valgono i ruoli descritti in `docs/dpo/pacchetto-scuola/Pacchetto-DPO-pantedu.md`, §5 |
| **Tempi conservazione** | Artefatti di sessione (PNG derivati + JSON) cancellati dopo `PDF_IMPORT_RETENTION_DAYS` (default 7 giorni) o subito dopo l'inserimento. Gli esercizi inseriti seguono la conservazione di B.3 |
| **Misure sicurezza** | `PiiMasker` (redazione codice fiscale ed email prima dell'invio), `PromptGuard` (difesa da prompt injection sul testo derivato dal PDF), `SsrfGuard` (allowlist host), chiavi API cifrate con la KEK del docente, budget token per docente, storage di sessione cifrato AES-256-GCM |
| **Limite dichiarato** | `PiiMasker` opera su pattern deterministici (regex) e **non riconosce i nomi propri**. La difesa contro nomi presenti su un frontespizio è organizzativa, non tecnica |
| **Marcatura output** | Il contenuto generato è marcato ex art. 50(2) Reg. (UE) 2024/1689 |

### B.10 — Segnalazioni di contenuti (modulo `/segnalazione-contenuti`)

| Campo | Valore |
|-------|--------|
| **Finalità** | Ricevere e gestire le segnalazioni di contenuti illeciti o lesivi (diritto d'autore, dati personali, contenuti inappropriati) e decidere se rimuoverli, con la procedura di [`../legal/takedown_procedure.md`](../legal/takedown_procedure.md) |
| **Base giuridica** | Art. 6(1)(c) — gestione delle segnalazioni che la legge chiede a chi ospita contenuti di terzi (art. 16 D.Lgs. 70/2003; art. 16 Reg. (UE) 2022/2065); Art. 6(1)(f) per conservare la prova di come la segnalazione è stata trattata |
| **Categorie interessati** | Chi segnala (chiunque, anche un genitore o un'autorità, senza account); il docente autore del contenuto segnalato; le persone eventualmente nominate nella descrizione |
| **Categorie dati** | Nome ed email di chi segnala (facoltativi), ruolo dichiarato, impronta con chiave dell'indirizzo IP da cui arriva la segnalazione (note comuni; fino al 24/9/2026 l'IP in chiaro, poi ridotto a impronta anche nelle righe già scritte), riferimento al contenuto, tipo di violazione (anche «Violazione GDPR art. 9», le categorie particolari), descrizione libera; stato, azione presa, note, chi ha deciso e quando (`takedown_requests`) |
| **Destinatari** | Solo Titolare. La notifica di ogni segnalazione arriva per posta alla casella abuse@, con i dati sopra tranne l'IP, passando da Resend (Sezione D) |
| **Trasferimenti extra-UE** | Sì, per il messaggio di notifica: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | Cinque anni dalla ricezione, come dice la procedura di rimozione; cancellazione automatica dal 24/9/2026 (`tools/audit/tabelle_da_purgare.php`, pulizia di ogni notte) |
| **Misure sicurezza** | Consultazione riservata all'amministratore (`/admin/takedown`). Una segnalazione del tipo «Violazione GDPR art. 9» non valutata entro sei ore fa scattare l'avviso della diagnostica (piano per le violazioni, Fase 0). Fino al 24/9/2026 l'IP si conservava e si inviava per posta in chiaro, e le righe non avevano un termine applicato |

### B.11 — Richieste degli interessati (modulo `/dpo-contact`)

| Campo | Valore |
|-------|--------|
| **Finalità** | Ricevere, tracciare e rispondere alle richieste di esercizio dei diritti (artt. 15-22) e alle segnalazioni di possibili violazioni di dati |
| **Base giuridica** | Art. 6(1)(c) — artt. 12-22 GDPR: rispondere all'interessato e poterlo dimostrare |
| **Categorie interessati** | Chi scrive (utenti, genitori, chiunque, anche senza account); le persone di cui parla il messaggio, anche minori |
| **Categorie dati** | Nome, email, oggetto della richiesta, messaggio libero, indicazione che la richiesta riguarda un minore, impronte di IP e User-Agent (note comuni), stato, date e note di lavorazione (`dpo_requests`). Le richieste di cancellazione dell'account stanno in `deletion_requests` (utente, stato, date, impronta dell'IP). **Registro della posta**: una copia del testo delle ricevute e delle notifiche di questo modulo, e delle email sulle domande di iscrizione (B.1-bis), resta in un file sul server (`mail.log`), che ruota oltre 5 MB tenendo cinque copie, senza un termine di tempo |
| **Destinatari** | Solo Titolare. La ricevuta al richiedente e la notifica al titolare passano da Resend (Sezione D) |
| **Trasferimenti extra-UE** | Sì, per i soli messaggi di posta: Resend (USA), clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | `dpo_requests`: un anno dalla richiesta. `deletion_requests`: cinque anni dalla richiesta, la prova che il diritto è stato esercitato e rispettato. Cancellazione automatica dal 24/9/2026 (`tools/audit/tabelle_da_purgare.php`, pulizia di ogni notte); prima nessun termine si applicava, e le righe restavano finché non si cancellavano a mano |
| **Misure sicurezza** | Consultazione riservata all'amministratore (`/admin/data-requests`). Una richiesta con oggetto «Segnalazione data breach» non valutata entro sei ore fa scattare l'avviso della diagnostica (piano per le violazioni, Fase 0) |

## Sezione C — Categorie particolari Art. 9

**Nessun trattamento Art. 9 attivo in Pantedu.** Vedi NOTA in cima al documento. Verificato 2026-04-27 sul codebase: app processa solo metadata di contenuto del docente + contatori numerici copie, mai dati sanitari individuali studente.

Un docente può comunque scrivere in un campo a testo libero un dato che riguarda uno studente, anche sulla salute. I Termini e l'AUP lo vietano; il rischio che resta è R20 della DPIA, e il piano per le violazioni dice che cosa fare se succede.

Se in futuro si introducesse tracking studente-DSA (es. profilo studente con flag DSA personale), questo trattamento andrebbe aggiunto qui con base giuridica Art. 9(2)(a) consenso esplicito separato + DPIA aggiornata.

## Sezione D — Fornitori che ricevono dati

La tabella elenca i responsabili del trattamento (art. 28), con il loro DPA, e
i fornitori elencati per cautela o solo se una funzione è attiva. Sotto la
tabella, gli altri destinatari.

| Fornitore | Servizio | Localizzazione | DPA |
|---------------|----------|----------------|-----|
| Hetzner Online GmbH | Hosting applicativo (server, DB, storage) | Germania (UE) | ✅ DPA Art. 28 GDPR di Hetzner (Data Processing Agreement standard, accettato all'attivazione del servizio). EU only. |
| Cloudflare, Inc. | CDN e sicurezza di bordo: termina la connessione TLS, quindi vede in chiaro ogni richiesta (note comuni della Sezione B); instradamento della posta in arrivo alle caselle @pantedu.eu (Email Routing) | Società USA, rete globale | ✅ DPA Cloudflare + SCC per i trasferimenti (Capo V GDPR) + certificazione EU-US Data Privacy Framework. |
| Backblaze Inc. (B2) | Copie di sicurezza fuori dal server (dati **cifrati lato client** prima dell'upload) | Società USA; archivio nella regione UE | ✅ DPA Backblaze, con SCC e certificazione EU-US Data Privacy Framework; i dati sono cifrati prima dell'invio → Backblaze non accede al contenuto. |
| Resend, Inc. | Invio delle email di servizio (domande di iscrizione, recupero password, codice di verifica via email, cambio email, ricevute e notifiche dei moduli di segnalazione e di contatto, avvisi): riceve indirizzo, nome e testo del messaggio, mai contenuti didattici | USA | ✅ DPA di Resend con clausole contrattuali tipo (Capo V GDPR). Il codice del secondo fattore via email vi transita (B.6-ter) |
| Google LLC (opzionale, attivo dal 2026-09-15) | Integrazione Google Drive (B.3-bis): il docente autorizza con OAuth il proprio account, solo su **opt-in** esplicito. Non esiste accesso alla piattaforma con l'account Google | USA | Elencato per cautela: le copie finiscono nell'account Google del docente, con le garanzie per il trasferimento (SCC, EU-US Data Privacy Framework) del suo rapporto con Google (Informativa §9.2) |
| GitHub, Inc. (opzionale, non in uso) | Copia dei contenuti nel repository GitHub del docente (B.3-bis), con un suo token personale | USA | Stessa posizione di Google: rapporto fra docente e GitHub (Informativa §9.2). Nessun docente l'ha configurato (misurato il 2026-09-21) |
| Fornitori di modelli di IA (eventuale, opzionale) | PDF-Import — solo se `PDF_IMPORT_ENABLED=true` **e** con provider cloud. Anthropic PBC / OpenAI L.L.C. | USA — SCC necessarie | NA finché la funzione non è attivata. Dettaglio in B.9; l'alternativa **Ollama locale** non comporta alcun sub-responsabile |

> **Non responsabili del trattamento**: **Porkbun LLC** (registrar del dominio / gestione DNS) non tratta dati personali degli interessati del servizio → non è responsabile ex Art. 28.
>
> **Posta in arrivo.** Le caselle @pantedu.eu ricevono tramite Cloudflare (Email Routing, sopra), che inoltra i messaggi alla casella del titolare. La PEC del titolare, che l'informativa indica come recapito anche per l'esercizio dei diritti, è gestita dal suo fornitore di PEC. Questi fornitori ricevono i messaggi che gli interessati scelgono di mandare, come servizio di posta del titolare.
>
> **JGraph Ltd (diagrams.net, Regno Unito)**: il visualizzatore e l'editor delle mappe sono caricati dal browser dai suoi server, che ricevono l'indirizzo IP come per ogni risorsa web; il contenuto della mappa non viaggia con la richiesta (frammento dell'indirizzo o messaggio al riquadro). Non tratta dati per conto della piattaforma → non è sub-responsabile. Misurato il 2026-09-15: nessun cookie leggibile, nessuna risorsa da altri domini.
>
> **GeoGebra GmbH (Austria, UE)**: l'editor GeoGebra, che solo i docenti aprono, si carica dai suoi server (`www.geogebra.org`), che ricevono l'indirizzo IP del docente come per ogni risorsa web. Agli studenti arriva solo l'immagine già prodotta. Non tratta dati per conto della piattaforma → non è responsabile.
>
> **CrowdSec SAS (Francia, UE)**: quando nel pannello del filtro è configurata la chiave del servizio, l'amministratore può chiedere la reputazione di un singolo IP del registro di sicurezza (B.6), anche di uno studente o di un visitatore. L'IP parte in chiaro; la risposta si conserva in `waf_cti_cache`. Nessun invio automatico da questa funzione.

### Hetzner — dettaglio DPA

- **Base giuridica**: Data Processing Agreement Art. 28 GDPR di Hetzner Online GmbH, accettato all'attivazione del servizio.
- **Localizzazione**: data center in Germania (UE); nessun trasferimento extra-UE per l'hosting.
- **Cancellazione fine contratto**: cancellazione/restituzione dati a scelta del Cliente.
- **Breach notification**: secondo le tempistiche normative (→ Art. 33 §2 GDPR).

## Sezione E — Eventi storici

### Aggiornamenti del registro

| Data | Versione | Modifica | Operatore |
|------|----------|----------|-----------|
| 2026-09-25 | 1.20 | **Sezione A**: il titolare agisce a titolo personale e gratuito; «professionista» era sbagliato. **Note comuni della Sezione B**, nuove: il transito da Cloudflare, che le schede tacevano scrivendo «NO» ai trasferimenti; le impronte di IP e User-Agent, con chiave dal 24 settembre (prima SHA-256 senza chiave, che per un IPv4 si ritrova per tentativi); che cosa fa la cancellazione dell'account. Prima era solo un aggiornamento della riga dell'utente che svuotava email, nome e cognome: nome utente, scuola e contenuti in chiaro restavano, e l'«ON DELETE CASCADE» citato in B.3-bis non scattava mai. Ora cancella prima i file e le righe in chiaro, poi riduce l'account a segnaposto e per ultima distrugge la chiave; la stessa procedura serve il pannello di amministrazione, e cancella anche il collegamento a Drive e GitHub. Le copie fatte prima della cancellazione restano fino alla loro scadenza. **B.1**: un account inutilizzato per 24 mesi si cancella dopo tre avvisi (prima: anonimizzazione a 730 giorni); dichiarati il segreto della verifica in due passaggi, non cifrato, e i codici di riserva. **Schede nuove, per trattamenti in corso e non registrati**: B.1-bis (domande di iscrizione: il termine di 30 giorni si applicava a una tabella che il codice non scrive; ora si applica al file, lo storico delle decisioni non si scrive più e l'esito sta nel registro delle operazioni), B.5-ter (operazioni sulle chiavi dei docenti, 5 anni), B.10 (segnalazioni di contenuti: l'IP era in chiaro, anche nell'email, e dal 24 settembre è un'impronta; il termine di cinque anni ora si applica), B.11 (richieste degli interessati e registro della posta: nessun termine si applicava, ora un anno per le richieste e cinque per quelle di cancellazione). **B.2 e B.3**: detto che cosa è cifrato e che cosa no; il corpo degli esercizi e le preferenze di stampa erano dati per cifrati. La chiave del docente è casuale e avvolta con una chiave derivata dalla master, non «derivata dalla master». Il preavviso delle bozze è di regola di sette giorni, non «almeno». **B.3-bis**: la rotta di migrazione chiede `drive.readonly`. **B.5**: la finalità citava l'art. 30 §1, che istituisce il registro; ora gli artt. 5 §2, 24 e 32, con base 6(1)(f); fra gli interessati i docenti oggetto delle operazioni. **B.6**: interessati anche gli studenti con credenziale e i visitatori; CrowdSec fra i destinatari; `rate_limits`, pulizia dichiarata e non eseguita fino al 24 settembre; `waf_logs` pulito ogni giorno invece che una volta al mese; tolta `sessions` (le sessioni sono file e non contengono l'IP); dichiarati `debug.log`, i blocchi del filtro, la cache di CrowdSec e le copie del database prima dei rilasci; la riga «no IP/UA in chiaro» contraddiceva quella successiva. **B.6-bis, B.6-ter, B.6-quater**: «Trasferimenti extra-UE: NO» era falso, l'email passa da Resend (USA); B.6-quinquies spostata dopo di loro. **Account studente di prova**: tolte una data di cancellazione inesatta e la parola «fittizi», perché erano account creati dall'autore. **Sezione C**: richiamato il rischio R20. **Sezione D**: tolto per Cloudflare un «instradamento UE» che nessun contratto verificabile garantisce; Cloudflare riceve anche la posta in arrivo; Backblaze è una società USA; GeoGebra e CrowdSec fra gli altri destinatari; la PEC, recapito per i diritti, non è più detta estranea ai dati degli interessati. **Sezione E**: le valutazioni ex art. 33 §5, compresa A-88. **Sezione F**: l'art. 30 §4 non fissa le 72 ore che gli si attribuivano; il registro è anche nella copia pubblica del codice. **Storia**: righe 1.13 e 1.18 riscritte come fatti | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.19 | **B.6 — il termine di conservazione era un numero che nessuno applicava.** Dichiarava 365 giorni rinviando a `app/Config/retention.php`, dove quella chiave è stata tolta il 22/9 perché nessun codice la leggeva: il registro di navigazione si tronca alle ultime mille voci, circa una settimana. Ora la voce dà il termine vero di ciascun registro, gli stessi della DPIA (§1, versione 1.22) e dell'informativa (§3.4). | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.18 | **B.1 — la scuola del docente è facoltativa** (ADR-047) fuori dallo Scenario 3. È un dato di natura diversa da indirizzo e classe: dice dove lavora l'interessato, e non serve a erogare il servizio a chi si iscrive per scrivere i propri materiali. Chi non la indica lo sa, perché il modulo elenca ciò che resta spento; si aggiunge e si toglie dal profilo. Corretta nella stessa voce un'imprecisione: il registro elencava `institute_id` fra le categorie, ma per i docenti quella colonna non è la scuola — misurato nel database di sviluppo, è NULL per i due account docente, il titolare e un account di prova, e la scuola sta in `teacher_institutes`. **Nessun dato nuovo: uno in meno** | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.17 | **B.3 — le compilazioni dei modelli risdoc hanno un termine proprio** (ADR-046): 15 giorni dall'ultima fra lo scaricamento del documento e l'ultima modifica, e comunque il 31 agosto per quelle ferme dal 1° giugno. Prima seguivano la vita dell'account, cioè restavano indefinitamente: sono il contenuto che per natura può nominare uno studente, ed è l'art. 5(1)(c) che chiedeva un termine. Avviso al docente con sette giorni di anticipo, conto che riparte a ogni modifica, e cancellazione della sola compilazione (il modello resta). Nessun dato nuovo, nessun destinatario nuovo: è una misura in meno di conservazione. Riferimenti: informativa 2.14, DPIA 1.19. | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.16 | **La voce dice che cosa c'è oggi, non che cosa diceva prima.** In B.5 la riga sui registri con l'IP in chiaro conteneva il racconto della propria formulazione precedente — che `waf_logs` fosse «l'unico registro» a toccare le sessioni con credenziale di classe. Il fatto resta scritto, ed è quello che conta: anche `rate_limits` conserva l'IP in chiaro fino alla pulizia del giorno dopo, perché l'ingresso con credenziale passa dal limitatore. Il racconto di come la voce era scritta prima sta nella riga 1.13 di questa stessa tabella, che è il posto della storia di un documento. Nessun dato nuovo, nessun destinatario nuovo: è una modifica di forma. | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.15 | **B.1**: elencati `users.indirizzo` e `users.classe`, cioè l'indirizzo e la classe che il docente **dichiara al momento dell'iscrizione**. Il registro elencava le spunte del profilo (`curriculum_teacher`) e gli incarichi (`teacher_sections`), e taceva i due campi scritti dal modulo di iscrizione. Nessun dato nuovo: erano raccolti e non dichiarati. | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.14 | **B.3**: dichiarata `print_info_data`, le preferenze di stampa del docente per ogni terna indirizzo-classe-materia, che non compariva in nessun documento. Vi si precisa che **non contiene nomi di alunni**: i due campi della «verifica nominativa» che il pannello offre per stamparli restano nel browser, e dal 22/9/2026 il server non li accetta più. Fino a quella data li salvava in chiaro, contro quanto dichiarato nella DPIA e vietato dai Termini §2.4; misurato prima di correggere, zero righe li contenevano. Riferimenti: DPIA 1.14. | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.13 | **B.3**: l'impostazione che non salva le compilazioni dei modelli istituzionali è una scelta del Titolare. Il registro la diceva presa «su indicazione del DPO» dell'Istituto: nessun DPO l'ha indicata. Fino a questa data il pannello scriveva da solo quel motivo, «indicazione del DPO dell'Istituto», nel registro degli accessi privilegiati a ogni spegnimento del salvataggio: era un testo fisso del codice, non un'indicazione ricevuta. **B.6-quinquies**: l'impronta del controllo anti-bot non si conserva più nemmeno come hash — fino al 22/9 la riga non arrivava al database perché l'INSERT falliva, e allargata la colonna si sarebbe scritta; nessuno la legge, quindi non si scrive. **Riferimenti**: tolto il numero di versione dell'informativa, che era fermo alla 2.9 mentre il documento era alla 2.11. Un numero ripetuto in due posti diverge sempre. | {{OPERATORE_NOME}} |
| 2026-09-22 | 1.12 | Aggiunto **B.6-quinquies**, la verifica anti-bot del filtro di sicurezza: c'era dal principio e non era registrata. Vi si dichiara che dal 22/9/2026 non raccoglie più i valori che identificano un dispositivo — impronta del canvas, modello della scheda video, impronta audio, elenco dei plugin, fuso orario — perché nessuna regola di punteggio li leggeva. **B.6 corretta**: diceva che `waf_logs` era «l'unico registro con l'IP in chiaro che tocca anche le sessioni con credenziale di classe», e non lo è — l'ingresso con credenziale passa dal limitatore di frequenza, che scrive l'IP in chiaro in `rate_limits`. Era un'affermazione categorica dedotta e non misurata. Dichiarato inoltre che `waf_logs` conserva anche percorso, referer e User-Agent. **Sicurezza**: il codice del QR della credenziale viaggiava nel percorso e restava in chiaro nel registro per trenta giorni; ora il percorso si maschera prima della scrittura. Riferimenti: informativa 2.11. | {{OPERATORE_NOME}} |
| 2026-09-21 | 1.11 | **B.3**: dichiarato il transito. Il testo di un documento passa dal server per generare il PDF o il pacchetto TeX; non viene conservato, e il risultato si forma e si cancella nel corso della stessa richiesta. Il registro descriveva la conservazione tacendo il passaggio. Nessun trattamento nuovo, nessun destinatario nuovo. Riferimenti: informativa §5.1 (2.10), DPIA 1.10 (R20). | {{OPERATORE_NOME}} |
| 2026-09-15 | 1.10 | **B.1**: con «solo incaricati» anche l'anno di corso di un indirizzo si usa solo con l'incarico dell'amministratore (ADR-043, migrazione 133); prima gli anni erano liberi per tutti. Nessuna categoria nuova. Riferimenti: informativa 2.10, DPIA 1.8. | {{OPERATORE_NOME}} |
| 2026-09-15 | 1.9 | Aggiunto **B.6-quater**: cambio dell'email con password, link di conferma al nuovo indirizzo e avviso al vecchio (migration 131); prima `POST /me/profile` la cambiava con il solo gettone CSRF. **B.6-bis e B.6-ter corretti**: la purga dopo un anno era dichiarata ma non applicata, le due tabelle mancavano dal job. Misurato anche che l'utenza di manutenzione non aveva il permesso di cancellare da `audit_activity_log`, `teacher_recovery_audit` e `consent_audit`: i permessi ora si ricavano dalla lista della purga, e la purga esce in errore se una tabella non si purga. Riferimenti: informativa 2.9. | {{OPERATORE_NOME}} |
| 2026-09-15 | 1.8 | **B.7 cessato**: descriveva cookie analytics che non esistevano, mentre esistevano un banner con la casella preselezionata, consensi ad analytics e marketing registrati senza mostrarli (revocati, migration 130) e misure web-vitals inviate senza consenso e mai lette (tolte). Sezione D: JGraph (diagrams.net) fra i non sub-responsabili. Riferimenti: informativa 2.8. Nella stessa giornata, prima di ogni invio: B.4 precisa che le sezioni rese pubbliche sono quelle della barra laterale, non le sezioni delle classi. | {{OPERATORE_NOME}} |
| 2026-09-15 | 1.7 | Aggiunto **B.3-bis**: copia dei contenuti nel Google Drive del docente (attivo dal 15 settembre) o nel suo repository GitHub (disponibile, non in uso). B.3 diceva «nessun trasferimento extra-UE» mentre la Sezione D elencava Google; ora la differenza è scritta. **B.4**: i contenuti di un solo docente visibili senza accesso (migration 129). **B.1**: incarichi sulle sezioni (ADR-041) e scelta del docente che pubblica in rete. **Sezione D**: Google attivo, tolto un «login con Google» che non esiste; aggiunto GitHub. Riferimenti: informativa 2.7. | {{OPERATORE_NOME}} |
| 2026-09-14 | 1.6 | **B.4 corretta**: descriveva una copia cifrata dei contenuti per ogni classe, con chiavi di classe che l'avrebbero conservata anche dopo la cancellazione del docente (Phase 25.D6). Il meccanismo non è mai stato attivato: tabelle vuote, rimosse il 14 settembre (ADR-037, migration 120). Gli studenti con la credenziale leggono il contenuto dove il docente lo ha pubblicato, e con la cancellazione del docente perdono l'accesso. B.3: destinatari allineati. Nessun trattamento aggiunto: tolta la descrizione di uno che non avveniva. | {{OPERATORE_NOME}} |
| 2026-09-04 | 1.5 | **Cessato ogni trattamento di dati di studenti**, mai avvenuto per studenti reali (Regolamento cloud ACN: Titolare di quei dati sarebbe stato l'Istituto, e l'infrastruttura di un privato non qualificato non può ospitarli). B.1, B.3, B.4, B.5-bis riscritti senza studenti; **B.8 cessato**. B.5/B.6: quattro registri conservavano IP e/o UA in chiaro contro quanto dichiarato — corretti e convertiti (migration 100); elencati i log di sicurezza che restano in chiaro; purga di `privileged_access_log` e `crypto_access_log` portata da dieci ai cinque anni dichiarati; log del server web disattivato. Titolarità dei dati dei docenti chiarita: il gestore, non l'Istituto. ToS 1.3, AUP 1.2 (poi 1.3 il 4 settembre, per le sole note di stato). Sezione D: aggiunto **Resend**, il servizio di posta transazionale, citato in B.6-bis e B.6-ter ma assente dall'elenco. Conservazioni riviste per l'art. 5(1)(e): eventi sui contenuti e chiavi di recupero da sette a cinque anni, con la ragione scritta; `consent_audit` da «permanente» a dieci anni dall'evento; `waf_logs` da 90 a 30 giorni; copie annuali di backup da due a una, con ri-cancellazione dopo ogni ripristino. Secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico all'interessato degli accessi amministrativi ai suoi contenuti; impronta giornaliera dei registri; timer per le cancellazioni art. 17, che mancava. | {{OPERATORE_NOME}} |
| 2026-09-02 | 1.4 | Aggiunti **B.6-bis** (recupero password) e **B.6-ter** (secondo fattore via email). B.5: l'append-only era dichiarato come fatto e non esisteva — applicato con trigger, con utenza separata per la sola purga. Tolti il segnaposto del telefono e il riferimento all'informativa come «PENDING». | {{OPERATORE_NOME}} |
| 2026-09-01 | 1.3 | Aggiunto **B.9** — estrazione esercizi da PDF tramite modelli di IA (PDF-Import, disattivata di default). Sezione D: aggiunti i fornitori di modelli di IA come sub-responsabili eventuali. Allineato l’elenco con il DPA art. 28, che ne riportava solo due su cinque. | {{OPERATORE_NOME}} |
| 2026-06-24 | 1.2 | Migrazione infrastruttura: hosting → **Hetzner** (DE, UE), backup → **Backblaze B2**, edge → **Cloudflare**; registrar → **Porkbun** (non sub-responsabile). Rimosso hosting legacy come hosting (resta solo eventuale PEC personale del titolare, fuori dal trattamento). | {{OPERATORE_NOME}} |
| 2026-04-29 | 1.1 | Sezione D: DPA sub-processor hosting archiviato + mappato vs Art. 28 §3 | {{OPERATORE_NOME}} |
| 2026-04-27 | 1.0 | Prima compilazione (Phase 25.C8) | {{OPERATORE_NOME}} |

### Data breach notificati

| Data | Tipo | Esposto | Notifica Garante (72h) | Notifica utenti | Risoluzione |
|------|------|---------|-----------------------|-----------------|-------------|
| _Nessuno_ | — | — | — | — | — |

### Valutazioni chiuse senza notifica (art. 33 §5)

L'art. 33 §5 chiede di documentare anche le valutazioni che si chiudono senza
notifica. Le valutazioni sono del titolare; il dettaglio è nella DPIA, storia
delle revisioni.

| Data | Evento | Esito della valutazione |
|------|--------|-------------------------|
| 2026-09-21 | La rotta che consegnava il pacchetto TeX esportato controllava che chi scaricava fosse un docente, non il proprietario del pacchetto | Nessuna violazione: a quella data l'unico docente reale era il titolare, e i pacchetti erano tutti suoi. La rotta non esiste più |
| 2026-09-24 | A-88: fino al 24 settembre la chiave master di produzione era scritta in chiaro in un annesso dell'autovalutazione di aprile, nel repository privato di sviluppo e nelle sue copie. Cambiata il 24 settembre fra le 00:25 e le 00:55; le chiavi dei docenti (KEK) restano le stesse, riavvolte con la chiave nuova | Non è una violazione di dati di terzi: la chiave proteggeva solo le chiavi di tre account, tutti del titolare o di prova. Annotata anche nel registro degli incidenti (`/admin/data-breach`). Resta da togliere il valore dall'annesso e dalla storia del repository |

## Sezione F — Manutenzione

- **Revisione obbligatoria**: annuale (gennaio di ogni anno)
- **Trigger update straordinario**: nuova feature Art. 9, nuovo fornitore che riceve dati, modifica architettura crypto, data breach
- **Esibizione**: su richiesta dell'autorità di controllo (Art. 30 §4)
- **Custodia**: nel repository del progetto (`docs/privacy/registro-trattamenti.md`), con la storia delle versioni; il file è anche nella copia pubblica del codice

## Riferimenti

- DPIA: `docs/privacy/dpia.md`
- Informative: su `/privacy/informativa` il sito serve quella dello scenario attivo. Finché le iscrizioni dei colleghi non sono aperte è `docs/privacy/informativa-personale.md`; dall'apertura, `docs/privacy/informativa.md`. La versione vigente è quella dichiarata in ciascun documento, e non si ripete qui
- Termini di conservazione applicati dal codice: `tools/audit/tabelle_da_purgare.php`, `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- ADR-006 (encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance): `wiki/decisions/ADR-007-gdpr-compliance.md` (Phase 25.C15)
- Compliance checklist: `docs/privacy/compliance_checklist.md` — documento storico del 16 aprile 2026, superato da DPIA e da questo registro
