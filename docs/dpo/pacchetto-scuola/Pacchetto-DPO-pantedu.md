---
title: "Pantedu — Pacchetto di accountability (GDPR artt. 24, 32, 35)"
subtitle: "Documentazione tecnica e organizzativa del Titolare"
author: "{{OPERATORE_NOME}} — sviluppatore e operatore di Pantedu"
date: "2026-09-25"
version: "1.7"
lang: it
---

**Versione 1.7 — 25 settembre 2026.** La storia delle revisioni è in fondo.
La versione 1.0 è quella trasmessa il 4 settembre 2026; una prima stesura era
stata inviata il 1° settembre.

# 0. Sintesi (in una pagina)

**Pantedu** è una piattaforma didattica web (materiali didattici di **qualsiasi disciplina**: esercizi, verifiche, mappe concettuali, laboratori, documenti) sviluppata e gestita personalmente da **{{OPERATORE_NOME}}**. L'autore la utilizza per le proprie materie (matematica/fisica), ma la piattaforma è **agnostica rispetto alla disciplina**. Questo documento è la documentazione di *accountability* del Titolare (art. 24 GDPR). È trasmesso per conoscenza al DPO dell'Istituto in cui l'autore insegna, che non ha mandato su questo trattamento.

- **Nessun dato identificativo di studenti.** Nessuno studente reale si è mai registrato: gli account studente esistiti erano account di prova creati dall'autore, ora cancellati, e la registrazione degli studenti è disattivata dal 3 settembre 2026. Gli studenti consultano i contenuti pubblicati tramite una **credenziale del docente**, eventualmente delimitata per classe e **non nominativa**: nessuna sessione è associata a una persona. Restano i soli dati tecnici di connessione, come per qualunque sito: l'IP in chiaro nel registro del filtro di sicurezza (30 giorni) e nei contatori del limitatore di frequenza (fino alla pulizia della notte successiva), altrove come impronta con chiave (§2). Quando uno studente apre una mappa, il visualizzatore caricato dai server di JGraph ne riceve l'IP (DPIA §1). Le funzioni di registrazione studente restano nel software, disattivate (§4).
- **Natura d'uso.** **(1)** uso personale dell'autore per la propria didattica; **(2)** uso da parte di colleghi docenti — di qualunque scuola — per esercizi, verifiche, mappe, laboratori e documenti propri, con contenuti separati per docente (che cosa è cifrato: §2) e condivisione solo su scelta esplicita del docente che li ha creati, verso colleghi dello stesso istituto, mai per materiale derivato da libri di testo; le compilazioni dei modelli di documenti scolastici non si condividono mai (predisposto; l'apertura delle iscrizioni è decisione del Titolare); **(3)** adozione formale da parte di un Istituto, con dati di studenti: possibile **solo** su infrastruttura qualificata ACN condotta dall'Istituto o da un fornitore qualificato, **mai** su infrastruttura dell'autore. Dettaglio in §5.
- **Titolarità.** Dei dati dei docenti che si iscrivono è **Titolare {{OPERATORE_NOME}}** (art. 4(7) GDPR): iscrizione volontaria, dati conferiti dall'interessato, finalità e mezzi determinati dall'autore. La piattaforma **non è uno strumento d'Istituto**; la titolarità dell'Istituto sui dati dei propri docenti attiene alla gestione del personale e resta distinta.
- **Postura di sicurezza.** L'applicazione è stata sottoposta (giugno 2026) a un **audit di sicurezza esaustivo** (VA automatizzato + verifica manuale + test attivo su clone isolato); i finding di quell'audit sono stati corretti o mitigati (§6). Dopo l'audit, fra il 21 e il 23 settembre 2026, sono stati trovati e corretti altri difetti, fra cui tre di gravità alta che toccano la protezione dei dati; il 24 settembre è stata cambiata la chiave master, il cui valore era scritto in un documento interno del repository di sviluppo (§6.1). **Nessuna vulnerabilità Critical/High residua nota al 24 settembre 2026.**
- **Minimizzazione.** I marcatori BES/DSA sono metadati dell'esercizio, **non** dati sanitari Art. 9; PEI, PDP, certificazioni e diagnosi non sono trattati e i Termini ne vietano il caricamento. Nessun dato particolare ex Art. 9.
- **Hosting nell'UE.** Server su **Hetzner Cloud, datacenter di Norimberga (Germania, UE)**. Alcuni fornitori sono fuori dall'UE, come il servizio di posta (Resend, USA): elenco al §4. Cifratura in transito; a riposo per i contenuti indicati al §2.

---

# 1. Inquadramento normativo

| Norma | Riferimento | Rilevanza |
|------|-------------|-----------|
| GDPR | Art. 4(7), 6(1)(b), 6(1)(f) | Titolarità e basi giuridiche dei dati dei docenti (§5) |
| GDPR | Art. 5(1)(c) | Minimizzazione dei dati |
| GDPR | Art. 24, 32 | Misure tecniche e organizzative adeguate + accountability |
| GDPR | Art. 8 | Consenso dei minori — **non più applicabile** dal 3 settembre 2026: nessun dato identificativo di studenti |
| GDPR | Art. 33/34 | Notifica violazioni 72h |
| GDPR | Art. 35 | DPIA — **dovuta** (provvedimento del Garante n. 467/2018, allegato 1, n. 6: trattamenti non occasionali di dati di soggetti vulnerabili, fra cui i minori), adottata dal Titolare il 25 settembre 2026 (Allegato D) |
| **Regolamento cloud ACN** | Decreto direttoriale n. 21007/24 del 27 giugno 2024, attuativo dell'art. 33-septies D.L. 179/2012 | Le PA si avvalgono solo di infrastrutture e servizi cloud qualificati: ragione per cui la piattaforma non tratta dati dell'Istituto (§5.1) |
| DPR 445/2000; CAD (D.Lgs. 82/2005) | artt. 50 ss.; artt. 40-44 | Gestione documentale: la piattaforma è strumento di redazione, non di conservazione degli atti (§5.2) |
| Cost.; D.Lgs. 297/1994 | art. 33; art. 1 | Libertà di insegnamento: il materiale preparatorio è del docente (§5.2) |
| AgID | Misure Minime di Sicurezza ICT (Circ. 2/2017, Piano Triennale 2024-2026) | 8 classi di controlli ABSC (§3) |
| **AI Act** | **Reg. (UE) 2024/1689, artt. 4, 5, 6, 50 + All. III p.3** | **Classificazione dei sistemi di IA presenti** — vedi § 1.1 |

## 1.1 Intelligenza artificiale — inquadramento AI Act

Pantedu include **una** funzione basata su modelli di IA: **PDF-Import**, che
estrae esercizi da pagine di libri di testo caricate dal docente. È
**disattivata per impostazione predefinita**; gli studenti non vi hanno alcun
accesso.

| Domanda | Risposta |
|---|---|
| Ruolo | **Fornitore** (art. 3(3)) e fornitore a valle (art. 3(68)): integra modelli di terzi |
| Classe di rischio | **Rischio limitato** — si applicano art. 4 (alfabetizzazione) e art. 50 (trasparenza) |
| Alto rischio? | **No.** Nessuno dei quattro casi dell'Allegato III p.3 ricorre |
| Pratiche vietate (art. 5)? | **Nessuna.** In particolare **nessuna inferenza di emozioni**, vietata in ambito scolastico dall'art. 5(1)(f) |

**Perché non è alto rischio.** L'Allegato III p.3 riguarda i sistemi che
determinano l'ammissione, **valutano i risultati di apprendimento**, assegnano
il livello di istruzione o sorvegliano gli studenti durante le prove. L'IA di
Pantedu classifica **esercizi**, non studenti: assegna una difficoltà a un
esercizio stampato su un libro, ne inferisce l'argomento, ne genera la
soluzione per il docente. Non prende in input il lavoro di uno studente né
produce output che lo riguardino. **Non vi è profilazione di persone fisiche** —
circostanza dirimente, perché l'art. 6(3), ultimo comma esclude ogni deroga
quando vi sia profilazione.

**Misure adottate**: marcatura dei contenuti generati (art. 50(2)), sia
leggibile da macchina sia visibile all'utente; revisione umana obbligatoria
prima della pubblicazione; scheda di alfabetizzazione per i docenti (art. 4).

**Documentazione**: assessment completo con classificazione motivata voce per
voce, limiti noti e condizioni che farebbero scattare l'alto rischio →
`https://pantedu.eu/legal/ai-act`. Profili di protezione dati del trattamento →
*Allegato D* (DPIA), § 3.1 e rischi R15-R18.

---

# 2. Misure tecniche e organizzative (GDPR Art. 32)

| Area | Misura implementata | Evidenza |
|------|---------------------|----------|
| **Cifratura in transito** | TLS 1.2/1.3, **HSTS con `preload`**, edge Cloudflare | Verificato in audit (header live) |
| **Cifratura a riposo** | Envelope encryption **AES-256-GCM**, con una chiave per docente (**KEK**) avvolta dalla chiave master. Sono cifrati i file delle mappe, i file TeX e PDF delle verifiche salvate, le compilazioni dei modelli di documenti e i gettoni di accesso a Drive e GitHub. Restano in chiaro sul server il testo di esercizi, verifiche, laboratori e documenti (con le versioni precedenti), titoli e argomenti, le preferenze di stampa con i contatori DSA/DIS. Chiave master (`KMS_MASTER_KEY`) **fuori dal repository** dal 24 settembre 2026: fino ad allora il suo valore era scritto in un documento interno del repository di sviluppo (§6.1). Guardia anti-rigenerazione distruttiva delle chiavi | `app/Services/Crypto/`, audit §4.6 |
| **Controllo accessi** | RBAC con principio del privilegio minimo; ruolo `super_admin` separato; authz *per-owner* sui contenuti (verificata: accessi cross-utente → 403/404). Dal 4 settembre 2026 l'operatore amministra con un account distinto dal proprio account docente, con secondo fattore obbligatorio | Audit di giugno: IDOR/BOLA negativo (correzione del 21/9 al §6.1) |
| **CSRF** | Token di sessione obbligatorio su tutte le richieste di stato (POST/PUT/PATCH/DELETE) | Audit (403 senza token) |
| **WAF** | Web Application Firewall self-hosted **in enforce**: geo IT-only, Proof-of-Work, protezione brute-force, threat-intel, anti-spoofing edge | Stato prod verificato: `enabled=1, mode=enforce` |
| **Hardening output** | Sanitizzazione HTML (HTMLPurifier) e SVG (svg-sanitize) sui contenuti resi agli utenti; dal 23 settembre 2026 una mappa si accetta solo come XML drawio verificato, e il suo contenuto non può più chiudere gli script della pagina | Audit di giugno; correzione A-3 (§6.1) |
| **SQL** | Query 100% PDO *prepared* (nessuna concatenazione di input) | Audit (SQLi = nessuna) |
| **Header sicurezza** | CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy, COOP; cookie `Secure; HttpOnly; SameSite` | Verificato live |
| **Backup** | Copia giornaliera del database e dei file, **cifrata con GPG sul server** prima del caricamento su Backblaze B2 (systemd); rotazione a livelli (3 giornaliere, 4 settimanali, 6 mensili, 1 annuale: al massimo un anno). La copia contiene anche la configurazione del server, chiave master compresa: per questo una cancellazione non tocca le copie già fatte, e i dati del docente vi restano fino alla loro scadenza; le chiavi dei docenti restano anche nei salvataggi giornalieri sul server, per 30 giorni. Dopo ogni ripristino le cancellazioni successive alla copia vengono rieseguite (`docs/security/operations/restore-reerasure.md`). Prima di ogni rilascio resta sul server una copia del database non cifrata: se ne tengono le ultime cinque. Nessuna copia ha mai contenuto dati di studenti reali | `tools/backup/encrypted_backup.sh` |
| **IP e User-Agent** | Nei registri di audit (operazioni, contenuti, accessi privilegiati, chiavi di recupero, consensi, recupero password) si conserva un'**impronta con chiave** (HMAC, chiave derivata con HKDF da un segreto del server) dal 24 settembre 2026; le righe precedenti hanno un'impronta SHA-256 senza chiave e si cancellano col loro termine ordinario. È un dato pseudonimo: resta dato personale. Il 3 settembre 2026 quattro registri risultavano in chiaro contro quanto dichiarato: codice corretto e righe esistenti convertite (migration 100). In chiaro restano, fra gli altri: il registro del filtro di sicurezza (30 giorni); i contatori del limitatore di frequenza, fino alla pulizia della notte successiva, che gira dal 24 settembre 2026 (fino ad allora era dichiarata e non eseguita: il primo giro ha tolto 6.536 righe, la più vecchia del 19 aprile); il registro di navigazione (ultime mille voci) e la riga di uscita del registro tecnico, che ruota per dimensione; i blocchi automatici del filtro, le cui righe restano dopo la scadenza finché non si tolgono a mano; il verbale di accettazione dei Termini, fino alla cancellazione dell'account. Le sessioni sono file sul server e non contengono l'indirizzo IP. Log di accesso del server web **disattivato**. L'elenco completo, con i termini, è nel registro (Allegato F, B.6) | Informativa §3.4; `app/Services/Audit/RequestFingerprint.php` |
| **Verifica regolare (Art. 32 §1(d))** | Audit di sicurezza giugno 2026 (VA + manuale + attivo); revisione completa del codice il 23 settembre 2026; toolchain SAST/SCA/DAST/secret-scanning | §6 + report firmato |
| **Lifecycle chiavi** | Pre-flight guard su generazione/rotazione (no rigenerazioni silenziose distruttive) | Audit §4.6 |
| **Accesso amministrativo ai contenuti** | Nessuna funzione mostra all'operatore i contenuti dei docenti in chiaro; l'operatore custodisce però la chiave master e può decifrarli **nei soli casi tassativi** dei ToS §3(c) e Informativa §11-bis, con riga **append-only** in `crypto_custody_events`. Non è un'impossibilità tecnica: dettaglio in §5.3. Dal 4 settembre 2026 ogni accesso è **notificato via email al docente interessato** nel momento in cui viene registrato, e consultabile da `/me/custody-events`. Fino al 23 settembre 2026 il registro degli accessi conservava l'id di sessione in chiaro, e chi lo leggeva poteva aggirare questa procedura (A-64, §6.1) | ToS §3(c); `CustodyNotifier` |
| **Integrità dei registri** | Impronta giornaliera a blocchi dei sette registri append-only, con hash concatenati, portata fuori dal server dal backup cifrato; verifica con `--verify`. Le cancellazioni art. 17 a fine cooling-off sono eseguite da un timer giornaliero | `tools/audit/export_audit_chain.php`; `tools/systemd/pantedu-audit-chain.timer`, `pantedu-gdpr-deletions.timer` |
| **Governance chiave master (opz.)** | Possibilità di **frazionare la `KMS_MASTER_KEY` con schema *Shamir Secret Sharing*** (es. 3-su-5 o 2-su-3): la copia di sicurezza della chiave è ricostruibile solo combinando *k* quote su *n*, affidate a **custodi distinti e indipendenti scelti dal Titolare**. Protegge la copia di sicurezza, **non elimina l'accesso operativo** (§5.3) | `app/Services/Crypto/ShamirSecretSharing*` |
| **Autenticazione forte** | **Verifica in due passaggi** controllata al login: TOTP o codice via email, a scelta dell'utente, con codici di backup monouso; dal 23 settembre 2026 un codice TOTP vale una volta sola. **Obbligo imposto ai ruoli amministrativi dal 4 settembre 2026**, con motivazione a registro; facoltativa per i docenti. **SPID/CIE**: abbozzato nel codice e spento, non utilizzabile senza la registrazione come fornitore di servizi presso AgID (roadmap §7) | `app/Services/Security/TwoFactorPolicy.php` |
| **Recupero delle credenziali** | Link monouso via email, valido un'ora, token conservato come hash; la risposta del modulo è identica per indirizzo noto e sconosciuto, così da non poter essere usato per scoprire chi ha un account. Reimpostare la password non disattiva la verifica in due passaggi | `app/Services/Security/PasswordResetService.php` |
| **Accesso amministrativo Zero Trust** | L'accesso SSH al server **non è esposto su Internet**: il daemon SSH è in ascolto solo su localhost e raggiungibile esclusivamente tramite **Cloudflare Tunnel** con autenticazione **Cloudflare Access** (identità email + MFA). Nessuna porta di amministrazione aperta verso l'esterno; l'accesso diretto all'IP è bloccato. | Tunnel `cloudflared` + Access policy |

---

# 3. Conformità AgID — Misure Minime di Sicurezza ICT (ABSC)

| ABSC | Controllo | Livello | Implementazione Pantedu | Status |
|------|-----------|---------|--------------------------|--------|
| 1.x | Inventario asset/dipendenze | Minimo | Lockfile `composer.lock`/`package-lock.json` tracciati; scan SCA (osv-scanner, Trivy) | ✅ |
| 2.x | Inventario software | Minimo | Dipendenze versionate; audit SCA periodico | ✅ |
| 3.x | Configurazioni sicure | Standard | Hardening nginx/PHP, header sicurezza, WAF | ✅ |
| 4.x | **Vulnerability Assessment** | Standard | VA automatizzato esaustivo (Semgrep, Trivy, Nuclei, OWASP ZAP, osv-scanner, gitleaks/trufflehog) + test manuale | ✅ |
| 5.x | Privilegi amministrativi | Minimo | RBAC, `super_admin` separato, least privilege | ✅ |
| 8.x | Difesa malware | — | n/a (applicazione web; upload con sanitizzazione+MIME check) | n/a |
| 10.x | Backup | Standard | Backup giornaliero cifrato (systemd + B2) | ✅ |
| 13.x | Protezione dati | Alto | Cifratura envelope AES-256-GCM + key safeguard | ✅ |

Posizione tecnica formale (Art. 32 + AgID ABSC 4): *l'applicazione è stata sottoposta a Vulnerability Assessment automatizzato esaustivo con copertura SAST+SCA+DAST+secret-scanning; tutte le vulnerabilità Critical e High trovate dall'audit sono state risolte. Misura equivalente al controllo automatizzato Standard.* Le correzioni successive all'audit sono al §6.1. Il test di intrusione esterno da terza parte il Titolare lo fa eseguire prima di attivare lo Scenario 3 (§7). Le Misure Minime si rivolgono alle PA: la mappatura è offerta come riferimento per l'ipotesi dello Scenario 3 (§5).

---

# 4. Minimizzazione dei dati e DPIA (GDPR Art. 5, 35)

- **Dati trattati (docenti)**: username, nome, cognome, email — base giuridica Art. 6(1)(b) (contratto dei Termini di Servizio); indirizzo e classe — delimitano la visibilità dei contenuti pubblicati; IP e User-Agent come impronta — Art. 6(1)(f), considerando 49 (sicurezza). La **scuola è facoltativa** dal 22/9/2026 (ADR-047): non è necessaria all'erogazione, serve alle funzioni che passano per la scuola. Senza, il docente scrive i materiali, compila i modelli e ne genera il PDF, ma non pubblica né condivide con i colleghi. Conservazione: un account **senza accessi da 24 mesi** si cancella con la stessa procedura della cancellazione su richiesta (art. 17), dopo tre avvisi via email, 60, 30 e 7 giorni prima. Basta un accesso per azzerare il conto; senza l'ultimo avviso l'account non si cancella; gli account di amministrazione della piattaforma sono esclusi. La cancellazione distrugge la chiave del docente e cancella i suoi contenuti in chiaro; la riga dell'account resta come segnaposto anonimo per l'integrità dei registri; nelle copie di sicurezza i dati restano fino alla loro scadenza (§2). Registro di navigazione: **ultime mille voci**; registro delle operazioni **2 anni**; accessi privilegiati, eventi sui contenuti e uso delle chiavi di recupero **5 anni** (termine di prescrizione degli abusi che servono a ricostruire); registro dei consensi **10 anni dall'evento** (prescrizione ordinaria). I termini sono quelli dell'*Allegato F* (Registro art. 30), che resta la fonte unica.
- **Studenti: nessun dato identificativo.** Nessuno studente reale si è mai registrato; gli account studente esistiti erano account di prova creati dall'autore, ora cancellati, e la registrazione degli studenti è disattivata dal 3 settembre 2026; le tabelle degli studenti e dei consensi genitoriali sono vuote. L'accesso ai contenuti pubblicati avviene con una **credenziale del docente** (grant tecnico legato all'`id` del docente, delimitabile per indirizzo e classe): la sessione non è associata a una persona; l'IP resta in chiaro nel registro del filtro di sicurezza (30 giorni) e nei contatori del limitatore di frequenza (fino alla pulizia della notte successiva), altrove come impronta con chiave.
- **Credenziale di classe: distribuzione e revoca.** La crea il docente nel proprio pannello — un'etichetta, una password conservata come hash, un ambito facoltativo per indirizzo e classe — e la comunica in classe. Dà accesso in sola lettura ai contenuti che quel docente ha pubblicato per quella classe: nessuna scrittura, nessun profilo, nessun dato dello studente. Il docente la disattiva, la cancella o ne crea una nuova in qualunque momento. Se circolasse fuori dalla classe, l'effetto sarebbe la lettura di materiale didattico pubblicato, non l'accesso a dati personali.
- **Registrazione studente nel software.** Le modalità di registrazione studente presenti nel software (*Completa* e *Ridotta*, con data di nascita e consenso genitoriale) sono **disattivate e non attivabili su pantedu.eu**: potrebbero esserlo solo in un'istanza dello Scenario 3, con l'Istituto Titolare. Lo scenario di esercizio si seleziona dal pannello di amministrazione con motivazione a registro (ADR-032); lo Scenario 3 è attivabile soltanto su un'istanza che dichiari in configurazione l'infrastruttura qualificata ACN, e su pantedu.eu quella dichiarazione non c'è.
- **BES/DSA — NON dato sanitario Art. 9**: verificato sul codice (DPIA, 27 aprile 2026). Pantedu tratta solo **marcatori dell'esercizio** (es. "stampa N copie versione DSA"), senza collegare uno studente a una condizione. PEI, PDP, certificazioni e diagnosi non sono trattati e i ToS ne vietano il caricamento (§2.2).
- **Campi a testo libero**: nei campi strutturati nessun alunno è identificabile. *(Fino al 22 settembre 2026 qui si leggeva «per costruzione»: la formula è stata tolta dalla DPIA lo stesso giorno, dopo aver trovato due campi — nome e cognome dell'alunno della «verifica nominativa» — che il server salvava in chiaro. Misurato allora: zero righe in produzione li contenevano; da quella data il server non li accetta più.)* Titoli, note e immagini restano testo libero: vale il divieto, in ToS e AUP, di inserirvi dati personali di studenti, sotto la responsabilità del docente (ToS §4). La DPIA lo registra come rischio residuo a mitigazione organizzativa (R20).
- **Responsabili del trattamento del Titolare (Art. 28)**:
    - **Hetzner Cloud** (Germania, UE) — hosting/infrastruttura.
    - **Cloudflare** — CDN/edge security (terminazione TLS, WAF edge).
    - **Backblaze B2** — backup cifrati (i dati sono cifrati prima del caricamento).
    - **Resend, Inc.** (USA) — invio delle email di servizio (registrazione, recupero password, codice di verifica via email, notifiche): indirizzo, nome e testo del messaggio, mai contenuti didattici; clausole contrattuali tipo.
    - **Fornitori di modelli di IA** (Anthropic, OpenAI) — **solo se la funzione PDF-Import viene attivata** con un provider cloud. È **disattivata per impostazione predefinita** e il percorso preimpostato è un modello **locale** (Ollama), che non comporta alcun trasferimento.
- **Altri destinatari, che non trattano dati per conto del Titolare**:
    - **Google LLC** e **GitHub, Inc.** (USA) — solo se il docente collega il proprio account, per copiare i suoi contenuti nel suo Drive o nel suo repository: il rapporto è fra il docente e il fornitore.
    - **JGraph Ltd** (diagrams.net, Regno Unito) — il visualizzatore delle mappe si carica dai suoi server, che ricevono l'IP di chi apre la pagina, studenti compresi.
    - **GeoGebra** — l'editor GeoGebra dei docenti si carica da `geogebra.org`, che riceve l'IP del docente; agli studenti arriva solo l'immagine.
    - **CrowdSec** (Francia, UE) — se il Titolare ne configura la chiave, riceve su sua richiesta l'IP di una riga del registro del filtro, per conoscerne la reputazione.
    - **Porkbun** — solo registrar del dominio (gestione DNS; non tratta dati personali degli interessati).
    - **MaxMind/db-ip** — database GeoIP locale per il WAF (nessun invio di dati personali a terzi).

---

# 5. Titolarità e scenari d'uso

**Scenario 1 — Uso personale dell'autore (attuale).** Pantedu è gestita dall'autore per la **propria attività didattica**: materiali propri, separati per docente. I suoi studenti consultano i contenuti pubblicati per la classe — mappe, esercizi propri e, per gli esercizi tratti da libri, il solo riferimento (numero, pagina, difficoltà e libro) con la soluzione elaborata dal docente, mai la traccia del libro — con la credenziale del docente, non nominativa. **Nessun dato identificativo di studenti.** Fino al 3 settembre 2026 il software prevedeva account nominativi per gli studenti, esistiti solo come account di prova creati dall'autore: nessuno studente reale si è mai registrato. Se fosse avvenuto, dei relativi dati sarebbe stato Titolare l'Istituto, non l'autore, su un'infrastruttura incompatibile con il Regolamento cloud ACN (§5.1).

**Scenario 2 — Uso da parte di colleghi docenti (predisposto).** Docenti di qualunque scuola possono iscriversi volontariamente e usare la piattaforma per **esercizi, verifiche, mappe, laboratori e documenti propri**, ciascuno con contenuti **isolati per docente** (che cosa è cifrato: §2): un docente non accede ai contenuti di un altro, salvo che chi li ha creati li condivida esplicitamente — un contenuto alla volta o un'intera materia, con il pool del proprio istituto, con singoli colleghi o con un proprio gruppo di colleghi dello stesso istituto — e il software blocca in ogni caso, anche quando si condivide un'intera materia, il materiale derivato da libri di testo o senza una fonte dichiarata (ToS §2.1.1). Le compilazioni dei modelli di documenti scolastici (§5.2) non si condividono mai: sono del solo docente, cifrate, e si cancellano da sole. La pubblicazione agli studenti è distinta dalla condivisione fra colleghi: riguarda le classi del docente, che vi accedono con la sua credenziale. Non è consentito caricare PEI, PDP, diagnosi o altri dati particolari (ToS §2.2), né dati personali di studenti. **Titolare dei dati dei docenti è {{OPERATORE_NOME}}**: l'iscrizione non è disposta dall'Istituto, i dati sono conferiti dall'interessato, finalità e mezzi li determina l'autore (art. 4(7) GDPR), su base contrattuale (Termini di Servizio) e di interesse legittimo per i registri di sicurezza. La piattaforma **non è uno strumento d'Istituto**. I Termini di Servizio 1.6 e l'AUP 1.5 dicono quanto sopra e vietano di inserire dati personali di studenti in qualunque campo. All'apertura delle iscrizioni vale l'informativa dell'Allegato E. La configurazione adottata per i modelli di documenti scolastici è dichiarata al §5.2.

**Chi decide che cosa (art. 26 GDPR).** Si ha contitolarità quando due soggetti determinano congiuntamente finalità e mezzi. La tabella elenca le decisioni del trattamento e chi le prende:

| Decisione | Chi la prende | Ruolo dell'Istituto |
|---|---|---|
| Finalità del trattamento (erogare la piattaforma, proteggerla) | l'autore | nessuno |
| Mezzi (software, infrastruttura, misure di sicurezza) | l'autore | nessuno |
| Iscrizione del docente | il docente, a titolo personale | non la dispone, non la conosce |
| Istituto, indirizzo e classe dichiarati | il docente, dagli elenchi pubblici del Ministero (MIM) | non li fornisce né li verifica |
| Incarichi sulle sezioni (abilitazione a pubblicare per una classe) | l'amministratore della piattaforma, cioè il Titolare | nessuno; non riproduce l'assegnazione dei docenti alle classi, che resta un atto del dirigente e che la piattaforma non verifica |
| Accesso ai dati dei docenti | l'autore, nei limiti dei ToS | nessun accesso, nessun ruolo amministrativo |
| Flussi di dati verso l'Istituto | nessuno | non riceve nulla |
| Uso in classe con gli studenti | il docente, nella libertà di insegnamento | **nessuno**. La presa d'atto che la Nota del 4 settembre 2026 chiedeva alla Dirigente è ritirata: all'Istituto non serve compiere alcun atto |

**Scenario 3 — Adozione formale da parte di un Istituto, con dati di studenti.** Possibile in due sole forme, entrambe fuori dall'infrastruttura dell'autore:

- l'**Istituto conduce l'istanza**, su infrastruttura qualificata del catalogo ACN o su infrastruttura propria che rispetti i livelli minimi del Regolamento (artt. 7 e 12); l'Istituto è Titolare, il software è riutilizzato ai sensi degli artt. 68-69 CAD (licenza EUPL-1.2), e l'autore non ha alcun ruolo nel trattamento, salvo un eventuale supporto tecnico su designazione dell'Istituto;
- il software è **adottato da un fornitore già qualificato ACN**, che ne è il Responsabile del trattamento ex art. 28 verso l'Istituto.

In tale istanza le modalità di registrazione studente presenti nel software (*Completa*, *Ridotta*, *Anonima*) tornano disponibili al Titolare. La bozza di DPA trasmessa il 1° settembre, che presupponeva l'autore come Responsabile su infrastruttura propria, è **ritirata**.

## 5.1 Perché la piattaforma non tratta dati dell'Istituto (Regolamento cloud ACN)

Il Regolamento cloud ACN (Decreto direttoriale n. 21007/24 del 27 giugno 2024, applicabile dal 1° agosto 2024, attuativo dell'art. 33-septies del D.L. 179/2012) consente alle pubbliche amministrazioni — le istituzioni scolastiche comprese (art. 1(2) D.Lgs. 165/2001) — di avvalersi soltanto di infrastrutture digitali e servizi cloud **qualificati**, e pone la qualificazione a carico del fornitore (art. 17). Un docente che conduca personalmente un server non può qualificarsi come fornitore. Ne segue che dati di cui l'Istituto è Titolare — quelli degli studenti — non possono risiedere su questa infrastruttura, a qualunque titolo l'autore li trattasse. È la ragione della riconfigurazione del 3 settembre 2026: il presupposto è stato rimosso prima che si concretizzasse — nessuno studente reale si era ancora registrato — anziché cercare una copertura formale.

## 5.2 Gestione documentale

I materiali che i docenti producono sulla piattaforma — verifiche, esercizi, mappe, laboratori — sono **materiale preparatorio**: il testo che il docente prepara, non la prova somministrata e corretta, che resta documento della scuola nei suoi archivi. La loro redazione rientra nella libertà di insegnamento (art. 33 Cost.; art. 1 D.Lgs. 297/1994). Il tema non è di titolarità ma di **gestione documentale** (DPR 445/2000, artt. 50 ss.; CAD, artt. 40-44; Linee guida AgID sui documenti informatici): la piattaforma include modelli generici di documenti scolastici — piano annuale, relazione finale, scheda progetto — che il docente compila come bozza, modifica e personalizza. I modelli non incorporano alcun Istituto: l'intestazione riporta il nome e la città della scuola che il docente ha dichiarato nel profilo (senza scuola, la dicitura generica «Istituto scolastico»), e il logo compare solo se l'istanza ne ha uno per quella scuola. Il risultato è un PDF che il docente deposita nei sistemi della scuola. **La configurazione adottata** (decisione del Titolare, 22 settembre 2026) è la clausola dei Termini di Servizio §2.5, in vigore dalla versione 1.3: *«La piattaforma è strumento di redazione e preparazione. Non costituisce luogo di conservazione degli atti formali dell'Istituto.»* Vincola ogni docente che si iscrive, è opponibile e non dipende da atti di terzi.

La piattaforma dispone di altre due leve. Le decide il Titolare, e nessun soggetto esterno concorre a determinarle. La prima si imposta per scuola dal pannello di amministrazione, con la motivazione a registro; la seconda è un'impostazione dell'intera istanza, nella configurazione del server, senza registro:

| Leva | Che cosa ottiene | Che cosa **non** ottiene |
|---|---|---|
| Clausola ToS §2.5 (**adottata**) | Nessun atto formale è conservato qui: la piattaforma è luogo di redazione. Vincolo contrattuale su chi la usa | Non impedisce tecnicamente di salvare una bozza |
| Compilazione senza salvataggio (`institutes.compilation_storage`) | Della compilazione sul server **non resta copia**: la bozza sta nel browser del docente | Non esclude il **transito**: generare il PDF avviene comunque sul server, dove il testo arriva, viene trasformato e non resta (Informativa §5.1). E toglie la tracciabilità che il salvataggio dà: nessuna riga negli eventi sui contenuti, nessun avviso di accesso amministrativo |
| Modelli istituzionali nascosti (`RISDOC_INSTITUTIONAL_TEMPLATES=false`) | Esclude conservazione **e** transito: i modelli non sono utilizzabili | Toglie la funzione: il docente li compila altrove |

Le tre non sono equivalenti, e fino al 21 settembre 2026 questo documento presentava la seconda e la terza come tali («la compilazione senza salvataggio ottiene lo stesso risultato per i dati»): non è esatto, perché solo la terza esclude anche il passaggio. La tabella lo dice adesso apertamente.

Dove le compilazioni si salvano, hanno una scadenza (ADR-046): una bozza si cancella 15 giorni dopo l'ultimo scaricamento o l'ultima modifica, e comunque il 31 agosto se è ferma dal 1° giugno. Prima della cancellazione parte un avviso via email al docente, di norma sette giorni prima; se il docente non ha un indirizzo valido, la cancellazione procede lo stesso. La scadenza non tocca il transito né il PDF già scaricato.

I modelli personali — programma svolto, obiettivi disciplinari, risorse — non sono toccati da nessuna delle impostazioni.

## 5.3 Chiave di cifratura e accesso amministrativo

La chiave master risiede sul server perché la piattaforma funzioni, e la cifratura avviene sul server: Pantedu non ha una cifratura lato client. L'operatore, collega degli interessati, può decifrare i contenuti nei soli casi tassativi dei ToS §3(c), con annotazione su un registro append-only a livello di database, alterabile solo con un accesso amministrativo al server, a sua volta tracciato. L'unica misura che sottrarrebbe la chiave a chi amministra il server sarebbe una cifratura lato client con chiave nelle sole mani del docente, incompatibile con il recupero dell'accesso e con ogni elaborazione dei contenuti sul server (compilazione delle verifiche, pubblicazione per classe). Il frazionamento Shamir protegge la copia di sicurezza della chiave, non elimina l'accesso operativo. Il conflitto che deriva dall'essere l'operatore un collega degli interessati si elimina solo se il server non è condotto da lui. Due misure, attive dal 4 settembre 2026, riducono l'autocontrollo: ogni accesso amministrativo ai contenuti di un docente gli viene notificato via email nel momento in cui viene registrato, e i registri hanno un'impronta giornaliera conservata fuori dal server, che rende rilevabile un'alterazione **a una condizione** — l'object lock sul contenitore del backup, che al 22/9/2026 **non è attiva**: senza, chi amministra il server può riscrivere anche la copia remota.

## 5.4 Codice pubblico e licenza

- **Licenza software**: EUPL-1.2. Il codice dell'applicazione è pubblicato su <https://github.com/vittop89/pantedu> ed è ispezionabile senza richiesta né registrazione. La copia pubblica è ripulita: ne sono esclusi alcuni documenti operativi (custodia delle chiavi, cooperazione con le autorità), gli strumenti d'accesso al server e i rapporti di audit, e i nomi reali sono sostituiti da segnaposto. Il repository include i metadati `publiccode.yml` previsti da Developers Italia e la **candidatura al catalogo `developers.italia.it` è stata depositata il 23 giugno 2026** ([italia/catalogo-software#12](https://github.com/italia/catalogo-software/issues/12)), attualmente in attesa di istruttoria. L'integrazione SPID/CIE non è attiva e non è requisito per l'inserimento in catalogo di software di terze parti.

---

# 6. Sintesi dell'audit di sicurezza (giugno 2026)

- **Metodologia**: audit assistito uomo + AI su metodologia standardizzata (13 fasi), eseguito su **clone isolato con dati fittizi** (mai su produzione con dati reali) + validazione passiva in produzione. Toolchain: Semgrep, Trivy, osv-scanner, gitleaks, trufflehog, Nuclei, OWASP ZAP, Schemathesis, testssl, più test attivo manuale (IDOR/BOLA, CSRF, verb-tampering, injection, file-read).
- **Esito**, al perimetro e alla data dell'audit: **postura solida. Nessuna vulnerabilità Critical/High residua.**
- **Finding e stato** (11 totali):
    - **Corretti e in produzione (7)**: dipendenza HTTP vulnerabile; dipendenze del servizio di compilazione; sink XSS legacy; CSRF su form di contatto pubblico; tightening dei verbi HTTP sulle azioni di stato; sanitizzazione SVG uniforme; lettura file via include LaTeX sul servizio isolato di compilazione.
    - **Basso impatto / mitigati (4)**: codice legacy non raggiungibile in produzione; flag di rate-limit (compensato dal WAF in enforce); file di configurazione tracciato (senza segreti reali); dipendenza solo-sviluppo.
- **Controlli risultati NEGATIVI (assenza di vulnerabilità)**: SQL injection, IDOR/BOLA cross-utente, privilege escalation, open redirect, mass assignment, XSS verso studenti, RCE.
- **Report tecnico completo firmato** (con hash-chain di integrità) **disponibile su richiesta**. *Nota di trasparenza*: la metodologia AI-assistita rappresenta evidenza di *due diligence* (Art. 24/32) ma non sostituisce un test di intrusione professionale certificato, che il Titolare fa eseguire prima di attivare lo Scenario 3 (§7).

## 6.1 Correzioni successive all'audit

Gli esiti qui sopra sono riferiti al perimetro e alla data dell'audit (giugno
2026) e non sono una garanzia permanente. Le correzioni successive che li
toccano si riportano qui, perché la circostanza che ne ha contenuto il rischio
— i soli account docente erano del Titolare o di prova — viene meno aprendo le
iscrizioni. Al 24 settembre 2026 non è nota alcuna vulnerabilità Critical/High
residua.

**21 settembre 2026 — autorizzazione (IDOR).** La rotta che consegnava il
pacchetto TeX esportato verificava che chi scaricava fosse **un** docente e non
**il proprietario** del pacchetto; chi ne avesse conosciuto il nome — sedici
caratteri casuali, un'ora di vita, non elencabile — avrebbe potuto scaricare il
lavoro di un altro. Nessun accesso non autorizzato è avvenuto, perché a quella
data l'unico docente reale era l'autore, e la valutazione ex art. 33 §5 è nella
storia delle revisioni della DPIA. La rotta non esiste più: il pacchetto si
consegna nel corpo della risposta e non resta su disco.

**23 settembre 2026 — revisione completa del codice.** Ha trovato difetti
corretti lo stesso giorno, tutti in produzione. Tre, di gravità alta, toccano
gli esiti del §6 o la protezione dei dati:

- un docente autenticato che vedesse un modello pubblico di documento poteva
  leggere file dell'applicazione, configurazione compresa (A-2). Prima di
  correggere si sono letti i registri di produzione disponibili, fra cui quello
  del filtro di sicurezza dal 10 agosto: nessuna richiesta cercava file fuori
  dai modelli;
- il file di una mappa poteva eseguire script nel browser di chi apriva la
  pagina di studio, studenti e ospiti compresi (A-3). Da allora una mappa si
  accetta solo come XML drawio verificato;
- l'esportazione firmata dei registri per l'autorità riportava vuoti due
  registri che non lo erano (A-62).

Di gravità media: l'impronta dell'IP nei registri si calcolava su intestazioni
che il client può scegliere (A-63); il registro degli accessi conservava l'id di
sessione in chiaro, e chi lo leggeva — il super-amministratore o chi amministra
il server — poteva usare la sessione di un docente senza passare dalla
procedura con motivazione, registrazione e avviso (A-64); un codice TOTP era
riusabile per circa 90 secondi, e un account disattivato restava dentro fino a
12 ore (A-65, A-66); i gettoni di conferma della cancellazione e del consenso
stavano in chiaro nel database (A-67); 50 azioni amministrative su 81 non
chiedevano la motivazione, che da allora è obbligatoria su tutte (A-69); i
contatori del limitatore conservavano l'IP senza che nessun lavoro li pulisse
(A-84: pulizia giornaliera attiva dal 24 settembre). A quella data gli unici
account docente erano del Titolare o di prova, e super-amministratore e accesso
al server erano del solo Titolare: i difetti che richiedevano uno di questi
accessi (A-2, A-3, A-64) non potevano essere usati da terzi.

**24 settembre 2026 — chiave master (A-88).** Fino a quel giorno il valore della
chiave master di produzione era scritto in chiaro in un documento
dell'autovalutazione di sicurezza di aprile 2026, nel repository privato di
sviluppo e nelle sue copie; non nella copia pubblica. Il 24 settembre, fra le
00:25 e le 00:55, la chiave è stata cambiata; le chiavi dei singoli docenti
restano le stesse, riavvolte con quella nuova. La chiave precedente proteggeva
solo le chiavi di tre account, tutti del Titolare (uno è di prova), e una chiave
di recupero. Valutazione ex art. 33 §5 del Titolare: non è una violazione di
dati di terzi; è nel registro degli incidenti e nella DPIA 1.23. Restano da
togliere il valore da quel documento e dalla storia del repository; la chiave
precedente, conservata sigillata fuori linea, si distrugge non prima del 24
ottobre 2026.

---

# 7. Fatto il 3 e il 4 settembre 2026, e roadmap

**Fatto.**

1. **Termini di Servizio 1.3 e AUP 1.2** (3 settembre; AUP 1.3 il 4, per le sole note di stato): tolte le formule del vecchio inquadramento — Preambolo (informativa «fornita dall'Istituto»), §1(b) («autorizzato dalla Scuola»), §2.1.2 (riferimento nominativo al Liceo), §4(a); nuovi §2.4 (divieto di inserire dati personali di studenti in qualunque campo) e §2.5 (clausola sugli atti formali); §3(a) (hash di IP/UA); §3(c) (Shamir: protegge la copia di sicurezza, non elimina l'accesso operativo).
2. **Hash di IP e User-Agent** in `content_action_log`, `privileged_access_log`, `teacher_recovery_audit` e, per lo User-Agent, `audit_activity_log`, con conversione delle righe esistenti (migration 100). Purga dei log privilegiati allineata ai cinque anni dichiarati: il job ne conservava dieci.
3. **Log di accesso del server web** disattivato.
4. **Credenziale di classe** (4 settembre 2026): la verifica pre-rilascio ha mostrato che il grant di sessione non era letto dagli endpoint di studio, e l'accesso «con la credenziale del docente» non mostrava nulla. Corretto: l'ospite con credenziale vede solo i contenuti pubblicati del docente della credenziale, per la sua classe; senza credenziale, solo i contenuti resi pubblici. Il blocco della condivisione del materiale da libri di testo è esteso anche ai grant verso singoli colleghi (ToS §2.1.1). Nello stesso giro: le compilazioni dei modelli risdoc, che nel database stavano in chiaro, sono ora cifrate con la chiave del docente; rimosso il Modulo di autorizzazione, residuo del modello con credenziali nominative per minorenni; senza account studente, i campi dei modelli riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio.
5. **4 settembre 2026, seconda tornata**: secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico al docente quando un accesso amministrativo ai suoi contenuti viene registrato; impronta giornaliera dei registri fuori dal server; cancellazioni art. 17 eseguite da un timer giornaliero (il job mancava); conservazioni riviste per l'art. 5(1)(e) — audit a cinque anni, consensi a dieci dall'evento, WAF a trenta giorni, backup a un anno con ri-cancellazione dopo ripristino; Resend, servizio di posta, fra i responsabili del trattamento; modelli risdoc resi generici — l'intestazione non incorpora più alcun Istituto: riporta quello dichiarato dal docente, e il logo è un file dell'istanza — con impostazione per Istituto sul salvataggio delle compilazioni (sul server o solo nel browser del docente) e interruttore per nasconderli del tutto.

**Roadmap.**

6. **Test di intrusione esterno** da terza parte, certificato: il Titolare lo fa eseguire prima di attivare lo Scenario 3, come dice la DPIA (§6). Negli scenari 1 e 2 non è una condizione: l'art. 32 chiede misure adeguate anche in rapporto ai costi di attuazione, e per una piattaforma gratuita condotta da una persona fisica la misura adottata è l'audit su clone isolato con toolchain standard, con le correzioni successive (§6 e §6.1), e il codice pubblico e ispezionabile da chiunque.
7. **SPID/CIE** come metodo di accesso: rilevante solo per un'istanza dello Scenario 3 — SDK `italia/spid-cie-php` / eID-Gateway MIM.
8. **Object lock** sul bucket remoto dei backup, così che l'impronta giornaliera dei registri non sia riscrivibile nemmeno da chi amministra il server.

---

# 8. Disponibilità e contatti

L'autore si rende disponibile a chiarimenti tecnici, approfondimenti su singoli controlli e fornitura del report di audit firmato.

**Contatto**: {{OPERATORE_EMAIL}} — PEC: <pec operatore> — modulo per le richieste privacy e l'esercizio dei diritti: https://pantedu.eu/dpo-contact (recapito privacy del Titolare: {{OPERATORE_EMAIL}}; un responsabile della protezione dei dati non è designato, art. 37)

## Allegati (schema unico A–F)

- **A — Questo Pacchetto di accountability** — `docs/dpo/pacchetto-scuola/Pacchetto-DPO-pantedu.pdf` (misure Art. 32, AgID, minimizzazione+DPIA sintesi, titolarità). La fonte unica dell'elenco dei trattamenti, dei responsabili e degli altri destinatari resta l'*Allegato F*: in caso di divergenza prevale il Registro.
- **B — Bozza DPA Art. 28** — **ritirata il 3 settembre 2026** (vedi §5).
- **C — Report di audit di sicurezza firmato** (hash-chain di integrità, giugno 2026) — consegna **su richiesta**, preferibilmente con un accordo di riservatezza. Descrive lo stato di giugno: le correzioni successive, compreso il cambio della chiave master del 24 settembre, sono al §6.1
- **D — DPIA, adottata dal Titolare il 25 settembre 2026** — `docs/privacy/dpia.pdf` (sorgente: `docs/privacy/dpia.md`)
- **E — Informativa privacy per lo Scenario 2** — `docs/privacy/informativa.pdf` (sorgente: `docs/privacy/informativa.md`). Vale dall'apertura delle iscrizioni ai colleghi, quando la pagina `https://pantedu.eu/privacy/informativa` la servirà; fino ad allora, nello Scenario 1, la stessa pagina serve l'informativa per l'uso personale (`docs/privacy/informativa-personale.md`, versione 1.3)
- **F — Registro delle attività di trattamento (Art. 30)** — `docs/privacy/registro-trattamenti.pdf` (sorgente: `docs/privacy/registro-trattamenti.md`; fonte unica dell'elenco dei trattamenti e dei destinatari; il sorgente è pubblicato anche nella copia pubblica del codice; esibibile al Garante su richiesta, art. 30 §4)

---

# Storia delle revisioni

| Versione | Data | Modifica |
|---|---|---|
| 1.7 | 2026-09-25 | **Allineato al codice e agli altri allegati dopo le correzioni del 23 e del 24 settembre.** La DPIA è detta dovuta, con il provvedimento del Garante n. 467/2018, non «come se fosse dovuta». §0 e §5, Scenario 2: la condivisione si può fare anche per un'intera materia, e il blocco del materiale dei libri vale anche lì (fino al 24 settembre la materia intera lo scavalcava; mai accaduto, perché non ci sono colleghi iscritti); le compilazioni dei modelli non si condividono mai. Titolo e §0: è la documentazione di accountability del Titolare, trasmessa per conoscenza. §0, §2, §3 e **§6.1 nuovo**: le correzioni successive all'audit (21/9; A-2, A-3, A-62 di gravità alta e A-63, A-64, A-65, A-66, A-67, A-69, A-84 del 23/9) e il cambio della chiave master del 24/9 (A-88); la postura dichiarata è riferita al 24/9. §2: che cosa è cifrato e che cosa resta in chiaro, al posto di «contenuti cifrati»; chiave master fuori dal repository solo dal 24/9; impronta degli IP con chiave dal 24/9, dato pseudonimo; depositi in chiaro: tolte le sessioni, che non contengono l'IP, aggiunti il limitatore (pulizia giornaliera dichiarata e non eseguita fino al 24/9) e gli altri archivi tecnici; le copie di sicurezza contengono database e chiave master, e i dati vi restano fino alla scadenza delle copie, non «protetti dalla cifratura per docente»; SPID/CIE abbozzato e spento, non assente. §4: frase sulla scuola corretta (era sgrammaticata e, alla lettera, falsa); a 24 mesi senza accessi l'account si cancella dopo tre avvisi, al posto dell'anonimizzazione a 730 giorni, che non faceva quanto dichiarato; responsabili separati dagli altri destinatari, aggiunti GitHub, GeoGebra e CrowdSec; tolta la data della cancellazione degli account studente di prova, che era inesatta, e «dati fittizi» (gli indirizzi email erano dell'autore). §5: agli studenti, degli esercizi dei libri, solo il riferimento; riga sugli incarichi; tabella sulla contitolarità in forma di fatti; intestazione dei modelli senza scuola; §5.2: la motivazione a registro vale solo per la prima leva, aggiunta la scadenza delle bozze; §5.3: nessuna cifratura lato client; §5.4 nuovo: la copia pubblica è ripulita, non «integrale». §7 punto 6: tolta la disponibilità a un pentest «finanziato dall'Istituto o da chi lo richieda», che la 1.5 aveva lasciato; il Titolare lo fa eseguire prima dello Scenario 3. §8: modulo per le richieste privacy, non «del DPO»; l'allegato E vale dall'apertura delle iscrizioni, mentre in rete c'è l'informativa per l'uso personale; il registro è pubblico, non «interno». Termini citati: 1.6, AUP 1.5. Storia delle revisioni: tolte le note di lavorazione. |
| 1.6 | 2026-09-22 | **Quattro incoerenze interne corrette.** (1) Il §5.3 si chiudeva ancora con «Su richiesta, il frazionamento Shamir con un custode indicato dall'Istituto»: la 1.5 l'aveva tolto dalla tabella delle misure ma non da qui. I custodi, se il frazionamento si attiva, sono terzi indipendenti scelti dal Titolare. (2) La tabella del §5 diceva che la presa d'atto «non va concessa», cioè chiedeva una condotta all'Istituto: ora dice che all'Istituto non serve compiere alcun atto. (3) Il §4 dava 365 giorni al log di accesso, un termine che nessun codice applicava: il registro di navigazione si tronca alle ultime mille voci (DPIA 1.22, registro 1.19). (4) I Termini citati sono ora la 1.5, in vigore dal 22 settembre, e l'AUP la 1.4; §2.1.1, §2.5 e §3(c) sono identici alla 1.4. Il §7, che racconta il 3 settembre, torna a citare i Termini 1.3, la versione di quel giorno: la 1.4 l'aveva allineata per errore. La storia delle revisioni è ora in un solo ordine, e ha la riga 1.5 che mancava. |
| 1.5 | 2026-09-22 | **Tolte tre disponibilità verso l'Istituto**: il custode della chiave «indicato dall'Istituto» nella tabella delle misure, le due leve «disponibili se un Istituto le chiedesse» (ora le decide il Titolare) e la «valutazione delle misure aggiuntive richieste dal DPO» fra le disponibilità dell'autore. **La scuola è facoltativa** fra i dati dei docenti (ADR-047). Corretta la formula «per costruzione» sui campi strutturati, tolta lo stesso giorno dalla DPIA. La DPIA si dichiara mantenuta come se fosse dovuta. Tolti i numeri di versione dall'elenco degli allegati. *(Questa riga è stata scritta con la 1.6: la 1.5 era uscita senza.)* |
| 1.4 | 2026-09-22 | **Citazioni dei Termini allineate alla 1.4**, che è la versione in vigore dal 22 settembre secondo `docs/legal/versions.json`: il pacchetto ne citava ancora la 1.3 in quattro punti, e la clausola §2.5 su cui poggia il §5.2 è identica nelle due versioni. **Nella stessa occasione**, corretta una contraddizione dentro i Termini stessi — dichiaravano 1.4 in testa e 1.3 in fondo, con due date diverse — e la data del frontmatter dell'AUP, che diceva 4 settembre mentre la 1.4 è in vigore dal 22. |
| 1.3 | 2026-09-22 | **Tolta dalla tabella del §5 la casella «presa d'atto della Dirigente (richiesta nella Nota)»** nella colonna «Ruolo dell'Istituto»: la richiesta è ritirata dal 22 settembre. Allegato D aggiornato alla DPIA 1.14, F al registro 1.14. |
| 1.2 | 2026-09-22 | **Rettificato l'esito «IDOR/BOLA cross-utente: negativo»** del §6. È riferito al perimetro e alla data dell'audit (giugno 2026), e il 21 settembre 2026 è stato trovato e corretto un difetto proprio di quella classe: la rotta che consegnava il pacchetto TeX verificava che chi scaricava fosse *un* docente e non *il proprietario*. Nessun accesso non autorizzato è avvenuto — a quella data l'unico docente reale era l'autore — e la valutazione ex art. 33 §5 è nella DPIA. Allegato D aggiornato alla DPIA 1.12. |
| 1.1 | 2026-09-22 | **Il documento acquista numero, data e questa storia.** Fino a qui non li aveva, ed era stato modificato il 21 settembre restando datato 4. **§5.2 riscritto**: la gestione documentale è la configurazione adottata dal Titolare — la clausola ToS §2.5, già in vigore — con una tabella che mette a confronto le tre leve disponibili e dice che cosa ciascuna **non** ottiene. Fino al 21 settembre il documento presentava la compilazione senza salvataggio e l'interruttore dei modelli come equivalenti «per i dati»: non lo sono, perché solo il secondo esclude anche il transito, e la frase è stata corretta. **Scenario 2**: l'apertura delle iscrizioni è decisione del Titolare, come impone l'art. 24, e non è più descritta come subordinata a un parere esterno. Allegati D, E ed F aggiornati alle versioni correnti (1.11, 2.11, 1.12): il documento rinviava ancora a 1.5, 2.3 e 1.5. |
| 1.0 | 2026-09-04 | Versione trasmessa il 4 settembre 2026 al DPO dell'Istituto insieme alla Nota di aggiornamento. Riscriveva sui tre scenari la stesura inviata il 1° settembre, dopo l'osservazione sul Regolamento cloud ACN; bozza di DPA art. 28 ritirata. |
