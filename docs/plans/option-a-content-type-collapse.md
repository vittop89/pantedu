# Opzione A — Collasso `content_type` (7 valori → 3 formati)

> ## ✅ IMPLEMENTATO (versione snella) — commit e896165, migration 078, in prod 2026-05-30
> Dopo l'audit è emerso (verificato nel codice) che **bes/risdoc/didattica NON hanno
> branching comportamentale** — si renderizzano identici (`formatOf`→`document`) e la
> loro distinzione (pannello+visibilità) è ora su `section_id`. L'unico irriducibile è
> **esercizio≠verifica**. Quindi NON si è fatto "7→3" ma **8→4**: `mappa, esercizio,
> verifica, document`. Mapping: `lab→esercizio`; `bes/risdoc/didattica/documento/''→document`.
> Realizzato SENZA l'expand/contract qui sotto (siamo in fase test, DB riseedabile):
> migration 078 rimappa+restringe l'ENUM direttamente, con dedup non-distruttivo
> (suffisso `#id`) e `FOREIGN_KEY_CHECKS=0` per la FK `map_shares`. Le viste
> `teacher_content`/`published_content` (`SELECT tc.*`) riflettono auto → nessuna
> ricreazione vista, nessuno shim. Verificato in prod: ENUM 4 valori, create document
> ok, pannelli per sezione, 0 regressioni. Il piano completo sotto resta come
> riferimento per l'eventuale rename `content_type→content_subtype` (NON fatto, cosmetico).
>
> ## ✅ COSMETICO COERENTE IMPLEMENTATO — commit bf82f1f, migration 079, in prod 2026-05-30
> Aggiunto l'asse esplicito al DB: `content_type`→**`content_subtype`** (4 valori, tipo fine) +
> **`content_format`** ENUM(map/exercise/document) come **STORED GENERATED** (CASE identico a
> `formatOf()` → zero drift, nessun write-side: la dual-write/crypto non la elenca, MariaDB la
> calcola). Viste ricreate con header `ALGORITHM=UNDEFINED SQL SECURITY DEFINER` (da 073) che
> espongono `content_subtype`+`content_format`+**alias `content_type`** → le ~21 read-side SQL
> restano invariate (shim del critico applicato). Solo gli accessi DIRETTI base-table passano a
> `content_subtype`. `search()` ha un filtro `content_format`. `CHANGE/ADD COLUMN IF [NOT] EXISTS`
> + FK_CHECKS=0 (FK map_shares). Verificato prod. NB onesto: NON ho rimosso `formatOf()` dai
> call-site (resta la mappa-di-record che la colonna generata rispecchia) — rimuoverlo ovunque
> era rischio alto/beneficio basso. Difetti #1-#5 del critico tutti evitati (viste con header
> preservato; published vuota; idempotente; no DDL-rollback-assunto perché su DB test; Fase 4
> indici non collassati alla cieca).
>
> Stato originario del documento: **PIANO / non eseguito.** Documento generato dal workflow `option-a-content-type-collapse`
> (5 finder paralleli + architetto + critico avversariale), 2026-05-30. Raccomandazione finale:
> **go-with-mitigations**, ma con l'avvertenza onesta che il piano sicuro **NON cancella** i 7 nomi —
> li conserva come colonna `content_subtype`. Il guadagno reale è un asse di branching/indice a 3 valori,
> non l'eliminazione dei letterali.

## 1. Cos'è l'Opzione A

`teacher_content.content_type` ha 7 valori: `mappa, esercizio, verifica, lab, bes, risdoc, didattica`.
A livello di **rendering** sono solo 3 formati, già astratti da `TeacherContentRepository::formatOf()`:

| formato | content_type sorgente | come si renderizza |
|---|---|---|
| `map` | `mappa` | iframe / immagine mappa |
| `exercise` | `esercizio`, `verifica`, `lab` | scheda esercizio / contract-shell |
| `document` | `bes`, `risdoc`, `didattica` | documento PT |

