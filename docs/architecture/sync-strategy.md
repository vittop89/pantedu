# Strategia di sincronizzazione completa — Design Doc

**Data:** 2026-05-08 · **Stato:** proposta · **Owner:** team Pantedu

## Contesto

Oggi (G22.S15.bis Fase 5) il docente ha 4 pulsanti sync nella topbar:

| Bottone | Cosa sincronizza | Storage |
|---|---|---|
| ☁ Drive | Mappe + verifiche (PDF) | Google Drive cartelle nominative |
| 💾 Locale | Mappe + verifiche (PDF) | Cartella FS Access (Chrome/Edge) |
| 🐙 GitHub | Solo README.md di test | Repo GitHub privato |
| ⇩ Tutto | Drive + Locale + GitHub orchestrato | (combinazione) |

**Lacune:**
1. Non sincronizza i **modelli personalizzati** del docente: template verifiche TeX (`storage/templates/verifiche/{teacherId}/...`), TikZ saved snippets, modelli risdoc texCommon (`risdoc_teacher_overrides`), GeoGebra catalog.
2. Non sincronizza i **PDF compilati** intermedi (solo i finali batch).
3. GitHub sync push solo un README di test, non il bundle completo.

## Problema 1 — Bundle docente completo

### Risorse da includere

| Categoria | Origine | Path target nel bundle |
|---|---|---|
| Mappe concettuali | `storage/maps/blob/{teacher}/` (cifrate) | `mappe/{materia}/{title}.{ext}` |
| Verifiche TEX | `verifica_documents.tex_files` (manifest blob) | `verifiche/{materia}/{title}/{variant}.tex` |
| Verifiche PDF | `verifica_documents.pdf_blob_path` | `verifiche/{materia}/{title}/{variant}.pdf` |
| GeoGebra PDF asset | `verifica_documents.tex_files` (path geogebra/N.pdf) | `verifiche/{materia}/{title}/geogebra/N.pdf` |
| Template verifiche docente | `storage/templates/verifiche/teachers/{tid}/` | `modelli/verifiche/{path}` |
| Modelli TikZ docente | `storage/tikz_catalog/teachers/{tid}/` | `modelli/tikz/{label}.tex` |
| GeoGebra catalog | `geogebra_catalog` table (per teacher) | `modelli/geogebra/{label}.ggb` |
| Risdoc texCommon override | `risdoc_teacher_overrides` (kind=texCommon, template_id=0) | `modelli/risdoc/{path}` |
| Risdoc PDF generati | `risdoc_compilations` (per teacher) | `risdoc/{template_label}/{istanza}.pdf` |
| Esercizi pubblicati dal docente | `teacher_content` (kind=exercise) | `esercizi/{materia}/{anno}/{title}.{ext}` |

### API server-side

Aggiungere endpoint **`/api/teacher/sync-bundle?include={mappe,verifiche,modelli,risdoc,esercizi}`** che:
1. Risolve tutte le risorse per il docente con cascade (teacher → istituto → default)
2. Decifra blob in stream (nessuna concatenazione in RAM se possibile)
3. Ritorna chunked JSON `[{path, content_b64, mime}, ...]` paginato (`offset`/`limit`)
4. Genera anche un `manifest.json` riassuntivo con sha256 + size + last_modified per dedupe lato target

### Frontend

Estendere `drive-sync-buttons.js` con nuovo helper `drainBundleEndpoint(target)`:
- Per **Drive**: crea sub-cartelle `mappe/`, `verifiche/`, `modelli/`, `risdoc/`, `esercizi/` sotto la root del docente. Idempotente via Drive search by name.
- Per **Locale (FS Access)**: già supporta path nesting (`fs.writeFile(root, "modelli/tikz/foo.tex", bytes)` crea le directory). Riusa.
- Per **GitHub**: per ogni file del bundle, chiama `pushFile()` del `GitHubSyncService` con path nel bundle. Rate limit: 5000 req/h, batch sequenziale + retry exponential.

### Cap dimensioni

- Bundle completo per teacher tipico: ~50-200 file, ~50-300 MB (PDF pesanti).
- Drive: nessun limite per docente.
- Locale: dipende dal disco utente.
- GitHub: limit 100 MB/file, 1 GB/repo soft. **Per file > 50 MB: skip** con warning nel log.

### Roadmap implementativa

| Step | Effort | Fase |
|---|---|---|
| 1. Endpoint `/api/teacher/sync-bundle` con sub-categoria `?include=mappe` | M | G22.S16 |
| 2. Estensione frontend per drain bundle multi-categoria | M | G22.S16 |
| 3. Aggiunta `?include=modelli` con risdoc + tikz catalog | M | G22.S17 |
| 4. GitHub push del bundle completo (orchestrato batch + rate limit) | L | G22.S17 |
| 5. UI dashboard: checkbox "cosa sincronizzare" prima del click | S | G22.S17 |

