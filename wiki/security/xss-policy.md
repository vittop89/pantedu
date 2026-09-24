---
tags:
  - documentazione/sicurezza
date: 2026-05-12
tipo: policy
status: attivo
aliases: ["xss", "sanitization"]
---

# XSS Sanitization Policy

> [!info] ADR: [[decisions/ADR-015-xss-sanitization]]
> Plan: [`docs/plans/G24-xss-sanitization.md`](../../docs/plans/G24-xss-sanitization.md)

## Three sanitization layer

Architettura **server-side authoritative** + **client defense-in-depth**:

```
┌──────────┐    ┌─────────────┐    ┌──────────────┐    ┌─────────┐
│ User type│───▶│ Client      │───▶│ Server       │───▶│ Storage │
│ in editor│    │ sanitize    │    │ (no sanit.)  │    │ (raw)   │
│          │    │ (UX hint)   │    │              │    │         │
└──────────┘    └─────────────┘    └──────────────┘    └─────────┘
                                                              │
                  ┌─────────────┐    ┌──────────────┐         │
                  │ Browser     │◀───│ Server       │◀────────┘
                  │ display     │    │ render +     │
                  │ (safe)      │    │ sanitize     │
                  └─────────────┘    └──────────────┘
                                       ↑ authoritative
```

**Storage può contenere payload non sanitizzato** (es. contract legacy). La sanitization a render time è retro-attiva: ogni HTTP response emette HTML pulito.

## Server-side wrappers

| Wrapper | Tool | Path | Apply quando |
|---------|------|------|--------------|
| `HtmlSanitizer::forBlockContent($html)` | `ezyang/htmlpurifier` | `app/Services/Security/HtmlSanitizer.php` | Text block content con inline HTML |
| `HtmlSanitizer::forStrictText($html)` | idem (config strict) | idem | Badge label, category label, titoli |
| `SvgSanitizer::sanitize($svg)` | `enshrined/svg-sanitize` | `app/Services/Security/SvgSanitizer.php` | GeoGebra SVG inline |
| `TikzScriptValidator::sanitize($body)` | Custom | `app/Services/Security/TikzScriptValidator.php` | TikZ script body |

Integration points: `app/Services/ContractRenderer.php::renderBlocks` (3 casi: `text`, `geogebra`, `tikz`).

## Allowlist

### Text block inline (`forBlockContent`)

**Tag**: `b`, `strong`, `i`, `em`, `u`, `s`, `sub`, `sup`, `a`, `span`, `br`

**Attr**:
- `<a href>`: solo `http://`, `https://`, `mailto:` (no `javascript:`, `data:`, `vbscript:`)
- `<a title>`: text plain
- `<span style>`: subset CSS (color, background-color, font-weight, font-style, text-decoration)
- `<span class>`: lascia ma niente exec
- `rel`: `noopener`, `noreferrer`, `nofollow` allowed

**Blocked**:
- Tutti gli `on*` handlers
- `<script>`, `<iframe>`, `<object>`, `<embed>`, `<style>`, `<link>`, `<meta>`, `<form>`, ...
- CSS `expression()`, `url(javascript:)`, `url(data:)`
- URI scheme `javascript:`, `data:`, `vbscript:`

### Strict text (`forStrictText`)

**Nessun markup**. Tutto stripped, solo text.

### SVG (GeoGebra)

**Strip**:
- `<script>` (anche dentro SVG)
- `<foreignObject>` con HTML payload
- `on*` event handlers
- `xlink:href="javascript:..."`, `<a href="javascript:...">`
- `style` attr con CSS expressions / `javascript:` URI (post-pass custom)
- Remote references (`xlink:href="http://...external"`)

**Preserve**: tutti gli elementi SVG geometrici/typografici (path, rect, circle, line, polygon, text, tspan, g, defs, filter, gradient).

### TikZ script body

**Sanitize**: escape `</` literal → `<\/` (preserva semantica TeX, blocca closing prematuro `<script>`).

**Validate** (opzionale, save-time): throws su `</script>` non-escapato (`<script\b` lookahead).

### Dati del server dentro uno `<script>`

I dati per il JavaScript della pagina vanno in un'isola,
`<script type="application/json" …>`, che il browser non esegue, e il codice
che li usa sta nel bundle e la legge: niente dati scritti dentro uno script
eseguibile in linea (revisione architetturale A-19, 23/9/2026). L'isola non
porta il nonce della CSP, che spetta solo agli script dell'applicazione
([[security-notes]], «Security header e CSP»).

