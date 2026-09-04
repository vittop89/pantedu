---
title: "Pantedu — Pacchetto di accountability per il DPO/RPD scolastico"
subtitle: "Documentazione tecnica e organizzativa ai sensi GDPR Art. 24, 32, 35"
author: "{{OPERATORE_NOME}} — sviluppatore e operatore di Pantedu"
date: "2026-09-04"
lang: it
---

# 0. Sintesi per il DPO/RPD (in una pagina)

**Pantedu** è una piattaforma didattica web (materiali didattici di **qualsiasi disciplina**: esercizi, verifiche, mappe concettuali, laboratori, documenti) sviluppata e gestita personalmente da **{{OPERATORE_NOME}}**. L'autore la utilizza per le proprie materie (matematica/fisica), ma la piattaforma è **agnostica rispetto alla disciplina**. Questo documento è il pacchetto di *accountability* GDPR predisposto per il DPO/RPD dell'Istituto in cui l'autore insegna; **aggiornato il 4 settembre 2026** a seguito dell'osservazione del DPO del 3 settembre sul Regolamento cloud ACN (vedi §5.1).

- **Nessun dato identificativo di studenti.** Nessuno studente reale si è mai registrato: gli account studente esistiti finora erano account di prova con dati fittizi, cancellati il 3 settembre 2026 insieme alla disattivazione della registrazione. Gli studenti consultano i contenuti pubblicati tramite una **credenziale del docente**, eventualmente delimitata per classe e **non nominativa**: nessuna sessione è associata a una persona. Restano i soli dati tecnici di connessione, come per qualunque sito: l'IP nel log di sicurezza per trenta giorni, altrove come hash (§2). Le funzioni di registrazione studente restano nel software, disattivate (§4).
- **Natura d'uso.** **(1)** uso personale dell'autore per la propria didattica; **(2)** uso da parte di colleghi docenti — di qualunque scuola — per esercizi, verifiche, mappe, laboratori e documenti propri, con isolamento cifrato per docente e condivisione solo su scelta esplicita del docente che li ha creati, verso colleghi dello stesso istituto, mai per materiale derivato da libri di testo (predisposto; apertura delle iscrizioni dopo il parere del DPO); **(3)** adozione formale da parte di un Istituto, con dati di studenti: possibile **solo** su infrastruttura qualificata ACN condotta dall'Istituto o da un fornitore qualificato, **mai** su infrastruttura dell'autore. Dettaglio in §5.
- **Titolarità.** Dei dati dei docenti che si iscrivono è **Titolare {{OPERATORE_NOME}}** (art. 4(7) GDPR): iscrizione volontaria, dati conferiti dall'interessato, finalità e mezzi determinati dall'autore. La piattaforma **non è uno strumento d'Istituto**; la titolarità dell'Istituto sui dati dei propri docenti attiene alla gestione del personale e resta distinta.
- **Postura di sicurezza.** L'applicazione è stata sottoposta (giugno 2026) a un **audit di sicurezza esaustivo** (VA automatizzato + verifica manuale + test attivo su clone isolato). **Nessuna vulnerabilità Critical/High residua**; tutti i finding sono stati corretti e sono in produzione. Sintesi in §6.
- **Minimizzazione.** I marcatori BES/DSA sono metadati dell'esercizio, **non** dati sanitari Art. 9; PEI, PDP, certificazioni e diagnosi non sono trattati e i Termini ne vietano il caricamento. Nessun dato particolare ex Art. 9.
- **Dati nell'UE.** Hosting su **Hetzner Cloud, datacenter di Norimberga (Germania, UE)**. Cifratura a riposo e in transito.

---

# 1. Inquadramento normativo

