---
tags:
  - documentazione/decisione
  - tikz
  - render
  - gdpr
date: 2026-05-06
tipo: architectural-decision
status: accettato
phase: G22.S15
aliases: ["adr-013", "tikz-server-render"]
---

# ADR-013 — Render TikZ server-side via VPS con cache GDPR-compliant

## Status

✅ **Accepted** — implementato in Phase G22.S15 (2026-05-06).
Consolida il VPS introdotto in [[ADR-012-tex-compile-vps]] aggiungendo un
endpoint dedicato per la conversione TikZ → SVG.

> [!note] G22.S15.bis (2026-05-10) — TikZJax deprecato definitivamente
> Il fallback client-side WASM (TikZJax) è stato completamente rimosso
> dal codice attivo. La pipeline VPS è ora l'unica via di rendering per
> il preview editor; errori di compile → blocco rosso inline. Vedi
> [[changelog/2026-05]] entry "Deprecazione TikZJax".

## Contesto

Il preview TikZ del sito gira oggi (pre-G22.S15) lato browser via
**TikZJax** (fork del runtime LaTeX in WebAssembly), con cache filesystem
manuale di `.svg` in cartelle tipo `svg_sc3s/` o `svg/<file>-svg/`:

1. Il browser carica un bundle ~2 MB (`tikzjax-develop/output/tikzjax.js`
   + fonts).
2. Per ogni `<script type="text/tikz">` legacy:
   - prima tenta lookup nella cache filesystem manuale,
   - se trova `.svg` → inline (path-based, NON content-based),
   - altrimenti fallback a TikZJax (compile lato browser, 3-10s,
     errori fragili su pacchetti non supportati).
3. La cache `.svg` deve essere pre-generata offline e caricata via FTP, o
   prodotta da TikZJax lato client + upload via `/tikz/save-svg`.

### Limiti

- **Bundle pesante**: ~2 MB obbligatori solo per pagine con TikZ.
- **TikZJax incompleto**: pacchetti come `pgfplots`, `circuitikz`,
  `tkz-euclide`, `physics` non supportati → blocchi error inline.
- **Cache path-based**: stale se l'autore modifica TikZ source senza
  rinominare il file (`tikz_001.svg` resta col vecchio contenuto).
- **Fedeltà ≠ PDF finale**: lo stesso TikZ può rendersi in due modi
  diversi tra preview (TikZJax) e PDF stampato (`pdflatex` su VPS).

Dopo [[ADR-012-tex-compile-vps]] il VPS Hetzner CAX11 dispone di TeX
Live completo: `pdflatex` + `dvisvgm` sono installati e già usati per
compile PDF di verifiche. Sfruttarli anche per il preview TikZ:

- Output identico al PDF finale (1:1 stesso engine).
- Pacchetti completi (`pgfplots`, `circuitikz`, ...).
- Bundle browser più leggero (TikZJax via).

## Decisione

Adottare **render server-side TikZ → SVG** con cache content-addressable
(SHA-256 del sorgente normalizzato) e isolamento GDPR per docente.

### Pipeline

```
[<script type="text/tikz" data-tikz-scope="public|teacher">]
   │
   ▼ JS tikz-render-client.js
   │  1. normalizeTikz(source)
   │  2. hash = sha256(normalized)
   │  3. GET /tikz/render?hash=H&scope=S
   │     ├─ 200 → inline SVG (cache hit)
   │     └─ 404 → POST /tikz/render {tikz, scope, libraries, ...}
   │
   ▼ PHP TikzRenderController
   │  - public READ: open
   │  - public WRITE: admin only
   │  - teacher: Auth::user()['id'] == teacherId required
   │
   ▼ TikzRenderService
   │  - cache lookup in storage/cache/tikz/{public|teacher_<id>}/
   │  - se MISS → POST tex.pantedu.eu/render-tikz (HMAC)
   │
   ▼ VPS /render-tikz (FastAPI + tikz_render.py)
   │  - wrap in standalone class
   │  - pdflatex -no-shell-escape -interaction=nonstopmode
   │  - dvisvgm --pdf --no-fonts --exact-bbox doc.pdf -o doc.svg
   │
   ▼ image/svg+xml → cache → inline DOM
```

### GDPR — due scope di cache

