# docs/legal — Documenti contrattuali multi-tenancy

> Documenti legali pronti per **Scenario B/C** (multi-tenant per istituto)
> come da framework descritto in
> [`docs/todo/multitenancy_responsibility_framework.md`](../todo/multitenancy_responsibility_framework.md).
>
> **Status (2026-05-20)**: 🟢 Documenti pubblicati su `/legal/*`,
> infrastruttura di click-acceptance + takedown ATTIVA in produzione.
> Scenario A è quello operativo (Operatore docente singolo); ToS è già
> firmabile e la queue takedown è funzionante. La sottoscrizione formale
> del DPA con un Istituto avviene quando si estende a Scenario C.

## File presenti

| File | Tipo | URL pubblico | Stato attivazione |
|------|------|--------------|--------------------|
| [`tos_docente.md`](tos_docente.md) | Terms of Service docente | [`/legal/tos`](https://pantedu.eu/legal/tos) | 🟡 Gate pronto ma SPENTO — si accende dal pannello `/admin/system/deployment`, altrimenti ricade su `TOS_ENFORCE` (non impostata sul VPS → `false`) |
| [`aup.md`](aup.md) | Acceptable Use Policy | [`/legal/aup`](https://pantedu.eu/legal/aup) | 🟢 Linkata in registrazione + footer |
| [`takedown_procedure.md`](takedown_procedure.md) | Notice & Takedown procedure | [`/legal/takedown-procedure`](https://pantedu.eu/legal/takedown-procedure) | 🟢 Form attivo `/segnalazione-contenuti` + queue `/admin/takedown` |
| [`dpa_template.md`](dpa_template.md) | Data Processing Agreement template | [`/legal/dpa`](https://pantedu.eu/legal/dpa) | 🟡 Template — sottoscrizione formale solo in Scenario C |
| [`upload_limits_design.md`](upload_limits_design.md) | Specifica tecnica vincoli upload | (non pubblico) | 🔴 Specifica — implementazione differita a Phase 26 |
| [`ai-act-assessment.md`](ai-act-assessment.md) | Assessment Reg. (UE) 2024/1689 | [`/legal/ai-act`](https://pantedu.eu/legal/ai-act) | 🟢 Pubblicata + linkata nel footer legale |
| [`ai-literacy.md`](ai-literacy.md) | Alfabetizzazione IA (art. 4 AI Act) | [`/legal/ai-literacy`](https://pantedu.eu/legal/ai-literacy) | 🟢 Pubblicata + linkata nel footer legale |

## Indirizzi di contatto

Ogni indirizzo pubblicato su una pagina o in un documento legale deve
comparire qui. Regola: **si pubblicano solo caselle del dominio**, mai il
recapito personale o quello scolastico dell'operatore — il primo non
sopravvive a un cambio di provider, il secondo non sopravvive a un
trasferimento e mescola il ruolo di docente con quello di operatore
tecnico del servizio.

| Indirizzo | Ruolo | Dove compare | Riceve |
|-----------|-------|--------------|--------|
| `{{OPERATORE_EMAIL}}` | Titolare del trattamento | `informativa.md`, `registro-trattamenti.md`, `dpia.md` | Cloudflare Email Routing |
| `{{OPERATORE_EMAIL}}` | Contatto generale, accessibilità, chiarimenti sui documenti | `accessibility.md`, `ai-literacy.md`, footer legali, modale autore | Cloudflare Email Routing |
| `{{OPERATORE_EMAIL}}` | DPO, esercizio diritti GDPR, `rua` DMARC | `informativa.md`, `accessibility.md`, `breach_notification_template.md`, `DPO_EMAIL` | Cloudflare Email Routing |
| `{{OPERATORE_EMAIL}}` | Coordinated Vulnerability Disclosure | `public/.well-known/security.txt`, `SECURITY.md` | Cloudflare Email Routing |
| `{{OPERATORE_EMAIL}}` | Notice & Takedown (RFC 2142) | `aup.md`, `tos_docente.md`, `takedown_procedure.md`, form pubblico | Cloudflare Email Routing |
| `{{OPERATORE_EMAIL}}` | `APP_MAIL_FROM` — mittente transazionale | nessun documento (è send-only) | **no** — le risposte vanno via `Reply-To` |
| `superadmin@pec.it` | PEC per atti formali | **non pubblicata** — `takedown_procedure.md` §1.3 è ancora `TBD` | provider PEC |

> §1.3 della Notice & Takedown resta aperta di proposito: la PEC esistente è
> personale, non del servizio, e pubblicarla su una pagina indicizzata è una
> scelta che va fatta a mente fredda. Stessa cosa per l'indirizzo postale, che
> oggi rimanda alla sede dell'Istituto.

Sending e receiving sono due percorsi distinti e vanno verificati separatamente:

- **Invio**: Resend, autenticato a livello di dominio (SPF su
  `send.pantedu.eu`, DKIM su `resend._domainkey.pantedu.eu`). Vale per
  qualsiasi mittente `@pantedu.eu`, quindi un indirizzo che *invia* non
  dimostra nulla su un indirizzo che *riceve*.
- **Ricezione**: MX su Cloudflare Email Routing. Ogni casella dell'elenco
  ha bisogno della **sua** regola di instradamento: senza, la mail rimbalza
  e il canale pubblicato è morto.

## Componenti codice/DB correlate

| Risorsa | Stato | Note |
|---------|-------|------|
| [`database/migrations/056_tos_aup_acceptance.sql`](../../database/migrations/056_tos_aup_acceptance.sql) | 🟢 Applicata | Tabella `user_tos_acceptance` |
| [`database/migrations/057_takedown_requests.sql`](../../database/migrations/057_takedown_requests.sql) | 🟢 Applicata | Tabella `takedown_requests` |
| [`database/migrations/058_teacher_content_source_type.sql`](../../database/migrations/058_teacher_content_source_type.sql) | 🟢 Applicata | `source_type` per copyright share-block (art. 70-bis) |
| [`app/Services/Gdpr/TosAcceptanceService.php`](../../app/Services/Gdpr/TosAcceptanceService.php) | 🟢 In router | `/tos-acceptance` GET/POST |
| [`app/Middleware/TosAcceptanceMiddleware.php`](../../app/Middleware/TosAcceptanceMiddleware.php) | 🟡 Applicato globalmente da `Kernel::handle()`, inerte finché `TosEnforcement::isEnabled()` è false | Redirect a `/tos-acceptance` se utente non ha accettato |
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

3. [x] **Configurare email abuse@**:
   - Regola Cloudflare Email Routing su `{{OPERATORE_EMAIL}}` → casella
     personale dell'operatore (la destinazione non si pubblica)
   - Test ricezione

4. [x] **Integrare ToS check nel router**:
   - `TosAcceptanceMiddleware` applicato **globalmente** da `Kernel::handle()`,
     non rotta per rotta: l'elenco delle esenzioni è più corto e più
     verificabile di quello delle rotte protette
   - `hasAccepted()` confronta su `effective_from`, mai sulla stringa di
     versione ('1.10' < '1.9' in ordine lessicografico)
   - Redirect a `/tos-acceptance` se NON accettato (form in `views/legal/`)
   - Resta **inerte** finché `TosEnforcement::isEnabled()` è false

5. [ ] **Integrare form pubblico takedown**:
   - Aggiungere route `GET/POST /segnalazione-contenuti` in Router/Kernel
   - Linka in footer pubblico + sito istituzionale

6. [x] **Admin UI takedown queue**:
   - View admin per `TakedownRequestService::listPending()`
   - Pulsanti azione (rimuovi / sospendi / dismissi)
   - Notifica automatica all'uploader (Fase 4) —
     `AdminTakedownController::notifyUploader()`, template §5.2 via Resend

7. [ ] **DPA**:
   - Personalizzare `dpa_template.md` con dati reali Istituto
   - Sottoscrizione formale: Operatore + Dirigente [Dirigente Scolastico]
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
    -V monofont="Consolas" \
    -V lang=it \
    -o tos_docente.pdf

# Firma PAdES con DSGA per autorità
# Cartella firmati gitignored: docs/legal/firmati/
```

> ⚠️ **Non usare il comando qui sopra a mano: usa lo script.**
>
> ```bash
> ./tools/legal/build_pdf.sh --all          # tutti i documenti
> ./tools/legal/build_pdf.sh docs/privacy/dpia.md
> ```
>
> Il comando pandoc "nudo" ha due trappole, e nessuna delle due dà errore —
> il PDF viene prodotto lo stesso, sbagliato:
>
> 1. **Caratteri di disegno** (`─ │ ├ └ ▼`) dei diagrammi ASCII: il font
>    monospace predefinito non li ha, e senza `-V monofont="Consolas"` xelatex
>    li scarta. Il diagramma dell'architettura nella DPIA esce a pezzi — e la
>    DPIA è proprio il documento che si consegna al DPO.
> 2. **Emoji** (`✅ ⚠️ ❌ ⬜ 🚨`): non esistono in Calibri e xelatex compone al
>    loro posto un **rettangolo segnaposto**, che in un documento formale
>    sembra un difetto di produzione.
>
> Lo script risolve la prima col font giusto e la seconda rimuovendo le emoji
> da una **copia temporanea** prima della conversione: il markdown resta
> intatto e sul web continua a mostrarle. È sicuro perché sono decorative —
> "**BASSO** ✅" resta "BASSO", e gli `❌` stanno sotto un titolo che dice già
> "Trattamenti esplicitamente esclusi".
>
> Lo script verifica da sé il risultato e **esce con codice diverso da zero se
> resta anche un solo glifo mancante**.
>
> I PDF del pacchetto DPO seguono un'altra pipeline (Edge headless con CSS
> dedicato, `docs/dpo/pacchetto-scuola/_gen_pdf.py`): lì le emoji si vedono.

## Riferimenti normativi quick ref

- **AI Act** (Reg. UE 2024/1689): artt. 2, 3, 4, 5, 6, 25, 50, 99 + Allegato III p.3 — vedi [`ai-act-assessment.md`](ai-act-assessment.md)
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
| 2026-05-20 | Phase 25.P attivata | abuse@ alias configurato, migrations 056+057+058 applicate, copyright share-block attivo |
| 2026-05-20 | Phase 25.Q — coerenza UI/legal | Route pubbliche `/legal/*` attive, link in footer + cookie modal + form registrazione, dashboard admin con tile takedown/tos-log |
| 2026-08-26 | Conformità AI Act | Assessment + scheda alfabetizzazione creati e **pubblicati** su `/legal/ai-act` e `/legal/ai-literacy` (footer legale aggiornato); marcatura art. 50(2) implementata nel codice; clausola art. 25(1)(c) in ToS; sub-responsabili LLM nel DPA § 7.1-bis |
| 2026-08-26 | Riduzione superficie IA | Copilot **rimosso** (irraggiungibile, etichetta errata, chiave API dal browser). Fornitori LLM ridotti ad Anthropic + OpenAI + Ollama: Qwen e OpenRouter rimossi perché ciascun fornitore è un DPA da verificare e una base di trasferimento da reggere. Default provider = `ollama` (nessun trasferimento) |
| 2026-09-01 | Notifica uploader automatica | Fase 4 della Notice & Takedown parte dalla POST admin (`AdminTakedownController::notifyUploader()`, template §5.2 via Resend). `notified_uploader` marcato solo a invio riuscito: il flag a 0 resta la prova che il passo manuale è dovuto. Chiuso il TODO residuo aperto dal 2026-05-20 |
| 2026-09-01 | Indirizzi di contatto pubblici normalizzati | Via da pagine e documenti pubblici il Gmail personale e l'indirizzo `@scuola-esempio.edu.it` (vedi tabella "Indirizzi di contatto"). Il secondo confondeva il ruolo di docente con quello di operatore e sarebbe morto al primo trasferimento |
| 2026-09-01 | Rettifica: il gate ToS non è mai stato attivo | Il registro dichiarava `TOS_ENFORCE=true` sul VPS dal 2026-05-20, ma la variabile non è mai stata impostata: il gate è sempre rimasto spento e `user_tos_acceptance` è vuota con 4 utenti attivi. La fonte ora è `TosEnforcement::isEnabled()`, che legge l'override runtime scritto dal pannello admin (`storage/config/tos_enforcement.json`) e ricade su `TOS_ENFORCE` |
| _futuro_ | Implementazione UploadService | Spec già scritta in `upload_limits_design.md` — Phase 26 |
| _futuro_ | Personalizzazione DPA + firma | Pre-attivazione concreta Scenario C (adozione istituzionale) |
