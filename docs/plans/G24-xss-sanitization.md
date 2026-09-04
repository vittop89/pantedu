# Piano G24 — XSS Sanitization (CRITICAL)

**Branch**: `master_vps` (working)
**Owner**: Operatore
**Stato**: 📋 Bozza — in attesa approvazione
**Aggiornato**: 2026-05-12
**Priorità**: 🚨 CRITICAL (audit G23 identificato 3 injection point ALTA)

---

## 🎯 Obiettivo

Eliminare i 3 XSS injection point identificati nell'audit G23:

1. **Text inline HTML** (`ContractRenderer::renderBlocks` line 752) — `<a href="javascript:...">`, `onclick=`, `<span style="background:url(javascript:)">`
2. **GeoGebra SVG inline** (`:793`) — `<svg><script>alert(1)</script></svg>` valido
3. **TikZ script body** (`:777`) — bypass `</script><script>alert(1)</script>`

Strategia: **defense-in-depth** server-side (rendering boundary) + client-side (capture time).

## 🔬 Threat model

Attaccanti potenziali:
- **Teacher compromesso** (account hijacking) → injection in proprio content visibile a studenti
- **Teacher malicious** (insider threat) → injection in content condiviso pool
- **MITM** (improbabile con HTTPS) → injection in transit

Asset esposti:
- Session cookie studenti (httpOnly mitigato, ma JS può fare XHR autenticati)
- Cross-site request via fetch a `/api/teacher/*` (CSRF guard mitigato ma non al 100%)
- Page redirect (location.href injection)
- Click-jacking via overlay invisibile

## 📐 Architettura proposta

### Pipeline sanitization

```
┌─────────────┐    ┌──────────────┐    ┌──────────────┐    ┌─────────┐
│ User input  │───▶│ Client       │───▶│ Server save  │───▶│ Storage │
│ (editor)    │    │ pre-sanit*   │    │ sanitize     │    │         │
└─────────────┘    └──────────────┘    └──────────────┘    └─────────┘
                                                                  │
                       ┌──────────────┐    ┌──────────────┐      │
                       │ Browser      │◀───│ Server render│◀─────┘
                       │ display      │    │ sanitize     │
                       └──────────────┘    └──────────────┘
```

\* client pre-sanit = UX hint only, NON security boundary

### Tre layer di sanitizzazione (server-side authoritative)

| Layer | Tool | Quando | Cosa |
|-------|------|--------|------|
| **Text inline HTML** | `ezyang/htmlpurifier` | Server save + server render | Allowlist tag/attr/protocol per text blocks |
| **SVG (GeoGebra)** | `enshrined/svg-sanitize` | Server save + server render | Strip `<script>`, `<foreignObject>HTML`, `on*` attrs |
| **TikZ script** | Custom validator | Server save (reject malformed) | Strip/escape `</script>`, no nested `<script>` |

## 📦 Dipendenze

```bash
composer require ezyang/htmlpurifier ^4.17
composer require enshrined/svg-sanitize ^0.21
```

- `htmlpurifier`: standard de-facto, configurabile, cache HTML4 schema
- `enshrined/svg-sanitize`: lightweight SVG sanitizer (~6 anni stable)

## 📋 Roadmap (4 fasi)

### Phase 1 — HTMLPurifier setup + integration

- [ ] `composer require ezyang/htmlpurifier`
- [ ] Crea `app/Services/Security/HtmlSanitizer.php`:
  ```php
  final class HtmlSanitizer {
      public static function forBlockContent(string $html): string;  // text inline
      public static function forBadgeRaw(string $html): string;       // strict (no inline)
  }
  ```
- [ ] Configura `HTMLPurifier_Config`:
  - `HTML.Allowed = "b,strong,i,em,u,s,sub,sup,a[href],span[style|class],br"`
  - `URI.AllowedSchemes = ["http","https","mailto"]`
  - `CSS.AllowedProperties = ["color","background-color","font-weight","font-style"]`
  - `HTML.SafeIframe = false`, `HTML.Trusted = false`
  - Cache definitions in `storage/htmlpurifier-cache/`
