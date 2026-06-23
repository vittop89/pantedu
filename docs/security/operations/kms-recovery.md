---
tags:
    - sicurezza/kms
    - runbook
    - phase/25.D11
date: 2026-04-27
tipo: runbook-operativo
status: vigente
classification: ⚠️ INTERNAL — chiavi non in chiaro
aliases: ["kms-recovery", "kms-backup", "envelope-recovery"]
---

# KMS_MASTER_KEY — backup, recovery & disaster runbook

> ⚠️ **Critico**: la perdita o leak di `KMS_MASTER_KEY` compromette TUTTI i contenuti
> cifrati Phase 25.D (envelope encryption + classe_keys). Questo documento è la
> single source of truth per gestione del segreto. Aggiornare ad ogni rotation
> o cambio operativo.

## Cos'è KMS_MASTER_KEY

32 bytes random (64 hex char) usata come **chiave master** per derivare:

- `TKEK_<teacher_id>` (Teacher Key-Encryption-Key) via HKDF-SHA256
  → wrap delle KEK random per-teacher in `teacher_keys.wrapped_kek`
  → encrypt body_html / body_pt in `teacher_content`

- `CKEK_<ind>_<cls>_<anno>_<kv>` (Class Key-Encryption-Key) via HKDF-SHA256
  → wrap delle class_key in `classe_keys.wrapped_key`
  → encrypt published_content per studenti

`KMS_MASTER_KEY` non viene MAI in:
- Git (qualsiasi repo, public o private)
- Database (qualsiasi tabella)
- Log files (storage/logs/, log/errors/, syslog)
- Sentry / monitoring exception payload
- API response (anche admin)
- Backup database (se usi mysqldump in `storage/backups/`, .env è separato)

`KMS_MASTER_KEY` vive in **3 location obbligatorie**:

1. **Server runtime** (env var, in-memory)
   - File: `.env.local` (gitignored — vedi `.gitignore` Phase 25.D eccezione)
   - Caricato via Dotenv `createMutable($basePath, '.env.local')->safeLoad()`
   - Process env via `$_ENV['KMS_MASTER_KEY']`

2. **Hardware token off-line** (Yubikey)
   - File `kms_master.gpg` cifrato con sub-key OpenPGP residente sulla Yubikey
   - Yubikey conservata in cassaforte/luogo sicuro fisico (NON nello stesso edificio del server, idealmente)

3. **Paper backup BIP-39** (32-word seed phrase)
   - 32 bytes hex → BIP-39 32-word phrase
   - Stampato/scritto a mano, conservato in cassaforte separata
   - **Mai fotografato, mai email, mai in cloud storage**

## Setup iniziale (one-shot al deploy)

### 1. Generazione chiave

Sul server di produzione (one-shot, mai re-eseguire senza piano rotation):

```bash
$ ssh prod-server
$ cd /var/www/pantedu
$ php tools/crypto/generate_kms_key.php

╔══════════════════════════════════════════════════════════════════╗
║  NEW KMS_MASTER_KEY GENERATED                                    ║
╚══════════════════════════════════════════════════════════════════╝

  KMS_MASTER_KEY=<INSERIRE_QUI_LA_NUOVA_KEY_64_HEX>
```

### 2. Salvataggio runtime

```bash
$ echo 'KMS_MASTER_KEY=<INSERIRE_QUI_LA_NUOVA_KEY>' >> .env.local
$ chmod 600 .env.local
$ chown www-data:www-data .env.local
$ ls -la .env.local
-rw------- 1 www-data www-data ... .env.local
```

Verifica che il servizio carica la chiave:

```bash
$ php -r 'require "app/bootstrap.php";
  $svc = new \App\Services\Crypto\TeacherCryptoService();
  echo $svc->isConfigured() ? "OK\n" : "FAIL\n";'
OK
```

### 3. Backup off-line (OBBLIGATORIO prima di abilitare encryption in prod)

**Step A — Yubikey GPG**:

```bash
# Su laptop sicuro (NON server prod), con Yubikey inserita
$ gpg --card-status              # verifica Yubikey attiva
$ gpg --output kms_master.gpg --encrypt --recipient your-key-id < kms_master_key.txt

# Salva kms_master.gpg in:
#   - chiavetta USB cifrata in cassaforte ufficio
#   - cloud storage personale cifrato (Tresorit, Cryptomator) come secondario
#   - mai in Drive/Dropbox in chiaro

$ shred -uvz kms_master_key.txt  # cancella sicuro il temp file
```

