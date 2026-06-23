---
tags:
    - decisione
    - architettura
    - sicurezza
date: 2026-04-27
status: accettato
phase: 25.D
aliases: ["adr-006", "envelope-encryption", "crypto-shredding"]
---

# ADR-006 — Envelope encryption applicativa AES-256-GCM con crypto-shredding

## Contesto

Phase 25 audit ha rilevato 3 gap critici Art. 32 GDPR (sicurezza) + diritto d'autore docente:

1. **Cifratura at-rest mancante**: dump DB compromesso = tutti i body docenti (esercizi, verifiche, mappe, risdoc) leggibili in chiaro. Backup mysql idem.
2. **Admin curioso (super_admin tecnico)**: ha accesso DB diretto. Anche se loggato in `privileged_access_log`, può leggere offline qualsiasi contenuto teacher senza intervento del proprietario.
3. **Diritto all'oblio Art. 17**: `DELETE FROM teacher_content WHERE teacher_id=?` lascia tracce in backup, replica, archive. Cancellazione "definitiva" è O(n) e irrecuperabile per chi sbaglia.

Il threat model copre:
- DB dump (sviluppatore esterno, breach hosting)
- Backup esposti (cron rsync senza encryption)
- Admin/super_admin che legge senza autorizzazione documentata
- Studente o terzo che ottenga shell sul server
- Richiesta GDPR di cancellazione completa

## Decisione

Implementare **envelope encryption applicativa**:

```
                                    ┌─────────────────┐
                                    │  KMS_MASTER_KEY │  (32 bytes, env var, mai in DB)
                                    └────────┬────────┘
                                             │ HKDF-SHA256 (per-teacher derivation)
                                             ▼
                       ┌─────────────────────────────────┐
                       │  TKEK_<teacher_id>              │  Teacher Key-Encryption-Key
                       │  (derived in-memory, ephemeral) │
                       └────────┬────────────────────────┘
                                │ AES-256-GCM wrap
                                ▼
              ┌──────────────────────────────┐
              │ teacher_keys (DB row)         │
              │  teacher_id │ wrapped_kek    │  KEK = data-encryption "wrapper"
              │  key_version│ + iv + tag      │   (per-teacher, 1 row)
              └──────┬───────────────────────┘
                     │
                     │ unwrap (only on read with Auth)
                     ▼ KEK
              ┌──────────────────────────────┐ AES-256-GCM ciphertext
              │ teacher_content              │
              │  body_pt_ct (BLOB) ← plain   │
              │  body_pt_iv (12B random)     │  (no per-row DEK — KEK encrypts directly,
              │  body_pt_tag (16B GCM)       │   semplificazione vs full envelope per
              │  body_pt_kv (key version)    │   data items "small" come body_pt)
              └──────────────────────────────┘
```

### Razionale: KEK direct vs envelope full

Il modello "full envelope" (KMS → KEK per teacher → DEK per row → encrypt) è ottimale per oggetti grandi (>10MB) dove la rotation di una DEK singola è preferibile alla re-encryption del body. Per Pantedu:

- `teacher_content.body_pt` = 1-50 KB tipicamente
- `teacher_content.body_html` = 1-200 KB
- `risdoc_overrides.body` = 1-50 KB

Tutti "piccoli". La rotation costa quanto re-encrypt. **Decisione**: KEK encrypts directly, no DEK separato. Riduce 1 livello di complessità + 1 lookup, mantiene proprietà:

- Per-teacher isolation (KEK ≠ tra teacher)
- Crypto-shredding O(1) (delete teacher_keys row → tutti body unreadable)
- KMS_MASTER mai esce dal server (in env var)

Per oggetti grandi futuri (PDF compilati, immagini >1MB) si potrà migrare a full envelope con DEK per-file in fase successiva.

### Algoritmi

| Step | Algorithm | Note |
|------|-----------|------|
| Derivation KMS → TKEK | HKDF-SHA256 | salt = `"pantedu-teacher-kek-v1\|{teacher_id}"`, info = key_version |
| Wrap TKEK → wrapped_kek | AES-256-GCM | iv = random 12B, tag = 16B (95-byte total: 32 ct + 12 iv + 16 tag + ...) |
| Encrypt body | AES-256-GCM | iv = random 12B per riga, tag = 16B authenticator |
| Random source | `random_bytes()` PHP | CSPRNG: `/dev/urandom` o equivalente OS |

AES-GCM scelto vs CBC perché:
- Authenticated encryption (tag previene tampering)
- Standard NIST SP 800-38D
- PHP `openssl_encrypt(..., 'aes-256-gcm', ...)` nativo, no userland crypto

### Crypto-shredding per Art. 17 GDPR

Cancellazione del row `teacher_keys WHERE teacher_id=?` rende **immediatamente illeggibili** tutti i `body_*_ct` di quel teacher, senza dover toccare la tabella `teacher_content` (che ha milioni di righe potenzialmente). Il dato cifrato resta nel DB, ma senza KEK è inutilizzabile (AES-256 bruteforce ≈ 2^128 op).

Backup contenenti il body cifrato sono comunque illeggibili (KEK rotata + KMS_MASTER non in backup, separati).