- [ ] Update `ContractRenderer::renderBlocks` line 752: chiama `HtmlSanitizer::forBlockContent($content)` PRIMA del `$visible = nl2br($normalized)`
- [ ] Test PHPUnit `HtmlSanitizerTest` con XSS cheat-sheet payloads:
  - `<script>alert(1)</script>` → strippato
  - `<a href="javascript:alert(1)">x</a>` → `<a>x</a>`
  - `<span onclick="alert(1)">x</span>` → `<span>x</span>`
  - `<span style="background:url(javascript:alert(1))">x</span>` → strippato
  - `<b>valid</b>` → `<b>valid</b>` (preservato)

### Phase 2 — SVG sanitize per GeoGebra

- [ ] `composer require enshrined/svg-sanitize`
- [ ] Crea `app/Services/Security/SvgSanitizer.php` wrapper
- [ ] Update `ContractRenderer.php:793` (`elseif ($t === 'geogebra')` block):
  ```php
  $svg = SvgSanitizer::sanitize((string)($b['svg'] ?? ''));
  ```
- [ ] Update server save handler (TeacherContentController) per sanitizzare SVG nel patch BEFORE save (defense-in-depth)
- [ ] Test PHPUnit `SvgSanitizerTest`:
  - `<svg><script>alert(1)</script></svg>` → script strippato
  - `<svg onload="alert(1)">...` → on* strippato
  - `<svg><foreignObject><iframe src="javascript:..."></iframe></foreignObject></svg>` → strippato
  - SVG valido (math notation) → preservato

### Phase 3 — TikZ script validation

- [ ] Crea `app/Services/Security/TikzScriptValidator.php`:
  ```php
  public static function validate(string $body): bool;  // throws if contains </script>
  public static function sanitize(string $body): string; // escape </script>
  ```
- [ ] Update `ContractRenderer::renderBlocks` line 777: chiama `TikzScriptValidator::sanitize($b['script'])` PRIMA dell'output
- [ ] Update `TeacherContentController` save handler: reject patch se TikZ contains `</script>` literal
- [ ] Test PHPUnit `TikzScriptValidatorTest`:
  - `\\begin{tikzpicture}...</script><script>alert(1)</script>` → escape `<\/`
  - `\\begin{tikzpicture}\\node{ok};\\end{tikzpicture}` → unchanged
  - Lo `<\/script>` viene letto come stringa letterale dal browser, no exec

### Phase 4 — Client pre-sanitization (defense-in-depth)

- [ ] Crea `js/modules/security/html-sanitize-client.js`:
  - Usa `DOMPurify` library OR custom wrapper su `DOMParser`
  - `sanitizeBlockContent(html)` → mirror server policy
- [ ] Update `_buildBlocksFromTextarea` per chiamare sanitize sui text blocks PRIMA del save
- [ ] Update `_toHtml` BLOCK_RENDERERS.text per sanitize all'apply post-save (display)
- [ ] **NOTA**: lato client è hint UX. Server è authoritative. Mai trust client sanitization.

### Phase 5 — Migration & testing

- [ ] Audit dei contract esistenti: scan `storage/objects/institutes/*/private/*/eser/*.contract.json` per pattern XSS
- [ ] Script una-tantum `tools/security/audit_xss_in_contracts.php`:
  - Find `<script`, `javascript:`, `onerror=`, `onclick=` in text content
  - Report (no auto-fix, manual review)
- [ ] E2E Playwright: `tests/e2e/g24_xss_sanitization.spec.js`:
  - Inserisci payload `<script>` in editor → save → reload → verifica DOM NON contiene `<script>`
  - Inserisci SVG malicious in GeoGebra → save → reload → verifica DOM pulito
  - Stessa cosa per TikZ
- [ ] Smoke test: contract REALE dell'utente → re-render dopo deploy → confronto visivo no regressione

### Phase 6 — Documentazione

- [ ] `wiki/security/xss-policy.md` — policy + tools + esempi
- [ ] `wiki/changelog/2026-05.md` G24 entry
- [ ] `wiki/decisions/ADR-015-xss-sanitization.md` (motivazioni, alternative, decisione)
- [ ] Update `wiki/_llm-primer.md` map navigazione

## ⚠️ Rischi & trade-off

