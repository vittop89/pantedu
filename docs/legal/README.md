# docs/legal — Documenti contrattuali multi-tenancy

> Documenti legali pronti per **Scenario B/C** (multi-tenant per istituto)
> come da framework descritto in
> [`docs/todo/multitenancy_responsibility_framework.md`](../todo/multitenancy_responsibility_framework.md).
>
> **Status (2026-05-20)**: 🟢 Documenti pubblicati su `/legal/*`,
> infrastruttura di click-acceptance + takedown ATTIVA in produzione.
> Scenario A è quello operativo (Vittorio docente singolo); ToS è già
> firmabile e la queue takedown è funzionante. La sottoscrizione formale
> del DPA con un Istituto avviene quando si estende a Scenario C.

## File presenti

| File | Tipo | URL pubblico | Stato attivazione |
|------|------|--------------|--------------------|
| [`tos_docente.md`](tos_docente.md) | Terms of Service docente | [`/legal/tos`](https://beta.pantedu.eu/legal/tos) | 🟢 Click-accept attivo (`TOS_ENFORCE=true`) |
| [`aup.md`](aup.md) | Acceptable Use Policy | [`/legal/aup`](https://beta.pantedu.eu/legal/aup) | 🟢 Linkata in registrazione + footer |
| [`takedown_procedure.md`](takedown_procedure.md) | Notice & Takedown procedure | [`/legal/takedown-procedure`](https://beta.pantedu.eu/legal/takedown-procedure) | 🟢 Form attivo `/segnalazione-contenuti` + queue `/admin/takedown` |
| [`dpa_template.md`](dpa_template.md) | Data Processing Agreement template | [`/legal/dpa`](https://beta.pantedu.eu/legal/dpa) | 🟡 Template — sottoscrizione formale solo in Scenario C |
| [`upload_limits_design.md`](upload_limits_design.md) | Specifica tecnica vincoli upload | (non pubblico) | 🔴 Specifica — implementazione differita a Phase 26 |

## Componenti codice/DB correlate

| Risorsa | Stato | Note |
|---------|-------|------|
| [`database/migrations/056_tos_aup_acceptance.sql`](../../database/migrations/056_tos_aup_acceptance.sql) | 🟢 Applicata | Tabella `user_tos_acceptance` |
| [`database/migrations/057_takedown_requests.sql`](../../database/migrations/057_takedown_requests.sql) | 🟢 Applicata | Tabella `takedown_requests` |
| [`database/migrations/058_teacher_content_source_type.sql`](../../database/migrations/058_teacher_content_source_type.sql) | 🟢 Applicata | `source_type` per copyright share-block (art. 70-bis) |
| [`app/Services/Gdpr/TosAcceptanceService.php`](../../app/Services/Gdpr/TosAcceptanceService.php) | 🟢 In router | `/tos-acceptance` GET/POST |
| [`app/Middleware/TosAcceptanceMiddleware.php`](../../app/Middleware/TosAcceptanceMiddleware.php) | 🟢 Attivo se `TOS_ENFORCE=true` | Redirect a `/tos-acceptance` se utente non ha accettato |
| [`app/Services/Gdpr/TakedownRequestService.php`](../../app/Services/Gdpr/TakedownRequestService.php) | 🟢 In router | CRUD + report annuale |
| [`app/Controllers/Public/PublicTakedownController.php`](../../app/Controllers/Public/PublicTakedownController.php) | 🟢 In router | `/segnalazione-contenuti` GET/POST, rate-limited 3/h |
| [`app/Controllers/Admin/AdminTakedownController.php`](../../app/Controllers/Admin/AdminTakedownController.php) | 🟢 In router | `/admin/takedown` (super-admin only) |
| [`app/Services/Sharing/SharedContentPolicy.php`](../../app/Services/Sharing/SharedContentPolicy.php) | 🟢 Attivo | Block share di `book_textbook`/`mixed` (art. 70-bis L. 633/1941) |
| `app/Services/Files/UploadService.php` | 🔴 Non implementato | Spec in `upload_limits_design.md` — Phase 26 |
| `database/migrations/059_upload_infrastructure.sql` | 🔴 Non scritta | Pre-requisito UploadService |

## Workflow di attivazione (checklist Scenario B)

Quando decidi di estendere pantedu ad altri docenti:

1. [ ] **Personalizzare i template legali**:
   - Sostituire placeholder (es. CF, indirizzi) nei file `.md`
   - Generare PDF firmati per archiviazione (vedi sezione "Generazione PDF")

2. [ ] **Eseguire migrations DB**:
   ```bash
   php tools/migrate.php
   # Esegue 056 + 057 idempotenti
   ```

3. [ ] **Configurare email abuse@**:
   - Setup alias DNS + forward a privacy@pantedu.eu
   - Test ricezione

4. [ ] **Integrare ToS check nel router**:
   - Add middleware `TosAcceptanceMiddleware` (da scrivere)
   - Check `hasAccepted($userId)` su ogni route autenticata non-pubblica
   - Redirect a `/tos-acceptance` se NON accettato
   - Form view per click-acceptance + POST handler

5. [ ] **Integrare form pubblico takedown**:
   - Aggiungere route `GET/POST /segnalazione-contenuti` in Router/Kernel
   - Linka in footer pubblico + sito istituzionale

6. [ ] **Admin UI takedown queue**:
   - View admin per `TakedownRequestService::listPending()`
   - Pulsanti azione (rimuovi / sospendi / dismissi)
   - Notification automatica all'uploader

7. [ ] **DPA**:
   - Personalizzare `dpa_template.md` con dati reali Istituto
   - Sottoscrizione formale: Vittorio + Dirigente [Dirigente Scolastico]
   - Archiviazione copia in cartella `docs/legal/firmati/` (gitignored)

8. [ ] **Comunicazione ai docenti partecipanti**:
   - Riunione info su uso + obblighi
   - Distribuzione PDF di ToS + AUP
   - Onboarding tecnico

9. [ ] **Test end-to-end**:
   - Nuovo docente fa primo login → vede ToS → accetta → entra
   - Test form takedown pubblico → email arriva → admin vede in queue
   - Test caso violazione → workflow takedown completo

## Workflow di attivazione (checklist Scenario C aggiuntivo)

Oltre allo Scenario B:

10. [ ] **DPIA (Data Protection Impact Assessment)**:
    - Coordinata con DPO Avv. [Consulente DPO]
    - Template Garante Privacy: <https://www.garanteprivacy.it/temi/valutazione-impatto>

11. [ ] **Delibera Consiglio di Istituto**:
    - Punto all'odg con presentazione executive summary
    - Verbale archiviato

12. [ ] **Tier 4 hardening** (se richiesto da DPIA):
    - Wazuh HIDS (richiede VPS dedicato)
    - Vault per secrets centralizzati
    - Vedi [`docs/todo/tier4_security_future_roadmap.md`](../todo/tier4_security_future_roadmap.md)

13. [ ] **Penetration test esterno**:
    - Annuale
    - Documentazione archiviata

14. [ ] **Cyber insurance** (opzionale ma raccomandata):
    - Polizza professionale Italia per attività docente + dev open-source

## Generazione PDF firmati (per archivio e consegna)

I documenti `.md` in questa cartella possono essere convertiti in PDF
firmati per archiviazione e consegna formale:

```bash
# Setup pandoc + xelatex
# (già disponibile in /c/security_tools/pdf/pandoc/ su VPS-dev)

# Conversione singolo file
pandoc tos_docente.md \
    --pdf-engine=xelatex \
    -V documentclass=article \
    -V geometry:margin=2cm \
    -V fontsize=10pt \
    -V mainfont="Calibri" \
    -V lang=it \
    -o tos_docente.pdf

# Firma PAdES con DSGA per autorità
# Cartella firmati gitignored: docs/legal/firmati/
```

## Riferimenti normativi quick ref

- **GDPR** (Reg. UE 2016/679): artt. 5, 6, 9, 13, 24, 28, 29, 32, 33
- **D.Lgs. 196/2003** mod. D.Lgs. 101/2018 (Codice Privacy IT)
- **D.Lgs. 70/2003** art. 16 (Direttiva 2000/31/CE — safe harbor)
- **L. 633/1941** (Diritto d'autore italiano)
- **DPR 62/2013** Codice di Comportamento dipendenti PA
- **D.Lgs. 165/2001** art. 53 (incompatibilità cumulo impieghi)
- **D.Lgs. 82/2005 CAD** art. 68-69 (riuso software PA — se open-source)
- **D.Lgs. 36/2023** Codice Appalti (auto-fornitura gratuita esclusa)

## Decision log

| Data | Decisione | Note |
|------|-----------|------|
| 2026-05-20 | Drafts iniziali creati | Pre-Scenario B/C |
| 2026-05-20 | Phase 25.P attivata | `TOS_ENFORCE=true` su VPS, abuse@ alias configurato, migrations 056+057+058 applicate, copyright share-block attivo |
| 2026-05-20 | Phase 25.Q — coerenza UI/legal | Route pubbliche `/legal/*` attive, link in footer + cookie modal + form registrazione, dashboard admin con tile takedown/tos-log |
| _futuro_ | Implementazione UploadService | Spec già scritta in `upload_limits_design.md` — Phase 26 |
| _futuro_ | Personalizzazione DPA + firma | Pre-attivazione concreta Scenario C (adozione istituzionale) |