| Scope     | Cache path                                          | Encryption                     | Auth READ | Auth WRITE         |
|-----------|-----------------------------------------------------|--------------------------------|-----------|---------------------|
| `public`  | `storage/cache/tikz/public/<2hex>/<hash>.svg`       | None (no PII per design)       | open      | admin only          |
| `teacher` | `storage/cache/tikz/teacher_<tid>/<2hex>/<hash>.bin`| Envelope AES-256-GCM (ADR-006) | teacher == owner | teacher == owner |

#### Encryption envelope (teacher scope)

Riusa [`TeacherCryptoService`](../../app/Services/Crypto/TeacherCryptoService.php) di
[[ADR-006-envelope-encryption]]. Format on-disk del blob `.bin`:

```
[1B version=1][1B kv][12B IV][16B GCM tag][... ciphertext SVG ...]
```

**Crypto-shredding (Art. 17 GDPR)**: `TeacherCryptoService::shred(tid)` cancella
la KEK dal DB → tutti i blob `.bin` di quel docente diventano
istantaneamente illeggibili (AES-256 senza chiave = ~2^128 brute-force).
Il filesystem non richiede DELETE: il dato è "shredded" via crittografia.

Per pulizia operativa di disco è disponibile
`TikzRenderService::purgeTeacherCache(tid)` (cron mensile o post-shred).

### Sicurezza VPS

- HMAC-SHA256 + timestamp window 300s (riusa `auth.py` di tex-compile-vps).
- Allowlist server-side di `\usepackage{...}` e `\usetikzlibrary{...}`
  (vedi `tikz_render.py` `ALLOWED_PACKAGES` / `ALLOWED_TIKZ_LIBRARIES`).
- `pdflatex -no-shell-escape` blocca `\write18` arbitrario.
- `-interaction=nonstopmode` evita prompt blocking.
- tmpdir isolato cleanup post-run, `MemoryMax=2G` systemd.
- Limit 1 MB sorgente TikZ in input, 10 MB SVG in output.

### Normalizzazione hash (PHP ↔ JS)

`TikzRenderService::normalize()` (PHP) e `normalizeTikz()` (JS)
**devono** produrre identico output per stesso input — altrimenti la
cache deduplica fallisce (browser e server calcolerebbero hash diversi).

Step:
1. CRLF → LF
2. `<br>`/`<p>`/`<span>`/`<div>`/`<b>`/`<i>`/`<u>` → rimossi
3. Entita HTML: `&nbsp;` → ` `, `&amp;` → `&`, ecc.
4. Trailing whitespace per riga collassato
5. >2 LF consecutivi → 2 LF
6. trim + LF finale singolo

Test parità: vedi `tests/Unit/TikzRenderServiceTest.php`
(da scrivere in follow-up).

## Conseguenze

### Positive

- **Fedeltà 1:1 PDF**: stesso engine `pdflatex` per preview e stampa.
- **Pacchetti completi**: `pgfplots`, `circuitikz`, ecc. funzionano.
- **Cache content-based**: edit del sorgente → hash cambia → ricompila
  automaticamente. Zero gestione manuale.
- **Bundle browser leggero**: TikZJax diventa fallback opzionale; con
  `$fmTikzServerOnly = true` l'asset scompare del tutto (~2 MB risparmiati).
- **GDPR Art. 17 free**: per le immagini docente, nessuna logica extra di
  cancellazione (envelope encryption + crypto-shredding di
  [[ADR-006-envelope-encryption]] coprono per design).
- **Isolamento per docente**: TikZ con label PII di un docente non
  contaminano la cache di altri docenti.

### Negative / Trade-off

- **Latenza prima compilazione**: 1-3s per nuovo TikZ (subsequent =
  cache hit istantaneo).
- **SPOF VPS sul preview**: se VPS down, fallback a TikZJax (lento ma
  funzionante). Con `TIKZ_SERVER_ONLY=1` il fallback sparisce → preview
  non disponibile durante outage VPS.
- **Storage**: cache cresce nel tempo, va prevista pulizia (cron) per i
  TikZ "orfani" (con hash non più referenziato da alcun contenuto).
- **Due implementazioni di normalize**: PHP + JS devono restare in
  sync. Test di parità essenziale.

## Alternative considerate

### A. MathJax-only per tutto

