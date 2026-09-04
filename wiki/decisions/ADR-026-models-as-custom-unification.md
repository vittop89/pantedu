# ADR-026 — Unificazione modelli risdoc ↔ documenti custom (header collassabile)

- **Stato:** COMPLETATO — Fase A (shell unificata) + Fase B (switch a default). `/risdoc/view` rende via `<fm-pt-document>` di default; `?ui=legacy` = escape hatch; `?admin_edit=1` = legacy. Parità E2E verde (16/19/22-25: render, topbar, modal, save 200, 0 errori).
- **Data:** 2026-05-25
- **Correlati:** ADR-022/024 (`<fm-pt-document>`), ADR-025 (curriculum data), il rework custom a card (`fm-pt-document` monta N `<fm-risdoc-pt-section>`)

## Contesto / richiesta utente

Oggi esistono DUE sistemi di rendering paralleli:
- **Custom** (`layout=custom`): `<fm-pt-document>` monta N `<fm-risdoc-pt-section>`
  da un singolo `body_pt` (card collassabili, editor PT, topbar `<fm-doc-topbar>`).
- **Modelli istituzionali** (`/risdoc/view/{id}`): `<fm-risdoc-template>` monta le
  sezioni da uno SCHEMA (multi-sezione, selettori di stato indirizzo/classe/materia,
  `options_source` dinamici, una sezione `type:"header"`, topbar server-side
  `.fm-doc-topbar--risdoc`).

Richiesta: i **modelli devono diventare un caso particolare dei custom**, dove la
sezione `class="header"` (intestazione + selettori) è **collassabile/espandibile**
(usata all'occorrenza). Cioè un'unica pipeline di rendering (quella custom a card),
con i modelli che aggiungono: schema → sezioni, selettori di stato, options_source,
header collassabile.

## Decisione (target)

Unica pipeline `<fm-pt-document>`:
1. **Sorgente sezioni astratta.** `fm-pt-document` accetta le sezioni da:
   (a) split di `body_pt` (custom, già fatto), oppure
   (b) uno SCHEMA risdoc (`schema_path`) → N sezioni `pt_unified` (riusando
       `sectionSchemaToPt` + `fm-risdoc-pt-section`, già usato da `fm-risdoc-template`).
2. **Header come prima sezione collassabile.** La sezione `type:"header"` dello
   schema (selettori indirizzo/classe/materia + intestazione) diventa una card
   `<fm-risdoc-pt-section>` con chrome collassabile (già supporta collapse) e
   `collapsed=true` di default; espandibile per cambiare i selettori.
3. **Stato (selettori) propagato** alle altre card via `fm:value-change`/`FM.pt.setState`
   (meccanismo già esistente in `fm-risdoc-template`).
4. **options_source dinamici** via l'endpoint ADR-025 (già fatto).
5. **Topbar unica** `<fm-doc-topbar>` per entrambi (custom variant + risdoc variant);
   azioni TeX/PDF (modal), ZIP, VSCode, Salva, render-mode.
6. **Persistenza:** custom → `teacher_content.body_pt`; modelli → compilation/override
   schema (come oggi). L'adapter astrae il save (già c'è `RisdocTemplateAdapter`
   stub in `pt-document/adapters/`).

## Strategia di migrazione (incrementale, NON big-bang)

1. **Estrarre** la logica multi-sezione + stato + options da `fm-risdoc-template`
   in un mixin/helper riusabile da `fm-pt-document`.
2. **Aggiungere** a `fm-pt-document` la modalità `source=risdoc-template` (mounta le
   sezioni da schema, header collassabile) DIETRO feature-flag, su una rotta di test.
3. **Verificare** parità (render, selettori, options, TeX/PDF, save) su 22-25 e Piano
   annuale con E2E (screenshot + console.log) PRIMA di sostituire `/risdoc/view`.
4. **Switch** `/risdoc/view/{id}` a `<fm-pt-document source=risdoc-template>` solo
   quando la parità è verde; tenere `fm-risdoc-template` come fallback finché stabile.
