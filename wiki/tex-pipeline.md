---
tags:
  - documentazione/modulo
  - dominio/verifiche
date: 2026-09-04
tipo: modulo
status: finale
aliases: ["tex-pipeline-verifiche", "pipeline tex"]
cssclasses: []
---

# Pipeline TEX — Architettura

Da `contract.json` (sorgente unica) a PDF compilato. Ogni step ha responsabilità ben definite e single-source-of-truth.

## Flusso end-to-end

```
contract.json (storage/objects/institutes/{N}/private/{teacherId}/{eser|verifiche}/*.contract.json)
    │  groups[].items[].{question, options, solution, justification}: Block[]
    │  Block = {type: text|latex|tikz|geogebra|list, content?, items?, ...}
    ▼
[1] ContractRenderer.renderBlocks($blocks, $section)         (PHP, server-side)
    │  ─ section ∈ {question, solution, justification, options}
    │  ─ emette HTML semantico:
    │     • text  → <span class="fm-text" data-raw="...">
    │     • latex → <span class="fm-latex" data-raw="\(...\)">
    │     • tikz  → <script type="text/tikz" data-tex-packages="..." data-tikz-libraries="...">
    │     • geogebra → <span class="fm-geogebra-wrap" data-ggb-base64="..." data-ggb-label="...">
    │     • list  → <ol|ul class="fm-dsa-li-list" data-dsa-section="<sec>">
    │              Solo section="question": ogni <li> riceve <span class="fm-dsa-li-buttons">F/GF</span>
    ▼
HTML reso nel browser dentro .collex / .sol / .giustsol / .giustifica
    │  
    │  ─ TikZ rendering (lazy): tikz-render-client.js sostituisce <script>
    │    con <svg data-tikz-body="<urlenc>"> via VPS /compile (cache hit comune).
    ▼
[2] dom-block-extractor.js (js/modules/core/, condiviso)    (JS, client-side)
    │  ─ extractItemHtml(.collex-item) → {html, sol}
    │  ─ collectRawNodes(nodes): TikZ rendered → <script>; lista → outerHTML
    │  ─ Selector: .fm-text|fm-latex|fm-badge[data-raw], svg[data-tikz-hash],
    │    script[type^=text/tikz], .fm-geogebra-wrap, .fm-dsa-li-list
    │  ─ Filter `closest('.fm-dsa-li-list')`: skip nodi nested (parent emette outerHTML)
    │  ─ Partizione problem vs sol via `closest('.sol, .giustsol, .giustifica')`
    │
    │  Caller: topbar-modern.js (SalvaTEX)
    │          verifiche-print-ui.js (Stampa verifica panel)
    ▼
JSON payload {problems:[{items:[{html, solution, points, includeSolution}]}]}
    ▼ POST /api/verifica/save-tex(-batch)  oppure  /teacher/print
[3] Selection.fromArray($payload)                            (PHP)
    │  Validazione + mapping selectedIIS/CLS/MATER → Selection
    ▼
[4] TexBuilder.buildFlat($sel, $variant, $opts)              (PHP)
    │  ─ build(MODE_FLAT) → BuildResult con N file (texCommon/, griglie/, versioni/)
    │  ─ EserciziBodyRenderer.render($sel, $isSol)
    │     │  per ogni problem: TableRenderer.render($problem, $isSol)
    │     │  ↳ Collect: enumerate[label=\Alph*] con \item per item
    │     │  ↳ RMulti:  enumerate[label=\arabic*] + dottedline A B C D
    │     │  ↳ VF:      tabularx con colonne V/F
    │     │  Sanitizer::latexPassthrough($html, $isSol) per html e solution
    │  ─ flatten() → singolo .tex monolitico self-contained
    │  ─ tryFormat($tex): cache hit (sha256) → SKIP roundtrip; miss → POST VPS /format-tex
    ▼
TEX formattato (latexindent applicato, indentation pulita)
    ▼ POST VPS /compile (TexCompileClient)
PDF compilato (pdflatex + TikZ via cache /compile-tikz)
```

## Componenti chiave

### Server-side PHP

