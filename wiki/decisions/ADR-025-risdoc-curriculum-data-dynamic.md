# ADR-025 — Dati curriculari risdoc (obiettivi/competenze/…) dinamici + istituzionali

- **Stato:** Accettato (A completo, B core completo; admin UI da fare)
- **Data:** 2026-05-25
- **Correlati:** ADR-024 (pt-document/topbar), migration 036 (indirizzo FK canonici)

## Contesto

I modelli risdoc (`/risdoc/view/{id}`) popolano i dropdown via `options_source`
(obiettivi disciplinari LG2010/dipartimento, competenze, abilità, conoscenze,
programmi, minimi) leggendo **file statici**:
`storage/templates/risdoc/{dataset}/{IIS}/{mat}/{IIS}_{cls}_{mat}.json`.

Due problemi:
1. **Codici legacy + mappe hardcoded.** I file usavano codici indirizzo `LSc`/
   `LArAR`/`LLi`; il client (`_options-fetcher`, `fm-risdoc-checkbox-group`,
   `fm-risdoc-dynamic-table`) aveva **3 copie** di `IIS_MAP { sc:LSc,… }` per
   rimappare. Dopo migration 036 i codici sono canonici (`SCI`/`ART`/`LIN`,
   admin-gestiti, dinamici via `curriculum_entries`) → `IIS_MAP["SCI"]` non
   mappava → 404. Le mappe hardcoded sono incompatibili con codici dinamici.
2. **Dati non istituzionali.** I file sono statici: un admin non può creare/
   modificare obiettivi per nuovi indirizzi/classi/materie senza toccare file.

## Decisione

**A — Codici canonici + mappe dinamiche.** Rinominati i dati ai codici canonici
(`LSc→SCI`, `LArAR→ART`, `LLi→LIN`; mapping derivato da `IIS_MAP` ⋈
`CurriculumLookup::INDIRIZZO_LEGACY_MAP`). Rimosse le 3 `IIS_MAP/CLS_MAP/MAT_MAP`
hardcoded → `mapIis/mapCls` = identità, `mapMat` = lowercase. I codici curriculum
(dinamici) sono usati DIRETTI: qualsiasi codice admin-creato funziona.

**B — Override istituzionali dinamici.** Tabella `risdoc_curriculum_data`
(migration 067): `institute_id` (0=globale|N=istituto), `dataset`, `indirizzo`,
`classe`, `materia`, `body JSON`. Endpoint `GET /api/risdoc/curriculum-options`
risolve **override istituto → globale (DB) → fallback file statico**; `POST
.../curriculum-options[/delete]` (super-admin) per editing. Il client chiama
l'endpoint coi codici canonici. Seed (`tools/risdoc/seed_curriculum_data.php`)
importa i file folder-structured come righe globali (i file restano fallback).

## Conseguenze

- Niente più mappe code hardcoded; i dropdown seguono i codici curriculum dinamici.
- Admin può sovrascrivere/aggiungere obiettivi per-istituto via API (UI da fare).
- I file statici restano come default/seed (fallback se DB vuoto).
- **TODO (B, residuo):** UI admin nell'area risdoc per CRUD su
  `curriculum-options` (selettori indirizzo/classe/materia dinamici + editor JSON).

## Note operative

- `risdoc_curriculum_data` keyata su `institute_id=0` per le globali (UNIQUE).
- `CurriculumDataRepository::find` è try/catch → se tabella assente/errore DB,
  null → fallback file (nessun 500).
- Gotcha deploy: `*.sql` è gitignored → migration aggiunta con `git add -f`.
