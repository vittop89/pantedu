---
tags:
    - documentazione/gdpr
    - phase/25.C10
date: 2026-04-27
tipo: informativa-utente
status: vigente
versione: 2.3
classification: PUBLIC — esibibile a utenti finali
aliases: ["informativa", "privacy-policy"]
---

# Informativa Privacy — Pantedu

**Versione:** 2.3
**Ultima revisione:** 2026-09-04
**Decorrenza:** 2026-04-27

> ⚠️ Per gli utenti già registrati: a fronte di questa nuova versione, al
> prossimo accesso ti verrà chiesto di confermare i consensi precedentemente
> espressi (Art. 7 §1 GDPR).

## 1. Titolare del trattamento

| Campo | Valore |
|-------|--------|
| Nome | {{OPERATORE_NOME}} |
| Email contatto | `{{OPERATORE_EMAIL}}` |
| Domicilio digitale (PEC) | `superadmin@pec.it` — recapito per comunicazioni formali e per l'esercizio dei diritti |
| Responsabile della protezione dei dati | Non designato: la designazione non è obbligatoria (Art. 37: persona fisica, nessun monitoraggio su larga scala, nessun dato Art. 9). `{{OPERATORE_EMAIL}}` è il recapito dedicato all'esercizio dei diritti |
| Indirizzo | Italia; per le comunicazioni formali vale il domicilio digitale (PEC) |

## 2. A chi è rivolta questa informativa

- **Docenti** che si registrano come professionisti, di qualunque scuola. Il Titolare è il gestore della piattaforma, non l'Istituto in cui il docente insegna: l'iscrizione è volontaria e non disposta dall'Istituto.
- **Studenti**: dal 3 settembre 2026 **non è prevista alcuna registrazione né alcun account**. I contenuti pubblicati si consultano con una credenziale fornita dal docente, non nominativa: nessun dato identificativo dello studente viene raccolto, e nessun consenso genitoriale (vedi sezione **Minori**); restano i dati tecnici di connessione (§3.4).
- **Super-Admin tecnici** (solo personale autorizzato per manutenzione/audit).

## 3. Quali dati raccogliamo

### 3.1 Dati di identificazione (sempre)

- Username, nome, cognome, email
- Password (memorizzata cifrata con bcrypt cost 12 — mai in chiaro)
- Ruolo (docente / admin)
- Istituto / classe / indirizzo di studio (per i docenti: delimitano a chi sono visibili i contenuti pubblicati)

**Base giuridica**: Art. 6(1)(b) GDPR — esecuzione contratto registrazione utenza.

### 3.2 Varianti adattate degli esercizi (DSA/DIS — NO Art. 9)

Il docente può segnare che un esercizio ha una **variante adattata** per copie DSA/DIS (es. con formula esplicita, font dyslexia-friendly, semplificazioni linguistiche). Può anche specificare un numero di copie da stampare in ciascuna variante (es. "3 copie standard, 1 copia DSA").

Questo è un **metadata di contenuto** del docente — NON un identificativo dello studente. L'app NON registra "lo studente Mario è DSA" e NON riceve dati sanitari personali (PEI/PDP, certificazioni mediche). Questi dati restano nella scuola tramite registro elettronico esterno + cartaceo.

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto (gestione contenuti didattici).

**Misure tecniche**: i contenuti del docente (incl. flag DSA come metadata) sono cifrati at-rest con AES-256-GCM (envelope encryption per-docente, vedi sezione **Sicurezza**).

### 3.3 Contenuti didattici autoredatti dai docenti

- Esercizi (body HTML + body Portable Text)
- Verifiche
- Mappe concettuali (link Google Drawio + metadati)
- Documenti riservati docente (risdoc)
- Strumenti compensativi BES/DSA

**Base giuridica**: Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente.

**Misure tecniche**: cifratura at-rest envelope (Phase 25.D — AES-256-GCM con per-teacher KEK derivata da HKDF), comprese le compilazioni dei modelli risdoc. Quando non esistono account studente, i campi dei modelli che si riferiscono a studenti o genitori non vengono salvati: il server li svuota prima di scrivere. Se il tuo Istituto lo ha richiesto, le compilazioni dei modelli istituzionali (piano annuale, relazione finale, schede) non vengono salvate sul server: restano nel tuo browser, e il PDF esportato va depositato nei sistemi della scuola.

### 3.4 Dati di accesso (IP, User-Agent)

