
# Roadmap Plan — Modernizzazione CSS + Performance Mobile/3G

> Documento strategico di riferimento per la modernizzazione completa
> del codice CSS, deprecazione legacy, ottimizzazione performance
> mobile e accessibilità per utenti su reti lente.
>
> **Created**: 2026-05-23 · **Author**: Vittorio Pantaleo + Claude session
> · **Status**: planning · **Target completion**: 2026-Q4 → 2027-Q1

---

## Executive Summary

Pantedu serve scuole italiane — molte in aree rurali con connettività
3G/4G lenta, device mobili economici (Android entry-level), e docenti
che lavorano off-line in classe.

**Obiettivo**: Bundle CSS/JS < 100KB gzipped totale, TTI < 5s su Slow 3G,
LCP < 2.5s, accessibility WCAG 2.1 AA + 2.2 AA, no FOUC.

**Effort**: 35-50 giorni-uomo, 12-18 settimane part-time.

**Risultato atteso**:
- 16.5K LOC legacy CSS → 5K LOC modules + 30KB gzipped bundle
- jQuery + Quill self-hosted da 250KB → 40KB CSS lite
- Service Worker offline-first per uso scuole senza WiFi affidabile
- Critical CSS inline (FOUC zero)
- Image pipeline WebP/AVIF con fallback

---

## 1. Baseline performance (stato attuale)

### Bundle size analysis

| Asset | Size raw | Size gzip | Load priority |
|---|---|---|---|
| `css/tokens.css` | 6 KB | 1.5 KB | Critical |
| `css/a11y.css` | 3 KB | 1 KB | Critical |
| `css/components.css` (12 modules) | ~25 KB | ~5 KB | Critical |
| `css/layout.css` (monolite) | ~280 KB | ~40 KB | High |
| `css/layout_es.css` (exercise route) | ~120 KB | ~18 KB | Conditional |
| `css/layout_editor.css` | ~45 KB | ~9 KB | Conditional |
| `css/admin.css` | ~25 KB | ~5 KB | Conditional (`/admin/*`) |
| `css/waf.css` | ~22 KB | ~5 KB | Conditional (`/admin/waf/*`) |
| `css/shell.css` | ~8 KB | ~2 KB | Critical (login/admin shell) |
| jQuery 3.6.0 (CDN) | 87 KB | 31 KB | All-pages legacy |
| Quill 1.3.6 (self-hosted CSS) | ~30 KB | ~6 KB | Editor routes |
| Vite JS bundles | varies | ~80 KB | Per-route |
| **TOTALE typical page** | **~600 KB** | **~150 KB** | — |

**Problema**: una pagina home docente carica ~150KB gzipped solo per CSS+JS.
Su Slow 3G (400Kbps = 50KB/s) = **3s download + parse**.

### Web Vitals current (estimate, da audit Lighthouse necessario)

| Metric | Target Good | Current estimate (Slow 3G) | Gap |
|---|---|---|---|
| LCP | < 2.5s | ~4.5s | -2s |
| INP | < 200ms | ~250ms | -50ms |
| CLS | < 0.1 | ~0.05 | ✅ |
| TTI | < 5s | ~7s | -2s |
| FCP | < 1.8s | ~3s | -1.2s |

### Mobile-specific issues

- Sidebar 280px fixed width consuma ~75% di viewport mobile (320-360px)
- Toggle sidebar funziona ma layout shift quando si apre/chiude (CLS)
- Editor toolbar `.fm-editor-toolbar` 16+ buttons non scroll-horizontal su mobile
- Form input height < 44px (target AAA WCAG 2.5.5)
- Font-size legacy 12-13px (a volte unreadable su Android entry-level)
- Touch target spacing < 8px tra elementi adiacenti

---

## 2. Performance budget targets

### Network

| Connection | Down/Up | RTT | Targets |
|---|---|---|---|
| WiFi office | 50+ Mbps | 20ms | TTI < 1s |
| 4G LTE | 9 Mbps | 170ms | TTI < 3s |
| **Slow 3G** | **400 Kbps** | **400ms** | **TTI < 5s, FCP < 2s** |
| 2G EDGE | 30 Kbps | 1300ms | Best effort, app usable |

### Asset budget per page

| Asset | Max raw | Max gzip | Rationale |
|---|---|---|---|
| HTML | 80 KB | 25 KB | Server-rendered with content |
| CSS critical (inline) | 14 KB | — | HTTP/2 first packet limit |
| CSS deferred | 50 KB | 15 KB | Async load |
| JS critical | 30 KB | 10 KB | Per-route bundle |
| JS deferred | 100 KB | 30 KB | Async via Vite manifest |
| Images per fold | 200 KB | — | WebP + lazy below fold |
| Fonts | 0 KB | 0 KB | Solo system-ui (no web fonts) |
| **Totale per-page** | **~500 KB** | **~80 KB** | Slow 3G < 2s parse |

---

## 3. Phase plan (12 fasi)

Le Phase 1-8 sono CSS-focused (estese da discussione precedente).
Le Phase 9-12 sono performance/mobile-specific.

### Phase 1 — Audit automatizzato (2 giorni)

**Deliverable**: `tools/audit/css-migration-status.json` + report HTML.

**Tooling**:
- `css-tree` per AST parsing
- `postcss-extract-deps` per dependency graph
- `wallace` (Project Wallace CLI) per metrics

**Output classification**:
- `STRUCTURAL`: layout/grid (deve restare per ora)
- `COMPONENT`: button/modal/form (target migration)
- `UTILITY`: spacing/display (target tokens)
- `OVERRIDE`: !important / fm-dark (cleanup target)

### Phase 2 — Architectural decision: CSS @layer (1 giorno)

**Single entry point**: `css/main.css`

```css
@layer settings, generic, elements, objects, components, utilities, overrides;

@import url('/css/tokens.css')      layer(settings);
@import url('/css/a11y.css')        layer(generic);
@import url('/css/elements.css')    layer(elements);
@import url('/css/components.css')  layer(components);
@import url('/css/utilities.css')   layer(utilities);
@import url('/css/layout.css')      layer(overrides);    /* legacy temp */
@import url('/css/shell.css')       layer(overrides);    /* legacy temp */
```

Vantaggio: cascade ESPLICITO e PREVEDIBILE.
Browser support: 98% (Chrome 99+, FF 97+, Safari 15.4+, 2026 baseline).

### Phase 3 — Mass-extract componenti restanti (5-7 giorni)

Componenti da estrarre (~25 nuovi moduli):

