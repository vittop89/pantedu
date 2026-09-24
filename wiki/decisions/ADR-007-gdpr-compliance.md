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

1. **Art. 17 (oblio)** richiedeva email manuale a `{{OPERATORE_EMAIL}}`, no self-service
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

> **Aggiornamento 2026-09-24.** Uno SHA-256 senza chiave di un IPv4 si
> rovescia provando tutti gli indirizzi: dall'impronta si risale all'IP. Dal
> 2026-09-24 l'IP si conserva come HMAC con chiave (`App\Support\ImprontaIp`),
> lo User-Agent resta SHA-256; tutte e due sono dati pseudonimi, non anonimi.
> La regola sta in [[security-notes]].

### Pattern consent revocation

`revoked_at` UPDATE invece di DELETE per **preserve history**:
- Audit DPO può ricostruire timeline consensi
- Re-grant futuro tracciabile come nuova row
- consent_audit log immutable per ogni grant/revoke event

### Pattern Art. 17 oblio

Workflow a 5 stati:
1. `pending_confirm`: token generato, attesa della conferma (il pulsante della pagina che apre il collegamento dell'email; aprirlo da solo non conferma, dal 24/9/2026). Una richiesta nuova sostituisce quella di prima in attesa solo dopo che la sua email è partita; con il collegamento scaduto non è più «in corso» (`activeRequest`), anche prima di essere segnata `expired`
2. `cooling_off`: confermato, esecuzione fra 30g (revocabile finché l'esecuzione non è cominciata). Finché c'è, una richiesta nuova non si crea: la risposta dice la data di esecuzione e come annullarla (dal 24/9/2026; prima la richiesta nuova la ritirava)
3. `executed`: cancellazione dell'account completata (dal 2026-09-24 con `CancellazioneDellAccount`, la stessa routine dell'anonimizzazione a 730 giorni: contenuti in chiaro e file cancellati, `users` ridotta a segnaposto, chiave distrutta per ultima)
4. `cancelled`: utente ha annullato, o richiesta sostituita da una nuova, o ritirata perché la sua email non è partita
5. `expired`: token scaduto senza confirm (lo segna `confirm()` quando qualcuno prova il gettone)

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
- **Crypto-shredding O(1)**: oblio efficiente nel database in servizio. **Non** vale per le copie di sicurezza fatte prima della cancellazione, che contengono la chiave del docente e la master fino alla loro scadenza (corretto il 2026-09-24: qui c'era scritto «sopravvive a backup compromessi»; vedi `docs/security/operations/restore-reerasure.md`).
- **Revoke history preserved**: audit DPO completo, re-grant tracciabile.

### Negative

- **Email mailer**: necessario per token confirm. Dal 24/9/2026 l'email parte davvero (`RichiestaDiCancellazione`; se non parte, si ritira la sola richiesta nuova e la risposta rimanda al DPO); il gettone nella risposta resta solo fuori dalla produzione, per la suite end-to-end. Fino a quel giorno la risposta diceva «Email di conferma inviata» e non partiva niente: [[changelog/2026-09-sett4]].
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
