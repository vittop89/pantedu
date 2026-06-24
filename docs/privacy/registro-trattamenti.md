---
tags:
    - documentazione/gdpr
    - phase/25.C8
date: 2026-04-27
tipo: registro-trattamenti
status: vigente
versione: 1.0
classification: ⚠️ INTERNAL — esibibile al Garante su richiesta
aliases: ["registro", "ROPA", "art-30"]
---

# Registro delle attività di trattamento — Art. 30 GDPR

> **Redatto come buona pratica** ex Art. 5 §2 (accountability) e perché
> trattiamo dati di minori (Art. 8). L'esenzione Art. 30 §5 (< 250 dipendenti)
> potrebbe applicarsi formalmente, ma il registro è raccomandato per
> accountability + incident response + esibizione Garante on-demand entro 72h.
>
> ## NOTA su BES/DSA — NON dato sanitario Art. 9 in Pantedu
>
> Verificato sul codebase 2026-04-27: Pantedu NON traccia "studente X
> ha DSA". Il flag DSA su esercizi è metadata di contenuto del docente
> (variante adattata dell'esercizio), non identificativo dello studente.
> I contatori `nPrintDSA`/`nPrintDIS` su `infoVer` sono numeri aggregati
> per stampa, non PII. Trattamento Art. 9 NON applicabile a Pantedu.

## Sezione A — Identificazione del Titolare

| Campo | Valore |
|-------|--------|
| Denominazione | {{OPERATORE_NOME}} (persona fisica, professionista) |
| Sede | Italia |
| Email contatto | {{OPERATORE_EMAIL}} |
| Email DPO | {{OPERATORE_EMAIL}} (auto-nomina, dimensione attuale non richiede DPO formale ex Art. 37) |
| Telefono | _da inserire_ |
| Tipo organizzazione | Singolo professionista — piattaforma educativa per scuole superiori |

## Sezione B — Trattamenti effettuati

### B.1 — Registrazione e gestione utenze

| Campo | Valore |
|-------|--------|
| **Finalità** | Autenticazione, autorizzazione, gestione ruoli (docente / studente / admin) |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto registrazione (TOS) |
| **Categorie interessati** | Docenti, studenti (anche minori), admin |
| **Categorie dati** | username, nome, cognome, email, password (hashed bcrypt), role, institute_id |
| **Destinatari** | Solo Titolare + sub-processor (hosting Hetzner, DE) |
| **Trasferimenti extra-UE** | NO (Hetzner data center Germania, UE) |
| **Tempi conservazione** | 730g inattività → anonimizzazione (`app/Config/retention.php`) |
| **Misure sicurezza** | bcrypt cost 12 + HTTPS + CSRF + rate-limit 10/min/IP |

### B.2 — Metadata varianti DSA/DIS su esercizi (NON Art. 9)

| Campo | Valore |
|-------|--------|
| **Finalità** | Permettere al docente di gestire varianti adattate dello stesso esercizio (es. formula esplicita per copia DSA, font dyslexia-friendly per copia DIS). Conta numeri di copie da stampare per ogni variante. |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto, parte della funzione core piattaforma |
| **Categorie interessati** | Docenti (autori dell'esercizio adattato) |
| **Categorie dati** | Checkbox HTML `dsa-checkbox` su `<li>` di esercizi (metadata contenuto), contatori `nPrintDSA`/`nPrintDIS` su `infoVer` (numeri aggregati copie stampa), marker inline `(*F*)` / `(*GF*)` |
| **NON trattati** | Identificativi studente (l'app non sa "Mario Rossi è DSA"), certificazioni mediche, PEI/PDP. Questi dati sono gestiti dalla scuola via registro elettronico esterno. |
| **Destinatari** | Solo docente proprietario |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Vita ciclo account docente, cifrato at-rest envelope encryption (insieme al body esercizio) |
| **Misure sicurezza** | AES-256-GCM body cifrato + per-teacher KEK + crypto-shredding Art. 17 (Phase 25.D) |

### B.3 — Contenuti didattici docenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Creazione, archiviazione, condivisione esercizi/verifiche/mappe/risdoc per attività didattica |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto + diritto d'autore docente |
| **Categorie interessati** | Docenti |
| **Categorie dati** | body_html, body_pt (Portable Text), metadata, file allegati |
| **Destinatari** | Docente proprietario, studenti classe (per published_content) |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Vita ciclo account, soft-delete + crypto-shredding al Art. 17 |
| **Misure sicurezza** | Envelope encryption (Phase 25.D), per-teacher KEK derivata da KMS_MASTER |

### B.4 — Pubblicazione contenuti agli studenti

| Campo | Valore |
|-------|--------|
| **Finalità** | Distribuzione esercizi/verifiche assegnati alla classe per studio |
| **Base giuridica** | Art. 6(1)(b) — esecuzione contratto didattico + Art. 6(1)(f) — interesse legittimo della scuola |
| **Categorie interessati** | Studenti della classe target, docenti pubblicatori |
| **Categorie dati** | Copia cifrata del body docente in `published_content` |
| **Destinatari** | Studenti della classe (decifrabile via cookie auth scoped) |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Anno scolastico + 30g (poi `archived_at`); rotation classe_key annuale |
| **Misure sicurezza** | classe_keys decoupled da teacher KEK (Phase 25.D6) — sopravvive ad Art. 17 docente |

### B.5 — Audit log accessi privilegiati

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento abusi privilegi admin + accountability Art. 30 §1 |
| **Base giuridica** | Art. 6(1)(c) — obbligo legale + Art. 6(1)(f) — interesse legittimo sicurezza |
| **Categorie interessati** | Super-admin tecnici |
| **Categorie dati** | username, action, resourceType, resourceId, **reason** (obbligatoria), timestamp, ip_hash, ua_hash |
| **Destinatari** | Solo Titolare + DPO |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | 1825g (5 anni — termine prescrizione abusi amministrativi) |
| **Misure sicurezza** | append-only (`REVOKE UPDATE,DELETE` su DB user — TODO operational), hash IP/UA, RequiresAuditReason middleware blocca azioni senza reason |

### B.6 — Logging IP / User-Agent (rilevamento anomalie)

| Campo | Valore |
|-------|--------|
| **Finalità** | Rilevamento brute-force, account takeover, anomalie comportamentali |
| **Base giuridica** | Art. 6(1)(f) — interesse legittimo (sicurezza) |
| **Categorie interessati** | Tutti gli utenti autenticati (e tentativi falliti) |
| **Categorie dati** | IP (hash SHA-256 primi 2 octet), User-Agent (hash SHA-256 full), timestamp, action |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | 365g (`app/Config/retention.php`) |
| **Misure sicurezza** | Hash unidirezionale (no IP/UA in chiaro), rotation log throttled |

### B.7 — Cookie analytics (opt-in)

| Campo | Valore |
|-------|--------|
| **Finalità** | Misurazione audience aggregata (no profiling individuale) |
| **Base giuridica** | Art. 6(1)(a) — consenso (cookie consent v2 Phase 25.C11) |
| **Categorie interessati** | Visitatori che accettano analytics |
| **Categorie dati** | Eventi navigazione anonimi |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO (servizio interno) |
| **Tempi conservazione** | Aggregati 90g, no row-level tracking |
| **Misure sicurezza** | Opt-in esplicito, revoca via cookie banner ogni momento |

### B.8 — Consenso parentale per minori (Art. 8)

| Campo | Valore |
|-------|--------|
| **Finalità** | Conformità Art. 8 GDPR + D.Lgs. 101/2018 (Italia: < 14 richiede consenso parentale) |
| **Base giuridica** | Art. 8 §1 — consenso parentale via doppio opt-in email |
| **Categorie interessati** | Studenti < 14 anni + genitori |
| **Categorie dati** | parent_email, parent_name (opzionale), token, timestamp confirm, IP/UA hash |
| **Destinatari** | Solo Titolare |
| **Trasferimenti extra-UE** | NO |
| **Tempi conservazione** | Vita ciclo account studente; revoca → cancellazione studente (cascade) |
| **Misure sicurezza** | Token random 64-hex single-use TTL 30g, expire automatico |

## Sezione C — Categorie particolari Art. 9

**Nessun trattamento Art. 9 attivo in Pantedu.** Vedi NOTA in cima al documento. Verificato 2026-04-27 sul codebase: app processa solo metadata di contenuto del docente + contatori numerici copie, mai dati sanitari individuali studente.

Se in futuro si introducesse tracking studente-DSA (es. profilo studente con flag DSA personale), questo trattamento andrebbe aggiunto qui con base giuridica Art. 9(2)(a) consenso esplicito separato + DPIA aggiornata.

## Sezione D — Sub-processors

| Sub-processor | Servizio | Localizzazione | DPA |
|---------------|----------|----------------|-----|
| Hetzner Online GmbH | Hosting applicativo (server, DB, storage) | Germania (UE) | ✅ DPA Art. 28 GDPR di Hetzner (Data Processing Agreement standard, accettato all'attivazione del servizio). EU only. |
| Cloudflare, Inc. | CDN / edge security (terminazione TLS, WAF di bordo) | Rete globale; per il traffico IT instradamento UE | ✅ DPA Cloudflare + SCC per eventuali trasferimenti (Capo V GDPR). |
| Backblaze Inc. (B2) | Backup off-site (dati **cifrati lato client** prima dell'upload) | UE (regione europea) | ✅ DPA Backblaze; i dati sono cifrati prima dell'invio → Backblaze non accede al contenuto. |
| Google LLC (eventuale, opzionale) | OAuth login + Drive integration — solo su **opt-in** esplicito del docente | USA — SCC necessarie | NA finché non attivato dal docente |

> **Non sub-responsabili del trattamento**: **Porkbun LLC** (registrar del dominio / gestione DNS) e l'eventuale provider della **PEC personale** del titolare non trattano dati personali degli interessati del servizio → non sono sub-responsabili ex Art. 28.

### Hetzner — dettaglio DPA

- **Base giuridica**: Data Processing Agreement Art. 28 GDPR di Hetzner Online GmbH, accettato all'attivazione del servizio.
- **Localizzazione**: data center in Germania (UE); nessun trasferimento extra-UE per l'hosting.
- **Cancellazione fine contratto**: cancellazione/restituzione dati a scelta del Cliente.
- **Breach notification**: secondo le tempistiche normative (→ Art. 33 §2 GDPR).

## Sezione E — Eventi storici

### Aggiornamenti del registro

| Data | Versione | Modifica | Operatore |
|------|----------|----------|-----------|
| 2026-04-27 | 1.0 | Prima compilazione (Phase 25.C8) | {{OPERATORE_NOME}} |
| 2026-04-29 | 1.1 | Sezione D: DPA sub-processor hosting archiviato + mappato vs Art. 28 §3 | {{OPERATORE_NOME}} |
| 2026-06-24 | 1.2 | Migrazione infrastruttura: hosting → **Hetzner** (DE, UE), backup → **Backblaze B2**, edge → **Cloudflare**; registrar → **Porkbun** (non sub-responsabile). Rimosso hosting legacy come hosting (resta solo eventuale PEC personale del titolare, fuori dal trattamento). | {{OPERATORE_NOME}} |

### Data breach notificati

| Data | Tipo | Esposto | Notifica Garante (72h) | Notifica utenti | Risoluzione |
|------|------|---------|-----------------------|-----------------|-------------|
| _Nessuno_ | — | — | — | — | — |

## Sezione F — Manutenzione

- **Revisione obbligatoria**: annuale (gennaio di ogni anno)
- **Trigger update straordinario**: nuova feature Art. 9, nuovo sub-processor, modifica architettura crypto, data breach
- **Esibizione**: il registro va prodotto al Garante entro 72h da richiesta (Art. 30 §4)
- **Custodia**: copia firmata Titolare in `docs/privacy/registro-trattamenti.md` (versionato git private repo) + copia stampata in cassaforte

## Riferimenti

- DPIA: `docs/privacy/dpia.md`
- Informativa: `docs/privacy/informativa.md` v2 (Phase 25.C10 PENDING)
- Retention policy: `app/Config/retention.php`
- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- ADR-006 (encryption): `wiki/decisions/ADR-006-envelope-encryption.md`
- ADR-007 (GDPR compliance): `wiki/decisions/ADR-007-gdpr-compliance.md` (Phase 25.C15)
- Compliance checklist: `docs/privacy/compliance_checklist.md`
