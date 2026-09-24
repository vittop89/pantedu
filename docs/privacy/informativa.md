---
tags:
    - documentazione/gdpr
    - phase/25.C10
date: 2026-09-25
tipo: informativa-utente
status: vigente
versione: 2.17
classification: PUBLIC — esibibile a utenti finali
aliases: ["informativa", "privacy-policy"]
---

# Informativa Privacy — Pantedu

**Versione:** 2.17

**Ultima revisione:** 2026-09-25

**Prima pubblicazione:** 2026-04-27

> Questa informativa vale quando l'istanza è nello **Scenario 2**: i docenti,
> di qualunque scuola, si iscrivono a titolo personale e il Titolare è il
> gestore della piattaforma. Su pantedu.eu vale **dall'apertura delle
> iscrizioni ai colleghi**: fino ad allora vale l'informativa per l'uso
> personale (**Scenario 1**), che la stessa pagina `/privacy/informativa`
> mostra finché le iscrizioni sono chiuse. Lo **Scenario 3** (adozione da
> parte di un Istituto, che ne è Titolare) ha la propria informativa. Quale
> sia lo scenario attivo è dichiarato nel pannello di amministrazione e in
> fondo a ogni pagina legale, questa compresa.

> Le modifiche di ogni versione sono riassunte nella sezione 12. Oggi la
> piattaforma non chiede consensi facoltativi: se ne introducesse, li
> chiederebbe in modo esplicito, con la casella spenta (Art. 7 GDPR).

## 1. Titolare del trattamento

| Campo | Valore |
|-------|--------|
| Nome | {{OPERATORE_NOME}} |
| Email contatto | `{{OPERATORE_EMAIL}}` |
| Domicilio digitale (PEC) | `<pec operatore>` — recapito per comunicazioni formali e per l'esercizio dei diritti |
| Responsabile della protezione dei dati | Non designato: la designazione non è obbligatoria (Art. 37: persona fisica, nessun monitoraggio su larga scala, nessun dato Art. 9). `{{OPERATORE_EMAIL}}` è il recapito privacy del Titolare, per l'esercizio dei diritti: non è l'indirizzo di un DPO |
| Indirizzo | Italia; per le comunicazioni formali vale il domicilio digitale (PEC) |

## 2. A chi è rivolta questa informativa

- **Docenti** di qualunque scuola che si iscrivono a titolo personale, per la propria attività didattica. Il Titolare è il gestore della piattaforma, non l'Istituto in cui il docente insegna: l'iscrizione è volontaria e non disposta dall'Istituto.
- **Studenti che entrano con la credenziale di classe**: dal 3 settembre 2026 **non è prevista alcuna registrazione né alcun account**. Usano una credenziale creata dal docente, non nominativa, eventualmente delimitata a una classe; la sessione non è associata alla persona. Nessun dato identificativo dello studente viene raccolto, e nessun consenso genitoriale (vedi sezione **Minori**). Per loro sono trattati solo i dati tecnici della connessione: l'indirizzo IP resta in chiaro nei registri di sicurezza a conservazione breve, e altrove è ridotto a impronta (§3.4). A questi si aggiungono il contatore di ingressi per credenziale, il cookie di sessione, quello della verifica di sicurezza quando il filtro la chiede e, a loro scelta, quello del portachiavi (§10). Nelle pagine con le mappe, il fornitore del visualizzatore riceve l'indirizzo IP (§10).
- **Chi scrive o segnala**: chi usa il modulo per l'esercizio dei diritti (`/dpo-contact`) o quello per segnalare un contenuto (`/segnalazione-contenuti`), e chi scrive ai recapiti di questa informativa (§3.6).
- **Amministratori della piattaforma**, per la manutenzione e i controlli.

## 3. Quali dati raccogliamo

### 3.1 Dati di identificazione (sempre)

- Username, nome, cognome, email
- Password (conservata come hash bcrypt, cost 12 — mai in chiaro)
- Ruolo (docente / amministratore)
- Istituto / classe / indirizzo di studio (per i docenti: delimitano a chi sono visibili i contenuti pubblicati). La classe è l'anno di corso del tuo indirizzo («2» vale per tutte le seconde) o una sezione, come la 2A: su pantedu.eu l'una e l'altra solo se l'amministratore te ne ha dato l'incarico. **Questi dati li dichiari tu**: l'Istituto non li fornisce, non li conferma e non li può correggere, e non sono quindi l'organizzazione ufficiale delle sue classi — sono la tua dichiarazione, e la cambi quando cambia. Te lo ricorda anche il modulo di iscrizione e il tuo profilo. **La scuola è facoltativa**: dal 22 settembre 2026 puoi iscriverti senza indicarne nessuna — dice dove lavori, non che cosa insegni, ed è un dato che non serve a erogarti il servizio. Il modulo ti dice che cosa resta spento senza (il catalogo, le fonti, i libri in adozione, le credenziali di classe, gli incarichi, la pubblicazione in rete e la condivisione con i colleghi), e la aggiungi o la togli quando vuoi dal tuo profilo. Nelle installazioni adottate da un Istituto resta invece obbligatoria, perché lì senza non si potrebbe fare nulla. Il legame fra un docente e le sue sezioni, messo insieme per più docenti, descriverebbe l'organizzazione delle classi della scuola, e per questo non si raccoglie dove non serve.

**Base giuridica**: Art. 6(1)(b) GDPR — esecuzione contratto registrazione utenza.

### 3.2 Varianti adattate degli esercizi (DSA/DIS — NO Art. 9)

Il docente può segnare che un esercizio ha una **variante adattata** per copie DSA/DIS (es. con formula esplicita, font dyslexia-friendly, semplificazioni linguistiche). Può anche specificare un numero di copie da stampare in ciascuna variante (es. "3 copie standard, 1 copia DSA").

Questo è un **metadata di contenuto** del docente — NON un identificativo dello studente. L'app NON registra "lo studente Mario è DSA" e NON riceve dati sanitari personali (PEI/PDP, certificazioni mediche). Questi dati restano nella scuola tramite registro elettronico esterno + cartaceo.

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto (gestione contenuti didattici).

**Dove sta**: il segno di variante e il numero di copie per variante stanno nei dati dell'esercizio e nelle preferenze di stampa del docente, che sul server sono **in chiaro**, protetti dai controlli di accesso (§3.3).

### 3.3 Contenuti didattici autoredatti dai docenti

- Esercizi e laboratori
- Verifiche
- Mappe concettuali (file diagrams.net)
- Documenti riservati docente (risdoc)
- Strumenti compensativi BES/DSA

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente.