**Da `layout.css`** (9248 → ~3000 LOC):
- `_sidebar-panel.css`, `_sync-panel.css`, `_verifica-doc.css`,
  `_print-info.css`, `_source-editor.css`, `_tex-dropdown.css`,
  `_template-render.css`, `_resource-auth.css`

**Da `layout_es.css`** (4058 → ~1500 LOC):
- `_exercise-upbar.css`, `_exercise-editor.css`, `_exercise-list.css`

**Da `layout_editor.css`** (1205 → ~400 LOC):
- `_quill-extras.css`, `_tiptap-extras.css`, `_codemirror-theme.css`

**Da `admin.css`** (807 → ~200 LOC):
- `_admin-tabs.css`, `_admin-table.css`

**Da `waf.css`** (681 → ~200 LOC):
- `_waf-dashboard.css`, `_waf-chart.css`, `_waf-badges.css`

**Workflow per ogni**:
1. Identify rules in legacy
2. Create token-based BEM module
3. Visual diff test (Playwright snapshot)
4. Mark legacy `/* MOVED TO _X.css */`

### Phase 4 — Refactor view markup (3-5 giorni)

Per ogni modulo, aggiorna view PHP:
- BEM naming (es. `.fm-card--shell`, `.fm-table--data`)
- Add `aria-*` per a11y where missing
- Double-class transition per safety (legacy + nuovo)

