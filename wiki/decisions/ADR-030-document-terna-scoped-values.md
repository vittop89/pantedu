# ADR-030 — Un documento, valori per terna (campi 🔗/📌)

**Stato:** Accettato e in produzione (2026-06-13)
**Contesto:** ADR-024/025/026 (documento PT unificato, dati curricolari dinamici), [[ADR-029]] è un altro lavoro (decomposizione God-controller) — qui è solo un numero successivo.

## Problema

I documenti del docente (es. *Piano annuale*) hanno **la stessa struttura** ma **dati diversi** a seconda della terna **indirizzo / classe / materia**. Fino a ora ogni terna richiedeva un **documento separato** (una riga `teacher_content` per SCI/2/MAT, un'altra per SCI/3/MAT…): duplicazione della struttura e gestione manuale.

In più: alcuni campi dipendono dalla terna **solo in parte** — la dipendenza c'è ma non per tutto il documento.

## Decisione

Il legame con la terna è una **proprietà del singolo campo**, non del documento.

- **Campo 🔗 (per-terna)**: il valore cambia per indirizzo/classe/materia.
  Un campo è 🔗 se ha `options_source.folder` (le **opzioni** già si risolvono per terna → anche il **valore** lo fa: inferenza automatica) **oppure** se dichiara `binding: "terna"` (toggle esplicito, valido per qualsiasi componente porta-valore della barra: Campo, Gruppo, Select, Sì/No, celle tabella, rawTex…).
- **Campo 📌 (fisso)**: il valore è uguale per tutte le terne (comportamento storico, resta inline nel `body_pt`).

**Un solo documento, una struttura.** I valori dei campi 🔗 si salvano per terna in un blocco speciale **non renderizzato** dentro il `body_pt`:

```
{ "_type": "ternaStore", "store": { "SCI/2/MAT": { fieldKey: value, … }, "SCI/3/MAT": { … } } }
```

Stando dentro `body_pt`, il `ternaStore` **eredita la cifratura per-docente** (envelope AES-256-GCM, ADR-006): nessun campo in chiaro nuovo, **nessuna migrazione DB**.

### Chiavi campo (stabili, persistite nel body_pt)
- componente top-level → `name` (generato se assente; trasportato via `data-field-id` nel roundtrip PT↔PM).
- cella tabella → `${table.name}#${cell.cid}` (`cid` per cella, dentro `rows`).
  `compactCell`/`normalizeCell` preservano `cid`/`binding` → id stabili fra i salvataggi (senza, il drift faceva perdere i valori di una terna modificandone un'altra).

### Lente terna
La terna "lente" (per cui si risolvono opzioni **e** valori) viene dall'**URL** `/studio/risdoc/{ind}/{cls}/{mat}/…` (autorevole per il doc salvato), con fallback ai selettori sidebar.

### Navigazione
Il modello è **gated** da `metadata.terna_scoped` (assente = comportamento legacy, zero regressione). Per i doc terna_scoped:
- il linkref della sidebar appende `?ids={docId}`;
- il server (`ContentStudyController::topicPage`), con `?ids` esplicito, **non vincola la terna dell'URL** (la visibilità studente e la ACL per-riga restano) → lo stesso documento si apre a qualsiasi terna lente.

### UI
- Toggle topbar **"Valori per classe"** (🔗/📌): attiva/disattiva `terna_scoped`. All'attivazione il salvataggio cattura i valori 🔗 correnti nello slot della terna lente (nessuna perdita).
- Toggle **"Valore: 🔗 Per classe / 📌 Fisso"** nei popover di cella tabella e Select inline (auto-🔗 disabilitato se sorgente cartella). Gli altri componenti ereditano il motore (`terna-binding.js`, registro accessor per tipo).

## Conseguenze

- ✅ Niente più un documento per ogni terna; struttura condivisa, valori per classe, dipendenza parziale nativa.
- ✅ Zero regressione sui doc esistenti (gate `terna_scoped`); zero migrazione DB; cifratura invariata.
- ✅ Render **server-side** (anteprima SSR / vista studente): `TernaBinding` (PHP) applica i valori della terna URL + strip `ternaStore` in `renderCustomTopicHtml`. NB: `search()` non espone metadata/body_pt decifrati in modo affidabile → si usa `find($rid)` dedicata (la riga è già ACL-validata da `applyAclFilter`, nessun bypass).
- ✅ Tool di consolidamento dei doc duplicati per-terna in uno `terna_scoped` (`tools/migrate_terna_consolidate.php`, dry-run/`--apply`).
- ⚠️ La disattivazione del toggle collassa ai valori della terna corrente (le altre terne nello store vengono scartate).
- ⚠️ Visibilità studente: un doc `terna_scoped` ha una sola riga (una terna nello scope `publish_scope`); per renderlo visibile a più classi serve `publish_scope=classes/general` (ADR-019/069). Non gestito automaticamente dal toggle.

## File principali
- `js/modules/risdoc/pt/terna-binding.js` — motore (extract/apply/split/attach, ensureBindingIds, accessor).
- `js/components/pt-document/fm-pt-document.js` — lente terna, load/save, toggle doc.
- `js/components/pt-document/adapters/teacher-content-adapter.js` — `load/saveTernaScoped`.
- `js/modules/risdoc/pt/pm-schema.js` — global attr `binding`, `compactCell/normalizeCell` (cid/binding), toggle nei popover.
- `js/modules/risdoc/pt/pm-pt-converter.js` — carry `binding` PT↔PM.
- `js/modules/features/risdoc-sidepage.js` — linkref `?ids` per terna_scoped.
- `app/Controllers/ContentStudyController.php` — relax filtro terna con `?ids`.

## Verifica
Test live su pantedu.eu (doc terna_scoped): valori dei campi 🔗 **isolati e persistenti** fra SCI/2/MAT e SCI/3/MAT; toggle "Valori per classe" attiva + migra (verificato anche nel DB: `terna_scoped=true`, `ternaStore` con lo slot della terna). Test unitari del motore verdi.
