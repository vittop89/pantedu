# ADR-028 — Governance istituto & capabilities per-docente

- **Stato:** ACCETTATO / COMPLETATO — Fase 1 (classi ammesse, trasversale) + Fase 2 (modello capabilities + policy) + Fase 3 (enforcement) + Fase 4 (admin UI: profili capability + assegnazione) tutte in produzione. Implementazione incrementale conclusa.
- **Data:** 2026-06-02
- **Correlati:** DeploymentMode single↔institute (ADR-017), sidebar DB-driven `sidebar_sections.visible_roles`/`allowed_content_types` (mig 070/072), ContentVisibilityPolicy + `publish_scope` (mig 069), pattern default-istituzionale+override-docente (ADR-025 risdoc), RegistrationController. Rilevante per la richiesta DPO (privacy/governance by design, art. 25/32).

## Contesto / richiesta utente

In **modo INSTITUTE** (più docenti/colleghi iscritti, scuola come Titolare) l'admin-istituto deve poter **governare le operazioni dei docenti-Autorizzati**. Quattro controlli:

1. **Visibilità sezioni sidebar** — il docente vede solo alcune sezioni.
2. **Creazione nuove sezioni** sidebar — capability concessa/negata.
3. **Creazione tipi di documento** (mappa, fork, link esterno, custom, da-fork) + **visibilità** dei documenti creati.
4. **Classi ammesse all'iscrizione** — allowlist di (indirizzo, classe) per la registrazione. **Trasversale**: vale anche in modo SINGLE, indipendente dall'attivazione INSTITUTE.

Oggi la sidebar filtra per **ruolo** (`visible_roles`) e per **tipi ammessi** per sezione (`allowed_content_types`), ma **non esiste un livello per-docente**. Non esiste un concetto di "classi ammesse".

## Decisione

### Modello capabilities — profili + override (riuso pattern ADR-025)

Una **capability** è una proprietà server-side che abilita/limita un'operazione. NON puro per-docente (carico admin insostenibile): **profili nominati con default + override per-docente**.

- `teacher_capability_profiles(id, name, capabilities JSON, is_default)` — profili (es. "Docente base", "Docente avanzato", "Collega esterno").
- `users.capability_profile_id` (nullable → profilo default).
- `teacher_capability_overrides(user_id, capabilities JSON)` — delta per-docente (Fase 4+).

**Schema `capabilities` (JSON, una sola blob per profilo/override):**
```json
{
  "sidebar": { "mode": "all|allow|deny", "sections": ["slug", ...] },
  "can_create_section": false,
  "doc_types": ["mappa","esercizio","verifica","document","fork","link","custom"],
  "max_visibility": "own_classes|classes|general"
}
```
Capability effettiva = `profilo default` merged con `profilo assegnato` merged con `override docente` (deep-merge, l'override vince).

### Enforcement — SERVER-SIDE, non solo UI (requisito di sicurezza/GDPR)

L'UI nasconde; i **gate veri** stanno nei controller. Un docente non deve poter bypassare via API diretta.

| Punto | Gate server-side |
|---|---|
| 1. Sezioni visibili | rendering sidebar filtra le sezioni con la capability `sidebar` (oltre a `visible_roles`) |
| 2. Crea sezione | `AdminSidebarConfigController`/endpoint creazione → richiede `can_create_section` |
| 3. Crea tipo doc + visibilità | `TeacherContentController::create*` → `doc_types` contiene il tipo; `publish/visibility` ≤ `max_visibility` |
| 4. Classi ammesse | `RegistrationController` → la coppia (indirizzo,classe) deve stare nell'allowlist istituto |

`TeacherCapabilityPolicy` (servizio) calcola la capability effettiva; i controller la interrogano. Fail-safe: capability assente ⇒ **negato** in INSTITUTE, **permesso** in SINGLE (retrocompat: tu solo).

### #4 Classi ammesse — trasversale

`institute_registration_policy` (o setting): `allowed_classes JSON` = lista `{indirizzo, classe}` (riusa i codici dinamici per-istituto). Vuoto/non-configurato ⇒ tutte ammesse (retrocompat). Check in registrazione studente/docente. Attivo **sempre** (anche SINGLE).

### Admin UI

- Tab **"Governance / Permessi istituto"** nella pagina `/admin/system/deployment`, **visibile solo se `DeploymentMode::isInstitute()`**: CRUD profili + assegnazione profilo per-docente + (Fase 4) override.
- **Classi ammesse** in una sezione "Registrazione" **sempre disponibile** (non gated da institute).

## Strategia di migrazione (incrementale, NON big-bang)

1. **Fase 1 — Classi ammesse** (isolata, valore immediato): migration + setting + check in `RegistrationController` + UI minima. Indipendente dal resto.
2. **Fase 2 — Modello capabilities**: migration profili/override + `TeacherCapabilityPolicy` + repository + seed profilo default ("tutto permesso", retrocompat).
3. **Fase 3 — Enforcement**: gate nei 3 controller (sidebar, section-create, content-create/visibility). Default permissivo in SINGLE.
4. **Fase 4 — Admin UI**: tab Governance (profili CRUD + assegnazione + override per-docente).

## Conseguenze

- **Privacy/governance by design (art. 25/32 GDPR)**: il Titolare (scuola) limita per-Autorizzato cosa vedere/creare + quali classi ammettere → misura tecnica/organizzativa concreta. Rinforza la storia di titolarità (scuola governa gli Autorizzati ex art. 29) e smussa il conflitto di ruoli del proponente. **Da citare nella richiesta DPO come capability progettata/disponibile.**
- Retrocompat: in SINGLE niente cambia (default permissivo); in INSTITUTE senza profili configurati = comportamento attuale.
- Rischio: complessità. Mitigato dalla fasatura + enforcement centralizzato in un solo servizio policy.

## Riferimenti
- DeploymentMode (`app/Support/DeploymentMode.php`), AdminSystemController.
- sidebar_sections (mig 070/072), ContentVisibilityPolicy, publish_scope (mig 069).
- RegistrationController, codici dinamici indirizzo/classe per istituto.