| Norma | Riferimento | Rilevanza |
|------|-------------|-----------|
| GDPR | Art. 4(7), 6(1)(b), 6(1)(f) | Titolarità e basi giuridiche dei dati dei docenti (§5) |
| GDPR | Art. 5(1)(c) | Minimizzazione dei dati |
| GDPR | Art. 24, 32 | Misure tecniche e organizzative adeguate + accountability |
| GDPR | Art. 8 | Consenso dei minori — **non più applicabile** dal 3 settembre 2026: nessun dato identificativo di studenti |
| GDPR | Art. 33/34 | Notifica violazioni 72h |
| GDPR | Art. 35 | DPIA — redatta come buona pratica (Allegato D) |
| **Regolamento cloud ACN** | Decreto direttoriale n. 21007/24 del 27 giugno 2024, attuativo dell'art. 33-septies D.L. 179/2012 | Le PA si avvalgono solo di infrastrutture e servizi cloud qualificati: ragione per cui la piattaforma non tratta dati dell'Istituto (§5.1) |
| DPR 445/2000; CAD (D.Lgs. 82/2005) | artt. 50 ss.; artt. 40-44 | Gestione documentale: la piattaforma è strumento di redazione, non di conservazione degli atti (§5.2) |
| Cost.; D.Lgs. 297/1994 | art. 33; art. 1 | Libertà di insegnamento: il materiale preparatorio è del docente (§5.2) |
| AgID | Misure Minime di Sicurezza ICT (Circ. 2/2017, Piano Triennale 2024-2026) | 20 controlli ABSC (§3) |
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
| **Cifratura a riposo** | Envelope encryption **AES-256-GCM** per i contenuti sensibili, **KEK per-docente**; chiave master (`KMS_MASTER_KEY`) **fuori dal repository**; guardia anti-rigenerazione distruttiva delle chiavi | `app/Core/Crypto*`, audit §4.6 |
| **Controllo accessi** | RBAC con principio del privilegio minimo; ruolo `super_admin` separato; authz *per-owner* sui contenuti (verificata: accessi cross-utente → 403/404). Dal 4 settembre 2026 l'operatore amministra con un account distinto dal proprio account docente, con secondo fattore obbligatorio | Audit IDOR/BOLA = negativo |
| **CSRF** | Token di sessione obbligatorio su tutte le richieste di stato (POST/PUT/PATCH/DELETE) | Audit (403 senza token) |
| **WAF** | Web Application Firewall self-hosted **in enforce**: geo IT-only, Proof-of-Work, protezione brute-force, threat-intel, anti-spoofing edge | Stato prod verificato: `enabled=1, mode=enforce` |
| **Hardening output** | Sanitizzazione HTML (HTMLPurifier) e SVG (svg-sanitize) sui contenuti resi agli utenti; MIME magic-byte sniffing sugli upload | Audit (XSS verso studenti = mitigato) |
| **SQL** | Query 100% PDO *prepared* (nessuna concatenazione di input) | Audit (SQLi = nessuna) |
| **Header sicurezza** | CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy, COOP; cookie `Secure; HttpOnly; SameSite` | Verificato live |
| **Backup** | Backup giornaliero **cifrato lato client** (systemd + Backblaze B2), archivio fuori dal server; rotazione a livelli (3 giornalieri, 4 settimanali, 6 mensili, 1 annuale: al massimo un anno). La cifratura per docente protegge i contenuti anche nei backup, non le anagrafiche, che restano fino alla rotazione; dopo ogni ripristino le cancellazioni successive alla copia vengono rieseguite (`docs/security/operations/restore-reerasure.md`); nessuna copia ha mai contenuto dati di studenti reali | `tools/backup/*`, memoria ops |
| **IP e User-Agent** | Conservati **come hash SHA-256** in tutti i registri di audit (operazioni, contenuti, accessi privilegiati, chiavi di recupero, consensi, recupero password). Il 3 settembre 2026 quattro registri risultavano in chiaro contro quanto dichiarato: codice corretto e righe esistenti convertite (migration 100, `RequestFingerprint`). In chiaro restano solo i log di sicurezza a conservazione breve (WAF 30 giorni, contatori anti-abuso, sessioni attive, log di navigazione delle ultime mille voci) e il verbale di accettazione dei Termini. Log di accesso del server web **disattivato** | Informativa §3.4; `database/migrations/100_audit_ip_ua_hash.sql` |
| **Verifica regolare (Art. 32 §1(d))** | Audit di sicurezza giugno 2026 (VA + manuale + attivo); toolchain SAST/SCA/DAST/secret-scanning | §6 + report firmato |
| **Lifecycle chiavi** | Pre-flight guard su generazione/rotazione (no rigenerazioni silenziose distruttive) | Audit §4.6 |
| **Accesso amministrativo ai contenuti** | Nessuna funzione mostra all'operatore i contenuti dei docenti in chiaro; l'operatore custodisce però la chiave master e può decifrarli **nei soli casi tassativi** dei ToS §3(c) e Informativa §11-bis, con riga **append-only** in `crypto_custody_events`. Non è un'impossibilità tecnica: dettaglio in §5.3. Dal 4 settembre 2026 ogni accesso è **notificato via email al docente interessato** nel momento in cui viene registrato, e consultabile da `/me/custody-events` | ToS 1.3 §3(c); `CustodyNotifier` |
| **Integrità dei registri** | Impronta giornaliera a blocchi dei sette registri append-only, con hash concatenati, portata fuori dal server dal backup cifrato; verifica con `--verify`. Le cancellazioni art. 17 a fine cooling-off sono eseguite da un timer giornaliero | `tools/audit/export_audit_chain.php`; `tools/systemd/pantedu-audit-chain.timer`, `pantedu-gdpr-deletions.timer` |
| **Governance chiave master (opz.)** | Possibilità di **frazionare la `KMS_MASTER_KEY` con schema *Shamir Secret Sharing*** (es. 3-su-5 o 2-su-3): la copia di sicurezza della chiave è ricostruibile solo combinando *k* quote su *n*, affidate a **custodi distinti** scelti dal Titolare; su richiesta, uno di essi può essere persona indicata dall'Istituto, senza ruolo di governo. Protegge la copia di sicurezza, **non elimina l'accesso operativo** (§5.3) | `app/Services/Crypto/ShamirSecretSharing*` |
| **Autenticazione forte** | **Verifica in due passaggi** controllata al login: TOTP o codice via email, a scelta dell'utente, con codici di backup monouso. **Obbligo imposto ai ruoli amministrativi dal 4 settembre 2026**, con motivazione a registro; facoltativa per i docenti. **SPID/CIE**: non integrato (roadmap §7) | `app/Services/Security/TwoFactorPolicy.php` |
| **Recupero delle credenziali** | Link monouso via email, valido un'ora, token conservato come hash; la risposta del modulo e' identica per indirizzo noto e sconosciuto, cosi' da non poter essere usato per scoprire chi ha un account. Reimpostare la password non disattiva la verifica in due passaggi | `app/Services/Security/PasswordResetService.php` |
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