## Problema 2 — Backup chiave decrittazione + import documenti

### Contesto sicurezza

I dati cifrati con TKEK (Teacher Key Encryption Key) sono inaccessibili senza la KEK derivata dal master KMS. **Se il KMS si corrompe**, i blob diventano illeggibili anche per il docente — perdita totale dei suoi documenti.

Inoltre il docente non ha modo di **migrare** i propri dati ad un altro server Pantedu: il bundle scaricato via sync è in chiaro (PDF/TeX), ma manca metadata strutturati per re-import.

### Proposta: Recovery Key opzionale

#### Generazione al signup
1. Quando il docente conferma email, mostra **popup informativo**:
   > "I tuoi documenti sono cifrati. Se preferisci poterli recuperare in caso di problemi del sistema, scarica una **Recovery Key** ora. Tienila al sicuro: senza, in caso di perdita master KMS i documenti sono persi per sempre."
2. Backend genera 256-bit random `R`, calcola:
   - `R_wrapped = AES-256-GCM(R, master_kms_key)` → memorizzato in `teacher_recovery_keys.wrapped_recovery`
   - Cifra la KEK del docente anche con `R`: `kek_recovery_wrapped = AES-256-GCM(kek, R)` → in `teacher_keys.kek_recovery_wrapped`
3. Frontend riceve `R` (in chiaro, **una sola volta**), genera PDF "Pantedu — Recovery Key" con:
   - Username + email del docente
   - QR code della chiave (formato base32 32 char)
   - Stringa hex backup
   - Istruzioni: "Stampa, conserva in cassaforte. Non condividere."
   - Hash di verifica per detection corruzioni

#### Import documenti

**Scenario A — stesso server, KMS perso:**
1. Admin sblocca "Recovery mode" (flag DB).
2. Docente accede con normale credenziali → richiesta Recovery Key.
3. Sistema usa `R` + `kek_recovery_wrapped` → ricostruisce KEK del docente → tutti i blob ridiventano leggibili.
4. Sistema rotazione: nuova KEK generata, riavvolge tutti i blob (job batch), invalida vecchia.

**Scenario B — server diverso (migrazione):**
1. Docente fa export bundle dal server vecchio (sync locale + Recovery Key).
2. Sul server nuovo si registra normalmente.
3. Endpoint `/api/teacher/import-bundle`:
   - Upload del manifest.json + file
   - Verifica firma (hash SHA-256 di tutti i file vs manifest)
   - Ricrea verifica_documents, mappe, modelli con i blob in chiaro → li ricifra con la TKEK del nuovo account
   - Recovery Key del server vecchio NON serve qui (i file sono già in chiaro nel bundle export)
4. Conflitti: se titolo + materia + variant esistono già, prompt utente (overwrite/skip/rename).

### Schema DB nuove tabelle

```sql
CREATE TABLE teacher_recovery_keys (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    wrapped_recovery BLOB NOT NULL,    -- AES-GCM(R, KMS)
    recovery_kv INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_downloaded_at DATETIME,        -- audit trail
    download_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE teacher_keys
    ADD COLUMN kek_recovery_wrapped BLOB NULL,  -- AES-GCM(KEK, R) per recovery
    ADD COLUMN recovery_kv INT UNSIGNED NULL;
```

### Audit & compliance

- **Mai loggare `R` in chiaro** né nei log applicativi né in DB
- **GDPR right-to-erase**: cancellando l'utente, cascade DELETE rimuove anche `teacher_recovery_keys`
- **Audit table** `recovery_key_audit`: ogni download / use registra timestamp + IP + user-agent
- **Rotazione**: prompt annuale al docente per rigenerare Recovery Key (se persa, vecchia inutilizzabile)

### Roadmap

| Step | Effort | Fase | Stato |
|---|---|---|---|
| 1. Migrazione DB recovery keys | S | G22.S18 | ✅ migration 035 (questo round) |
| 2. Generazione + popup signup | M | G22.S18 | ⏳ scaffolded, UI pending |
| 3. Recovery flow (admin-gated) | L | G22.S19 | ⏳ design only |
| 4. Import bundle endpoint | XL | G22.S20 | ⏳ design only |
| 5. UI export+import in Dashboard | M | G22.S20 | ⏳ design only |

### Implementazione G22.S15.bis Fase 5 (questo round)

