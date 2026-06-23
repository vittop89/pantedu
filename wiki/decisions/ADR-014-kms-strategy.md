---
tags:
  - decisions
  - architecture
  - security/crypto
date: 2026-05-21
status: differito
deciders: {{OPERATORE_NOME}}
---

# ADR-014 — KMS strategy (deferral pending DPO assessment)

## Stato

**DEFERRED** — decisione finale rimandata a Phase 27 o successiva, condizionata da:
1. valutazione del DPO scolastico
2. crescita docenti reali (trigger ≥ 25)
3. introduzione dati Art. 9 (sensibili)

Vedi anche [[ADR-006-envelope-encryption]] per la base crypto.

## Contesto

Pantedu cifra at-rest tutti i contenuti dei docenti (envelope encryption AES-256-GCM,
ADR-006). La master key `KMS_MASTER_KEY` (32 bytes hex) vive oggi in `.env.local` su:

- VPS Hetzner produzione `beta.pantedu.eu` — variabile env letta da `php-fpm`
- Backup off-line cifrato su laptop dev (gitignored, never on cloud)

Permessi attuali: `0640 pantedu:www-data` con hardening filesystem (vedi `docs/security/operations/incident-response.md` §3).

### Modello di minaccia attuale

| Attacco | Mitigato? | Note |
|---------|-----------|------|
| Backup DB rubato | ✅ | wrapped_kek da soli inutili senza KMS_MASTER |
| Snapshot filesystem (escluso .env.local) | ✅ | idem |
| Insider Hetzner cloud provider | ⚠️ parziale | DPA contrattuale + criptato disk |
| Root VPS compromesso live | ❌ | KMS_MASTER leggibile da root → all data decryptable |
| Root VPS compromesso post-reboot | ⚠️ | Cloud Firewall + hardening riducono finestra |
| Mandato giudiziario lecito | ✅ | procedura documentata (`docs/security/operations/authority-cooperation.md`) |

Lo scenario "root VPS compromesso live" è l'unico non chiuso e richiederebbe **non avere KMS_MASTER sul VPS**.

## Opzioni considerate

### Opzione 1 — Status quo (KMS in `.env.local` + hardening locale)

**Pro**:
- Latency zero (memoria locale)
- Zero ops aggiuntive
- Zero costi cloud
- Zero dipendenze esterne

**Contro**:
- Root VPS compromesso = decifratura totale possibile
- Audit log lokal cancellabile da attaccante root

**Misure compensative attive**:
- File perms `0640 pantedu:www-data`
- Hardening filesystem: `ptrace_scope=3`, `chattr +i`, AppArmor profile
- Audit immutabile off-VPS (Backblaze B2 Object Lock)
- Cloud Firewall Hetzner restrict SSH
- CrowdSec + fail2ban
- Backup off-laptop cifrati

### Opzione 2 — GCP KMS (managed cloud KMS)

**Pro**:
- Master key non sul VPS — in HSM Google
- Audit centralizzato Cloud Logging
- Revoca instant via console
- Costo basso (~€0.10-2/mese fino a 100 docenti)
- Region UE (`europe-west3` Frankfurt)
- Latency ~10-30ms da Hetzner nbg1

**Contro**:
- Lock-in Google (DPA + Standard Contractual Clauses)
- Service Account JSON file diventa "weak link" (mitigabile con IP allowlist + WIF)
- Dipendenza esterna (GCP outage → app down per crypto)
- Setup account + billing + IAM (2-3h)

### Opzione 3 — HashiCorp Vault self-hosted (VPS dedicato Hetzner CX22)

**Pro**:
- Zero lock-in cloud
- Full GDPR sovereignty (tutto Hetzner Germania)
- Latency LAN private (~1-5ms)
- Audit completo built-in
- Network isolation potenziale (firewall stretto)

**Contro**:
- Costo €4.87/mese fisso (VPS dedicato)
- Operational overhead +2-4h/mese (patch, monitor, unseal post-reboot)
- Nuovo punto di failure (Vault down → app down)
- Setup 1 giornata di lavoro
- Vault sealed dopo reboot richiede intervento manuale

### Opzione 4 — AWS KMS / Azure Key Vault

Comparabili a GCP KMS ma:
- AWS più caro ($1/mese baseline vs $0.06)
- Azure simile a AWS ma ecosistema PHP meno maturo
- Nessun vantaggio rispetto a GCP per il caso pantedu (no stack AWS/Azure pre-esistente)

## Decisione

**Per Phase 26 (oggi, <10 docenti, dati ordinari Art. 5 GDPR)**:

→ **Mantenere Opzione 1** (status quo + hardening locale rinforzato).