**Pro**: zero VPS, tutto in browser.
**Contro**: MathJax NON supporta TikZ (è solo formule). Non e' una
soluzione per i diagrammi.
**Verdetto**: scartata.

### B. TikZJax + cache filesystem migliorata

**Pro**: niente VPS aggiuntivo.
**Contro**: TikZJax incompleto su pacchetti chiave; bundle 2MB; cache
path-based fragile.
**Verdetto**: scartata. È lo stato pre-G22.S15.

### C. Render at-write-time (job async sul save)

Compilare l'SVG durante il save dell'esercizio, salvarlo definitivo.

**Pro**: zero latenza al primo accesso utente.
**Contro**: latenza percepibile sul save (peggio per UX docente),
storage permanente fisso anche per TikZ poco usati, blocco del save se
VPS fallisce.
**Verdetto**: scartata. Strategia "lazy on first access + cache" e' più
flessibile e robusta.

### D. Render server-side (scelta adottata)

✅ **Verdetto**: trade-off ottimale UX docente + costo + GDPR.

## File toccati

| File | Tipo | Ruolo |
|------|------|-------|
| `tools/tex-compile-vps/app/tikz_render.py` | nuovo | Python wrapper pdflatex+dvisvgm |
| `tools/tex-compile-vps/app/main.py` | mod | endpoint POST /render-tikz |
| `app/Services/TexCompile/TikzRenderClient.php` | nuovo | client HMAC PHP |
| `app/Services/Tikz/TikzRenderService.php` | nuovo | cache + envelope encryption |
| `app/Services/Tikz/TikzRenderException.php` | nuovo | log compile fail |
| `app/Controllers/TikzRenderController.php` | nuovo | endpoint /tikz/render |
| `app/Config/tex_compile.php` | mod | sezione `tikz_render` |
| `routes/web.php` | mod | GET/POST /tikz/render |
| `js/modules/editor/tikz-render-client.js` | nuovo | hash+fetch+fallback |
| `js/modules/editor/latex-render.js` | mod | delega a tikz-render-client |
| `views/partials/_exercise_assets.php` | mod | TikZJax solo come fallback |
| `storage/cache/tikz/.gitignore` | nuovo | cache dir |

## Note operative

### Disabilitazione integrazione (rollback)

Lasciare `TEX_COMPILE_ENDPOINT` vuoto in `.env` → `TikzRenderService::createDefault()`
ritorna null → `/tikz/render` POST risponde 503; il JS cade su fallback
TikZJax (se asset caricato).

### Disabilitazione TikZJax bundle

Impostare `$fmTikzServerOnly = true` nel controller che renderizza la
pagina (oppure cablare via env in bootstrap.php). Il `<script>` di
tikzjax non viene incluso → bundle ~2 MB più leggero. Tradeoff: in caso
di VPS down, TikZ non vengono renderizzati (fallback assente).

### Pulizia cache

```bash
# Una-tantum, pulisce TikZ orfani > 90 giorni atime
find storage/cache/tikz/public -type f -name '*.svg' -atime +90 -delete
find storage/cache/tikz/teacher_*/ -type f -name '*.bin' -atime +90 -delete
```

Cron mensile raccomandato in `tools/cron/cleanup-tikz-cache.sh`
(da scrivere in follow-up).

### Crypto-shredding di un docente

Quando viene eseguito `TeacherCryptoService::shred(tid)` (Art. 17
GDPR), eseguire ANCHE:

```php
$svc = TikzRenderService::createDefault();
$svc?->purgeTeacherCache($teacherId);
```

per liberare lo spazio disco occupato dai blob `.bin` ormai illeggibili.

## Riferimenti

- Sorgente VPS: `tools/tex-compile-vps/app/tikz_render.py`
- Client PHP: `app/Services/TexCompile/TikzRenderClient.php`
- Service PHP: `app/Services/Tikz/TikzRenderService.php`
- Controller PHP: `app/Controllers/TikzRenderController.php`
- Client JS: `js/modules/editor/tikz-render-client.js`
- ADR base: [[ADR-012-tex-compile-vps]]
- Envelope encryption: [[ADR-006-envelope-encryption]]
- GDPR baseline: [[ADR-007-gdpr-compliance]]
- Changelog entry: [[changelog/2026-05]] (2026-05-06)