Opzione A = far diventare i 3 formati l'**asse fisico** nel DB e nel codice, invece di tenere 7 nomi
astratti da `formatOf()`.

## 2. Verdetto del critico: perché NON è una vera cancellazione

L'audit ha trovato **i 7 nomi sono superficie persistita ed esterna**, non solo interna:

- **~21 query read-side** filtrano `WHERE content_type IN(...)` (search, analytics, metrics, pool, GDPR).
- **URL pubblici** `/studio/{type}/...` + slug legacy (`LegacyGoneMiddleware::TYPE_MAP`) → i bookmark salvati dipendono dai 7 nomi.
- **Export GDPR (Art. 20)**: `TeacherContentExporter` usa i 7 nomi come cartelle (`mappe/ esercizi/ lab/`) e chiavi stat (`mappe_count`…). Cambiare i nomi rompe il formato dell'archivio e la re-importabilità.
- **Import bundle**: dispatch a 4 vie su `type` (`mappa/esercizio/documento/verifica-tex`).
- **Semantica cross-type irriducibile a formato**: l'accoppiamento `esercizio ↔ verifica` (`SharedContentPolicy`) e lo split di path `eser` vs `verifiche` (`PoolController`, `ContractRepository`) distinguono `esercizio` da `verifica`, che hanno lo **stesso** formato `exercise`. Un modello a soli 3 formati **non può** esprimere questa distinzione.

→ Conclusione: i 7 nomi vanno **conservati** (rinominati `content_subtype`). Si aggiunge `content_format`
come asse di branching pulito. Cancellarli davvero costerebbe settimane (versioning archivio GDPR,
layer redirect URL, breaking change OpenAPI, migrazione formato import) e **non è raccomandato**.

## 3. Strategia: Expand / Contract (non distruttivo, reversibile a livello codice)

Pattern a 5 fasi, ognuna deployabile e revertibile in modo indipendente.

### Fase 0 — Inventario & probe (rischio basso, ~0.5g)
- Snapshot `SHOW CREATE TABLE teacher_content_data` **e** `published_content_data` (la `schema.sql` è **stale**: righe 191-193 indicizzano colonne droppate dalla migration 040 — usare il DDL **live**, non lo schema dump).
- `SELECT content_type, COUNT(*) ... GROUP BY content_type` su entrambe le tabelle → confermare che esistano solo i 7 valori; verificare assenza di `documento`/`custom` legacy (visti in backup predupe ed ENUM `teacher_sidebar_sections`). Se presenti → `documento`→format `document`; verificare che NON siano nell'ENUM prima della Fase 3.
- Probe duplicati: trovare coppie `(teacher_id, title)` oggi uniche solo perché `content_type` differisce (es. un `esercizio` e una `verifica` omonimi). Documentare il blast radius — **non** toccare la unique key.
- Test invariante CI: `formatOf()` ≡ `{mappa:map; esercizio,verifica,lab:exercise; default:document}` per **tutti** i `TYPES`, così il `CASE` SQL resta in lockstep col PHP.