**Bulk sync GitHub esteso** (`POST /api/teacher/github/sync-all`):
- ✅ Verifiche PDF compilati → `verifiche/{materia}/{title}/{variant}.pdf`
- ✅ Verifiche TEX manifest multi-file → `verifiche/{materia}/{title}/{variant}/{relPath}.tex`
- ✅ Modelli verifiche docente → `modelli/verifiche/{rel}` (filesystem `storage/templates/verifiche/teachers/{tid}/`)
- ✅ Modelli risdoc texCommon override → `modelli/risdoc/{rel}` (3 file: main.tex, risdoc.sty, intestaLAteX_IIS.tex)
- ⏳ Mappe (deferred — richiede investigazione tabella maps + crypto)
- ⏳ TikZ catalog docente (deferred — investigazione storage)
- ⏳ GeoGebra catalog (deferred — query DB tabella `geogebra_catalog`)
- ⏳ Esercizi pubblicati docente (deferred — query `teacher_content` kind=exercise)

**Recovery Key** (`migration 035`): tabelle pronte, popup signup + flow admin-gated da implementare in fase successiva.

## Problema 3 — Drawio shape libraries (G22.S16+)

### Use case

Docente vuole caricare librerie custom drawio (file XML, es. `pianiCartesiani.xml`)
da usare durante creazione/modifica mappe concettuali. Le librerie devono:
1. Essere disponibili nell'editor drawio embedded della UI mappe
2. Essere incluse nel sync (drive/local/github) come asset docente

### Storage proposto

```
storage/templates/drawio/
├── _default/                          ← admin-managed shape libraries
│   ├── matematica/pianiCartesiani.xml
│   ├── matematica/figureGeometriche.xml
│   └── fisica/circuiti.xml
└── teachers/{tid}/                    ← teacher-uploaded libraries
    └── personale/{nome}.xml
```

Cascade resolution come per texCommon: teacher override > admin default > nothing.

### Bundle path

```
{institute}/modelli/drawio/
├── matematica/pianiCartesiani.xml
├── matematica/figureGeometriche.xml
└── personale/{customName}.xml
```

Riusa `template-file` type del manifest. Modifica `buildLocalBundleManifest`:
walk `storage/templates/drawio/_default/` + `storage/templates/drawio/teachers/{tid}/`.

### UI integration

1. **Upload** in `/teacher/dashboard` nuova sezione "📐 Librerie drawio":
   - Drop zone per file `.xml`
   - List delle librerie attuali (default + teacher)
   - POST `/api/teacher/drawio/library` (multipart/form-data)

2. **Editor mappe**: drawio embed `<mxgraph>` accetta sidebar custom via `customEntries`
   parameter. Servire elenco librerie a init editor:
   ```js
   const libs = await fetch('/api/teacher/drawio/libraries').then(r=>r.json());
   editor.config.libraries = libs.map(l => ({ url: l.url, name: l.name }));
   ```

3. **Endpoint** `/api/teacher/drawio/libraries`: ritorna list con cascade resolved.

### Validazione XML

Lato server: parse drawio XML, verifica structure (`<mxlibrary>` root, no script eseguibili). Reject se invalid o > 1MB.

### Roadmap

| Step | Effort | Fase |
|---|---|---|
| 1. Migrazione + storage filesystem | S | G22.S16 |
| 2. Endpoint upload + list | M | G22.S16 |
| 3. UI dashboard upload + manage | M | G22.S17 |
| 4. Integrazione editor mappe drawio | L | G22.S17 |
| 5. Bundle inclusion (modelli/drawio/) | S | G22.S17 |

## Decisioni tecniche

- **GitHub sync**: PAT fine-grained (NO OAuth App) per ridurre superficie attacco e complessità infra. Il docente gestisce il token sul proprio account. Vantaggio: zero costi, zero approvazioni Marketplace.
- **Drive sync**: già OAuth, non cambia.
- **Locale**: File System Access API limitato a Chrome/Edge desktop. Niente fallback IE/Firefox (browser legacy ignorati).
- **Bundle format**: JSON with `content_b64` per file binari. Alternativa zip server-side è più efficiente in banda ma più complessa (memoria + streaming). Da valutare in G22.S17.

## Open questions

1. **Frequenza sync automatica?** Oggi solo manuale. Auto on-save? Auto-daily? → da chiedere docenti.
2. **Recovery key revoca?** Se docente perde la chiave, si genera nuova (scarda recovery wrapping). Ma se attaccante ha la vecchia → KEK ancora ricostruibile finché non roteer. Serve job di rotazione KEK on revoke.
3. **Import conflict UI**: quale granularità? Per file? Per documento intero? → mockup design needed.

---

**Riferimenti:**
- [ADR-006 envelope encryption](../decisions/ADR-006-encryption.md)
- [ADR-009 drive integration](../decisions/ADR-009-drive-integration.md)
- Memoria: `reference_vps_tex_compile_deploy.md` (deploy procedure)
