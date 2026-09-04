---
tags:
    - decisione
    - architettura
    - ux
    - verifica
date: 2026-04-30
status: accettato
phase: G8
aliases: ["adr-010", "modern-topbar", "verifica-documents", "salvatex"]
---

# ADR-010 — Modern unified topbar + verifica_documents (TEX/PDF cifrato)

## Contesto

La `.upbar > .selwrapbtncopy` legacy (Phase 8/20 carryover) esponeva 4
checkbox + 1 bottone (Overleaf, Server, Drive, ARGOMENTI rimossa, GENERA-VER)
con UX a checkbox-stato che richiede memorizzazione + 1 click finale per
generare. Limiti:

1. **Stato implicito difficile da capire**: combinazione checkbox decideva
   destinazione (`Server` ON + `Overleaf` OFF → solo server, `Server` ON
   + `Overleaf` ON → server + apri Overleaf, ecc.).
2. **#syncDrive deprecato**: G6/G7 ha sostituito il polling Apps Script con
   sync server→Drive on-demand via `.fm-session-drive-sync` button. La
   checkbox `#syncDrive` legacy non aveva piu' significato.
3. **Nessun salvataggio "verifica come artefatto"**: il flusso legacy
   produce `.tex` su `/temp/` o lo posta a Overleaf; nessun record
   strutturato persistente ricercabile per docente/materia.
4. **Niente PDF compilati associati**: il PDF prodotto da Overleaf non era
   collegabile al `.tex` originale (record duplicati su Drive senza
   metadata link).
5. **Templates TEX non modificabili**: intestazione/griglia voti/criteri/
   footer hard-coded in `TexBuilder.php` (Sanitizer/VersionPicker).
   Cambio = code change + redeploy.

## Decisione

### 1. Modern unified topbar (`.fm-topbar`)

Sostituire `.selwrapbtncopy` con una topbar a 3 zone (meta | target |
actions) e 6 azioni primarie esplicite:

| Azione        | Endpoint                          | Effetto                                  |
|---------------|-----------------------------------|------------------------------------------|
| 💾 SalvaTEX   | POST /api/verifica/save-tex       | persiste .tex cifrato (envelope ADR-006) |
| 📤 Overleaf   | toggle bridge `#overleaf` legacy  | (compat con flusso print-export.js)      |
| 💾 ZIP        | GET /api/verifica/{id}/zip        | bundle .tex+.pdf+README                  |
| 🔘 GENERA     | save + modal target picker        | scelta Overleaf / server / locale        |
| ⚙ filtri      | toggle drawer `.upbar-controls-container` | preserva handler legacy via CSS  |
| ⚙ Editor      | modal templates CRUD              | edit intestazione/griglia/criteri/footer |

Invariante: la nuova topbar appare SOLO su pagine con `body.exercise-context
+ .problem|layout=exercises|.fm-db-study` (detection runtime in
`topbar-modern.js`), preservando la upbar legacy filtri come drawer.

**Bridge invisibile**: gli hidden inputs `#overleaf #Server #syncDrive
#btnCopyver` restano dentro `.fm-topbar__legacy-bridge` cosi' che
`print-export.js` + `utilities.js` continuino a leggerli/scriverli senza
modifiche fino al refactor finale di quei moduli (G8.14.X futuro).

### 2. `verifica_documents` table + EncryptedBlobStore namespace

Nuova tabella `verifica_documents` (migration 021) con TEX/PDF cifrati
envelope tramite `App\Services\Crypto\EncryptedBlobStore('verifiche_enc')`,
generalizzazione del `MapBlobStore` (Phase G2) per supportare blob in
namespace multipli.

Layout file blob (compat con MapBlobStore):
```
[2B kv (BE)] [12B IV] [16B GCM tag] [N B ciphertext]
```

### 3. `verifica_templates` editabili

Nuova tabella `verifica_templates` con 4 frammenti TEX:
`intestazione`, `griglia_voti`, `criteri`, `footer`.

`VerificaDocumentService.applyTemplate` inietta i frammenti nel TEX
prodotto da `TexBuilder` ai punti chiave:
- `intestazione` → dopo `\maketitle`
- `griglia_voti / criteri / footer` → prima di `\end{document}`

Auto-applicazione del default per docente; override via `template_id`
nel payload SalvaTEX.

### 4. PDF popup upload + viewer iframe

Click su `.fm-vd-link` nella sidepage:
- senza PDF: apre modal upload (drag&drop, max 30 MiB, magic bytes %PDF-)
- con PDF:   apre viewer iframe full-screen `/api/verifica/{id}/pdf`
              con pulsanti chiudi/scarica/sostituisci.