Vantaggi vs DELETE classico:
- O(1) operazione (1 row vs n rows)
- Atomic (no race con concurrent reads)
- Non tocca foreign key relationships (audit trail conservato)
- Backup vecchi rimangono illeggibili senza KEK

### Dual-role super_admin (superadmin)

Super_admin ha accesso DB ma NON ha automaticamente accesso al body in chiaro. Scenari:

1. **Lettura propri contenuti** (teacher_id == accessor_id): TeacherCryptoService::decrypt riceve `$teacher_id == $accessor_id`. Authorized. Log in `crypto_access_log` (action=decrypt, accessor=teacher).
2. **Lettura contenuti altri docenti** (teacher_id != accessor_id):
   - **Default**: NEGATO da Permission::canView (gating attuale).
   - **Eccezione**: se accessor è super_admin + ha `audit_reason` valido → autorizzato. Decrypt loggato con `accessor=super_admin, teacher=N, reason=...`.
   - Compromesso necessario per: incident response, supporto utente che ha perso accesso, legal hold.
3. **Crypto-shredding di un docente cancellato**: super_admin trigger via `/admin/users/{id}/delete` con audit_reason. shred() chiamata in transazione.

### KMS_MASTER_KEY backup

Critico: perdita di KMS_MASTER = perdita di tutti i dati cifrati. Backup obbligatorio:

1. **Server env**: `KMS_MASTER_KEY` in `.env` (non versionato in repo public; in repo private è documentato come "configurare prima di deploy").
2. **Off-line**: Yubikey hardware con PGP-encrypted blob OR paper backup BIP-39 (32-word seed phrase, encoded da 32 bytes hex).
3. **Restore drill**: semestrale, documentato in `docs/security/operations/kms-recovery.md`.

Nessuna chiave **mai** in:
- Git (ANY repo, public o private)
- `risdoc_templates`/altre tabelle DB
- Log files
- Sentry/monitoring exception payload
- API responses

## Conseguenze

### Positive

- **Art. 32 GDPR compliance**: cifratura at-rest end-to-end.
- **Crypto-shredding O(1)**: Art. 17 GDPR efficiente, audit trail preservato.
- **Diritto d'autore docente**: contenuti accessibili SOLO al proprietario + super_admin con audit_reason. Dump DB cifrato.
- **Backup safer**: cifratura sopravvive a backup compromessi (purché KMS_MASTER off-site).
- **Per-teacher isolation crittografica**: KEK ≠ tra docenti, leak di una KEK non compromette altri.

### Negative

- **Performance overhead**: ~5-10ms p99 per decrypt body_pt (target < 100ms p99 totale request, accettabile). `tools/crypto/benchmark.php` valida.
- **Search impossibile su body**: ricerca full-text su `body_pt_ct` non possibile. Ricerca attuale su `title`/`topic` (in chiaro) resta. Future: encrypted search via blind index se necessario.
- **KMS rotation costoso**: re-wrap di tutti `wrapped_kek` (1 row per teacher → fattibile, ~minuti per 1000 docenti). Annual rotation.
- **Backup KMS_MASTER off-line obbligatorio**: nuovo single-point-of-failure se non backuppato. Mitigation: BIP-39 paper + Yubikey + drill semestrale.
- **Search/sort/aggregation perse**: `WHERE body LIKE '%...%'` ora impossibile. Adattare query analytics che dipendevano dal body in chiaro (rare nel codebase attuale).
- **No browser-side encryption**: il browser riceve plaintext (HTTPS). Threat model NON copre client compromesso (browser malware che legge DOM). Per E2E browser-server crypto serve WebCrypto + chiavi derivate da password — fuori scope.

### Neutrali

- **Nessun frontend change**: server decifra, browser riceve plaintext via HTTPS.
- **Nessuna API breaking**: feature flag `CRYPTO_DUAL_WRITE` permette transizione graduale.
- **Backwards compat plaintext**: durante backfill, repository legge prima plaintext (legacy) poi ciphertext (Phase D), facendo override su read.

## Implementazione

Phase 25.D (commit serializzati):

1. **D1+D2** ✅ (questo commit) — Service + Migration additive, plaintext PRESERVATO.
2. **D3** — Repository dual-write con feature flag `CRYPTO_DUAL_WRITE=true`.
3. **D4** — Backfill `tools/crypto/backfill_teacher_content.php` con verify byte-by-byte.
4. **D5-D6** — Rotation + classe_keys per pubblicazione studenti.
5. **D7-D11** — Test, benchmark, monitoring, KMS backup runbook.
6. **D13** — Drop plaintext columns (solo dopo verifica D4 + flag `CRYPTO_READ_FROM=ciphertext`).

Rollback per ogni sub-phase: feature flag toggle off → repository legge plaintext + ignora colonne `*_ct`. Migration additive, no data loss.

## Riferimenti

- NIST SP 800-38D (AES-GCM)
- RFC 5869 (HKDF)
- OWASP Cryptographic Storage Cheat Sheet
- Crypto-shredding: Cloud Security Alliance "Top Threats to Cloud Computing"
- `wiki/changelog.md` Phase 25.D entries
- `docs/security/operations/kms-recovery.md` (KMS_MASTER backup runbook)