| File | Responsabilità |
|------|----------------|
| `app/Services/ContractRenderer.php` | contract → HTML. `renderBlocks($blocks, $section)` markup uniforme: `data-dsa-section` discrimina lato JS senza duplicare logica. |
| `app/Services/TexBuilder.php` | Selection → BuildResult → flat .tex. Auto-format VPS con cache sha256 disk (storage/cache/tex_format/). |
| `app/Services/TexBuilder/Sanitizer.php` | HTML → LaTeX. Placeholder hold/restore protegge `<svg data-tikz-body>`, `<ol>/<ul>/<li>`, `<span class=fm-geogebra-wrap>` da `strip_tags`. |
| `app/Services/TexBuilder/TableRenderer.php` | Item → enumerate/tabularx. Solo 3 layout: Collect (\Alph*), RMulti (\arabic* + dotted), VF (tabularx 2col). |
| `app/Services/Verifica/TemplateFileStore.php` | Read template (verifica.sty, intestazione, griglie). Cache request-scoped statica + invalidate on write/delete. |
| `app/Services/TexCompile/TexFormatClient.php` | HMAC client per VPS /format-tex (latexindent). |
| `app/Services/TexCompile/TexCompileClient.php` | HMAC client per VPS /compile (pdflatex). |

### Client-side JS

| File | Responsabilità |
|------|----------------|
| `js/modules/core/dom-block-extractor.js` | **Single source of truth** estrazione blocchi. Importato da topbar-modern e verifiche-print-ui. |
| `js/modules/features/topbar-modern.js` | SalvaTEX (PDF 2/2). buildSelectionFromDOM → POST /api/verifica/save-tex(-batch). |
| `js/modules/print/verifiche-print-ui.js` | Pannello "Stampa verifica" admin. Allineato a dom-block-extractor (no innerHTML naive). |
| `js/modules/editor/tikz-render-client.js` | Render lazy TikZ via VPS cache. Preserva sorgente in `data-tikz-body` URL-encoded — **anche nel riquadro d'errore**. |
| `js/modules/editor/campo-in-blocchi.js` | Il serializzatore dei campi: dal DOM reso al campo dell'editor (`sorgenteDelContenitore`) e dal campo ai blocchi del contratto (`blocchiDalCampo`). |
| `js/modules/editor/inline-blocks-markers.js` | Collassa/espande i blocchi TikZ e GeoGebra in marcatori `⟨🔍 TikZ #N⟩`. Riconosce lo `<script>` con `type` in qualunque posizione e toglie il `nonce`. |

## Block types per sezione (audit dati reali)

Distribuzione effettiva (4 contract teacher 77, 95 verifiche):

| Section | text | latex | tikz | list | geogebra |
|---------|------|-------|------|------|----------|
| question | 4633 | 3491 | 138 | **212** | 2 |
| solution | 4555 | 5019 | **360** | 0 | 0 |
| justification | 3844 | 3122 | 11 | 0 | 0 |

**TikZ in solution è il caso DOMINANTE** (360 occorrenze). Il client cattura via `closest('.sol, .giustsol')` partition.

**Liste** ad oggi solo in question, ma il refactor `data-dsa-section` permette future liste in solution/justification senza bug.

## Performance ottimizzazioni

| Caching | Layer | Speedup misurato |
|---------|-------|------------------|
| `TexFormatClient` sha256 disk | server | 1174ms → 0ms (1174x su input ripetuto) |
| `TemplateFileStore::read` static | server | ~10 file × 4 varianti per build, ora 1 read each |
| TikZ SVG cache (esistente) | VPS | ~500ms → cache hit istantaneo |

## Test E2E di riferimento

| File | Cosa testa |
|------|-----------|
| `tests/e2e/sanitizer_list_tikz_fix.spec.js` | Pipeline backend completa con HTML scratch |
| `tests/e2e/salvatex_lavatrice_real_dom.spec.js` | Real-flow: DOM contract → buildSelectionFromDOM → save-tex |
| `tests/e2e/g19_49_tex_variants.spec.js` | Variant routing SOL/NOR/DSA/DIS |

## Andata e ritorno di una figura TikZ (regola)

Nel contratto ci sta la **sorgente**. Quello che il browser ne fabbrica per
mostrarla non ci torna mai: l'SVG compilato, il riquadro rosso del render
fallito, lo `<script type="text/tikz">` prima che il client lo sostituisca. Se
ci torna, la sorgente è persa e non si ricostruisce.

