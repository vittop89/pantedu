---
tags:
    - decisione
    - gdpr
    - privacy
date: 2026-04-27
status: accettato
phase: 25.C
aliases: ["adr-007", "gdpr-compliance"]
---

# ADR-007 — GDPR compliance design (consent + Art. 17 self-service + minori)

## Contesto

Phase 25 audit ha rilevato gap GDPR critici per produzione con minori:

1. **Art. 17 (oblio)** richiedeva email manuale a `info@pantedu.eu`, no self-service
2. **Art. 8 (minori)** nessuna validazione età né parent consent
3. **Art. 30 (registro trattamenti)** mancante (raccomandato per accountability anche sotto soglia 250)
4. **Art. 13 (informativa)** mancava disclosure IP/UA logging + retention dettaglio
5. **Art. 16 (rettifica)** + **Art. 20 (portabilità)**: no endpoint self-service

### NOTA storica — falso positivo Art. 9

Nella prima bozza di questa ADR (committed 411a7c2 il 2026-04-27) avevo indicato
BES/DSA come dato sanitario Art. 9. Verifica successiva sul codebase 2026-04-27
ha rivelato l'errore: BES/DSA in Pantedu è solo **metadata di contenuto del
docente** (checkbox HTML su esercizi + contatori `nPrintDSA`/`nPrintDIS` per
stampa), NON un identificativo dello studente. I dati sanitari individuali
(PEI/PDP, certificazioni mediche) restano nella scuola tramite registro
elettronico esterno + cartaceo, non passano per Pantedu.

→ **Trattamento Art. 9 NON applicabile** al sistema. R6 della DPIA (vedi
`docs/privacy/dpia.md`) marcato come N/A. C2 della roadmap riformulato (no
consent Art. 9 separato; signup form si limita a TOS + privacy policy
disclosure).

Punteggio compliance pre-Phase 25: **65/100**. Target post-Phase 25.C
(senza falso positivo Art. 9): **90/100**.

## Decisione

Implementare **GDPR self-service** completo + documentazione obbligatoria + integrazione con Phase 25.D crypto-shredding per Art. 17 efficiente.

### Architettura GDPR

```
                              ┌─────────────────────────────────┐
                              │  ConsentService (Phase 25.C3)   │
                              │  - grant idempotent             │
                              │  - revoke (UPDATE no DELETE)    │
                              │  - hasActive / listActive       │
                              │  - needsReconfirm               │
                              └──────┬──────────────────────────┘
                                     │
                                     ▼
                              ┌─────────────────────────────────┐
                              │  consents (DB)                  │
                              │  - 6 type ENUM                  │
                              │  - granted_at / revoked_at      │
                              │  - text_version (auto-versioning)│
                              │  - consent_audit (immutable)    │
                              └─────────────────────────────────┘

                              ┌─────────────────────────────────┐
                              │  DeletionRequestService (C4)    │
                              │  - request → token TTL 7g       │
                              │  - confirm → cooling_off 30g    │
                              │  - cancel (anytime)             │
                              │  - executeOverdue (cron)        │
                              └──────┬──────────────────────────┘
                                     │
                                     ▼
                              ┌─────────────────────────────────┐
                              │  TeacherCryptoService::shred()  │
                              │  (Phase 25.D6 ready)            │
                              │  → DELETE 1 row teacher_keys    │
                              │  → tutti body O(1) unreadable   │
                              │  → user anonymization in tx     │
                              └─────────────────────────────────┘

                              ┌─────────────────────────────────┐
                              │  parent_consents (C7)           │
                              │  - parent_email + token         │
                              │  - status workflow              │
                              │  - cascade delete account       │
                              └─────────────────────────────────┘
```

### Pattern self-service

Tutti gli endpoint `/me/*` richiedono auth + CSRF + rate-limit per-bucket. IP/UA hash SHA-256 per audit (no PII raw).

### Pattern consent revocation

`revoked_at` UPDATE invece di DELETE per **preserve history**:
- Audit DPO può ricostruire timeline consensi
- Re-grant futuro tracciabile come nuova row
- consent_audit log immutable per ogni grant/revoke event

