# ADR-024 — Unificazione chrome documento PT: topbar risdoc-style, toggle render_mode, modal unico

- **Stato:** Accettato
- **Data:** 2026-05-25
- **Supersede (parziale):** estende ADR-022 (`<fm-pt-document>` unificato)
- **Correlati:** ADR-020 (page-doc block types), ADR-021 (centralizzazione, superseded), ADR-023 (CSS cascade)

## Contesto

Dopo ADR-022 la pagina "personalizzabile" (custom `teacher_content`, `layout=custom`)
è renderizzata da `<fm-pt-document>` ma con tre problemi UX segnalati dall'utente:

1. **Doppia topbar.** La pagina custom vive dentro `/studio/...` che mostra la
   topbar studio server-rendered (`_topbar_modern.php` → `.fm-topbar`: TEX/PDF,
   Overleaf, ZIP, VSC, filtri, Editor — azioni della pipeline *verifiche*). Sotto,
   `<fm-pt-document>` rende una **seconda** barra bespoke `.ptdoc__toolbar`. Risultato:
   due barre impilate, stile incoerente con la pagina dei modelli istituzionali
   (`/risdoc/view/{id}`) che ha **una sola** topbar `.fm-risdoc-toolbar`.

2. **Nessun toggle HTML↔interattivo persistito.** Il docente non può scegliere se
   il documento si apra come web-component interattivo o come HTML statico
   sanitizzato (vista "pubblicata" tipo pagine informative FismaPant). Lo stato
   `_mode` era solo in memoria client.

3. **Due modal di creazione divergenti.** Il bottone `+` (`fm-section-add`) apriva
   `openInstanceModal` (fork template, per `risdoc`/`bes`) **oppure** `buildModalHtml`
   (`esercizio`/`lab`/`verifica`/`mappa`). Categorie a compartimenti stagni: non si
   poteva forkare un template dalla categoria Eser, né creare un esercizio in risdoc.

## Decisione

### 1. Topbar unica risdoc-style, encapsulata nel componente
`<fm-pt-document>` rende la **propria** topbar usando le classi globali
`.fm-topbar` / `.fm-topbar__btn` (già caricate da `_topbar-modern.css`), ottenendo
look byte-equivalente alla topbar risdoc senza accoppiarsi al PHP di
`TemplateViewController` (che porta logica schema/istanze/admin irrilevante).

`topbar-modern.js` riceve un **guard**: se in pagina esiste `.fm-pt-custom-page`,
`isContextActive()` ritorna `false` → la topbar studio (azioni verifiche) resta
nascosta. Le pagine custom gestiscono la propria barra. Niente DOM-surgery
cross-component (scelta più pulita di "adottare" la topbar studio: nessun
ripristino fragile su SPA-swap).

### 2. `metadata.render_mode` persistito
Nuovo campo `render_mode ∈ {interactive, html}` in `teacher_content.metadata`.
- Toggle nella topbar (solo `can-edit`). Al click → `adapter.saveRenderMode(mode)`
  (fetch meta → set `render_mode` → POST `/update`).
- Server `renderCustomTopicHtml` legge `render_mode`:
  - `html` + studente (no edit) → solo HTML sanitizzato (`PtToHtml` +
    `HtmlSanitizer::forPageDoc`), nessun componente. Vista "articolo" pulita.
  - `html` + docente → `<fm-pt-document render-mode="html">`: mostra l'articolo
    ma con topbar per ri-switchare a interattivo.
  - `interactive` (default) → componente pieno view/edit.

### 3. Modal di creazione unico cross-categoria
`openInstanceModal` viene assorbito in `buildModalHtml`. Un solo modal ovunque;
`data-fm-type`/`data-fm-pre-category` diventano **pre-selezione**, non gate. Ogni
`+` offre tutte le vie: fork template istituzionale (ruolo D/C/R), PT libero
("Personalizzabile"), stile esercizi, (mappa: link/upload/drawio). Il dispatcher
`sidepage-inline-actions.js` smette di ramificare su `supportsFork`.

## Conseguenze

**Positive:** una sola topbar coerente con i modelli istituzionali; scelta di
presentazione persistita per-documento e cross-device; creazione cross-categoria
(un docente può comporre risorse miste). Codice più pulito: logica documento tutta
nel componente; un solo path modal.

**Negative / rischi:** `render_mode=html` per studenti bypassa il componente →
va garantito che `PtToHtml`+sanitizer producano l'identico markup della view del
componente (stessi CSS `_pt-page-doc.css`). Il modal unico amplia le combinazioni
da testare (fork da categoria non-risdoc).

**Fuori scope (invariato):** il data-model risdoc (schema multi-sezione + fields)
resta distinto dal `body_pt` singolo del custom — ADR-022 §"perché non unificare i
data-model" resta valido. Qui si unifica solo la *chrome* (topbar + modal), non lo
storage.
