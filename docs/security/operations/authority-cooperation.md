---
tags:
  - security/operations
  - gdpr/cooperation
date: 2026-05-21
status: bozza-operativa
---

# Procedura cooperazione con autorità — Recupero dati cifrati

> **Scope**: descrive come pantedu risponde a richieste lecite di accesso ai
> dati cifrati at-rest (KEK envelope) da parte di **autorità giudiziarie**,
> **Garante Privacy**, **forze di polizia**, o richieste del **data controller**
> per cause di forza maggiore (es. perdita accesso del docente, decesso).

## 1. Architettura attuale e implicazioni

I dati cifrati at-rest seguono [ADR-006 envelope encryption](../../../wiki/decisions/ADR-006-envelope-encryption.md):

```
KMS_MASTER_KEY (env, 32 byte hex, off-line)
    ↓ HKDF-SHA256 + teacher_id
TKEK_teacher (in-memory)
    ↓ AES-256-GCM(wrap)
teacher_keys.wrapped_kek (DB, per docente)
    ↓ AES-256-GCM
teacher_content.body_pt_ct (DB)
```

**Conseguenza pratica**: per decifrare contenuti di un docente servono
**ENTRAMBI**:

1. `KMS_MASTER_KEY` (custodita off-line dal data controller)
2. `teacher_keys.wrapped_kek` (riga DB MySQL)

Se uno dei due manca → crypto-shredding O(1) automatico (Art. 17 GDPR).

## 2. Custodia di `KMS_MASTER_KEY` — Stato attuale e roadmap

### 2.1 Stato attuale (single-operator)

- **Unico custode**: Vittorio Pantaleo (data controller).
- **Fingerprint (SHA-256 della chiave, NO valore in chiaro)**: registrata
  nel sistema via evento `kms_backup_created` in `/admin/crypto-status`
  per verifica di integrità futura.

#### Copie attive (Phase 25.R follow-up — 5 copie indipendenti, tutte UE)

| # | Tipo | Dove | Sovereignty | Accesso | Air-gapped |
|---|------|------|-------------|---------|------------|
| 1 | **Produzione** | `KMS_MASTER_KEY` env var su VPS Hetzner `beta.pantedu.eu` — file `/var/www/pantedu/.env.local` perms `0640 pantedu:www-data` + `chattr +i` immutable | 🇩🇪 Germania (Hetzner Nuremberg) | SSH chiave personale + Cloud Firewall IP whitelist | ❌ online |
| 2 | **Backup laptop dev** | `.env.local` su laptop personale Vittorio (`~/progetti_vscode/pantedu/.env.local`, gitignored, mai committato) | 🇮🇹 Italia (locale Vittorio) | login utente Windows + BitLocker FDE | ❌ online |
| 3 | **Cloud cifrato (zero-knowledge)** | Cryptomator vault su OneDrive personale Microsoft: il file dentro il vault contiene `KMS_MASTER_KEY=<hex>`. OneDrive vede solo blob cifrato (Cryptomator AES-256 client-side), zero-knowledge | 🇩🇪/🇮🇪 (OneDrive UE) + zero-knowledge sopra (Cryptomator OSS audited, Germania) | account Microsoft + 2FA + master password Cryptomator | ❌ sync online |
| 4 | **Password manager locale** | Entry "pantedu KMS_MASTER" in Password Safe (file `.psafe3`/`.kdbx` cifrato master password, salvato sul laptop dev) | 🇮🇹 Italia (locale Vittorio) | master password Password Safe (mai stessa di altri sistemi) | ❌ online (sul laptop) |
| 5 | **Cold backup HDD esterno** | HDD esterno USB cifrato (NTFS BitLocker o ext4 LUKS), aggiornato mensilmente con copia completa dei backup B2 + `.env.local` snapshot. Etichetta fisica + custodia cassetto/cassetta sicurezza casa | 🇮🇹 Italia (locale Vittorio, fisico) | sblocco LUKS/BitLocker manuale + chiave fisica | ✅ **air-gapped** quando staccato |

