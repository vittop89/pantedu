---
tags:
  - documentazione/architettura
  - dominio/verifiche
date: 2026-04-23
tipo: architettura
status: finale
aliases: ["verifiche"]
cssclasses: []
---

# Dominio: verifiche

> [!abstract] Scopo
> Verifiche scolastiche: composizione da selezione esercizi, gestione print_info, builder, download. Strettamente accoppiato al dominio esercizi.

## Confini del dominio

- **In**: esercizi selezionati da docente, configurazioni stampa
- **Out**: PDF verifica, LaTeX verifica, dati print_info

## Moduli interni

| Modulo | File | Responsabilità |
|--------|------|----------------|
| VerificheController | `app/Controllers/VerificheController.php` | `managePrintInfo`, `saveLoadScelte` — gestione dati stampa e scelte verifica |
| VerificaBuilderController | `app/Controllers/VerificaBuilderController.php` | `listMine`, `show`, `build`, `delete` — CRUD verifiche da selezione |
| VerificheService | `app/Services/VerificheService.php` | Logica composizione verifica |
| TeacherController | `app/Controllers/TeacherController.php` | `verifiche`, `downloadVerifica`, `cloneExercise` |

## JS modules (verifiche)

| Modulo | File | Funzione |
|--------|------|---------|
| verifica-builder | `js/modules/features/verifica-builder.js` | UI builder verifica: selezione esercizi, ordine |
| verifica-sticky | `js/modules/features/verifica-sticky.js` | Sticky header nel builder |
| verifiche-print-ui | `js/modules/print/verifiche-print-ui.js` | UI stampa verifica |
| print-export | `js/modules/print/print-export.js` | Generazione LaTeX verifica (condiviso con esercizi) |

## API pubblica

- `GET /api/verifiche` — lista verifiche del docente
- `GET /api/verifiche/{id}` — dettaglio verifica
- `POST /api/verifiche/build` — componi verifica da esercizi selezionati + csrf + rate
- `POST /api/verifiche/{id}/delete` — elimina verifica + csrf + rate
- `ANY /verifiche/print-info` — gestione print_info.json + csrf + rate
- `ANY /verifiche/scelte` — salva/carica scelte verifica + csrf + rate
- `GET /teacher/verifiche.json` — lista verifiche formato JSON
- `GET /teacher/verifiche/{id}/download` — download verifica
- `POST /teacher/exercises/clone` — clone esercizio da verifica + csrf + rate

## Relazione con esercizi

Le verifiche sono composte da item del `teacher_content` (esercizi). Il `VerificaBuilderController::build()` riceve una lista di ID esercizi e compone una verifica. La visualizzazione riusa le stesse classi CSS degli esercizi (`collex-item`, `problem`, ecc.).

## Phase G22.S2 — Cache PDF content-addressed

Da G22.S2 (2026-05-05) il flow `compilePdf` controlla una cache per-teacher
content-addressed prima di chiamare il VPS:

- Migration 030: `verifica_documents.tex_sha256 CHAR(64) NULL` + index
  composito `(teacher_id, tex_sha256)`.
- `saveTex/saveBatch/updateTex` calcolano e persistono `sha256(tex)` su
  ogni row.
- `VerificaController::compilePdf` chiama `attachCachedPdfFor` prima del
  VPS: se trova un altro doc dello stesso docente con stesso sha + PDF
  popolato, ne riusa il PDF (decifra + ricifra envelope per la row
  corrente). Cache hit → response `compile.engine = 'cache'`,
  `cache_hit = true`, `duration_ms = 0`.
- Bypass cache su `?with_artifacts=1` (preview modal richiede synctex/log
  non in cache).
- Scope per-teacher OBBLIGATO da envelope encryption ADR-006.

Vedi changelog `2026-05-05 — Phase G22.S2`.

## Phase G22.S1 — Atomicità saveBatch / saveTex

Da G22.S1 (2026-05-05) il salvataggio di una verifica e' transazionale:

- `VerificaDocumentService::saveBatch` esegue 8 INSERT in una sola
  transazione DB (via `App\Support\TransactionRunner`). Su rollback,
  i blob cifrati gia' scritti su filesystem vengono cancellati
  automaticamente (cleanup `$writtenBlobs`).
- Il branch `force=1` raccoglie i blob da cancellare in `$blobsToReap`
  e li cancella SOLO post-commit, cosi' che un rollback non perda
  i blob esistenti.
- Stesso pattern in `saveTex` (1 blob + 1 row).
- Bug fix: `force=1` chiamava `store->delete($teacherId, $path)` con
  firma sbagliata → blob force-replaced rimanevano orfani. Ora corretto.

Vedi changelog `2026-05-05 — Phase G22.S1`.

## Note legacy

`/verifiche/*` (URL legacy) → 410 Gone via `LegacyGoneMiddleware`. Le verifiche legacy erano PHP monolitici (3000-5000 LOC) in `_archive_phase20/legacy_phase15/verifiche/php/`.

## Phase G8 — verifica_documents (TEX/PDF cifrato)

Da G8 esiste un secondo flusso "SalvaTEX" parallelo a `/api/verifiche/build`,
con tabelle dedicate `verifica_documents` + `verifica_templates` (migration
021) e blob TEX/PDF cifrati envelope (storage/verifiche_enc/).

Vedi [[decisions/ADR-010-modern-topbar]] per il design completo:
- Topbar `.fm-topbar` con 6 azioni (SalvaTEX/Overleaf/ZIP/GENERA/filtri/Editor)
- `verifica-documents-sidepage.js` rende fm-db-block per-materia
- PDF upload modal + viewer iframe (G8.8)
- Templates editor (intestazione/griglia/criteri/footer) auto-applicati
  via `VerificaDocumentService.applyTemplate`
- VSCode quick-launch via vscode:// (G8.11)

I due flussi (legacy `teacher_verifiche` vs nuovo `verifica_documents`)
coesistono finche' tutti i docenti migrano. Il bridge invisibile in
`_topbar_modern.php` preserva la compat con `print-export.js` legacy.

## Link correlati

[[domains/esercizi/esercizi-overview]] · [[routing-and-api]] · [[technical-debt]]
· [[decisions/ADR-010-modern-topbar]]
