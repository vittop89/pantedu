---
tags:
    - documentazione/gdpr
    - phase/25.C9
    - sicurezza
date: 2026-09-04
tipo: dpia
status: bozza-completa
versione: 1.5
classification: ⚠️ INTERNAL
aliases: ["dpia", "valutazione-impatto"]
---

# DPIA — Valutazione d'Impatto Privacy

> **Art. 35 GDPR**: la DPIA è obbligatoria quando il trattamento può presentare
> un rischio elevato per i diritti e le libertà delle persone fisiche, in
> particolare quando si trattano **dati di minori** (Art. 8).
>
> **Dal 3 settembre 2026 Pantedu non tratta dati identificativi di studenti**
> (vedi §1, «Perché non ci sono dati di studenti»). La DPIA resta redatta come
> buona pratica di accountability (Art. 24) per i dati dei docenti e come
> registro delle decisioni prese: non è obbligatoria, perché non si trattano
> dati Art. 9 né dati di minori.
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
> 3. **Nome studente** sull'eventuale copia stampata: scritto a mano dal
>    docente DOPO la stampa, non registrato in DB.
>
> I dati sanitari veri (PEI/PDP, certificazioni mediche) sono gestiti dalla
> scuola tramite registro elettronico esterno + cartaceo. Pantedu non
> riceve né elabora questi dati. **Trattamento Art. 9 NON applicabile.**

## 1. Descrizione sistematica del trattamento (Art. 35 §7 a)