Per ogni accesso (login, navigazione autenticata, API calls):
- Indirizzo IP — memorizzato come **hash SHA-256** (confrontabile con un indirizzo noto, non ricostruibile dal registro)
- User-Agent — memorizzato come **hash SHA-256** (audit traceabile, no fingerprinting)
- Timestamp, action, resourceType

**Base giuridica**: Art. 6(1)(f) — interesse legittimo sicurezza (rilevamento brute-force, account takeover, abusi privilegi).

**Conservazione**:
- Access log applicativo (statistiche di navigazione): **365 giorni**
- Registro delle operazioni (`audit_activity_log`): **2 anni**
- Privileged access log (admin actions): **1825 giorni / 5 anni** (termine prescrizione abusi amministrativi)

**Che cosa finisce nel registro delle operazioni.** Dal 2 settembre 2026 le
operazioni di tutti i ruoli sono registrate in una
tabella append-only e non piu' soltanto in un file che ne conservava le ultime
mille. Vi finiscono le scritture (ogni richiesta che non sia una semplice
lettura), i tentativi respinti, e gli eventi che una rotta da sola non
descrive: la domanda di iscrizione e il suo esito. Le sessioni con credenziale
del docente vi compaiono come anonime. Le letture andate a buon fine restano fuori: contarle
serve alle statistiche di navigazione, non a rispondere di un'operazione.
L'indirizzo IP e' conservato come hash, non in chiaro.

**Correzione del 3 settembre 2026.** Quattro registri — le operazioni sui
contenuti, gli accessi privilegiati, l'uso delle chiavi di recupero e, per il
solo User-Agent, il registro delle operazioni — conservavano indirizzo IP e
User-Agent **in chiaro**, contro quanto questa informativa dichiarava. Il
codice è stato corretto e le righe esistenti convertite ad hash con la stessa
formula. Il log di accesso del server web è disattivato dalla stessa data.

**Dove restano in chiaro.** Per la sola sicurezza e a conservazione breve:
nel log del filtro di sicurezza (WAF, 30 giorni), nel contatore dei tentativi
di accesso falliti (un'ora), nei contatori di rate-limit (pulizia
giornaliera), nelle sessioni attive e nel log di navigazione (ultime mille
voci). Restano in chiaro anche nel verbale di accettazione dei Termini di
Servizio, quale prova dell'accettazione.

### 3.5 Cookie

Vedi sezione dedicata "Cookie" più sotto. Cookie banner (granulare 3-switch: necessari / analytics / marketing) gestito client-side + sync backend (`/me/consents`).

## 4. Finalità del trattamento

1. **Didattica** (Art. 6(1)(b) — esecuzione contratto): erogazione piattaforma per gestione esercizi/verifiche/mappe, comprese le varianti adattate degli esercizi (DSA/DIS metadata).
2. **Sicurezza** (Art. 6(1)(f) — interesse legittimo): audit log, prevenzione abusi, rate-limiting.
3. **Conformità normativa** (Art. 6(1)(c) — obbligo legale): retention policy, accountability Art. 5 §2.

Trattamenti **esplicitamente esclusi**:
- ❌ Profilazione comportamentale automatica
- ❌ Pubblicità mirata
- ❌ Vendita / cessione dati a terzi (no monetizzazione)
- ❌ Decisioni automatizzate Art. 22 GDPR
- ❌ Geolocalizzazione precisa

## 5. Tempi di conservazione

Definiti in `app/Config/retention.php`. Job automatico `tools/gdpr/anonymize_expired.php` esegue dry-run + commit:

