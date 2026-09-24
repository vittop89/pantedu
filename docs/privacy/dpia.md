---
tags:
    - documentazione/gdpr
    - phase/25.C9
    - sicurezza
date: 2026-09-25
tipo: dpia
status: adottata
versione: 1.23
classification: ⚠️ INTERNAL
aliases: ["dpia", "valutazione-impatto"]
---

# DPIA — Valutazione d'Impatto Privacy

**Versione:** 1.23
**Data:** 25 settembre 2026
**Stato:** adottata dal titolare il 25 settembre 2026

> **Art. 35 GDPR**: la DPIA è obbligatoria quando un trattamento può presentare
> un rischio elevato per i diritti e le libertà delle persone fisiche. Il
> Garante la richiede, fra gli altri, per i «trattamenti non occasionali di dati
> relativi a soggetti vulnerabili (minori, […])» (provvedimento n. 467
> dell'11 ottobre 2018, allegato 1, n. 6; considerando 75; linee guida WP248
> rev.01).
>
> **Dal 3 settembre 2026 Pantedu non tratta dati identificativi di studenti**
> (vedi §1, «Perché non ci sono dati di studenti»).
>
> **Perché questa DPIA è dovuta.** Gli
> studenti che entrano con la credenziale di classe sono per lo più minorenni,
> e di loro si trattano i dati tecnici della connessione — indirizzo IP, che è
> dato personale anche quando dinamico (CGUE C-582/14, *Breyer*) — non
> occasionalmente ma a ogni lezione.
>
> È il caso del punto 6 dell'elenco del Garante; dei criteri del WP248 ne
> ricorrono due, interessati vulnerabili e registrazione sistematica delle
> richieste nel registro di sicurezza. **La DPIA è quindi dovuta**, ed è redatta
> e mantenuta dalla prima stesura del 27 aprile 2026 (Art. 24 e 35). I rischi
> residui sono medi o bassi (§3): la consultazione preventiva del Garante
> (Art. 36) non è richiesta.
>
> ## NOTA su BES/DSA — NON dato sanitario Art. 9 in Pantedu
>
> Verificato sul codebase 2026-04-27: l'app NON traccia "studente X ha
> DSA". Il modello effettivo è:
>
> 1. **Metadata di contenuto** (`<input id="DSA" type="checkbox">` su
>    `infoVer`, `dsa-checkbox` su `<li>` di esercizi): il docente segna
>    che un esercizio/sezione ha versione adattata DSA. NON è un
>    identificativo studente.
> 2. **Contatori numerici** per stampa (`nPrintDSA`, `nPrintDIS`): il
>    docente specifica "stampami 3 copie DSA, 1 DIS". Numeri aggregati,
>    no PII.
> 3. **Nome studente** sull'eventuale copia stampata. Il pannello delle
>    verifiche ha un interruttore «verifica nominativa» che scopre due campi
>    per nome e cognome dell'alunno: servono a **stamparlo sul foglio**, e dal
>    22 settembre 2026 il server **non li salva** — restano nel browser e si
>    svuotano quando il docente richiude l'interruttore. Fino a quella data li
>    salvava, in chiaro, in `print_info_data`: questo riquadro dichiarava «non
>    registrato in DB» e non era esatto. Misurato in produzione prima di
>    correggere: tre righe in tutto, **zero** con quei campi valorizzati —
>    nessun nome è mai stato salvato, quindi nessuna violazione da valutare ex
>    art. 33, e niente da cancellare. Il divieto dei Termini §2.4 resta, e
>    adesso il software lo sostiene invece di contraddirlo.
>
> I dati sanitari veri (PEI/PDP, certificazioni mediche) sono gestiti dalla
> scuola tramite registro elettronico esterno + cartaceo. Pantedu non
> riceve né elabora questi dati. **Trattamento Art. 9 NON applicabile.**

## 1. Descrizione sistematica del trattamento (Art. 35 §7 a)