### Pattern Art. 17 oblio

Workflow a 5 stati:
1. `pending_confirm`: token generato, attesa click email
2. `cooling_off`: confermato, esecuzione fra 30g (revocabile)
3. `executed`: crypto-shredding + anonimizzazione completati
4. `cancelled`: utente ha annullato
5. `expired`: token scaduto senza confirm

Cooling-off 30g previene errori utente + permette ripensamento. Crypto-shredding rende l'oblio O(1) (vs O(n) DELETE per ogni body row).

### Pattern minori Art. 8

Soglia consenso autonomo: **14 anni** (D.Lgs. 101/2018 italiano, vs 16 default GDPR).

- Età < 14 → richiede `parent_email` + double-opt-in via token
- Account NON attivo finché parent non conferma
- Parent può revocare consenso → cascade delete studente

## Conseguenze

### Positive

- **Compliance Art. 7, 9, 16, 17, 20**: piena conformità self-service.
- **Compliance Art. 30 §3**: registro trattamenti obbligatorio per Art. 9 documentato.
- **Compliance Art. 35**: DPIA completa (bozza firmabile dal Titolare).
- **Compliance Art. 8 minori**: doppio opt-in parent + soglia 14 anni Italia.
- **Trasparenza Art. 13**: informativa v2 con disclosure completo IP/UA + BES/DSA.
- **Crypto-shredding O(1)**: oblio efficiente, sopravvive a backup compromessi.
- **Revoke history preserved**: audit DPO completo, re-grant tracciabile.

### Negative

- **Email mailer**: necessario per token confirm (Phase futura — oggi token in response per dev/E2E).
- **Cron job `executePending Deletions`**: nuovo cron giornaliero per esecuzione cancellazioni overdue.
- **Cleanup parent_consents expired**: cron per cancellare token scaduti + studenti pending > 30g.
- **Re-consent flow**: text_version bump richiede riconferma a tutti gli utenti attivi al login (UX overhead).

### Neutrali

- **Backward compat**: utenti pre-Phase 25.C possono continuare senza consensi attivi finché non aggiorniamo `text_version`. Al primo login post-update, prompt re-consent.
- **Privacy by design**: `Permission::canView` già filtra docente proprietario; aggiunto solo gating consent layer separato.

## Implementazione

Phase 25.C sequence:

1. **C1 (DONE)** — Schema migration 015 + 016 (consents + parent_consents + deletion_requests + consent_audit + users.deleted_at)
2. **C3 (DONE)** — ConsentService + endpoint /me/consents
3. **C4 (DONE)** — DeletionRequestService + endpoint /me/request-deletion + crypto-shredding
4. **C5 (DONE)** — Endpoint /me/export-data Art. 20
5. **C6 (DONE)** — Endpoint /me/profile PATCH Art. 16
6. **C8 (DONE)** — Registro trattamenti `docs/privacy/registro-trattamenti.md`
7. **C9 (DONE)** — DPIA completa `docs/privacy/dpia.md` v1.0
8. **C10 (DONE)** — Informativa v2 `docs/privacy/informativa.md` con disclosure IP/UA + BES/DSA
9. **C2 (PENDING)** — UI signup BES/DSA Art. 9 consent
10. **C7 (PENDING)** — Validazione minori Art. 8 + parent_email
11. **C11 (PENDING)** — Cookie consent v2 sync backend
12. **C12 (PENDING)** — Data breach drill semestrale
13. **C13 (PENDING)** — DPO contact form `/dpo-contact`
14. **C14 (DONE)** — E2E `gdpr_self_service.spec.js` 8/8 pass

## Riferimenti

- ADR-006 (envelope encryption per Art. 32 + crypto-shredding Art. 17)
- DPIA: `docs/privacy/dpia.md`
- Registro: `docs/privacy/registro-trattamenti.md`
- Informativa: `docs/privacy/informativa.md` v2
- Compliance checklist: `docs/privacy/compliance_checklist.md`
- KMS recovery: `docs/security/operations/kms-recovery.md`
- GDPR full text — https://gdpr-info.eu/
- D.Lgs. 101/2018 (Italia): adeguamento normativa privacy nazionale