Quando il docente apre l'editor su una voce, una figura può trovarsi in **tre**
stati, e tutti e tre devono ridare lo stesso blocco `tikz`:

| Stato | Nel DOM | Quando |
|---|---|---|
| non reso | `<script type="text/tikz">` | gruppo chiuso (render pigro), o figura appena inserita |
| reso | `<svg data-tikz-tagopen data-tikz-body>` (o un `<div>` che lo avvolge) | compilazione riuscita |
| in errore | `<div class="fm-tikz-error-messages-block" data-tikz-tagopen data-tikz-body>` | il servizio TeX non ha risposto, o il disegno ha errori di pdflatex (sezione sotto) |

Il terzo porta gli stessi attributi degli altri **dal 20 settembre 2026**: prima
non li portava, e con il servizio TeX muto la sorgente spariva dal DOM. Chi
tocca `renderAll` non lo separi di nuovo: `_tagOpen`/`_body` si calcolano prima
del `try` proprio perché servono anche al ramo `catch`.

Due trappole misurate, tutte e due nel guasto del 18 settembre 2026 (verifica
75, esercizi 14 e 16):

- **il `nonce` viene prima di `type`.** In produzione la CSP è rigida e fino
  al 23/9/2026 `SecurityHeadersMiddleware::stampScriptNonce` infilava
  `nonce="…"` subito dopo `<script` in ogni script, figure TikZ comprese: il
  tag vero era `<script nonce="…" type="text/tikz" …>`. Un riconoscitore che
  pretende `type` come primo attributo non trovava niente **in produzione e
  solo lì** — in locale, senza CSP strict, funzionava. Dal 23/9 (A-16) le
  figure del contenuto arrivano senza nonce, ma l'HTML salvato prima può
  averlo: il riconoscitore lo regge ancora. Il `nonce` è di quella risposta e
  sola: si toglie (`senzaNonce`), non si propaga.
- **gli attributi si leggono dall'HTML, non dal DOM.** `ContractRenderer` li
  scrive con `htmlspecialchars`, quindi `tex_packages` arriva come
  `{&quot;amsmath&quot;:&quot;&quot;}` e va decodificato: senza, a ogni
  salvataggio la deformazione cresceva di un giro (`&amp;quot;`, poi
  `&amp;amp;quot;`…).

Lato server la rete è `App\Services\Contract\TestoDiPaginaResa`:
`ContractRepository::save()` rifiuta (422, messaggio in italiano) la scrittura
che **introduce** uno di quei marcatori. Guarda solo quello che compare adesso,
così un contratto già rovinato resta modificabile — altrimenti la rete
impedirebbe proprio di ripararlo. Il controllo `contratti` di
`tools/ops/diagnostica.php` conta quelli che già ne portano
(`docs/ops/diagnostica.md`).

I marcatori riconoscono quello che fabbrica il **client**, non un tag qualunque.
Per l'SVG in particolare: il contenuto dei blocchi non passa da `HtmlSanitizer`
prima del salvataggio (lo chiama `ContractRenderer` in fase di resa), quindi il
testo del docente arriva alla guardia com'è — e una regola su `<svg` avrebbe
rifiutato il salvataggio a chi scrive un quesito su SVG. Si guardano invece i
due segni che ce li mette `tikz-render-client.js`: gli attributi `data-tikz-*`
che `renderAll` appende, e il prefisso `tk<6 esadecimali>_<n>_` che
`renameSvgIds` dà a ogni `id` interno per non farli collidere fra due figure
della stessa pagina. Il secondo regge anche se gli attributi si perdono per
strada, e nessuno lo scrive a mano.

Rifiutano allo stesso modo, con la stessa risposta, tutte le rotte che scrivono
un contratto: `quesito/*` e `group/*` (422 `rendered_text`), `clone-to-eser` e
`group/add` compresi. «Duplica in…» (`copiaPerNuovoContenuto`) fa eccezione a
metà: rifiuta quello che **aggiunge** al sorgente, ma non quello che eredita da
un sorgente già rovinato — rifiutare lì non salverebbe nessuna sorgente, quella
è persa da prima, e toglierebbe al docente la duplicazione di quel contenuto. Ciò
che eredita finisce nel registro.

