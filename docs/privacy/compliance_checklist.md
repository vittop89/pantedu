# Compliance Checklist Finale — Phase 14 (2026-04-16)

Copertura tecnica post BATCH 1-5.

## Tecnica GDPR

| Requisito | Stato | Evidenza |
|---|:-:|---|
| Privacy by design/default (art. 25) | ✅ | AclPolicy scoping teacher-id default; super-admin esclude studenti |
| Minimizzazione dati (art. 5 §1 c) | ✅ | UsersAdminController::index filtra `role<>'student'` e unset `email` per super-admin |
| Cifratura in transito (art. 32) | ✅ | HTTPS/TLS 1.2+ con HSTS preload (edge Cloudflare + origin Hetzner) |
| Cifratura at-rest | ✅ | Phase 25.D envelope encryption AES-256-GCM (ADR-006). Per-teacher KEK derivata via HKDF-SHA256 da KMS_MASTER_KEY off-line backed up. Crypto-shredding O(1) per Art. 17. |
| Password hashing robusto | ✅ | bcrypt cost 12 (RegistrationService + seed_super_admin) |
| Audit log accessi privilegiati (art. 30) | ✅ | `privileged_access_log` append-only + reason obbligatorio |
| Immutabilità log | ⚠️ | Da completare con `REVOKE UPDATE,DELETE` sul ruolo applicativo (comando in schema.sql commento) |
| Retention policy (art. 5 §1 e) | ✅ | `app/Config/retention.php` + `tools/gdpr/anonymize_expired.php` |
| Diritti interessati (artt. 15-22) | ✅ | Phase 25.C self-service: /me/consents (grant/revoke), /me/request-deletion (Art. 17 + crypto-shredding 30g cooling-off), /me/export-data (Art. 20 JSON), /me/profile (Art. 16 PATCH). E2E 8/8 pass. |
| Data breach runbook (artt. 33-34) | ✅ | `docs/privacy/data_breach_runbook.md` |
| DPIA (art. 35) | ✅ | Phase 25.C9 — `docs/privacy/dpia.md` v1.0 completa. 14 rischi mappati con misure mitiganti. Bozza firmabile dal Titolare. Consultazione Garante NON necessaria condizionata a chiusura R6+R7. |
| Policy aggiornata | ✅ | Phase 25.C10 — `docs/privacy/informativa.md` v2.0 con disclosure IP/UA SHA-256 hash + BES/DSA Art. 9 + minori Art. 8 + self-service /me/* endpoints |
| Registro trattamenti | ✅ | Phase 25.C8 — `docs/privacy/registro-trattamenti.md` v1.0. Redatto come buona pratica (Art. 5 §2 accountability + dati minori Art. 8). 8 trattamenti documentati, sub-processor hosting Hetzner (DE, UE). **Correzione 2026-04-27**: rimosso falso positivo "Art. 9 BES/DSA" — verificato sul codebase: app processa solo metadata contenuto del docente, no dati sanitari studente individuali. |

## ACL runtime

| Controllo | Stato | Evidenza |
|---|:-:|---|
| Teacher vede solo propri materiali | ✅ | `TeacherContentController` scoping `teacher_id = currentUser` (pre-esistente) |
| Teacher vede solo propri studenti | ✅ | `AclPolicy::canReadStudentsOfTeacher` enforced; no endpoint cross-teacher |
| Super-admin zero accesso studenti | ✅ | `UsersAdminController::index` + AclPolicy |
| Super-admin read-only materiali tracciato | ✅ | `PrivilegedAccessLogger` richiesto con reason |
| Pool istituto condiviso | ⚠️ | Schema pronto (`institute_pool_policy`, `shared_with_pool`); UI pool da cablare |
| Sidebar ATTIVA solo per proprietario | ⚠️ | Attualmente visibile a tutti admin/teacher; filtering per owner sidepage rimandato (richiede data-owner su .sidepage, phase successiva) |

## Storage & infrastruttura

| Requisito | Stato | Evidenza |
|---|:-:|---|
| Abstraction layer storage | ✅ | `StorageProvider` + Local + S3 stub + Factory |
| Nessun path fisico hardcoded nuove feature | ✅ | `storage.default_provider` + `storage_objects` metadati |
| Metadati indipendenti dal provider | ✅ | `storage_objects(provider, storage_key, checksum, size, mime, visibility, owner, institute)` |
| Signed URL con TTL | ✅ | `LocalStorageProvider::signedUrl` HMAC-SHA256, TTL clampato [60,3600]s |
| Dashboard super-admin | ✅ | `/admin/infrastructure` + `/api/admin/infrastructure.json` |
| Soglie alert 70/85/95% | ✅ | `app/Config/monitoring.php` + threshold label in snapshot |
| Backup DB + file fuori hosting | ⚠️ | Dirs configurate (`storage/backups/{db,files}`); esportazione off-hosting da pianificare cron |
| Test periodico restore | ⚠️ | Procedura da formalizzare |

## Gap residui (priorità)
1. **Alta**: eseguire `REVOKE UPDATE,DELETE ON privileged_access_log` sul ruolo DB applicativo dopo deploy migration.
2. **Alta**: configurare backup MySQL off-hosting (rsync → storage esterno o bucket S3) + test restore mensile.
3. **Media**: cablare UI sidebar per filtrare ATTIVA per owner della sidepage (richiede annotazione `data-owner-id` sui .sidepage generati).
4. **Media**: implementare endpoint self-service diritti interessati (`/me/data-export`, `/me/delete`).
5. **Bassa**: controller `/storage/signed` per servire file da `LocalStorageProvider::signedUrl`.
6. **Bassa**: compilare DPIA completa prima di introdurre analytics avanzata.

## Comandi post-deploy consigliati
```sql
-- M13/M14 ALTER idempotenti
ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
ALTER TABLE teacher_content ADD COLUMN shared_with_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility;
UPDATE users SET is_super_admin = 1 WHERE role = 'admin';

-- hardening audit log
REVOKE UPDATE, DELETE ON privileged_access_log FROM 'pantedu_app'@'%';
GRANT SELECT, INSERT ON privileged_access_log TO 'pantedu_app'@'%';
FLUSH PRIVILEGES;
```

```bash
# pre-warm MIUR index (una tantum, high memory)
php -d memory_limit=512M -r "require 'app/bootstrap.php'; \
  App\Services\MiurSchoolsService::fromConfig()->search('Esempio');"

# seed super-admin
php tools/seeds/seed_super_admin.php 'SecurePassword!'

# GDPR retention dry-run
php tools/gdpr/anonymize_expired.php
# applicare davvero:
GDPR_RETENTION_ENABLED=1 php tools/gdpr/anonymize_expired.php
```
