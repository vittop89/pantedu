# Operational Steps — Phase Roadmap perf/observability

> Stato: 2026-05-24 — 3 step attivati automaticamente (1+2+4),
> 2 step richiedono azione manuale (3 CF dashboard, 5 trigger condition).
>
> **Allineamento 2026-06-18 — stato dei "TODO future" residui (verificato vs codice)**:
> - ⬜ **AVIF `<picture>` nelle view** (Step 2): NON ancora fatto — nessun
>   `<picture>` né `.avif` referenziato in `views/` (le immagini ottimizzate
>   vengono generate ma non ancora servite via `<picture>`).
> - ⬜ **Grafana dashboard "Pantedu Web Vitals"** (Step 4): NON ancora
>   creata — nessun JSON dashboard committato nel repo (la pipeline di
>   ingestion è attiva, manca solo la dashboard).

## ✅ Step 1 — FM_CRITICAL_CSS=1 — ATTIVO

**Cos'è**: inline `css/critical.css` (above-the-fold) in `<head>` via PHP
+ async preload di `main.css`. Riduce FCP -1.5s su Slow 3G.

**Status**: `.env` ora ha `FM_CRITICAL_CSS=1`. Attivo automaticamente al
prossimo deploy VPS. In locale (XAMPP) richiede restart Apache per
ricaricare config (`.env` parsed via dotenv ad ogni request OK).

**Verifica**: Playwright test su `/teacher/dashboard` → inline `<style>`
presente + `<noscript>` fallback ✅.

**Note**: solo pagine che includono `views/partials/head.php` (admin/
teacher dashboards). Pagine shell (`/login`, `/register`) usano
`shell.php` che ha `<link>` diretto — non sono target di critical CSS
(no above-the-fold content didattico, basso impatto FCP).

## ✅ Step 2 — sharp + npm run build:images — ATTIVO

**Cos'è**: pipeline `tools/build/optimize-images.mjs` converte
`img/sources/*.png|jpg` in WebP + AVIF responsive multi-size, strip
EXIF (GDPR). Riduzione -86 a -89% size.

**Status**: `sharp` installato in package.json. 3 PNG sources copiati
(`logo_LIC1`, `logo_LIC2`, `stemma_REP1`). Build genera 6 file in
`public/img/optimized/` + manifest.json.

**Esempio output**:
```
logo_LIC1.png  35428 B → webp  5036 B (-86%) → avif  5342 B (-85%)
logo_LIC2.png  46651 B → webp  5180 B (-89%) → avif  5544 B (-89%)
stemma_REP1.png 27332 B → webp 3258 B (-89%) → avif 4089 B (-86%)
```

**Deploy integration**: `deploy.sh` chiama `npm run build:images` post
git pull se `img/sources/` esiste. Output `public/img/optimized/`
gitignored (rigenerato ad ogni deploy).

**TODO future** (⬜ aperto al 2026-06-18): usare `<picture>` element
nelle view per servire AVIF con fallback WebP → PNG. Verificato: nessun
`<picture>`/`.avif` ancora presente in `views/`.

## 🔧 Step 3 — Cloudflare Early Hints — DA ATTIVARE (manual user step)

**Cos'è**: Cloudflare risponde `HTTP 103 Early Hints` PRIMA della
response finale. Browser preload CSS/JS bundle parallelo al request
HTML. Riduce LCP -200ms tipico.

**Procedura attivazione** (~5 minuti):

1. Login https://dash.cloudflare.com → seleziona dominio `pantedu.eu`
2. Sidebar sinistra → **Caching** → **Configuration**
3. Toggle **Early Hints** → ON
4. Save
5. Verifica con `curl -I --http2-prior-knowledge https://pantedu.eu/teacher/dashboard`:
   risposta dovrebbe includere `link: ...; rel=preload; ...` 103 prima del 200

**Test post-attivazione**: Lighthouse audit perf score, confronto FCP/LCP
pre vs post (atteso -100/-300ms LCP).

**Trigger**: una sera dopo cena (test richiede sessione browser).

## 🔧 Step 4 — Web Vitals → Grafana — ATTIVO (pipeline) + DA CREARE (dashboard)

**Cos'è**: client web-vitals.js raccoglie LCP/CLS/INP/TTFB/FCP da
50% dei browser (sample rate config) → POST `/api/vitals` → NDJSON in
`logs/web-vitals/YYYY-MM-DD.ndjson` → Promtail → Loki → Grafana.

**Status pipeline**:
- ✅ JS web-vitals.js installato (lazy-load CDN web-vitals@4)
- ✅ AnalyticsController endpoint `/api/vitals` (append NDJSON)
- ✅ `.env` ha `FM_VITALS_ENABLED=1`
- ✅ Promtail scrape job `web-vitals` configurato su VPS:
  ```yaml
  - job_name: web-vitals
    pipeline_stages:
      - json: { expressions: { metric: name, value: value, ... } }
      - labels: { metric:, rating: }
    static_configs:
      - targets: [localhost]
        labels: { job: web-vitals, host: pantedu-vps,
                  __path__: /var/www/pantedu/logs/web-vitals/*.ndjson }
  ```
- ✅ Dir `/var/www/pantedu/logs/web-vitals/` creata mode g+w www-data
- ✅ Loki + Grafana + Promtail active su VPS

**TODO Grafana dashboard** (manual creation — ⬜ aperto al 2026-06-18,
dashboard non ancora committata nel repo):
1. Login https://grafana.pantedu.eu (admin)
2. Dashboards → New → Add visualization → datasource Loki
3. Query esempio panel "LCP P75":
   ```
   quantile_over_time(0.75,
     {job="web-vitals", metric="LCP"} | json | unwrap value [5m])
   ```
4. Salva dashboard come "Pantedu Web Vitals" con panel LCP/CLS/INP/FCP/TTFB

**Trigger setup dashboard**: quando hai 24-48h di dati raccolti
(altrimenti grafico vuoto).

## ⏳ Step 5 — Lighthouse CI warn→error — RIMANDATO

**Cos'è**: promuovere `categories:performance` da `warn` a `error` in
`lighthouserc.json`. PR bloccate se perf score < threshold.

**Status attuale** (`lighthouserc.json`):
```json
"categories:performance":     ["warn",  { "minScore": 0.80 }],
"categories:accessibility":   ["error", { "minScore": 0.95 }],  // già strict
```

**Trigger flip a error**: dopo 2 settimane di run CI con perf score
**stabilmente ≥ 0.85** su tutti i 3 URL (`/`, `/login`, `/accessibility`).
Verifica via `gh run list --workflow=lighthouse --limit 14`.

**Quando**: post-attivazione Step 1+2+3 (sono perf optimization che
alzano lo score). Aspettare ~2 settimane → flip 1 riga + commit.

**Modifica futura**:
```diff
-"categories:performance":     ["warn",  { "minScore": 0.80 }],
+"categories:performance":     ["error", { "minScore": 0.85 }],
```
