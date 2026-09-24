# app/Services/Crypto — envelope encryption

Cifratura a riposo per-docente dei contenuti sensibili (mappe, verifiche, blob). Design: **envelope encryption** (KMS master key → KEK per docente → DEK per blob), AES-256-GCM, derivazione HKDF-SHA256. Decisione: **ADR-006** (`wiki/decisions/`).

## File chiave

| File | Ruolo |
|------|-------|
| `ChiaveMadre.php` | L'unica lettura di `KMS_MASTER_KEY` e l'unica regola: assente, valida (64 caratteri esadecimali) o malformata. Chi scriverebbe in chiaro senza chiave, con una chiave malformata si ferma |
| `TeacherCryptoService.php` | Cuore: `ensureTeacherKey()`, wrap/unwrap KEK, encrypt/decrypt blob. La chiave da `ChiaveMadre` |
| `EncryptedBlobStore.php` | Storage dei blob cifrati (`storage/maps_enc/`, ecc.) |
| `TeacherRecoveryService.php` | Recovery chiave docente |
| `ShamirSecretSharing.php` | Split/recombine del segreto (recovery a soglia) |
| `RiavvolgimentoDellaMaster.php` | Cambio della `KMS_MASTER_KEY`: riavvolge con la chiave nuova `teacher_keys.wrapped_kek` e `teacher_recovery_keys.wrapped_recovery`, le sole due cose cifrate con la master; prova, applica, verifica |
| `ComandoDiRiavvolgimento.php` | Il corpo di `tools/crypto/rewrap_master.php` (argomenti, rifiuti, resoconto con soli conteggi e id). Procedura: `docs/security/operations/kms-recovery.md` |

## ⚠️ Pre-flight guard (NON rimuovere)

`TeacherCryptoService::ensureTeacherKey()` ha un **guard contro la rigenerazione distruttiva**: se manca la riga `teacher_keys` MA esistono già blob cifrati (`teacher_content` cifrati o file in `maps_enc/{tid}/`), **rifiuta** di generare una nuova KEK e solleva eccezione. Senza questo guard, una KEK rigenerata silenziosamente rende **indecifrabili tutti i blob precedenti**.

Origine: **INCIDENT 2026-05-13** (147+→212 mappe indecifrabili, recovery 90-91%). Dettaglio in `docs/todo/security-history.md` §16.4. Bypass solo con `ALLOW_CRYPTO_REGENERATE=1` in `.env.local`.

## Note operative

- **Backup obbligatorio** dei key store (`teacher_keys`) separato e cifrato (cron) — una chiave persa = blob persi.
- Audit delle operazioni wrap/encrypt in `crypto_access_log`.
- I file scritti come root via SSH diventano `root:root` → `www-data` non legge → `chown www-data` (vedi history).