| Rischio | Mitigazione |
|---------|-------------|
| HTMLPurifier strippa formattazione legittima (es. CSS color speciale) | Allowlist esplicito + test fixtures con tutti i casi UX comuni. Hot-fix se regression. |
| Performance: HTMLPurifier ~10-50ms per render | Cache definitions in storage. Sanitize OUTPUT cached per content+version hash. |
| SVG complessi (GeoGebra) sanitized perdono interattività | Decisione: GeoGebra in editor è interattivo via JS, in render display è static SVG → OK strip script. |
| TikZ legitimi con `\textless/script\textgreater` test edge case | Validator allowlist solo se `<\/script>` literal escapato, non `\textless/script\textgreater` testo |
| Contract storati pre-G24 con payload | Audit script identifica + manual review. Sanitize a RENDER time è retro-attivo automatico (idempotent). |
| Breaking change UI: link `javascript:` rimossi (anche legitimi) | NESSUN link `javascript:` legitimo in app didattica. Allowlist `http(s)://` + `mailto:`. |

## 📊 File toccati (preview)

| File | Phase | Tipo |
|------|-------|------|
| `composer.json` | 1 | Add deps `htmlpurifier`, `svg-sanitize` |
| `app/Services/Security/HtmlSanitizer.php` (NEW) | 1 | Wrapper purifier |
| `app/Services/Security/SvgSanitizer.php` (NEW) | 2 | Wrapper svg-sanitize |
| `app/Services/Security/TikzScriptValidator.php` (NEW) | 3 | Custom validator |
| `app/Services/ContractRenderer.php` | 1,2,3 | Inject sanitize calls in renderBlocks |
| `app/Controllers/TeacherContentController.php` | 2,3 | Patch input sanitize |
| `js/modules/security/html-sanitize-client.js` (NEW) | 4 | DOMPurify wrapper |
| `js/modules/features/checkin-handlers.js` | 4 | _buildBlocksFromTextarea integration |
| `tests/Unit/Services/Security/HtmlSanitizerTest.php` (NEW) | 1 | XSS payloads |
| `tests/Unit/Services/Security/SvgSanitizerTest.php` (NEW) | 2 | SVG payloads |
| `tests/Unit/Services/Security/TikzScriptValidatorTest.php` (NEW) | 3 | TikZ payloads |
| `tools/security/audit_xss_in_contracts.php` (NEW) | 5 | One-time scan tool |
| `tests/e2e/g24_xss_sanitization.spec.js` (NEW) | 5 | E2E browser |
| `wiki/security/xss-policy.md` (NEW) | 6 | Docs |
| `wiki/decisions/ADR-015-xss-sanitization.md` (NEW) | 6 | ADR |
| `wiki/changelog/2026-05.md` | 6 | Changelog entry G24 |

**Diff stimato**: ~800 LOC aggiunte (server + client + test), ~50 LOC modificate in ContractRenderer.

## 🔄 Rollback strategy

Se Phase 1-3 introducono regressioni:
1. Composer downgrade non disponibile (HTMLPurifier non rimuovibile a runtime, ma `composer remove` se necessario)
2. Feature flag env `XSS_SANITIZE_ENABLED=false` per disabilitare temporaneamente:
   ```php
   if (!Config::get("security.xss_sanitize", true)) {
       return $content; // bypass
   }
   ```
3. Test rollout su staging VPS prima di prod
4. Monitoring: log dei sanitize-strip events per detection false positive

## ✅ Definition of Done

1. 3 injection point ALTA priority chiusi (text/SVG/TikZ)
2. PHPUnit test suite passa con XSS cheat-sheet payloads
3. E2E Playwright passa con scenario reali (insert XSS → render → no exec)
4. Audit script su contract esistenti completato + manual review
5. Wiki + ADR + changelog aggiornati
6. Performance no regression: render contract benchmark <50ms aggiuntivi
7. VPS deploy verificato post-push

## 🔗 Reference

- [OWASP XSS Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [HTMLPurifier docs](http://htmlpurifier.org/)
- [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer)
- Audit G23 — `docs/plans/G23-rm-table-unification.md` § ASSE 4 Sicurezza

---

**Estimate totale**: 2-3 giorni full-time (6 phases × ~3-6h).
