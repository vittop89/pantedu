---
tags:
  - decisions
  - architecture
  - deployment
  - gdpr
date: 2026-05-22
status: accettato
deciders: Vittorio Pantaleo
---

# ADR-017 — Deployment mode switch: single-teacher (S1) vs multi-teacher istituto (S2)

## Stato

**ACCETTATO / IMPLEMENTATO** — `App\Support\DeploymentMode` (switch SINGLE↔INSTITUTE
con override runtime `storage/config/deployment.json` su `.env DEPLOYMENT_MODE`) e
`App\Policies\TeacherCapabilityPolicy` sono in produzione; vedi anche ADR-028
(governance & capabilities per-docente) che estende questo modo.
S3 (SaaS multi-tenant) **scartato** (overhead compliance non sostenibile come
singolo dev). S4 (OSS GitHub) in parallelo a S1+S2.

## Contesto

Audit compliance multi-scenario (2026-05-22) ha identificato 4 modi d'uso
realistici:

| Scenario | Descrizione                              | Compliance load | Decisione   |
| -------- | ---------------------------------------- | --------------- | ----------- |
| **S1**   | Solo docente Vittorio, uso personale     | Bassa           | Procedere   |
| **S2**   | Vittorio + colleghi stessa scuola        | Media           | Implementare dopo S1 |
| S3       | SaaS multi-istituto (paid)               | Altissima       | Scartato    |
| S4       | OSS GitHub (self-host community)         | Bassa (delegata)| In prep     |

Il codebase corrente è strutturato single-teacher (S1) con scaffolding multi-tenant
(institute_id, classe_keys, teacher_keys) pronto. Le differenze tra S1 e S2 sono
prevalentemente **operative/legali**, non architetturali:

- **DPO**: S1 non richiesto (singolo dev privato); S2 richiesto (scuola = controller)
- **Privacy notice / DPA**: S1 minimo; S2 contratti scuola↔Vittorio (Art. 28)
- **Risk profile**: S1 dati propri; S2 dati colleghi + minori sotto sua responsabilità
- **Authority cooperation**: S1 risponde lui; S2 con catena scuola→DPO→AG

Serve un meccanismo per **commutare** comportamento dell'app senza fork del codice.

## Decisione

Introdurre flag globale `DEPLOYMENT_MODE` in `.env`:

```ini
# .env
DEPLOYMENT_MODE=single   # single | institute
INSTITUTE_OWNER_EMAIL=   # solo se institute: email DPO scuola
INSTITUTE_LEGAL_NAME=    # solo se institute: ragione sociale scuola
```

Valori:

- `single` (default): scenario S1, **registration self-signup APERTA solo per
  studenti** (Vittorio = unico docente). Nuovi docenti vanno aggiunti dall'admin
  manualmente via `/admin/users/new`. DPO contact = `APP_MAIL_FROM`.
- `institute`: scenario S2, registration aperta a colleghi della scuola
  (role=teacher) E ai loro studenti (role=student), entrambi richiedono approve
  admin. DPO contact = `INSTITUTE_OWNER_EMAIL`, watermark legale
  "Gestito da [INSTITUTE_LEGAL_NAME]" su footer.

Switch implementato come `Config::get('app.deployment_mode')` letto da:

- `RegistrationController` → 404 se single, 200 form se institute
- `views/layout/sidebar.php` → nasconde tab "Utenti registrazione" se single
- `views/legal/privacy-notice.php` → carica template diverso (S1 vs S2)
- `AdminDpoController` → DPO contact info da config

Pannello UI in `/admin/system/deployment` (super_admin only):
- Visualizza modo corrente
- Wizard per switch single → institute (richiede compilare DPO/owner data)
- Switch reverse (institute → single) bloccato se ci sono > 1 user attivi
  (escape valve: CLI tool `tools/admin/downgrade_to_single.php --confirm`)

## Conseguenze

### Positive

- Singolo codebase serve entrambi gli scenari → no fork, no divergence
- Self-hosters S4 (GitHub) scelgono modo al deploy
- Migration path S1 → S2 senza re-installazione (Vittorio + scuola dopo onboarding)
- Audit/legal templates già modulari (ADR-007 conformità)

### Negative

- Aggiunge superficie test: ogni feature deve verificare entrambi i modi
- Rischio di "modo nascosto" con bug latenti se branch institute usato meno
- Documentazione raddoppia (privacy notices, DPA template, onboarding doc)

### Neutre

- Migration DB-side: nessuna. Le tabelle multi-tenant esistono già.
- Performance: trascurabile (1 lookup config in più per request).

## Implementazione (fasi)

1. **F1 (S1 baseline)** — quando S1 chiuso, aggiungere `DEPLOYMENT_MODE` config
   con default `single`. Test che il comportamento corrente continui invariato.
2. **F2 (gating)** — guardrail nei punti di ingresso (registration, sidebar,
   privacy notice) basati sul flag.
3. **F3 (UI switch)** — pannello `/admin/system/deployment` per switch + wizard.
4. **F4 (template multi)** — privacy notice S2, DPA template scuola, footer
   watermark istituto.
5. **F5 (test matrix)** — PHPUnit + Playwright run con `DEPLOYMENT_MODE=institute`
   in CI matrix.

## Alternative considerate

1. **Codebase fork (master_single vs master_institute)** — rifiutato: doppio
   mantenimento, drift, complicazione self-host.
2. **Feature flag granulare per ogni feature** — rifiutato: troppo ampio,
   diventa permutation hell. Solo 2 modi predefiniti, niente combinazioni custom.
3. **Build-time toggle (Vite env)** — rifiutato: il toggle è runtime perché
   un'installazione self-host può evolvere senza rebuild (es. solo singolo →
   piccola scuola pilot).

## Riferimenti

- [[ADR-007-gdpr-compliance]] — base compliance
- [[ADR-014-kms-strategy]] — KMS deferred decision
- `wiki/security-notes.md` § Compliance scenarios
- `docs/privacy/dpia.md` — DPIA da aggiornare per institute mode