5. **Header collassabile**: incremento già fattibile ORA su `fm-risdoc-template`
   (rendere la sezione header `_collapsed=true` default + toggle) come primo step a
   basso rischio, indipendente dall'unificazione completa.

## Rischi

- `fm-risdoc-template` è maturo e in produzione (modelli istituzionali usati): un
  big-bang rischia regressioni su selettori/options/compilations. → migrazione
  dietro flag + parità E2E prima dello switch.
- Save semantico diverso (body_pt vs compilation/override schema) → l'adapter deve
  gestirlo senza perdita.

## Fase A — implementata (dietro flag `?ui=unified`)

`/risdoc/view/{id}?ui=unified` rende il modello come caso particolare del custom:
- `<fm-pt-document source="risdoc-template" template-id schema-url initial-state topbar-html>`
  fa da SHELL: topbar `<fm-doc-topbar variant=risdoc>` (buttonsHtml = bottoni server) +
  monta `<fm-risdoc-template>` (motore: rendering/state/save/compilations NATIVE → lossless).
- `fm-doc-topbar` variant risdoc: aggiunti `.fm-risdoc-toolbar` + `data-fm-risdoc-toolbar` (hook export.js).
- `fm-risdoc-export.js`: delegation a livello `document` (sblocca topbar async del web component).
- TemplateViewController branch `?ui=unified`: carica `fm-pt-document.js` raw (su /risdoc/view non c'è bootstrap).
- **Verificato** (E2E, pantedu.eu): modello completo renderizzato, topbar (Modifica struttura/Salva/
  TEX/PDF/ZIP/Export/Import JSON), modal TeX/PDF apre, hook ZIP presente, 0 errori console.
- **Default (no flag) INTATTO** in produzione.

## Fase B — SWITCH (FATTO)

`/risdoc/view/{id}` rende la shell unificata di DEFAULT. Condizione:
`$useUnified = ($ui !== 'legacy') && !$isAdminEdit`.
- **Default** → unified (fm-pt-document).
- **`?ui=legacy`** → path storico (escape hatch / fallback rapido).
- **`?admin_edit=1`** → legacy (modifica struttura schema, full-featured).
- instance-key passata alla shell; navigator + toolbar-actions caricati.
- **Verificato E2E** (pantedu.eu): default=unified, legacy=storico, admin_edit=legacy,
  save compilation 200, modal TeX/PDF, 0 errori console su 6 template.

Eventuale rimozione del path legacy: solo dopo periodo di osservazione in prod.

## Audit funzionalità modal TeX/PDF (2026-05-26, commit 71ab728)

Verificate tutte le funzioni del modal in modalità unificata `risdoc-template`
(E2E su pantedu.eu, screenshot + console.log). Bug trovati e risolti:

1. **Salva TEX → HTTP 500** `Data truncated for column 'kind'`. La colonna
   `risdoc_teacher_overrides.kind` (migration 006) era `ENUM('html','tex','css',
   'json','image')` e nessuna migration la estese sul DB **live** — solo
   `schema.sql` (install puliti) includeva già `texCommon`,`schema`. Fix:
   **migration 068** (`MODIFY` idempotente). Verificato: save → 200.
2. **GeoGebra non funzionava per modelli/custom**: l'attach postava a
   `/api/verifica/{id}/geogebra-attach` (id non è una verifica). Fix: fuori da
   mode `verifica` il client inserisce il marker `\fmgeogebra{base64}{label}`
   nel buffer; il `GeoGebraTexPreProcessor` (ora invocato anche in
   `TexFilesController::compilePdf` e `TeacherContentController::compilePdf` via
   `applyGeogebraPreprocess()`) lo converte in `\includegraphics` + PDF al
   compile. Verificato: editor GGB apre + compile col marker → PDF 200 (~97KB).
3. **Legenda**: il glifo "comune" era `·` = identico al separatore → invisibile.
   Riscritta come lista a griglia costruita da `STATUS_ICON` (DRY), glifo `○`.
4. **Nessun feedback toast su `/risdoc/view`**: `ToastManager` non caricato
   (shell unificata, no bootstrap) → `ensureToast` fa fallback sulla status bar.
5. **Sidebar file-click**: verificato **funzionante** (non più rotto) — era una
   regressione pre-unificazione.

Sweep altri bottoni (zoom, page nav, Ricompila, Compare, copy/clear log): OK,
0 errori. **Nota cosmetica non risolta** (fuori scope, nessun errore): il select
engine è ignorato in mode risdoc-template (il compile-pdf usa sempre il default
server pdflatex, non `State.engine`).

## Parità shell + barra intestazione (2026-05-26)

Scoperto che la "distinzione modelli vs custom" residua NON era nei componenti
(già unificati) ma nel **rendering di pagina**: il branch unificato di
`TemplateViewController` ritornava una pagina NUDA (solo `risdoc-tokens.css`),
bypassando `views/layout/app.php` → `head.php` (che carica `main.bundle.css` +
sidebar/chrome). Sintomi: topbar non stilizzata, logo SVG VSCode 800×800, niente
sidebar. Fix (commit 2a46927): il branch unificato ora rende dentro `app.php`
come il legacy → stesso CSS/sidebar/topbar. + logo vincolato inline (54289ea).
Con `app.php` arriva anche `bootstrap` (bundle) → doppia `customElements.define`
(raw `index.js` + bundle via `fm-pt-document`) → guard idempotente su 15
componenti risdoc (f472120).

**Barra Intestazione e selettori anche per i CUSTOM (dc34971):** `fm-pt-document`
(custom) monta `<fm-risdoc-section-header>` in cima — STESSO componente dei
modelli (selettori sync con sidebar `#sel-iis/cls/mater`, checkbox "Includi
intestazione istituto" → `metadata.includeHeader`, adapter load/saveIncludeHeader).
Verificato E2E: header+checkbox+selettori presenti su modello E custom, 0 errori.

## Topbar centralizzata (2026-05-26, commit 930c6e7)

I pulsanti COMUNI dei modelli ora vengono dallo STESSO `fm-pt-document::_topbarButtons()`
del custom (Salva, **HTML statico**, TeX/PDF, ZIP, VSCode, Export/Import JSON), resi
da `fm-doc-topbar .buttons`. Azioni modello instradate al motore via
`_onRisdocAction`: toggle→`previewMode` (HTML statico = anteprima client, no
persistenza), save→`saveTeacherCompilation()`, tex→`openVerificaPreview(formState live)`,
zip/vscode→`/api/risdoc/templates/{id}/export`, json→`exportTemplateJson/importTemplateJson`.
I widget SOLO-modelli (istanze, ✏️ Modifica struttura, admin, navigator) passano come
`topbar-extra-html` (trailingHtml). Verificato E2E su modello 16: 8 pulsanti comuni +
Modifica struttura + navigator, toggle/tex funzionano, 0 errori console. Elenchi PT
riusano `Sanitizer::listLabel` (stessa resa LaTeX esercizi); allineamenti full-stack.

**Navigator su entrambi + HTML statico modelli (commit fc6a501):** Navigator
`#section-navigator` ora anche sul custom (trailingHtml in `fm-doc-topbar` variant
custom); `listSections` scansiona `fm-risdoc-template` (modelli) o `fm-pt-document`
(custom). HTML statico modelli FIXATO: `previewMode` era solo load-time → ora
`_setRisdocStatic` inietta stylesheet `data-static` nello shadow (flatten controlli +
hide editing) = vista sanitizzata sola-lettura. Verificato E2E: modello data-static+
navigator 11 voci; custom navigator 2 sezioni in edit; 0 errori. **Parità custom↔modelli
sostanzialmente completa** (shell/CSS, editor/toolbar/sezioni, PtToTex/PtToHtml,
header+selettori, intestazione toggle, allineamenti, elenchi, topbar, HTML statico, navigator).

**Gotcha CF/deploy (vedi memory `reference_vps_deploy`):** `/risdoc/view` carica
moduli ESM RAW → CF li teneva 24h stale; il purge del deploy era rotto (CRLF in
`/etc/pantedu-deploy.env` + solo CSS). Fix: `purge_everything` + strip `\r`. Le
email "deploy fallito" erano i workflow GitHub Actions a11y/lighthouse (flake
transiente codeload), spostati a PR+nightly.

## Fase C — ONEPATH PIENO (card path per i modelli) — PIANO (2026-05-27)

**Richiesta utente:** "onepath per tutto, stile come modelli". Cioè NON più il
motore `fm-risdoc-template` dentro la shell (Fase B), ma i modelli renderizzati
con lo STESSO path a card del custom: `schema → sectionSchemaToPt → body_pt → N
<fm-risdoc-pt-section>`, save via `PT → ptToFields → compilations`
(`RisdocTemplateAdapter.save`). Risultato: UN solo motore, UNA sola UX
(edit/view, topbar), look = **stile modelli**. Elimina le differenze residue
(edit-first vs view-first, "Modifica struttura" vs "HTML statico/HTML").

### Gate di sicurezza (BLOCCANTE) — round-trip lossless
Tool: `tools/validate-onepath-roundtrip.mjs <schema.json>` — fa
`schema+fields → sectionSchemaToPt → PT → ptToFields → fields'` e confronta
per-campo. **DEVE essere ✅ LOSSLESS su TUTTI gli schemi reali** prima del flip.

**Stato attuale (2026-05-27): ❌ ROSSO sul Piano annuale (template 16)** —
34 campi: OK 7, CAMBIATI 9, PERSI 18. Gap da chiudere:

1. **`nota-textarea` → PERSO.** Diventa un PT `block` non nominato; `ptToFields`
   non lo recupera (i block sono contenuto, non `fields[name]`).
   *Fix:* mappare nota-textarea su un nodo PT che porta `name` + rich text
   recuperabile (es. `textField kind="richtext"` o `block` con `fieldName`),
   e `ptToFields` lo rimette in `fields[name]`.
2. **`dynamic-table` → CAMBIATO.** Le righe passano da `{colonna: valore}` ad
   array posizionali `["",""]` → si perde l'associazione colonna→valore.
   *Fix:* il PT `table` deve portare le `columns` (chiavi) + `name`; `ptToFields`
   ricostruisce le righe come oggetti keyed by colonna (non array).
3. **`checkbox-group` con `options_source` → PERSO nel test (pessimista).** Senza
   opzioni materializzate non costruisce items. *Fix gate:* il tool deve passare
   `dynamicOpts` con le opzioni fetchate (come l'app) per misurare il caso reale;
   atteso lossless con opzioni presenti (la selezione è per `value/label`).
4. select / textField / formCheckbox / checkboxGroup statici / accordion: già OK.

### Fasi
- **C1 — Converter lossless + gate permanente.** Sistema `section-to-pt.js`
  (nota-textarea nominato, dynamic-table keyed, checkbox-group options_source) +
  `ptToFields` simmetrico. Rendi `tools/validate-onepath-roundtrip.mjs` un test
  committato che gira su TUTTI gli schemi in `schemas/risdoc/*.json` e FALLISCE se
  un solo campo si perde. **Gate:** verde su 16 + 22-25 + tutti.
- **C2 — Model via card path.** `fm-pt-document source=risdoc-template` monta le
  sezioni da `sectionSchemaToPt(schema, fields, state)` come card
  `<fm-risdoc-pt-section>` (NON più il motore `fm-risdoc-template`); header =
  prima card collassabile (selettori); stato propagato via `fm:value-change`;
  options_source via endpoint ADR-025. Save: `RisdocTemplateAdapter.save` (PT →
  ptToFields → POST /compilations). Engine `fm-risdoc-template` resta dietro
  `?ui=legacy` come fallback.
- **C3 — Stile unico = modelli.** Topbar + UX edit/view uguali per custom e
  modelli, look modello (header collassabile, toolbar completa già fatta).
  *NB:* preferenza topbar precedentemente "custom" → ora "stile come modelli";
  confermare i pulsanti finali in questa fase.
- **C4 — Parità E2E + flip default.** E2E (pantedu.eu, screenshot+console):
  render, selettori, options_source, save **lossless** (ri-apri → stessi dati),
  TeX/PDF, ZIP su 16 + 22-25. Flip default a card-path quando verde; legacy
  fallback osservato in prod prima della rimozione.

### Rischi / rollback
- **Corruzione modelli compilati**: il save card-path scrive compilations via
  ptToFields; se lossy → dati persi. Mitigazione: gate C1 verde su tutti gli
  schemi + C4 verifica reopen-equality su dati reali PRIMA del flip. Rollback:
  `?ui=legacy` (motore intatto) + default revert.
- Non flippare nulla finché il gate non è ✅ su ogni schema.

### SCOPERTA 2026-05-27 (ispezione 60 compilation reali, template 16) — REVISIONE
I valori in `risdoc_compilations.data_json.fields` **NON sono primitivi**: sono
**array PT (blocchi) per-nome-campo**. Es. `profilo_classe → [3 blocchi]`,
`obiettivi_disciplinari → [74 blocchi]`, `uda_table → [3 blocchi]`,
`section_1_… → [9 blocchi]`. Cioè la compilation è già una mappa
`name → PT[]` (a granularità mista: alcune sezioni, alcuni sotto-campi).

**Conseguenza:** il round-trip `schema→PT(piatto)→ptToFields` è la strada
SBAGLIATA: appiattire in un unico `body_pt` e ri-segmentare per nome è
intrinsecamente lossy (i blocchi non hanno confini di campo → `nota-textarea`
e altri si perdono). **Confermato dal gate (18 persi).**

**Approccio CORRETTO (revisione C1/C2): card per-campo keyed by name.**
- `fm-pt-document` in modalità modello monta UNA card `<fm-risdoc-pt-section>`
  per ogni CHIAVE-CAMPO della compilation (= `name → PT[]`), invece di
  appiattire. La struttura/ordine/titoli vengono dallo schema; il contenuto da
  `fields[name]` (già PT).
- Save: `_sectionValues` è GIÀ una mappa `name → PT[]` → si scrive
  direttamente `fields[name] = _sectionValues[name]`. **Lossless per
  costruzione** (nessuna conversione PT→primitivo, nessuna ri-segmentazione).
- `sectionSchemaToPt`/`ptToFields` restano per i selettori/header e per i campi
  primitivi residui (select/textField/formCheckbox), ma il grosso del contenuto
  (note, checkbox-group, tabelle compilate) viaggia come PT keyed-by-name.

**Gate rivisto:** non più "schema→PT→fields lossless", ma **reopen-equality**
su compilation reali: `load(fields) → cards → save → fields'` con
`fields' ≡ fields` (deep-equal) su un campione delle 60 compilation locali.
Questo è il vero test di non-corruzione.

**Stato:** approccio identificato, NON ancora implementato (richiede modalità
"keyed sections" in `fm-pt-document` + adapter save name→PT). Da confermare con
l'utente prima dell'implementazione (cambio architetturale non banale).

### VALIDAZIONE 2026-05-27 (gate `tools/validate-keyed-compilations.mjs`) ✅
Eseguito su TUTTE le 60 compilation reali locali (499 campi):
`fields (name→PT) → sezioni keyed → save verbatim → fields'` ⇒ **identità**.
- **✅ lossless su tutte**, 0 valori non-PT/non-primitivi.
- Struttura confermata: `data_json = { fields: {name→PT[]}, state }`; le chiavi
  sono **1:1 con le sezioni** dello schema (12 chiavi ↔ 12 sezioni nel t16).
→ L'approccio keyed è LOSSLESS PER COSTRUZIONE su dati reali. Via libera a C2.

### SCOPERTA 2026-05-27 #2 (overlap chiavi) — RISTRUTTURAZIONE NECESSARIA
Ispezione PT reale: le chiavi a **livello-sezione** CONTENGONO le sotto-chiavi
a **livello-campo**. Es. `section_1` include i 3 blocchi di `profilo_classe`
(stesse "Sezione Custom MARK_BX" / "MARK_INNER" / "MARK_OUT"). Cioè la
compilation salva **rappresentazioni sovrapposte/ridondanti** (aggregato di
sezione + sotto-campo). Il gate reopen-equality era verde SOLO per identità
(salvataggio verbatim preserva chiavi sovrapposte); ma con **editing reale via
card** (quale card vince tra `section_1` e `profilo_classe`?) si avrebbe
duplicazione/conflitto → **l'approccio keyed NON è lossless in pratica**.

Il motore `fm-risdoc-template` gestisce la sovrapposizione perché **schema-aware**
(sa che il contenuto di `section_1` include `profilo_classe` e instrada gli edit
alle chiavi giuste). Rifarlo a card = ricostruire la logica del motore (grande,
ad alto rischio di corruzione dei dati reali).

**DECISIONE (ristrutturazione):** NON sostituire il motore per i modelli
complessi. Mantenere `fm-risdoc-template` (Fase B: già dentro la shell
`fm-pt-document`, save nativo lossless) e raggiungere "stile/aspetto come
modelli, unico percorso percepito" a livello di **SHELL/UX cosmetica**, senza
toccare il modello dati delle compilation:
- topbar identica (set pulsanti + label) per custom e modelli;
- allineare edit-first vs view-first (scelta UX unica) a livello shell;
- header/selettori/toolbar già unificati.
Onepath card-path resta valido SOLO per i modelli a sezioni PT omogenee NON
sovrapposte (semplici), se mai servirà; per i complessi il motore è la verità.

### C2-FULL — onepath pieno anche sui complessi (scelta utente 2026-05-27)
L'utente conferma: onepath per TUTTI, apertura **edit-first** per entrambe.
Accettato che è un progetto grande + gated su validazione editing reale.

**Modello dati del motore (caratterizzato, `fm-risdoc-template._mergeValuesIntoSchema` riga 905):**
- chiave campo = `s.name` se presente, altrimenti `"section_"+slug(s.title)`.
- `section_<slug>` (pt_unified) = PT **autoritativo dell'INTERA sezione**
  (header + tabelle + checkbox + note + sotto-campi).
- I sotto-campi nominati (es. `profilo_classe`) hanno una chiave PROPRIA che è
  una **sotto-fetta ridondante** della sezione → **overlap**. Sono potenzialmente
  derivabili dalla sezione, ma vanno verificati come read-authoritative altrove.

**Requisiti per il flip (TUTTI obbligatori, nessuno saltabile):**
1. **Card = sezione autoritativa** (`section_<slug>` / `s.name` a livello sezione),
   NON i sotto-campi ridondanti. Una card per sezione pt_unified.
2. **Editing round-trip gate (REALE, non identità):** harness che, per ogni
   compilation reale, applica un edit rappresentativo su una card, salva via
   card-path, e confronta il `fields` risultante con quello prodotto dal MOTORE
   per lo STESSO edit. Deve combaciare (inclusa gestione delle chiavi ridondanti:
   o derivate coerentemente o lasciate intatte senza divergenza). **NESSUN flip
   finché verde su tutte le 60 compilation.**
3. **Chiavi ridondanti** (`profilo_classe` & co.): decidere se (a) derivarle dalla
   sezione al save (richiede sapere lo slice esatto) o (b) non scriverle e
   verificare che nessun consumer le legga come fonte. Gate #2 lo prova.
4. **Selettori/options_source/state**: invariati (header card + state).
5. Motore resta `?ui=legacy`; flip default solo a gate verde + E2E parità.

**Stato:** spec completa, NON implementato. È un build dedicato (reimplementare
la mappa schema-aware sul card-path + gate editing). De-rischiato: il blocco è
noto e circoscritto. Da affrontare come lavoro a sé, non in coda a una sessione.

### SCOPERTA 2026-05-27 #3 (contenuto GIÀ centralizzato) — riduce C2-full
Studio del motore (`fm-risdoc-template._renderSection`):
- sezioni `pt_unified:true` → rese con `<fm-risdoc-pt-section>` (riga 972) =
  STESSO componente del custom.
- header (`type:"header"`) → `<fm-risdoc-section-header>` (riga 1003) = STESSO
  componente del custom (gradient teal via `--fm-risdoc-header-bg`, token globale).
- Verificato: `.ptdoc` = 900px centrato su ENTRAMBE le pagine; header component
  identico (token condiviso).
→ **Il CONTENUTO è già visivamente centralizzato.** Le rule CSS inline di
  `TemplateViewController` (518-537: `.header`/`.section-header` sticky) targetano
  il DOM LEGACY del motore (`#fm-risdoc-content`, classi `.header`) che nel path
  UNIFICATO (default `fm-pt-document`) NON esiste → sono DEAD per l'unified.

**Residuo "due stili" = solo chrome esterno + TOPBAR:**
- wrapper `.fm-risdoc-view--unified` (modello) vs `.fm-pt-custom-page` (custom):
  entrambi contengono `.ptdoc` 900px centrato → inner identico.
- topbar: modello (engine path) ha "Modifica struttura", custom ha "HTML statico";
  doctype prefix già rimosso (commit a7489f8).
- `body.fm-studio-risdoc` (modello) abilita qualche token/scope non presente sul
  custom.

**C2-full ridotto a:** (1) far renderizzare il modello con la stessa topbar del
custom (`_topbarButtons`, già quasi unificata) eliminando il ramo engine
`_renderRisdocShell`; (2) gestire le poche sezioni NON-pt_unified + il save
overlap (gate editing). Il grosso (componenti sezione/header) è già fatto.

### Ristrutturazione C2 (design concreto, keyed sections) — SUPERATA dalla scoperta #2
1. **`fm-pt-document` modalità modello "keyed"**: invece di montare il motore
   `fm-risdoc-template`, costruisce N card `<fm-risdoc-pt-section>` — UNA per
   chiave-campo della compilation — con `section = { name, title (da schema),
   default: fields[name] (PT) }`. Header/selettori = card collassabile guidata
   da `state`. `_sections` keyed by name (già il modello del custom).
2. **`_mergeSections` (modello)**: produce la mappa `name → PT[]` (NON flat),
   leggendo `_sectionValues` (già name→PT).
3. **`RisdocTemplateAdapter`**: `load()` → `{ sections: name→PT (da fields) +
   titoli/ordine da schema, state }`; `save(map)` → `fields[name]=PT` →
   POST `/compilations` (stesso payload odierno). Niente `ptToFields` per il
   contenuto (solo per i selettori/header se servono primitivi).
4. **options_source / selettori**: lo `state` pilota il fetch opzioni (endpoint
   ADR-025) e il filtraggio; invariato rispetto al motore.
5. **Gate permanente**: `validate-keyed-compilations.mjs` in CI + reopen-equality
   E2E (apri modello → salva senza modifiche → `data_json` identico) prima del flip.
6. **Flip default** solo dopo E2E parità verde; `?ui=legacy` resta fallback.

## Stato lavori correlati (fatto in questa sessione)

- Custom editor a card = UI risdoc (fatto).
- TeX/PDF modal uniforme custom↔risdoc + ZIP + VSCode (fatto).
- Fix compile modelli: texCommon ripristinati, babel italiano via `\babelprovide`,
  immagini nel bundle, **sectionbox bilanciate (stack)** → modelli 22-25 compilano.
- ADR-025: options curriculari dinamiche + override istituzionali (core fatto).
- Permessi `schemas/` nel deploy + CF purge (fatto).