**Che cosa è cifrato e che cosa no.** Sono cifrati a riposo con la chiave del docente (AES-256-GCM; una chiave casuale per docente, avvolta con una chiave derivata dalla chiave master, §8): i file delle mappe, i file TeX e PDF delle verifiche salvate, le compilazioni dei modelli risdoc e i token di Google Drive e GitHub. Il resto sta sul server **in chiaro**, protetto dai controlli di accesso: il testo di esercizi, verifiche e laboratori, con le loro versioni precedenti, i titoli e gli argomenti, le preferenze di stampa (compreso il numero di copie DSA/DIS). Quando non esistono account studente, i campi dei modelli che si riferiscono a studenti o genitori non vengono salvati: il server li svuota prima di scrivere. Per alcuni Istituti le compilazioni dei modelli istituzionali (piano annuale, relazione finale, schede) non vengono salvate sul server: restano nel tuo browser, e il PDF esportato va depositato nei sistemi della scuola. La configurazione la decide il Titolare della piattaforma; nessun soggetto esterno la determina. Dove invece vengono salvate, **non restano per sempre**: si cancellano 15 giorni dopo che hai scaricato il documento, e comunque a fine anno scolastico se restano ferme (§5). Ricevi un avviso prima (di regola sette giorni prima; se l'avviso non è partito in tempo, la bozza si cancella non prima di tre giorni dopo l'avviso), ogni modifica fa ripartire il conto, e si cancella solo la tua compilazione: il modello resta.

**Chi li vede.** I contenuti che pubblichi li vedono le sessioni con la tua credenziale di classe. Senza accesso sono visibili i contenuti pubblicati di **un solo docente** per installazione, nelle sezioni della barra laterale (per esempio «Mappe concettuali») che l'amministratore rende pubbliche, per tutte le sue classi, con o senza sezione: chi visita le sceglie con i selettori di indirizzo e classe, e solo se l'amministratore sceglie quel docente, con motivazione a registro: su pantedu.eu è il Titolare. I contenuti degli altri docenti non sono mai visibili senza accesso. Se scegli di condividere un contenuto con i colleghi della tua stessa scuola — tutti, alcuni o un tuo gruppo — lo vedono insieme al tuo nome e possono copiarlo nel proprio account. La condivisione non esce dalla scuola e non è disponibile se non ne hai indicata una.

**Copie nei tuoi servizi.** Se colleghi il tuo Google Drive o un tuo repository GitHub, la piattaforma vi copia le tue mappe e verifiche **in chiaro** (§9.2).

### 3.4 Dati di accesso (IP, User-Agent)

Nei registri di audit (§5), per ogni operazione:

- Indirizzo IP — conservato come **impronta con chiave**: un HMAC calcolato con una chiave derivata da un segreto del server. L'indirizzo non compare, ma chi ha la chiave può verificare se un indirizzo noto è nel registro: è un dato pseudonimo, non anonimo, e resta dato personale. Le righe scritte prima del 24 settembre 2026 hanno un'impronta senza chiave (SHA-256), che per un indirizzo IPv4 si ricostruisce provando tutti i valori possibili: si cancellano con il termine ordinario del loro registro
- User-Agent — conservato come impronta: non si legge direttamente, ma i browser in uso sono pochi, e l'impronta si ritrova confrontandola con i più comuni. Anche questo resta dato personale
- **Nessun identificativo del dispositivo.** La verifica anti-bot (§10) legge alcune
  capacità del browser — se lo schermo, la grafica e l'audio rispondono, quanti
  processori dichiara, se il mouse si è mosso — per distinguere una persona da un
  programma automatico. Dal 22 settembre 2026 **non legge più i valori che
  identificano una macchina** (l'impronta del canvas, il modello della scheda
  video, l'impronta audio, l'elenco dei plugin, il fuso orario): di quelli serviva
  sapere se ci sono, non quali sono. Nessuna impronta del dispositivo viene conservata
- Data e ora, operazione, tipo di risorsa

**Base giuridica**: Art. 6(1)(f) — interesse legittimo sicurezza (rilevamento brute-force, account takeover, abusi privilegi).

**Conservazione**:

- Registro di navigazione (statistiche di navigazione): **le ultime mille voci**,
  che in produzione coprono circa una settimana. Il file si tronca da sé quando
  cresce: non c'è un termine in giorni, e quello di 365 che qui si leggeva fino
  al 22 settembre 2026 non era applicato da nessun lavoro — era un numero in una
  configurazione che nessun codice leggeva
- Registro delle operazioni (`audit_activity_log`): **2 anni**
- Registro degli accessi privilegiati (azioni degli amministratori): **1825 giorni / 5 anni** (termine prescrizione abusi amministrativi)

**Che cosa finisce nel registro delle operazioni.** Dal 2 settembre 2026 le
operazioni di tutti i ruoli sono registrate in una
tabella append-only e non più soltanto in un file che ne conservava le ultime
mille. Vi finiscono le scritture (ogni richiesta che non sia una semplice
lettura), i tentativi respinti, e gli eventi che una rotta da sola non
descrive: la domanda di iscrizione e il suo esito. Le sessioni con credenziale
del docente vi compaiono come anonime. Le letture andate a buon fine restano fuori: contarle
serve alle statistiche di navigazione, non a rispondere di un'operazione.
L'indirizzo IP è conservato come impronta con chiave, non in chiaro.

**Correzione del 3 settembre 2026.** Quattro registri — le operazioni sui
contenuti, gli accessi privilegiati, l'uso delle chiavi di recupero e, per il
solo User-Agent, il registro delle operazioni — conservavano indirizzo IP e
User-Agent **in chiaro**, contro quanto questa informativa dichiarava. Il
codice è stato corretto e le righe esistenti convertite ad hash con la stessa
formula. Il log di accesso del server web è disattivato dalla stessa data.