### Titolare del trattamento — modello di titolarità (aggiornato 2026-09-03)
- **Dati dei docenti** (l'autore e i colleghi di qualunque scuola che si iscrivono): **Titolare è {{OPERATORE_NOME}}** (Art. 4(7)). L'iscrizione è volontaria e non disposta da un Istituto, i dati sono conferiti dall'interessato, finalità e mezzi li determina l'autore. Basi: Art. 6(1)(b) per l'account, Art. 6(1)(f) per i registri di sicurezza. La piattaforma **non è uno strumento d'Istituto**; la titolarità di un Istituto sui dati dei propri docenti attiene alla gestione del personale e resta distinta.
- **Dati di studenti**: **nessun dato identificativo**, e nessuno di studenti reali in passato: gli account studente esistiti erano account di prova creati dall'autore, ora cancellati; la registrazione degli studenti è disattivata dal 3 settembre 2026. Delle sessioni con credenziale di classe restano i dati tecnici di connessione: l'IP in chiaro nei registri di sicurezza a conservazione breve, altrove come impronta (§2). Se studenti reali si fossero registrati, di quei dati sarebbe stato Titolare l'**Istituto**, non l'autore, che li avrebbe trattati per conto dell'Istituto senza accordo ex Art. 28. Le versioni precedenti di questa DPIA indicavano l'autore come Titolare: era un errore di impianto.
- **Adozione formale da parte di un Istituto** (con dati di studenti): possibile solo in un'istanza condotta dall'Istituto o da un fornitore qualificato ACN, mai su infrastruttura dell'autore (vedi sotto). In tal caso il Titolare è l'Istituto e la DPIA relativa è a suo carico. La bozza di DPA che prevedeva l'autore come Responsabile è ritirata.

### Perché non ci sono dati di studenti (2026-09-03)
Il Regolamento cloud ACN (Decreto direttoriale n. 21007/24 del 27 giugno 2024, applicabile dal 1° agosto 2024, attuativo dell'art. 33-septies del D.L. 179/2012) consente alle pubbliche amministrazioni, scuole comprese, di avvalersi soltanto di infrastrutture e servizi cloud **qualificati**, e pone la qualificazione a carico del fornitore (art. 17). Un docente che conduca personalmente un server non può qualificarsi. Dati di cui l'Istituto è Titolare non possono quindi risiedere su questa infrastruttura, a qualunque titolo l'autore li trattasse. Il presupposto è stato rimosso prima che si concretizzasse: registrazione degli studenti disattivata; account di prova cancellati; tabelle degli studenti e `parent_consents` vuote. Nessuna copia di sicurezza ha mai contenuto dati di studenti reali (vedi R10). Viene meno anche il tema dell'età e del consenso dei minori (Art. 8 GDPR; art. 2-quinquies del Codice).

### Ambito di accesso degli studenti (aggiornato 2026-09-03)
Gli studenti consultano i contenuti che il docente pubblica per la classe — mappe, esercizi propri e, per gli esercizi tratti da libri protetti da diritto d'autore, il **solo riferimento bibliografico** (badge + fonte) con l'eventuale svolgimento del docente, **mai** traccia/soluzioni del libro — con una **credenziale del docente**: grant tecnico legato all'`id` del docente (`fm_teacher_access`), delimitabile per indirizzo e classe, **non nominativo**. La sessione non è associata a una persona; nel registro delle operazioni compare come anonima; l'IP resta in chiaro nei soli registri di sicurezza a conservazione breve (§2), altrove come impronta. Nessuna creazione/modifica di contenuti da parte dello studente. La credenziale la crea il docente nel proprio pannello (etichetta composta dal sistema — classe, indirizzo, sigle delle materie — con un'aggiunta breve facoltativa; password conservata come hash; ambito facoltativo per indirizzo e classe), la comunica in classe e può disattivarla o sostituirla in qualunque momento. Dal 19 settembre 2026 l'etichetta non è più testo libero (ADR-044): nella parte composta dal sistema non può finire un nome, e per l'aggiunta (al massimo dodici caratteri) e per l'username il modulo invita a non usare nomi di persone.

Le modalità di registrazione studente presenti nel software — **Completa** (con data di nascita e consenso genitoriale per i minori di 14 anni) e **Ridotta** — sono **disattivate e non attivabili su pantedu.eu**. Restano nel codice per l'ipotesi di un'istanza condotta da un Istituto Titolare, che in quel caso ne sarebbe responsabile con propria DPIA. Il software distingue tre scenari di esercizio (ADR-032: uso personale, colleghi, Istituto), commutabili dal pannello di amministrazione con motivazione a registro; lo scenario Istituto è attivabile solo su un'istanza che dichiari in configurazione l'infrastruttura qualificata ACN. **Verificato il 22/9/2026, e non è una dichiarazione: è un rifiuto.** `DeploymentScenario::activationBlocker()` interroga `instanceAcnQualified()` e respinge il passaggio con `institute_requires_acn_instance`; su questa istanza quel valore è `false`, quindi lo scenario Istituto **non si può attivare qui**, nemmeno volendo. Servono inoltre una motivazione di almeno dieci caratteri, i dati identificativi dell'Istituto e una presa d'atto esplicita sul Regolamento cloud ACN. Ne discende un limite utile da dire: un documento che chiedesse nome, cognome e condizione BES/DSA di singoli alunni — dato sanitario di minori ex art. 9, di cui sarebbe titolare l'Istituto — non sarebbe lecito qui, e la piattaforma non consente di mettersi nella configurazione in cui lo sarebbe. Negli scenari 1 e 2, se un modello simile venisse creato, `CompilationScrubber` svuota i campi e le righe delle tabelle che per nome si riferiscono a una persona; resta scoperto il campo nominato in modo neutro e il testo libero, che è R20, e in quello scenario `/privacy/informativa` serve un'informativa distinta con l'Istituto come Titolare.

**Delimitazione**: indirizzo e classe del docente delimitano a quali credenziali sono
visibili i contenuti pubblicati; la scuola, **dove il docente ne ha dichiarata
una**, delimita anche quello.

**La scuola è facoltativa** (ADR-047, 22/9/2026), fuori dallo scenario 3. Non
è un dettaglio di forma: dice *dove lavora* l'interessato, non che cosa
insegna, ed è l'unico dato dell'account che lo leghi a un'istituzione. Un
docente può quindi usare la piattaforma senza mai nominare un Istituto — e in
quel caso l'Istituto sparisce del tutto dal trattamento. Chi non la indica
perde le funzioni che passano per la scuola (catalogo, fonti, adozioni,
credenziali di classe, incarichi, pubblicazione in rete e condivisione fra
colleghi): l'elenco sta in `App\Services\SenzaScuola`, in un posto solo, e lo
legge anche il modulo d'iscrizione. Nello scenario 3 resta obbligatoria, perché
lì un account senza scuola non potrebbe fare nulla.

### Contenuti visibili senza accesso (2026-09-15)
Oltre che con la credenziale di classe, i contenuti di **un solo docente** per installazione possono essere visibili a chiunque, senza accesso. Il docente lo sceglie l'amministratore, con motivazione a registro (`users.pubblica_in_rete`: un indice unico ne ammette uno, migration 129). Sono visibili solo i suoi contenuti pubblicati, e solo nelle sezioni della barra laterale (per esempio «Mappe concettuali») che l'amministratore rende pubbliche, per tutte le sue classi, con o senza sezione: chi visita le sceglie con i selettori di indirizzo e classe. Senza una scelta non è visibile niente: la barra dei visitatori mostra solo l'accesso. Su pantedu.eu il docente scelto è il Titolare, e per sua decisione del 15 settembre 2026 i contenuti di altri docenti non si aprono al pubblico. Dei visitatori restano i soli dati tecnici di connessione (§2).

### Le sezioni delle classi (2026-09-14, ADR-041)
L'elenco delle classi di una scuola non è un dato personale: arriva dal dataset pubblico delle adozioni. Il legame «il docente X insegna in 2A» lo è, e messo insieme per più docenti descrive l'organizzazione delle classi dell'Istituto. Per non raccoglierlo dove non serve (Art. 5 §1 c), l'amministratore sceglie per ogni istituto chi può usare le classi: tutti i docenti, solo quelli che ne hanno l'incarico (`teacher_sections`), oppure nessuno le sezioni e tutti gli anni. Su pantedu.eu vale **«solo incaricati»** per tutti gli istituti: un docente usa una classe — l'anno di corso di un indirizzo («2» dello scientifico vale per tutte le sue seconde) o una sezione — solo con l'incarico dell'amministratore. Fino al 15 settembre 2026 gli anni erano liberi per tutti (ADR-043): il docente vedeva gli anni di ogni corso anche dove non insegnava. L'incarico sull'anno è meno dettagliato di quello sulla sezione, e resta l'unico legame che l'amministratore registra. Chi si iscrive parte senza incarichi. Le spunte su classi che la modalità non ammette vengono sospese (`curriculum_teacher.sospesa_dalla_scuola`), non usate; i materiali restano dove sono.

### Collegamenti a servizi del docente (2026-09-15)
Su scelta del singolo docente, la piattaforma copia i suoi contenuti in un servizio del docente stesso. Le copie sono **in chiaro** nell'account del docente, fuori dalla cifratura at-rest della piattaforma (R21).
- **Google Drive** (ADR-038), attivo su pantedu.eu dal 15 settembre 2026. Il docente collega il proprio account Google con l'ambito `drive.file`, che dà accesso ai soli file creati dalla piattaforma. Nel suo Drive finiscono le mappe (anche con il giro notturno, per quelle modificate) e, a sua richiesta, le verifiche. Sul server restano il token di aggiornamento, cifrato con la chiave del docente, l'ambito concesso, l'indirizzo email dell'account Google, la cartella radice e la data dell'ultima sincronizzazione (`teacher_drive_oauth`). La disconnessione cancella la riga; i file restano nel Drive del docente.
- **GitHub**: disponibile, non in uso (nessun docente l'ha configurato, misurato il 21 settembre 2026). Il docente indica un proprio repository e un token personale, conservato cifrato con la sua chiave (`teacher_github_sync`); la piattaforma vi copia i file delle sue mappe e verifiche.

Il rapporto sulle copie si instaura fra il docente e il fornitore (Informativa §9.2); il Registro art. 30 elenca comunque Google fra i sub-responsabili, come posizione più cauta.

### Chi riceve qualcosa quando si apre una mappa (2026-09-22)
Le pagine con le mappe caricano il visualizzatore di **JGraph Ltd**
(diagrams.net, Regno Unito) dai suoi server: il browser di chi apre la pagina vi
si collega, e JGraph ne riceve l'**indirizzo IP**, come per qualunque risorsa
caricata da un altro dominio. Vale anche per gli studenti che entrano con la
credenziale di classe. L'editor con cui il docente modifica le mappe è ospitato
dalla piattaforma dal 15 settembre 2026; l'editor di JGraph si apre, in una
nuova finestra, solo se chi legge preme «Modifica copia su drawio.com».

Il contenuto della mappa **non** viaggia con la richiesta: sta nel frammento
dell'indirizzo, che il browser non invia, o passa al riquadro dalla pagina.
Misurato il 15 settembre 2026: non imposta cookie leggibili, non carica risorse
da altri domini, e nel browser salva solo la propria configurazione.

Il Regno Unito è coperto dalla decisione di adeguatezza della Commissione del 19
dicembre 2025, che rinnova quella del 28 giugno 2021 e vale fino al 27 dicembre
2031, quindi il trasferimento non richiede garanzie ulteriori. JGraph non
tratta dati per conto della piattaforma e non è sub-responsabile (Registro,
Sezione D): il rapporto è quello fra il browser di chi visita e un fornitore di
risorse web. Era dichiarato nell'informativa §10 e non in questa valutazione.

### Finalità del trattamento
1. **Didattica**: docenti creano contenuti (esercizi, verifiche, mappe, laboratori, risdoc) e li pubblicano alle proprie classi, che li consultano con la credenziale del docente. Possono condividerli con colleghi dello stesso istituto, su scelta esplicita e mai per materiale derivato da libri di testo (blocco nel software, ToS §2.1.1); la condivisione fra colleghi è distinta dalla pubblicazione agli studenti.
2. **Versioni adattate**: i docenti possono segnare che un esercizio ha varianti per copie DSA/DIS — è un **metadata di contenuto**, non un identificativo dello studente. NON costituisce trattamento dato sanitario Art. 9 (vedi NOTA sopra).
3. **Audit operativo**: log accessi privilegiati per rilevamento abusi (Art. 32 sicurezza).
4. **Minimizzazione**: dati raccolti = solo quelli necessari per le finalità sopra.

### Categorie di dati e interessati

| Categoria | Tipologia | Base giuridica | Periodo conservazione |
|-----------|-----------|----------------|----------------------|
| Identificazione docente (username, nome, cognome, email) | Dato comune | Art. 6(1)(b) — esecuzione contratto registrazione | 730 giorni di inattività → la stessa cancellazione dell'art. 17, dopo tre avvisi via email a 60, 30 e 7 giorni; mai senza l'ultimo avviso partito da almeno 7 giorni (R8; `app/Config/retention.php`, `AvvisiDiInattivita`) |
| Docente — indirizzo e classe, e incarichi sulle classi (anni e sezioni). La **scuola è facoltativa** fuori dallo scenario 3 (ADR-047): non è necessaria all'erogazione, serve solo alle funzioni che passano per la scuola | Dato comune | Art. 6(1)(b) — erogazione servizio + delimitazione della visibilità | 730 giorni di inattività → la stessa cancellazione dell'art. 17 (R8) |
| Docente — collegamento a Google Drive o GitHub (token cifrati, email dell'account Google, repository) | Dato comune | Art. 6(1)(b) — funzione scelta dal docente | Fino alla disconnessione o alla cancellazione dell'account |
| Studenti (username, nome, cognome, email, data di nascita), genitori (email, nome) | — | **Nessun trattamento**: registrazione disattivata dal 2026-09-03; in passato solo account di prova creati dall'autore, cancellati | — |
| Flag DSA/DIS su esercizi (metadata contenuto) | Dato comune (proprietà intellettuale del docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account docente. **In chiaro** nei dati dell'esercizio e nelle preferenze di stampa (`print_info_data`), protetto dai controlli di accesso |
| Contatori numerici copie stampa (nPrintDSA, nPrintDIS) | Aggregato non identificativo | Art. 6(1)(b) | Vita ciclo verifica |
| Indirizzo IP e User-Agent | Quasi-identificativo: come impronta è un pseudonimo, e resta dato personale | Art. 6(1)(f) — interesse legittimo (sicurezza) | **Come impronta**, nei registri di audit: 2 anni (audit_activity_log), 5 anni (privileged_access_log, content_action_log, teacher_recovery_audit), 10 anni (consent_audit). **In chiaro**: 30 giorni (waf_logs), fino alla pulizia del giorno dopo (rate_limits), ultime mille voci, circa una settimana (access_log.json); gli altri punti in §2. Termini completi: informativa §5 |
| Contenuti didattici: testo di esercizi, verifiche e laboratori (con le versioni precedenti), titoli e argomenti | Dato comune (proprietà intellettuale docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account. **In chiaro** sul server, protetto dai controlli di accesso |
| Contenuti didattici: file delle mappe, file TeX e PDF delle verifiche salvate | Dato comune (proprietà intellettuale docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account, cifrati con la chiave del docente (Phase 25.D) |
| Compilazioni dei modelli risdoc (piano annuale, relazione finale, schede) | Dato comune; **per natura il contenuto più esposto**, perché sono atti che parlano di alunni | Art. 6(1)(b) — esecuzione contratto | **15 giorni** dall'ultima fra scaricamento e modifica, e comunque il **31 agosto** se ferme dal 1° giugno (ADR-046, 2026-09-22). Prima: vita ciclo account. Cifrate at-rest con la chiave del docente (migration 101) |
| Registri di audit: chi ha fatto che cosa, e quando | Dato comune | Art. 6(1)(f) | 2 anni (audit_activity_log); 5 anni (privileged_access_log, crypto_access_log, content_action_log, teacher_recovery_audit) |

### Categorie di interessati
- **Docenti**: maggiorenni, professionisti, di qualunque scuola.
- **Studenti**: **nessun dato identificativo** dal 2026-09-03. Le sessioni con credenziale del docente non sono associate a una persona; ne restano i dati tecnici di connessione.
- **Genitori**: nessuno dal 2026-09-03 (nessun consenso genitoriale raccolto).
- **Super-admin tecnici**: maggiorenni, accesso operativo log + KMS recovery.

### Architettura tecnica del trattamento

```
Browser (HTTPS)
    │
    ▼
Cloudflare (edge, USA: termina il TLS e vede in chiaro ogni richiesta; R13)
    │
    ▼
nginx dell'host (HSTS) → contenitore: nginx + CSP via SecurityHeadersMiddleware
    │
    ▼
PHP 8.4 (FPM) + Dotenv + Config
    │
    ▼ Session cookie (SameSite=Lax, secure)
    │
Auth Middleware → CSRF Middleware → Rate-Limit Middleware → Audit Middleware
    │
    ▼
Controllers + Services
    │
    ├── PDO MariaDB 11.x (utf8mb4)
    │       │
    │       ├── teacher_content_data (vista teacher_content; testo e metadati in chiaro;
    │       │   i file di mappe e verifiche, cifrati per docente, stanno nello storage degli oggetti)
    │       ├── teacher_keys (wrapped_kek per docente)
    │       ├── consents + consent_audit (Phase 25.C1)
    │       ├── deletion_requests (Phase 25.C4)
    │       ├── parent_consents (Phase 25.C7 — vuota dal 2026-09-03)
    │       ├── content_publications (dove è pubblicato un contenuto; ADR-037)
    │       └── teacher_drive_oauth, teacher_github_sync (token cifrati con la KEK del docente)
    │
    ├── KMS_MASTER_KEY (.env.local, fuori dal repository; cambiata il 2026-09-24)
    │       └── 3 copie: 1 fuori linea + 2 contenitori cifrati (§4, custodia)
    │
    └── storage/logs (ruotati per dimensione: 5 MB, cinque copie)
```

## 2. Necessità + proporzionalità (Art. 35 §7 b)

### Necessità

Ogni dato raccolto ha finalità motivata documentata:

- **Username/email**: necessari per autenticazione (Art. 6(1)(b)).
- **Flag DSA/DIS su esercizi**: metadata di contenuto del docente — permette di mantenere varianti adattate dello stesso esercizio (es. con formula esplicita per copia DSA). NON è dato dello studente. Art. 6(1)(b) sufficiente.
- **IP/UA log**: necessari per rilevare accessi sospetti (brute force, account takeover) → Art. 32 sicurezza.

### Proporzionalità (Art. 5 §1 c — minimizzazione)

- Password: bcrypt cost 12 (no plaintext mai).
- Indirizzo IP: nei registri di audit resta un'**impronta con chiave** (HMAC-SHA256, con una chiave derivata con HKDF da un segreto del server), dal 24 settembre 2026. Un IP noto resta confrontabile con il registro; chi ha il registro ma non il segreto non ritrova l'indirizzo provando tutti i valori possibili. È un **pseudonimo, e resta dato personale**. Le righe scritte prima del 24 settembre hanno un'impronta SHA-256 senza chiave, da cui un indirizzo IPv4 si ritrova per tentativi: restano fino al loro termine ordinario, e la loro protezione è l'accesso ristretto ai registri.
- User-Agent: anche lui resta come impronta. Un valore comune si riconosce confrontandolo con un elenco di User-Agent diffusi: è un pseudonimo, non un dato anonimo.
- **Corretto il 2026-09-03**: `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent **in chiaro**, e `audit_activity_log` lo User-Agent, contro quanto dichiarato qui, nell'Informativa §3.4 e nel Registro B.5. La logica dell'impronta è ora unica (`App\Services\Audit\RequestFingerprint`) e le righe esistenti sono state convertite con la stessa formula (migration `100_audit_ip_ua_hash.sql`). Restano in chiaro, per la sola sicurezza e a conservazione breve: `waf_logs` (30 giorni, cancellati ogni giorno; erano 90 fino al 2026-09-04), `waf_login_failures` (tentativi di accesso falliti, cancellati dalla pulizia giornaliera), `rate_limits` (pulizia giornaliera), `access_log.json` (ultime mille voci di navigazione); e il verbale di accettazione dei Termini, quale prova dell'accettazione, finché esiste l'account (alla cancellazione IP e User-Agent si svuotano, R8). Le sessioni attive sono file sul server e non contengono l'indirizzo IP. Il log di accesso del server web è disattivato (`access_log off`); restano i suoi registri degli errori, nel contenitore e sull'host, che nelle sole righe d'errore riportano l'indirizzo del client: la loro conservazione dipende dalla rotazione dei registri del sistema e del contenitore, e va ancora misurata.
- **Fuori dai registri di sicurezza** l'IP resta in chiaro anche nella riga di uscita del registro di servizio (`debug.log`) e nella cache delle interrogazioni al servizio di reputazione degli indirizzi CrowdSec (Francia), che l'amministratore fa su singoli IP dal pannello del filtro (`waf_cti_cache`). Il registro della posta (`mail.log`) conserva il testo delle email di servizio, comprese le ricevute delle richieste al recapito privacy. I file di registro ruotano per dimensione, non per data. Le segnalazioni di contenuti (`takedown_requests`) tenevano l'IP in chiaro e lo mandavano nell'email al Titolare: dal 24/9/2026 tengono l'impronta con chiave, e le righe precedenti l'hanno perso (migrazione 141).
- Cifrati con la chiave del docente (envelope encryption, Phase 25.D): i file delle mappe, i file TeX e PDF delle verifiche salvate, le compilazioni dei modelli risdoc, i token di Google Drive e GitHub.
- **In chiaro**, protetti dai controlli di accesso: il testo di esercizi, verifiche e laboratori (il «contratto» JSON nello storage degli oggetti) con le versioni precedenti, titoli e argomenti, i metadati dei contenuti, le preferenze di stampa con il numero di copie DSA/DIS (`print_info_data`). Chi ha una copia del database o dello storage li legge senza la chiave del docente.
- Backup: cifrati **lato client prima dell'upload** (GPG/age) verso Backblaze B2 (società statunitense, dati in UE); transport TLS. Ogni copia contiene il database e il `.env.local` di quel giorno, quindi anche la chiave che apre i contenuti: la protegge la passphrase della copia, che ha il solo Titolare (R10). Sul server restano circa una settimana; fuori, rotazione a livelli: 3 giornalieri, 4 settimanali, 6 mensili, 1 annuale — una copia può sopravvivere fino a un anno. Dopo ogni ripristino le cancellazioni successive alla copia vengono rieseguite (`docs/security/operations/restore-reerasure.md`). Nessuna copia ha mai contenuto dati di studenti reali (vedi R10). L'hosting applicativo è su Hetzner (Germania, UE).

### Valutazione dell'interesse legittimo — registri di sicurezza (Art. 6(1)(f))

- **Interesse**: proteggere la piattaforma e gli account da accessi abusivi, forza bruta e furto di sessione, e poter ricostruire chi ha fatto cosa in caso di reclamo o incidente (considerando 49; Art. 32; Art. 5 §2). Interesse reale, attuale e lecito.
- **Necessità**: senza indirizzo IP e User-Agent non si distingue un accesso legittimo da uno anomalo né si blocca chi insiste. L'alternativa meno invasiva è già adottata: un'impronta con chiave, cioè una pseudonimizzazione, in tutti i registri di audit; IP in chiaro solo dove serve a bloccare (filtro, limitatore, tentativi falliti) e al più per trenta giorni.
- **Bilanciamento — docenti**: interessati adulti e professionisti, iscritti volontariamente e informati (Informativa §3.4); aspettativa ragionevole che un servizio online conservi registri di sicurezza; nessuna profilazione, nessuna decisione automatizzata, nessun uso per finalità diverse; impatto contenuto dall'impronta e dalle conservazioni definite (trenta giorni in chiaro; due e cinque anni come impronta).
- **Bilanciamento — studenti con credenziale di classe** (aggiunto il 2026-09-22). L'art. 6(1)(f) contiene un'unica indicazione espressa su chi pesa di più — «in particolare se l'interessato è un minore» — e il considerando 38 la ribadisce: il bilanciamento va fatto **per loro separatamente**, perché le premesse dei docenti non valgono. Non si iscrivono volontariamente: entrano perché il docente dà loro la credenziale. Non sono adulti. Non hanno un account da cui esercitare i diritti. **Cosa si tratta di loro**: nessun dato identificativo, nessun nome, nessun account; i soli dati tecnici della connessione — IP in chiaro per trenta giorni nel registro del filtro di sicurezza e fino alla pulizia del giorno dopo nel limitatore di frequenza, altrove come impronta; come ogni dato del database, anche queste righe restano nelle copie di sicurezza cifrate fino alla loro rotazione, al massimo un anno, accessibili al solo Titolare — il contatore di ingressi della credenziale, e il cookie del portachiavi se lo scelgono. Quando aprono una mappa, anche JGraph ne riceve l'IP (§1). Dal 19 aprile al 24 settembre 2026 la pulizia del limitatore non girava, e i loro IP vi restavano (storia, 1.23). **Perché prevale comunque**: senza registro di sicurezza il servizio che leggono è indifendibile da scansioni e furti di credenziale, e la credenziale che verrebbe abusata è quella che apre i materiali della loro classe; nei registri di sicurezza l'IP in chiaro resta al più trenta giorni; non c'è profilazione né decisione automatizzata; nel registro delle operazioni compaiono come anonimi, e dal 2026-09-22 il codice del loro QR non entra più nel registro (B.6). **Misura presa proprio per loro**: la verifica anti-bot ha smesso di leggere i valori che identificano il dispositivo (B.6-quinquies) — la riduzione vale per tutti, ma è sui minori che pesava di più. **Limite dichiarato**: l'opposizione ex art. 21 si esercita «dall'indirizzo registrato», e chi non ha un account non ne ha uno: per loro il canale è il docente che ha creato la credenziale, che può disattivarla in qualunque momento, e `{{OPERATORE_EMAIL}}` per chiunque scriva.
- **Garanzie**: registri append-only, accesso al solo Titolare con motivazione a registro, impronta giornaliera fuori dal server, diritto di opposizione (Art. 21) via {{OPERATORE_EMAIL}}, informativa che elenca gli archivi in chiaro.

Esito: l'interesse legittimo prevale, e il trattamento resta limitato a quanto sopra.

Trattamenti **rifiutati per proporzionalità insufficiente**:

- Profilazione comportamentale studente (es. "tempo permanenza pagina") — esclusa.
- Geo-location precisa (latitudine/longitudine) — non necessaria.
- Dati biometrici facciali / riconoscimento foto — esplicitamente esclusi.

## 3. Valutazione dei rischi (Art. 35 §7 c)

### Matrice di rischio

| # | Trattamento / scenario | Probabilità | Gravità | Mitigazione | Rischio residuo |
|---|------------------------|-------------|---------|-------------|-----------------|
| R1 | Un docente vede i contenuti di un altro docente | Media — due casi reali, trovati e chiusi il 21 e il 23 settembre 2026 (storia, 1.11-bis e 1.23) | Alta | Controlli di proprietà su ogni rotta (`Permission::canView`, `AclPolicy`) e cifratura con la chiave del singolo docente. Prove end-to-end con due docenti: `risdoc/modifiche-isolate`, `admin/copie-in-concorrenza`, `risdoc/il-bottone-del-pacchetto` (la rotta del difetto del 21/9 risponde 404). Dal 23/9 i file dei modelli si leggono solo dalle loro cartelle (A-2) | **BASSO** ✅ |
| R2 | Un amministratore legge i contenuti dei docenti | Media | Alta | Cifratura per docente + `crypto_access_log` + motivazione obbligatoria sulle azioni amministrative (`RequiresAuditReason`); avviso automatico al docente quando un accesso amministrativo ai suoi contenuti viene registrato (dal 2026-09-04), impronta giornaliera dei registri fuori dal server, secondo fattore obbligatorio per gli amministratori. Dal 23/9 il registro degli accessi non contiene più l'identificativo della sessione, con cui chi lo leggeva poteva presentarsi come il docente (A-64). La cifratura copre mappe, verifiche salvate e compilazioni risdoc: il testo di esercizi e laboratori, i titoli e le preferenze di stampa sono in chiaro, e chi amministra il server li legge senza passare dall'applicazione. Per quella parte la difesa è organizzativa: un solo amministratore, il Titolare | **BASSO** ✅ |
| R3 | Furto di una copia del database | Media | Alta | Mappe, verifiche salvate e compilazioni risdoc cifrate per docente; la chiave master non sta nel database. Account (nome, cognome, email), testo di esercizi e laboratori, titoli e preferenze di stampa sono in chiaro e si leggono dalla copia. Il fascicolo notturno contiene database e chiave insieme: lo protegge la sua passphrase (§2). Copie della chiave: una fuori linea e due in contenitori cifrati presso fornitori distinti (§4, custodia) | **BASSO** ✅ |
| R4 | Brute-force login | Alta | Media | Rate-limit 10/min/IP (Phase 25.B5) + bcrypt cost 12 | **BASSO** ✅ |
| R5 | XSS / CSRF su form | Media | Alta | CSP (Track 7): `'unsafe-eval'` rimosso, handler inline bonificati, `strict` con nonce+strict-dynamic pronta (toggle `/admin/waf/config`); + SameSite=Lax + CSRF middleware (token via header) + CSRF token da fonte client unica `dom-utils.fetchCsrf`; superficie ridotta: zero jQuery + zero CSS-in-JS a runtime, escaping HTML centralizzato (2026-06-05); dal 23/9 il file di una mappa non può più chiudere lo script della pagina di studio, e il caricamento accetta solo file drawio (A-3) | **BASSO** ✅ |
| R6 | ~~Rivelazione BES/DSA senza consenso esplicito~~ | — | — | RIMOSSO 2026-04-27: BES/DSA non è trattato come dato Art. 9 in Pantedu (vedi NOTA in cima a §1). Solo metadata di contenuto del docente. | **N/A** ✅ |
| R7 | ~~Trattamento dati minori senza base/consenso (Art. 8)~~ | — | — | **NON APPLICABILE dal 2026-09-03**: nessun dato identificativo di studenti; accesso con credenziale del docente non nominativa. Le modalità Completa/Ridotta restano nel software, disattivate: riattivabili solo in un'istanza con un Istituto Titolare, con DPIA a suo carico | **N/A** ✅ |
| R8 | Il diritto alla cancellazione (Art. 17) non si riesce a esercitare | Media | Alta | Richiesta dal profilo, conferma con il collegamento che arriva per email, trenta giorni di ripensamento, esecuzione dal lavoro giornaliero (dal 2026-09-04). Fino al 24/9/2026 l'email di conferma non partiva, e la richiesta dal profilo non si poteva completare (storia, 1.23). La cancellazione (`CancellazioneDellAccount`, la stessa per l'art. 17, per l'inattività e per il pannello di amministrazione) cancella i file e i contenuti in chiaro del docente (contratti di esercizi e laboratori, preferenze di stampa, titoli e argomenti, librerie delle mappe, figure in cache), lascia la riga dell'account come segnaposto (nome utente sostituito; email, nome, cognome, scuola, indirizzo, classe e data di nascita svuotati; nel verbale dei Termini restano data e versione, IP e User-Agent si svuotano) e, per ultima, distrugge la chiave del docente: un passo che non riesce non lascia niente di irreversibile, e il giro dopo riprende. Una cancellazione confermata non si annulla più quando è cominciata. Per inattività si arriva dopo tre avvisi via email (60, 30 e 7 giorni prima). Nei registri append-only restano, per il loro termine, il nome utente, le operazioni registrate e, nel registro degli eventi sui contenuti, titolo, classe, indirizzo e materia dei contenuti creati (Art. 17 §3, lett. b ed e): un trigger rifiuta ogni modifica a quei registri. Il limite delle copie è R10 | **BASSO** ✅ |
| R9 | Mancata tracciabilità delle azioni amministrative | Bassa | Media | `RequiresAuditReason` in modalità **enforce** dal 2026-09-02 sulle rotte che la dichiaravano, 31 azioni amministrative su 81; le altre 50, fra cui il cambio di ruolo, la cancellazione di un utente e le migrazioni dal pannello, non la chiedevano. **Dal 2026-09-23** la chiede ogni azione del super-amministratore sotto `/admin` e `/api/admin`, salvo undici esenzioni scritte (rotte che non scrivono dati o toccano solo file temporanei), con una prova che lo verifica (`CoperturaMotivazioneTest`, A-69). Senza una motivazione di almeno dieci caratteri l'azione è respinta con 400. Per le azioni ordinarie la motivazione la compone il pannello (azione e oggetto, più la nota dell'amministratore dove il pannello la chiede). Chi, che cosa e quando restano comunque in `audit_activity_log`. `privileged_access_log` immutabile | **BASSO** ✅ |
| R10 | Una cancellazione lascia tracce nelle copie | Media | Media | Nel sistema in servizio la cancellazione toglie i contenuti in chiaro e i file del docente, e la distruzione della chiave rende illeggibile quello che resta cifrato. Le copie fatte prima della cancellazione contengono ancora il database e la chiave com'erano: i salvataggi giornalieri delle chiavi dei docenti, sul server, per 30 giorni; le copie cifrate notturne, sul server per circa una settimana e fuori dal server al massimo un anno, protette dalla passphrase e accessibili al solo Titolare. Lì contenuti e anagrafiche restano fino alla scadenza della copia. Dopo ogni ripristino le cancellazioni successive alla copia si rieseguono (`docs/security/operations/restore-reerasure.md`). Gli account studente, tutti di prova e dell'autore, non richiedono interventi sulle copie | **Limite dichiarato**, per i contenuti e per le anagrafiche |
| R11 | ~~Phishing parent_email per fake consent~~ | — | — | **NON APPLICABILE dal 2026-09-03**: nessun consenso genitoriale raccolto | **N/A** ✅ |
| R12 | Perdita KMS_MASTER_KEY | Bassa | **Critica** | docs/security/operations/kms-recovery.md: 3 copie con modalità di guasto indipendenti (una fuori linea, su carta + 2 contenitori cifrati presso fornitori distinti) + prova di ripristino semestrale registrata in crypto_custody_events. Chiave cambiata il 2026-09-24 (A-88): stato delle copie in §4, custodia | **MEDIO** ⚠️ |
| R13 | Trasferimenti fuori dall'UE | Bassa | Media | Hosting Hetzner (Germania). Backup Backblaze B2: società statunitense, dati in UE (Amsterdam), cifrati prima dell'invio; clausole contrattuali tipo e Data Privacy Framework. **Cloudflare** (società statunitense) sta davanti al sito: termina il TLS e vede in chiaro, in transito, ogni richiesta, anche quelle degli studenti; non conserva i contenuti; trasferimento possibile in transito, con clausole contrattuali tipo e Data Privacy Framework. Extra-UE con clausole contrattuali tipo anche: Resend (email di servizio: indirizzo, nome, testo del messaggio, codice del secondo fattore via email) e, su scelta del docente, Google (Drive, attivo dal 2026-09-15). JGraph (Regno Unito, decisione di adeguatezza, §1). Le copie su GitHub, se il docente lo configura, seguono il suo rapporto con il fornitore (R21). Dettaglio e garanzie: Registro art. 30, Sezione D | **BASSO** ✅ |
| R14 | Ingegneria sociale → reset password ottenuto convincendo l'amministratore | Bassa | Alta | **Recupero self-service** (link monouso via email, un'ora, token conservato come hash): la richiesta non passa più da una persona da convincere, e l'amministratore non ha più motivo di reimpostare password su richiesta. **2FA** verificata al login e obbligatoria per gli amministratori: chi ottenesse la password di un amministratore non entra comunque. **Per i docenti la verifica in due passaggi è facoltativa**: per chi non l'ha attivata, chi ottiene la password o il collegamento di recupero entra. Dal 2026-09-23 un codice dell'app di autenticazione vale una volta sola (A-65). La reimpostazione **non** disattiva il secondo fattore | **BASSO** ✅ |
| R15 | PDF-Import: dati personali in un PDF caricato finiscono al fornitore di modelli | Media | Media | `PiiMasker` redige codice fiscale ed email; storage di sessione cifrato con TTL; vincolo d'uso "solo libri di testo" in AUP e `ai-literacy.md`. **La redazione non copre i nomi propri** | **MEDIO** ⚠️ (vedi §3.1) |
| R16 | PDF-Import: prompt injection da PDF malevolo | Bassa | Media | `PromptGuard` neutralizza i marcatori d'istruzione e incapsula il testo derivato in un blocco dati delimitato; l'output del modello è sempre trattato come dato non fidato e riparsato | **BASSO** ✅ |
| R17 | PDF-Import: trasferimento extra-SEE verso il fornitore | Media | Media | Funzione disattivata di default; SCC art. 46(2)(c) dei fornitori; alternativa **Ollama locale** senza alcun trasferimento. Vedi Registro art. 30, B.9 e Sezione D | **MEDIO** ⚠️ con provider cloud — **BASSO** ✅ con Ollama |
| R18 | Contenuto generato da IA scambiato per materiale d'autore | Media | Bassa | Marcatura ex art. 50(2) AI Act (attributi DOM + `<meta>` + JSON-LD + sigla visibile) e revisione obbligatoria del docente prima dell'inserimento | **BASSO** ✅ |
| R19 | Impossibile ricostruire cosa ha fatto un utente | Media | Media | `audit_activity_log` (2026-09-02), append-only, due anni. Copre le scritture, i tentativi respinti e gli eventi di dominio di ogni ruolo. Prima l'unica traccia era un file troncato a mille voci, che in produzione copriva sette giorni e aveva già scartato tre mesi. Le sessioni con credenziale del docente vi compaiono come anonime | **BASSO** ✅ |
| R20 | Dati personali di studenti inseriti dai docenti in campi a testo libero (titoli, note, immagini) | Media | Media | Difesa **organizzativa** sul testo libero (divieto in ToS §2.4 e AUP, responsabilità del docente, Notice & Takedown), con l'avviso che si legge **mentre si compila**; difesa **tecnica** su tutto il resto: campi strutturati svuotati prima del salvataggio, nessun campo che chieda il nome di un alunno, e — unica misura che non dipende da una persona — un **limite di tempo** alla conservazione delle bozze (ADR-046). Per esteso in **§3.2** | **MEDIO** ⚠️ — residuo accettato: sul testo libero nessuna misura tecnica può impedire la scrittura, solo ridurre la finestra |
| R21 | Copia in chiaro dei contenuti del docente in un suo servizio (Google Drive, GitHub), fuori dalla cifratura della piattaforma; vi finirebbero anche eventuali dati di studenti scritti nel testo libero (R20) | Media | Media | Solo su scelta del singolo docente e sul suo account; Drive con ambito `drive.file`; token cifrati con la chiave del docente, cancellati con la disconnessione e resi illeggibili con la cancellazione dell'account, che distrugge quella chiave; le copie stanno solo nell'account del docente, che le conserva anche dopo la disconnessione. **Limite dichiarato**: le condivisioni del Drive e la visibilità del repository GitHub le decide il docente, non la piattaforma | **BASSO** ✅ — limite dichiarato |

### Rischi residui ALTI

Nessuno. **R7 e R11 non sono più applicabili** dal 2026-09-03: la piattaforma non tratta dati identificativi di studenti né consensi genitoriali. Un'eventuale riattivazione degli account studente è possibile solo in un'istanza dello Scenario 3 (Istituto Titolare, infrastruttura qualificata ACN) e richiederebbe una DPIA del Titolare; per quel caso resta valida la mitigazione già implementata (Phase 25.C7: età < 14 → parent_email + doppio opt-in).

**R6 RIMOSSO**: ricerca approfondita codebase 2026-04-27 ha confermato che BES/DSA in Pantedu è solo **metadata di contenuto** (checkbox su esercizi + contatori numerici per stampa). I dati sanitari veri (PEI/PDP, certificazioni) sono gestiti dalla scuola tramite registro elettronico esterno + cartaceo, non passano per Pantedu. Quindi **trattamento Art. 9 NON applicabile** al sistema.

### 3.1 PDF-Import — modelli di IA (aggiornamento 2026-08-26)

La funzione **PDF-Import** invia pagine di libri di testo a un modello
linguistico esterno. È **disattivata per impostazione predefinita**
(`PDF_IMPORT_ENABLED=false`): i rischi R15-R18 sono latenti finché il flag
non viene abilitato.

**Rischio residuo principale (R15) — dichiarato onestamente.** `PiiMasker`
opera su pattern deterministici: **codice fiscale ed email via regex**. Non
riconosce i nomi propri, che in linguaggio naturale non sono distinguibili da
altre parole con mezzi puramente sintattici. Un PDF scansionato con il nome di
uno studente sul frontespizio verrebbe quindi inviato al fornitore con quel
nome intatto.

La difesa è **organizzativa, non tecnica**: l'uso ammesso è quello dei libri di
testo, mai gli elaborati degli studenti. Il vincolo è scritto nell'[AUP](../legal/aup.md)
e nella scheda di alfabetizzazione [`ai-literacy.md`](../legal/ai-literacy.md).
Non si sostiene che il rischio sia eliminato: è mitigato e accettato, e la
sua occorrenza va trattata come potenziale data breach secondo il
[runbook](data_breach_runbook.md).

**Azione raccomandata prima di abilitare la funzione con un fornitore cloud**:

1. verificare che il DPA del fornitore scelto sia in vigore e che le SCC art. 46
   coprano il caso d'uso;
2. aggiornare il Registro art. 30 (Sezione D) e l'informativa (§9.1) con il nuovo sub-responsabile, e informarne gli utenti registrati;
3. valutare se l'alternativa **Ollama in locale** — nessun trasferimento, nessun
   sub-responsabile — sia sufficiente per il caso d'uso.

**Rapporto con l'AI Act**: la classificazione del rischio ai sensi del
Regolamento (UE) 2024/1689 è un esercizio distinto da questa DPIA ed è svolta
in [`../legal/ai-act-assessment.md`](../legal/ai-act-assessment.md). Esito:
rischio limitato, nessuna pratica vietata, nessun sistema ad alto rischio. Le
due valutazioni sono complementari: l'art. 27 AI Act (valutazione d'impatto sui
diritti fondamentali) **non** si applica qui, perché presuppone un sistema ad
alto rischio.

### 3.2 R20 — il testo libero, e che cosa lo difende

Un docente può scrivere il nome di un alunno in un campo libero: il titolo di
una verifica, una nota, la didascalia di un'immagine, il corpo di una
relazione. Nessuna misura tecnica può impedirlo senza impedire anche la
scrittura, e questa valutazione non sostiene il contrario.

**Quello che la piattaforma può fare, e fa**, è ridurre tutto il resto.

**Nei campi strutturati nessun alunno è identificabile.** I due campi della
«verifica nominativa» — nome e cognome dell'alunno — che il pannello di stampa
offre restano nel browser: il server non li accetta. Sono l'unico punto in cui
questa affermazione non ha retto, e la misura fatta prima di correggere è parte
della prova: **zero righe in produzione li contenevano**. L'etichetta della
credenziale di classe la compone il sistema, salvo un'aggiunta di al massimo
dodici caratteri per cui il modulo invita a non scrivere nomi (ADR-044). Il
Modulo di autorizzazione, che chiedeva dati di alunni e genitori, non esiste
più.

**Nei modelli risdoc i campi che nominano una persona vengono svuotati dal
server prima del salvataggio**, dove non esistono account studente
(`CompilationScrubber`). Il testo libero dello stesso modello no, e l'avviso in
pagina lo dice — è la parte che conta, perché una difesa raccontata meglio di
com'è vale meno di nessuna difesa.

**Per Istituto, le compilazioni dei modelli istituzionali possono non essere
salvate affatto** (`institutes.compilation_storage`): la bozza resta nel browser
del docente. È una configurazione decisa dal Titolare. Riguarda la
**conservazione**, non il transito: il PDF si genera comunque sul server, dove
il testo arriva, viene trasformato e non resta (informativa §5.1). Toglierlo
significherebbe togliere il documento.

**L'unica mitigazione che non dipende da una persona è il tempo** (ADR-046). Le
bozze di compilazione non restano sul server a tempo indefinito: si cancellano
quindici giorni dopo che il docente ha scaricato il documento — il momento in
cui una copia esiste fuori di qui, e tenerne una qui non serve più — e comunque
a fine anno scolastico se restano ferme. Non impedisce che un dato vietato
venga scritto: **riduce la finestra in cui può uscire**, che è l'unica cosa che
una misura tecnica possa fare contro un campo libero. Il docente è avvisato per
email, di regola sette giorni prima; se l'avviso parte in ritardo, la bozza si
cancella non prima di tre giorni dopo l'avviso; senza un indirizzo valido si
cancella lo stesso. Ogni modifica fa ripartire il conto, perché una
minimizzazione che si paga con il lavoro di qualcuno non regge alla prima
lamentela. Un limite, oggi: una bozza modificata dopo l'avviso non riceve un
secondo avviso, e si cancella alla nuova scadenza. L'invariante `bozze` della
diagnostica fallisce se restano righe scadute: una conservazione dichiarata e
non applicata sarebbe peggio di nessuna dichiarazione.

**Sul testo libero la difesa resta organizzativa**: il divieto sta nei ToS §2.4
e nell'AUP, la responsabilità è del docente (ToS §4), e c'è la procedura di
Notice & Takedown. Il divieto si legge **mentre si compila**, non solo
all'iscrizione: sui modelli istituzionali, fuori dallo scenario 3, la pagina
dice che è una bozza e non un atto della scuola, che i dati personali degli
studenti non si inseriscono, e **che cosa la difesa automatica non copre**
(`AvvisoCompilazione`). Non impedisce niente, ed è detto: sposta l'unica cosa
che un avviso possa spostare, cioè che chi scrive un dato vietato l'abbia fatto
dopo averlo letto lì.

**Se accadesse.** Il dato si rimuove e il caso si valuta nel registro degli
incidenti, che ha anche le colonne per indicare un titolare diverso quando i
dati non sono della piattaforma; la procedura sta nella Fase 1, caso B, del
[runbook](data_breach_runbook.md). Una segnalazione arrivata dai
moduli pubblici e rimasta più di sei ore senza un incidente aperto fa fallire
la diagnostica, quindi manda un avviso: era il punto più debole del piano.

**Residuo: MEDIO, accettato.**


## 4. Misure tecniche e organizzative (Art. 32)

### Tecniche (implementate)

- ✅ **Cifratura at-rest** (Phase 25.D): AES-256-GCM envelope encryption con HKDF-SHA256, una KEK per docente, per i file delle mappe, i file TeX e PDF delle verifiche salvate, le compilazioni dei modelli risdoc e i token dei servizi collegati. Non copre il testo di esercizi, verifiche e laboratori, i titoli, i metadati e le preferenze di stampa, che stanno in chiaro (§2). Nessuna chiave per classe: quella prevista dalla Phase 25.D6 non è mai stata attivata ed è stata rimossa il 2026-09-14 (ADR-037, migration 120). Le copie che il docente sceglie di tenere nel proprio Google Drive o GitHub sono fuori da questa cifratura (§1, R21).
- ✅ **Cifratura in transito**: HTTPS obbligatorio (HSTS 1y + includeSubDomains).
- ✅ **Hashing password robusto**: bcrypt cost 12, con rifiuto delle password comparse in violazioni note (Have I Been Pwned, k-anonymity: alla API arrivano i primi caratteri dell'hash, mai la password).
- ✅ **Verifica in due passaggi**: TOTP o codice via email, a scelta dell'utente; codici di backup monouso e limite di tentativi. **Obbligatoria per i ruoli amministrativi dal 2026-09-04** (decisione a registro), facoltativa per i docenti. Il metodo via email è dichiarato più debole all'utente che lo sceglie. Dal 2026-09-23 un codice dell'app di autenticazione vale una volta sola: prima lo stesso codice apriva l'accesso per circa novanta secondi (A-65).
- ✅ **Avviso all'interessato** (2026-09-04): ogni accesso amministrativo ai contenuti cifrati di un docente (`kek_emergency_access`, `data_recovered`) gli viene notificato via email nel momento in cui viene registrato (`CustodyNotifier`), e l'elenco è consultabile da `/me/custody-events`. Le richieste dell'autorità restano fuori dall'automatismo: un provvedimento può vietare di informare l'interessato.
- ✅ **Impronta giornaliera dei registri** (2026-09-04): `tools/audit/export_audit_chain.php` riduce ogni giorno le righe nuove dei sette registri append-only a blocchi di cinquecento con hash concatenati; l'impronta finisce nella cartella che il backup cifrato porta fuori dal server, e `--verify` ricalcola i blocchi. Chi amministra il server può ancora riscrivere una riga, non senza che la verifica lo dica. L'immutabilità della copia remota richiede l'object lock sul bucket, proprietà del bucket da attivare una volta.
- ✅ **Cancellazioni art. 17 eseguite ogni giorno** (2026-09-04, `pantedu-gdpr-deletions.timer`): fino a quella data `executeOverdue()` esisteva e nessun job lo chiamava — una richiesta confermata sarebbe rimasta in attesa per sempre. Nessuna richiesta reale era pendente.
- ✅ **CSRF protection**: token per sessione, middleware `csrf` su tutte le mutazioni; recupero token lato client da **fonte unica centralizzata** (`dom-utils.fetchCsrf`, 2026-06-05).
- ✅ **WAF fail-closed JSON-aware** (2026-06-05): per le richieste API/XHR challenge e block rispondono in JSON 403 mantenendo il blocco (non degradano il front-end); client auto-recupera la verifica via reload.
- ✅ **Superficie XSS ridotta** (2026-06-05): zero jQuery e zero CSS-in-JS a runtime, escaping HTML centralizzato (`esc`/`escAttr`).
- ✅ **Rate-limiting**: per-bucket (login 10/min IP, content 60/min teacher, deletion 5/min).
- ✅ **Audit log append-only** (2026-09-02): `privileged_access_log`, `crypto_access_log`, `consent_audit`, `crypto_custody_events`, `content_action_log`, `audit_activity_log` e `teacher_recovery_audit` rifiutano modifiche e cancellazioni a livello di database. Purga delle sole righe scadute riservata all'utenza dei job pianificati. Vedi nota sotto.
- ✅ **Registro delle operazioni** (2026-09-02): `audit_activity_log` conserva per due anni le operazioni di tutti i ruoli con metodo, percorso, esito e attore reale (le sessioni con credenziale del docente compaiono come anonime). Prima esisteva solo `access_log.json`, un file troncato alle ultime mille voci: in produzione copriva sette giorni. Registra le scritture, i tentativi respinti e gli eventi di dominio; le letture riuscite restano fuori per minimizzazione. IP conservato come impronta con chiave.
- ✅ **Pseudonimizzazione**: impronta di IP e User-Agent in tutti i registri di audit, con logica unica in `RequestFingerprint`; per l'IP, dal 2026-09-24, un'impronta con chiave (§2). Dal 2026-09-23 l'IP registrato è quello della connessione, o quello indicato dal proxy fidato, come per il limitatore e il filtro: prima un client poteva far registrare l'impronta di un indirizzo a sua scelta (A-63). In chiaro solo i registri di sicurezza a conservazione breve, il verbale di accettazione dei Termini e i punti elencati in §2.
- ✅ **Distruzione della chiave (crypto-shredding)**: cancellare la chiave del docente rende illeggibili tutti i suoi contenuti cifrati nel database in servizio; le copie fatte prima restano leggibili fino alla loro scadenza (R10).
- ✅ **Gettoni monouso come impronta** (2026-09-23): i gettoni che confermano una cancellazione dell'account non stanno più in chiaro nel database e nelle copie; le richieste in attesa con il gettone vecchio sono state fatte scadere (A-67).
- **Frazionamento della chiave master** (implementato, **non attivo**): la copia di sicurezza della `KMS_MASTER_KEY` può essere frazionata con **Shamir Secret Sharing** (es. 3-su-5 o 2-su-3) tra custodi distinti e indipendenti scelti dal Titolare, e nessun singolo custode ricostruirebbe la chiave da solo. Attivarlo è una decisione del Titolare. Proteggerebbe la copia di sicurezza: **non elimina l'accesso operativo** di chi amministra il server, dove la chiave deve risiedere perché la piattaforma funzioni. Implementazione: `app/Services/Crypto/ShamirSecretSharing*`.
- ✅ **CSP**: default-src 'self', frame-ancestors 'none', `object-src 'none'`, `base-uri 'self'`; `'unsafe-eval'` rimosso; modalità `strict` (nonce per-request + `strict-dynamic`) pronta + `report-only` per rollout, toggle runtime da `/admin/waf/config` (Track 7, 2026-06-03).
- ✅ **Isolamento fra docenti provato**: prove end-to-end con due docenti (`risdoc/modifiche-isolate`, `admin/copie-in-concorrenza`), il caso di chi è insieme docente e amministratore, e la rotta del difetto del 21/9 che risponde 404 (`risdoc/il-bottone-del-pacchetto`).

#### Nota — le tre utenze di database e l'append-only

Le versioni precedenti di questo documento indicavano come misura
`REVOKE UPDATE, DELETE` sull'utente applicativo. Non era applicabile: la
concessione di `pantedu_app` è a livello di database, e MySQL/MariaDB non
permette di sottrarre un permesso su singole tabelle da una concessione più
ampia. Si sono usati dei trigger, che sono anzi più forti — valgono per
qualunque utenza e non vanno rivisti quando si aggiungono tabelle.

I trigger però non proteggono se stessi, e per questo le utenze sono ora tre,
separate per funzione:

| Utenza | Può fare |
|---|---|
| `pantedu_app` | legge e scrive i dati. Nessun potere sulla struttura: il privilegio `TRIGGER` le è stato revocato il 2026-09-02 |
| `pantedu_migrator` | modifiche alla struttura. La usano `tools/migrate.php` e, per il solo super-amministratore con motivazione a registro, il pannello `/admin/migrate`, che applica solo le migrazioni già presenti nel codice rilasciato |
| `pantedu_maint` | purga le righe scadute dai log, come impone l'art. 5(1)(e). Solo job pianificati |

**Limite residuo**: chi ha accesso amministrativo al database o al server può
comunque rimuovere le protezioni. È lo stesso confine della chiave master —
non impossibilità tecnica, ma un accesso privilegiato a sua volta protetto
(SSH non esposto, Cloudflare Access con MFA) e tracciato. Chi ottenesse le sole
credenziali applicative non può invece alterare il registro. Le credenziali
del migratore però stanno nello stesso `.env.local` che l'applicazione legge:
la separazione impedisce a un'iniezione SQL fatta con l'utenza applicativa di
togliere i trigger, non a chi legge `.env.local` o esegue codice nel
contenitore. Dal 2026-09-04
l'alterazione da parte di chi amministra il server è **rilevabile a una
condizione**, ed è giusto dirla: l'impronta giornaliera esce dal server e
finisce nel contenitore del backup, ma finché su quel contenitore non è attivo
l'**object lock** — che è proprietà del contenitore, da attivare una volta, e
al 22/9/2026 **non è attiva** — chi amministra il server può riscrivere anche
la copia remota, e con essa l'impronta. La misura alza il costo
dell'alterazione e la rende visibile a chi confronta, non la rende
impossibile. Diventa quello che dichiara di essere quando l'object lock è
acceso; fino ad allora questa è la formulazione esatta, e la precedente
(«è rilevabile», senza condizione) era più forte del vero. L'accesso ai
contenuti di un docente gli è comunque notificato: su quel fronte il registro
non è più letto soltanto da chi lo scrive.

Strumenti: `tools/security/apply_audit_append_only.php`,
`tools/security/create_migrator_db_user.sh`.

### Organizzative

- ✅ **Privacy by design** documentato in ADR-006/007.
- ✅ **Separazione dei privilegi** (2026-09-04): l'account docente dell'operatore non ha più poteri amministrativi; l'amministrazione avviene da un account dedicato, con secondo fattore obbligatorio.
- ✅ **Recapito privacy del titolare**: modulo pubblico `/dpo-contact`, che scrive a `{{OPERATORE_EMAIL}}`. Non c'è un responsabile della protezione dei dati designato (informativa §1).
- ✅ **Termini di conservazione**: `app/Config/retention.php` e `tools/audit/tabelle_da_purgare.php`, applicati da lavori pianificati.
- ✅ **Minimizzazione delle bozze** (2026-09-22, ADR-046): le compilazioni dei modelli si cancellano quindici giorni dopo lo scaricamento del documento, e comunque a fine anno scolastico. Regola in `App\Services\Risdoc\ScadenzaDelleBozze`, esecuzione notturna `tools/gdpr/spazza_bozze_scadute.php`, sorveglianza dall'invariante `bozze` della diagnostica — che guarda il risultato, non l'esecuzione.
- ✅ **Data breach runbook**: `docs/privacy/data_breach_runbook.md`. La prova periodica del piano la esegue un'unità di sistema due volte l'anno, il 15 gennaio e il 15 luglio, con l'avviso di guasto agganciato: se trova qualcosa, l'esercitazione fallisce e l'avviso parte per la stessa strada degli altri guasti. L'unità è installata sul server dal 24 settembre 2026, e il primo giro è riuscito.
- ✅ **Registro trattamenti Art. 30**: completato (Phase 25.C8). Dovuto: l'esenzione dell'Art. 30 §5 vale per i soli trattamenti occasionali, e la gestione delle utenze e i registri di sicurezza non lo sono.
- ✅ **DPIA adottata dal Titolare** il 25 settembre 2026 (§6).
- ✅ **DPA con sub-responsabili**: Hetzner (hosting UE), Cloudflare (edge), Backblaze B2 (backup), Resend (email di servizio) — DPA art. 28 accettati all'attivazione dei rispettivi servizi; dettaglio e garanzie nel Registro art. 30, Sezione D, che ne è la fonte unica.
- **Revisione annuale di questa valutazione**: a gennaio di ogni anno (la prossima a gennaio 2027), o prima se il trattamento cambia.

### Se l'operatore non c'è più (2026-09-22)

Una persona sola conduce il server e custodisce la chiave da cui derivano
quelle dei docenti. La domanda — che cosa accade in caso di trasferimento ad
altra scuola, malattia, cessazione o decesso — non era in nessun documento, e
va posta prima di aprire ad altri, non dopo.

**Quello che c'è, ed è la parte che conta.** Ogni docente può esportare da sé
**tutti i propri dati, in chiaro, in qualunque momento**, senza chiedere niente
a nessuno: `GET /me/export-data` restituisce un archivio con profilo, consensi,
curriculum, contenuti didattici **decifrati**, verifiche, modelli, compilazioni
risdoc, pubblicazioni, condivisioni e il proprio registro di audit (dieci
esportatori, `app/Services/Gdpr/Export/`). La decifratura è automatica e usa la
chiave del docente.

Ne discende la misura esatta del rischio, che è diversa da come si presenta di
solito: **l'esposizione è sulla continuità del servizio, non sui dati.** Un
docente che abbia esportato ha in mano tutto il suo lavoro in un formato
leggibile senza la piattaforma; un docente che non abbia mai esportato dipende
da un server che una persona sola conduce. La differenza fra i due casi la
decide il docente, non l'operatore, ed è per questo che l'esportazione è
self-service e senza limiti.

**Quello che non c'è, e si dichiara come limite.**

- Nessun **piano di successione** scritto: non è previsto chi subentrerebbe
  nella conduzione, né a quali condizioni.
- Nessun **custode esterno** della chiave master. Il frazionamento Shamir
  (§4, «Frazionamento della chiave master») è implementato ma **non attivo**:
  attivarlo è una decisione del Titolare, e fino ad allora le tre copie sono
  tutte in custodia della stessa persona.
- Nessun **preavviso automatico** di cessazione del servizio: se l'operatore
  chiudesse, non c'è un meccanismo che lo annunci ai docenti con anticipo.

**Perché si scrive invece di risolverlo qui.** Le tre cose sopra non sono
difetti di software: sono impegni che una persona fisica prende, e hanno un
costo e una controparte (chi fa il custode, chi subentra, con quale accordo).
Dichiararle è il minimo dovuto all'art. 5 §2; prenderle è una decisione del
Titolare, e questa valutazione sarà riesaminata quando la prende. Nel frattempo
il limite è noto a chi si iscrive, perché è scritto qui — e qui soltanto: nei Termini di Servizio non c'è, e finché non c'è questa valutazione è l'unico posto in cui un docente lo trova.

### Custodia della chiave master (Art. 32 §1 c)

Vedi `docs/security/operations/kms-recovery.md`.

**Cambiata il 24 settembre 2026**, fra le 00:25 e le 00:55 (A-88; valutazione
nella storia, 1.23): la chiave precedente era trascritta in un documento
interno dell'autovalutazione di sicurezza di aprile 2026. Il cambio ha
riavvolto con la chiave nuova le chiavi dei docenti, senza cambiarle: tre
account, tutti del Titolare, e una chiave di recupero. L'impronta SHA-256 della
chiave nuova comincia con `b7743e11…`. La chiave precedente resta sigillata
fuori linea e si distrugge non prima del 24 ottobre 2026, alle condizioni
della procedura.

- Server: `.env.local`, fuori dal repository, permessi 640 (proprietario
  l'utente del servizio, gruppo quello del server web): lo leggono il PHP del
  contenitore e i lavori pianificati — copia **operativa**, non un backup
- **Una copia fuori linea**: la chiave trascritta su carta, custodita in
  cassaforte
- **Due contenitori cifrati** presso fornitori cloud distinti, ciascuno con
  passphrase propria: sono copie in rete, non fuori linea

Le copie della chiave nuova sono state fatte prima del cambio, come chiede la
procedura. La loro verifica per impronta e la registrazione in
`crypto_custody_events` (eventi `kms_rotated` e `kms_backup_created`) sono
l'ultimo passo della procedura, a carico del Titolare: fino ad allora l'ultima
verifica registrata delle copie è quella del 26 agosto 2026, e riguarda la
chiave precedente.

Requisito di progetto: le tre copie devono avere **modalità di guasto indipendenti** — supporto fisico, e due account presso fornitori diversi. Nessun singolo incidente (ransomware, lockout di un account, furto presso una sede) deve poterle compromettere insieme.

> L'identità dei fornitori, l'ubicazione fisica della copia fuori linea e i nomi dei contenitori sono **dati operativi del singolo esercente**: non compaiono in questo documento. Sono tracciati nel registro `crypto_custody_events` dell'istanza e nel runbook interno `docs/security/operations/kms-recovery.md`, che non fa parte della distribuzione pubblica.

- **Postazioni di sviluppo**: non devono mai contenere la chiave di produzione. Ogni ambiente di sviluppo usa una `KMS_MASTER_KEY` propria, valida solo per dati di prova. Fino al 24 settembre 2026 non era così: l'autovalutazione di aprile era stata fatta sulla postazione di sviluppo, che usava la stessa chiave della produzione (A-88). Dal cambio la postazione ha una chiave propria, diversa dalla vecchia e dalla nuova (impronte confrontate il 24 settembre 2026).

- **Prova di ripristino**: registrata come evento `kms_backup_verified` in `crypto_custody_events`, compilabile da `/admin/crypto-status`. Cadenza semestrale. Prima prova il 26 agosto 2026.
- **Frazionamento Shamir** *k*-su-*n* (es. 3-su-5) della copia di sicurezza: implementato, **non attivo**; attivarlo è una decisione del Titolare. Con le quote affidate a custodi distinti, nessuno ricostruirebbe da solo la chiave dalla copia di sicurezza. Non elimina l'accesso operativo (§4, «Frazionamento della chiave master»).

## 5. Consultazione preventiva Garante (Art. 36)

Necessaria SE rischio residuo ALTO non mitigabile:

- Nessun rischio ALTO. R6, R7 e R11 non sono applicabili; R14 è sceso a BASSO con il recupero password self-service e la verifica in due passaggi; restano MEDIO R12 (perdita chiave master, mitigato da tre copie indipendenti), R15/R17 (PDF-Import, funzione disattivata) e R20 (testo libero, mitigazione organizzativa).
- Decisione: **NON necessaria**.

## 6. Conclusioni e azioni richieste

### Decisione DPIA

La consultazione preventiva del Garante (art. 36) **non è necessaria**: nessun
rischio residuo è ALTO, e §5 ne dà conto.

Questa valutazione era stata resa condizionata a una lista di lavori. Quelli
tecnici sono chiusi; resta una voce, ed è giusto che si legga per quello che
è — una decisione — invece che come una spunta.

| Condizione | Stato |
|---|---|
| Isolamento e irrigidimento dell'istanza | ✅ fatto |
| Cifratura a riposo dei contenuti, con chiave per docente | ✅ fatto |
| Cancellazione self-service (art. 17) con crypto-shredding | ✅ fatto; l'email di conferma parte dal 2026-09-24 (R8) |
| Registro delle attività di trattamento (art. 30) | ✅ fatto — dovuto: il trattamento non è occasionale (art. 30 §5) |
| Informativa conforme agli artt. 13-14 | ✅ fatto |
| Misure per i minori (art. 8) | **non applicabile** dal 2026-09-03: nessun dato identificativo di studenti, accesso con credenziale del docente non nominativa. Le modalità con account studente restano nel software, disattivate |
| Recapito per l'esercizio dei diritti (`{{OPERATORE_EMAIL}}`; nessun responsabile della protezione dei dati designato) e registro degli accessi privilegiati | ✅ fatto |
| Pentest esterno di terza parte | ⚠️ **richiesto prima di attivare lo Scenario 3**, qui sotto |

**Il pentest esterno, e perché è legato allo scenario invece che a una data.**
«Prima del go-live» non è un criterio: è una condizione che non si chiude mai,
e che rende non adottabile la valutazione che la contiene. La proporzionalità
della misura (art. 32 §1) si misura sulla natura dei dati e degli interessati.

- **Scenari 1 e 2** (uso personale; docenti di qualunque scuola, iscritti su
  approvazione, senza dati identificativi di studenti): il pentest esterno non
  è una condizione. Le misure in essere, l'audit di giugno 2026 e le revisioni
  del codice di settembre sono proporzionati a questi trattamenti, e il costo
  di un pentest certificato non lo è per un servizio gratuito. La decisione è
  del Titolare e sta scritta qui, come chiede l'art. 32 §1.
- **Scenario 3** (piattaforma adottata da un Istituto, con dati identificativi
  di studenti): si esegue **prima** di attivarlo, e non è rinunciabile.

I difetti trovati il 21 e il 23 settembre 2026 sono venuti da riletture
interne, in una finestra in cui gli unici account docente e di amministrazione
erano del Titolare (storia, 1.11-bis e 1.23).


Un'eventuale riattivazione degli account studente è possibile solo in un'istanza condotta da un Istituto Titolare su infrastruttura qualificata ACN, e richiederebbe una DPIA a cura di quel Titolare.

### Adozione

| Campo | Valore |
|-------|--------|
| Titolare | {{OPERATORE_NOME}}, persona fisica che agisce a titolo personale e gratuito |
| Email | {{OPERATORE_EMAIL}} |
| Prima stesura | 2026-04-27 |
| Versione DPIA | 1.23 del 25 settembre 2026 |
| Stato | **Adottata dal titolare il 25 settembre 2026** — nessun dato identificativo di studenti (registrazione disattivata dal 2026-09-03, mai studenti reali) |
| Prossima revisione | Annuale (gennaio 2027), o prima se il trattamento cambia |

**Adottata dal titolare il 25 settembre 2026.**

{{OPERATORE_NOME}}, titolare del trattamento

**Firma**: __________________________

## Storia delle revisioni

| Versione | Data | Che cosa è cambiato | Operatore |
|---|---|---|---|
| 1.23 | 2026-09-25 | **Adottata dal titolare il 25 settembre 2026**: la valutazione non è più una bozza. **La DPIA è dovuta**: il riquadro iniziale cita il provvedimento del Garante n. 467/2018 (allegato 1, n. 6, trattamenti non occasionali di dati di soggetti vulnerabili, fra cui i minori) e non dice più che la questione «resta aperta». **Corrette le affermazioni che il codice smentiva.** Non tutti i contenuti sono cifrati: la cifratura per docente copre mappe, verifiche salvate, compilazioni risdoc e token, mentre il testo di esercizi, verifiche e laboratori, i titoli, i metadati e le preferenze di stampa con i contatori DSA/DIS stanno in chiaro, e la valutazione diceva il contrario (§2, R2, R3). R10: la distruzione della chiave rende illeggibili i contenuti nel database in servizio, non nelle copie fatte prima, che contengono database e chiave fino alla loro scadenza; la versione precedente diceva «anche nei backup». Impronta dell'IP: era uno SHA-256 senza chiave, da cui un indirizzo IPv4 si ritrova per tentativi, e questa valutazione la diceva «non ricostruibile»; dal 24 settembre è un'impronta con chiave, e le righe precedenti si cancellano con il loro termine ordinario. Cancellazione (art. 17): la richiesta dal profilo non mandava l'email di conferma, quindi non si poteva completare, e l'anonimizzazione dopo 730 giorni sostituiva l'email, svuotava nome e cognome e disattivava l'account, senza distruggere la chiave; dal 24 settembre l'email parte, e le due fanno la stessa cosa (R8). `waf_logs` si puliva una volta al mese, e le righe restavano fino a circa due mesi invece di trenta giorni: ora la pulizia è giornaliera. La prova periodica del piano per le violazioni era dichiarata e la sua unità non era installata sul server: lo è dal 24 settembre, e il primo giro è riuscito. Il migratore del database si usa anche dal pannello, non solo da riga di comando; `.env.local` ha permessi 640, non 600; due delle tre copie della chiave master sono contenitori cifrati in rete, non copie fuori linea. Dichiarati i punti con l'IP in chiaro che mancavano (registri d'errore del server web, `debug.log`, cache delle interrogazioni a CrowdSec) e il registro della posta; le segnalazioni di contenuti tenevano l'IP in chiaro e dal 24 settembre tengono l'impronta con chiave; tolte le sessioni, che sono file senza IP. `access_stats.json`, mai dichiarato, teneva per ogni giorno i nomi utente di chi entrava: dal 24 settembre tiene solo i totali. R1 descrive il rischio vero, un docente che vede i contenuti di un altro, con le prove che esistono: quella citata era stata sostituita il 7 settembre. R13 nomina Cloudflare, società statunitense, fra i trasferimenti, e Backblaze come società statunitense con dati in UE. R14 dice che per i docenti il secondo fattore è facoltativo. Il Regno Unito è coperto dalla decisione di adeguatezza del 19 dicembre 2025, che ha rinnovato quella del 2021. Il riquadro iniziale citava l'art. 8, che riguarda il consenso dei minori, come fonte dell'obbligo della DPIA. Il pentest esterno è legato allo Scenario 3, non a «una decina di colleghi della stessa scuola». Gli account studente di prova erano dell'autore, con suoi indirizzi email, e sono stati cancellati dopo la disattivazione della registrazione, non il 3 settembre. In §3.2 («Se accadesse») tolto il ruolo attribuito all'Istituto. Il bilanciamento per i minori non dice più che la loro è «la conservazione più breve di tutto il sistema»: le copie di sicurezza tengono anche quelle righe fino a un anno. Tolte dalle voci 1.14, 1.20, 1.21 e 1.22 le note di lavorazione. Account inattivi: tre avvisi via email (60, 30 e 7 giorni) prima della cancellazione a 730 giorni, e mai la cancellazione senza l'ultimo avviso partito da almeno 7 giorni (R8). La cancellazione cancella prima file e righe e per ultima la chiave, ed è la stessa dal pannello di amministrazione; nei registri append-only restano anche i titoli dei contenuti. Nessun dato nuovo, nessun termine allungato. | {{OPERATORE_NOME}} |
| 1.23 | 2026-09-25 | **Correzioni di sicurezza del 23 settembre 2026**, trovate da una revisione del codice e chiuse lo stesso giorno. Toccano misure che questa valutazione dava per fatte. Un docente autenticato poteva leggere file dell'applicazione, configurazione compresa, dalla rotta dei file dei modelli (A-2). Il file di una mappa poteva eseguire uno script nella pagina di studio, anche per studenti e ospiti (A-3). Il registro degli accessi conteneva l'identificativo delle sessioni, con cui chi lo leggeva poteva presentarsi come il docente (A-64). L'IP dei registri era falsificabile con un'intestazione scelta dal client (A-63). Un codice dell'app di autenticazione valeva più volte per circa novanta secondi (A-65). I gettoni di cancellazione e di consenso stavano in chiaro nel database e nelle copie (A-67). 50 azioni amministrative su 81, fra cui il cambio di ruolo, la cancellazione di un utente e le migrazioni dal pannello, non chiedevano la motivazione che R9 dava per obbligatoria dal 2 settembre (A-69). **Valutazione ex art. 33 §5.** Ogni difetto richiedeva un account docente o di amministrazione, o l'accesso ai registri o al database, e alla data gli unici account di questo tipo erano del Titolare; A-63 alterava i registri, non esponeva dati; per A-2 i registri di produzione, guardati prima di correggere, non mostrano usi sospetti. Nessun accesso non autorizzato a dati di terzi: nessuna violazione, niente da notificare. | {{OPERATORE_NOME}} |
| 1.23 | 2026-09-25 | **Valutazione ex art. 33 §5: la chiave master (A-88).** Fino al 24 settembre 2026 la chiave master in uso in produzione era scritta in chiaro in un annesso dell'autovalutazione di sicurezza di aprile 2026 e nel rapporto PDF che la accompagna: l'autovalutazione era stata fatta sull'ambiente di sviluppo, che usava la stessa chiave della produzione. Stava nel repository di sviluppo, privato su GitHub (unico collaboratore il Titolare, nessun fork né invito; lo leggono anche il server, per il rilascio, e la catena di integrazione), nelle sue copie sulle macchine del Titolare e sul server; non nella copia pubblica del codice, verificato il 24 settembre. Accaduto, per stima, il 28 aprile 2026; rilevato il 23 settembre confrontando le impronte delle chiavi, mai i valori. La chiave proteggeva le chiavi di tre account, tutti del Titolare (amministrazione, docente, prova), e una chiave di recupero: nessun dato di terzi; per leggere i contenuti serviva anche una copia del database. Nessun export firmato con quella chiave è stato consegnato. Nessun indizio di accesso di terzi. **Non è una violazione di dati personali di terzi**: nessuna notifica al Garante, nessuna comunicazione a interessati. Cambiata il 24 settembre fra le 00:25 e le 00:55 (§4, custodia). Il cambio non cambia le chiavi dei docenti: con la chiave vecchia e una copia del database anteriore al 24 settembre si leggerebbero ancora i contenuti di quei tre account. Resta da togliere il valore dall'annesso e dalla storia del repository; le copie notturne anteriori al 24 settembre la contengono, cifrata, fino alla loro rotazione. La stessa valutazione sta nel registro degli incidenti. | {{OPERATORE_NOME}} |
| 1.23 | 2026-09-25 | **Valutazione ex art. 33 §5: una pulizia dichiarata e non eseguita.** Dal 4 settembre 2026 questa valutazione dichiarava una pulizia giornaliera di `rate_limits`, la tabella del limitatore di frequenza, che conserva l'IP in chiaro, anche degli studenti che entrano con la credenziale di classe. Nessun lavoro la eseguiva, e le righe si accumulavano dal 19 aprile 2026. L'unità che la esegue gira dal 24 settembre, ogni giorno alle 03:15; il primo giro, quella notte, ha tolto 6.536 righe, la più vecchia del 19 aprile. IP conservati oltre il termine dichiarato: è una mancata applicazione dell'art. 5(1)(e), non una violazione di sicurezza; la tabella sta nel database, accessibile al solo Titolare, e non risultano accessi di terzi. Nessuna notifica. Le copie di sicurezza cifrate anteriori al 24 settembre contengono ancora quelle righe fino alla loro rotazione. | {{OPERATORE_NOME}} |
| 1.22 | 2026-09-22 | **Il registro di navigazione: 365 giorni che nessuno applicava.** La tabella delle categorie (§1) dava ancora 365 giorni all'access log, il termine che l'informativa aveva corretto la stessa mattina: il contenimento vero è un troncamento alle ultime mille voci (`app/Config/audit.php`, `access_log_max_entries`), che in produzione coprono circa una settimana, e la chiave `access_log_days` di `app/Config/retention.php` non la leggeva nessun codice ed è stata tolta. Trovato rileggendo gli allegati: il numero vecchio restava in tre punti su quattro. Allineati nella stessa occasione il registro (B.6, versione 1.19) e il Pacchetto di accountability (§4, versione 1.6). Nessun dato nuovo, nessun termine allungato. | {{OPERATORE_NOME}} |
| 1.21 | 2026-09-22 | **La scuola diventa facoltativa, e la DPIA non lo sapeva** (ADR-047). L'informativa 2.16 e il registro 1.18 lo dichiaravano dalla stessa mattina; questo documento no, e continuava a presentare l'istituto come dato necessario all'erogazione del servizio — cioè la premessa che ADR-047 ha tolto. Corretti §1 (delimitazione) e la tabella delle categorie. Un docente può usare la piattaforma senza mai nominare un Istituto, e in quel caso l'Istituto sparisce dal trattamento. Nessun dato nuovo: uno in meno. | {{OPERATORE_NOME}} |
| 1.20 | 2026-09-22 | **La forma, perché la forma qui è sostanza.** Revisione della forma del documento. (1) **La storia delle revisioni era una tabella rotta**: la voce 1.16 era lunga otto paragrafi su righe che non cominciano con «\|», e in Markdown questo chiude la tabella — nel PDF consegnato gli otto paragrafi uscivano come testo sciolto e le voci successive come una seconda tabella senza intestazione. Ora ogni voce sta su una riga, e una prova automatica su tutti i documenti legali impedisce che ricapiti. (2) **L'ordine delle versioni** era crescente, poi decrescente, poi tre frammenti scollegati: ora è uno solo, dalla più recente, come nell'informativa e nel registro. (3) **La matrice dei rischi** era R1-R9, R19, R10, R11, R20, R12-R18, R21: ora è in ordine. (4) **R20 era un saggio dentro una cella**, 3.735 caratteri contro una media di 354: la sostanza è passata in una sezione sua (§3.2), sul modello della §3.1, e nella cella resta la sintesi. Le date che raccontavano *quando* una misura è stata introdotta sono diventate presente; resta nel corpo la storia che è **prova**, per esempio che i due campi del nome dell'alunno si salvavano in chiaro fino al 22 settembre e che in produzione erano zero le righe che li contenevano. (5) **La §6 elencava come «condizioni» due voci che erano changelog**, datate 3 e 4 settembre, e verificate parola per parola come già contenute nella voce 1.5 di questa stessa tabella: tolte da lì, dove erano un doppione. Le altre condizioni sono ora una tabella con il loro stato, e il pentest esterno si legge per quello che è — una decisione con una soglia — invece che come una spunta non ancora messa. Nessun fatto è stato tolto: misurato, zero parole perse dalla coda del documento. | {{OPERATORE_NOME}} |
| 1.19 | 2026-09-22 | **R20 ha una mitigazione che non dipende da nessuno** (ADR-046). Fino a oggi il rischio «dati personali di studenti scritti nel testo libero» era difeso solo da misure organizzative: un divieto nei Termini, un avviso in pagina, la responsabilità del docente. Tutte cose che chiedono a una persona di non fare qualcosa. Da oggi c'è anche un limite di tempo: **le compilazioni dei modelli non restano più sul server a tempo indefinito**. Si cancellano quindici giorni dopo che il docente ha scaricato il documento — il momento in cui una copia esiste fuori di qui, e tenerne una qui non serve più — e comunque il 31 agosto se restano ferme dal 1° giugno precedente. **Non impedisce che un dato vietato venga scritto**, e va detto: riduce la finestra in cui quel dato può uscire da una violazione, che è l'unica cosa che una misura tecnica possa fare contro un campo a testo libero. Il residuo resta MEDIO e resta accettato. Il docente è avvisato per email con almeno sette giorni di anticipo e ogni modifica fa ripartire il conto, perché una minimizzazione che si paga con il lavoro di qualcuno non regge alla prima lamentela. E perché una conservazione dichiarata e non applicata sarebbe peggio di nessuna dichiarazione, l'invariante `bozze` della diagnostica fallisce se restano righe scadute: guarda il **risultato**, non se il lavoro notturno è partito. Riferimenti: informativa 2.14, registro 1.17. | {{OPERATORE_NOME}} |
| 1.18 | 2026-09-22 | **La storia esce dal corpo e resta nella storia.** Il documento raccontava nel testo che cosa vi si leggeva prima: in apertura, che la valutazione «non è obbligatoria, perché non si trattano dati Art. 9 né dati di minori» — frase la cui seconda metà non reggeva, perché «nessun dato identificativo» non è «nessun dato»; e al §7, che la prova periodica del piano era indicata come «PENDING» e nessuno la eseguiva. Entrambe restano scritte qui, dove la storia di un documento si legge. **Nel corpo no**, per due ragioni. La prima è di forma: chi apre una valutazione d'impatto cerca lo stato dell'arte, e trova invece l'archeologia di formulazioni superate. La seconda è di sostanza: quelle formulazioni non hanno riguardato nessuno — alla data in cui sono state corrette la piattaforma aveva un solo utente reale, l'autore — quindi non c'è un affidamento da riparare, e ripeterle nel corpo non protegge nessuno. **Resta invece nel corpo la storia che è prova**: per esempio, al §1, che i due campi del nome dello studente si salvavano in chiaro fino al 22 settembre e che in produzione erano zero le righe che li contenevano. Quella non è una confessione, è la dimostrazione che nessuna violazione è avvenuta, e va dove la domanda sorge. Il criterio: la storia che risponde a una domanda resta nel corpo, quella che si limita a raccontare una formulazione superata scende qui. | {{OPERATORE_NOME}} |
| 1.17 | 2026-09-22 | **Il punto più debole del piano, chiuso.** Il piano per le violazioni dichiarava di non avere alcun collegamento fra una segnalazione arrivata dai moduli pubblici e il registro degli incidenti: l'incidente si apre a mano, quindi è un passaggio che si può dimenticare — e quelle sono le sole vie che possano rilevare il rischio R20, che nessun controllo automatico vede. **L'incidente continua a non aprirsi da solo, ed è una scelta**: quei moduli sono pubblici e senza autenticazione, e una riga del registro non si cancella mai, quindi farli scrivere direttamente lascerebbe che chiunque lo riempia per sempre — la conservazione permanente, che è una garanzia, diventerebbe l'arma. Si fa il contrario: le due tabelle delle segnalazioni hanno ora una colonna che le lega all'incidente (migrazione 137), e un invariante della diagnostica guarda quelle senza legame: oltre **sei ore** l'unità fallisce e l'avviso parte per la stessa strada degli altri guasti. Il controllo gira due volte al giorno, quindi il rumore arriva entro dodici ore e ne restano sessanta delle settantadue. Si spegne in un modo solo: o si apre l'incidente dal pannello, o si chiude la segnalazione dichiarando che non era una violazione — e anche quella valutazione l'art. 33 §5 chiede di documentarla. **Corretto nella stessa occasione un errore che toccava proprio il termine di legge**: l'applicazione gira su Europe/Rome e il database su UTC, quindi una data scritta da PHP finiva due ore nel futuro rispetto all'orologio del database. Il campo colpito era `detected_at`, quello da cui decorrono le settantadue ore e che il trigger della migrazione 136 congela: due ore di margine in più prese per sé, in un registro che serve a dimostrare il contrario. Il percorso automatico prende ora l'ora dal database; le altre scritture di date restano da verificare una per una, ed è scritto nel registro del debito tecnico. | {{OPERATORE_NOME}} |
| 1.16 | 2026-09-22 | **Una giornata di rilievi, chiusi.** Sette modifiche, tutte nate dal confronto fra quello che i documenti affermano e quello che il codice fa. **A chi segnala una violazione si prometteva un mese**: il modulo `/dpo-contact` ha un oggetto «Segnalazione data breach», e la ricevuta era la stessa dei nove oggetti — «risposta entro 30 giorni, art. 12 §3». L'orologio di una violazione è l'art. 33, e dà 72 ore da quando il titolare ne viene a conoscenza, cioè da quell'invio. Ora lo dice, dice che se i dati sono di un altro titolare avvisiamo anche quello, e la richiesta non si segna più da sola come presa in carico. **Il registro delle violazioni era «append-only» solo a parole**: nessun trigger lo imponeva. Ora i fatti non si correggono — la data del rilevamento, quella dell'accaduto, le notifiche già fatte — e una riga non si cancella, mentre il flusso di lavoro resta possibile (migrazione 136). Lo stesso registro ha ora le colonne per dire **di chi è il dato** quando non siamo noi, e per registrare che il titolare è stato avvisato: senza, una fuga di dati di cui è titolare una scuola non era nemmeno attribuibile. **Il piano per le violazioni non diceva da dove arriva la notizia**: la sua prima fase si chiamava «Rilevamento» e cominciava con «isolare il vettore». Riscritto: una Fase 0 con le sei strade misurate, una Fase 1 che chiede di chi siano i dati prima di ogni altra cosa — con il caso R20 e la procedura verso l'Istituto — e in coda l'elenco di ciò che il piano **non** ha. **La prova periodica del piano non l'aveva mai eseguita nessuno** e questa valutazione la dava «PENDING»: ora la esegue un'unità di sistema due volte l'anno con l'avviso di guasto agganciato. Uno dei suoi passi, inoltre, diceva di contare i tentativi di accesso e contava gli utenti creati. **Un limite dichiarato non operava**: il modulo pubblico di segnalazione era descritto come «3 all'ora per IP» in tre posti, uno dei quali pubblicato in rete, e operava a tre al minuto. **Il divieto di scrivere dati degli studenti si legge ora dove si scrive**: sui modelli istituzionali, fuori dallo scenario 3, e dice anche che cosa la difesa automatica non copre (vedi R20). **Chiudere un'anomalia non lasciava scritto perché**: si registravano il codice, l'istante e il nome di chi segnava, non che cosa avesse visto. Ora la motivazione è obbligatoria. | {{OPERATORE_NOME}} |
| 1.15 | 2026-09-22 | **Una difesa dichiarata, verificata.** Il §1 diceva che lo scenario Istituto è attivabile solo su un'istanza qualificata ACN: misurato che non è una dichiarazione ma un rifiuto — `DeploymentScenario::activationBlocker()` interroga `instanceAcnQualified()` e respinge con `institute_requires_acn_instance`, e su questa istanza quel valore è `false`. Ne discende il limite, ora scritto: un documento che chiedesse nome, cognome e condizione BES/DSA di singoli alunni — dato sanitario di minori ex art. 9, di cui sarebbe titolare l'Istituto — non sarebbe lecito qui, e la piattaforma non consente di mettersi nella configurazione in cui lo sarebbe. Detto anche che negli scenari 1 e 2 `CompilationScrubber` svuota righe e celle delle tabelle nominate su una persona, e che resta scoperto il campo nominato in modo neutro: è R20, e non si chiude con una lista di parole, perché su una piattaforma didattica «certificazione», «sostegno» e «patologia» sono materia di studio. | {{OPERATORE_NOME}} |
| 1.14 | 2026-09-22 | **Il campo del nome dello studente.** Trovato in una rilettura: il pannello delle verifiche ha un interruttore «verifica nominativa» che scopre due campi dedicati a nome e cognome di un alunno — non campi liberi in cui un nome può scappare, campi fatti per contenerlo — e il server li salvava **in chiaro** in `print_info_data`, sotto una chiave che porta istituto e sezione. Contraddiceva questo stesso documento (riquadro iniziale, «non registrato in DB»), R20 («nei campi strutturati nessun alunno è identificabile, per costruzione»), i Termini §2.4 e l'AUP §2.6, che vietano al docente ciò che l'interfaccia gli offriva con un bottone. **Misurato in produzione prima di correggere: tre righe in tutto, zero con quei campi valorizzati** — nessun nome è mai stato salvato, quindi nessuna violazione ex art. 33 da valutare e niente da cancellare. La funzione resta (la verifica si stampa col nome), il salvataggio no: i due campi escono dalla lista dei campi persistiti e il server li scarta anche se il client li manda. Aggiunta al registro la tabella `print_info_data`, che non era dichiarata. Registro 1.14. | {{OPERATORE_NOME}} |
| 1.13 | 2026-09-22 | **Da un audit dei rilievi**, che ha confrontato una per una le affermazioni dei documenti con il codice. R20: l'impostazione `compilation_storage` non è più descritta come presa «su indicazione del DPO» dell'Istituto — era l'Istituto scritto dentro la determinazione di un mezzo. §4, successione: il limite non è «scritto qui e nei Termini», perché nei Termini non c'è, e questa valutazione è l'unico posto in cui un docente lo trova. **Due controlli dichiarati che non operavano, corretti nel codice**: il limitatore di frequenza usciva su tutto ciò che non scrive, quindi dodici rotte GET dichiaravano un limite inesistente — per la verifica in due passaggi e il recupero password senza danno, perché la POST è limitata, ma l'ingresso con QR esiste solo come GET, tenta credenziali e ne accetta dodici per richiesta (token di 256 bit: non era un modo per entrare, era un controllo assente e letture senza freno); e il pacchetto generato per il DPO dichiarava «Backblaze B2 con Object Lock retention» e «audit log immutabile (REVOKE UPDATE/DELETE)», due misure che i documenti del progetto avevano già corretto — l'object lock non è attivo, e il REVOKE era stato sostituito dai trigger fin dalla versione 1.3 di questa DPIA. Informativa 2.12, registro 1.13. | {{OPERATORE_NOME}} |
| 1.12 | 2026-09-22 | **Tre affermazioni che non reggevano più, rettificate.** (1) «L'alterazione dei registri da parte di chi amministra il server è rilevabile»: lo è **a una condizione**, e la condizione non è soddisfatta. L'impronta giornaliera esce dal server, ma finché sul contenitore del backup non è attivo l'object lock — e al 22/9/2026 non lo è, come questa stessa DPIA dice due righe sopra — chi amministra può riscrivere anche la copia remota. La misura alza il costo dell'alterazione e la rende visibile a chi confronta; non la rende impossibile. La formulazione precedente era più forte del vero. (2) Il **pentest esterno** era una condizione «pre-go-live» senza criterio, e una condizione che non si chiude mai rende non adottabile la valutazione che la contiene: ora ha una soglia dichiarata, sul numero e sulla natura degli interessati, con la decisione del Titolare scritta come chiede l'art. 32 §1. (3) Aggiunta la sezione **«Se l'operatore non c'è più»**: la domanda non era in nessun documento. Misurato che ogni docente può esportare da sé tutti i propri dati **in chiaro** e in qualunque momento, contenuti decifrati compresi — quindi l'esposizione è sulla continuità del servizio, non sui dati —, e dichiarati come limiti i tre pezzi che mancano: nessun piano di successione, nessun custode esterno della chiave, nessun preavviso automatico di cessazione. | {{OPERATORE_NOME}} |
| 1.11-bis | 2026-09-22 | **Valutazione ex art. 33 §5 del difetto chiuso il 2026-09-21** (PR #185). La rotta che consegnava il pacchetto TeX esportato verificava che chi scaricava fosse *un* docente, non *il proprietario* del pacchetto: chi ne avesse conosciuto il nome — sedici caratteri casuali, un'ora di vita, non elencabile — avrebbe potuto scaricare il lavoro di un altro docente, che per R20 può contenere dati di studenti scritti in campo libero. **Non è una violazione ex art. 4 n. 12**: alla data del difetto la piattaforma aveva un solo docente reale, e i pacchetti erano tutti suoi; nessun accesso non autorizzato è quindi avvenuto, e non c'era nulla da notificare. È però documentata qui, perché l'art. 33 §5 chiede di documentare la valutazione e non solo le violazioni, e perché la circostanza che ha contenuto il rischio — un solo utente — è esattamente quella che viene meno quando si aprono le iscrizioni. **Chiusura**: la rotta non esiste più e il pacchetto si consegna nel corpo della risposta, quindi non resta su disco e non c'è niente da servire; una prova end-to-end verifica che quell'indirizzo risponda 404. La riga della versione 1.10 diceva «nessuna misura tolta» e taceva che una era stata aggiunta: questa riga lo corregge. | {{OPERATORE_NOME}} |
| 1.11 | 2026-09-22 | **I minori entrano nel bilanciamento.** Il test dell'interesse legittimo (§2) descriveva gli interessati come «adulti e professionisti, iscritti volontariamente»: è la descrizione dei docenti, e gli studenti con credenziale di classe — che non si iscrivono, non sono adulti e non hanno un account — ne restavano fuori, mentre l'art. 6(1)(f) indica espressamente il minore come chi pesa di più. Aggiunto un bilanciamento separato per loro, con il limite dichiarato sull'art. 21. Riformulata la frase con cui il riquadro iniziale escludeva l'obbligatorietà della valutazione («non si trattano dati di minori»): non reggeva, perché «nessun dato identificativo» non è «nessun dato». La DPIA è mantenuta come se fosse dovuta. **JGraph** (diagrams.net) aggiunto fra chi riceve qualcosa: caricando il visualizzatore delle mappe ne riceve l'indirizzo IP, anche degli studenti; era dichiarato nell'informativa e non qui. Regno Unito, decisione di adeguatezza del 28/6/2021. **Verifica anti-bot**: il filtro leggeva l'impronta del canvas, il modello della scheda video e l'impronta audio — valori che identificano un dispositivo — e nessuna regola di punteggio li usava; dal 22/9 non si raccolgono più, con una prova che dimostra che il punteggio è rimasto identico. Il registro del filtro perdeva in silenzio le righe con esito più lungo di sedici caratteri, fra cui blocchi e verifiche da threat intelligence: colonna allargata (migrazione 135), e una scrittura mancata ora finisce fra le anomalie. Il codice del QR della credenziale non entra più nel percorso registrato. Informativa 2.11, registro 1.12. | {{OPERATORE_NOME}} |
| 1.10 | 2026-09-21 | **Precisata la distinzione fra conservare e trattare.** Con `compilation_storage = 0` il salvataggio della compilazione viene rifiutato, ma la generazione del PDF passa comunque dal server: il testo vi arriva, viene trasformato e non vi resta. R20 riformulato di conseguenza; informativa §5.1 aggiunta, con la riga corrispondente nella tabella delle conservazioni. Misurata l'intera catena: il componente che compila sta sulla stessa macchina del sito (unità `tex-compile`, indirizzo interno non raggiungibile da internet, richieste firmate, `PrivateTmp=yes`), e il risultato non viene scritto su disco — PDF e pacchetto TeX si formano e si cancellano nel corso della stessa richiesta. **Nessun dato nuovo, nessun destinatario nuovo, nessuna misura tolta: modifica non sostanziale**, e per questo l'informativa resta alla versione 2.10 senza ripresentare il pannello dei consensi ai docenti. Nella stessa giornata Overleaf è uscito dall'applicazione (ADR-045): non era un destinatario dichiarato e non ha mai ricevuto nulla — il percorso era morto — ma il codice per farlo c'era, e adesso non c'è più. | {{OPERATORE_NOME}} |
| 1.9 | 2026-09-19 | §1, «Ambito di accesso degli studenti»: l'etichetta della credenziale di classe è composta dal sistema — classe, indirizzo, sigle delle materie scelte dal docente fra le sue — con un'aggiunta breve facoltativa, invece che scritta liberamente dal docente (ADR-044, migrazione 134); il modulo invita a non usare nomi di persone nell'aggiunta e nell'username. R20 aggiornato: il testo libero dell'etichetta si riduce all'aggiunta, senza sparire. Nessun dato nuovo, nessun destinatario nuovo. Modifica non sostanziale. | {{OPERATORE_NOME}} |
| 1.8 | 2026-09-15 | §1, «Le sezioni delle classi»: con «solo incaricati» anche l'anno di corso di un indirizzo si usa solo con l'incarico dell'amministratore (ADR-043, migrazione 133); prima gli anni erano liberi per tutti. Categorie dati: incarichi sulle classi, anni e sezioni. Nessun dato nuovo: cambia chi può usare un anno, non che cosa si raccoglie. | {{OPERATORE_NOME}} |
| 1.7 | 2026-09-15 | **§4 corretta**: la cifratura at-rest citava ancora una chiave per classe, la stessa rimossa dal §1 nella 1.6; la prova sui documenti di conformità cercava solo la grafia italiana e non l'aveva trovata. **§1 completata** con tre cose che la piattaforma fa e la DPIA non descriveva: i contenuti di un solo docente visibili senza accesso (migration 129; su pantedu.eu il Titolare); le sezioni delle classi per incarico (ADR-041, «solo incaricati»); i collegamenti a Google Drive (attivo dal 15 settembre) e a GitHub (disponibile, non in uso). **R21** aggiunto: copia in chiaro dei contenuti nel servizio del docente, con il limite dichiarato. R13 allineato. Nella stessa giornata, prima di ogni invio: precisato che le sezioni rese pubbliche sono quelle della barra laterale, non le sezioni delle classi. | {{OPERATORE_NOME}} |
| 1.6 | 2026-09-14 | Correzione descrittiva, §1: lo schema citava le tabelle della copia cifrata dei contenuti per classe (Phase 25.D6), mai attivata; tabelle vuote, rimosse il 14 settembre (ADR-037, migration 120). Al loro posto `content_publications`, dove il docente pubblica il contenuto. Nessun rischio nuovo, nessuna misura tolta. | {{OPERATORE_NOME}} |
| 1.5 | 2026-09-04 | **Rimossi i dati degli studenti**, a seguito dell'osservazione del DPO sul Regolamento cloud ACN (Decreto direttoriale n. 21007/24): di quei dati era Titolare l'Istituto, non l'autore, e l'infrastruttura di un privato non qualificato non può ospitarli. Nessuno studente reale si era mai registrato: registrazione disattivata, account di prova cancellati, `parent_consents` vuota. R7 e R11 non applicabili; **R20** aggiunto (dati di studenti in campi a testo libero, mitigazione organizzativa); R10 riscritto: lo shredding copre i contenuti, non le anagrafiche nei backup, che ruotavano fino a due anni (dal 4 settembre: un anno, con ri-cancellazione dopo ripristino). Modello di titolarità riscritto: Titolare dei dati dei docenti è l'autore; bozza di DPA ritirata. **Rilevato e corretto**: `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent in chiaro, `audit_activity_log` lo User-Agent, contro quanto dichiarato — logica di hash unificata in `RequestFingerprint`, righe esistenti convertite (migration 100); dichiarati i log di sicurezza che restano in chiaro (WAF, contatori, sessioni, navigazione) e il verbale di accettazione dei ToS. Purga di `privileged_access_log` e `crypto_access_log` portata da dieci ai cinque anni dichiarati. Log di accesso del server web disattivato. ToS 1.3 e AUP 1.2. **2026-09-04**: rilevato che la credenziale di classe (accesso «Anonima») metteva il grant in sessione ma nessun endpoint lo leggeva — l'accesso dichiarato non mostrava nulla; corretto (`ClassAccessGrant`, ADR-032): l'ospite con credenziale vede solo i contenuti pubblicati del docente della credenziale, per la sua classe; senza credenziale legge per id solo i contenuti pubblici. Il blocco copyright sulla condivisione esteso ai grant mirati verso singoli colleghi. **Risdoc**: le compilazioni dei modelli erano l'unico contenuto del docente in chiaro nel database — ora cifrate con la sua chiave come tutto il resto (migration 101, conversione delle righe esistenti con `tools/gdpr/encrypt_risdoc_compilations.php`); rimosso il Modulo di autorizzazione, residuo del modello con credenziali nominative per minorenni (migration 102); tolto il selettore «studente» dalla scheda di recupero; i campi riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio quando non esistono account studente (R20). **Seconda tornata del 4 settembre**: secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico al docente per ogni accesso amministrativo ai suoi contenuti (`CustodyNotifier`, `/me/custody-events`); impronta giornaliera a blocchi dei registri append-only, fuori dal server con il backup; timer per le cancellazioni art. 17 — `executeOverdue()` non era chiamato da nessun job; conservazioni riviste per l'art. 5(1)(e); Resend, servizio di posta, fra i responsabili; formula «nessun dato identificativo di studenti» al posto di «nessun dato personale», perché dei dati tecnici di connessione restano. | {{OPERATORE_NOME}} |
| 1.4 | 2026-09-02 | Copertura del registro verificata contro la produzione. Aggiunto **R19**: le operazioni di studenti e docenti non erano ricostruibili (file troncato a mille voci, sette giorni di storia). Nuovo `audit_activity_log` append-only; append-only esteso a `content_action_log` e `teacher_recovery_audit`; `RequiresAuditReason` passato a `enforce` (R9 riformulato); ricreate `content_versions` e `teacher_recovery_audit`, assenti in produzione. Registrato il consenso genitoriale **concesso**, che finora non lasciava traccia. | {{OPERATORE_NOME}} |
| 1.3 | 2026-09-02 | Rilettura integrale del pacchetto. Architettura corretta: era indicata Apache/PHP 8.3/MySQL 5.7, il server esegue **nginx 1.26, PHP 8.4, MariaDB 11.8**. Append-only sui log di audit: era dichiarato e non esisteva, ora applicato (vedi nota §4). R14 rivalutato a BASSO. Aggiunti recupero password e secondo fattore. | {{OPERATORE_NOME}} |
| 1.2 | 2026-09-01 | Aggiunta la funzione PDF-Import basata su modelli di IA: nuova § 3.1 e rischi **R15-R18** (invio di dati personali al fornitore, prompt injection, trasferimento extra-SEE, contenuto generato scambiato per materiale d’autore). Dichiarato il limite di `PiiMasker`, che redige codice fiscale ed email ma **non i nomi propri**: la difesa è organizzativa. Inquadramento AI Act in `docs/legal/ai-act-assessment.md` | {{OPERATORE_NOME}} |
| 1.1 | 2026-04-27 | Rimosso R6 (BES/DSA non è dato Art. 9, verificato sul codice) | {{OPERATORE_NOME}} |
| 1.0 | 2026-04-27 | Prima stesura | {{OPERATORE_NOME}} |

## Riferimenti

- ADR-006 (envelope encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance): `wiki/decisions/ADR-007-gdpr-compliance.md`
- KMS recovery runbook: `docs/security/operations/kms-recovery.md`
- Retention policy: `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- Compliance checklist: `docs/privacy/compliance_checklist.md` — documento storico del 16 aprile 2026, superato da questa valutazione e dal registro art. 30
- Migration 015 (consents): `database/migrations/015_consents_gdpr.sql`
- Migration 012 (encryption): `database/migrations/012_teacher_crypto.sql`
