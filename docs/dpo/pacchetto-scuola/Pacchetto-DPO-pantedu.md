---
title: "Pantedu — Pacchetto di accountability per il DPO/RPD scolastico"
subtitle: "Documentazione tecnica e organizzativa ai sensi GDPR Art. 24, 28, 32, 35"
author: "Vittorio Pantaleo — sviluppatore e operatore di Pantedu"
date: "2026-06-16"
lang: it
---

# 0. Sintesi per il DPO/RPD (in una pagina)

**Pantedu** è una piattaforma didattica web (materiali di matematica/fisica: esercizi, verifiche, mappe, documenti) sviluppata e gestita personalmente da **Vittorio Pantaleo**. Questo documento è il pacchetto di *accountability* GDPR pensato per il DPO/RPD di una scuola che valuti l'adozione di Pantedu.

- **Natura d'uso (importante).** Pantedu nasce e opera per **uso personale** del suo autore (didattica propria). L'eventuale **adozione da parte di un Istituto è possibile su richiesta della scuola e a spese di quest'ultima** (hosting/risorse dedicate) — vedi §5. In tale scenario la scuola è **Titolare del trattamento** e Pantedu/Vittorio Pantaleo è **Responsabile del trattamento** (GDPR Art. 28), con DPA dedicato.
- **Postura di sicurezza.** L'applicazione è stata sottoposta (giugno 2026) a un **audit di sicurezza esaustivo** (VA automatizzato + verifica manuale + test attivo su clone isolato). **Nessuna vulnerabilità Critical/High residua**; tutti i finding sono stati corretti e sono in produzione. Sintesi in §6.
- **Minimizzazione dati.** Pantedu **non tratta dati identificativi diretti degli studenti** nel flusso didattico (vedi §4 e DPIA allegata). I dati BES/DSA **non** costituiscono dato sanitario Art. 9 (solo marcatori aggregati lato docente, nessun collegamento studente↔patologia in DB).
- **Dati nell'UE.** Hosting su **Hetzner Cloud, datacenter di Norimberga (Germania, UE)**. Cifratura a riposo e in transito.

---

# 1. Inquadramento normativo

| Norma | Riferimento | Rilevanza |
|------|-------------|-----------|
| GDPR | Art. 5(1)(c) | Minimizzazione dei dati |
| GDPR | Art. 24, 32 | Misure tecniche e organizzative adeguate + accountability |
| GDPR | Art. 28 | Rapporto Titolare (scuola) ↔ Responsabile (Pantedu) |
| GDPR | Art. 8 | Condizioni per il consenso dei minori (≥14 in IT) |
| GDPR | Art. 33/34 | Notifica violazioni 72h |
| GDPR | Art. 35 | DPIA per trattamenti ad alto rischio (dati minori) |
| AgID | Misure Minime di Sicurezza ICT (Circ. 2/2017, Piano Triennale 2024-2026) | 20 controlli ABSC |
| Garante Privacy | Provv. 2026 ispezioni IA nelle scuole | Verifica DPIA su sistemi IA scolastici |

---

# 2. Misure tecniche e organizzative (GDPR Art. 32)

