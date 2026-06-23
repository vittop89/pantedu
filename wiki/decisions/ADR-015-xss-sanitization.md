---
tags:
  - documentazione/adr
  - sicurezza
date: 2026-05-12
tipo: ADR
status: accettato
aliases: ["xss", "sanitization", "G24"]
---

# ADR-015 — XSS Sanitization end-to-end (G24)

> [!warning] Decision finale: server-side authoritative + client defense-in-depth.

## Context

Audit di sicurezza G23 (12 maggio 2026) ha identificato **3 XSS injection point ALTA priority** in `app/Services/ContractRenderer.php`:

1. **Text block content inline HTML** (`renderBlocks:752`):
   - `<a href="javascript:alert(1)">click</a>` passava through
   - `<span onclick="...">`, `<span style="background:url(javascript:)">` idem
2. **GeoGebra SVG inline** (`renderBlocks:793`):
   - `<svg><script>alert(1)</script></svg>` valido
   - SVG può contenere `<foreignObject>` con HTML payload + `on*` handlers
3. **TikZ `<script type="text/tikz">` body** (`renderBlocks:777`):
   - Body emesso raw senza escape
   - Vector: `\end{tikzpicture}</script><script>alert(1)</script>` chiude prematuramente lo script wrapper

Threat model: teacher compromesso/malicious può injettare in contract proprio → studenti vedono il payload eseguito al render.

## Decision

Implementare sanitization end-to-end via libreria di settore + custom validator:

### Server-side (authoritative)

| Vector | Tool | Class |
|--------|------|-------|
| Text inline HTML | `ezyang/htmlpurifier` ^4.19 | `App\Services\Security\HtmlSanitizer` |
| GeoGebra SVG | `enshrined/svg-sanitize` ^0.22 | `App\Services\Security\SvgSanitizer` |
| TikZ script body | Custom (regex escape) | `App\Services\Security\TikzScriptValidator` |

Integrate calls in `ContractRenderer::renderBlocks` ai 3 injection points.

### Client-side (defense-in-depth)

`js/modules/security/html-sanitize-client.js` con `sanitizeBlockContent()` + `sanitizeStrictText()`. Mirror policy server. Applied in `_buildBlocksFromTextarea` PRIMA del save (UX hint).

**Server è ALWAYS authoritative** — il client è solo UX hint per ridurre payload in transit.

### Feature flag rollback

Env `XSS_SANITIZE_ENABLED=false` disabilita istantaneamente. Per debug emergency, NON per produzione.

## Allowlist

### Text block inline HTML

| Tag | Attr permessi |
|-----|---------------|
| `<b>`, `<strong>` | - |
| `<i>`, `<em>` | - |
| `<u>`, `<s>` | - |
| `<sub>`, `<sup>` | - |
| `<a>` | `href` (solo `http://`, `https://`, `mailto:`), `title` |
| `<span>` | `style` (subset CSS), `class` |
| `<br>` | - |

CSS properties allowed: `color`, `background-color`, `font-weight`, `font-style`, `text-decoration`. Dropped: `expression()`, `url(javascript:...)`, `url(data:...)`.

### Strict text mode

NESSUN tag. Usato per badge labels, category labels, titoli.

### SVG (GeoGebra)

- Strip: `<script>`, `<foreignObject>HTML`, `on*` handlers, `xlink:href="javascript:..."`
- Post-pass custom: strip `style` attr con `javascript:` / `expression()` / `vbscript:` / `url(javascript|data:)`
- Preserve: elementi geometrici, `<text>`, gradients, filters

### TikZ script

- Escape `</` literal in body (innocuo per TeX, blocca closing prematuro)
- `validate()` opzionale throws su `</script>` non escapato (hard reject save-time)

## Alternatives considered

1. **No sanitization (status quo G22)** — REJECTED: XSS attivo nei text blocks
2. **OWASP Java Encoder port** — REJECTED: no PHP port mature
3. **DOMPurify (JS)** server-side via PHP/V8 — REJECTED: dipendenza pesante runtime
4. **HTMLPurifier only** (no SVG sanitizer) — REJECTED: SVG ha vector specifici
5. **Allow tutto, escape solo a render** — REJECTED: client editor mostra HTML rendered, escape rompe UX

## Consequences

### Positive

- 3 XSS injection point CHIUSI (testati con OWASP cheat-sheet)
- Defense-in-depth: server + client + audit tool
- Feature flag rollback istantaneo
- Cache HTMLPurifier in `storage/htmlpurifier-cache/` → overhead <2ms a render

### Negative / Trade-off

- 2 nuove deps composer (htmlpurifier + svg-sanitize): trust chain estesa
- Performance: ~10-50ms overhead a render per contratti con molti text+SVG block (mitigata da cache)
- Possibile regressione UX: link `javascript:` legittimi NON esistono in didattica, ma allowlist conservativa potrebbe strippare CSS nuovi (es. `transform`)
- Contract storati pre-G24 con payload: sanitize a render time = retro-attivo automatico (idempotent, no migration)

### Migration

- Audit tool `tools/security/audit_xss_in_contracts.php` ha scansionato 148 contract: **0 finding**
- Render time sanitize copre tutti i contract esistenti automaticamente
- No migration script necessario

## Test

- PHPUnit: `HtmlSanitizerTest` (28), `SvgSanitizerTest` (15), `TikzScriptValidatorTest` (11) = 54 nuovi test
- E2E Playwright: `g24_xss_sanitization.spec.js` (3 test client)
- Integration ContractRenderer: 2 nuovi test in `ContractRendererRmTest`
- Audit tool: 0 finding su 148 contract esistenti

## References

- Audit G23 — `docs/plans/G23-rm-table-unification.md` § ASSE 4 Sicurezza
- Plan G24 — `docs/plans/G24-xss-sanitization.md`
- [OWASP XSS Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [HTMLPurifier docs](http://htmlpurifier.org/)
- [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) v0.22 (0.21 ha vulnerability advisory)