Posizione tecnica formale (Art. 32 + AgID ABSC 4): *l'applicazione è stata sottoposta a Vulnerability Assessment automatizzato esaustivo con copertura SAST+SCA+DAST+secret-scanning; tutte le vulnerabilità Critical e High sono state risolte prima/durante il rilascio. Misura equivalente al controllo automatizzato Standard. Un pentest manuale certificato resta raccomandato per l'evoluzione futura.* Le Misure Minime si rivolgono alle PA: la mappatura è offerta come riferimento per l'ipotesi dello Scenario 3 (§5).

---

# 4. Minimizzazione dei dati e DPIA (GDPR Art. 5, 35)

- **Dati trattati (docenti)**: username, nome, cognome, email — base giuridica Art. 6(1)(b) (contratto dei Termini di Servizio); istituto, indirizzo e classe — necessari a **delimitare la visibilità** dei contenuti pubblicati; IP e User-Agent come hash — Art. 6(1)(f), considerando 49 (sicurezza). Conservazione: **730 giorni di inattività → anonimizzazione automatica** (`app/Config/retention.php`); log di accesso **365 giorni**, registro delle operazioni **2 anni**, accessi privilegiati, eventi sui contenuti e uso delle chiavi di recupero **5 anni** (termine di prescrizione degli abusi che servono a ricostruire), registro dei consensi **10 anni dall'evento** (prescrizione ordinaria). I termini sono quelli dell'*Allegato F* (Registro art. 30), che resta la fonte unica.
- **Studenti: nessun dato identificativo.** Nessuno studente reale si è mai registrato; gli account di prova, con dati fittizi, sono stati cancellati il 3 settembre 2026 e la registrazione è disattivata; le tabelle degli studenti e dei consensi genitoriali sono vuote. L'accesso ai contenuti pubblicati avviene con una **credenziale del docente** (grant tecnico legato all'`id` del docente, delimitabile per indirizzo e classe): la sessione non è associata a una persona; l'IP resta nel solo log di sicurezza per trenta giorni, altrove come hash.
- **Credenziale di classe: distribuzione e revoca.** La crea il docente nel proprio pannello — un'etichetta, una password conservata come hash, un ambito facoltativo per indirizzo e classe — e la comunica in classe. Dà accesso in sola lettura ai contenuti che quel docente ha pubblicato per quella classe: nessuna scrittura, nessun profilo, nessun dato dello studente. Il docente la disattiva, la cancella o ne crea una nuova in qualunque momento. Se circolasse fuori dalla classe, l'effetto sarebbe la lettura di materiale didattico pubblicato, non l'accesso a dati personali.
- **Registrazione studente nel software.** Le modalità di registrazione studente presenti nel software (*Completa* e *Ridotta*, con data di nascita e consenso genitoriale) sono **disattivate e non attivabili su pantedu.eu**: potrebbero esserlo solo in un'istanza dello Scenario 3, con l'Istituto Titolare. Lo scenario di esercizio si seleziona dal pannello di amministrazione con motivazione a registro (ADR-032); lo Scenario 3 è attivabile soltanto su un'istanza che dichiari in configurazione l'infrastruttura qualificata ACN, e su pantedu.eu quella dichiarazione non c'è.
- **BES/DSA — NON dato sanitario Art. 9**: verificato sul codice (DPIA, 27 aprile 2026). Pantedu tratta solo **marcatori dell'esercizio** (es. "stampa N copie versione DSA"), senza collegare uno studente a una condizione. PEI, PDP, certificazioni e diagnosi non sono trattati e i ToS ne vietano il caricamento (§2.2).
- **Campi a testo libero**: nei campi strutturati nessun alunno è identificabile, per costruzione. Titoli, note e immagini restano testo libero: vale il divieto, in ToS e AUP, di inserirvi dati personali di studenti, sotto la responsabilità del docente (ToS §4). La DPIA lo registra come rischio residuo a mitigazione organizzativa (R20).
- **Responsabili del trattamento del Titolare (Art. 28)**:
  - **Hetzner Cloud** (Germania, UE) — hosting/infrastruttura.
  - **Cloudflare** — CDN/edge security (terminazione TLS, WAF edge).
  - **Backblaze B2** — backup cifrati (i dati sono cifrati prima del caricamento).
  - **Resend, Inc.** (USA) — invio delle email di servizio (registrazione, recupero password, codice di verifica via email, notifiche): indirizzo, nome e testo del messaggio, mai contenuti didattici; clausole contrattuali tipo.
  - **Google LLC** — solo su **opt-in** esplicito del docente (OAuth/Drive per import facoltativo).
  - **Fornitori di modelli di IA** (Anthropic, OpenAI) — **solo se la funzione PDF-Import viene attivata** con un provider cloud. È **disattivata per impostazione predefinita** e il percorso preimpostato è un modello **locale** (Ollama), che non comporta alcun trasferimento.
  - **Porkbun** — solo registrar del dominio (gestione DNS; non tratta dati personali degli interessati → non responsabile del trattamento).
  - **MaxMind/db-ip** — database GeoIP locale per il WAF (nessun invio di dati personali a terzi).