#### Procedura mensile cold backup HDD esterno

**Frequenza**: mensile (es. primo lunedì di ogni mese).

1. Connetti HDD esterno al laptop dev
2. Esegui `scripts/cold_backup.sh` (vedi sezione 2.4):
   - Scarica ultimo backup completo da Backblaze B2 (DB + storage objects + audit log)
   - Verifica SHA-256 del manifest
   - Copia su HDD esterno in `pantedu-backups/YYYY-MM/`
   - Copia anche `.env.local` corrente del VPS (via SSH rsync)
   - Verifica fingerprint SHA-256 di KMS_MASTER_KEY
3. Cancella backup oltre 24 mesi dall'HDD (rotation manuale o auto)
4. Scollega HDD + riponi in cassetto sicuro/cassetta banca
5. Registra evento `kms_backup_verified` su `/admin/crypto-status` con esito + timestamp

#### Procedura verifica annuale integrità (tutte le 5 copie)

**Ogni 12 mesi** (next: 2027-05-21) Vittorio esegue:

1. Apri ognuna delle 5 copie + estrai il valore di KMS_MASTER_KEY
2. Calcola SHA-256 di ogni copia
3. Confronta con la **fingerprint di riferimento** (registrata in `crypto_custody_events`)
4. Tutte e 5 devono matchare → registra evento `kms_backup_verified` con esito
5. Se anche solo 1 copia differisce → indagine (rotation accidentale? compromise? corruzione?)
6. Annota tempo verifica + esito

```bash
# Da locale (laptop dev) — calcola SHA-256 della chiave
grep '^KMS_MASTER_KEY=' .env.local | cut -d= -f2 | tr -d '\r\n' | sha256sum

# Da VPS (via SSH)
ssh pantedu-vps "grep '^KMS_MASTER_KEY=' /var/www/pantedu/.env.local | cut -d= -f2 | tr -d '\r\n' | sha256sum"

# Da Cryptomator+OneDrive
# 1. Unlock Cryptomator vault → 2. cat kms_master_pantedu.txt → 3. sha256sum

# Da HDD esterno (5ª copia)
# 1. Connetti HDD + sblocca LUKS/BitLocker
# 2. cat /mnt/external/pantedu-backups/YYYY-MM/env-local-snapshot.txt
# 3. grep '^KMS_MASTER_KEY=' | cut -d= -f2 | sha256sum
```

#### Single-point-of-failure analysis (5 copie)

| Scenario disastro | Resilience attuale |
|-------------------|---------------------|
| VPS Hetzner cancellato/account suspended | ✅ 4 copie alternative |
| Laptop dev rubato/distrutto | ✅ VPS + OneDrive + HDD esterno (Password Safe perduto col laptop) |
| OneDrive account compromesso/cancellato | ✅ VPS + laptop + HDD esterno |
| Catastrofe casa Vittorio (incendio, alluvione) | ✅ VPS + OneDrive (HDD esterno potrebbe essere distrutto se non in cassetta sicurezza banca) |
| Ransomware su laptop + sync verso OneDrive (worst case) | ✅ HDD esterno **air-gapped** sopravvive + VPS |
| Compromissione coordinata laptop + OneDrive + VPS | ✅ HDD esterno air-gapped è ultima linea di difesa |
| Compromissione TUTTE e 5 simultaneamente | ❌ → **crypto-shred totale** (scenario quasi impossibile se HDD scollegato) |
| Decesso/incapacità Vittorio | ⚠️ eredi possono accedere a HDD esterno se lasciate istruzioni; eventualmente Step 2.3 con notaio formalizza |

**Miglioramenti rispetto a 4 copie**:
- L'HDD esterno air-gapped chiude il pattern "worst case: ransomware/supply-chain compromette tutto online"
- Spostando l'HDD in cassetta sicurezza banca (€30-100/anno) chiudi anche "catastrofe casa"
- Per business continuity multi-tenant: aggiungere notaio (§2.3) come 6ª copia con chain of custody legale