**Step B — Paper BIP-39 (alternativa/backup)**:

```bash
# Tool offline: https://iancoleman.io/bip39/ (download HTML, run airgap)
# Inserisci hex 64-char → genera 32-word phrase
# Stampa OPPURE scrivi a mano in 2 copie identiche
# Conservazione:
#   - copia 1: cassaforte ufficio
#   - copia 2: cassaforte casa / banca
#
# Verifica che il decoder ritorna lo stesso hex prima di archiviare.
```

**Step C — Password manager (terziario, online)**:

- 1Password / Bitwarden con vault separato dedicato a "DR/Crypto"
- Accessibile solo a 2-of-N persone designate (DPO + sysadmin senior)
- Recupero richiede 2FA + master password

### 4. Verifica restore

Almeno 2 volte: simulare recovery scenario completo (cancellare `.env.local` su staging, ripristinare da Yubikey, verificare che encrypt/decrypt funziona). **Documenta la data del drill in fondo a questo file.**

## Rotation policy

### Annuale (raccomandato)

KMS_MASTER rotation annuale (1 gennaio, server downtime ~30 min):

```bash
# 1. Genera nuova chiave (NON sovrascrivere subito .env.local!)
$ php tools/crypto/generate_kms_key.php > new_kms.txt

# 2. Mantieni old + new in env per il periodo di rewrap (server resta up)
$ cp .env.local .env.local.bak
$ echo 'KMS_MASTER_KEY_NEW=new_hex...' >> .env.local

# 3. Run rewrap script (riservato a Phase D11.future):
#    Scenario: re-derive TKEK e CKEK con new KMS_MASTER, re-wrap tutti i
#    wrapped_kek / wrapped_key. body_*_ct restano invariati.
#    
#    NB: Phase 25.D11 attuale NON include questo script. È TODO Phase D14
#    quando KMS rotation diventa effettivamente necessaria. Documentato qui
#    come placeholder strategy.

# 4. Verifica rewrap completo: tutti wrapped_* decryptable col new KMS

# 5. Switch: rimuovi old KMS, rinomina new → KMS_MASTER_KEY
$ sed -i 's/^KMS_MASTER_KEY=.*//' .env.local
$ sed -i 's/^KMS_MASTER_KEY_NEW=/KMS_MASTER_KEY=/' .env.local

# 6. Verifica: encrypt/decrypt smoke test
$ php tools/crypto/benchmark.php --iter=10 --size=small

# 7. Aggiorna backup off-line con NEW KMS (Yubikey + BIP-39 + 1Password)
# 8. Distruggi old KMS solo DOPO verifica backup new (almeno 30 giorni)
```

### Eventuale (security incident)

Se sospetti compromise KMS_MASTER:

1. **Isola immediatamente**: stop server, blocca network egress.
2. **Forensics**: log analysis, identifica vector. Notifica DPA/DPO entro 72h (Art. 33 GDPR).
3. **Decisione**: rotation forzata immediate vs full re-encrypt + nuova chiave.
4. **Re-encrypt full**: per ogni teacher → `tools/crypto/rotate_kek.php --teacher=ID --reencrypt --prune-old-kv`. Per ogni classe → `archiveYear() + getOrCreateActiveKey()` con rotation forzata.
5. **Audit**: query `crypto_access_log` per identificare possibili decrypt non autorizzati.
6. **Notifica utenti**: se data subject impacted (Art. 34 GDPR).

## Recovery scenarios

### Scenario 1: `.env.local` perso/corrotto sul server

**Sintomi**: `php tools/crypto/benchmark.php` → `kms_not_configured` exception. Tutti gli endpoint che usano encrypt/decrypt rispondono 500.

**Action**:

```bash
# 1. Stop traffic verso server (load balancer drain o nginx maintenance mode)
# 2. Recupera KMS dal Yubikey
$ gpg --decrypt kms_master.gpg > /tmp/kms_recovery.txt
$ cat /tmp/kms_recovery.txt
KMS_MASTER_KEY=<INSERIRE_QUI_LA_NUOVA_KEY>

# 3. Restore .env.local
$ echo 'KMS_MASTER_KEY=<INSERIRE_QUI_LA_NUOVA_KEY>' > .env.local
$ chmod 600 .env.local
$ chown www-data:www-data .env.local

# 4. Shred temp
$ shred -uvz /tmp/kms_recovery.txt

# 5. Verifica
$ php -r 'require "app/bootstrap.php";
  echo (new \App\Services\Crypto\TeacherCryptoService())->isConfigured()
    ? "OK\n" : "FAIL\n";'
OK

# 6. Ripristino traffic
```