---

# 5. Titolarità e scenari d'uso

**Scenario 1 — Uso personale dell'autore (attuale).** Pantedu è gestita dall'autore per la **propria attività didattica**: materiali propri, cifrati per docente. I suoi studenti consultano i contenuti pubblicati per la classe — mappe, esercizi propri e, per gli esercizi tratti da libri, il solo riferimento bibliografico — con la credenziale del docente, non nominativa. **Nessun dato identificativo di studenti.** Fino al 3 settembre 2026 il software prevedeva account nominativi per gli studenti, esistiti solo come account di prova con dati fittizi: nessuno studente reale si è mai registrato. Se fosse avvenuto, dei relativi dati sarebbe stato Titolare l'Istituto, non l'autore, su un'infrastruttura incompatibile con il Regolamento cloud ACN (§5.1).

**Scenario 2 — Uso da parte di colleghi docenti (predisposto; apertura dopo il parere del DPO).** Docenti di qualunque scuola possono iscriversi volontariamente e usare la piattaforma per **esercizi, verifiche, mappe, laboratori e documenti propri**, ciascuno con contenuti **isolati e cifrati per docente**: un docente non accede ai contenuti di un altro, salvo che chi li ha creati li condivida esplicitamente — con il pool del proprio istituto o con singoli colleghi dello stesso istituto — e il software blocca in ogni caso la condivisione del materiale derivato da libri di testo (ToS §2.1.1). La pubblicazione agli studenti è distinta dalla condivisione fra colleghi: riguarda le classi del docente, che vi accedono con la sua credenziale. Non è consentito caricare PEI, PDP, diagnosi o altri dati particolari (ToS §2.2), né dati personali di studenti. **Titolare dei dati dei docenti è {{OPERATORE_NOME}}**: l'iscrizione non è disposta dall'Istituto, i dati sono conferiti dall'interessato, finalità e mezzi li determina l'autore (art. 4(7) GDPR), su base contrattuale (Termini di Servizio) e di interesse legittimo per i registri di sicurezza. La piattaforma **non è uno strumento d'Istituto**. I Termini di Servizio 1.3 e l'AUP 1.3 dicono quanto sopra e vietano di inserire dati personali di studenti in qualunque campo. Resta da chiudere, con il DPO dell'Istituto, la questione documentale (§5.2).