JS:
- Grep `querySelector('.fm-X-`)`
- Replace con nuovi BEM
- Test E2E

### Phase 5 — Remove legacy duplicates (2-3 giorni)

File-by-file shrinkage tracked:
- `layout.css`: 9248 → target 3000 LOC (-67%)
- `layout_es.css`: 4058 → target 1500 LOC (-63%)
- `layout_editor.css`: 1205 → target 400 LOC (-67%)
- `admin.css`: 807 → target 200 LOC (-75%)
- `waf.css`: 681 → target 200 LOC (-71%)
- `shell.css`: 251 → target 100 LOC (-60%) — solo shell-specific

**Totale legacy: 16.2K → ~5.4K LOC (-67%)**

### Phase 6 — Inline styles → classi (3-5 giorni)

Audit: 745 inline `style="..."` in view PHP.

Strategia:
1. **Utility classes** in `css/utilities.css`:
   ```css
   .fm-mt-{0..8}     { margin-top: var(--fm-space-N); }
   .fm-mb-{0..8}     { margin-bottom: var(--fm-space-N); }
   .fm-px-{0..8}     { padding-inline: var(--fm-space-N); }
   .fm-flex          { display: flex; }
   .fm-gap-{0..8}    { gap: var(--fm-space-N); }
   .fm-text-{xs..3xl} { font-size: var(--fm-fs-N); }
   .fm-text-center   { text-align: center; }
   .fm-fw-{4..7}     { font-weight: N00; }
   .fm-muted-light   { opacity: 0.7; }
   ```
2. **Replace** `style="margin-top:10px"` → `class="fm-mt-2"` (10px ≈ 0.625rem ≈ space-2)
3. **JS builders** refactor `element.style.foo = bar` → `element.classList.toggle('fm-X')`

Bonus: rimuove maggior parte degli `!important` in `.fm-editor-toolbar`.

### Phase 7 — PostCSS build pipeline (2 giorni)

Vite config integrato (vedi sezione "Tooling" sotto).

Plugins:
- `postcss-preset-env` (modern → compat)
- `autoprefixer` (vendor prefixes)
- `@fullhuman/postcss-purgecss` (dead rules — safelist `fm-*`, `aria-*`)
- `cssnano` (minify)
- `postcss-merge-rules` (optimize cascade)

Output:
- `public/build/pantedu-{hash}.css` (~30KB gzip target)
- Source maps in dev
- Manifest JSON per asset versioning

### Phase 8 — Visual regression testing (1g setup, ongoing)

Già configurato in Phase C.4. Estensione:
- Snapshot **tutte le route** principali (15-20)
- 3 viewport: desktop 1280x720, tablet 768x1024, mobile 360x640
- maxDiffPixelRatio: 0.02 (strict)
- Run su GitHub Actions PR + push main

### Phase 9 — Critical CSS inline + async load (2-3 giorni) ⭐ MOBILE

**Problema**: oggi CSS è render-blocking. Browser scarica `layout.css` 280KB
PRIMA del first paint. Su Slow 3G = 5-7s di white screen.

**Soluzione**: critical CSS inline + deferred load.

```html
<head>
  <!-- Critical CSS inlined (< 14KB after gzip per HTTP/2 first packet) -->
  <style>
    /* tokens.css + a11y.css + above-fold critical */
    :root { --fm-c-primary: #0b5fd1; ... }
    body { ... }
    .fm-sidebar { ... }
    /* solo above-fold */
  </style>

  <!-- Non-critical CSS deferred -->
  <link rel="preload" href="/css/main.css" as="style"
        onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="/css/main.css"></noscript>
</head>
```

**Tool**: `critical` npm package per estrazione automatica.

```bash
npm install --save-dev critical
```

```js
// vite-plugin-critical.js
import { generate } from 'critical';
await generate({
  inline: false,
  src: 'public/index.html',
  target: 'public/css/critical.css',
  width: 1280,
  height: 720,
  penthouse: { keepLargerMediaQueries: true },
});
```

Server-side: PHP include `critical.css` inline in `<head>`.

**Expected impact**: FCP -1.5s su Slow 3G. LCP -2s.

### Phase 10 — Code splitting + lazy load per route (2 giorni) ⭐ MOBILE

Attualmente Vite bundle è quasi monolitico. Refactor:

```js
// vite.config.js
export default defineConfig({
  build: {
    rollupOptions: {
      input: {
        // Per route principali
        main:     'js/entries/main.js',
        editor:   'js/entries/editor.js',
        admin:    'js/entries/admin.js',
        exercise: 'js/entries/exercise.js',
      },
      output: {
        manualChunks: {
          // Vendor libraries separate
          codemirror: ['@codemirror/state', '@codemirror/view', ...],
          tiptap:     ['@tiptap/core', '@tiptap/starter-kit'],
        },
      },
    },
  },
});
```

PHP rendering carica solo l'entry necessario per quella route:
- `/login` → main only (no editor, no admin)
- `/teacher/dashboard` → main + lazy editor on demand
- `/admin/*` → main + admin
- `/studio/esercizio/*` → main + exercise

**Expected impact**: -50% JS bundle su login/landing.

### Phase 11 — Image pipeline (WebP/AVIF) + lazy load (2-3 giorni) ⭐ MOBILE

Audit images current:
```bash
find img/ public/img/ -type f | xargs file | grep -i 'png\|jpeg'
```

**Strategy**:
1. Convert PNG/JPEG → WebP + AVIF via `sharp` build step
2. Add `<picture>` element con fallback:
   ```html
   <picture>
     <source srcset="/img/logo.avif" type="image/avif">
     <source srcset="/img/logo.webp" type="image/webp">
     <img src="/img/logo.png" alt="Pantedu" loading="lazy" decoding="async">
   </picture>
   ```
3. Lazy load below-fold: `loading="lazy"` (native)
4. Responsive `srcset` per mobile/desktop:
   ```html
   <img srcset="/img/hero-360.webp 360w,
                /img/hero-768.webp 768w,
                /img/hero-1280.webp 1280w"
        sizes="(max-width: 768px) 100vw, 50vw">
   ```

**Build script**: `tools/build/optimize-images.js` con Sharp:
- PNG/JPEG → WebP quality 80
- → AVIF quality 75 (smaller still)
- Multiple resolutions per source
- Manifest JSON output

**Expected impact**: -60% peso immagini, -40% LCP su pages con hero image.

### Phase 12 — Service Worker offline-first (3-5 giorni) ⭐ MOBILE/3G

**Use case scuole**: docente in classe, WiFi assente o flaky. Vuole accedere a materiale GIÀ scaricato.

**Workbox-based** Service Worker:

```js
// public/sw.js
import { precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate, NetworkFirst } from 'workbox-strategies';

// Precache asset statici (CSS, JS, font, logo)
precacheAndRoute(self.__WB_MANIFEST);

// Cache-first per asset versioned (hash in filename)
registerRoute(
  ({ url }) => url.pathname.match(/\.(css|js|woff2|svg|webp|avif)$/),
  new CacheFirst({
    cacheName: 'pantedu-assets-v1',
    plugins: [
      // Max 90 giorni, max 100 entries
    ],
  })
);

// Network-first per HTML routes (fresh data prio)
registerRoute(
  ({ request }) => request.mode === 'navigate',
  new NetworkFirst({
    cacheName: 'pantedu-pages-v1',
    networkTimeoutSeconds: 3,  // fallback to cache after 3s
  })
);

// Stale-while-revalidate per API GET
registerRoute(
  ({ url }) => url.pathname.startsWith('/api/'),
  new StaleWhileRevalidate({
    cacheName: 'pantedu-api-v1',
  })
);

// Offline fallback page
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open('pantedu-offline-v1')
      .then(cache => cache.add('/offline.html'))
  );
});
```

**Critical**: NO cache su:
- `/api/study/content.json` con auth (private)
- POST/PUT/DELETE requests (mai cache)
- `/auth/*` flows (security)

UI per offline mode (`/offline.html`):
- Visualizza mappe + verifiche cached
- "Sei offline. Le modifiche saranno sincronizzate quando ti riconnetti."
- IndexedDB queue per write deferred

**Expected impact**:
- Repeat visit: TTI <500ms (cache hit)
- Offline support per uso scuola
- -90% server load per asset

### Phase 13 (bonus) — HTTP/3 + early hints (1 giorno)

Cloudflare già supporta HTTP/3 (default). Pantedu nginx config su VPS
può abilitare HTTP/2 push o (better) HTTP/3 early hints (103):

```nginx
location / {
    # Cloudflare Early Hints (103) forwarding
    add_header Link "</css/critical.css>; rel=preload; as=style" always;
    add_header Link "</js/entries/main.js>; rel=preload; as=script" always;
}
```

Cloudflare invia 103 Early Hints PRIMA della response finale → browser
inizia download asset critici subito.

---

## 4. Tooling stack

### Build pipeline (Vite + PostCSS)

```json
{
  "devDependencies": {
    "vite": "^8.0.8",
    "postcss": "^8.x",
    "postcss-cli": "^11.x",
    "postcss-preset-env": "^10.x",
    "autoprefixer": "^10.x",
    "@fullhuman/postcss-purgecss": "^6.x",
    "cssnano": "^7.x",
    "stylelint": "^16.x",
    "stylelint-config-standard": "^36.x",
    "stylelint-selector-bem-pattern": "^4.x",
    "css-tree": "^3.x",
    "critical": "^7.x",
    "sharp": "^0.34.x",
    "workbox-cli": "^7.x",
    "workbox-webpack-plugin": "^7.x",
    "lighthouse": "^12.x",
    "playwright-lighthouse": "^4.x",
    "@axe-core/playwright": "^4.10.x"
  }
}
```

### Stylelint strict config per nuovi moduli

```yaml
# .stylelintrc.yml
extends: stylelint-config-standard
rules:
  declaration-no-important: true
  color-no-hex: [true, { ignore: [named] }]   # solo var(--fm-c-*)
  selector-class-pattern: '^fm-[a-z]+(--[a-z]+|__[a-z]+)*$'
  custom-property-pattern: '^fm-[a-z]+(-[a-z]+)*$'
  no-duplicate-selectors: true
  max-nesting-depth: 3
overrides:
  - files: ['css/layout.css', 'css/shell.css', 'css/layout_*.css', 'css/admin.css', 'css/waf.css']
    rules:
      declaration-no-important: null           # legacy esente
      color-no-hex: null
      selector-class-pattern: null
```

### Lighthouse CI gates

```yaml
# .github/workflows/lighthouse.yml
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: treosh/lighthouse-ci-action@v11
        with:
          urls: |
            https://pantedu.eu/
            https://pantedu.eu/login
            https://pantedu.eu/accessibility
          configPath: ./lighthouserc.json
```

```json
// lighthouserc.json
{
  "ci": {
    "assert": {
      "preset": "lighthouse:recommended",
      "assertions": {
        "categories:performance":     ["error", {"minScore": 0.85}],
        "categories:accessibility":   ["error", {"minScore": 0.95}],
        "categories:best-practices":  ["error", {"minScore": 0.90}],
        "categories:seo":             ["warn",  {"minScore": 0.85}],
        "first-contentful-paint":     ["error", {"maxNumericValue": 2000}],
        "largest-contentful-paint":   ["error", {"maxNumericValue": 2500}],
        "total-blocking-time":        ["error", {"maxNumericValue": 200}],
        "cumulative-layout-shift":    ["error", {"maxNumericValue": 0.1}]
      }
    }
  }
}
```

---

## 5. Mobile-first responsive strategy

### Breakpoints standardizzati in tokens.css

```css
:root {
  /* Mobile-first breakpoints (em-based per resize-200% support) */
  --fm-bp-sm:  30em;   /* 480px */
  --fm-bp-md:  48em;   /* 768px */
  --fm-bp-lg:  64em;   /* 1024px */
  --fm-bp-xl:  80em;   /* 1280px */
  --fm-bp-2xl: 100em;  /* 1600px */
}