Un dato che il server scrive dentro uno `<script>`, isola compresa, si
codifica con
`json_encode(…, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`,
più `JSON_UNESCAPED_UNICODE` se serve. Con `JSON_HEX_TAG` `<` e `>` diventano
`\u003C` e `\u003E`: né `</script` né `<!--` possono chiudere lo script
o cambiare il punto in cui il browser ne cerca la fine. `JSON_UNESCAPED_SLASHES`
senza `JSON_HEX_TAG` è la forma sbagliata: lascia passare `</script>`.

Esempi: l'isola di `views/admin/sections.php`, e
`StudyPageRenderer::JSON_NELLO_SCRIPT` per il file drawio delle mappe nella
pagina di studio (isola `data-fm-mappe-xml`). Lì fino al 23/9/2026 un
`</script>` nel file di una mappa chiudeva lo script, per studenti e ospiti
(revisione architetturale A-3); prova
`tests/Integration/MappeNegliScriptDellaPaginaTest.php`, una mappa piccola e
una grande.

In ingresso, il file di una mappa deve essere un XML drawio
(`App\Services\Maps\FileDrawio`), sia da «Carica file» sia dall'import dei
pacchetti; e il link firmato `/api/maps/dl` lo dà come allegato
`application/octet-stream`, mai come documento del sito. I dettagli e le prove
stanno in [[domains/mappe/mappe-overview]] («Il file della mappa: in ingresso,
nella pagina, nel link firmato»). È il secondo strato: un drawio valido può
contenere `</script>` in un commento o in un CDATA, e a renderlo innocuo nella
pagina è la codifica.

## Client defense-in-depth

`js/modules/security/html-sanitize-client.js` con stessa policy:

```js
import { sanitizeBlockContent, sanitizeStrictText } from "../security/html-sanitize-client.js";

const cleanHtml = sanitizeBlockContent('<a href="javascript:alert(1)">x</a>');
// → '<a>x</a>' (href stripped)
```

Usato in `_buildBlocksFromTextarea` (PRIMA del save) e `_toHtml` BLOCK_RENDERERS (display post-save).

**NON è security boundary** — server applica di nuovo a render. Solo UX hint per ridurre payload in transit (es. paste da fonti untrusted).

## Feature flag

Env `XSS_SANITIZE_ENABLED=false` disabilita istantaneamente. Per:
- Debug emergency in produzione (ripristina output pre-sanitization)
- Testing isolato (vedere render senza filtro)

**Default: ON**. Non disabilitare in produzione regolare.

Client: `window.__FM_XSS_SANITIZE_DISABLED = true` (raro, solo debug DevTools).

## Audit tool

`tools/security/audit_xss_in_contracts.php`:

```bash
php tools/security/audit_xss_in_contracts.php             # human-readable
php tools/security/audit_xss_in_contracts.php --verbose   # con samples
php tools/security/audit_xss_in_contracts.php --json      # machine-readable
```

Scansiona `storage/objects/**/*.contract.json` per pattern noti (script tag, javascript: URI, on* handlers, iframe, ecc). Esce `1` se finding, `0` se pulito.

Da eseguire periodicamente (cron mensile?) per detection di contract corrotti.

## Test

PHPUnit (54 test totali):
- `tests/Unit/Services/Security/HtmlSanitizerTest.php` (28 test, OWASP cheat-sheet)
- `tests/Unit/Services/Security/SvgSanitizerTest.php` (15 test)
- `tests/Unit/Services/Security/TikzScriptValidatorTest.php` (11 test)

E2E Playwright:
- `tests/e2e/g24_xss_sanitization.spec.js` (3 test client)

Integration:
- `tests/Unit/Services/ContractRendererRmTest::renderer_sanitizes_xss_*` (2 test)

## Performance

HTMLPurifier cache definitions in `storage/htmlpurifier-cache/` (gitignored). Primo render contratto: ~30ms overhead (build schema cache). Successivi: <2ms per chiamata.

Run benchmark: `tools/_bench_render_with_sanitize.php` (TODO future).

## Aggiornamento policy

Per aggiungere tag/attr/CSS prop alla allowlist:

1. Update `HtmlSanitizer::buildBlockContentConfig` (server)
2. Update `html-sanitize-client.js` `ALLOWED_TAGS/ATTRS/CSS_PROPS` (client mirror)
3. Aggiungere test PHPUnit + E2E per il nuovo elemento
4. Documentare in questo file