**Perché non c'è contitolarità (art. 26 GDPR).** La contitolarità richiede che due soggetti determinino congiuntamente finalità e mezzi. Nessun elemento del trattamento è determinato dall'Istituto:

| Decisione | Chi la prende | Ruolo dell'Istituto |
|---|---|---|
| Finalità del trattamento (erogare la piattaforma, proteggerla) | l'autore | nessuno |
| Mezzi (software, infrastruttura, misure di sicurezza) | l'autore | nessuno |
| Iscrizione del docente | il docente, a titolo personale | non la dispone, non la conosce |
| Istituto, indirizzo e classe dichiarati | il docente, da elenchi MIUR | non li fornisce né li verifica |
| Accesso ai dati dei docenti | l'autore, nei limiti dei ToS | nessun accesso, nessun ruolo amministrativo |
| Flussi di dati verso l'Istituto | nessuno | non riceve nulla |
| Uso in classe con gli studenti | il docente, nella libertà di insegnamento | presa d'atto della Dirigente (richiesta nella Nota) |

**Scenario 3 — Adozione formale da parte di un Istituto, con dati di studenti.** Possibile in due sole forme, entrambe fuori dall'infrastruttura dell'autore:
  - l'**Istituto conduce l'istanza**, su infrastruttura qualificata del catalogo ACN o su infrastruttura propria che rispetti i livelli minimi del Regolamento (artt. 7 e 12); l'Istituto è Titolare, il software è riutilizzato ai sensi degli artt. 68-69 CAD (licenza EUPL-1.2), e l'autore non ha alcun ruolo nel trattamento, salvo un eventuale supporto tecnico su designazione dell'Istituto;
  - il software è **adottato da un fornitore già qualificato ACN**, che ne è il Responsabile del trattamento ex art. 28 verso l'Istituto.

  In tale istanza le modalità di registrazione studente presenti nel software (*Completa*, *Ridotta*, *Anonima*) tornano disponibili al Titolare. La bozza di DPA trasmessa il 1° settembre, che presupponeva l'autore come Responsabile su infrastruttura propria, è **ritirata**.

## 5.1 Perché la piattaforma non tratta dati dell'Istituto (Regolamento cloud ACN)