### Fase 1 — EXPAND: colonna `content_format` (additiva, idempotente) (rischio basso, ~0.5g)
- Migration `078_add_content_format.sql`, **idempotente** (guard `information_schema`, pattern 058/069/071 — **NON** ALTER nudo come negli snippet §5: vanno avvolti nel guard).
- `ADD COLUMN content_format ENUM('map','exercise','document') NOT NULL DEFAULT 'document'` su `teacher_content_data` **E** `published_content_data` (le due tabelle non devono mai divergere).
- Backfill `UPDATE ... SET content_format = CASE content_type ...` (byte-identico a `formatOf()`).
- `INDEX idx_tc_format (content_format, visibility)`; lasciare intatti gli indici `content_type`.
- Ricreare la VIEW `teacher_content` **copiando la testata esatta** della migration 073 (`ALGORITHM = UNDEFINED SQL SECURITY DEFINER`) — gli snippet §5 le omettono: ometterle cambia silenziosamente algoritmo/contesto privilegi in prod (**difetto #1 del critico**). Essendo `SELECT tc.*`, `content_format` fluisce automaticamente.
- Validare dual-write/crypto con `TeacherContentDualWriteTest` + `EncryptionFullFlowTest`. Colonna dormiente. Deploy.

### Fase 2 — Routing rendering + storage-strategy su `content_format` (solo codice) (rischio medio, ~2g)
- Convertire i branch su nome-tipo a `format`: `TeacherContentExporter:162` (blob `mappa`), `PoolController:546` (clone mappa) → `format==='map'`. `ContentStudyController:1512,1633` già usano `formatOf()` (tenere).
- **Mantenere** lo split `esercizio` vs `verifica` dove è genuinamente cross-type (slug `eser`/`verifiche` in `PoolController:653`, `ContractRepository:476-480`): leggono `content_subtype`, **non** format. Marcare ogni sito come `subtype-intentional`.
- JS: in `sidepage-modal-content.js` sostituire `isMappa`/array-tipo con check su `format` dal registry; `sidepage-registry.js` `BASE_SIDEPAGES` guadagna un campo `format` **accanto** a `type` (non rimuovere `type`). Il dispatch create (157-159) continua a emettere i 7 nomi subtype (obbligatorio per storage); solo il **layout UI** usa il format.
- Lasciare INVARIATI: validazione (`TYPES`, `validateInput:840`), filtro `search()`, URL, export GDPR, dispatch import, sidebar config, accoppiamento esercizio/verifica.
- Test: output rendering identico pre/post per 1 riga di ciascuno dei 7 tipi + il POST JS continua a inviare un subtype valido. Deploy.

### Fase 3 — CONTRACT: rename `content_type` → `content_subtype` + shim VIEW (rischio ALTO, ~1.5g)
- Migration `079`: `CHANGE COLUMN content_type content_subtype ENUM(...7 valori...)` su entrambe le tabelle (idempotente). I 7 valori **preservati verbatim** — solo cambio nome colonna.
- **Shim back-compat critico**: ricreare la VIEW come `SELECT tc.*, tc.content_subtype AS content_type, ...` → la VIEW continua a esporre `content_type` (alias) ⇒ **tutte** le ~21 query read-side restano invariate. Copiare di nuovo la testata `ALGORITHM/SECURITY` da 073.
- Unique key: ridefinire `uq_teach_content_title (teacher_id, content_subtype, title)` — stessa tupla, nuovo nome colonna. **NON** collassare a `(teacher_id, title)`.
- Aggiornare il **write-side** che fa INSERT/UPDATE diretto sulla tabella base: `TeacherContentRepository::create()` (440-451), INSERT `ImportBundleController` (488/583/640), `MapsController:198`, `ContractRepository:560`, `ContentActionLogger:62`, **+ il servizio di publish** (vedi rischio sotto). Scrivere **sia** `content_subtype` **sia** `content_format`.
- I reader della VIEW restano coperti dall'alias; aggiornare solo le query dirette sulla tabella base (`MapSyncService:198/224/259`, dup-check `ImportBundle:461`, filtro verifica `ContentStudyController:292/302`).
- Spedire migration + commit write-side **insieme**, dietro finestra di manutenzione. Deploy.

### Fase 4 — Consolidamento indici (opzionale, EXPLAIN-gated) (rischio basso, ~0.5g)
- ⚠️ **Ripianificare contro il DDL live**: la premessa del finder ("`idx_tc_subject`/`idx_tc_section` sono duplicati `(content_type,visibility)`") è **falsa** — `schema.sql:191-193` mostra indici multi-colonna (`subject_code`/`indirizzo`/`classe`-prefissati) su colonne droppate in 040 (**difetto #5 del critico**). Fare `SHOW CREATE` live prima di toccare qualsiasi indice.
- Tenere un indice su `content_subtype` SOLO se una query calda filtra ancora per i 7 valori (`MapSyncService`, dup-check import, filtro verifica). Reversibile.

## 4. Rischi chiave (corretti dal critico)

| # | Rischio | Severità | Mitigazione |
|---|---|---|---|
| 1 | **DDL MariaDB NON transazionale**: se `079` fallisce tra i due `CHANGE COLUMN` (una tabella sì, l'altra no) → skew senza rollback automatico | **ALTA** | Le due `CHANGE COLUMN` in un'unica migration; ma il "transaction-wrapped batch" è **impossibile** per DDL. Drill di rollback su clone staging; runbook manuale per lo skew; snapshot DDL Fase 0. |
| 2 | **`content_format` drift su `published_content`**: `NOT NULL DEFAULT 'document'` + backfill una-tantum, ma **nessun writer di `published_content` trovato** → ogni mappa/esercizio pubblicata futura nasce `document` (corruzione silenziosa lato studente) | **ALTA** | Localizzare il servizio di publish e aggiungerlo al write-side Fase 3 **oppure** rendere `content_format` una **STORED GENERATED column** su `published_content_data` (lì nessuno la scrive → il DB la deriva, niente drift). |
| 3 | **Clausole VIEW perse**: `078/079` ricreano la view senza `ALGORITHM=UNDEFINED SQL SECURITY DEFINER` di 073 → cambio silenzioso privilegi/ottimizzazione | **ALTA** | Copiare la testata esatta da `073_teacher_content_view_section_id.sql`. |
| 4 | **`formatOf()` ↔ `CASE` SQL divergono** se si aggiunge un 8° tipo a `TYPES` senza migration | **ALTA** | Test CI invariante (Fase 0); guard che fallisce se `TYPES` cresce senza migration corrispondente. |
| 5 | **Idempotenza**: gli snippet `078/079` sono ALTER/UPDATE nudi → errore al re-run | media | Avvolgere in guard `information_schema` (pattern 058/069/071). |
| 6 | **Lock prod su `CHANGE COLUMN ENUM`** su tabella grande con blob crypto → rebuild/metadata-lock = stallo scritture | media | Finestra di manutenzione; misurare su clone; valutare `pt-online-schema-change` se il fermo è inaccettabile. |
| 7 | JS registry: consumer che leggono `.type` si rompono a metà transizione | media | Campo `format` **accanto** a `type` (dual-field), non sostituzione. |

## 5. SQL di migrazione (bozza — da indurire con guard idempotenti + testata VIEW da 073)

> ⚠️ Gli snippet sotto sono la **forma logica**. Prima dell'esecuzione: (a) avvolgere ogni DDL in guard
> `information_schema`; (b) copiare `ALGORITHM = UNDEFINED SQL SECURITY DEFINER` e il corpo JOIN esatto
> dalla migration 073; (c) decidere generated-vs-plain per `published_content_data.content_format` (rischio #2).

```sql
-- 078_add_content_format.sql (EXPAND)
ALTER TABLE teacher_content_data
  ADD COLUMN content_format ENUM('map','exercise','document') NOT NULL DEFAULT 'document' AFTER content_type;
UPDATE teacher_content_data SET content_format = CASE content_type
  WHEN 'mappa' THEN 'map'
  WHEN 'esercizio' THEN 'exercise' WHEN 'verifica' THEN 'exercise' WHEN 'lab' THEN 'exercise'
  ELSE 'document' END;
ALTER TABLE teacher_content_data ADD INDEX idx_tc_format (content_format, visibility);
ALTER TABLE published_content_data
  ADD COLUMN content_format ENUM('map','exercise','document') NOT NULL DEFAULT 'document' AFTER content_type;
UPDATE published_content_data SET content_format = CASE content_type
  WHEN 'mappa' THEN 'map'
  WHEN 'esercizio' THEN 'exercise' WHEN 'verifica' THEN 'exercise' WHEN 'lab' THEN 'exercise'
  ELSE 'document' END;
-- VIEW: ricreare con testata 073 (qui omessa) — SELECT tc.* espone già content_format

-- 079_rename_content_type_to_subtype.sql (CONTRACT)
ALTER TABLE teacher_content_data
  CHANGE COLUMN content_type content_subtype ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica') NOT NULL;
ALTER TABLE published_content_data
  CHANGE COLUMN content_type content_subtype ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica') NOT NULL;
ALTER TABLE teacher_content_data
  DROP INDEX uq_teach_content_title,
  ADD UNIQUE INDEX uq_teach_content_title (teacher_id, content_subtype, title);
-- shim: la VIEW espone content_subtype AS content_type (testata 073) → read-side intatto
```

Down: invertire l'ordine (VIEW plain → unique key originale → `CHANGE COLUMN` inverso su entrambe →
`DROP COLUMN content_format` + `DROP INDEX idx_tc_format`). Nota: il down DDL **non** è atomico (rischio #1).

## 6. Test plan
- `TeacherContentDualWriteTest` + `EncryptionFullFlowTest` dopo Fase 1 e Fase 3 (crypto round-trip).
- Invariante mapping: per ognuno dei 7 `TYPES`, `formatOf(type)===atteso` **e** una riga appena inserita ottiene il `content_format` corrispondente (CASE DB ≡ PHP).
- **GDPR golden-file**: export di un docente con 1 riga per tipo **prima/dopo** → albero (`mappe/ esercizi/ lab/`) e chiavi summary byte-identici (contratto Art. 20).
- **URL regression**: `/studio/mappa/...`, `/studio/esercizio/...`, `/studio/risdoc/...` → 200 + render mode corretto post-rename (prova alias VIEW + path `formatOf`).
- **Import round-trip**: export → re-import per ogni tipo; dispatch 4-vie risolve, righe con `content_subtype` + `content_format` corretti.
- **Pool recover**: clone mappa (blob), esercizio+verifica (path `eser` vs `verifiche`), risdoc → storage-strategy usa il subtype.
- **Counterpart esercizio↔verifica** (`SharedContentPolicy`): propagazione ancora attiva (subtype preservato).
- `SidebarSectionRepositoryTest`: `allowed_content_types` coi 7 nomi valida ancora.
- EXPLAIN before/after su `search()` e analytics (gate Fase 4).
- **Rollback drill** su clone: up 078+079 → down → ri-eseguire crypto + GDPR golden.

## 7. Domande aperte (da chiudere prima di eseguire)
1. Valore legacy `documento` realmente presente in righe live? Se sì → `document` + confermarlo nell'ENUM prima del `CHANGE COLUMN`.
2. `published_content_data.content_format`: STORED GENERATED (DB-enforced, no drift, ma non scrivibile da INSERT) vs plain scritto dal dual-write? → decide il rischio #2. Probabile: **generated** su `published_content` (nessun writer), **plain** su `teacher_content_data` (dual-write esplicito).
3. `published_content` è rigenerato a ogni publish o copia indipendente? Se rigenerato, deriva `content_format` al publish; se indipendente, il backfill 078 è obbligatorio e va mantenuto in sync.
4. Esistono integratori esterni che trattano l'enum `content_type` OpenAPI (`generate_openapi.php:188`) come contratto rigido? Se sì → il subtype resta **permanente**, non transitorio.
5. `strcomp` è ancora alias vivo di `bes` (`sidepage-modal-content.js:701,814`) o morto? Se vivo, includere l'alias nel mapping subtype.

## 8. Stima & raccomandazione finale
**~4-6 giorni-ingegnere.** Il grosso è la Fase 2 (re-routing PHP/JS) e la Fase 3 (rename + shim, rischio
massimo, finestra di manutenzione). **Onestà**: poiché i 7 nomi restano come `content_subtype`, questo
**non è** la cancellazione dei 7 valori — compra un asse di branching/indice a 3 valori pulito.
Se l'obiettivo fosse davvero eliminare i 7 letterali, l'effort esplode a settimane e **non è raccomandato**.

**Decisione suggerita**: eseguire al più Fase 0-2 (guadagno reale: branching su formato, indice `content_format`,
zero rischio distruttivo) e fermarsi prima della Fase 3 a meno che il rename non porti un beneficio concreto
oltre l'estetica. `formatOf()` fornisce già il 90% del valore dell'Opzione A senza toccare lo schema.