Prove: `tests/js-unit/tikz-nel-salvataggio.test.js` (i tre stati × con e senza
`nonce`, sull'HTML vero di `ContractRenderer` tenuto allineato da
`tests/Unit/Rendering/HtmlDellaVerificaConTikzTest.php`),
`tests/Unit/Contract/TestoDiPaginaResaTest.php` (la rete e la copia, nei due
versi) e `tests/Integration/PaginaResaNelleRotteTest.php` (le rotte).

## Gli errori di pdflatex (regola)

Dal 24/9/2026 **un errore di pdflatex è un errore anche se il PDF esce.**
pdflatex compila in nonstopmode: davanti a una chiave TikZ sbagliata o a un
comando non definito scrive `! …` nel log, rimedia come può e produce lo
stesso un PDF, con il disegno a metà. Prima il servizio giudicava solo
dall'esistenza del PDF: l'anteprima mostrava figure incomplete senza una
parola, il modal «Editor TikZ avanzato» diceva «ok», e il log compariva solo
quando il PDF non usciva affatto — e anche allora erano i primi e gli ultimi
2000 caratteri, cioè il caricamento dei pacchetti.

Chi legge il log e dove:

| percorso | chi trova gli errori | che cosa arriva al browser |
|---|---|---|
| anteprima SVG (`POST /tikz/render`, pagine, editor di `/admin/templates`) | il servizio TeX, `tools/tex-compile-vps/app/errori_tex.py` in `render_tikz` | 422 con `log` (un estratto che comincia dagli errori) ed `errors`; niente SVG e niente cache |
| modal «Editor TikZ avanzato» (`POST /api/tex/compile-adhoc-pdf`) | il PHP, `App\Services\TexCompile\ErroriTex`, sul log intero che il servizio manda con `with_artifacts` | 422 `tex_errors` con `errors`, l'estratto e il PDF parziale in `pdf_b64`; senza PDF 422 `compile_failed`, con `errors` |

Gli errori sono `{line, message, context}`: `line` è la riga del documento
**compilato** (con la classe e i font in testa), non quella dell'editor. La
riga del sorgente la ritrova `js/modules/editor/tex-errori.js` cercando il
testo che TeX cita dopo `l.NNN`, e la scrive nel riquadro: «riga 3 del
sorgente — ! …». Con `errors` non vuoto il client non ritenta il 422
(`ritentaIl422`): è il disegno a essere sbagliato, non un intoppo.

Le due copie del lettore (Python e PHP) si provano sugli stessi log veri
(`tests/fixtures/log-pdflatex/`): `tests/tex/test_errori_tex.py` e
`tests/Unit/Services/TexCompile/ErroriTexTest.php`. Il percorso completo con il
servizio vero sta in `tests/e2e/editor/errori-tex.spec.js` (`@tex`).

Il PDF delle verifiche resta com'era: riuscito se esce il PDF. L'anteprima
della verifica chiede gli artefatti (`with_artifacts=1`, in
`verifica-preview-editor.js`), che portano `errors` e `warnings` del servizio;
come li mostri non è stato riguardato il 24/9/2026.

## Punti di attenzione

- **Markup uniforme dopo refactor**: Tutte le sezioni ora emettono `<ol|ul class="fm-dsa-li-list" data-dsa-section="...">`. Il selettore JS è agnostico alla sezione (cattura sempre); solo i pulsanti F/GF sono question-only.
- **`closest('.fm-dsa-li-list')` filter**: necessario per skip dei nodi nested (il parent list emette outerHTML che include li). NON usare `closest('ol, ul')` generico (matcherebbe `<ol class="collexercise">` legacy).
- **Cache invalidation TexFormat**: deterministica su sha256 del TEX raw. Per invalidare, `rm -rf storage/cache/tex_format/`. Si rigenera al primo save.
- **TKEK envelope**: gli script CLI che chiamano `updateTex` devono essere consapevoli che `ensureTeacherKey` può creare row teacher_keys per teacher mai-cifrato → invalida vecchi blob non-decifrabili. Vedi `tools/_rebuild_tex_for_teacher.php`.