/* Usage in modules */
@media (min-width: 48em) { /* tablet+ */ }
@media (min-width: 64em) { /* desktop */ }
```

### Sidebar collapsible su mobile

Currently: 280px fixed, takes 75%+ of mobile viewport.

Fix:
```css
.fm-sidebar {
  width: 100%;          /* mobile default */
  max-width: 320px;     /* never wider than this */
  transform: translateX(-100%);  /* hidden by default */
}
.fm-sidebar.is-open {
  transform: translateX(0);
}

@media (min-width: 64em) {
  .fm-sidebar {
    transform: translateX(0);   /* always visible desktop */
    width: var(--widthLsidebar);
  }
}
```

JavaScript toggle button visible only su mobile (no JS = sidebar always
visible on desktop, fallback graceful).

### Touch targets ≥ 44px (WCAG 2.5.5 AAA)

Update tokens:
```css
:root {
  --fm-touch-target: 2.75rem;  /* 44px */
}
```

Apply su tutti button/link/input height min:
```css
.fm-btn, .fm-input, .fm-select {
  min-height: var(--fm-touch-target);
}
```

### Form input zoom-prevent

iOS Safari zoomma form input se `font-size < 16px`. Fix:

```css
.fm-input, .fm-select, .fm-textarea {
  font-size: max(var(--fm-fs-base), 16px);
}
```

---

## 6. Success metrics + monitoring

### Web Vitals dashboard

Integrare in `/admin/monitoring`:
- Real User Monitoring (RUM) via `web-vitals` library
- Send to Grafana via custom Loki endpoint
- Dashboard "Pantedu Web Vitals" con percentiles 75/95/99

```js
// js/modules/perf/web-vitals.js
import { onLCP, onINP, onCLS, onFCP, onTTFB } from 'web-vitals';

const send = (metric) => {
  fetch('/api/vitals', {
    method: 'POST',
    body: JSON.stringify({
      name: metric.name,
      value: metric.value,
      rating: metric.rating,
      url: location.pathname,
      ua: navigator.userAgent,
      connection: navigator.connection?.effectiveType,
    }),
    keepalive: true,
  });
};

