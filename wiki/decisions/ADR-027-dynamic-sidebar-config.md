# ADR-027 — Sidebar docente data-driven da DB + UI di configurazione

- **Stato:** ACCETTATO / PARZIALE — Step 1-3 + 7 in produzione; Step 4+ (estensioni) TODO.
- **Data:** 2026-05-28
- **Correlati:** ADR-028 (governance & capabilities: `sidebar_sections.visible_roles`/`allowed_content_types`), publish_scope + ContentVisibilityPolicy (mig 069), migration 070/072 (schema `sidebar_sections`)

## Contesto

Le voci della sidebar dell'area docente erano hardcoded nel markup/JS. In modo
INSTITUTE l'admin deve poter governare quali sezioni esistono, il loro ordine,
i ruoli che le vedono e i tipi di contenuto ammessi — senza modificare il codice.

## Decisione

I pulsanti sezione (`.fm-sb-sec`, `data-section-key`) sono **data-driven dal DB**:
la tabella `sidebar_sections` (migration 070/072) definisce `section_key`, label,
ordine, `visible_roles`, `allowed_content_types`, `group_mode`. Una UI admin
(`/admin/sidebar-config`) permette CRUD e riordino. Il rendering legge la config
a runtime; i `section_key` orfani (rimossi dalla config) vengono potati
automaticamente dai contenuti che li referenziano.

## Stato implementazione

- **Step 1-3** (schema DB, rendering data-driven, UI admin base) — in produzione.
- **Step 7** (potatura automatica `section_key` orfani) — in produzione.
- **Step 4+** (estensioni: ulteriori controlli/automazioni) — TODO.

L'enforcement per-docente delle sezioni visibili è formalizzato in **ADR-028**
(`TeacherCapabilityPolicy::filterSidebarSections`/`pruneSidebarSections`).

> Nota: ADR-027 colma il salto di numerazione tra ADR-026 e ADR-028. Memory di
> riferimento (slug): `project_sidebar_dynamic`.
