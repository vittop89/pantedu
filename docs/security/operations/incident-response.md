---
tags:
  - security/operations
  - incident-response
date: 2026-05-21
status: operativo
review: 2027-05-21
---

# Incident Response Runbook — Pantedu

> **Scope**: procedura step-by-step per gestire incident di sicurezza
> (data breach, compromissione VPS, leak credenziali, DoS).
>
> **Frameworks di riferimento**:
> - NIST SP 800-61 Rev. 2 (Computer Security Incident Handling Guide)
> - GDPR Art. 33-34 (notifica Garante + interessati)
> - ENISA Incident Response Maturity Model

## 1. Contatti — STAMPARE E CONSERVARE OFFLINE

| Ruolo | Persona | Contatto primario | Contatto secondario |
|-------|---------|-------------------|---------------------|
| Data Controller / DPO | Vittorio Pantaleo | info@pantedu.eu | (cell. personale) |
| DPO scolastico | TODO | TODO | TODO |
| Hetzner support | — | support@hetzner.com | https://console.hetzner.cloud/support |
| Backblaze support | — | https://help.backblaze.com | — |
| Garante Privacy emergenze | Numero verde reclami | 800-622-622 | https://servizi.gpdp.it/databreach |
| Polizia Postale | C.N.A.I.P.I.C. | +39 06 4654 4001 | denuncia@pec.poliziadistato.it |

**Garante data breach notification online**: https://servizi.gpdp.it/databreach/s/

## 2. Tipologie di incident e criticità

| Tipo | Criticità | SLA notifica Garante | Esempio |
|------|-----------|----------------------|---------|
| **DB exfiltration** | 🔴 critical | 72h (Art. 33) | Dump completo `users` + `teacher_content` |
| **KMS_MASTER leak** | 🔴 critical | 72h | `.env.local` letta da terzi |
| **VPS root compromise** | 🔴 critical | 72h (presunto breach) | shell non autorizzata in `/root` |
| **Account docente takeover** | 🟠 high | 72h se dati altri esposti | password reuse + login da IP estero |
| **DoS / availability** | 🟡 medium | non richiesta (no breach dati) | flood requests, sito down |
| **Defacement** | 🟡 medium | valutare caso per caso | homepage alterata |
| **Phishing pantedu-themed** | 🟢 low | non richiesta | email truffa con logo |
| **Copyright takedown request** | 🟢 low | non richiesta | art. 16 D.Lgs 70/2003 |

## 3. Procedura standard (4 fasi NIST)

### Phase 3.1 — IDENTIFICATION (rilevamento)

**Trigger possibili**:
- ⚠️ Alert AIDE: file integrity changed (es. `/usr/sbin/php-fpm8.4` hash mismatch)
- ⚠️ Alert CrowdSec: massive ban / anomaly
- ⚠️ Alert auditd: lettura `.env.local` da utente diverso da www-data/pantedu
- ⚠️ Alert Cloud Monitoring: rate decrypt anomalo (se GCP KMS attivo Phase 27+)
- ⚠️ Utente segnala: "non riesco a fare login" / "vedo dati di altri"
- ⚠️ Backup giornaliero B2 fallisce 2 giorni di fila
- ⚠️ Hetzner contatto admin: "abuse report"
- ⚠️ Tu noti attività sospetta in `privileged_access_log`

**STEP 1 — Verifica preliminare (5-15 min)**

```bash
# Connetti via SSH (se firewall ti blocca → console web Hetzner)
ssh pantedu-vps

# 1. Chi è loggato adesso
who -a
last -20

# 2. Processi sospetti
ps auxf | grep -vE '\[.*\]' | sort -rk3 | head -30

# 3. Connessioni network attive
ss -tunp | grep -v '127.0.0.1\|::1'

# 4. Modifiche recenti filesystem
find /var/www/pantedu -mtime -1 -type f 2>/dev/null | head -50
find /etc -mtime -1 -type f 2>/dev/null | head -20

# 5. Audit log ultimi 100 eventi
sudo ausearch --start today | tail -100

# 6. AIDE check
sudo aide --check 2>&1 | head -50

# 7. Web server log ultimi errori
tail -100 /var/log/nginx/error.log
tail -100 /var/www/pantedu/storage/logs/php_errors.log
tail -100 /var/www/pantedu/storage/logs/access_log.json

# 8. crypto_access_log anomalie
mysql -e "SELECT * FROM pantedu.crypto_access_log WHERE created_at > NOW() - INTERVAL 24 HOUR ORDER BY id DESC LIMIT 50"
```