### Scenario 2: Yubikey persa MA paper BIP-39 disponibile

```bash
# 1. BIP-39 phrase (32 word) → hex (32 bytes) via tool offline air-gapped
#    https://iancoleman.io/bip39/ (download offline ZIP, no JS online)
# 2. Restore .env.local con hex restored
# 3. Verify funzionamento (come Scenario 1 step 4-6)
# 4. Genera nuova Yubikey backup ASAP (rotation o re-encrypt non necessario,
#    KMS_MASTER non è cambiato)
```

### Scenario 3: Yubikey + Paper persi (CATASTROFE)

⚠️ **Recovery NON possibile** — i dati cifrati sono unrecoverable.

Procedura damage control:

1. **Notifica formale al DPO + management**: stato degradato critico.
2. **Notifica utenti** (Art. 33-34 GDPR): perdita dati personali. Questo è un breach reportable a Garante Privacy entro 72h.
3. **Recovery dati plaintext**:
   - Backup mysqldump pre-Phase 25.D ancora disponibili? Controlla retention policy (`storage/backups/db/`). Se sì, restore parziale + perdita dati post-Phase 25.D.
   - Se backup post-Phase 25.D: i body sono cifrati. Servono solo per audit/metadata.
4. **Re-bootstrap encryption**:
   - `php tools/crypto/generate_kms_key.php` → nuova chiave master.
   - Tutti i body cifrati esistenti diventano illeggibili → `DELETE FROM teacher_content WHERE body_html_ct IS NOT NULL`. Dati persi.
   - Ripristino plaintext da backup pre-25.D dove possibile.
5. **Post-mortem + Lessons Learned**: review backup procedure, aggiungi 4° location di backup (cloud crypto manager).

## Restore drill semestrale

Documenta ogni drill in fondo a questo file. Frequenza: 1 ogni 6 mesi (gennaio + luglio).

Procedura drill:

1. Annuncia drill 7 giorni prima (calendar invite a sysadmin + DPO).
2. Scegli data/ora low-traffic (domenica mattina).
3. Su staging environment (NON prod):
   ```bash
   $ rm staging-server:/var/www/.env.local
   $ ssh staging "php -r 'require \"app/bootstrap.php\";
       echo (new \\App\\Services\\Crypto\\TeacherCryptoService())->isConfigured()
       ? \"OK\\n\" : \"FAIL\\n\";'"
   FAIL  # come atteso
   ```
4. Esegui Scenario 1 recovery procedure.
5. Verifica encrypt/decrypt funziona post-restore.
6. Misura tempo totale (target < 15 min dalla detection al ripristino).
7. Compila log drill sotto.

## Drill log

| Data | Operatore | Scenario | Tempo recovery | Esito | Note |
|------|-----------|----------|----------------|-------|------|
| _TBD_ | _TBD_ | Yubikey restore | _TBD_ | _TBD_ | First drill post-Phase 25.D11 deploy |

## Audit trail

Ogni accesso al backup off-line va loggato fisicamente (registro cassaforte):

| Data | Persona | Azione | Reason | Counter-firmato |
|------|---------|--------|--------|-----------------|
| _TBD_ | _TBD_ | Setup iniziale | Phase 25.D11 deploy | _TBD_ |

## Riferimenti

- ADR-006 — design envelope encryption ([wiki/decisions/ADR-006-envelope-encryption.md](../../wiki/decisions/ADR-006-envelope-encryption.md))
- Migration 012 — schema teacher_keys ([database/migrations/012_teacher_crypto.sql](../../database/migrations/012_teacher_crypto.sql))
- Migration 014 — schema classe_keys ([database/migrations/014_published_content_classe_keys.sql](../../database/migrations/014_published_content_classe_keys.sql))
- TeacherCryptoService ([app/Services/Crypto/TeacherCryptoService.php](../../app/Services/Crypto/TeacherCryptoService.php))
- ClasseKeyService ([app/Services/Crypto/ClasseKeyService.php](../../app/Services/Crypto/ClasseKeyService.php))
- BIP-39 spec — https://github.com/bitcoin/bips/blob/master/bip-0039.mediawiki
- NIST SP 800-57 Part 1 Rev. 5 — Key Management Recommendations
- GDPR Art. 32 (sicurezza), Art. 33-34 (data breach notification), Art. 35 (DPIA)
