---
tags:
    - documentazione/gdpr
    - phase/25.C8
date: 2026-09-04
tipo: registro-trattamenti
status: vigente
versione: 1.5
classification: ⚠️ INTERNAL — esibibile al Garante su richiesta
aliases: ["registro", "ROPA", "art-30"]
---

# Registro delle attività di trattamento — Art. 30 GDPR

> **Registro dovuto.** L'esenzione dell'Art. 30 §5 (meno di 250 dipendenti)
> non si applica: vale solo per trattamenti occasionali, e la gestione delle
> utenze e i registri di sicurezza non lo sono. Serve inoltre all'accountability
> (Art. 5 §2), all'incident response e all'esibizione al Garante su richiesta.
>
> **Non sono trattati dati identificativi di studenti**: registrazione disattivata
> dal 3 settembre 2026; in passato solo account di prova con dati fittizi,
> cancellati. Nessuno studente reale si è mai registrato. Delle sessioni con
> credenziale di classe restano i dati tecnici di connessione (B.6). Motivo: il Regolamento cloud ACN (Decreto
> direttoriale n. 21007/24) consente all'Istituto, Titolare di quei dati, di
> avvalersi solo di infrastrutture qualificate. Dettaglio in DPIA §1.
>
> ## NOTA su BES/DSA — NON dato sanitario Art. 9 in Pantedu
>
> Verificato sul codebase 2026-04-27: Pantedu NON traccia "studente X
> ha DSA". Il flag DSA su esercizi è metadata di contenuto del docente
> (variante adattata dell'esercizio), non identificativo dello studente.
> I contatori `nPrintDSA`/`nPrintDIS` su `infoVer` sono numeri aggregati
> per stampa, non PII. Trattamento Art. 9 NON applicabile a Pantedu.

## Sezione A — Identificazione del Titolare

| Campo | Valore |
|-------|--------|
| Denominazione | {{OPERATORE_NOME}} (persona fisica, professionista) |
| Sede | Italia; domicilio digitale (PEC): superadmin@pec.it |
| Email contatto | {{OPERATORE_EMAIL}} |
| Responsabile della protezione dei dati | Non designato: non obbligatorio ex Art. 37 (persona fisica, nessun monitoraggio su larga scala, nessun dato Art. 9). {{OPERATORE_EMAIL}} è il recapito per l'esercizio dei diritti |
| Telefono | Comunicato su richiesta motivata (non pubblicato) |
| Tipo organizzazione | Singolo professionista — piattaforma educativa per scuole superiori |

## Sezione B — Trattamenti effettuati

### B.1 — Registrazione e gestione utenze

| Campo | Valore |
|-------|--------|
| **Finalità** | Autenticazione, autorizzazione, gestione ruoli (docente / admin) |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto registrazione (TOS) |
| **Categorie interessati** | Docenti (di qualunque scuola; il Titolare è il gestore della piattaforma, non l'Istituto), admin. **Studenti: nessuno** (registrazione disattivata dal 2026-09-03; in passato solo account di prova con dati fittizi, cancellati) |
| **Categorie dati** | username, nome, cognome, email, password (hashed bcrypt), role, institute_id |
| **Destinatari** | Solo Titolare + sub-processor (hosting Hetzner, DE; Resend per le email di servizio) |
| **Trasferimenti extra-UE** | Hosting no (Hetzner, Germania). Le email di servizio passano da Resend (USA) con clausole contrattuali tipo (Sezione D) |
| **Tempi conservazione** | 730g inattività → anonimizzazione (`app/Config/retention.php`) |
| **Misure sicurezza** | bcrypt cost 12 + HTTPS + CSRF + rate-limit 10/min/IP |

### B.2 — Metadata varianti DSA/DIS su esercizi (NON Art. 9)

| Campo | Valore |
|-------|--------|
| **Finalità** | Permettere al docente di gestire varianti adattate dello stesso esercizio (es. formula esplicita per copia DSA, font dyslexia-friendly per copia DIS). Conta numeri di copie da stampare per ogni variante. |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto, parte della funzione core piattaforma |
| **Categorie interessati** | Docenti (autori dell'esercizio adattato) |
| **Categorie dati** | Checkbox HTML `dsa-checkbox` su `<li>` di esercizi (metadata contenuto), contatori `nPrintDSA`/`nPrintDIS` su `infoVer` (numeri aggregati copie stampa), marker inline `(*F*)` / `(*GF*)` |
| **NON trattati** | Identificativi studente (l'app non sa "Mario Rossi è DSA"), certificazioni mediche, PEI/PDP. Questi dati sono gestiti dalla scuola via registro elettronico esterno. |
| **Destinatari** | Solo docente proprietario |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Vita ciclo account docente, cifrato at-rest envelope encryption (insieme al body esercizio) |
| **Misure sicurezza** | AES-256-GCM body cifrato + per-teacher KEK + crypto-shredding Art. 17 (Phase 25.D) |

### B.3 — Contenuti didattici docenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Creazione, archiviazione, condivisione esercizi/verifiche/mappe/risdoc per attività didattica |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente |
| **Categorie interessati** | Docenti |
| **Categorie dati** | body_html, body_pt (Portable Text), metadata; nessun file caricato dall'utente, salvo le pagine PDF di libri di testo di PDF-Import (B.9, disattivata); compilazioni dei modelli risdoc (cifrate con la chiave del docente dal 2026-09-04, migration 101). Senza account studente, i campi dei modelli riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio. Per Istituto, su indicazione del suo DPO, le compilazioni dei modelli istituzionali possono non essere salvate affatto (`institutes.compilation_storage`, migration 103): restano nel browser del docente |
| **Destinatari** | Docente proprietario; sessioni con credenziale del docente, non nominative (per published_content) |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Vita ciclo account, soft-delete + crypto-shredding al Art. 17. Eventi sui contenuti (`content_action_log`) e uso delle chiavi di recupero (`teacher_recovery_audit`): 5 anni come gli accessi privilegiati, termine di prescrizione degli abusi che servono a ricostruire, con IP e User-Agent come hash (`tools/audit/purge_old_logs.php`) |
| **Misure sicurezza** | Envelope encryption (Phase 25.D), per-teacher KEK derivata da KMS_MASTER |

### B.4 — Pubblicazione contenuti agli studenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Distribuzione alla classe dei contenuti pubblicati dal docente, per consultazione: mappe, esercizi propri e, per gli esercizi tratti da libri di testo, il solo riferimento bibliografico con l'eventuale svolgimento del docente |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del contratto con il docente |
| **Categorie interessati** | Docenti pubblicatori. Le sessioni di consultazione avvengono con la credenziale del docente (`fm_teacher_access`), delimitabile per classe e **non nominativa**: nessun dato identificativo dello studente, sessione non associata a una persona; restano i dati tecnici di connessione (B.6) |
| **Categorie dati** | Copia cifrata del body docente in `published_content` |
| **Destinatari** | Sessioni con credenziale del docente (decifrabile via cookie auth scoped) |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Anno scolastico + 30g (poi `archived_at`); rotation classe_key annuale |
| **Misure sicurezza** | classe_keys decoupled da teacher KEK (Phase 25.D6) — sopravvive ad Art. 17 docente |

### B.5 — Audit log accessi privilegiati

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento abusi privilegi admin + accountability Art. 30 §1 |
| **Base giuridica** | Art. 6(1)(c) — obbligo legale + Art. 6(1)(f) — interesse legittimo sicurezza |
| **Categorie interessati** | Super-admin tecnici |
| **Categorie dati** | username, action, resourceType, resourceId, **reason** (obbligatoria), timestamp, ip_hash, ua_hash. Il nome utente resta per il termine del registro anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | 1825g (5 anni — termine prescrizione abusi amministrativi) |
| **Misure sicurezza** | **Append-only a livello di database** dal 2026-09-02: trigger che rifiutano modifiche e cancellazioni; l'utenza applicativa non può rimuoverli. Purga delle righe scadute riservata a `pantedu_maint`. Hash IP/UA; middleware RequiresAuditReason. Impronta giornaliera a blocchi (`tools/audit/export_audit_chain.php`), conservata fuori dal server con il backup cifrato e verificabile. Dettaglio e limite residuo: DPIA §4, nota sulle tre utenze |

### B.5-bis — Registro delle operazioni (tutti i ruoli)

| Campo | Valore |
|-------|--------|
| **Finalità** | Poter ricostruire chi ha fatto cosa sulla piattaforma: accountability Art. 5 §2, rilevamento abusi, gestione dei reclami |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo sicurezza; Art. 6(1)(c) per la parte di accountability |
| **Categorie interessati** | Tutti gli utenti autenticati (docenti, amministratori) e i tentativi non autenticati respinti. Le sessioni con credenziale del docente compaiono come anonime. Le righe riferite agli account studente di prova, cancellati il 2026-09-03, contengono solo dati fittizi |
| **Categorie dati** | username, ruolo effettivo, metodo HTTP, percorso, stato della risposta, esito, evento di dominio (iscrizione, consenso genitoriale), soggetto dell'operazione, dettagli, timestamp, ip_hash, user-agent (hash), request-id. Il nome utente resta per i due anni del registro anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | 2 anni (`tools/audit/purge_old_logs.php`) |
| **Misure sicurezza** | Append-only a livello di database (trigger `trg_append_only_audit_activity_log_*`); IP conservato come hash SHA-256, mai in chiaro; **minimizzazione: le letture andate a buon fine non vengono registrate**, solo le scritture, i tentativi respinti e gli eventi di dominio |

**Perché esiste (2026-09-02).** Fino a questa data le operazioni di studenti e
docenti vivevano solo in `access_log.json`, un file riscritto per intero a ogni
richiesta e troncato alle ultime mille voci: in produzione copriva sette
giorni, e i tre mesi precedenti erano stati scartati senza che nessuno potesse
accorgersene. Nessuna tabella registrava le azioni di uno studente. Il
registro dichiarava una capacità di ricostruzione che il sistema non aveva.

### B.6 — Logging IP / User-Agent (rilevamento anomalie)

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento brute-force, account takeover, anomalie comportamentali |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo (sicurezza); valutazione dell'interesse legittimo in DPIA §2 |
| **Categorie interessati** | Tutti gli utenti autenticati (e tentativi falliti) |
| **Categorie dati** | IP (hash SHA-256), User-Agent (hash SHA-256), timestamp, action |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | 365g (`app/Config/retention.php`) |
| **Misure sicurezza** | Hash unidirezionale (no IP/UA in chiaro), rotation log throttled |
| **Correzione (2026-09-03)** | `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent **in chiaro**, `audit_activity_log` lo User-Agent, contro quanto dichiarato qui e nell'Informativa. Logica di hash unificata (`RequestFingerprint`), righe esistenti convertite (migration 100). Log di accesso del server web disattivato dalla stessa data |
| **In chiaro, per la sola sicurezza** | `waf_logs` (30 giorni dal 2026-09-04, erano 90: è l'unico registro con l'IP in chiaro che tocca anche le sessioni con credenziale di classe), `waf_login_failures` (un'ora), `rate_limits` (pulizia giornaliera), `sessions` (sessioni attive), `access_log.json` (ultime mille voci di navigazione); `user_tos_acceptance` (IP e User-Agent quale prova dell'accettazione dei Termini, per la durata dell'account) |

### B.6-bis — Recupero password (link monouso via email)

| Campo | Valore |
|-------|--------|
| **Finalità** | Consentire a un utente che ha perso le credenziali di rientrare senza intervento manuale dell'amministratore |
| **Base giuridica** | Art. 6(1)(b) — esecuzione del servizio; Art. 6(1)(f) per la parte di prevenzione abusi |
| **Categorie interessati** | Docenti e amministratori registrati |
| **Categorie dati** | Riferimento all'utente, hash SHA-256 del token (mai il token), hash SHA-256 dell'IP richiedente, marche temporali di emissione/scadenza/uso |
| **Destinatari** | Solo Titolare. L'email col link transita dal provider di posta già elencato fra i sub-responsabili |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Riga conservata dopo l'uso (`used_at` valorizzato) come traccia verificabile di "chi ha reimpostato la mia password e quando"; purga insieme ai log d'accesso |
| **Misure sicurezza** | Token valido un'ora e una sola volta, conservato come hash: un dump del database non contiene link utilizzabili. IP come hash (§B.6). Intervallo minimo fra due invii. Risposta identica per indirizzo noto e sconosciuto, così da non poter scoprire chi ha un account. La reimpostazione **non** disattiva la verifica in due passaggi |

### B.6-ter — Secondo fattore di autenticazione via email

| Campo | Valore |
|-------|--------|
| **Finalità** | Verificare l'identità al momento dell'accesso, per gli utenti che scelgono l'email invece di un'app di autenticazione |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo alla sicurezza degli account; Art. 6(1)(b) per l'erogazione del servizio |
| **Categorie interessati** | Utenti che attivano la verifica in due passaggi con metodo «email» |
| **Categorie dati** | Riferimento all'utente, hash SHA-256 del codice (mai il codice), scopo (accesso o attivazione), marche temporali, numero di tentativi |
| **Destinatari** | Solo Titolare. Il messaggio col codice transita dal fornitore di posta già elencato in Sezione D |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Riga conservata dopo l'uso come traccia dell'accesso; purga insieme ai log d'accesso (365g) |
| **Misure sicurezza** | Codice a sei cifre valido dieci minuti e una sola volta, conservato come hash; cinque tentativi errati lo bruciano; scopi separati fra attivazione e accesso; non finisce in alcun log. **Limite dichiarato**: anche il recupero password passa dall'email, quindi chi controlla la casella ha entrambi i fattori — la pagina di scelta lo scrive e consiglia l'app |

### B.7 — Cookie analytics (opt-in)

| Campo | Valore |
|-------|--------|
| **Finalità** | Misurazione audience aggregata (no profiling individuale) |
| **Base giuridica** | Art. 6(1)(a) — consenso (cookie consent v2 Phase 25.C11) |
| **Categorie interessati** | Visitatori che accettano analytics |
| **Categorie dati** | Eventi navigazione anonimi |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO (servizio interno) |
| **Tempi conservazione** | Aggregati 90g, no row-level tracking |
| **Misure sicurezza** | Opt-in esplicito, revoca via cookie banner ogni momento |

### B.8 — Consenso parentale per minori (Art. 8) — **cessato il 2026-09-03**

| Campo | Valore |
|-------|--------|
| **Stato** | **Trattamento cessato il 2026-09-03**: nessun account studente, nessun consenso genitoriale. Mai attivato per studenti reali: solo prove interne con dati fittizi; tabella `parent_consents` vuota. Il codice resta nel software, disattivato: riattivabile solo in un'istanza condotta da un Istituto Titolare |
| **Finalità (storica)** | Conformità Art. 8 GDPR + art. 2-quinquies Codice (Italia: < 14 richiede consenso parentale) |
| **Base giuridica (storica)** | Art. 8 §1 — consenso parentale via doppio opt-in email |
| **Categorie interessati (storiche)** | Studenti < 14 anni + genitori |
| **Categorie dati (storiche)** | parent_email, parent_name (opzionale), token, timestamp confirm, IP/UA hash |
| **Tracce residue** | `consent_audit`: eventi pseudonimi (identificativo numerico dell'utente anonimizzato, tipo di evento, data, hash dell'IP), append-only, senza nomi né email; conservati dieci anni dall'evento (prescrizione ordinaria, `tools/audit/purge_old_logs.php`), poi purgati |

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
| **Trasferimenti extra-UE** | **SÌ** con fornitore cloud — clausole contrattuali tipo art. 46(2)(c). **NO** con Ollama locale. Fornitori in Sezione D; per un'istanza dello Scenario 3 il modello di DPA (`docs/legal/dpa_template.md`, § 7.1-bis) |
| **Tempi conservazione** | Artefatti di sessione (PNG derivati + JSON) cancellati dopo `PDF_IMPORT_RETENTION_DAYS` (default 7 giorni) o subito dopo l'inserimento. Gli esercizi inseriti seguono la conservazione di B.3 |
| **Misure sicurezza** | `PiiMasker` (redazione codice fiscale ed email prima dell'invio), `PromptGuard` (difesa da prompt injection sul testo derivato dal PDF), `SsrfGuard` (allowlist host), chiavi API cifrate con la KEK del docente, budget token per docente, storage di sessione cifrato AES-256-GCM |
| **Limite dichiarato** | `PiiMasker` opera su pattern deterministici (regex) e **non riconosce i nomi propri**. La difesa contro nomi presenti su un frontespizio è organizzativa, non tecnica |
| **Marcatura output** | Il contenuto generato è marcato ex art. 50(2) Reg. (UE) 2024/1689 |

## Sezione C — Categorie particolari Art. 9

**Nessun trattamento Art. 9 attivo in Pantedu.** Vedi NOTA in cima al documento. Verificato 2026-04-27 sul codebase: app processa solo metadata di contenuto del docente + contatori numerici copie, mai dati sanitari individuali studente.

Se in futuro si introducesse tracking studente-DSA (es. profilo studente con flag DSA personale), questo trattamento andrebbe aggiunto qui con base giuridica Art. 9(2)(a) consenso esplicito separato + DPIA aggiornata.

## Sezione D — Sub-processors

| Sub-processor | Servizio | Localizzazione | DPA |
|---------------|----------|----------------|-----|
| Hetzner Online GmbH | Hosting applicativo (server, DB, storage) | Germania (UE) | ✅ DPA Art. 28 GDPR di Hetzner (Data Processing Agreement standard, accettato all'attivazione del servizio). EU only. |
| Cloudflare, Inc. | CDN / edge security (terminazione TLS, WAF di bordo) | Rete globale; per il traffico IT instradamento UE | ✅ DPA Cloudflare + SCC per eventuali trasferimenti (Capo V GDPR) + certificazione EU-US Data Privacy Framework. |
| Backblaze Inc. (B2) | Backup off-site (dati **cifrati lato client** prima dell'upload) | UE (regione europea) | ✅ DPA Backblaze; i dati sono cifrati prima dell'invio → Backblaze non accede al contenuto. |
| Resend, Inc. | Invio delle email di servizio (conferma di registrazione, recupero password, codice di verifica via email, notifiche di takedown): riceve indirizzo, nome e testo del messaggio, mai contenuti didattici | USA | ✅ DPA di Resend con clausole contrattuali tipo (Capo V GDPR). Il codice del secondo fattore via email vi transita (B.6-ter) |
| Google LLC (eventuale, opzionale) | OAuth login + Drive integration — solo su **opt-in** esplicito del docente | USA — SCC necessarie | NA finché non attivato dal docente |
| Fornitori di modelli di IA (eventuale, opzionale) | PDF-Import — solo se `PDF_IMPORT_ENABLED=true` **e** con provider cloud. Anthropic PBC / OpenAI L.L.C. | USA — SCC necessarie | NA finché la funzione non è attivata. Dettaglio in B.9; l'alternativa **Ollama locale** non comporta alcun sub-responsabile |

> **Non sub-responsabili del trattamento**: **Porkbun LLC** (registrar del dominio / gestione DNS) e l'eventuale provider della **PEC personale** del titolare non trattano dati personali degli interessati del servizio → non sono sub-responsabili ex Art. 28.

### Hetzner — dettaglio DPA

- **Base giuridica**: Data Processing Agreement Art. 28 GDPR di Hetzner Online GmbH, accettato all'attivazione del servizio.
- **Localizzazione**: data center in Germania (UE); nessun trasferimento extra-UE per l'hosting.
- **Cancellazione fine contratto**: cancellazione/restituzione dati a scelta del Cliente.
- **Breach notification**: secondo le tempistiche normative (→ Art. 33 §2 GDPR).

## Sezione E — Eventi storici

### Aggiornamenti del registro

| Data | Versione | Modifica | Operatore |
|------|----------|----------|-----------|
| 2026-04-27 | 1.0 | Prima compilazione (Phase 25.C8) | {{OPERATORE_NOME}} |
| 2026-04-29 | 1.1 | Sezione D: DPA sub-processor hosting archiviato + mappato vs Art. 28 §3 | {{OPERATORE_NOME}} |
| 2026-06-24 | 1.2 | Migrazione infrastruttura: hosting → **Hetzner** (DE, UE), backup → **Backblaze B2**, edge → **Cloudflare**; registrar → **Porkbun** (non sub-responsabile). Rimosso hosting legacy come hosting (resta solo eventuale PEC personale del titolare, fuori dal trattamento). | {{OPERATORE_NOME}} |
| 2026-09-01 | 1.3 | Aggiunto **B.9** — estrazione esercizi da PDF tramite modelli di IA (PDF-Import, disattivata di default). Sezione D: aggiunti i fornitori di modelli di IA come sub-responsabili eventuali. Allineato l’elenco con il DPA art. 28, che ne riportava solo due su cinque. | {{OPERATORE_NOME}} |
| 2026-09-02 | 1.4 | Aggiunti **B.6-bis** (recupero password) e **B.6-ter** (secondo fattore via email). B.5: l'append-only era dichiarato come fatto e non esisteva — applicato con trigger, con utenza separata per la sola purga. Tolti il segnaposto del telefono e il riferimento all'informativa come «PENDING». | {{OPERATORE_NOME}} |
| 2026-09-04 | 1.5 | **Cessato ogni trattamento di dati di studenti**, mai avvenuto per studenti reali (Regolamento cloud ACN: Titolare di quei dati sarebbe stato l'Istituto, e l'infrastruttura di un privato non qualificato non può ospitarli). B.1, B.3, B.4, B.5-bis riscritti senza studenti; **B.8 cessato**. B.5/B.6: quattro registri conservavano IP e/o UA in chiaro contro quanto dichiarato — corretti e convertiti (migration 100); elencati i log di sicurezza che restano in chiaro; purga di `privileged_access_log` e `crypto_access_log` portata da dieci ai cinque anni dichiarati; log del server web disattivato. Titolarità dei dati dei docenti chiarita: il gestore, non l'Istituto. ToS 1.3, AUP 1.2 (poi 1.3 il 4 settembre, per le sole note di stato). Sezione D: aggiunto **Resend**, il servizio di posta transazionale, citato in B.6-bis e B.6-ter ma assente dall'elenco. Conservazioni riviste per l'art. 5(1)(e): eventi sui contenuti e chiavi di recupero da sette a cinque anni, con la ragione scritta; `consent_audit` da «permanente» a dieci anni dall'evento; `waf_logs` da 90 a 30 giorni; copie annuali di backup da due a una, con ri-cancellazione dopo ogni ripristino. Secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico all'interessato degli accessi amministrativi ai suoi contenuti; impronta giornaliera dei registri; timer per le cancellazioni art. 17, che mancava. | {{OPERATORE_NOME}} |

### Data breach notificati

| Data | Tipo | Esposto | Notifica Garante (72h) | Notifica utenti | Risoluzione |
|------|------|---------|-----------------------|-----------------|-------------|
| _Nessuno_ | — | — | — | — | — |

## Sezione F — Manutenzione

- **Revisione obbligatoria**: annuale (gennaio di ogni anno)
- **Trigger update straordinario**: nuova feature Art. 9, nuovo sub-processor, modifica architettura crypto, data breach
- **Esibizione**: il registro va prodotto al Garante entro 72h da richiesta (Art. 30 §4)
- **Custodia**: copia firmata Titolare in `docs/privacy/registro-trattamenti.md` (versionato git private repo) + copia stampata in cassaforte

## Riferimenti

- DPIA: `docs/privacy/dpia.md`
- Informativa: `docs/privacy/informativa.md` v2.3 (vigente, pubblicata su `/privacy/informativa`)
- Retention policy: `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- ADR-006 (encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance): `wiki/decisions/ADR-007-gdpr-compliance.md` (Phase 25.C15)
- Compliance checklist: `docs/privacy/compliance_checklist.md`