**STEP 2 — Decisione**: è un vero incident o falso positivo?

- **Falso positivo** (es. AIDE alert su file legittimamente cambiati) → aggiorna baseline `aide --update`, documenta in `log/false_positives.log`. STOP.
- **Vero incident** → continua al Phase 3.2.

### Phase 3.2 — CONTAINMENT (contenimento, primi 15-60 min)

**STEP 3 — Stop the bleeding**

In ordine di priorità:

```bash
# 1. Se sospetti compromissione root → ISOLA il VPS dalla rete pubblica
# Via Hetzner Console (web): server → Networking → disable public IP
# OPPURE rimuovi firewall rule HTTP/HTTPS temporaneamente
# Questo blocca ulteriori exfil ma ti lascia SSH (se firewall lo permette)

# 2. Se sospetti KMS_MASTER leak → IMMEDIATAMENTE:
# 2a. Cambia tutte le credenziali ammin
sudo passwd pantedu   # nuova password forte
# 2b. Revoca SSH keys non riconosciute
cat ~/.ssh/authorized_keys
sudo nano /root/.ssh/authorized_keys  # rimuovi chiavi sconosciute
# 2c. Forza logout di TUTTI gli utenti web
mysql -e "DELETE FROM pantedu.user_sessions"  # se esiste tabella; altrimenti truncate session files
sudo rm -rf /var/www/pantedu/storage/sessions/*

# 3. Se sospetti specific account takeover docente → disabilita quel docente
mysql -e "UPDATE pantedu.users SET active=0 WHERE username='X'"

# 4. Snapshot Hetzner del VPS (forensics)
# Via API o console: crea snapshot etichettato "incident-YYYYMMDD-HHMM"
# NON cancellarlo finché incident non chiuso

# 5. Esporta audit log a Backblaze IMMEDIATAMENTE (oltre il cron daily)
/opt/scripts/ship_audit_logs.sh --force --incident
```

**STEP 4 — Comunicazione interna (entro 1h dal trigger)**

- Annota timestamp esatto rilevamento, descrizione, evidenze in `log/incidents/YYYYMMDD-<tag>.md`
- Apri thread/email a DPO scolastico (se incident coinvolge dati studenti)
- Se penali sospetti (intrusione, frode) → notifica Polizia Postale (allegando log)

### Phase 3.3 — ERADICATION (eradicazione, 1-24h)

**STEP 5 — Identifica vector di attacco**

```bash
# Analizza la timeline:
# - quando è iniziato?
# - quale endpoint sfruttato?
# - quale credenziale?
sudo aureport --auth   # tentativi login
sudo aureport --executable  # binari eseguiti
sudo journalctl --since "24 hours ago" | grep -iE 'fail|error|denied'

# Verifica se c'è webshell PHP nei file
find /var/www/pantedu -name "*.php" -mtime -7 -newer /etc/timestamp_baseline -exec grep -l 'eval(\|base64_decode\|system(\|exec(' {} \;
```

**STEP 6 — Rimuovi presenza attaccante**

```bash
# 1. Termina processi sospetti
sudo kill -9 <PID_sospetto>

# 2. Rimuovi webshell / file inseriti
sudo rm /var/www/pantedu/path/to/malware.php

# 3. Se sospetti modifiche binari di sistema → reinstall pacchetto Debian
sudo apt install --reinstall <package>

# 4. Se incident grave → rebuild VPS da zero:
# 4a. Backup DB + storage attuale
# 4b. Crea NUOVO VPS Hetzner pulito
# 4c. Restore da snapshot pre-incident (verificato)
# 4d. Cambia IP DNS verso nuovo VPS
# 4e. Vecchio VPS rimane offline come "evidence", scaricato per forensics
```

**STEP 7 — Rotazione segreti compromessi**

Se KMS_MASTER potrebbe essere stata letta:

```bash
# ATTENZIONE: questo è uno scenario DISTRUTTIVO
# Decifrare tutti i blob ESISTENTI prima di sostituire KMS_MASTER
# Vedi docs/security/operations/kms-recovery.md

# 1. Backup pre-rotation
mysqldump pantedu > /tmp/pre-rotation-$(date +%s).sql

# 2. Genera nuova KMS_MASTER
php tools/crypto/generate_kms_key.php > /tmp/new_kms.txt

# 3. Re-wrap tutti i wrapped_kek con la nuova master
# (richiede vecchia + nuova in memoria simultaneamente)
php tools/crypto/rotate_kms_master.php --old=<old_hex> --new=<new_hex>

# 4. Sostituisci .env.local con nuova
sudo sed -i 's/^KMS_MASTER_KEY=.*/KMS_MASTER_KEY=<new_hex>/' /var/www/pantedu/.env.local
sudo systemctl reload php8.4-fpm

# 5. Backup OLD KMS_MASTER su pendrive offline (rollback emergency)
```

Per tutti gli altri segreti (DB pass, WAF HMAC, ecc.): documentati in `.env.example` con stesso pattern.

### Phase 3.4 — RECOVERY (ripristino, 1-72h)

**STEP 8 — Verifica integrità sistemi**

```bash
# AIDE check completo
sudo aide --check

# Test login utenti chiave (te + 1-2 docenti pilota)
# Test crypto: encrypt/decrypt round-trip
php tools/crypto/test_e2e_blob.php <test_content_id>

# Test backup pipeline
/opt/scripts/ship_audit_logs.sh --dry-run
```

**STEP 9 — Riattiva accessi**

- Sblocca utenti reimpostati
- Riapri firewall HTTP/HTTPS
- Notifica utenti via banner se richiesto reset password

**STEP 10 — Notifica GDPR Art. 33 (entro 72h dal rilevamento)**

Se dati personali sono stati esposti:

1. Apri `https://servizi.gpdp.it/databreach/s/`
2. Compila form Garante:
   - Data e ora rilevamento
   - Natura del breach (confidentiality / integrity / availability)
   - Categorie e numero approssimativo interessati
   - Categorie di dati
   - Conseguenze probabili
   - Misure adottate / proposte
3. Salva PDF della notifica → `log/incidents/YYYYMMDD-garante-notifica.pdf`

Se rischio "elevato per i diritti e libertà" → notifica anche gli interessati (Art. 34):
- Email diretta ai data subject coinvolti (template in `docs/legal/templates/breach_notification_user.md`)
- Banner in-app
- Comunicazione DPO scolastico per tramite scuola se appropriato

**STEP 11 — Registra incident in `/admin/data-breach`**

UI super-admin → "+ Nuovo incident" → compila tutti i campi:
- occurred_at + detected_at (per il countdown SLA 72h)
- severity (low/medium/high/critical)
- affected_users_count
- description, root_cause, remedial_actions
- evidence_url (link al PDF della notifica Garante)

### Phase 3.5 — POST-INCIDENT (1-2 settimane)

**STEP 12 — Post-mortem**

Crea documento `log/incidents/YYYYMMDD-<tag>-postmortem.md` con:

- **Timeline** completa (rilevamento → containment → recovery)
- **Vector di attacco**
- **Cosa è andato bene** / **Cosa è andato male**
- **Action items**:
  - Patch immediate (codice, configurazione)
  - Hardening preventivo (es. nuove regole CrowdSec)
  - Aggiornamento questo runbook
- **Cost analysis**: ore dedicate, downtime, eventuali sanzioni

**STEP 13 — Aggiornamento sicurezza**

- Implementa action items entro 30g
- Aggiorna `docs/privacy/data_breach_runbook.md` con lezione appresa
- Drill di simulazione: prossima volta che è OK perdere 1h, ripeti questo runbook su un breach finto

## 4. Decision tree quick reference

```
ALERT ricevuto
  │
  ├─ Falso positivo? ────────────→ Documenta + chiudi
  │
  ├─ Vero incident?
  │   │
  │   ├─ Confidentiality (dati esposti)?
  │   │   ├─ YES → Phase 3.2 CONTAINMENT urgente
  │   │   │       → Notifica Garante 72h
  │   │   │       → Possibile notifica utenti
  │   │   └─ NO  → Phase 3.2 ma SLA più rilassata
  │   │
  │   ├─ Integrity (dati alterati)?
  │   │   ├─ YES → Phase 3.2 + restore da backup
  │   │   └─ NO  → continua a Phase 3.3
  │   │
  │   └─ Availability (DoS, sito down)?
  │       ├─ YES → CrowdSec ban + scaling + (no notifica Garante)
  │       └─ NO  → continua
  │
  └─ Phase 3.3 ERADICATION
      → Phase 3.4 RECOVERY
      → Phase 3.5 POST-MORTEM
```