### 2.2 Perché la configurazione attuale NON basta per multi-tenant

Le 4 copie digitali di §2.1 sono già **resilienti contro disastri tecnici**:
- Disco VPS rotto → 3 copie alternative
- Laptop rubato → VPS + OneDrive
- Account OneDrive compromesso → VPS + laptop

Però **NON coprono** 3 scenari che diventano critici quando l'app gestisce dati di terzi (multi-tenant):

#### A. Compromissione coordinata "all-digital"

Tutte e 4 le copie attuali sono **digitali e online-reachable** (anche il Password Safe sta sul laptop che è connesso). Uno scenario worst-case (ransomware sofisticato + supply-chain attack + phishing OneDrive credentials) può in teoria compromettere tutte e 4 in cascata. Una busta sigillata fisica in cassetta di sicurezza è **air-gapped** — impenetrabile da remoto.

#### B. Chain of custody legale

Nelle 4 copie attuali, **non c'è data certa** di custodia. Se in tribunale serve dimostrare "questa chiave esisteva al 21/05/2026 con questo contenuto", devi affidarti a fingerprint hash e a tua testimonianza. Con deposito notarile hai:
- Atto pubblico con data certa
- Sigillo notarile sulla busta (rotto solo davanti a notaio)
- Procedure recovery formalizzate (a chi, in quali condizioni)

Per dati di terzi (istituti scolastici, GDPR-compliance) questo è valore aggiunto importante in caso di breach investigation o request GDPR Art. 15-22 contestate.

#### C. Eredità / business continuity post-Vittorio

Se a Vittorio capita qualcosa (decesso, malattia incapacitante, incidente prolungato):
- VPS continua a girare ma nessuno può ruotare la chiave
- Le 3 copie personali (laptop, OneDrive, Password Safe) richiedono accesso ai device + password che gli eredi potrebbero non avere
- Senza chiave → studenti/docenti delle scuole-cliente perdono accesso ai dati cifrati

Con deposito notarile + procedura successoria documentata: gli eredi vanno dal notaio, presentano certificato di morte + procura, ottengono la busta, ripristinano il servizio. Tempo di recovery: giorni invece di "mai".

### 2.4 Script cold backup HDD esterno (`tools/admin/cold_backup.sh`)

Procedura semi-automatica per la copia mensile su HDD esterno.