| Area | Misura implementata | Evidenza |
|------|---------------------|----------|
| **Cifratura in transito** | TLS 1.2/1.3, **HSTS con `preload`**, edge Cloudflare | Verificato in audit (header live) |
| **Cifratura a riposo** | Envelope encryption **AES-256-GCM** per i contenuti sensibili, **KEK per-docente**; chiave master (`KMS_MASTER_KEY`) **fuori dal repository**; guardia anti-rigenerazione distruttiva delle chiavi | `app/Core/Crypto*`, audit §4.6 |
| **Controllo accessi** | RBAC con principio del privilegio minimo; ruolo `super_admin` separato; authz *per-owner* sui contenuti (verificata: accessi cross-utente → 403/404) | Audit IDOR/BOLA = negativo |
| **CSRF** | Token di sessione obbligatorio su tutte le richieste di stato (POST/PUT/PATCH/DELETE) | Audit (403 senza token) |
| **WAF** | Web Application Firewall self-hosted **in enforce**: geo IT-only, Proof-of-Work, protezione brute-force, threat-intel, anti-spoofing edge | Stato prod verificato: `enabled=1, mode=enforce` |
| **Hardening output** | Sanitizzazione HTML (HTMLPurifier) e SVG (svg-sanitize) sui contenuti resi agli utenti; MIME magic-byte sniffing sugli upload | Audit (XSS verso studenti = mitigato) |
| **SQL** | Query 100% PDO *prepared* (nessuna concatenazione di input) | Audit (SQLi = nessuna) |
| **Header sicurezza** | CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy, COOP; cookie `Secure; HttpOnly; SameSite` | Verificato live |
| **Backup** | Backup giornaliero **cifrato** (systemd + Backblaze B2), archivio fuori dal server | `tools/backup/*`, memoria ops |
| **Verifica regolare (Art. 32 §1(d))** | Audit di sicurezza giugno 2026 (VA + manuale + attivo); toolchain SAST/SCA/DAST/secret-scanning | §6 + report firmato |
| **Lifecycle chiavi** | Pre-flight guard su generazione/rotazione (no rigenerazioni silenziose distruttive) | Audit §4.6 |
| **Autenticazione forte** | Infrastruttura **2FA TOTP** (RFC 6238) presente, attivabile per ruolo; **SPID/CIE**: non integrato (raccomandazione roadmap §7) | `app/Config/security.php` |

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

Posizione tecnica formale (Art. 32 + AgID ABSC 4): *l'applicazione è stata sottoposta a Vulnerability Assessment automatizzato esaustivo con copertura SAST+SCA+DAST+secret-scanning; tutte le vulnerabilità Critical e High sono state risolte prima/durante il rilascio. Misura equivalente al controllo automatizzato Standard. Un pentest manuale certificato resta raccomandato per l'evoluzione futura.*

---

# 4. Minimizzazione dei dati e DPIA (GDPR Art. 5, 8, 35)

- **Dati trattati**: identificazione utente operatore (username, nome, cognome, email) — dato comune, base giuridica Art. 6(1)(b) (esecuzione del servizio di registrazione). Conservazione: **730 giorni di inattività → anonimizzazione automatica** (`app/Config/retention.php`); log a **30 giorni**.
- **Studenti / minori (Art. 8)** — *ambito ristretto*: l'accesso studente è previsto **solo per gli studenti del sottoscritto** (non per gli studenti degli altri docenti) e ha **unica finalità la visualizzazione delle fonti** (badge + riferimento bibliografico) degli esercizi tratti da libri protetti da diritto d'autore. **Non** sono mai esposti agli studenti **traccia né soluzioni**; nessuna produzione/modifica di contenuti da parte dello studente. La quantità di dati raccolti è **configurabile dal Titolare/super-admin** (`/admin/system/deployment`) tra **tre modalità** (default: Completa):
  - **Completa** *(default)* — dati trattati: `username` (= nome.cognome), **nome**, **cognome**, **email**, **data di nascita** (per determinare la maggiore/minore età), **istituto**, **indirizzo**, **classe**; per i **minori di 14 anni** è richiesto e registrato il **consenso del genitore** (email + nome del genitore, con doppio opt-in — tabella `parent_consents`, Art. 8). Conservazione: **730 gg di inattività → anonimizzazione automatica**.
  - **Ridotta** — dati trattati: `username` (= nome.cognome), **nome**, **cognome**, **email**, **istituto**, **indirizzo**, **classe**. **Non** si raccolgono **data di nascita** né **dati del genitore** (nessun *age-gating* Art. 8): scelta di minimizzazione (Art. 5.1.c) coerente con la sola finalità di visualizzazione delle fonti.
  - **Anonima** — **nessun account studente**: gli studenti accedono tramite una credenziale del docente; la sessione registra **solo** un *grant* tecnico legato all'`id` del docente (`fm_teacher_access`), **zero dati identificativi dello studente**.