| Dato | Conservazione | Azione a scadenza |
|------|---------------|-------------------|
| Account attivi (docente) | Vita ciclo account | Soft-delete + crypto-shredding al Art. 17 self-service |
| Account inattivi > 730 giorni | 730 giorni | Anonimizzazione (email/nome svuotati, body crypto-shredded) |
| Registrazioni pending mai approvate | 30 giorni | Cancellazione completa |
| Access log applicativo (navigazione) | 365 giorni | Cancellazione |
| Registro operazioni (`audit_activity_log`) | 2 anni | Cancellazione |
| Privileged access log (admin actions) | 1825 giorni (5 anni) | Cancellazione |
| Eventi contenuti (`content_action_log`) | 5 anni | Cancellazione |
| Uso chiavi di recupero (`teacher_recovery_audit`) | 5 anni | Cancellazione |
| Log del filtro di sicurezza (`waf_logs`, IP in chiaro) | 30 giorni | Cancellazione |
| Backup cifrati (database e file), copia locale | 7 giorni | Cancellazione |
| Backup cifrati (database e file), archivio fuori dal server | Rotazione a livelli: 3 giornalieri, 4 settimanali, 6 mensili, 1 annuale — una copia può sopravvivere fino a **un anno** | Cancellazione per rotazione. La cifratura per docente rende illeggibili i contenuti già cancellati, non le anagrafiche, che restano nelle copie fino alla rotazione; dopo ogni ripristino le cancellazioni successive alla copia vengono rieseguite |
| classe_keys (pubblicazione studenti) | 1 anno scolastico | Archive `archived_at` (decrypt audit-only) |
| Nome utente nei registri di audit | Per il termine di ciascun registro (5 anni; 2 anni per il registro delle operazioni), anche dopo la cancellazione dell'account (Art. 17 §3, lett. b ed e) | Cancellazione con il registro. Dopo l'anonimizzazione dell'account il nome utente non è più collegato ad alcun altro dato |
| consent_audit (eventi pseudonimi sui consensi) | 10 anni dall'evento (prescrizione ordinaria) | Cancellazione |

## 6. Minori (Art. 8 GDPR; art. 2-quinquies D.Lgs. 196/2003)

Dal **3 settembre 2026** la piattaforma **non raccoglie dati identificativi di studenti** e non prevede account per minori. Gli studenti consultano i contenuti pubblicati con una credenziale fornita dal docente, eventualmente delimitata per classe e non nominativa: la sessione non è associata a una persona; dell'accesso restano i soli dati tecnici di connessione, con l'indirizzo IP nel log di sicurezza per trenta giorni e altrove come hash (§3.4). Il docente crea la credenziale dal proprio pannello e può disattivarla o sostituirla in qualunque momento.

Di conseguenza non si applica il consenso del minore (valido in Italia dai 14 anni) né il consenso genitoriale. La procedura di consenso genitoriale descritta nelle versioni precedenti di questa informativa non è più attiva. Nessuno studente reale si è mai registrato: gli unici account studente esistiti erano account di prova con dati fittizi, cancellati il 3 settembre 2026.

Le funzioni di registrazione studente restano nel software, disattivate: potrebbero essere riattivate solo in un'istanza condotta da un Istituto scolastico che ne sia Titolare, con propria informativa.

## 7. Diritti dell'interessato (Art. 15-22 GDPR)

Per ogni utente registrato sono disponibili endpoint **self-service** (vedi `/me/*`):

| Diritto | Articolo | Endpoint self-service |
|---------|----------|----------------------|
| Accesso ai propri dati | Art. 15 | `GET /me/export-data` (download JSON) |
| Rettifica dati | Art. 16 | `POST /me/profile` (aggiorna nome/cognome/email) |
| **Diritto all'oblio** | Art. 17 | `POST /me/request-deletion` → email conferma → 30g cooling-off → crypto-shredding O(1) ed anonimizzazione, eseguiti ogni giorno. Il nome utente resta nei registri di audit per il loro termine (§5) |
| Limitazione | Art. 18 | Richiesta a `{{OPERATORE_EMAIL}}` |
| Portabilità | Art. 20 | `GET /me/export-data` (JSON strutturato user + consents + contenuti decifrati + override) |
| Opposizione | Art. 21 | Richiesta a `{{OPERATORE_EMAIL}}` per i trattamenti fondati sull'interesse legittimo (registri di sicurezza) |
| Revoca del consenso | Art. 7 §3 | `POST /me/consents/revoke` o banner cookie |

Per i diritti che non hanno endpoint self-service, inviare richiesta a `{{OPERATORE_EMAIL}}` dall'indirizzo registrato. Risposta entro 30 giorni (prorogabile a 60 con comunicazione motivata in caso di complessità).