**Prerequisiti**:
- HDD esterno cifrato (BitLocker To Go o LUKS) montato su path noto (es. `E:\` Windows, `/mnt/external/` Linux)
- rclone configurato col remote `b2-pantedu` (già presente sul laptop dev)
- SSH config con alias `pantedu-vps`

**Flow**:
1. Detect mount point HDD esterno (parameter `--mount=...` o auto-detect)
2. Crea cartella `pantedu-backups/YYYY-MM/`
3. `rclone copy b2-pantedu:pantedu-backup-vps/ <mount>/pantedu-backups/YYYY-MM/`
4. SSH al VPS: `cat /var/www/pantedu/.env.local` → salva localmente in `env-local-snapshot.txt` (poi verifica fingerprint)
5. Genera `manifest.txt` con SHA-256 di tutti i file copiati + fingerprint KMS_MASTER
6. Cancella cartelle oltre 24 mesi dall'HDD (rotation)
7. Stampa report + reminder "scollega HDD + riponi"

Costo: **€0/mese** ricorrente (HDD esterno una tantum €40-80).
Tempo manuale: 10-15 min/mese (connetti + run script + scollega).

### 2.5 Roadmap multi-custode (richiesto pre-onboarding terzi)

| Step | Cosa | Stato | Costo stimato (Italia, 2026) |
|------|------|-------|------------------------------|
| 1 | Backup KMS in busta sigillata presso notaio | TODO | ~€200-500 una tantum atto + €0-150/anno custodia |
| 2 | Shamir Secret Sharing 3-su-5: split tra Vittorio + 2 fiduciari + notaio + cassetta sicurezza banca | TODO | ~€50-80/anno cassetta sicurezza + €0 fiduciari + setup 4-8h |
| 3 | Procedura annuale verifica integrità custodia (`kms_backup_verified` event) | TODO | ~€0-100/anno se richiede visita notaio |
| 4 | Documento di policy notarizzato che descrive a chi e in quali condizioni la chiave può essere ricostruita | TODO | ~€150-300 una tantum |

**Note sulla scelta del notaio**:
- Atto di deposito ("verbale di deposito di documento") + busta sigillata è la formula classica. Onorario notarile circa €200-500 a seconda della complessità e dello studio (Roma/Milano più cari; provincia più economico).
- Custodia annuale: alcuni notai la includono nell'onorario iniziale, altri chiedono €50-150/anno. Da concordare ESPLICITAMENTE prima.
- Recupero: in caso di disastro tu (o un erede con procura) vai dal notaio, identifica te stesso, apre la busta in presenza tua. Costo per apertura: €50-100 una tantum.

**Alternative più economiche**:
- **Cassetta di sicurezza bancaria** (UniCredit, Intesa, ecc.): €30-100/anno. Pro: meno burocrazia; contro: nessuna garanzia notarile sull'integrità della busta, accesso solo per intestatario (no eredi senza procura preventiva).
- **Vault Backblaze B2 con Object Lock + key separata su pendrive USB in cassetta sicurezza**: ~€5-10/anno totali. Tecnico ma economico. Adatto se preferisci automazione a formalismo legale.
- **Shamir Secret Sharing distribuito** (3-su-5 con `vault operator init`): costo software €0. Ogni custode (notaio, banca, fiduciario) ha propri costi. Tecnicamente più robusto perché un compromesso singolo non basta a ricostruire la chiave.

**Pre-onboarding terzi consigliato**:
- Per pantedu single-operator <10 docenti: opzione cassetta sicurezza bancaria + Shamir 2-su-3 è sufficiente (~€50/anno).
- Per pantedu multi-tenant (>25 docenti o dati Art. 9): notaio + Shamir 3-su-5 raccomandato (~€300/anno comprensivo di setup + custodia).

**Tempo procedure**:
- Setup notaio: 1 visita (1-2h) + preparazione documento (2-4h tue) = mezza giornata
- Setup Shamir + Vault: 4-8h tecniche
- Setup cassetta sicurezza: 1 visita banca (1h) + apertura conto + visita per deposito = 2-3h totali

## 3. Procedura risposta a richiesta autorità

### 3.1 Richiesta in arrivo

Trigger event-types `crypto_custody_events`:
- `authority_request` — registrazione richiesta in arrivo.

Campi richiesti:
- `authority_name`, `authority_ref` (numero procedimento)
- `legal_basis` (es. "Art. 24-bis L. 121/1981 + decreto autorizz. PM")
- `description` (oggetto richiesta: utenti coinvolti, perimetro temporale)
- `evidence_url` (PDF firmato del provvedimento archiviato)

### 3.2 Valutazione di legittimità (Art. 6(1)(c) + (e) GDPR)

Il data controller verifica entro 72h:

- ✅ La richiesta proviene da autorità competente (tribunale, PG con autorizz.,
  Garante, ecc.).
- ✅ Esiste base giuridica esplicita (decreto, ordine, art. di legge citato).
- ✅ Il perimetro è proporzionato (non fishing expedition).
- ✅ La richiesta è in forma scritta + firmata.

Risultato:
- Se OK → `authority_granted` event.
- Se NO → `authority_denied` event con motivo; risposta scritta all'autorità.

### 3.3 Estrazione dati (solo se granted)

Procedure operative:

1. **Identificazione perimetro**: lista `teacher_id` / `user_id` coinvolti +
   range temporale.
2. **Snapshot DB** prima dell'operazione (audit-trail per integrità).
3. **Recupero KEK**: usa `KMS_MASTER_KEY` + `wrapped_kek` per ricostruire KEK
   in memoria. Logga `kek_emergency_access` per ogni docente toccato.
4. **Decifratura mirata**: solo i contenuti nel perimetro autorizzato.
   Mai estrazione massiva.
5. **Export firmato**: bundle JSON + manifest HMAC-firmato con la
   `KMS_MASTER_KEY`.
6. **Custody chain**: consegna documentata via PEC + protocollo all'autorità.

Eventi loggati: `data_recovered`, `data_provided`.

### 3.4 Notifica interessati (Art. 14 GDPR)

Salvo eccezioni (Art. 23 GDPR, segreto investigativo, ordine specifico
dell'autorità), il data controller informa gli interessati entro 30 giorni
dell'avvenuto trattamento per ordine dell'autorità.

Eccezione: ordine motivato di non divulgazione (segreto investigativo) → log
`description` con menzione dell'eccezione + scadenza non-disclosure.

## 4. Recupero dati per cause di forza maggiore

Casi:
- Docente perde accesso al proprio account + Recovery Key.
- Decesso del docente, eredi richiedono dati.
- Bug critico che ha corrotto `wrapped_kek`.

### 4.1 Per recupero docente vivo

1. Docente contatta DPO via `/dpo-contact` (Art. 15 GDPR — accesso).
2. Verifica identità: passaporto + selfie + matching email registrazione.
3. Se Recovery Key generata (`teacher_recovery_keys`):
   - Re-issue download via `/me/recovery-key/regenerate` (revoca + nuovo
     download log).
4. Se Recovery Key mai generata o revocata permanentemente:
   - Decisione di policy: il data controller può estrarre i dati lato server
     usando KMS_MASTER (è il "owner" tecnico) e consegnarli al richiedente
     verificato.
   - Logga `kek_emergency_access` con `legal_basis = "Art. 15 GDPR — accesso self"`.

### 4.2 Per eredi (decesso docente)

1. Eredi presentano documentazione (certificato decesso + dichiarazione eredità).
2. Procedura analoga al §3 (richiesta autorità) con `authority_name = "Eredi"`
   + `legal_basis = "Codice Civile art. 460 + GDPR considerando 27"`.
3. Estrazione mirata dei dati personali del defunto.

## 5. Schema log database

Tabella: `crypto_custody_events` (vedi `database/migrations/061_crypto_custody.sql`).

Tutti gli eventi sopra descritti producono righe in questa tabella. Query
storica: `SELECT * FROM crypto_custody_events WHERE teacher_id = ? ORDER BY occurred_at`.

UI: `/admin/crypto-status` (super-admin only).

## 6. Contatti e riferimenti

| Ruolo | Persona | Contatto |
|-------|---------|----------|
| Data Controller / DPO | Vittorio Pantaleo | info@pantedu.eu |
| Legal advisor (TBD) | — | — |
| Notaio custodia chiave | TODO | — |

| Riferimento | Documento |
|-------------|-----------|
| Architettura crypto | [[../../wiki/decisions/ADR-006-envelope-encryption]] |
| GDPR compliance | [[../../wiki/decisions/ADR-007-gdpr-compliance]] |
| KMS recovery operativa | [kms-recovery.md](kms-recovery.md) |
| DPA template | [../../legal/dpa_template.md](../../legal/dpa_template.md) |
| Informativa privacy | [../../privacy/informativa.md](../../privacy/informativa.md) (§11 data breach, §13 contatti) |

## 7. Modifiche

| Data | Versione | Cambio |
|------|----------|--------|
| 2026-05-21 | 1.0 | Prima bozza Phase 25.R.5.3 — copre policy custodia + recupero per autorità + forza maggiore |

<!-- Test snapshot pre-deploy automation (Phase 25.R follow-up) -->