## 5. Procedure speciali

### 5.1 Lockout SSH (firewall mi blocca)

1. Hetzner Console web → server → tab "Console"
2. Login VNC (user pantedu, password locale o root)
3. Edit firewall via API o disabilita temporaneamente
4. Ripristina accesso

### 5.2 Sospetto attaccante ancora attivo

- NON cancellare log (forensics)
- NON spegnere VPS (potresti perdere RAM evidence)
- Snapshot Hetzner immediato (preserva stato)
- Isola network ma mantieni SSH access
- Coordina con C.N.A.I.P.I.C. se ipotesi reato

### 5.3 Recovery da backup compromesso

Se snapshot/backup recente potrebbe contenere malware:

1. Identifica primo timestamp "pulito" pre-attack
2. Restore da quel snapshot
3. Comunica utenti: dati creati dopo X data possono essere persi
4. Documenta data loss in Art. 33 notifica

### 5.4 Loss of KMS_MASTER (chiave persa, non rubata)

Vedi `docs/security/operations/kms-recovery.md`. Sintesi:

- Se persa **chiave produzione + chiave backup laptop** → crypto-shred totale automatico (irreversibile)
- Notifica Garante: Art. 33 (loss of availability)
- Comunicazione utenti: "perso accesso ai materiali cifrati post-data X"
- Restore solo dati plaintext (es. `users`, audit log, metadata non cifrati)

## 6. Template snippets

### 6.1 Notifica utente per breach

```text
Oggetto: [Comunicazione importante] Incident di sicurezza pantedu

Gentile [nome],

In data [DD/MM/YYYY] alle ore [HH:MM] abbiamo rilevato un incident di
sicurezza che potrebbe aver coinvolto i tuoi dati personali su pantedu.

NATURA: [confidentiality breach / data loss / ...]
DATI POTENZIALMENTE COINVOLTI: [nome, cognome, ...]
MISURE ADOTTATE: [...]
COSA PUOI FARE:
- Cambia la password pantedu entro [data]
- Se hai usato la stessa password altrove cambiala anche lì
- Monitora per attività sospetta
RIFERIMENTI:
- Notifica Garante: protocollo n° [...]
- DPO: info@pantedu.eu
- Garante Privacy reclami: https://servizi.gpdp.it

Cordiali saluti,
Vittorio Pantaleo
Data Controller pantedu
```

### 6.2 Comunicazione DPO scolastico

```text
Oggetto: [Notifica DPO] Incident sicurezza piattaforma pantedu

Egr. [Nome DPO],

In qualità di docente che utilizza la piattaforma pantedu nel contesto
dell'istituto [nome scuola], le comunico il seguente incident:

DATA RILEVAMENTO: [...]
DATI ISTITUTO COINVOLTI: [...] studenti, [...] docenti
NATURA: [...]
MISURE: [...]

Ho già notificato il Garante (protocollo [...]) e gli interessati diretti.
Resto a disposizione per qualsiasi chiarimento o supporto al suo ruolo
di DPO scolastico.

Cordiali saluti,
Vittorio Pantaleo
docente + data controller pantedu
```

## 7. Manutenzione di questo runbook

- **Review obbligatoria**: ogni 12 mesi (next: 2027-05-21)
- **Aggiornamento ad hoc**: dopo ogni incident reale o drill
- **Validazione**: simulazione semi-annuale (tabletop exercise)
- **Audit**: linea di principio Art. 32(1)(d) GDPR "procedura per verificare e valutare regolarmente l'efficacia delle misure"

## 8. Riferimenti

- [docs/privacy/data_breach_runbook.md](../../privacy/data_breach_runbook.md) — Runbook breach GDPR
- [docs/security/operations/authority-cooperation.md](authority-cooperation.md) — Cooperazione autorità
- [docs/security/operations/kms-recovery.md](kms-recovery.md) — KMS recovery
- [wiki/decisions/ADR-014-kms-strategy](../../../wiki/decisions/ADR-014-kms-strategy.md) — KMS deferral
- NIST SP 800-61 Rev. 2 (https://csrc.nist.gov/publications/detail/sp/800-61/rev-2/final)
- GDPR Art. 33-34
- ENISA Incident Reporting (https://www.enisa.europa.eu/topics/incident-reporting)