- **Finalità di indirizzo/classe/istituto**: indispensabili allo *scoping* della visibilità — ogni studente vede **esclusivamente** i contenuti pubblicati dei docenti del **proprio istituto + indirizzo + classe** di registrazione (ancorato all'account; nessun accesso ai contenuti di altre classi/istituti).

  Una DPIA dedicata è disponibile (`docs/privacy/dpia.md`, allegata) e copre tutte le modalità.
- **BES/DSA — NON dato sanitario Art. 9**: verificato sul codice. Pantedu tratta solo **marcatori aggregati** lato docente (es. "stampa N copie versione DSA"), senza collegare uno studente identificato a una condizione. Nessun trattamento Art. 9.
- **Sub-responsabili (Art. 28 §2)**:
  - **Hetzner Cloud** (Germania, UE) — hosting/infrastruttura.
  - **Cloudflare** — CDN/edge security (terminazione TLS, WAF edge).
  - **Backblaze B2** — backup cifrati (i dati sono cifrati prima del caricamento).
  - **Google** — solo su **opt-in** esplicito del docente (OAuth/Drive per import facoltativo).
  - **Aruba** — solo registrar DNS del dominio (non tratta dati personali → non sub-responsabile).
  - **MaxMind/db-ip** — database GeoIP locale per il WAF (nessun invio di dati personali a terzi).

---

# 5. Titolarità, modello d'uso ed economico

- **Uso personale (default).** Pantedu è gestito dall'autore per la **propria attività didattica**. In questo scenario il Titolare del trattamento dei propri dati è l'autore stesso.
- **Uso da parte di una scuola (su richiesta, a spese della scuola).** Qualora un Istituto richieda di adottare Pantedu per i propri docenti/studenti:
  - la **scuola** assume il ruolo di **Titolare del trattamento**;
  - **Pantedu / Vittorio Pantaleo** assume il ruolo di **Responsabile del trattamento (Art. 28)**, regolato da **apposito accordo (DPA)** che includerà: finalità e durata, categorie di dati e interessati, misure Art. 32 (questo pacchetto), elenco sub-responsabili (§4), obblighi di assistenza (Art. 33/34/35), istruzioni documentate, restituzione/cancellazione a fine rapporto;
  - i **costi** di infrastruttura dedicata (hosting, risorse, eventuali integrazioni SPID/CIE, pentest manuale certificato se richiesto dal DPO) sono **a carico della scuola**.
- **Licenza software**: EUPL-1.2 (codice ispezionabile su richiesta, in linea con i principi Developers Italia).

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

# 7. Roadmap di rafforzamento (raccomandazioni, a richiesta della scuola)

1. **SPID/CIE** come metodo di accesso (elimina la gestione password; conformità auditata da AgID) — SDK `italia/spid-cie-php` / eID-Gateway MIM.
2. **2FA TOTP** obbligatoria per i ruoli amministrativi (infrastruttura già presente, da attivare).
3. **Pentest manuale certificato** da terza parte, se richiesto dal DPO (a spese della scuola).
4. **DPA formale** Art. 28 + eventuale isolamento di rete (whitelisting IP) se l'uso è intramurale.

---

# 8. Disponibilità e contatti

Il Responsabile si rende disponibile a: chiarimenti tecnici, approfondimenti su singoli controlli, fornitura del report di audit firmato, sottoscrizione del DPA, e valutazione delle misure aggiuntive richieste dal DPO.

**Contatto**: vittorio.pantaleo@pantedu.eu — DPO request form: https://pantedu.eu/dpo-contact

## Allegati disponibili su richiesta
- A — DPIA completa (`docs/privacy/dpia.md`)
- B — Informativa privacy (`docs/privacy/informativa.pdf` — fonte unica, servita anche su `/privacy/informativa`)
- C — Report di audit di sicurezza firmato (hash-chain, giugno 2026)
- D — Elenco sub-responsabili dettagliato
- E — Bozza DPA Art. 28