**Motivazione**:
- Profilo di rischio basso: ~250 individui (10 docenti × ~25 studenti), dati non sensibili Art. 9
- DPIA non obbligatoria (Art. 35 GDPR — non monitoring sistematico su larga scala)
- Misure Art. 32 "adeguate al rischio" già implementate (vedi `/security` page)
- Costo opportunità: 6-7h di hardening locale dà > 80% del beneficio rispetto a 30h+ di setup Vault/KMS esterno
- Operations: ~20 min/mese (no-regret) vs 2-4h/mese (VPS Vault)

## Trigger di revisione

Riapri questa decisione quando uno di:

| Trigger | Threshold | Direzione consigliata |
|---------|-----------|----------------------|
| Docenti reali attivi | ≥ 25 | → Valutare GCP KMS |
| Istituti distinti | ≥ 3 | → Valutare GCP KMS + audit centralizzato |
| Carichi dati Art. 9 (DSA/BES/medici) | qualsiasi | → **MIGRAZIONE IMMEDIATA a GCP KMS** + DPIA |
| Carichi minori <14 anni in numero | ≥ 10 | → Valutare GCP KMS |
| Compliance enterprise (SOC 2 / ISO 27001) | richiesta cliente | → Valutare Vault dedicato + WIF |
| DPO scolastico richiede misura specifica | qualsiasi | → Implementare richiesta DPO entro 30g |
| Budget IT mensile | ≥ €50/mese | → Vault dedicato giustificabile |

## Piano migrazione (quando trigger raggiunto)

### Path A: → GCP KMS (raccomandato se DPO accetta lock-in)

1. Setup GCP project + Cloud KMS API
2. Crea keyring `pantedu-prod` in `europe-west3`
3. Crea Service Account con scope minimo (key-level role)
4. VPC Service Controls + IP allowlist su VPS IP
5. Audit logging → Cloud Logging + alert anomaly
6. Implementare `GcpKmsClient` in `App\Services\Crypto\`
7. Refactor `TeacherCryptoService::wrap/unwrap` con feature flag `CRYPTO_PROVIDER`
8. Dual-mode migration: nuovi wrap su GCP, lettura cerca GCP poi fallback locale
9. Tool `tools/crypto/rewrap_to_gcp.php` per ri-wrappare KEK esistenti
10. Switch a `gcp_only` dopo verifica 100%
11. Backup off-line del vecchio `KMS_MASTER` (rollback option)
12. Rimuovi `KMS_MASTER_KEY` da VPS `.env.local`

Stima effort: 1.5 giorni.

### Path B: → Vault self-hosted (raccomandato se scala/compliance lo richiedono)

1. Provisioning VPS Hetzner CX22 (Falkenstein o stesso nbg1)
2. Setup Vault open-source + Transit secrets engine
3. Auth: AppRole con TTL 1h
4. Network: private network Hetzner, firewall ingress solo da IP VPS app
5. Backup Vault snapshot daily → B2
6. Implementare `VaultKmsClient` + feature flag
7. Stessa migrazione dual-mode di Path A
8. (Opzionale) HA pair: 2° VPS CX22 replica

Stima effort: 5-7 giorni totali (con HA).

## Decisione "no-regret" (Phase 26 oggi)

In attesa di re-evaluation, applicare i 6 hardening locali:

1. `kernel.yama.ptrace_scope=3` — anti dump memoria
2. `chattr +i` su `.env*` — anti tampering filesystem
3. `auditd` watch su `.env*` e `/opt/secrets/`
4. AIDE daily + email alert
5. Audit log shipping → Backblaze B2 con Object Lock retention 1 anno
6. Cloud Firewall Hetzner (SSH restrict + outbound default)
7. Hetzner snapshot pre-deploy via API token

Tutti compatibili con qualsiasi futura migrazione KMS — nessun lock-in tecnico introdotto.

## Conseguenze

### Positive

- Massima ROI sicurezza vs effort (~7h una-tantum, €0.70/mese)
- Postura ART. 32 GDPR adeguata per il livello di rischio attuale
- Pronto per re-evaluation senza rework
- Documentazione completa per DPO

### Negative / debiti tecnici

- Scenario "root VPS compromise live" non chiuso → accettato in considerazione del profilo di rischio basso
- Audit log retention dipende da Backblaze (sub-processor extra-UE con SCC) — accettato perché il primary copy resta su VPS
- KMS_MASTER rotation manuale (no automation) — TODO P27 quando si introduce KMS esterno

## Riferimenti

- [[ADR-006-envelope-encryption]] — design base crypto
- [[ADR-007-gdpr-compliance]] — quadro generale GDPR
- [docs/security/operations/authority-cooperation.md](../../docs/security/operations/authority-cooperation.md) — procedura lawful access
- [docs/security/operations/incident-response.md](../../docs/security/operations/incident-response.md) — runbook breach response
- [docs/privacy/informativa.md](../../docs/privacy/informativa.md) §8 — misure tecniche Art. 32
