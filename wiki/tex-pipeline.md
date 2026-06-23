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
| [`app/Services/ContractRenderer.php`](../app/Services/ContractRenderer.php) | contract → HTML. `renderBlocks($blocks, $section)` markup uniforme: `data-dsa-section` discrimina lato JS senza duplicare logica. |
| [`app/Services/TexBuilder.php`](../app/Services/TexBuilder.php) | Selection → BuildResult → flat .tex. Auto-format VPS con cache sha256 disk (storage/cache/tex_format/). |
| [`app/Services/TexBuilder/Sanitizer.php`](../app/Services/TexBuilder/Sanitizer.php) | HTML → LaTeX. Placeholder hold/restore protegge `<svg data-tikz-body>`, `<ol>/<ul>/<li>`, `<span class=fm-geogebra-wrap>` da `strip_tags`. |
| [`app/Services/TexBuilder/TableRenderer.php`](../app/Services/TexBuilder/TableRenderer.php) | Item → enumerate/tabularx. Solo 3 layout: Collect (\Alph*), RMulti (\arabic* + dotted), VF (tabularx 2col). |
| [`app/Services/Verifica/TemplateFileStore.php`](../app/Services/Verifica/TemplateFileStore.php) | Read template (verifica.sty, intestazione, griglie). Cache request-scoped statica + invalidate on write/delete. |
| [`app/Services/TexCompile/TexFormatClient.php`](../app/Services/TexCompile/TexFormatClient.php) | HMAC client per VPS /format-tex (latexindent). |
| [`app/Services/TexCompile/TexCompileClient.php`](../app/Services/TexCompile/TexCompileClient.php) | HMAC client per VPS /compile (pdflatex). |

### Client-side JS

| File | Responsabilità |
|------|----------------|
| [`js/modules/core/dom-block-extractor.js`](../js/modules/core/dom-block-extractor.js) | **Single source of truth** estrazione blocchi. Importato da topbar-modern e verifiche-print-ui. |
| [`js/modules/features/topbar-modern.js`](../js/modules/features/topbar-modern.js) | SalvaTEX (PDF 2/2). buildSelectionFromDOM → POST /api/verifica/save-tex(-batch). |
| [`js/modules/print/verifiche-print-ui.js`](../js/modules/print/verifiche-print-ui.js) | Pannello "Stampa verifica" admin. Allineato a dom-block-extractor (no innerHTML naive). |
| [`js/modules/editor/tikz-render-client.js`](../js/modules/editor/tikz-render-client.js) | Render lazy TikZ via VPS cache. Preserva sorgente in `data-tikz-body` URL-encoded. |

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
| [`tests/e2e/sanitizer_list_tikz_fix.spec.js`](../tests/e2e/sanitizer_list_tikz_fix.spec.js) | Pipeline backend completa con HTML scratch |
| [`tests/e2e/salvatex_lavatrice_real_dom.spec.js`](../tests/e2e/salvatex_lavatrice_real_dom.spec.js) | Real-flow: DOM contract → buildSelectionFromDOM → save-tex |
| [`tests/e2e/g19_49_tex_variants.spec.js`](../tests/e2e/g19_49_tex_variants.spec.js) | Variant routing SOL/NOR/DSA/DIS |

## Punti di attenzione

- **Markup uniforme dopo refactor**: Tutte le sezioni ora emettono `<ol|ul class="fm-dsa-li-list" data-dsa-section="...">`. Il selettore JS è agnostico alla sezione (cattura sempre); solo i pulsanti F/GF sono question-only.
- **`closest('.fm-dsa-li-list')` filter**: necessario per skip dei nodi nested (il parent list emette outerHTML che include li). NON usare `closest('ol, ul')` generico (matcherebbe `<ol class="collexercise">` legacy).
- **Cache invalidation TexFormat**: deterministica su sha256 del TEX raw. Per invalidare, `rm -rf storage/cache/tex_format/`. Si rigenera al primo save.
- **TKEK envelope**: gli script CLI che chiamano `updateTex` devono essere consapevoli che `ensureTeacherKey` può creare row teacher_keys per teacher mai-cifrato → invalida vecchi blob non-decifrabili. Vedi `tools/_rebuild_tex_for_teacher.php`.