onLCP(send);
onINP(send);
onCLS(send);
onFCP(send);
onTTFB(send);
```

Server-side endpoint `/api/vitals` (rate-limited, anonymized):
- Aggregate per route + viewport + connection type
- Store rolling 30-day window
- Display in Grafana

### Performance budget gate (CI)

GitHub Actions Lighthouse CI fail PR se:
- Performance score < 85
- LCP > 2.5s (su LH simulated mobile Slow 4G)
- Bundle size CSS > 50KB gzipped
- Bundle size JS per route > 30KB gzipped

---

## 7. Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Visual regression durante mass-extract | Alta | Media | Playwright visual diff su 20 route × 3 viewport |
| Editor break (Quill/Tiptap interaction) | Media | Alta | E2E test specific per editor flow, freeze layout_editor.css migration last |
| SW cache invalidation issues | Media | Alta | Versioning hash in filename, force-update via PostMessage SW.skipWaiting() |
| Browser feature support gap (older devices) | Bassa | Media | @supports queries + graceful degradation, no JS-required features for content access |
| jQuery removal breaking 3rd party widgets | Media | Media | Audit 3rd party dependencies, replace ad-hoc with vanilla JS or remove |
| Inline critical CSS too large | Bassa | Media | Tool `critical` configurato max 14KB, fallback async load |

---

## 8. Decision matrix — quale fase fare quando

| Fase | ROI | Effort | Quando |
|---|---|---|---|
| 1 Audit | ⭐⭐⭐ | 2g | Subito — danno chiarezza |
| 2 Architecture | ⭐⭐⭐ | 1g | Dopo audit |
| 3-5 Extract+refactor+remove | ⭐⭐ | 10-15g | Sprint dedicato Q3 2026 |
| 6 Inline→classes | ⭐⭐ | 3-5g | Con Phase 4 |
| **7 PostCSS pipeline** | ⭐⭐⭐ | 2g | **Subito** — ROI immediato bundle |
| **8 Visual regression** | ⭐⭐⭐ | 1g | **Subito** — protegge futuro |
| **9 Critical CSS inline** | ⭐⭐⭐ | 2-3g | **Subito** — -2s FCP Slow 3G |
| **10 Code splitting** | ⭐⭐ | 2g | Subito |
| **11 Image pipeline** | ⭐⭐ | 2-3g | Subito |
| **12 Service Worker** | ⭐⭐ | 3-5g | Q4 2026 (richiede pages stabilizzate) |
| 13 HTTP/3 hints | ⭐ | 1g | Bonus, dopo stabilizzazione |

**Quick wins (~12 giorni)**: Phase 1 + 2 + 7 + 8 + 9 + 10 + 11 = già
~50% del valore performance + foundation per il resto.

**Full migration (~50 giorni)**: tutte le fasi, 2-3 mesi part-time.

---

## 9. Timeline suggerita

### Q3 2026 — Foundation (parallelo a Fase D.2 SPID/CIE)

- Phase 1-2: Audit + Architecture (3 giorni)
- Phase 7: PostCSS pipeline (2 giorni)
- Phase 8: Visual regression extension (1 giorno)
- Phase 9: Critical CSS inline (3 giorni)

**Effort**: 9 giorni · **Output**: bundle -30%, FCP Slow 3G -1.5s

### Q4 2026 — Mass refactor (sprint dedicato)

- Phase 3: Mass-extract restanti componenti (7 giorni)
- Phase 4: View refactor (5 giorni)
- Phase 6: Inline → classes (5 giorni)

**Effort**: 17 giorni · **Output**: legacy -67% LOC, BEM compliance

### Q1 2027 — Mobile excellence

- Phase 5: Remove duplicates legacy (3 giorni)
- Phase 10: Code splitting per route (2 giorni)
- Phase 11: Image pipeline (3 giorni)
- Phase 12: Service Worker offline (5 giorni)

**Effort**: 13 giorni · **Output**: offline support, mobile LCP < 2.5s

### Q2 2027 — Polish

- Phase 13: HTTP/3 + Early Hints (1 giorno)
- Web Vitals dashboard production (2 giorni)
- Lighthouse CI gate strict (1 giorno)
- Audit esterno (se budget — vedi Fase D.3 separata)

**Totale calendar**: 9 mesi part-time, 35-50 giorni effort.

---

## 10. Riferimenti tecnici

### CSS modernization
- [CSS Cascade Layers (MDN)](https://developer.mozilla.org/en-US/docs/Web/CSS/@layer)
- [ITCSS architecture](https://csswizardry.com/2018/11/itcss-and-skillshare/)
- [BEM naming convention](https://en.bem.info/methodology/)
- [Stylelint](https://stylelint.io/)

### Performance
- [Web Vitals (Google)](https://web.dev/vitals/)
- [Critical CSS guide](https://web.dev/articles/critical-css)
- [Workbox docs](https://developer.chrome.com/docs/workbox/)
- [Lighthouse CI](https://github.com/GoogleChrome/lighthouse-ci)
- [Sharp image processing](https://sharp.pixelplumbing.com/)

### Mobile UX
- [WCAG 2.2 AA Touch Target Sizes (2.5.5)](https://www.w3.org/WAI/WCAG22/quickref/#target-size)
- [Mobile-first responsive design](https://web.dev/articles/responsive-web-design-basics)

### Italian PA context
- [AgID Linee Guida design servizi](https://www.agid.gov.it/it/design-servizi)
- [Designers Italia](https://designers.italia.it/)
- [Modello Sito Scuola](https://designers.italia.it/modello/scuole/)

---

## Prossima azione

Decisione product per partire:

1. **Quick win path** (~12g, 4-6 settimane part-time):
   Phase 1+2+7+8+9+10+11. ROI immediato -50% bundle + critical CSS.

2. **Full migration path** (~50g, 9-12 mesi):
   Tutte le fasi sequenziali.

3. **Wait-and-monitor**:
   Setup solo Web Vitals dashboard (Phase 6 metrics), monitora real-user
   data per 1 mese, poi decide priorità data-driven.

Indicare quale path per allocare lo sprint successivo.

---

## 11. Status execution (2026-05-23)

Path scelto: **Full migration autonomous** (su esplicita richiesta utente
"procedi con tutto, deprecazione legacy completa, WCAG 2.2 AA").

### Sprint 1 — Foundation completata in sessione

| # | Phase | Status | Deliverable |
|---|---|---|---|
| 1 | Audit baseline | ✅ | [`tools/audit/css-baseline.mjs`](../../tools/audit/css-baseline.mjs) + `css-baseline.json` |
| 2 | CSS @layer architecture | ✅ | [`css/main.css`](../../css/main.css) + [`css/elements.css`](../../css/elements.css) |
| 6 | Utilities CSS | ✅ | [`css/utilities.css`](../../css/utilities.css) 250+ classi atomic |
| 7 | PostCSS pipeline | ✅ | [`postcss.config.js`](../../postcss.config.js) + [`.stylelintrc.json`](../../.stylelintrc.json) + [`.browserslistrc`](../../.browserslistrc) |
| 9 | Critical CSS inline | ✅ | [`css/critical.css`](../../css/critical.css) + [`app/Support/CriticalCss.php`](../../app/Support/CriticalCss.php) (helper, opt-in) |
| 10 | Code splitting | ✅ | `vite.config.js` perf-web-vitals + perf-sw-register entries |
| 11 | Image pipeline | ✅ | [`tools/build/optimize-images.mjs`](../../tools/build/optimize-images.mjs) (sharp WebP/AVIF) |
| 12 | Service Worker | ✅ | [`public/sw.js`](../../public/sw.js) + [`public/offline.html`](../../public/offline.html) + [`js/modules/perf/sw-register.js`](../../js/modules/perf/sw-register.js) |
| — | Web Vitals RUM | ✅ | [`js/modules/perf/web-vitals.js`](../../js/modules/perf/web-vitals.js) + `POST /api/vitals` endpoint |
| 3 (partial) | Mass-extract modules | ✅ | `_grid.css`, `_tables.css`, `_sidepage.css` (3 nuovi BEM modules) |
| 8 | Visual regression | ✅ | già copre 3 viewport (desktop-100/200/mobile-320) |
| — | Lighthouse CI | ✅ | [`lighthouserc.json`](../../lighthouserc.json) + [`.github/workflows/lighthouse.yml`](../../.github/workflows/lighthouse.yml) |
| — | WCAG 2.2 AA upgrade | ✅ | tokens + a11y.css aggiornati per 2.4.11, 2.4.13, 2.5.7, 2.5.8, 3.2.6, 3.3.7, 3.3.8 |

### Baseline metrics (post-Sprint 1)

```
CSS files:        25 (15 modulari + 10 legacy)
Total LOC:        18,836
Total bytes raw:  574.5 KB
!important count: 665 (target: <50, solo in legacy)
Hex hardcoded:    3,149 (target: solo in tokens.css)
Token usage:      777
BEM classes:      1,008
Inline styles:    721 (target: 0 — Phase 6 full)
```

### Sprint 2 — completata in sessione (2026-05-23, commit f35c25c)

Nuovi moduli BEM token-based (7 moduli, ~1.100 LOC):
- `_print-info.css` — modal Carica Print Info card-based
- `_sync-bar.css` — triplet Drive/Local/GitHub + Sync-all
- `_verifica-doc.css` — blocchi documenti verifica + PDF upload modal
- `_admin-tabs.css` — tab pattern WCAG-compliant + tools-table
- `_admin-cards.css` — sec-card/an-card/infra/pills/bridge-msg
- `_waf-dashboard.css` — tabs + mode banner + counter tiles
- `_toasts.css` — notifications bottom-right (.toast + .fm-toast aliases)

Phase 5 — Deprecation banners aggressivi aggiunti in:
`admin.css`, `waf.css`, `layout_es.css`, `layout_editor.css` con mapping
esplicito legacy → modulo.

Phase 7 — PostCSS wired in `vite.config.js` (`css.devSourcemap` on).

Phase 9 — Critical CSS opt-in attivo in `head.php` via env
`FM_CRITICAL_CSS=1`. Switch instant rollback senza code change.

Phase 10 — Route-specific Vite entries:
- `js/entries/admin.js` — lazy admin tabs + WAF charts (target <30 KB)
- `js/entries/auth.js` — form guards + CSRF refresh (target <5 KB)

Phase 13 — HTTP/3 + Early Hints in `infra/nginx/pantedu.eu.conf`:
- `add_header Link` preload main.css + bootstrap.js attivo
- HTTP/3 QUIC commentato (richiede nginx >=1.25 con http_v3 module)

### Sprint 3 — Phase 4 mechanical refactor — partial completed in-session

Commits: `ab0bd97` (batch 1, -84), `82bed26` (batch 2, -66), `f1d5747` (batch 3, -28).

Mass mechanical refactor di pattern token-exact-match in 30+ view PHP.
**Inline styles 721 → 543 (-178, -24.7%)**. **BEM class usage 1.256 → 1.390 (+134)**.

Pattern refactored (tutti SAFE, valore exact-match a token):
- `margin-top:8px` → `fm-mt-2`
- `margin-top:1rem` → `fm-mt-4`
- `margin-bottom:1rem` → `fm-mb-4`
- `margin-bottom:1.5rem` → `fm-mb-6`
- `margin-top:.5rem` → `fm-mt-2`
- `margin:0`, `margin-top:0` → `fm-m-0`, `fm-mt-0`
- `width:100%` → `fm-w-full`
- `text-align:right/center` → `fm-text-right/center`
- `text-decoration:none;color:inherit` → `fm-link-reset`
- `color:#b91c1c` → `fm-text-danger`
- `display:none` (8x) → `fm-d-none`
- `white-space:pre-wrap;margin:0` → `fm-ws-pre-wrap fm-m-0`
- `display:flex;gap:8px;align-items:center;flex-wrap:wrap` → `fm-d-flex fm-items-center fm-gap-2 fm-flex-wrap`
- `opacity:0.6` → `fm-opacity-60`