### Titolare del trattamento — modello di titolarità (aggiornato 2026-09-03)
- **Dati dei docenti** (l'autore e i colleghi di qualunque scuola che si iscrivono): **Titolare è {{OPERATORE_NOME}}** (Art. 4(7)). L'iscrizione è volontaria e non disposta da un Istituto, i dati sono conferiti dall'interessato, finalità e mezzi li determina l'autore. Basi: Art. 6(1)(b) per l'account, Art. 6(1)(f) per i registri di sicurezza. La piattaforma **non è uno strumento d'Istituto**; la titolarità di un Istituto sui dati dei propri docenti attiene alla gestione del personale e resta distinta.
- **Dati di studenti**: **nessun dato identificativo**, e nessuno di studenti reali in passato: gli account studente esistiti fino al 3 settembre 2026 erano account di prova con dati fittizi. Delle sessioni con credenziale di classe restano i dati tecnici di connessione: l'IP nel log di sicurezza per trenta giorni, altrove come hash (§2). Se studenti reali si fossero registrati, di quei dati sarebbe stato Titolare l'**Istituto**, non l'autore, che li avrebbe trattati per conto dell'Istituto senza accordo ex Art. 28. Le versioni precedenti di questa DPIA indicavano l'autore come Titolare: era un errore di impianto.
- **Adozione formale da parte di un Istituto** (con dati di studenti): possibile solo in un'istanza condotta dall'Istituto o da un fornitore qualificato ACN, mai su infrastruttura dell'autore (vedi sotto). In tal caso il Titolare è l'Istituto e la DPIA relativa è a suo carico. La bozza di DPA che prevedeva l'autore come Responsabile è ritirata.

### Perché non ci sono dati di studenti (2026-09-03)
Il Regolamento cloud ACN (Decreto direttoriale n. 21007/24 del 27 giugno 2024, applicabile dal 1° agosto 2024, attuativo dell'art. 33-septies del D.L. 179/2012) consente alle pubbliche amministrazioni, scuole comprese, di avvalersi soltanto di infrastrutture e servizi cloud **qualificati**, e pone la qualificazione a carico del fornitore (art. 17). Un docente che conduca personalmente un server non può qualificarsi. Dati di cui l'Istituto è Titolare non possono quindi risiedere su questa infrastruttura, a qualunque titolo l'autore li trattasse. Il presupposto è stato rimosso prima che si concretizzasse: registrazione degli studenti disattivata; account di prova cancellati; tabelle degli studenti e `parent_consents` vuote. Nessuna copia di sicurezza ha mai contenuto dati di studenti reali (vedi R10). Viene meno anche il tema dell'età e del consenso dei minori (Art. 8 GDPR; art. 2-quinquies del Codice).

### Ambito di accesso degli studenti (aggiornato 2026-09-03)
Gli studenti consultano i contenuti che il docente pubblica per la classe — mappe, esercizi propri e, per gli esercizi tratti da libri protetti da diritto d'autore, il **solo riferimento bibliografico** (badge + fonte) con l'eventuale svolgimento del docente, **mai** traccia/soluzioni del libro — con una **credenziale del docente**: grant tecnico legato all'`id` del docente (`fm_teacher_access`), delimitabile per indirizzo e classe, **non nominativo**. La sessione non è associata a una persona; nel registro delle operazioni compare come anonima; l'IP resta nel solo log di sicurezza per trenta giorni, altrove come hash. Nessuna creazione/modifica di contenuti da parte dello studente. La credenziale la crea il docente nel proprio pannello (etichetta, password conservata come hash, ambito facoltativo per indirizzo e classe), la comunica in classe e può disattivarla o sostituirla in qualunque momento.

Le modalità di registrazione studente presenti nel software — **Completa** (con data di nascita e consenso genitoriale per i minori di 14 anni) e **Ridotta** — sono **disattivate e non attivabili su pantedu.eu**. Restano nel codice per l'ipotesi di un'istanza condotta da un Istituto Titolare, che in quel caso ne sarebbe responsabile con propria DPIA. Il software distingue tre scenari di esercizio (ADR-032: uso personale, colleghi, Istituto), commutabili dal pannello di amministrazione con motivazione a registro; lo scenario Istituto è attivabile solo su un'istanza che dichiari in configurazione l'infrastruttura qualificata ACN, e in quello scenario `/privacy/informativa` serve un'informativa distinta con l'Istituto come Titolare.

**Scoping**: istituto + indirizzo + classe del docente delimitano a quali credenziali sono visibili i contenuti pubblicati.

### Finalità del trattamento
1. **Didattica**: docenti creano contenuti (esercizi, verifiche, mappe, laboratori, risdoc) e li pubblicano alle proprie classi, che li consultano con la credenziale del docente. Possono condividerli con colleghi dello stesso istituto, su scelta esplicita e mai per materiale derivato da libri di testo (blocco nel software, ToS §2.1.1); la condivisione fra colleghi è distinta dalla pubblicazione agli studenti.
2. **Versioni adattate**: i docenti possono segnare che un esercizio ha varianti per copie DSA/DIS — è un **metadata di contenuto**, non un identificativo dello studente. NON costituisce trattamento dato sanitario Art. 9 (vedi NOTA sopra).
3. **Audit operativo**: log accessi privilegiati per rilevamento abusi (Art. 32 sicurezza).
4. **Minimizzazione**: dati raccolti = solo quelli necessari per le finalità sopra.

### Categorie di dati e interessati

| Categoria | Tipologia | Base giuridica | Periodo conservazione |
|-----------|-----------|----------------|----------------------|
| Identificazione docente (username, nome, cognome, email) | Dato comune | Art. 6(1)(b) — esecuzione contratto registrazione | 730g inattività → anonimizzazione (vedi `app/Config/retention.php`) |
| Docente — scope (istituto, indirizzo, classe) | Dato comune | Art. 6(1)(b) — erogazione servizio + delimitazione della visibilità | 730g inattività → anonimizzazione |
| Studenti (username, nome, cognome, email, data di nascita), genitori (email, nome) | — | **Nessun trattamento**: registrazione disattivata dal 2026-09-03; in passato solo account di prova con dati fittizi, cancellati | — |
| Flag DSA/DIS su esercizi (metadata contenuto) | Dato comune (proprietà intellettuale del docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account docente, cifrato at-rest (Phase 25.D) |
| Contatori numerici copie stampa (nPrintDSA, nPrintDIS) | Aggregato non identificativo | Art. 6(1)(b) | Vita ciclo verifica |
| IP address + User-Agent (hash) | Quasi-identificativo | Art. 6(1)(f) — interesse legittimo (sicurezza) | 365g (access_log) / 2 anni (audit_activity_log) / 5 anni (privileged_access_log, content_action_log, teacher_recovery_audit) / 30g in chiaro (waf_logs) |
| Contenuti didattici (esercizi, body_html, body_pt) | Dato comune (proprietà intellettuale docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account, cifrato at-rest (Phase 25.D) |
| Log audit operativo | Dato comune | Art. 6(1)(f) | 1825g (5 anni) |

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
nginx (HSTS) → CSP via SecurityHeadersMiddleware (PHP, single-source)
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
    │       ├── teacher_content (cifrato Phase 25.D — body_html_ct + body_pt_ct)
    │       ├── teacher_keys (wrapped_kek per docente)
    │       ├── consents + consent_audit (Phase 25.C1)
    │       ├── deletion_requests (Phase 25.C4)
    │       ├── parent_consents (Phase 25.C7 — vuota dal 2026-09-03)
    │       └── classe_keys + published_content (Phase 25.D6)
    │
    ├── KMS_MASTER_KEY (env var, .env.local gitignored)
    │       └── 3 copie off-line indipendenti (vedi §KMS_MASTER_KEY backup)
    │
    └── storage/logs (rotation throttled, retention configurata)
```

## 2. Necessità + proporzionalità (Art. 35 §7 b)

### Necessità

Ogni dato raccolto ha finalità motivata documentata:
- **Username/email**: necessari per autenticazione (Art. 6(1)(b)).
- **Flag DSA/DIS su esercizi**: metadata di contenuto del docente — permette di mantenere varianti adattate dello stesso esercizio (es. con formula esplicita per copia DSA). NON è dato dello studente. Art. 6(1)(b) sufficiente.
- **IP/UA log**: necessari per rilevare accessi sospetti (brute force, account takeover) → Art. 32 sicurezza.

### Proporzionalità (Art. 5 §1 c — minimizzazione)

- Password: bcrypt cost 12 (no plaintext mai).
- IP address: hash SHA-256 (32 byte grezzi): confrontabile con un IP noto, non ricostruibile dal registro.
- User-Agent: hash SHA-256 (audit traceable, no fingerprinting).
- **Corretto il 2026-09-03**: `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent **in chiaro**, e `audit_activity_log` lo User-Agent, contro quanto dichiarato qui, nell'Informativa §3.4 e nel Registro B.5. La logica di hash è ora unica (`App\Services\Audit\RequestFingerprint`) e le righe esistenti sono state convertite con la stessa formula (migration `100_audit_ip_ua_hash.sql`). Restano in chiaro, per la sola sicurezza e a conservazione breve: `waf_logs` (30 giorni, dal 2026-09-04; erano 90), `waf_login_failures` (un'ora), `rate_limits` (pulizia giornaliera), `sessions` (sessioni attive), `access_log.json` (ultime mille voci di navigazione); e il verbale di accettazione dei Termini, quale prova dell'accettazione. Il log di accesso del server web è disattivato (`access_log off`).
- Flag DSA/DIS su esercizi (metadata): cifrato at-rest insieme al body del docente (Phase 25.D), accessibile solo al docente proprietario.
- Body docente (esercizi/verifiche/mappe): cifrato at-rest envelope encryption.
- Backup: cifrati **lato client prima dell'upload** (GPG/age) verso Backblaze B2 (UE); transport TLS. Rotazione a livelli: 3 giornalieri, 4 settimanali, 6 mensili, 1 annuale — una copia può sopravvivere fino a un anno. Dopo ogni ripristino le cancellazioni successive alla copia vengono rieseguite (`docs/security/operations/restore-reerasure.md`). Nessuna copia ha mai contenuto dati di studenti reali (vedi R10). L'hosting applicativo è su Hetzner (Germania, UE).

### Valutazione dell'interesse legittimo — registri di sicurezza (Art. 6(1)(f))

- **Interesse**: proteggere la piattaforma e gli account da accessi abusivi, forza bruta e furto di sessione, e poter ricostruire chi ha fatto cosa in caso di reclamo o incidente (considerando 49; Art. 32; Art. 5 §2). Interesse reale, attuale e lecito.
- **Necessità**: senza indirizzo IP e User-Agent non si distingue un accesso legittimo da uno anomalo né si blocca chi insiste. L'alternativa meno invasiva è già adottata: hash non reversibile in tutti i registri di audit; IP in chiaro solo dove serve a bloccare (WAF, contatori) e per pochi giorni.
- **Bilanciamento**: interessati adulti e professionisti, iscritti volontariamente e informati (Informativa §3.4); aspettativa ragionevole che un servizio online conservi registri di sicurezza; nessuna profilazione, nessuna decisione automatizzata, nessun uso per finalità diverse; impatto contenuto dall'hash e dalle conservazioni definite (trenta giorni in chiaro; due e cinque anni come hash).
- **Garanzie**: registri append-only, accesso al solo Titolare con motivazione a registro, impronta giornaliera fuori dal server, diritto di opposizione (Art. 21) via {{OPERATORE_EMAIL}}, informativa che elenca ogni archivio in chiaro.

Esito: l'interesse legittimo prevale, e il trattamento resta limitato a quanto sopra.

Trattamenti **rifiutati per proporzionalità insufficiente**:
- Profilazione comportamentale studente (es. "tempo permanenza pagina") — fuori scope Phase 25.
- Geo-location precisa (latitudine/longitudine) — non necessaria.
- Dati biometrici facciali / riconoscimento foto — esplicitamente esclusi.

## 3. Valutazione dei rischi (Art. 35 §7 c)

### Matrice di rischio

| # | Trattamento / scenario | Probabilità | Gravità | Mitigazione | Rischio residuo |
|---|------------------------|-------------|---------|-------------|-----------------|
| R1 | Accesso docente a studenti altri docenti | Bassa | Alta | `Permission::canView` Phase 21 + AclPolicy + 4-teacher concurrent isolation E2E (Phase 25.B7) | **BASSO** ✅ |
| R2 | Super-admin curioso legge body docenti | Media | Alta | Phase 25.D envelope encryption + crypto_access_log + RequiresAuditReason middleware (Phase 25.B4); dal 2026-09-04 avviso automatico al docente per ogni accesso amministrativo, impronta giornaliera dei registri fuori dal server, secondo fattore obbligatorio per gli amministratori | **BASSO** ✅ |
| R3 | Breach DB dump | Media | Alta | Phase 25.D body cifrato + KMS_MASTER off-line backup: 3 copie indipendenti (vedi §KMS_MASTER_KEY backup) | **BASSO** ✅ |
| R4 | Brute-force login | Alta | Media | Rate-limit 10/min/IP (Phase 25.B5) + bcrypt cost 12 | **BASSO** ✅ |
| R5 | XSS / CSRF su form | Media | Alta | CSP (Track 7): `'unsafe-eval'` rimosso, handler inline bonificati, `strict` con nonce+strict-dynamic pronta (toggle `/admin/waf/config`); + SameSite=Lax + CSRF middleware (token via header) + CSRF token da fonte client unica `dom-utils.fetchCsrf`; superficie ridotta: zero jQuery + zero CSS-in-JS a runtime, escaping HTML centralizzato (2026-06-05) | **BASSO** ✅ |
| R6 | ~~Rivelazione BES/DSA senza consenso esplicito~~ | — | — | RIMOSSO 2026-04-27: BES/DSA non è trattato come dato Art. 9 in Pantedu (vedi NOTA in cima a §1). Solo metadata di contenuto del docente. | **N/A** ✅ |
| R7 | ~~Trattamento dati minori senza base/consenso (Art. 8)~~ | — | — | **NON APPLICABILE dal 2026-09-03**: nessun dato identificativo di studenti; accesso con credenziale del docente non nominativa. Le modalità Completa/Ridotta restano nel software, disattivate: riattivabili solo in un'istanza con un Istituto Titolare, con DPIA a suo carico | **N/A** ✅ |
| R8 | Mancato esercizio diritto oblio Art. 17 | Media | Alta | Phase 25.C4: self-service /me/request-deletion + crypto-shredding O(1); esecuzione giornaliera dal 2026-09-04. Il nome utente resta nei registri append-only per il loro termine (Art. 17 §3, lett. b ed e), scollegato da ogni altro dato dopo l'anonimizzazione: dichiarato nell'Informativa §5 | **BASSO** ✅ |
| R9 | Mancata tracciabilità mutazioni admin | Bassa | Media | `RequiresAuditReason` in modalità **enforce** dal 2026-09-02: senza una motivazione di almeno dieci caratteri la mutazione è respinta con 400. Prima girava in `warn` e passava comunque: 178 righe su 216 in produzione avevano come motivo il segnaposto `MISSING_OR_INVALID_AUDIT_REASON`. `privileged_access_log` immutabile | **BASSO** ✅ |
| R19 | Impossibile ricostruire cosa ha fatto un utente | Media | Media | `audit_activity_log` (2026-09-02), append-only, due anni. Copre le scritture, i tentativi respinti e gli eventi di dominio di ogni ruolo. Prima l'unica traccia era un file troncato a mille voci, che in produzione copriva sette giorni e aveva già scartato tre mesi. Le sessioni con credenziale del docente vi compaiono come anonime | **BASSO** ✅ |
| R10 | Cancellazione utente lascia tracce in backup | Media | Media | Crypto-shredding rende illeggibili i **contenuti** anche nei backup (KEK shred = body unreadable). Le righe anagrafiche (nome, email) restano invece nei backup fino alla rotazione, al massimo un anno, e dopo ogni ripristino vengono ri-cancellate secondo `docs/security/operations/restore-reerasure.md`: la versione precedente di questa riga taceva il limite. Gli account studente di prova cancellati il 2026-09-03 contenevano dati fittizi: nessuna distruzione di copie necessaria | **BASSO** ✅ per i contenuti — **limite dichiarato** per le anagrafiche dei docenti |
| R11 | ~~Phishing parent_email per fake consent~~ | — | — | **NON APPLICABILE dal 2026-09-03**: nessun consenso genitoriale raccolto | **N/A** ✅ |
| R20 | Dati personali di studenti inseriti dai docenti in campi a testo libero (titoli, note, immagini) | Media | Media | Nei campi strutturati nessun alunno è identificabile per costruzione; nei modelli risdoc i campi che per nome si riferiscono a studenti o genitori vengono **svuotati dal server prima del salvataggio** quando non esistono account studente (`CompilationScrubber`, 2026-09-04); il Modulo di autorizzazione, che li aveva, è stato rimosso; per Istituto, su indicazione del suo DPO, la compilazione dei modelli istituzionali può non essere salvata sul server (`institutes.compilation_storage`): la bozza resta nel browser del docente e sul server resta il solo modello. Nei campi liberi la difesa è **organizzativa**: divieto in ToS §2.4 e AUP, responsabilità del docente (ToS §4), Notice & Takedown. Se accadesse, il docente agirebbe come autorizzato del proprio Istituto e i dati andrebbero rimossi | **MEDIO** ⚠️ — mitigazione organizzativa sul solo testo libero, accettato |
| R12 | Perdita KMS_MASTER_KEY | Bassa | **Critica** | docs/security/operations/kms-recovery.md: 3 copie con modalità di guasto indipendenti (supporto fisico off-line + 2 contenitori cifrati su provider distinti) + drill semestrale registrato in crypto_custody_events | **MEDIO** ⚠️ |
| R13 | Cloud extra-UE | Bassa | Media | Hosting Hetzner (DE) + backup Backblaze B2 (NL), tutto in UE. Extra-UE con clausole contrattuali tipo: Resend (email di servizio: indirizzo, nome, testo del messaggio, codice del secondo fattore via email) e, su opt-in del docente, Google | **BASSO** ✅ |
| R14 | Ingegneria sociale → reset password ottenuto convincendo l'amministratore | Bassa | Alta | **Recupero self-service** (link monouso via email, un'ora, token conservato come hash): la richiesta non passa piu' da una persona da convincere, e l'amministratore non ha piu' motivo di reimpostare password su richiesta. **2FA TOTP** verificata al login e imponibile per ruolo: chi ottenesse la password non entra comunque. La reimpostazione **non** disattiva il secondo fattore | **BASSO** ✅ |
| R15 | PDF-Import: dati personali in un PDF caricato finiscono al fornitore di modelli | Media | Media | `PiiMasker` redige codice fiscale ed email; storage di sessione cifrato con TTL; vincolo d'uso "solo libri di testo" in AUP e `ai-literacy.md`. **La redazione non copre i nomi propri** | **MEDIO** ⚠️ (vedi §3.1) |
| R16 | PDF-Import: prompt injection da PDF malevolo | Bassa | Media | `PromptGuard` neutralizza i marcatori d'istruzione e incapsula il testo derivato in un blocco dati delimitato; l'output del modello è sempre trattato come dato non fidato e riparsato | **BASSO** ✅ |
| R17 | PDF-Import: trasferimento extra-SEE verso il fornitore | Media | Media | Funzione disattivata di default; SCC art. 46(2)(c) dei fornitori; alternativa **Ollama locale** senza alcun trasferimento. Vedi Registro art. 30, B.9 e Sezione D | **MEDIO** ⚠️ con provider cloud — **BASSO** ✅ con Ollama |
| R18 | Contenuto generato da IA scambiato per materiale d'autore | Media | Bassa | Marcatura ex art. 50(2) AI Act (attributi DOM + `<meta>` + JSON-LD + sigla visibile) e revisione obbligatoria del docente prima dell'inserimento | **BASSO** ✅ |

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

## 4. Misure tecniche e organizzative (Art. 32)

### Tecniche (implementate)

- ✅ **Cifratura at-rest** (Phase 25.D): AES-256-GCM envelope encryption con HKDF-SHA256, per-teacher KEK + per-classe class_key.
- ✅ **Cifratura in transito**: HTTPS obbligatorio (HSTS 1y + includeSubDomains).
- ✅ **Hashing password robusto**: bcrypt cost 12, con rifiuto delle password comparse in violazioni note (Have I Been Pwned, k-anonymity: alla API arrivano i primi caratteri dell'hash, mai la password).
- ✅ **Verifica in due passaggi**: TOTP o codice via email, a scelta dell'utente; codici di backup monouso e limite di tentativi. **Obbligatoria per i ruoli amministrativi dal 2026-09-04** (decisione a registro), facoltativa per i docenti. Il metodo via email e' dichiarato piu' debole all'utente che lo sceglie.
- ✅ **Avviso all'interessato** (2026-09-04): ogni accesso amministrativo ai contenuti cifrati di un docente (`kek_emergency_access`, `data_recovered`) gli viene notificato via email nel momento in cui viene registrato (`CustodyNotifier`), e l'elenco è consultabile da `/me/custody-events`. Le richieste dell'autorità restano fuori dall'automatismo: un provvedimento può vietare di informare l'interessato.
- ✅ **Impronta giornaliera dei registri** (2026-09-04): `tools/audit/export_audit_chain.php` riduce ogni giorno le righe nuove dei sette registri append-only a blocchi di cinquecento con hash concatenati; l'impronta finisce nella cartella che il backup cifrato porta fuori dal server, e `--verify` ricalcola i blocchi. Chi amministra il server può ancora riscrivere una riga, non senza che la verifica lo dica. L'immutabilità della copia remota richiede l'object lock sul bucket, proprietà del bucket da attivare una volta.
- ✅ **Cancellazioni art. 17 eseguite ogni giorno** (2026-09-04, `pantedu-gdpr-deletions.timer`): fino a quella data `executeOverdue()` esisteva e nessun job lo chiamava — una richiesta confermata sarebbe rimasta in attesa per sempre. Nessuna richiesta reale era pendente.
- ✅ **CSRF protection**: token per sessione, middleware `csrf` su tutte le mutazioni; recupero token lato client da **fonte unica centralizzata** (`dom-utils.fetchCsrf`, 2026-06-05).
- ✅ **WAF fail-closed JSON-aware** (2026-06-05): per le richieste API/XHR challenge e block rispondono in JSON 403 mantenendo il blocco (non degradano il front-end); client auto-recupera la verifica via reload.
- ✅ **Superficie XSS ridotta** (2026-06-05): zero jQuery e zero CSS-in-JS a runtime, escaping HTML centralizzato (`esc`/`escAttr`).
- ✅ **Rate-limiting**: per-bucket (login 10/min IP, content 60/min teacher, deletion 5/min).
- ✅ **Audit log append-only** (2026-09-02): `privileged_access_log`, `crypto_access_log`, `consent_audit`, `crypto_custody_events`, `content_action_log`, `audit_activity_log` e `teacher_recovery_audit` rifiutano modifiche e cancellazioni a livello di database. Purga delle sole righe scadute riservata all'utenza dei job pianificati. Vedi nota sotto.
- ✅ **Registro delle operazioni** (2026-09-02): `audit_activity_log` conserva per due anni le operazioni di tutti i ruoli con metodo, percorso, esito e attore reale (le sessioni con credenziale del docente compaiono come anonime). Prima esisteva solo `access_log.json`, un file troncato alle ultime mille voci: in produzione copriva sette giorni. Registra le scritture, i tentativi respinti e gli eventi di dominio; le letture riuscite restano fuori per minimizzazione. IP conservato come hash.
- ✅ **Pseudonimizzazione**: hash SHA-256 di IP e User-Agent in tutti i registri di audit, con logica unica in `RequestFingerprint` (2026-09-03: quattro registri erano in chiaro, convertiti dalla migration 100 — vedi §2). In chiaro solo i log di sicurezza a conservazione breve e il verbale di accettazione dei Termini.
- ✅ **Crypto-shredding O(1)**: Art. 17 GDPR efficiente (DELETE 1 row teacher_keys → all body unreadable).
- ✅ **Governance chiave master (opzionale, su richiesta)**: la copia di sicurezza della `KMS_MASTER_KEY` può essere frazionata con **Shamir Secret Sharing** (es. 3-su-5 o 2-su-3) tra **custodi distinti** scelti dal Titolare; su richiesta, uno di essi può essere persona indicata dall'Istituto, senza ruolo di governo. Nessun singolo custode ricostruisce la chiave da solo. Protegge la copia di sicurezza: **non elimina l'accesso operativo** di chi amministra il server, dove la chiave deve risiedere perché la piattaforma funzioni. Implementazione: `app/Services/Crypto/ShamirSecretSharing*`.
- ✅ **CSP**: default-src 'self', frame-ancestors 'none', `object-src 'none'`, `base-uri 'self'`; `'unsafe-eval'` rimosso; modalità `strict` (nonce per-request + `strict-dynamic`) pronta + `report-only` per rollout, toggle runtime da `/admin/waf/config` (Track 7, 2026-06-03).
- ✅ **Isolation testata**: 4-teacher concurrent E2E (Phase 25.B7) + dual-role super_admin path verificato.

#### Nota — le tre utenze di database e l'append-only

Le versioni precedenti di questo documento indicavano come misura
`REVOKE UPDATE, DELETE` sull'utente applicativo. Non era applicabile: la
concessione di `pantedu_app` e' a livello di database, e MySQL/MariaDB non
permette di sottrarre un permesso su singole tabelle da una concessione piu'
ampia. Si sono usati dei trigger, che sono anzi piu' forti — valgono per
qualunque utenza e non vanno rivisti quando si aggiungono tabelle.

I trigger pero' non proteggono se stessi, e per questo le utenze sono ora tre,
separate per funzione:

| Utenza | Puo' fare |
|---|---|
| `pantedu_app` | legge e scrive i dati. Nessun potere sulla struttura: il privilegio `TRIGGER` le e' stato revocato il 2026-09-02 |
| `pantedu_migrator` | modifiche alla struttura. La usa il solo `tools/migrate.php`, non raggiungibile dal web |
| `pantedu_maint` | purga le righe scadute dai log, come impone l'art. 5(1)(e). Solo job pianificati |

**Limite residuo**: chi ha accesso amministrativo al database o al server puo'
comunque rimuovere le protezioni. E' lo stesso confine della chiave master —
non impossibilita' tecnica, ma un accesso privilegiato a sua volta protetto
(SSH non esposto, Cloudflare Access con MFA) e tracciato. Chi ottenesse le sole
credenziali applicative non puo' invece alterare il registro. Dal 2026-09-04
l'alterazione da parte di chi amministra il server e' rilevabile (impronta
giornaliera fuori dal server) e l'accesso ai contenuti di un docente gli e'
notificato: il registro non e' piu' letto soltanto da chi lo scrive.

Strumenti: `tools/security/apply_audit_append_only.php`,
`tools/security/create_migrator_db_user.sh`.

### Organizzative (in implementazione)

- ✅ **Privacy by design** documentato in ADR-006/007.
- ✅ **Separazione dei privilegi** (2026-09-04): l'account docente dell'operatore non ha più poteri amministrativi; l'amministrazione avviene da un account dedicato, con secondo fattore obbligatorio.
- ✅ **DPO contact**: form pubblico `/dpo-contact` (instradato a `{{OPERATORE_EMAIL}}`).
- ✅ **Retention policy**: `app/Config/retention.php` con anonimizzazione automatica.
- ✅ **Data breach runbook**: `docs/privacy/data_breach_runbook.md` (Phase 25.C12 drill semestrale PENDING).
- ✅ **Registro trattamenti Art. 30**: completato (Phase 25.C8). Dovuto: l'esenzione dell'Art. 30 §5 vale per i soli trattamenti occasionali, e la gestione delle utenze e i registri di sicurezza non lo sono.
- 🚨 **DPIA firmata Titolare**: questo documento, in bozza.
- ✅ **DPA con sub-responsabili**: Hetzner (hosting UE), Cloudflare (edge), Backblaze B2 (backup), Resend (email di servizio) — DPA art. 28 accettati all'attivazione dei rispettivi servizi; dettaglio e garanzie nel Registro art. 30, Sezione D, che ne è la fonte unica.
- ⬜ **Annual privacy review**: scheduled gennaio di ogni anno.

### KMS_MASTER_KEY backup (Art. 32 §1 c)

Vedi `docs/security/operations/kms-recovery.md`:
Stato verificato al 2026-08-26 (vedi registro `crypto_custody_events`):

- Server env (`.env.local` gitignored, chmod 600) — copia **operativa**, non un backup
- **Supporto fisico off-line** (chiave trascritta, custodita in cassaforte o cassetta di sicurezza)
- **Due contenitori cifrati** su provider cloud distinti, ciascuno con passphrase propria

Requisito di progetto: le tre copie devono avere **modalità di guasto indipendenti** — supporto fisico, e due account presso fornitori diversi. Nessun singolo incidente (ransomware, lockout di un account, furto presso una sede) deve poterle compromettere insieme.

> L identità dei fornitori, l ubicazione fisica della copia off-line e i nomi dei contenitori sono **dati operativi del singolo esercente**: non compaiono in questo documento. Sono tracciati nel registro `crypto_custody_events` dell istanza e nel runbook interno `docs/security/operations/kms-recovery.md`, che non fa parte della distribuzione pubblica.

- **Postazioni di sviluppo**: non devono mai contenere la chiave di produzione. Ogni ambiente dev usa una `KMS_MASTER_KEY` propria, valida solo per dati di test.

- **Drill**: registrato come evento `kms_backup_verified` in `crypto_custody_events`, compilabile da `/admin/crypto-status`. Cadenza semestrale. Primo drill eseguito 2026-08-26.
- **Opzione (su richiesta)**: frazionamento Shamir *k*-su-*n* (es. 3-su-5) della copia di sicurezza, con quote affidate a custodi distinti scelti dal Titolare → nessun single point of trust sul ripristino della chiave master. Non elimina l'accesso operativo (§4, governance chiave master).

## 5. Consultazione preventiva Garante (Art. 36)

Necessaria SE rischio residuo ALTO non mitigabile:
- Nessun rischio ALTO. R6, R7 e R11 non sono applicabili; R14 è sceso a BASSO con il recupero password self-service e la verifica in due passaggi; restano MEDIO R12 (perdita chiave master, mitigato da tre copie indipendenti), R15/R17 (PDF-Import, funzione disattivata) e R20 (testo libero, mitigazione organizzativa).
- Decisione: **NON necessaria**.

## 6. Conclusioni e azioni richieste

### Decisione DPIA

**Stato**: BOZZA COMPLETA — consultazione Garante NON necessaria condizionata a:

1. ✅ Phase 25.B isolation hardening (DONE)
2. ✅ Phase 25.D encryption at-rest (DONE)
3. ✅ Phase 25.C self-service oblio (DONE)
4. ✅ Phase 25.C8 registro trattamenti (DONE — dovuto: trattamento non occasionale, Art. 30 §5)
5. ✅ Phase 25.C10 informativa v2 (DONE — disclosure IP/UA + diritti self-service)
6. **Minori (Art. 8)**: **non applicabile dal 2026-09-03** — nessun dato identificativo di studenti; accesso con credenziale del docente non nominativa. Le modalità Completa/Ridotta restano nel software, disattivate.
7. ⚠️ **Phase 25.C13 raccomandata**: DPO contact form + audit register cassaforte.
8. ⚠️ **Phase 25.E7 raccomandata**: pentest esterno pre-go-live.
9. ✅ **2026-09-03**: hash di IP/UA in `content_action_log`, `privileged_access_log`, `teacher_recovery_audit` e `audit_activity_log` con conversione delle righe esistenti (migration 100); ToS 1.3 e AUP 1.2 (formule del vecchio inquadramento tolte, divieto generale sui dati personali di studenti, clausola sulla conservazione degli atti formali in attesa del parere del DPO); purga dei log privilegiati allineata ai cinque anni dichiarati.
10. ✅ **2026-09-04**: secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico al docente per ogni accesso amministrativo ai suoi contenuti; impronta giornaliera dei registri fuori dal server; timer per le cancellazioni art. 17, che mancava; conservazioni riviste (audit a cinque anni, consensi a dieci, WAF a trenta giorni, backup a un anno con ri-cancellazione dopo ripristino); Resend fra i responsabili del trattamento.

Un'eventuale riattivazione degli account studente è possibile solo in un'istanza condotta da un Istituto Titolare su infrastruttura qualificata ACN, e richiederebbe una DPIA a cura di quel Titolare.

### Firma Titolare

| Campo | Valore |
|-------|--------|
| Titolare | {{OPERATORE_NOME}} |
| Email | {{OPERATORE_EMAIL}} |
| Data versione bozza | 2026-04-27 (agg. 2026-09-04) |
| Versione DPIA | 1.5 |
| Stato | BOZZA — nessun dato identificativo di studenti (registrazione disattivata dal 2026-09-03, mai studenti reali); Titolare dei dati dei docenti: {{OPERATORE_NOME}} |
| Prossima revisione | Annuale (gennaio 2027) o ad evento |

**Firma**: __________________________ Data: __________

## Storia delle revisioni

| Versione | Data | Modifica |
|---|---|---|
| 1.5 | 2026-09-04 | **Rimossi i dati degli studenti**, a seguito dell'osservazione del DPO sul Regolamento cloud ACN (Decreto direttoriale n. 21007/24): di quei dati era Titolare l'Istituto, non l'autore, e l'infrastruttura di un privato non qualificato non può ospitarli. Nessuno studente reale si era mai registrato: registrazione disattivata, account di prova cancellati, `parent_consents` vuota. R7 e R11 non applicabili; **R20** aggiunto (dati di studenti in campi a testo libero, mitigazione organizzativa); R10 riscritto: lo shredding copre i contenuti, non le anagrafiche nei backup, che ruotavano fino a due anni (dal 4 settembre: un anno, con ri-cancellazione dopo ripristino). Modello di titolarità riscritto: Titolare dei dati dei docenti è l'autore; bozza di DPA ritirata. **Rilevato e corretto**: `content_action_log`, `privileged_access_log` e `teacher_recovery_audit` conservavano IP e User-Agent in chiaro, `audit_activity_log` lo User-Agent, contro quanto dichiarato — logica di hash unificata in `RequestFingerprint`, righe esistenti convertite (migration 100); dichiarati i log di sicurezza che restano in chiaro (WAF, contatori, sessioni, navigazione) e il verbale di accettazione dei ToS. Purga di `privileged_access_log` e `crypto_access_log` portata da dieci ai cinque anni dichiarati. Log di accesso del server web disattivato. ToS 1.3 e AUP 1.2. **2026-09-04**: rilevato che la credenziale di classe (accesso «Anonima») metteva il grant in sessione ma nessun endpoint lo leggeva — l'accesso dichiarato non mostrava nulla; corretto (`ClassAccessGrant`, ADR-032): l'ospite con credenziale vede solo i contenuti pubblicati del docente della credenziale, per la sua classe; senza credenziale legge per id solo i contenuti pubblici. Il blocco copyright sulla condivisione esteso ai grant mirati verso singoli colleghi. **Risdoc**: le compilazioni dei modelli erano l'unico contenuto del docente in chiaro nel database — ora cifrate con la sua chiave come tutto il resto (migration 101, conversione delle righe esistenti con `tools/gdpr/encrypt_risdoc_compilations.php`); rimosso il Modulo di autorizzazione, residuo del modello con credenziali nominative per minorenni (migration 102); tolto il selettore «studente» dalla scheda di recupero; i campi riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio quando non esistono account studente (R20). **Seconda tornata del 4 settembre**: secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico al docente per ogni accesso amministrativo ai suoi contenuti (`CustodyNotifier`, `/me/custody-events`); impronta giornaliera a blocchi dei registri append-only, fuori dal server con il backup; timer per le cancellazioni art. 17 — `executeOverdue()` non era chiamato da nessun job; conservazioni riviste per l'art. 5(1)(e); Resend, servizio di posta, fra i responsabili; formula «nessun dato identificativo di studenti» al posto di «nessun dato personale», perché dei dati tecnici di connessione restano. |
| 1.0 | 2026-04-27 | Prima stesura |
| 1.1 | 2026-04-27 | Rimosso R6 (BES/DSA non è dato Art. 9, verificato sul codice) |
| 1.4 | 2026-09-02 | Copertura del registro verificata contro la produzione. Aggiunto **R19**: le operazioni di studenti e docenti non erano ricostruibili (file troncato a mille voci, sette giorni di storia). Nuovo `audit_activity_log` append-only; append-only esteso a `content_action_log` e `teacher_recovery_audit`; `RequiresAuditReason` passato a `enforce` (R9 riformulato); ricreate `content_versions` e `teacher_recovery_audit`, assenti in produzione. Registrato il consenso genitoriale **concesso**, che finora non lasciava traccia. |
| 1.3 | 2026-09-02 | Rilettura integrale del pacchetto. Architettura corretta: era indicata Apache/PHP 8.3/MySQL 5.7, il server esegue **nginx 1.26, PHP 8.4, MariaDB 11.8**. Append-only sui log di audit: era dichiarato e non esisteva, ora applicato (vedi nota §4). R14 rivalutato a BASSO. Aggiunti recupero password e secondo fattore. |
| 1.2 | 2026-09-01 | Aggiunta la funzione PDF-Import basata su modelli di IA: nuova § 3.1 e rischi **R15-R18** (invio di dati personali al fornitore, prompt injection, trasferimento extra-SEE, contenuto generato scambiato per materiale d’autore). Dichiarato il limite di `PiiMasker`, che redige codice fiscale ed email ma **non i nomi propri**: la difesa è organizzativa. Inquadramento AI Act in `docs/legal/ai-act-assessment.md` |

## Riferimenti

- ADR-006 (envelope encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance, in arrivo Phase 25.C15)
- KMS recovery runbook: `docs/security/operations/kms-recovery.md`
- Retention policy: `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- Compliance checklist: `docs/privacy/compliance_checklist.md`
- Migration 015 (consents): `database/migrations/015_consents_gdpr.sql`
- Migration 012 (encryption): `database/migrations/012_teacher_crypto.sql`