**Dove restano in chiaro.** Per la sola sicurezza e a conservazione breve,
anche per chi entra con la credenziale di classe: nel log del filtro di
sicurezza (WAF, 30 giorni) e nei contatori del limitatore di frequenza (ogni
notte si cancellano quelli più vecchi di un'ora). Per chi prova ad accedere
con nome utente e password, anche nel contatore dei tentativi falliti (ogni
notte si cancellano quelli più vecchi di un giorno). Per docenti e
amministratori, inoltre: nel registro di navigazione (IP e User-Agent, ultime
mille voci) e nella riga che un registro tecnico scrive a ogni uscita (nome
utente e IP), un file che ruota quando supera 5 MB, di cui si tengono cinque
copie, senza un termine in giorni. Restano in chiaro anche nel verbale di
accettazione dei Termini di Servizio, quale prova dell'accettazione: alla
cancellazione dell'account restano data e versione, e IP e User-Agent si
svuotano (§5). Dopo troppi tentativi il filtro blocca per qualche tempo un
indirizzo o un nome utente: gli elenchi dei blocchi oggi non si ripuliscono di
quelli scaduti. Le sessioni attive sono file sul server e non contengono
l'indirizzo IP.

**Correzioni del 24 settembre 2026.** La pulizia giornaliera dei contatori del
limitatore, dichiarata in questa informativa dal 4 settembre, non girava: la
tabella conservava indirizzi IP dal 19 aprile. È partita la notte del 24
settembre, e il primo giro ha cancellato 6.536 righe. Le statistiche di
navigazione tenevano, giorno per giorno e senza termine, il nome utente di chi
entrava, e nessun documento lo diceva: dal 24 settembre tengono solo il numero
di accessi al giorno. L'impronta dell'indirizzo IP nei registri di audit era
calcolata senza chiave, e questa informativa la diceva «non ricostruibile»:
dal 24 settembre è un'impronta con chiave.

### 3.5 Cookie

Vedi la sezione dedicata «Cookie» (§10).

### 3.6 Richieste e segnalazioni

- **Modulo per l'esercizio dei diritti** (`/dpo-contact`): nome, email,
  oggetto, messaggio e se la richiesta riguarda un minore; l'indirizzo IP e lo
  User-Agent solo come impronta.
- **Modulo per segnalare un contenuto** (`/segnalazione-contenuti`): nome,
  email, ruolo di chi segnala (anche «genitore»), il contenuto segnalato, la
  descrizione e l'indirizzo IP della connessione come **impronta con chiave**
  (§3.4). Fino al 24 settembre 2026 l'indirizzo si conservava in chiaro e
  compariva nell'email di notifica; le segnalazioni scritte prima l'hanno
  perso.
- **Email ai recapiti** di questa informativa: indirizzo del mittente e
  contenuto del messaggio.

Le ricevute e le notifiche del modulo per l'esercizio dei diritti, e le
email sulle domande di iscrizione (ricevuta, approvazione, rifiuto con la
motivazione), restano anche in un registro della posta inviata sul server, con
destinatario, oggetto e testo: il file ruota quando supera 5 MB, se ne tengono
cinque copie, e non ha un termine in giorni.

**Base giuridica**: Art. 6(1)(c) — rispondere alle richieste degli interessati
(artt. 12-22) e alle segnalazioni di contenuti illeciti (art. 16 D.Lgs.
70/2003); Art. 6(1)(f) per conservare la prova della risposta.

## 4. Finalità del trattamento

1. **Didattica** (Art. 6(1)(b) — esecuzione contratto): erogazione piattaforma per gestione esercizi/verifiche/mappe, comprese le varianti adattate degli esercizi (DSA/DIS metadata).
2. **Sicurezza** (Art. 6(1)(f) — interesse legittimo): audit log, prevenzione abusi, rate-limiting.
3. **Conformità normativa** (Art. 6(1)(c) — obbligo legale): retention policy, accountability Art. 5 §2.

Trattamenti **esplicitamente esclusi**:

- Profilazione comportamentale automatica
- Pubblicità mirata
- Vendita / cessione dati a terzi (no monetizzazione)
- Decisioni automatizzate Art. 22 GDPR
- Geolocalizzazione precisa

## 5. Tempi di conservazione

I termini qui sotto li applicano lavori pianificati che girano sul server ogni
notte: la cancellazione degli account (chiesta da te, o per inattività), la
pulizia delle domande di iscrizione, dei registri e dei contatori del
limitatore, la cancellazione delle bozze scadute. La rotazione delle copie di
sicurezza la fa lo script che le crea. I termini dei registri sono elencati in
`tools/audit/tabelle_da_purgare.php`, quelli degli account in
`app/Config/retention.php`.

| Dato | Conservazione | Azione a scadenza |
|------|---------------|-------------------|
| Account (docente) | Finché esiste | Cancellazione quando la chiedi (Art. 17, §7): vedi «Che cosa fa la cancellazione», qui sotto |
| Account senza accessi da 730 giorni | 730 giorni dall'ultimo accesso | La stessa cancellazione dell'Art. 17, dopo **tre avvisi via email**, 60, 30 e 7 giorni prima, ciascuno con la data. Basta un accesso per azzerare il conto. Nessun account si cancella se l'avviso dei 7 giorni non è partito da almeno 7 giorni: se un avviso non parte, l'account resta. Mai gli account di amministrazione della piattaforma |
| Avvisi di inattività partiti (data, destinatario implicito nell'account) | 5 anni | Cancellazione: sono la prova che l'avviso è partito |
| Domande di iscrizione non approvate | 30 giorni dalla domanda | Cancellazione. Indirizzo IP e User-Agent non si conservano nelle domande |
| Esito delle domande di iscrizione (nel registro delle operazioni: nome utente, esito, motivazione di un rifiuto, e l'impronta di IP e User-Agent di chi ha presentato la domanda) | 2 anni, il termine del registro delle operazioni | Cancellazione con il registro |
| Registro di navigazione | Le ultime mille voci (in produzione circa una settimana); il file si tronca da sé | Sovrascrittura delle più vecchie |
| Registro operazioni (`audit_activity_log`) | 2 anni | Cancellazione |
| Registro degli accessi privilegiati (azioni degli amministratori) | 1825 giorni (5 anni) | Cancellazione |
| Eventi contenuti (`content_action_log`) | 5 anni | Cancellazione |
| Uso chiavi di recupero (`teacher_recovery_audit`) | 5 anni | Cancellazione |
| Log del filtro di sicurezza (`waf_logs`, IP in chiaro) | 30 giorni | Cancellazione, con la pulizia di ogni notte |
| Contatori del limitatore di frequenza (IP in chiaro) | Un'ora | Cancellazione con la pulizia di ogni notte: restano al massimo circa un giorno |
| Tentativi di accesso falliti (IP e nome utente in chiaro) | Un giorno | Cancellazione con la pulizia di ogni notte: restano al massimo circa due giorni |
| Backup cifrati (database e file), copia locale | Circa una settimana | Cancellazione per rotazione |
| Backup cifrati (database e file), archivio fuori dal server | Rotazione a livelli: 3 giornalieri, 4 settimanali, 6 mensili, 1 annuale — una copia può sopravvivere fino a **un anno** | Cancellazione per rotazione. Le copie contengono i dati com'erano quando sono state fatte, insieme alle chiavi che li aprono: vedi «Che cosa fa la cancellazione» |
| Salvataggi giornalieri delle chiavi dei docenti, sul server | Circa un mese (30 giorni) | Cancellazione |
| Copie del database fatte prima di ogni aggiornamento del software, sul server, non cifrate | Le ultime cinque | Sostituzione con le successive |
| Nome utente nei registri di audit | Per il termine di ciascun registro (5 anni; 2 anni per il registro delle operazioni), anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) | Cancellazione con il registro |
| Titolo, classe, indirizzo e materia dei contenuti creati, nel registro degli eventi sui contenuti | 5 anni, anche dopo la cancellazione dell'account | Cancellazione con il registro |
| consent_audit (eventi pseudonimi sui consensi) | 10 anni dall'evento (prescrizione ordinaria) | Cancellazione |
| Consensi e consensi dei genitori, verbale di accettazione dei Termini, avvisi delle nuove versioni dei documenti | 10 anni (prescrizione ordinaria). Alla cancellazione dell'account, del verbale restano data e versioni: IP e User-Agent si svuotano | Cancellazione |
| Richieste di cancellazione dell'account | 5 anni dalla richiesta: la prova che il diritto è stato esercitato e rispettato | Cancellazione |
| Bozze di compilazione dei modelli (`risdoc`) salvate sul server | **15 giorni** dall'ultima fra lo scaricamento del documento e l'ultima modifica; e comunque il **31 agosto**, per quelle ferme dal 1° giugno precedente | Cancellazione, dopo un avviso al docente: di regola sette giorni prima; se l'avviso non è partito in tempo, non prima di tre giorni dopo l'avviso. Ogni modifica fa ripartire il conto. Il modello resta: si cancella solo la compilazione |
| Richieste dal modulo per l'esercizio dei diritti (§3.6) | 1 anno | Cancellazione con la pulizia di ogni notte |
| Segnalazioni di contenuti (§3.6), con l'impronta dell'IP | 5 anni, come dice la procedura di rimozione | Cancellazione con la pulizia di ogni notte |
| Testo di un documento mentre se ne genera il PDF o il pacchetto TeX | **Non conservato.** Il testo arriva al server, viene trasformato e il risultato torna al browser | Né il PDF né il pacchetto vengono salvati: si formano e si cancellano nel corso della stessa richiesta, e sul server non ne resta copia |

**Che cosa fa la cancellazione.** La cancellazione che chiedi tu (Art. 17,
§7), quella di un account senza accessi da 730 giorni e quella decisa
dall'amministratore fanno la stessa cosa, in quest'ordine:

- i file del docente sul server vengono cancellati (contratti di esercizi,
  verifiche e laboratori, mappe e verifiche cifrate, modelli, librerie delle
  mappe, figure in cache, importazioni dai PDF), e con loro i contenuti che
  sul server stanno in chiaro (§3.3: il testo di esercizi, verifiche e
  laboratori, i titoli e gli argomenti, le preferenze di stampa);
- l'account viene disattivato e resta come segnaposto, perché i registri
  continuino a riferirsi a qualcosa: il nome utente viene sostituito, e email,
  nome, cognome, scuola, indirizzo, classe e data di nascita vengono svuotati;
- del verbale di accettazione dei Termini restano la data e la versione
  accettata; indirizzo IP e User-Agent vengono svuotati;
- per ultima, la chiave del docente viene distrutta: se un passo prima non
  riesce, niente di irreversibile è successo, e la cancellazione riparte la
  notte dopo.

Una cancellazione confermata si può annullare finché non comincia; da quel
momento no. Restano, fino al termine dei rispettivi registri (tabella qui
sopra), il nome utente di allora nei registri di audit e i titoli dei
contenuti creati nel registro degli eventi sui contenuti: sono registri che il
database non lascia modificare, ed è la loro garanzia di integrità.

**Le copie non si cancellano subito.** Le copie di sicurezza cifrate (fino a
un anno), i salvataggi giornalieri delle chiavi dei docenti (30 giorni) e le
copie del database fatte prima degli aggiornamenti (le ultime cinque)
contengono i dati com'erano, e con le chiavi i contenuti cifrati si possono
ancora aprire. I dati restano lì fino alla scadenza di quelle copie,
accessibili al solo Titolare. Se una copia viene usata per un ripristino, le
cancellazioni successive alla copia vengono rieseguite.

### 5.1 Quello che passa dal server senza restarci

Generare il PDF di un documento (o il pacchetto TeX da compilare altrove) è
un'operazione che avviene **sul server**: il testo scritto dal docente vi arriva,
viene trasformato in un documento tipografico e il risultato torna al browser.

- Per arrivare al server, il testo attraversa **Cloudflare**, che termina la
  connessione cifrata e quindi lo vede in chiaro nel momento in cui passa: è il
  sub-responsabile dichiarato al §9.1, vale per ogni richiesta al sito e non
  solo per questa, e non conserva i contenuti.
- Il testo **non viene conservato** per questa operazione: quello che resta in
  archivio è la compilazione salvata dal docente (cifrata, §3.3), non una copia
  fatta per il PDF. Anche il risultato — il PDF, o il pacchetto TeX — si forma e
  si cancella nel corso della stessa richiesta: l'unica copia che ne resta è
  quella scaricata dal browser.
- E la compilazione salvata **non resta per sempre**: scaricare il documento fa
  partire i 15 giorni al termine dei quali la bozza viene cancellata dal server
  (§5). È la stessa idea di questo paragrafo, portata fino in fondo: quando il
  documento ce l'hai tu, tenerne una copia qui non serve più a niente.
- La trasformazione la esegue un componente che sta **sulla stessa macchina**
  del sito (unità di sistema `tex-compile`), raggiungibile solo dall'interno:
  **non è un fornitore esterno** e non compare fra i responsabili del
  trattamento (§9.1), perché non c'è nessun trasferimento verso terzi. Il
  componente lavora in una cartella temporanea che cancella a fine lavoro, e nei
  propri registri annota soltanto dati tecnici (quale documento, quanto tempo,
  quanti byte), mai il contenuto.
- Questo vale **anche** per gli Istituti che hanno scelto di non far salvare le
  compilazioni sul server (§3.3): quella scelta riguarda la **conservazione** —
  la bozza resta nel browser del docente — e non impedisce la generazione del
  PDF, che continua a funzionare e per cui il testo transita comunque.

## 6. Minori (Art. 8 GDPR; art. 2-quinquies D.Lgs. 196/2003)

Dal **3 settembre 2026** la piattaforma **non raccoglie dati identificativi di studenti** e non prevede account per minori. Gli studenti consultano i contenuti pubblicati con una credenziale fornita dal docente, eventualmente delimitata per classe e non nominativa: la sessione non è associata a una persona; dell'accesso restano i soli dati tecnici di connessione: l'indirizzo IP resta in chiaro nel log del filtro di sicurezza (30 giorni) e nei contatori del limitatore di frequenza (al massimo circa un giorno), e altrove è un'impronta (§3.4). Il docente crea la credenziale dal proprio pannello e può disattivarla o sostituirla in qualunque momento.

Di conseguenza non si applica il consenso del minore (valido in Italia dai 14 anni) né il consenso genitoriale. La procedura di consenso genitoriale descritta nelle versioni precedenti di questa informativa non è più attiva. Nessuno studente reale si è mai registrato: gli unici account studente esistiti erano account di prova con dati fittizi, cancellati il 3 settembre 2026.

Le funzioni di registrazione studente restano nel software, disattivate in questo scenario: si attivano solo nello Scenario 3, in un'istanza condotta da un Istituto scolastico che ne sia Titolare, con la propria informativa (`informativa-istituto`).

## 7. Diritti dell'interessato (Art. 15-22 GDPR)

Chi ha un account esercita i diritti dalla pagina «I tuoi dati»
(`/privacy/your-data`) o scrivendo al recapito privacy:

| Diritto | Articolo | Come |
|---------|----------|------|
| Accesso e portabilità | Art. 15 e 20 | «Scarica i tuoi dati» nella pagina «I tuoi dati»: un archivio ZIP con file JSON (profilo, consensi, contenuti decifrati, compilazioni dei modelli, pubblicazioni e condivisioni) |
| Rettifica | Art. 16 | L'email da «Il mio account» (`/me/account`), con la password e un link di conferma al nuovo indirizzo; nome e cognome su richiesta a `{{OPERATORE_EMAIL}}` |
| **Cancellazione** | Art. 17 | «Chiedi la cancellazione dell'account» nella pagina «I tuoi dati». Ricevi un'email con un collegamento valido 7 giorni; aprendolo trovi il pulsante per confermare. Dopo la conferma hai 30 giorni per ripensarci e annullare dalla stessa pagina; poi la cancellazione viene eseguita (§5, «Che cosa fa la cancellazione»). Se l'email non può partire la pagina te lo dice, e la richiesta si fa a `{{OPERATORE_EMAIL}}` |
| Limitazione | Art. 18 | Richiesta a `{{OPERATORE_EMAIL}}` |
| Opposizione | Art. 21 | Richiesta a `{{OPERATORE_EMAIL}}` per i trattamenti fondati sull'interesse legittimo (registri di sicurezza) |
| Revoca del consenso | Art. 7 §3 | Oggi la piattaforma non raccoglie consensi facoltativi, e non c'è niente da revocare (§10) |

Le richieste si mandano a `{{OPERATORE_EMAIL}}` dall'indirizzo del tuo account,
alla PEC (§1) o dal modulo `/dpo-contact`. Risposta entro 30 giorni
(prorogabile a 60 con comunicazione motivata in caso di complessità).

**Studenti e genitori.** Chi usa la credenziale di classe (o, per un minore,
un genitore) scrive a `{{OPERATORE_EMAIL}}`, alla PEC o dal modulo `/dpo-contact`:
non serve un nome utente. Se indica da quale indirizzo IP e in quale momento
si è collegato, il Titolare cerca le righe nei registri che lo conservano
(Art. 11 §2). Il docente che ha dato la credenziale può disattivarla in
qualunque momento.

In caso di insoddisfazione: reclamo al Garante per la protezione dei dati personali ([garanteprivacy.it](https://www.garanteprivacy.it)).

## 8. Sicurezza tecnica e organizzativa

Misure implementate (Art. 32 GDPR — vedi anche `wiki/decisions/ADR-006-envelope-encryption.md` e `docs/privacy/dpia.md`):

- **Cifratura a riposo** di una parte dei contenuti (§3.3): AES-256-GCM con una chiave casuale per docente (KEK), avvolta con una chiave derivata con HKDF-SHA256 dalla chiave master. La chiave master sta sul server, perché la piattaforma funzioni.
- **Cifratura in transito**: HTTPS obbligatorio (HSTS 1 anno).
- **Hashing password**: bcrypt cost 12.
- **CSRF**: un gettone legato alla sessione, verificato su ogni richiesta che modifica dati; vale al più due ore e si rinnova all'accesso, all'uscita e al cambio di password o di email.
- **Rate-limiting**: 10/min/IP login (anti-brute-force), 60/min teacher content.
- **Audit log append-only**: il database rifiuta ogni modifica o cancellazione dei registri di audit, e l'applicativo non può rimuovere questa protezione; solo un'utenza tecnica separata cancella le righe scadute. *Limite*: resta possibile a chi ha accesso amministrativo al server.
- **Pseudonimizzazione**: nei registri di audit IP e User-Agent sono impronte, non valori in chiaro (§3.4). Restano dati personali.
- **Cancellazione completa, con la chiave per ultima**: alla cancellazione dell'account si cancellano i contenuti e i file del docente e, per ultima, si distrugge la sua chiave. Nelle copie di sicurezza i dati restano fino alla loro scadenza (§5).
- **CSP rigorosa**: prevenzione XSS + frame injection.
- **Permessi per docente**: ogni docente vede i propri contenuti e quelli che un collega gli ha condiviso (§3.3); la separazione è verificata da prove automatiche.
- **Verifica in due passaggi (2FA)**: obbligatoria per i ruoli amministrativi dal 4 settembre 2026, facoltativa per i docenti; codice generato da un'app di autenticazione oppure inviato via email; codici di backup monouso e limite di tentativi.
- **Avviso di accesso amministrativo**: se l'operatore accede ai tuoi contenuti cifrati nei casi del §11-bis, ricevi un'email nel momento in cui l'evento viene registrato; l'elenco è consultabile da `/me/custody-events`.
- **Integrità dei registri di audit**: impronta giornaliera dei registri, conservata fuori dal server con il backup cifrato e verificabile. Un'alterazione successiva è rilevabile **a una condizione**, ed è giusto dirla: finché sul contenitore del backup non è attivo l'object lock — e al 22 settembre 2026 non lo è — chi amministra il server può riscrivere anche la copia remota, e con essa l'impronta. La misura alza il costo dell'alterazione e la rende visibile a chi confronta; non la rende impossibile.
- **Cambio dell'email con conferma**: da «Il mio account» serve la password attuale; l'email cambia solo aprendo il link mandato al nuovo indirizzo, e al vecchio arriva un avviso. I link di recupero password e di cambio email e i codici del secondo fattore via email si cancellano dopo un anno.
- **Recupero password autonomo**: link monouso valido un'ora, inviato all'indirizzo registrato. Reimpostare la password non disattiva la verifica in due passaggi.

## 9. Sub-processor e trasferimenti

### 9.1 Fornitori che ricevono dati

La colonna «Ruolo» distingue i sub-responsabili (Art. 28), che trattano dati
per conto del Titolare con un accordo sul trattamento (DPA), dai fornitori
elencati per cautela o solo eventuali.

| Fornitore | Servizio | Ruolo | Localizzazione | Trasferimento extra-UE |
|-----------|----------|-------|----------------|------------------------|
| Hetzner Online GmbH | VPS hosting (web server + database + storage), datacenter Norimberga | Sub-responsabile, con DPA | Germania (UE) | NO |
| Cloudflare, Inc. | CDN e sicurezza di bordo. **Termina la connessione TLS in ingresso**: ogni richiesta vi transita in chiaro prima di raggiungere il server | Sub-responsabile, con DPA | Rete globale | Possibile in transito — clausole contrattuali tipo (Capo V) e certificazione EU-US Data Privacy Framework. Non conserva i contenuti |
| Backblaze Inc. | Object storage per backup cifrati (region eu-central-003, Amsterdam) | Sub-responsabile, con DPA | Società USA, storage in UE | I dati sono cifrati **prima** dell'upload: Backblaze non accede al contenuto. SCC 2021/914 + DPF |
| Resend, Inc. | Invio delle email di servizio: conferma di registrazione, recupero password, codice di verifica via email, notifiche. Riceve indirizzo, nome e testo del messaggio, mai contenuti didattici | Sub-responsabile, con DPA | USA | SÌ — clausole contrattuali tipo (Capo V) nel DPA del fornitore |
| Google LLC | Integrazione Google Drive, attiva dal 15 settembre 2026 — **solo su opt-in** del singolo docente (§9.2) | Elencato per cautela: il rapporto è fra il docente e Google (§9.2) | USA | Clausole contrattuali tipo art. 46(2)(c). Nessun dato finché il docente non attiva |
| GitHub, Inc. | Copia dei contenuti nel repository GitHub del docente — **solo su opt-in** del singolo docente (§9.2); nessun docente l'ha attivata (misurato il 21 settembre 2026) | Elencato per cautela, come Google (§9.2) | USA | Come per Google: nessun dato finché il docente non attiva |
| Anthropic PBC / OpenAI, L.L.C. | Modelli di IA per PDF-Import — **solo se la funzione è attivata** con un fornitore cloud | Eventuale: solo con la funzione attiva | USA | Clausole contrattuali tipo art. 46(2)(c). Disattivata di default; con modello locale nessun trasferimento |
| CrowdSec SAS | Reputazione di un singolo indirizzo IP, chiesta dall'amministratore dal pannello del filtro di sicurezza (anche l'indirizzo di uno studente, se compare nel log) | Consultato su richiesta dell'amministratore | Francia (UE) | NO |

Le risposte di CrowdSec si conservano sul server insieme all'indirizzo
interrogato, per non ripetere la domanda: oggi senza un termine applicato.

L'elenco completo, con le basi giuridiche dei trasferimenti, è nel Registro
delle attività di trattamento ex art. 30 (Sezione D), che ne è la **fonte
unica**: in caso di divergenza prevale il Registro.

### 9.2 Servizi che il docente attiva (non sub-responsabili)

Integrazioni che il **docente attiva opzionalmente** e che instaurano una relazione diretta tra docente e fornitore terzo: pantedu invia i contenuti del docente a un account del docente, e il fornitore li riceve come servizio reso al docente:

| Servizio | Ruolo pantedu | Cosa attiva il docente | Privacy applicabile |
|----------|----------------|------------------------|---------------------|
| Google LLC — integrazione Google Drive | Il docente collega il PROPRIO account Google; pantedu copia mappe e verifiche nel SUO Drive (le mappe modificate anche con il giro notturno). Sul server restano il token di aggiornamento cifrato con la chiave del docente, l'ambito concesso, l'email dell'account Google e la data dell'ultima sincronizzazione | clicca «Collega Drive» + autorizza l'ambito `drive.file`, che dà accesso ai soli file creati da pantedu | ToS + Privacy Policy Google direttamente applicabili al rapporto docente-Google |
| GitHub, Inc. — copia in un repository | Il docente indica un PROPRIO repository e un suo token personale, conservato cifrato con la sua chiave; pantedu vi copia i file di mappe e verifiche | configura repository e token dal proprio pannello | Termini e informativa di GitHub applicabili al rapporto docente-GitHub |

Le copie sono **in chiaro** nell'account del docente, fuori dalla cifratura della piattaforma. Chi può vederle (condivisioni del Drive, visibilità del repository) lo decide il docente: se il repository è pubblico, lo è anche il suo contenuto.

Oltre al collegamento ordinario ne esiste un secondo, che nessuna pagina mostra e si raggiunge solo digitandone l'indirizzo: chiede in più la lettura dell'intero Drive (`drive.readonly`). È servito il 15 settembre 2026 a recuperare mappe create fuori dalla piattaforma, e Google lo concede solo dopo una conferma esplicita del docente sulla propria pagina di autorizzazione.

**Conseguenze giuridiche**:

- Nel rapporto così configurato Google **non** agisce come sub-responsabile di
  pantedu ex Art. 28: pantedu non sceglie come né dove Google tratti i dati, e
  non dispone di un account Google centralizzato. Il rapporto si instaura fra
  il docente e Google.
- Per prudenza, e perché la qualificazione può essere letta diversamente,
  Google è **comunque elencato** fra i sub-responsabili nel Registro art. 30
  (Sezione D), con le clausole contrattuali tipo indicate. È la posizione più cauta fra le due, ed è quella
  che prevale in caso di dubbio.
- Trasferimento extra-UE: gestito dal rapporto docente-Google (SCC + DPF di
  Google per i propri utenti UE).
- Il docente può **disconnettere in qualsiasi momento** via `POST /teacher/drive/disconnect`: la piattaforma cancella dal server il token e i dati del collegamento; l'autorizzazione data a Google si revoca dalle impostazioni dell'account Google, e i dati già nel Drive del docente restano nel suo account.
- Lo stesso vale per GitHub: la disconnessione cancella dal server repository e token; i file già copiati restano nel repository del docente.

## 10. Cookie

La piattaforma usa **solo cookie tecnici**, necessari al servizio che hai chiesto, e non mostra un banner di consenso.

- **Sessione** (sempre): il cookie di sessione, che porta lo stato di accesso e la protezione CSRF. Base: Art. 6(1)(b).
- **Portachiavi di classe** (facoltativo: solo se, nell'accesso per la classe, lo studente spunta «Ricorda su questo dispositivo»): cookie `fm_keychain` con i riferimenti firmati alle credenziali di classe scelte, nessun dato personale e nessuna password; dura fino alla scadenza della credenziale (per default il 31 agosto) e si cancella con «Esci da tutte». È un cookie tecnico attivato da una scelta esplicita di chi lo usa, per un servizio che ha richiesto. Base: Art. 6(1)(b).

- **Verifica di sicurezza** (quando il filtro la richiede): cookie `waf_session`, che porta la prova di aver superato il controllo anti-bot. Non è leggibile da JavaScript (`HttpOnly`), viaggia solo su HTTPS, dura un'ora e non serve a riconoscerti fra una visita e l'altra. Contiene l'indirizzo da cui il controllo è stato superato, perché quel permesso non possa essere riusato da un altro. È tecnico: senza, la pagina che hai chiesto non si può servire. Base: Art. 6(1)(f) — sicurezza.

Non sono in uso cookie di analytics né di marketing, e le preferenze di interfaccia restano nella memoria del tuo browser.

Il sito installa nel browser un *service worker*, che conserva copie delle pagine e dei file statici per mostrarli anche senza rete. Non conserva ciò che il server marca come da non conservare, e la copia delle pagine si svuota all'uscita e alla scadenza della sessione. È memoria tecnica, necessaria al servizio.

**diagrams.net nelle pagine con le mappe.** Le mappe si vedono con il visualizzatore di diagrams.net (JGraph Ltd, Regno Unito), che il browser scarica dai loro server: ricevono l'indirizzo IP, come per ogni risorsa web. Il contenuto della mappa non viaggia con la richiesta: sta nel frammento dell'indirizzo, che il browser non invia, o passa al riquadro dalla pagina. Misurato il 15 settembre 2026: non impostano cookie leggibili, non caricano risorse da altri domini e salvano nel browser solo la propria configurazione (`.drawio-config`). L'editor con cui il docente crea e modifica le mappe è servito dalla piattaforma stessa, non da JGraph; il collegamento «Modifica copia» apre app.diagrams.net in un'altra finestra, solo se lo scegli.

**GeoGebra negli editor.** Se, mentre scrivi un esercizio o una verifica, apri GeoGebra, il browser scarica l'applet dai server di GeoGebra GmbH (Austria), che ricevono l'indirizzo IP, come per ogni risorsa web; la figura resta nel tuo browser finché non la salvi qui. Riguarda solo i docenti: le pagine degli studenti non caricano GeoGebra.

**Correzione del 15 settembre 2026.** Fino a quella data un banner chiedeva il consenso ai cookie «funzionali», con la casella già attiva alla prima apertura, e quel consenso non bloccava davvero diagrams.net. Per gli utenti autenticati che sceglievano «Accetta tutti» registrava anche un consenso ad analytics e marketing, categorie che non mostrava: quei consensi sono stati revocati. Le misure di prestazione delle pagine (web-vitals) partivano senza consenso e non venivano mai lette: sono state tolte. Banner e misure non ci sono più.

## 11. Data breach

Vedi `docs/privacy/data_breach_runbook.md`. In caso di violazione:

- notifica al Garante entro 72 ore (Art. 33);
- comunicazione agli interessati se il rischio è elevato (Art. 34);
- distruzione d'emergenza delle chiavi e cambio della chiave master, se servono (la procedura operativa è un documento interno del Titolare, non pubblicato; il modello di custodia della chiave è nella DPIA §4).

Il registro degli incidenti è tenuto in `/admin/data-breach`, accessibile al solo super-amministratore.

## 11-bis. Cooperazione con autorità per recupero dati

La procedura operativa è un documento interno del Titolare, non pubblicato. Casi coperti:

- **Richieste autorità giudiziaria / Garante / forze di polizia**: il
  Titolare verifica entro 72h legittimità (base giuridica + decreto motivato),
  registra l'evento nel log `crypto_custody_events`, ed estrae i dati
  esclusivamente nel perimetro autorizzato. Logging completo della chain of
  custody (`/admin/crypto-status`).
- **Eredi** del docente (Art. 460 c.c. + considerando 27 GDPR): documentazione
  successoria + estrazione mirata.
- **Docente che ha perso accesso** (Art. 15 GDPR): self-service via
  `/dpo-contact` + verifica identità → re-issue Recovery Key o estrazione
  amministrativa.

Tutte le operazioni di accesso amministrativo alle KEK docenti producono
righe immutabili in `crypto_custody_events` (kind: `kek_emergency_access`,
`data_recovered`, `data_provided`). Per il recupero e l'accesso amministrativo
l'interessato riceve un avviso automatico via email; per le richieste
dell'autorità l'avviso segue la valutazione del caso, perché un provvedimento
può vietarlo.

## 11-ter. Sviluppo del software e trasparenza

L'Applicativo è **software libero/open source** rilasciato sotto licenza
**EUPL-1.2**: il codice dell'applicazione — incluse le logiche automatiche
(es. il filtro di sicurezza WAF) — è **ispezionabile** pubblicamente su
[github.com/vittop89/pantedu](https://github.com/vittop89/pantedu), senza
necessità di richiesta o registrazione. Dalla copia pubblica restano esclusi
alcuni documenti operativi (procedure interne, custodia delle chiavi, strumenti
di accesso al server, rapporti di audit), e alcuni nomi reali vi sono
sostituiti da segnaposto.

Il codice sorgente è stato **scritto dai modelli di intelligenza artificiale
Claude di Anthropic (famiglie Opus e Fable) sotto
la guida e la direzione di {{OPERATORE_NOME}}**, che ne ha curato ideazione, requisiti, revisione e responsabilità
(co-autorialità uomo–AI). L'informazione è fornita a fini di **trasparenza**; la
titolarità dei diritti d'autore del software è della persona fisica che lo ha
diretto (un sistema di IA non può detenerli).

L'Applicativo **non effettua processi decisionali automatizzati che producano
effetti giuridici o significativi sull'interessato** ai sensi dell'**Art. 22
GDPR**: in particolare **non assegna valutazioni, non corregge elaborati e non
profila gli studenti**.

## 11-quater. Uso di sistemi di intelligenza artificiale

L'Applicativo include una funzione — **PDF-Import** — che invia pagine di libri
di testo a un modello di intelligenza artificiale per ricavarne esercizi. La
funzione è **riservata ai docenti**, è **disattivata per impostazione
predefinita** e gli studenti non vi hanno alcun accesso.

Il modello svolge due tipi di operazione:

- **trascrizione** di ciò che è stampato sulla pagina (testo, numerazione,
  livello di difficoltà);
- **generazione** di contenuto nuovo (soluzioni non stampate sul libro,
  argomento dell'esercizio, traduzioni).

Il contenuto **generato** è marcato come tale, in forma leggibile da macchina e
in forma visibile, in attuazione dell'**art. 50(2) del Regolamento (UE)
2024/1689 (AI Act)**. Nessun contenuto generato raggiunge gli studenti senza
essere prima stato rivisto da un docente.

**Nessun dato personale degli studenti viene inviato ai modelli**: la funzione
opera su libri di testo. Prima di ogni invio, codici fiscali e indirizzi email
eventualmente presenti vengono automaticamente redatti.

Quando è configurato un fornitore cloud, l'invio comporta un trasferimento
verso un Paese terzo: fornitori, basi giuridiche e alternativa senza
trasferimenti sono al §9.1 di questa informativa e nel Registro art. 30
(voce B.9).

L'inquadramento normativo completo — ruolo, classificazione del rischio e
misure — è in [assessment AI Act](/legal/ai-act). Ai sensi
dell'art. 5 dello stesso Regolamento si precisa che l'Applicativo **non
inferisce emozioni** di studenti o docenti, non effettua riconoscimento
biometrico e non attribuisce punteggi sociali.

Le altre funzioni automatiche presenti (filtro di sicurezza WAF) sono basate su
regole deterministiche scritte da persone e non costituiscono sistemi di
intelligenza artificiale ai sensi dell'art. 3(1) del Regolamento.

## 12. Modifiche all'informativa

Questa informativa ha versione **2.17** (25 settembre 2026).

| Versione | Data | Che cosa è cambiato |
|---|---|---|
| **2.17** | 25 set 2026 | Detto in testa che su pantedu.eu vale dall'apertura delle iscrizioni ai colleghi; fino ad allora vale l'informativa per l'uso personale. **Corrette affermazioni che il codice smentiva.** Non tutti i contenuti sono cifrati: il testo di esercizi, verifiche e laboratori, i titoli e le preferenze di stampa, con i contatori DSA/DIS, stanno in chiaro (§3.2, §3.3); la chiave del docente è casuale, non derivata dalla master, e la master sta sul server (§8). L'impronta dell'IP era uno SHA-256 senza chiave, che per un IPv4 si ricostruisce per tentativi: dal 24 settembre ha una chiave, e resta dato personale (§3.4). La pulizia dei contatori del limitatore, dichiarata dal 4 settembre, non girava fino al 24; le statistiche di navigazione tenevano i nomi utente (§3.4). Le pulizie mensili dei registri e delle domande di iscrizione diventano giornaliere: prima un IP poteva restare nel log del filtro fino a circa due mesi (§5). Le sessioni non contengono l'IP. La cancellazione lasciava in chiaro i contenuti non cifrati e parte dell'account, e l'anonimizzazione a 730 giorni non distruggeva la chiave: ora fanno la stessa cosa, con la chiave distrutta per ultima, e le copie di sicurezza conservano i dati, con le chiavi, fino alla loro scadenza, mentre prima si diceva che la cifratura li rendesse illeggibili (§5). Un account inattivo riceve tre avvisi via email prima della cancellazione (§5). Richieste, segnalazioni, consensi, verbale dei Termini e richieste di cancellazione hanno ora un termine applicato; le segnalazioni tengono l'impronta dell'IP, non l'indirizzo, e i tentativi di accesso falliti si tengono un giorno (§3.4, §3.6, §5). La cancellazione si chiede dalla pagina «I tuoi dati», e l'email di conferma, che prima non partiva, ora parte (§7). Il gettone CSRF è descritto com'è (§8). L'editor delle mappe è servito dalla piattaforma, non da JGraph (§10). **Aggiunti**: la condivisione con i colleghi (§3.3); gli altri archivi con dati in chiaro (§3.4, §3.6, §5); richieste e segnalazioni (§3.6, §5); i diritti di chi usa la credenziale di classe (§7); CrowdSec (§9.1); il secondo collegamento a Google Drive (§9.2); GeoGebra e la copia delle pagine nel browser (§10). Tolti i rimandi a documenti interni non pubblicati e l'inciso sui commit del repository pubblico, che ne ha uno solo |
| **2.16** | 22 set 2026 | **La scuola diventa facoltativa** (ADR-047). Indirizzo e classe dicono che cosa insegni; la scuola dice dove lavori, ed è un dato di natura diversa: identifica il posto di lavoro di una persona. Da oggi puoi iscriverti senza indicarne nessuna, e il modulo ti dice che cosa resta spento (catalogo, fonti, libri in adozione, credenziali di classe, incarichi, pubblicazione in rete, condivisione con i colleghi) e che cosa continua a funzionare. La aggiungi e la togli quando vuoi dal profilo. Nelle installazioni adottate da un Istituto resta obbligatoria, perché lì senza non si potrebbe fare nulla. **Un dato in meno, non uno in più** |
| **2.15** | 22 set 2026 | **§12 diceva due cose che non succedono.** La procedura per le revisioni sostanziali prometteva un «avviso in pagina al login» e una «richiesta di nuova conferma dei consensi». Il primo è un banner costruito per i Termini e per l'AUP, dove è un impegno contrattuale, e non per questa informativa; il secondo non ha materia, perché qui non si chiedono consensi facoltativi e i consensi attivi sono zero. Ora §12 dice quello che si fa: versione alzata, tabella delle revisioni, email ai docenti registrati. **Nessuna garanzia tolta a nessuno**: una misura dichiarata e non applicata non protegge, e l'art. 5 §2 chiede di poter dimostrare quello che si scrive |
| **2.14** | 22 set 2026 | Le bozze di compilazione dei modelli **non restano più per sempre**: si cancellano 15 giorni dopo che hai scaricato il documento, e comunque il 31 agosto se restano ferme dal 1° giugno. Ogni modifica fa ripartire il conto e ricevi un avviso con almeno sette giorni di anticipo; si cancella la tua compilazione, non il modello (§3.3, §5, §5.1). **È una misura in più, non un dato in più**: nessuna nuova raccolta, nessun nuovo destinatario. Corretta anche la riga che diceva che i tempi del §5 li applica un solo lavoro automatico: sono tre, ciascuno con il proprio elenco |
| **2.13** | 22 set 2026 | §3.1: detto per esteso che istituto, indirizzo e classe **li dichiara il docente** — la scuola non li fornisce, non li conferma e non li può correggere, e non sono quindi l'organizzazione ufficiale delle sue classi. Lo ricordano anche il modulo di iscrizione e il profilo, nei soli Scenari 1 e 2. Nessun dato nuovo, nessun destinatario nuovo. *(Sempre il 22 settembre, a sera: questa sezione era un blocco unico di circa ottocento parole che raccontava per esteso ogni revisione, correzioni comprese, ed è diventata questa tabella — l'art. 12 §1 chiede forma concisa e intelligibile. Nessuna revisione è sparita: di ciascuna resta che cosa è cambiato, non il racconto di come era scritta prima. Modifica di sola forma, e per questo la versione non è stata alzata.)* |
| **2.12** | 22 set 2026 | L'integrità dei registri è rilevabile **a una condizione**, e la condizione — l'object lock sul contenitore del backup — non è attiva: la frase precedente era più forte del vero. L'impostazione che non salva le compilazioni dei modelli istituzionali è una configurazione decisa dal Titolare, non una richiesta dell'Istituto |
| **2.11** | 22 set 2026 | Corrette due affermazioni che il codice smentiva. «No fingerprinting»: la verifica anti-bot leggeva l'impronta del canvas, il modello della scheda video e l'impronta audio — valori che identificano un dispositivo — senza che nessuna regola li usasse; dal 22 settembre non vengono più raccolti. E i cookie tecnici erano tre, non due: `waf_session` non era dichiarato, ora è al §10. Aggiunto al §5.1 il transito da Cloudflare |
| **2.10** | 15 set 2026 | Anche l'anno di corso, come la sezione, si usa solo con l'incarico dell'amministratore. *(21 settembre: aggiunto il §5.1 sul testo che passa dal server per generare il PDF, e la riga nella tabella delle conservazioni — chiarimento di una funzione già presente, versione non alzata.)* Non sostanziale |
| **2.9** | 15 set 2026 | Il cambio dell'email richiede la password e un link di conferma al nuovo indirizzo, con avviso al vecchio; i link monouso e i codici via email si cancellano dopo un anno. Non sostanziale |
| **2.8** | 15 set 2026 | Tolto il banner dei cookie, che aveva la casella preselezionata e registrava consensi mai mostrati; tolte le misure di prestazione web-vitals, che partivano senza consenso; dichiarato diagrams.net nelle pagine con le mappe; §10 riscritto. Non sostanziale per chi usa il servizio |
| **2.7** | 15 set 2026 | Descritte tre funzioni già presenti che l'informativa non diceva: i contenuti di un solo docente visibili senza accesso, le sezioni delle classi solo con l'incarico, la copia dei contenuti in un repository GitHub del docente. Google Drive indicato come attivo; le mappe sono file cifrati, non collegamenti. Non sostanziale |
| **2.6** | 14 set 2026 | Tolta dalla tabella delle conservazioni la riga sulle chiavi di classe: descriveva una copia cifrata dei contenuti per ogni classe, prevista e mai attivata. Nessun dato è mai stato conservato così. Non sostanziale |
| **2.5** | 6 set 2026 | Dichiarato in testa che l'informativa vale per lo Scenario 2 e che gli Scenari 1 e 3 ne hanno una propria; aggiunti fra gli interessati gli studenti con credenziale di classe. Non sostanziale |
| **2.4** | 5 set 2026 | Aggiunto, fra i cookie, quello facoltativo del portachiavi di classe «Ricorda su questo dispositivo» |
| **2.3** | 4 set 2026 | **Rimossi gli account studente e il consenso genitoriale.** IP e User-Agent ridotti a impronta nei quattro registri che li conservavano in chiaro, e dichiarati i registri di sicurezza che restano in chiaro; corretta la conservazione dei backup; aggiunto Resend fra i sub-responsabili; conservazioni riviste; avviso automatico degli accessi amministrativi; secondo fattore obbligatorio per gli amministratori |

**Ad ogni revisione sostanziale**: si alza la versione e la si descrive nella
tabella qui sopra. Il testo integrale di ogni versione resta nell'archivio del
Titolare ed è fornito su richiesta. Ai docenti registrati la revisione è
comunicata **per email**.

## 13. Domande, contatti, reclami

| Tipo richiesta | Contatto |
|----------------|----------|
| Generiche / supporto | `{{OPERATORE_EMAIL}}` |
| Privacy ed esercizio dei diritti | `{{OPERATORE_EMAIL}}` (nell'oggetto «GDPR» e, se ne hai uno, il tuo nome utente), la PEC (§1) o il modulo `/dpo-contact` |
| Reclamo al Garante | [garanteprivacy.it/home/footer/contatti](https://www.garanteprivacy.it/home/footer/contatti) |

## Riferimenti tecnici (per audit professionali)

- `docs/privacy/dpia.md` — Valutazione d'Impatto Privacy
- `docs/privacy/registro-trattamenti.md` — Registro Art. 30
- `docs/privacy/data_breach_runbook.md` — Procedura data breach
- `tools/audit/tabelle_da_purgare.php` e `app/Config/retention.php` — Termini di conservazione applicati dai lavori pianificati
- `wiki/decisions/ADR-006-envelope-encryption.md` — Design crypto
- `wiki/decisions/ADR-007-gdpr-compliance.md` — Design GDPR