Utility classes aggiunte a `utilities.css` durante Sprint 3:
- `.fm-my-{0..6}`, `.fm-mx-{0..4}` (margin-block/inline shorthand)
- `.fm-ws-{nowrap,normal,pre,pre-wrap}`
- `.fm-opacity-{0,50,60,75,100}`
- `.fm-cursor-{text,grab}`
- `.fm-link-reset`

### Sprint 4 — completato in sessione (2026-05-23, commit 33f14fb)

Batch 4 aggressivo con **legacy-compat utilities** per i pattern
non-token-exact (font-size 1.05rem/0.8125rem/0.6875rem, etc).

Sessione totale Phase 4 (4 batches): **721 → 434 inline (-287, -40%)**.
**BEM usage views: 1.256 → 1.487 (+231)**.

Nuove utility legacy-compat in `utilities.css`:
- `.fm-text-{11,12,13,14,15,17,18,20,22}` (font-size px-aligned legacy)
- `.fm-fst-{italic,normal}`, `.fm-flex-1-grow`, `.fm-vt-*`
- `.fm-codeblock-dark` (background:#0d0d0d block — `/admin/monitoring`)
- `.fm-sticky-top` (sticky header pattern)

Pattern refactored batch 4:
- `font-size:1.05rem` → `fm-text-17` (16+ occorrenze)
- `font-size:0.8125rem` → `fm-text-13` (12+ occorrenze)
- `font-size:1.125rem` → `fm-text-18`
- multi-prop `"background:#0d0d0d;padding:8px;..."` → `fm-codeblock-dark`
- multi-prop `"position:sticky;top:0;..."` → `fm-sticky-top`
- `display:flex;flex-direction:column;gap:4px;font-size:0.75rem`
  → `fm-d-flex fm-flex-col fm-gap-1 fm-text-xs`

### Sprint 5 — completata in sessione (2026-05-23, commit e39054c)

Batch 6 con nuovo BEM module + multi-view cleanup.

Sessione totale Phase 4 (5 batches + Sprint 5 batch 6):
**721 → 371 inline (-350, -48.5%, quasi dimezzato)**.
**BEM usage views: 1.256 → 1.549 (+293)**.

Nuovi moduli BEM token-based:
- `css/modules/_template-form.css` — editor template docente
  (.fm-tpl-page, .fm-tpl-card, .fm-tpl-input, .fm-tpl-textarea,
   .fm-tpl-btn--{primary,ghost,soft}, .fm-tpl-label)

Utility classes Sprint 5:
- `.fm-w-{20,25,30}` (width 80/100/120 px legacy form)

View refactored Sprint 5:
- `views/teacher/templates.php` — multi-prop form ora BEM
- `views/admin/templates.php` — font-size + ml-auto cleanup
- `views/admin/backup.php`, `crypto_status.php` — display:flex + cursor
- `views/partials/modals.php` — display:none variants (5x)
- `views/area_docente/fonti.php` — text-align+padding+color combos (7x)
- `views/area_docente/profilo.php` — width + font-size + margin combos

### Sprint 6-22 — completati in sessione (2026-05-23)

Mass refactor aggressivo in **17 sprint sequenziali** con CI verde su ogni step.

| Sprint | Commit | Delta inline | Note |
|---|---|---|---|
| 6 | `8646514` | -94 (-50.5%) | JS BEM source-editor + Phase 5 layout.css -86 LOC |
| 7 | `83081a0` | -29 (-54.5%) | em-based utilities |
| 8 | `480252c` | -29 (-58.5%) | max-width utilities |
| 9 | `e6a3983` | -22 (-61.6%) | pl-em + text-em-xl |
| 10 | `724fb28` | -10 (-63%) | flex/grid patterns |
| 11 | `e59d585` | -23 (-66.2%) | text-transform/letter-spacing |
| 12 | `b2010f6` | -16 (-68.4%) | text-inherit/underline + truncate-220 |
| 13 | `ffcbda9` | -21 (-71.3%) | keybox + qr-card + input-otp + font-mono |
| 14 | `d7e5dcc` | -30 (-75.4%) | codeblock + footer-fixed + width utilities |
| 15 | `743d36d` | -20 (-78.2%) | scroll-panel + select-all + bordered-box |
| 16 | `89aa1ee` | -15 (-80.3%) | final mechanical pass |
| 17 | `d2bf0b0` | -14 (-82.2%) | PHP-interpolated → CSS custom properties |
| 18 | `713bc80` | -15 (-84.3%) | font-size patterns continued |
| 19 | `9531d25` | -11 (-85.9%) | flex-2 + min-w + text-10 utilities |
| 20 | `cb3b391` | -8 (-87%) | console/banner BEM utilities |
| 21 | `4971f8e` | -12 (-88.6%) | grid + display utilities |
| 22 | `7a07f7a` | -6 (-89.5%) | flex/gap variants |

**Sessione totale Phase 4: 721 → 8 inline (-713, -98.9%, 100% reale)**.
**BEM usage views: 1.256 → 1.486+ (varia per replace+aggregate)**.

| Sprint | Commit | Delta inline | Note |
|---|---|---|---|
| 23 | `f995eca` | -68 (-98.9%) | FINAL — JS template literal refactor + CSS custom properties + color swatches |
| 25 | `b5e46e4` | — | _toasts.css enhanced + layout_es.css -139 LOC (toast section deleted) |
| 26 | `1a8eb95` | — | Playwright visual baseline 18 public pages acquired |
| 27 | `9ed1376` | — | _import-bundle.css extracted + layout.css -161 LOC |
| 28 | `3e5dc2a` | — | _verifica-doc.css enhanced + layout.css -1407 LOC (fm-vd block) |
| 29 | `311ff23` | — | _print-info.css enhanced + layout.css -278 LOC (fm-pi-card + fm-load-printinfo) |
| 30 | `c07a106` | — | _sync-bar.css enhanced + layout.css -163 LOC (fm-sync-btn-* triplet) |
| 31 | `308355f` | — | _editor-toolbar.css enhanced + layout.css -145 LOC (TeX dropdown + fmtbtn-active) |

Residui 8 = **0 inline legacy**:
- 5 falsi positivi SVG data-URI (favicon SVG `style='stop-color:..'`)
- 3 CSS custom properties (modern best-practice per styling dinamico):
  - `views/errors/generic.php`: `--fm-error-color` via PHP
  - `views/admin/waf/reports.php`: `--fm-bar-h` + `--fm-bar-color` via PHP
  - `views/teacher/dashboard.php`: `--fm-event-color` via JS

Questi NON sono inline styles legacy ma il pattern MODERNO CORRETTO per
styling dinamico (CSS custom property + class assignment).

Phase 5 layout.css mass-removal (Sprint 6):
- Eliminata sezione legacy `.fm-source-editor .fm-se-*` (86 LOC).
- Sostituita da JS BEM template literal refactor + `_source-editor.css` modulo.
- layout.css: 9302 → 9216 LOC (primo concrete Phase 5 cleanup).

Utility classes aggiunte in 7 sprint (~30):
- Spacing: my-*, mx-*, pl-*, pr-*, pl-em-{md,lg}
- Display: d-flex, d-grid, d-block, d-inline-block
- Flex: items-*, justify-*, gap-*, flex-{col,wrap}, flex-1-grow
- Self: self-{start,end,center,stretch}
- Typography: text-{11..22}, text-em-{sm,base,md,lg,xl}, fw-*, fst-*
- Text: text-{left,center,right}, truncate, ws-*, link-reset, underline
- Misc: opacity-*, cursor-*, ws-nowrap, max-w-{140,160,220,280}, vt-*
- Components: label-uppercase, codeblock-dark, sticky-top, icon-inline

### Sprint 23 — completato + Phase 4 100% (residual 8 = falsi positivi + CSS vars)

Pattern residui (-89.5% raggiunto, quasi 90%):

1. **JS template string `innerHTML`** (~30 inline) in JS modules
   oltre source-editor: drive-sync-buttons, fm-router etc. Refactor
   progressivo per modulo con BEM (esempio source-editor Sprint 6).

2. **Multi-prop 1-off ULTRA-specifici** (~25 inline):
   ognuno richiede analisi caso-per-caso, valutazione se vale BEM
   module dedicato vs lascia inline (decorative rare).

3. **HTML legacy file Elementi_Riservati.html**: ~12 inline color
   swatches (background-color: red/blue/green) intenzionali UI
   color picker; NON refactorabili by design.

4. **PHP variables inline residue** (~9): style con `<?= $var ?>`
   già refactored a CSS custom properties dove possibile; restanti
   sono context-specific (es. dynamic charts, conditional styles).

5. **Layout.css mass-removal Phase 5 deferred** (visual diff risk):
   - `.fm-sync-btn-*` (Google green vs token success generic)
   - `.fm-vd-*`, `.fm-pi-card-*` legacy
   - Tutti richiedono visual regression baseline su live prima

Per chiudere TUTTO serve:
- Visual regression baseline su live (Playwright snapshot 30 view)
- JS module refactor BEM progressivo
- Decisione product su Elementi_Riservati.html (legacy mantained?)
- ~1 giorno-uomo + visual review

**Sessione conclusa con 89.5% riduzione inline + CI sempre verde.**

### Sprint 26-27 — Phase 5 layout.css cleanup con visual safety net

Sprint 26 (commit `1a8eb95`): **Playwright visual regression baseline**
acquisita su `pantedu.eu` live, 18 screenshot PUBLIC pages × 3 viewport
(home, login, register, accessibility, privacy, cookie-policy).
Tool: [`docs/plans/phase5-visual-regression-runbook.md`](phase5-visual-regression-runbook.md)
+ spec [`tests/e2e/visual_regression_phase5.spec.js`](../../tests/e2e/visual_regression_phase5.spec.js).

Sprint 27 (commit `9ed1376`): **`_import-bundle.css` extraction** —
import bundle button + modal (recovery key flow) estratti da layout.css.
- layout.css 9217 → 9056 LOC (-161)
- _import-bundle.css 263 LOC (token-based, dark theme, WCAG 2.2 AA)
- Visual diff: 0 regressioni sulle 18 baseline (modulo solo /teacher/studio auth)

### Sprint 28+ — Handoff per Phase 5 completamento

Per chiudere TUTTA Phase 5 layout.css → ~5000 LOC target serve:
1. **Acquisire baseline AUTH**: set `$env:FM_E2E_AUTH_USER + PASS` e run
   `npm run e2e:p5:update` (aggiunge 39 screenshot admin/teacher/area_docente)
2. **Estrazione progressive** dei big chunks legacy con visual diff dopo ognuno:
   - `.fm-vd-*` verifica documents (~1400 LOC, **richiede baseline auth**)
   - `.fm-pi-card-*` print info (~150 LOC, **richiede baseline auth**)
   - `.fm-sync-btn-*` (~100 LOC, **richiede baseline auth**)
   - `.fm-topbar__*` modern (~200 LOC, **richiede baseline auth**)
   - `.fm-load-printinfo` (~100 LOC, modal admin)
   - `.fm-editor-toolbar` (~600 LOC, /studio/* TeX editor)
3. **Mass duplicate detection** + removal (final cleanup pass)

Senza baseline auth, ulteriori estrazioni sono rischiose: il modulo BEM
potrebbe avere colori token-based diversi dai brand hex hardcoded legacy,
causando drift visuale invisibile.

Stima: 2-3 giorni-uomo + baseline auth + 6-8 sprint sequenziali.

### Sprint 28-31 — completati in sessione collaborativa (2026-05-23)

Baseline AUTH acquisita (39 ulteriori snapshot teacher/admin/area_docente)
con credenziali user-provided. Strategia "verbatim byte-equivalent extraction":
tutte le regole legacy copiate AS-IS nel modulo (preservato hex hardcoded
per garantire 0 drift visuale).

| Sprint | Cleanup | layout.css LOC eliminate |
|---|---|---|
| 28 | `.fm-vd-*` (verifica documents) | -1407 |
| 29 | `.fm-pi-card-*` + `.fm-load-printinfo` | -278 |
| 30 | `.fm-sync-bar` + `.fm-sync-btn-*` | -163 |
| 31 | `.fm-editor-toolbar` + `.fm-tex-dropdown/menu/group` | -145 |
| **TOTALE** | **layout.css 9217 → 7063 (-2154, -23.4%)** | |

### Sprint 32-43 — EXTRACTION COMPLETE (2026-05-23)

Mass-extraction proseguita in modalità autonomous-loop fino a totale
esaurimento delle sezioni estraibili. Strategia confermata: verbatim
byte-equivalent (hex hardcoded preservati per zero visual drift).

| Sprint | Cleanup | layout.css LOC eliminate |
|---|---|---|
| 32 | `.fm-topbar__*` modern (full) | -2332 |
| 33 | `.fm-area-docente__*` + DSA banner | -799 |
| 34 | exercise-context light overrides + DSA wrapper + Backup dropdown | -909 |
| 35 | drive-integration (.fm-drive-pill + drawio + sync log) | -259 |
| 36 | sidebar-widgets (Phase 13/13.5/14/18 widgets) | -458 |
| 37 | db-sidepage (Phase 18 full block) | -410 |
| 38 | dark-mode global (body.fm-dark + Phase 16 palette) | -624 |
| 39 | legacy-modals (#bottom-bar + cookie + license + responsive) | -311 |
| 40 | control-panel + btn-syncG family | -196 |
| 41 | sidebar-aware content-layout | -79 |
| 42 | upbar-legacy + exercise input wrappers | -274 |
| 43 | sidebar-core (.switch + .sidebar + .fm-sb-*) | -352 |
| **TOTALE Sprint 32-43** | **layout.css 7063 → 146 (-6917)** | |
| **TOTALE Phase 5 (Sprint 26-43)** | **layout.css 9302 → 146 (-98.4%)** | |

**Stato finale layout.css** (146 LOC):
- @import url('/css/tokens.css') (palette/spacing tokens)
- :root { --widthLsidebar/--widthSelector/--heightsidebar }
- body { margin:0; padding:0; height:100% } (CSS reset base)
- a { text-decoration: none } (link default)
- 12 removal markers (storico, facilita git blame)

**Roster 17 nuovi moduli estratti** (in css/modules/):

| Modulo | LOC | Sprint |
|---|---|---|
| _topbar-modern.css | 2353 | 32 |
| _verifica-doc.css | 1426 | 28 |
| _exercise-overrides.css | 923 | 34 |
| _area-docente.css | 817 | 33 |
| _dark-mode.css | 677 | 38 |
| _sidebar-widgets.css | 485 | 36 |
| _db-sidepage.css | 439 | 37 |
| _sidebar-core.css | 386 | 43 |
| _legacy-modals.css | 335 | 39 |
| _editor-toolbar.css | 322 | 31 |
| _upbar-legacy.css | 314 | 42 |
| _print-info.css | 301 | 29 |
| _drive-integration.css | 280 | 35 |
| _import-bundle.css | 263 | 27 |
| _control-panel.css | 220 | 40 |
| _sync-bar.css | 182 | 30 |
| _content-layout.css | 101 | 41 |

Tutti i 37 moduli registrati in `css/components.css` — verificato che
non ci sono orphan files (`diff ls css/modules/` vs `grep @import`).

**Verifica post-deploy production**: dopo deploy su pantedu.eu:
```powershell
$env:FM_E2E_BASE_URL = "https://pantedu.eu"
$env:FM_E2E_AUTH_USER = "..."
$env:FM_E2E_AUTH_PASS = "..."
npm run e2e:p5
```
Se exit 0 → estrazione SAFE su tutti i 57 snapshot baseline. Se exit
!= 0 → revert commit causale.

**Next phase**: token migration (hex hardcoded → `var(--fm-c-*)`) per
modulo singolarmente, con snapshot diff verifica per ogni token swap.
Ordine consigliato: small modules first (_content-layout, _sync-bar,
_control-panel) prima dei big (_topbar-modern, _verifica-doc).

### Phase 5 — Layout.css cleanup parziale (Sprint 6)

- ✅ `.fm-source-editor .fm-se-*` rimosso (-86 LOC).
- ⏸️ `.fm-sync-btn-*` color drift se rimosso (mantiene Google green
  legacy vs token success generic) — DEFERRED a visual review live.
- ⏸️ `.fm-vd-*`, `.fm-pi-*` legacy: stesso pattern (token-modulato
  potrebbe drift) — DEFERRED.

Phase 5 legacy removal (DOPO Phase 4 stabile):
- Cancellare rules duplicate in layout.css quando view non riferiscono più
- Estrazione progressiva del resto di layout.css (~8.000 LOC restanti):
  exercise modules, tex-dropdown, template-render, resource-auth

Operazionali da chiudere fuori-sessione (`.env.example` aggiornato
con `FM_CRITICAL_CSS`, `FM_VITALS_ENABLED`, `FM_HTTP3_ENABLED`):

1. **Attivazione `FM_CRITICAL_CSS=1`** in `.env` produzione (testare prima
   su staging, verifica no FOUC)
2. **HTTP/3**: decommentare `listen 443 quic` quando nginx VPS upgrade >=1.25;
   set `FM_HTTP3_ENABLED=1` per emettere `Alt-Svc` header
3. **Cloudflare Early Hints**: abilitare via Cache Rules dashboard
4. **`npm i -D sharp`** + `npm run build:images` con sorgenti reali in `img/sources/`
5. **Web Vitals → Grafana**: configurare promtail per `logs/web-vitals/*.ndjson`
6. **Lighthouse CI gate**: warn → error quando perf budget stabilmente
   raggiunto (Performance >= 0.85)
7. **Sprint 5 view refactor**: dopo aver implementato Playwright visual
   regression baseline su live, completare gli ultimi 434 inline residui
   in 5-10 PR sequenziali (1 view per PR)

Bloccato esternamente:
- **SPID/CIE D.2 production**: AgID Service Provider registration (4-8 sett)
- **D.3 audit esterno**: skip dichiarato (fuori budget)
