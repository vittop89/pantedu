---
tags:
    - documentazione/gdpr
    - phase/25.C9
    - sicurezza
date: 2026-04-27
tipo: dpia
status: bozza-completa
versione: 1.1
classification: ⚠️ INTERNAL
aliases: ["dpia", "valutazione-impatto"]
---

# DPIA — Valutazione d'Impatto Privacy

> **Art. 35 GDPR**: la DPIA è obbligatoria quando il trattamento può presentare
> un rischio elevato per i diritti e le libertà delle persone fisiche, in
> particolare quando si trattano **dati di minori** (Art. 8).
>
> Pantedu tratta dati di studenti potenzialmente minorenni → DPIA
> raccomandata pre-go-live (non strettamente obbligatoria perché non si
> trattano dati Art. 9 — vedi nota sotto).
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

### Titolare del trattamento — modello di titolarità
- **Uso personale (default)**: {{OPERATORE_NOME}} è **Titolare** per la propria attività didattica.
- **Adozione da parte di un Istituto** (su richiesta dell'Istituto e con oneri a suo carico): l'**Istituto è Titolare**, {{OPERATORE_NOME}} è **Responsabile del trattamento (Art. 28)**, regolato da DPA dedicato (cfr. `docs/dpo/pacchetto-scuola/Bozza-DPA-Art28.md`).

### Ambito di accesso degli studenti (aggiornamento 2026-06)
L'accesso studente è circoscritto ai **soli studenti del docente proponente** (non agli studenti di altri docenti) e alla **sola visualizzazione delle fonti** (badge + riferimento bibliografico) di esercizi tratti da libri protetti da diritto d'autore — **mai** traccia/soluzioni; nessuna creazione/modifica di contenuti da parte dello studente. La quantità di dati raccolti è **configurabile dal Titolare/super-admin** (`/admin/system/deployment`) tra **tre modalità** (default: Completa):
- **Completa** *(default)*: `username` (= nome.cognome), nome, cognome, **email**, **data di nascita**, istituto, indirizzo, classe; per i **minori di 14 anni** consenso del genitore (email+nome, doppio opt-in, tabella `parent_consents`, Art. 8).
- **Ridotta**: `username`, nome, cognome, **email**, istituto, indirizzo, classe — **niente data di nascita né dati del genitore** (nessun age-gating; minimizzazione Art. 5.1.c).
- **Anonima**: nessun account studente; accesso via credenziale del docente → **zero** dati identificativi dello studente (solo grant tecnico legato all'`id` del docente).

**Scoping**: istituto + indirizzo + classe sono usati per limitare la visibilità — lo studente vede solo i contenuti pubblicati dei docenti del **proprio istituto/indirizzo/classe**.

### Finalità del trattamento
1. **Didattica**: docenti creano + condividono contenuti (esercizi, verifiche, mappe, risdoc) con studenti dell'istituto/classe assegnata.
2. **Versioni adattate**: i docenti possono segnare che un esercizio ha varianti per copie DSA/DIS — è un **metadata di contenuto**, non un identificativo dello studente. NON costituisce trattamento dato sanitario Art. 9 (vedi NOTA sopra).
3. **Audit operativo**: log accessi privilegiati per rilevamento abusi (Art. 32 sicurezza).
4. **Minimizzazione**: dati raccolti = solo quelli necessari per le finalità sopra.

### Categorie di dati e interessati

| Categoria | Tipologia | Base giuridica | Periodo conservazione |
|-----------|-----------|----------------|----------------------|
| Identificazione utente (username, nome, cognome, email) | Dato comune | Art. 6(1)(b) — esecuzione contratto registrazione | 730g inattività → anonimizzazione (vedi `app/Config/retention.php`) |
| Studente — scope (istituto, indirizzo, classe) | Dato comune | Art. 6(1)(b)/(e) — erogazione servizio + scoping visibilità | 730g inattività → anonimizzazione |
| Studente — data di nascita (**solo modalità Completa**) | Dato comune | Art. 8 (verifica età minori) | 730g inattività → anonimizzazione |
| Genitore di minore <14 (email, nome) (**solo modalità Completa**) | Dato comune | Art. 8 — consenso genitoriale (`parent_consents`) | fino a revoca/cessazione account studente |
| Flag DSA/DIS su esercizi (metadata contenuto) | Dato comune (proprietà intellettuale del docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account docente, cifrato at-rest (Phase 25.D) |
| Contatori numerici copie stampa (nPrintDSA, nPrintDIS) | Aggregato non identificativo | Art. 6(1)(b) | Vita ciclo verifica |
| IP address + User-Agent | Quasi-identificativo | Art. 6(1)(f) — interesse legittimo (sicurezza) | 365g (access_log) / 1825g (privileged_access_log) |
| Contenuti didattici (esercizi, body_html, body_pt) | Dato comune (proprietà intellettuale docente) | Art. 6(1)(b) — esecuzione contratto | Vita ciclo account, cifrato at-rest (Phase 25.D) |
| Log audit operativo | Dato comune | Art. 6(1)(f) | 1825g (5 anni) |

### Categorie di interessati
- **Docenti**: maggiorenni, professionisti.
- **Studenti**: minorenni (potenzialmente da 11 anni — primo anno scuola superiore).
- **Genitori** (per minori < 14 ex D.Lgs. 101/2018): titolari del consenso parentale Art. 8.
- **Super-admin tecnici**: maggiorenni, accesso operativo log + KMS recovery.

### Architettura tecnica del trattamento

```
Browser (HTTPS)
    │
    ▼
Apache + .htaccess (HSTS) → CSP via SecurityHeadersMiddleware (PHP, single-source)
    │
    ▼
PHP 8.3 (FPM) + Dotenv + Config
    │
    ▼ Session cookie (SameSite=Lax, secure)
    │
Auth Middleware → CSRF Middleware → Rate-Limit Middleware → Audit Middleware
    │
    ▼
Controllers + Services
    │
    ├── PDO MySQL 5.7+ (utf8mb4)
    │       │
    │       ├── teacher_content (cifrato Phase 25.D — body_html_ct + body_pt_ct)
    │       ├── teacher_keys (wrapped_kek per docente)
    │       ├── consents + consent_audit (Phase 25.C1)
    │       ├── deletion_requests (Phase 25.C4)
    │       ├── parent_consents (Phase 25.C7)
    │       └── classe_keys + published_content (Phase 25.D6)
    │
    ├── KMS_MASTER_KEY (env var, .env.local gitignored)
    │       └── Yubikey + paper BIP-39 backup off-line
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
- IP address: hash SHA-256 dei primi 2 octet (geo-coarse, no identification).
- User-Agent: hash SHA-256 (audit traceable, no fingerprinting).
- Flag DSA/DIS su esercizi (metadata): cifrato at-rest insieme al body del docente (Phase 25.D), accessibile solo al docente proprietario.
- Body docente (esercizi/verifiche/mappe): cifrato at-rest envelope encryption.
- Backup: cifrati **lato client prima dell'upload** (GPG/age) verso Backblaze B2 (UE); transport TLS. L'hosting applicativo è su Hetzner (Germania, UE).

Trattamenti **rifiutati per proporzionalità insufficiente**:
- Profilazione comportamentale studente (es. "tempo permanenza pagina") — fuori scope Phase 25.
- Geo-location precisa (latitudine/longitudine) — non necessaria.
- Dati biometrici facciali / riconoscimento foto — esplicitamente esclusi.

## 3. Valutazione dei rischi (Art. 35 §7 c)

### Matrice di rischio

| # | Trattamento / scenario | Probabilità | Gravità | Mitigazione | Rischio residuo |
|---|------------------------|-------------|---------|-------------|-----------------|
| R1 | Accesso docente a studenti altri docenti | Bassa | Alta | `Permission::canView` Phase 21 + AclPolicy + 4-teacher concurrent isolation E2E (Phase 25.B7) | **BASSO** ✅ |
| R2 | Super-admin curioso legge body docenti | Media | Alta | Phase 25.D envelope encryption + crypto_access_log + RequiresAuditReason middleware (Phase 25.B4) | **BASSO** ✅ |
| R3 | Breach DB dump | Media | Alta | Phase 25.D body cifrato + KMS_MASTER off-line backup (Yubikey + BIP-39) | **BASSO** ✅ |
| R4 | Brute-force login | Alta | Media | Rate-limit 10/min/IP (Phase 25.B5) + bcrypt cost 12 | **BASSO** ✅ |
| R5 | XSS / CSRF su form | Media | Alta | CSP (Track 7): `'unsafe-eval'` rimosso, handler inline bonificati, `strict` con nonce+strict-dynamic pronta (toggle `/admin/waf/config`); + SameSite=Lax + CSRF middleware (token via header) + CSRF token da fonte client unica `dom-utils.fetchCsrf`; superficie ridotta: zero jQuery + zero CSS-in-JS a runtime, escaping HTML centralizzato (2026-06-05) | **BASSO** ✅ |
| R6 | ~~Rivelazione BES/DSA senza consenso esplicito~~ | — | — | RIMOSSO 2026-04-27: BES/DSA non è trattato come dato Art. 9 in Pantedu (vedi NOTA in cima a §1). Solo metadata di contenuto del docente. | **N/A** ✅ |
| R7 | Trattamento dati minori senza base/consenso (Art. 8) | Alta | **Critica** | **Anonima/Ridotta** = nessuna data di nascita/dato del minore → rischio non applicabile. **Completa** = data di nascita + per <14 consenso genitoriale (email genitore + doppio opt-in, `parent_consents`); base giuridica concordata con l'Istituto Titolare. | **Anonima/Ridotta: BASSO ✅ — Completa: MEDIO ⚠️** (mitigato dal consenso genitoriale) |
| R8 | Mancato esercizio diritto oblio Art. 17 | Media | Alta | Phase 25.C4: self-service /me/request-deletion + crypto-shredding O(1) | **BASSO** ✅ |
| R9 | Mancata tracciabilità mutazioni admin | Bassa | Media | Phase 25.B4: RequiresAuditReason middleware + privileged_access_log immutable | **BASSO** ✅ |
| R10 | Cancellazione utente lascia tracce in backup | Media | Media | Crypto-shredding rende illeggibili anche backup (KEK shred = body unreadable) | **BASSO** ✅ |
| R11 | Phishing parent_email per fake consent | Media | Alta | Token random 64-hex single-use + TTL 30g + warning in mail | **MEDIO** ⚠️ |
| R12 | Perdita KMS_MASTER_KEY | Bassa | **Critica** | docs/security/kms-recovery.md: 3 backup (env + Yubikey + BIP-39) + drill semestrale | **MEDIO** ⚠️ |
| R13 | Cloud extra-UE | Bassa | Media | Hosting Hetzner (DE) + backup Backblaze B2 (NL), tutto in UE; SCC se sub-processor extra-UE (es. opt-in Google) | **BASSO** ✅ |
| R14 | Ingegneria sociale → admin reset password | Media | Alta | Multi-fattore admin (TODO Phase 25.E future) — attualmente solo password+CSRF | **MEDIO** ⚠️ |

### Rischi residui ALTI (BLOCKER PROD-MINORI)

**R7** è inaccettabile per il rollout production con minori. Mitigation **OBBLIGATORIA** prima di onboarding studenti:
- Phase 25.C7: validation età < 14 → richiede parent_email + double-opt-in.

**R6 RIMOSSO**: ricerca approfondita codebase 2026-04-27 ha confermato che BES/DSA in Pantedu è solo **metadata di contenuto** (checkbox su esercizi + contatori numerici per stampa). I dati sanitari veri (PEI/PDP, certificazioni) sono gestiti dalla scuola tramite registro elettronico esterno + cartaceo, non passano per Pantedu. Quindi **trattamento Art. 9 NON applicabile** al sistema.

## 4. Misure tecniche e organizzative (Art. 32)

### Tecniche (implementate)

- ✅ **Cifratura at-rest** (Phase 25.D): AES-256-GCM envelope encryption con HKDF-SHA256, per-teacher KEK + per-classe class_key.
- ✅ **Cifratura in transito**: HTTPS obbligatorio (HSTS 1y + includeSubDomains).
- ✅ **Hashing password robusto**: bcrypt cost 12.
- ✅ **CSRF protection**: token per sessione, middleware `csrf` su tutte le mutazioni; recupero token lato client da **fonte unica centralizzata** (`dom-utils.fetchCsrf`, 2026-06-05).
- ✅ **WAF fail-closed JSON-aware** (2026-06-05): per le richieste API/XHR challenge e block rispondono in JSON 403 mantenendo il blocco (non degradano il front-end); client auto-recupera la verifica via reload.
- ✅ **Superficie XSS ridotta** (2026-06-05): zero jQuery e zero CSS-in-JS a runtime, escaping HTML centralizzato (`esc`/`escAttr`).
- ✅ **Rate-limiting**: per-bucket (login 10/min IP, content 60/min teacher, deletion 5/min).
- ✅ **Audit log immutabile**: privileged_access_log + crypto_access_log + consent_audit (REVOKE UPDATE/DELETE post-deploy — TODO operational).
- ✅ **Pseudonimizzazione**: hash SHA-256 IP/UA in audit log.
- ✅ **Crypto-shredding O(1)**: Art. 17 GDPR efficiente (DELETE 1 row teacher_keys → all body unreadable).
- ✅ **Governance chiave master (opzionale, su richiesta)**: la `KMS_MASTER_KEY` può essere frazionata con **Shamir Secret Sharing** (es. 3-su-5 o 2-su-3) tra **custodi distinti** (es. responsabile tecnico + segreteria + dirigenza): nessun singolo custode ricostruisce la chiave da solo. Implementazione: `app/Services/Crypto/ShamirSecretSharing*`.
- ✅ **CSP**: default-src 'self', frame-ancestors 'none', `object-src 'none'`, `base-uri 'self'`; `'unsafe-eval'` rimosso; modalità `strict` (nonce per-request + `strict-dynamic`) pronta + `report-only` per rollout, toggle runtime da `/admin/waf/config` (Track 7, 2026-06-03).
- ✅ **Isolation testata**: 4-teacher concurrent E2E (Phase 25.B7) + dual-role super_admin path verificato.

### Organizzative (in implementazione)

- ✅ **Privacy by design** documentato in ADR-006/007.
- ✅ **DPO contact**: form pubblico `/dpo-contact` (instradato a `{{OPERATORE_EMAIL}}`).
- ✅ **Retention policy**: `app/Config/retention.php` con anonimizzazione automatica.
- ✅ **Data breach runbook**: `docs/privacy/data_breach_runbook.md` (Phase 25.C12 drill semestrale PENDING).
- ✅ **Registro trattamenti Art. 30**: completato (Phase 25.C8). Non più obbligatorio ex §3 (no Art. 9), ma redatto come buona pratica per dati di minori e accountability Art. 5 §2.
- 🚨 **DPIA firmata Titolare**: questo documento, in bozza.
- ⬜ **DPA con sub-processors**: Hetzner (hosting UE), Cloudflare (edge), Backblaze B2 (backup) — verifica DPA Art. 28 §4 per ciascuno.
- ⬜ **Annual privacy review**: scheduled gennaio di ogni anno.

### KMS_MASTER_KEY backup (Art. 32 §1 c)

Vedi `docs/security/kms-recovery.md`:
- Server env (`.env.local` gitignored, chmod 600)
- Yubikey hardware GPG-encrypted (cassaforte ufficio)
- Paper BIP-39 32-word seed phrase (cassaforte separata)
- Drill semestrale documentato
- **Opzione (su richiesta dell'Istituto)**: frazionamento Shamir *k*-su-*n* (es. 3-su-5) con quote affidate a custodi distinti (responsabile tecnico + segreteria + dirigenza) → nessun single point of trust sulla chiave master.

## 5. Consultazione preventiva Garante (Art. 36)

Necessaria SE rischio residuo ALTO non mitigabile:
- ⚠️ Da valutare DOPO chiusura R6 + R7 + R11 + R12 + R14 (al massimo: tutti MEDIO, nessuno ALTO).
- Decisione preliminare: **NON necessaria** se Phase 25.C2+C7+C13 sono completate prima del go-live con minori.

## 6. Conclusioni e azioni richieste

### Decisione DPIA

**Stato**: BOZZA COMPLETA — consultazione Garante NON necessaria condizionata a:

1. ✅ Phase 25.B isolation hardening (DONE)
2. ✅ Phase 25.D encryption at-rest (DONE)
3. ✅ Phase 25.C self-service oblio (DONE)
4. ✅ Phase 25.C8 registro trattamenti (DONE — obbligatorio Art. 30 sopra threshold scolastico)
5. ✅ Phase 25.C10 informativa v2 (DONE — disclosure IP/UA + diritti self-service)
6. **Minori (Art. 8)**: risolto nelle modalità **Anonima** e **Ridotta** (nessuna data di nascita / dato del minore). Nella modalità **Completa** è attivo il flusso consenso genitoriale per i <14 (Phase 25.C7: parent_email + double-opt-in), con base concordata con l'Istituto Titolare.
7. ⚠️ **Phase 25.C13 raccomandata**: DPO contact form + audit register cassaforte.
8. ⚠️ **Phase 25.E7 raccomandata**: pentest esterno pre-go-live.

Senza completamento del punto 6 (R7 Art. 8 minori), il go-live con utenti < 14 anni richiede o consultazione Garante o esclusione minori dal target di servizio.

### Firma Titolare

| Campo | Valore |
|-------|--------|
| Titolare | {{OPERATORE_NOME}} |
| Email | vittop89@users.noreply.github.com |
| Data versione bozza | 2026-04-27 (agg. 2026-06-17) |
| Versione DPIA | 1.1 |
| Stato | BOZZA — go-live minori OK in Anonima/Ridotta; in Completa con consenso genitoriale (base concordata con l'Istituto) |
| Prossima revisione | Annuale (gennaio 2027) o ad evento |

**Firma**: __________________________ Data: __________

## Riferimenti

- ADR-006 (envelope encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance, in arrivo Phase 25.C15)
- KMS recovery runbook: `docs/security/kms-recovery.md`
- Retention policy: `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- Compliance checklist: `docs/privacy/compliance_checklist.md`
- Migration 015 (consents): `database/migrations/015_consents_gdpr.sql`
- Migration 012 (encryption): `database/migrations/012_teacher_crypto.sql`