Il PDF e' cifrato envelope come il TEX. Streaming inline con
`Content-Disposition: inline` + `X-Content-Type-Options: nosniff`.

### 5. Sidebar render per-materia

`verifica-documents-sidepage.js` aggiunge fm-db-block per-materia dentro
`.fm-risdoc-cat[data-category=VERIFICHE]` con `fm-db-head-label` = nome
materia (risolto da `#sel-mater` option text). Re-render automatico su
`fm:verifica-saved` evento.

### 6. VSCode quick-launch (opzionale)

Bottone 📝 nella sidepage triggera download `.tex` + `vscode://file/{path}`
con path indovinato dalla cartella Downloads localStorage-saved
(prima volta: prompt all'utente per la directory).

## Conseguenze

### Positive

- **UX esplicita**: ogni azione ha bottone dedicato, nessuna combinazione
  checkbox da imparare.
- **Verifiche persistenti ricercabili**: `verifica_documents` e' indicizzato
  per (teacher_id, materia) + (teacher_id, fm_db_section), si possono
  filtrare facilmente per render sidebar.
- **Crypto-shredding O(1)** anche per verifiche: cancellando
  `teacher_keys.kv` di un docente, tutti i blob TEX/PDF diventano
  illeggibili (Art. 17 GDPR efficiente, riuso ADR-006).
- **Templates editabili senza redeploy**: docente ridefinisce
  intestazione/griglia/criteri/footer dal modal Editor.
- **Preserva legacy print-export.js**: il bridge invisibile permette il
  refactor incrementale.

### Negative / trade-off

- **Server-side pdflatex compile** non implementato: GENERA "Server" e'
  disabled stub. Compile via Overleaf cloud o locale (TeXworks/VSCode).
  Aggiungere il queue worker pdflatex e' un'estensione futura (G8.9.X).
- **vscode:// quick-launch e' fragile**: il browser non espone il path
  effettivo del file scaricato, quindi indoviniamo `~/Downloads/file.tex`.
  Funziona se utente non ha cambiato la cartella default. Mitigato con
  setting localStorage (shift+click sul btn 📝 per editare).
- **Bridge legacy resta**: `#overleaf #Server #syncDrive #btnCopyver`
  permangono come hidden inputs finche' `print-export.js` non viene
  refattorizzato. Documentato in `_topbar_modern.php`.

## Stack tecnologico

- **Backend**: PHP 8.3, MySQL (verifica_documents/verifica_templates),
  ZipArchive (G8.10), envelope encryption (ADR-006).
- **Frontend**: Vite + ES modules, vanilla DOM (no jQuery in topbar/modal),
  custom events (`fm:verifica-saved`, `fm:db-sidepage-rendered`).
- **CSS**: gradient sticky topbar, dark-aware, responsive < 720px.

## Roll-out

Branch `feat/topbar-modern-g8` con 14 commit atomici (G8.1 - G8.14).
Strategia "no flag" + commit reversibili via `git revert`. Master invariato
fino a merge esplicito (Master Immutable rule).

## File aggiunti / modificati

```
app/Controllers/VerificaController.php           (nuovo)
app/Controllers/VerificaTemplateController.php   (nuovo)
app/Services/Crypto/EncryptedBlobStore.php       (nuovo)
app/Services/Verifica/VerificaDocumentService.php (nuovo)
app/Repositories/VerificaDocumentRepository.php   (nuovo)
app/Repositories/VerificaTemplateRepository.php   (nuovo)
database/migrations/021_verifica_documents.sql   (nuovo)
views/partials/_topbar_modern.php                (nuovo)
views/partials/_upbar_loader.php                 (modificato)
views/partials/upbar.html                        (modificato — cleanup)
js/modules/features/topbar-modern.js             (nuovo, ~250 lines)
js/modules/features/verifica-documents-sidepage.js (nuovo)
js/modules/features/verifica-pdf-modal.js        (nuovo)
js/modules/features/verifica-genera-modal.js     (nuovo)
js/modules/features/verifica-vscode-launch.js    (nuovo)
js/modules/features/verifica-templates-modal.js  (nuovo)
css/layout.css                                   (modificato — ~350 lines added)
routes/web.php                                   (modificato — 9 routes nuove)
js/modules/bootstrap.js                          (modificato — 6 import nuovi)
```

## Riferimenti

- ADR-006 — Envelope encryption (TKEK / crypto-shredding).
- ADR-009 — Google Drive integration G1-G7 (sync mappe).
- Phase G7 — `.fm-session-drive-sync` (sostituisce #syncDrive).
- `app/Services/TexBuilder.php` — produce TEX raw a cui il template
  viene applicato in `VerificaDocumentService.applyTemplate`.