Il Regolamento cloud ACN (Decreto direttoriale n. 21007/24 del 27 giugno 2024, applicabile dal 1° agosto 2024, attuativo dell'art. 33-septies del D.L. 179/2012) consente alle pubbliche amministrazioni — le istituzioni scolastiche comprese (art. 1(2) D.Lgs. 165/2001) — di avvalersi soltanto di infrastrutture digitali e servizi cloud **qualificati**, e pone la qualificazione a carico del fornitore (art. 17). Un docente che conduca personalmente un server non può qualificarsi come fornitore. Ne segue che dati di cui l'Istituto è Titolare — quelli degli studenti — non possono risiedere su questa infrastruttura, a qualunque titolo l'autore li trattasse. È la ragione della riconfigurazione del 3 settembre 2026: il presupposto è stato rimosso prima che si concretizzasse — nessuno studente reale si era ancora registrato — anziché cercare una copertura formale.

## 5.2 Gestione documentale

I materiali che i docenti producono sulla piattaforma — verifiche, esercizi, mappe, laboratori — sono **materiale preparatorio**: il testo che il docente prepara, non la prova somministrata e corretta, che resta documento della scuola nei suoi archivi. La loro redazione rientra nella libertà di insegnamento (art. 33 Cost.; art. 1 D.Lgs. 297/1994). La questione aperta non è di titolarità ma di **gestione documentale** (DPR 445/2000, artt. 50 ss.; CAD, artt. 40-44; Linee guida AgID sui documenti informatici): la piattaforma include modelli generici di documenti scolastici — piano annuale, relazione finale, scheda progetto — che il docente compila come bozza, modifica e personalizza. I modelli non incorporano alcun Istituto: l'intestazione riporta l'Istituto dichiarato dal docente nel profilo, e il logo compare solo se l'istanza ne ha uno per quell'Istituto. Il risultato è un PDF che il docente deposita nei sistemi della scuola. La proposta sottoposta al DPO è una clausola, già inserita nei Termini di Servizio 1.3 (§2.5) in attesa del suo parere — *«La piattaforma è strumento di redazione e preparazione. Non costituisce luogo di conservazione degli atti formali dell'Istituto.»* — eventualmente accompagnata da un'indicazione della Dirigente al personale; in alternativa o in aggiunta, la **compilazione senza salvataggio**: un'impostazione per Istituto, attivabile dall'amministratore su indicazione del DPO di quell'Istituto (`institutes.compilation_storage`, pannello `/admin/institutes`), per cui la compilazione dei modelli istituzionali non viene salvata sul server — la bozza resta nel browser del docente, come un file sul suo computer; il PDF si esporta e si deposita nei sistemi della scuola; sul server resta il solo modello (`CompilationStoragePolicy`). L'export passa dal server: il bundle e il file prodotti restano in una cartella temporanea al massimo un'ora, poi un timer li cancella (`pantedu-tmp-cleanup.timer`). Esiste anche un interruttore d'istanza che nasconde del tutto i modelli istituzionali (`RISDOC_INSTITUTIONAL_TEMPLATES=false`): non è proposto al DPO, perché la compilazione senza salvataggio ottiene lo stesso risultato per i dati lasciando il modello utilizzabile. I modelli personali — programma svolto, obiettivi disciplinari, risorse — non sono toccati da nessuna delle due impostazioni.

## 5.3 Chiave di cifratura e accesso amministrativo

La chiave master risiede sul server perché la piattaforma funzioni. L'operatore, collega degli interessati, può decifrare i contenuti nei soli casi tassativi dei ToS §3(c), con annotazione su un registro append-only a livello di database, alterabile solo con un accesso amministrativo al server, a sua volta tracciato. L'unica misura che sottrarrebbe la chiave a chi amministra il server è una cifratura lato client con chiave nelle sole mani del docente, incompatibile con il recupero dell'accesso e con ogni elaborazione dei contenuti sul server (compilazione delle verifiche, pubblicazione per classe). Il frazionamento Shamir protegge la copia di sicurezza della chiave, non elimina l'accesso operativo. Il conflitto che deriva dall'essere l'operatore un collega degli interessati si elimina solo se il server non è condotto da lui. Due misure, attive dal 4 settembre 2026, riducono l'autocontrollo: ogni accesso amministrativo ai contenuti di un docente gli viene notificato via email nel momento in cui viene registrato, e i registri hanno un'impronta giornaliera conservata fuori dal server, che rende rilevabile ogni alterazione. Su richiesta, il frazionamento Shamir con un custode indicato dall'Istituto.

- **Licenza software**: EUPL-1.2. Il codice sorgente è **pubblicato integralmente** su <https://github.com/vittop89/pantedu> e ispezionabile senza richiesta né registrazione. Il repository include i metadati `publiccode.yml` previsti da Developers Italia e la **candidatura al catalogo `developers.italia.it` è stata depositata il 23 giugno 2026** ([italia/catalogo-software#12](https://github.com/italia/catalogo-software/issues/12)), attualmente in attesa di istruttoria. L'integrazione SPID/CIE non è ancora presente e non è requisito per l'inserimento in catalogo di software di terze parti.

---

# 6. Sintesi dell'audit di sicurezza (giugno 2026)

- **Metodologia**: audit assistito uomo + AI su metodologia standardizzata (13 fasi), eseguito su **clone isolato con dati fittizi** (mai su produzione con dati reali) + validazione passiva in produzione. Toolchain: Semgrep, Trivy, osv-scanner, gitleaks, trufflehog, Nuclei, OWASP ZAP, Schemathesis, testssl, più test attivo manuale (IDOR/BOLA, CSRF, verb-tampering, injection, file-read).
- **Esito**: **postura solida. Nessuna vulnerabilità Critical/High residua.**
- **Finding e stato** (11 totali):
  - **Corretti e in produzione (7)**: dipendenza HTTP vulnerabile; dipendenze del servizio di compilazione; sink XSS legacy; CSRF su form di contatto pubblico; tightening dei verbi HTTP sulle azioni di stato; sanitizzazione SVG uniforme; lettura file via include LaTeX sul servizio isolato di compilazione.
  - **Basso impatto / mitigati (4)**: codice legacy non raggiungibile in produzione; flag di rate-limit (compensato dal WAF in enforce); file di configurazione tracciato (senza segreti reali); dipendenza solo-sviluppo.
- **Controlli risultati NEGATIVI (assenza di vulnerabilità)**: SQL injection, IDOR/BOLA cross-utente, privilege escalation, open redirect, mass assignment, XSS verso studenti, RCE.
- **Report tecnico completo firmato** (con hash-chain di integrità) **disponibile su richiesta** del DPO. *Nota di trasparenza*: la metodologia AI-assistita rappresenta evidenza di *due diligence* (Art. 24/32) ma non sostituisce un pentest professionale certificato.

---

# 7. Fatto il 3 e il 4 settembre 2026, e roadmap

**Fatto.**
1. **Termini di Servizio 1.3 e AUP 1.2** (3 settembre; AUP 1.3 il 4, per le sole note di stato): tolte le formule del vecchio inquadramento — Preambolo (informativa «fornita dall'Istituto»), §1(b) («autorizzato dalla Scuola»), §2.1.2 (riferimento nominativo al Liceo), §4(a); nuovi §2.4 (divieto di inserire dati personali di studenti in qualunque campo) e §2.5 (clausola sugli atti formali, in attesa del parere del DPO); §3(a) (hash di IP/UA); §3(c) (Shamir: protegge la copia di sicurezza, non elimina l'accesso operativo).
2. **Hash di IP e User-Agent** in `content_action_log`, `privileged_access_log`, `teacher_recovery_audit` e, per lo User-Agent, `audit_activity_log`, con conversione delle righe esistenti (migration 100). Purga dei log privilegiati allineata ai cinque anni dichiarati: il job ne conservava dieci.
3. **Log di accesso del server web** disattivato.
4. **Credenziale di classe** (4 settembre 2026): la verifica pre-rilascio ha mostrato che il grant di sessione non era letto dagli endpoint di studio, e l'accesso «con la credenziale del docente» non mostrava nulla. Corretto: l'ospite con credenziale vede solo i contenuti pubblicati del docente della credenziale, per la sua classe; senza credenziale, solo i contenuti resi pubblici. Il blocco della condivisione del materiale da libri di testo è esteso anche ai grant verso singoli colleghi (ToS §2.1.1). Nello stesso giro: le compilazioni dei modelli risdoc, unico contenuto del docente ancora in chiaro nel database, sono ora cifrate con la sua chiave; rimosso il Modulo di autorizzazione, residuo del modello con credenziali nominative per minorenni; senza account studente, i campi dei modelli riferiti a studenti o genitori vengono svuotati dal server prima del salvataggio.

5. **4 settembre 2026, seconda tornata**: secondo fattore obbligatorio per i ruoli amministrativi; avviso automatico al docente per ogni accesso amministrativo ai suoi contenuti; impronta giornaliera dei registri fuori dal server; cancellazioni art. 17 eseguite da un timer giornaliero (il job mancava); conservazioni riviste per l'art. 5(1)(e) — audit a cinque anni, consensi a dieci dall'evento, WAF a trenta giorni, backup a un anno con ri-cancellazione dopo ripristino; Resend, servizio di posta, fra i responsabili del trattamento; modelli risdoc resi generici — l'intestazione non incorpora più alcun Istituto: riporta quello dichiarato dal docente, e il logo è un file dell'istanza — con impostazione per Istituto sul salvataggio delle compilazioni (sul server o solo nel browser del docente) e interruttore per nasconderli del tutto.

**Roadmap.**
6. **Pentest manuale certificato** da terza parte: resta raccomandato, a richiesta e a spese di chi lo richiede. L'art. 32 chiede misure adeguate anche in rapporto ai costi di attuazione: per una piattaforma gratuita condotta da una persona fisica il costo di un pentest certificato non è proporzionato, e la misura adottata è l'audit su clone isolato con toolchain standard (§6), il codice pubblico e ispezionabile da chiunque, e l'apertura a un pentest finanziato dall'Istituto o da chi lo richieda.
7. **SPID/CIE** come metodo di accesso: rilevante solo per un'istanza dello Scenario 3 — SDK `italia/spid-cie-php` / eID-Gateway MIM.
8. **Object lock** sul bucket remoto dei backup, così che l'impronta giornaliera dei registri non sia riscrivibile nemmeno da chi amministra il server.

---

# 8. Disponibilità e contatti

L'autore si rende disponibile a: chiarimenti tecnici, approfondimenti su singoli controlli, fornitura del report di audit firmato e valutazione delle misure aggiuntive richieste dal DPO.

**Contatto**: {{OPERATORE_EMAIL}} — PEC: superadmin@pec.it — DPO request form: https://pantedu.eu/dpo-contact

## Allegati (schema unico A–F)
- **A — Questo Pacchetto di accountability** — `docs/dpo/pacchetto-scuola/Pacchetto-DPO-pantedu.pdf` (misure Art. 32, AgID, minimizzazione+DPIA sintesi, titolarità). La fonte unica dell'elenco dei trattamenti e dei responsabili resta l'*Allegato F*: in caso di divergenza prevale il Registro.
- **B — Bozza DPA Art. 28** — **ritirata il 3 settembre 2026** (vedi §5).
- **C — Report di audit di sicurezza firmato** (hash-chain di integrità, giugno 2026) — consegna **su richiesta**, preferibilmente sotto NDA
- **D — DPIA completa** — `docs/privacy/dpia.pdf` (sorgente: `docs/privacy/dpia.md`), versione 1.5
- **E — Informativa privacy** — `docs/privacy/informativa.pdf` (sorgente: `docs/privacy/informativa.md`; fonte unica, servita anche su `https://pantedu.eu/privacy/informativa`), versione 2.3
- **F — Registro delle attività di trattamento (Art. 30)** — `docs/privacy/registro-trattamenti.pdf` (sorgente: `docs/privacy/registro-trattamenti.md`; fonte unica dell'elenco dei trattamenti e dei responsabili; classificazione interna, esibibile al DPO e al Garante su richiesta), versione 1.5