In caso di insoddisfazione: reclamo al Garante per la protezione dei dati personali ([garanteprivacy.it](https://www.garanteprivacy.it)).

## 8. Sicurezza tecnica e organizzativa

Misure implementate (Art. 32 GDPR — vedi anche `wiki/decisions/ADR-006-envelope-encryption.md` e `docs/privacy/dpia.md`):

- **Cifratura at-rest**: envelope encryption AES-256-GCM con per-teacher KEK derivata via HKDF-SHA256 da `KMS_MASTER_KEY` off-line backed up.
- **Cifratura in transito**: HTTPS obbligatorio (HSTS 1 anno).
- **Hashing password**: bcrypt cost 12.
- **CSRF**: token rotation su ogni mutazione.
- **Rate-limiting**: 10/min/IP login (anti-brute-force), 60/min teacher content.
- **Audit log append-only**: il database rifiuta ogni modifica o cancellazione dei registri di audit, e l'applicativo non può rimuovere questa protezione; solo un'utenza tecnica separata cancella le righe scadute. *Limite*: resta possibile a chi ha accesso amministrativo al server.
- **Pseudonimizzazione**: hash SHA-256 IP/UA in audit log (no PII raw).
- **Crypto-shredding O(1)**: cancellazione 1 row teacher_keys → tutti i dati cifrati immediatamente illeggibili.
- **CSP rigorosa**: prevenzione XSS + frame injection.
- **Permission system per-teacher**: isolation testata con E2E concurrent (Phase 25.B7).
- **Verifica in due passaggi (2FA)**: obbligatoria per i ruoli amministrativi dal 4 settembre 2026, facoltativa per i docenti; codice generato da un'app di autenticazione oppure inviato via email; codici di backup monouso e limite di tentativi.
- **Avviso di accesso amministrativo**: se l'operatore accede ai tuoi contenuti cifrati nei casi del §11-bis, ricevi un'email nel momento in cui l'evento viene registrato; l'elenco è consultabile da `/me/custody-events`.
- **Integrità dei registri di audit**: impronta giornaliera dei registri, conservata fuori dal server con il backup cifrato e verificabile: un'alterazione successiva è rilevabile.
- **Recupero password autonomo**: link monouso valido un'ora, inviato all'indirizzo registrato. Reimpostare la password non disattiva la verifica in due passaggi.

## 9. Sub-processor e trasferimenti

### 9.1 Sub-processor reali (Art. 28 GDPR)

Soggetti che processano dati personali **per conto di pantedu**, con DPA standard:

| Sub-processor | Servizio | Localizzazione | Trasferimento extra-UE |
|---------------|----------|----------------|------------------------|
| Hetzner Online GmbH | VPS hosting (web server + database + storage), datacenter Norimberga | Germania (UE) | NO |
| Cloudflare, Inc. | CDN e sicurezza di bordo. **Termina la connessione TLS in ingresso**: ogni richiesta vi transita in chiaro prima di raggiungere il server | Rete globale, instradamento UE per il traffico italiano | Possibile in transito — clausole contrattuali tipo (Capo V) e certificazione EU-US Data Privacy Framework. Non conserva i contenuti |
| Backblaze Inc. | Object storage per backup cifrati (region eu-central-003, Amsterdam) | Società USA, storage in UE | I dati sono cifrati **prima** dell'upload: Backblaze non accede al contenuto. SCC 2021/914 + DPF |
| Resend, Inc. | Invio delle email di servizio: conferma di registrazione, recupero password, codice di verifica via email, notifiche. Riceve indirizzo, nome e testo del messaggio, mai contenuti didattici | USA | SÌ — clausole contrattuali tipo (Capo V) nel DPA del fornitore |
| Google LLC | OAuth e integrazione Drive — **solo su opt-in** del singolo docente | USA | Clausole contrattuali tipo art. 46(2)(c). Nessun dato finché il docente non attiva |
| Anthropic PBC / OpenAI, L.L.C. | Modelli di IA per PDF-Import — **solo se la funzione è attivata** con un fornitore cloud | USA | Clausole contrattuali tipo art. 46(2)(c). Disattivata di default; con modello locale nessun trasferimento |

L'elenco completo, con le basi giuridiche dei trasferimenti, è nel Registro
delle attività di trattamento ex art. 30 (Sezione D), che ne è la **fonte
unica**: in caso di divergenza prevale il Registro. Lista live su
`/admin/subprocessors` (super-admin).

### 9.2 Servizi terzi user-initiated (NON sub-processor)

Integrazioni che il **docente attiva opzionalmente** e che instaurano una relazione diretta tra docente e fornitore terzo (pantedu è solo middleware OAuth, non destinatario dei dati):

| Servizio | Ruolo pantedu | Cosa attiva il docente | Privacy applicabile |
|----------|----------------|------------------------|---------------------|
| Google LLC — OAuth + Drive integration | Solo middleware OAuth: il docente collega il PROPRIO account Google, i materiali sincronizzati finiscono nel SUO Drive personale | clicca "Connetti Drive" + autorizza scope `drive.file` | ToS + Privacy Policy Google direttamente applicabili al rapporto docente-Google |

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
- Il docente può **disconnettere in qualsiasi momento** via `POST /teacher/drive/disconnect` — questo revoca solo l'autorizzazione OAuth lato pantedu; i dati già nel Drive del docente restano nel suo account.

## 10. Cookie

Cookie banner granulare (vedi popup al primo accesso) con 3 categorie:

- **Necessari** (sempre attivi, no consenso richiesto): session cookie, CSRF token, login state. Base: Art. 6(1)(b).
- **Funzionali** (opt-in): preferenze UI (modalità scura, sidebar state). Base: Art. 6(1)(a).
- **Analytics** (opt-in): metriche aggregate audience, no profiling individuale. Base: Art. 6(1)(a) — opt-in esplicito.
- **Marketing** (opt-in): NON ATTIVO oggi. Riservato a usi futuri post-consenso.

Tutti i cookie opt-in sono **revocabili in qualsiasi momento** via il banner persistente in basso a destra o via `POST /me/consents/revoke`.

## 11. Data breach

Vedi `docs/privacy/data_breach_runbook.md`. In caso di breach:
- Notifica al Garante entro 72h (Art. 33)
- Notifica utenti se rischio elevato (Art. 34)
- Crypto-shredding emergenziale + KMS rotation (vedi `docs/security/operations/kms-recovery.md`)

Il registro incident è gestito internamente via `/admin/data-breach` (super-admin only).

## 11-bis. Cooperazione con autorità per recupero dati

Vedi `docs/security/operations/authority-cooperation.md`. Casi coperti:

- **Richieste autorità giudiziaria / Garante / forze di polizia**: il data
  controller verifica entro 72h legittimità (base giuridica + decreto motivato),
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
**EUPL-1.2**: il codice sorgente — incluse le logiche automatiche (es. il filtro
di sicurezza WAF) — è **interamente ispezionabile** pubblicamente all'indirizzo
<https://github.com/vittop89/pantedu>, senza necessità di richiesta o
registrazione.

Il codice sorgente è stato **scritto dai modelli di intelligenza artificiale
Claude Opus 4.7 e 4.8 e Claude Fable 5.1 (Anthropic), sotto la guida e la
direzione di {{OPERATORE_NOME}}**, che ne ha curato ideazione, requisiti, revisione e responsabilità
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

Questa informativa ha versione **2.3** (4 settembre 2026: rimossi gli account studente e il consenso genitoriale; IP e User-Agent ridotti a hash nei quattro registri che li conservavano in chiaro, e dichiarati i log di sicurezza che restano in chiaro; corretta la conservazione dei backup; aggiunto il servizio di posta Resend fra i sub-responsabili; conservazioni riviste: eventi sui contenuti e chiavi di recupero a cinque anni, consensi a dieci anni dall'evento, WAF a trenta giorni, backup a un anno; avviso automatico degli accessi amministrativi; secondo fattore obbligatorio per gli amministratori). Ad ogni revisione sostanziale:
1. Bump versione (es. 2.1 → 3.0)
2. Notifica utenti registrati via banner al login
3. **Re-consent prompt** per i consensi attivi (Art. 7 §1 — informazione comprensibile)
4. Versione precedente conservata in audit (`consent_audit.text_version`)

## 13. Domande, contatti, reclami

| Tipo richiesta | Contatto |
|----------------|----------|
| Generiche / supporto | `{{OPERATORE_EMAIL}}` |
| DPO / privacy / esercizio diritti | `{{OPERATORE_EMAIL}}` (oggetto: "GDPR — [tuo username]") |
| Reclamo al Garante | [garanteprivacy.it/home/footer/contatti](https://www.garanteprivacy.it/home/footer/contatti) |

## Riferimenti tecnici (per audit professionali)

- `docs/privacy/dpia.md` — Valutazione d'Impatto Privacy
- `docs/privacy/registro-trattamenti.md` — Registro Art. 30
- `docs/privacy/data_breach_runbook.md` — Procedura data breach
- `docs/security/operations/kms-recovery.md` — KMS recovery
- `docs/security/operations/authority-cooperation.md` — Cooperazione autorità + custodia chiavi
- `wiki/decisions/ADR-006-envelope-encryption.md` — Design crypto
- `wiki/decisions/ADR-007-gdpr-compliance.md` — Design GDPR
