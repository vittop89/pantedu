---
tags:
  - documentazione/adr
date: 2026-04-23
tipo: adr
status: accettato
aliases: ["ADR-005"]
cssclasses: []
---

# ADR-005: Schema-driven rendering per template risdoc

**Status**: accettata

## Contesto

Ogni documento risdoc (piano annuale, relazione finale, programma svolto, ecc.) ha una struttura diversa: sezioni diverse, tipi di input diversi (checkbox, tabelle, textarea, grade-selector). Plan A richiedeva un file PHP o HTML custom per ogni template.

## Decisione

Adottare un approccio **schema-driven**: ogni template risdoc è descritto da un file JSON in `schemas/risdoc/` che definisce la struttura del form (sezioni, tipi di campi, opzioni). Il rendering del form avviene via Web Components Lit 3 che leggono lo schema a runtime.

Schema principale: `schemas/risdoc/template.schema.json` (meta-schema).
Schema esempio: `schemas/risdoc/piano-annuale-docente.json`.

Il campo `schema_path` in `risdoc_templates` (migration 008) punta al file JSON per ogni template.

## Motivazioni

1. **DRY**: un solo set di WC per tutti i template risdoc.
2. **Estensibilità**: aggiungere un nuovo tipo di documento = aggiungere un file JSON + record DB. Nessun codice PHP o HTML.
3. **Validazione**: `ContractSchemaValidator` usa `justinrainbow/json-schema` per validare i dati contro lo schema prima del salvataggio.
4. **Separazione form/TeX**: lo schema descrive il form; il .tex file descrive l'output. Sono indipendenti.

## Conseguenze

- **Pro**: 17 template risdoc documentati in `schemas/risdoc/`; aggiunta nuovo template senza codice PHP.
- **Contro**: debug più complesso (errori nello schema sono difficili da tracciare senza validazione esplicita); la corrispondenza tra campi schema e marker TeX (`[field-nome]`) è implicita — documentata solo nel codice.
- **Debito**: la corrispondenza schema↔TeX non è verificata automaticamente in CI. `COVERAGE_REPORT.md` in `schemas/risdoc/` documenta lo stato.

> [!info] Scelta Architetturale
> L'alternativa "un PHP per template" era più semplice ma non scalabile. La scelta schema-driven anticipa la possibilità di esporre un'UI admin per la creazione di nuovi template risdoc senza deploy.
