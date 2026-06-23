# NOTICE — Third-Party Licenses

**pantedu** è distribuito sotto **EUPL-1.2** (European Union Public License v1.2). Vedi [`LICENSE`](LICENSE) per il testo integrale.

Questo file elenca le librerie e i dati di terze parti utilizzati dal software e le rispettive licenze, in conformità con i requisiti di attribuzione delle relative licenze e con le [Linee Guida AGID 2020 rev. 2024](https://www.agid.gov.it/it/design-servizi/riuso-open-source) sull'acquisizione e riuso del software open-source.

Ultimo audit: **2026-05-22** — vedi [`docs/legal/third-party-licenses-audit.md`](docs/legal/third-party-licenses-audit.md) per analisi dettagliata.

---

## ✍️ Autori del software

- **Vittorio Pantaleo** — ideazione, direzione, requisiti, architettura,
  revisione e responsabilità del prodotto. **Titolare del copyright** (EUPL-1.2).
- **Claude Opus 4.7** e **Claude Opus 4.8** (Anthropic) — **stesura del codice
  sorgente**, sotto la guida di Vittorio Pantaleo. Co-autori del codice in senso
  fattuale/di sviluppo (i commit riportano `Co-Authored-By: Claude Opus`).

> Un sistema di IA non può detenere diritti d'autore: il titolare del copyright
> resta la persona fisica (Vittorio Pantaleo). L'accreditamento dei modelli è
> una dichiarazione di **trasparenza sul processo di sviluppo**.

---

## 📦 Backend (PHP / Composer)

| Pacchetto | Licenza | Uso |
|-----------|---------|-----|
| `enshrined/svg-sanitize` | GPL-2.0-or-later | Sanitizzazione SVG upload |
| `ezyang/htmlpurifier` | LGPL-2.1-or-later | Sanitizzazione HTML user-generated |
| `geoip2/geoip2` | Apache-2.0 | Lettura DB GeoIP (formato MMDB) |
| `google/apiclient` + `google/auth` | Apache-2.0 | Integrazione Google Drive |
| `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `guzzlehttp/promises` | MIT | HTTP client |
| `monolog/monolog` | MIT | Logging strutturato |
| `phpseclib/phpseclib` | MIT | Crittografia ausiliaria |
| `psr/*` (cache, log, http-*) | MIT | PSR interfaces |
| `firebase/php-jwt` | BSD-3-Clause | JWT signing |
| `vlucas/phpdotenv` | BSD-3-Clause | Loading variabili `.env` |
| `nikic/php-parser` | BSD-3-Clause | Parsing PHP (tools dev) |
| `symfony/yaml` + `symfony/polyfill-*` | MIT | YAML parsing, polyfill compat |
| `league/uri`, `league/openapi-psr7-validator` | MIT | URI / OpenAPI validation |
| `respect/validation` | MIT | Validazione input |
| `justinrainbow/json-schema` | MIT | JSON Schema validation |
| `maxmind-db/reader`, `maxmind/web-service-common` | Apache-2.0 | Reader formato MMDB |
| `marc-mabe/php-enum` | BSD-3-Clause | Enum polyfill |
| `paragonie/constant_time_encoding`, `paragonie/random_compat` | MIT | Crypto utilities |
| `riverline/multipart-parser` | MIT | Parsing multipart |
| `composer/ca-bundle` | MIT | CA certificate bundle |
| `webmozart/assert`, `graham-campbell/result-type` | MIT | Utility |

**Test/dev (non shipped a runtime)**: PHPUnit, PHPStan, PHP_CodeSniffer (BSD-3 / MIT).

Elenco completo macchina-leggibile: `composer licenses --format=json`.

---

## 🎨 Frontend (JavaScript / npm)

| Pacchetto | Licenza | Uso |
|-----------|---------|-----|
| `@codemirror/*` | MIT | Editor di codice (JSON/syntax highlighting) |
| `@tiptap/*` | MIT | Rich-text editor (consegne esercizi) |
| `prosemirror-*` | MIT | Base ProseMirror per TipTap |
| `@lezer/*` | MIT | Parser CodeMirror |
| `codemirror` | MIT | Bundle CodeMirror v6 |
| `sortablejs` | MIT | Drag & drop UI |

**Test/dev (non shipped a runtime)**: Vite, Vitest, ESBuild, ESLint, Prettier (MIT); Playwright (Apache-2.0); happy-dom (MIT).

Elenco completo: `npm ls --all --json --omit=dev`.

---

## 🌐 Dati esterni (runtime)

### GeoIP database (Country + ASN)

Il software supporta lettura di **due** database GeoIP nel formato MMDB:
- **Country DB** — lookup ISO code da IP, usato per geo-blocking WAF
- **ASN DB** — lookup Autonomous System Number, usato per threat intelligence

La distribuzione **non include alcun database GeoIP**: l'amministratore li scarica separatamente.

**Provider consigliato** (compatibile EUPL): **DB-IP Lite** — licenza **CC-BY-4.0** (entrambi i DB).

> GeoIP data — © DB-IP (db-ip.com), licensed under CC-BY-4.0.

Download mensile gratuito:
- <https://db-ip.com/db/download/ip-to-country-lite>
- <https://db-ip.com/db/download/ip-to-asn-lite>

Script di aggiornamento automatico fornito: [`tools/waf/update_dbip_geoip.sh`](tools/waf/update_dbip_geoip.sh) (cron mensile).

**Alternativa storica** (proprietary): MaxMind GeoLite2-{Country,ASN}.mmdb — soggetto a [MaxMind End User License Agreement](https://www.maxmind.com/en/geolite2/eula). Da scaricare da `maxmind.com` previa registrazione. **Sconsigliato** per deploy distribuiti perché la EULA non è open-source.

L'SDK `geoip2/geoip2` (Apache-2.0) usato per la lettura è compatibile con entrambi i formati e con entrambi i tipi di DB.

---

## 🖼️ Font e asset statici

| Asset | Licenza | Note |
|-------|---------|------|
| Material Symbols (icone) | Apache-2.0 | Google Fonts |
| KaTeX fonts (rendering matematica) | SIL OFL 1.1 / MIT | Bundled in dist build |

---

## 🧰 Componenti deprecati (rimossi dalla distribuzione)

I seguenti componenti erano presenti in versioni precedenti e **non sono inclusi** nella distribuzione pubblicata:

- **TikZJax (client-side)** — `tikzjax-develop/` — GPL-3.0+. Sostituito da rendering server-side via `pdflatex` + `dvisvgm` su VPS (vedi [`wiki/decisions/ADR-013-tikz-server-render.md`](wiki/decisions/ADR-013-tikz-server-render.md)). Cartella sorgenti escluse via `.gitignore` per evitare propagazione obblighi GPL.

---

## 🔍 Verifica e mantenimento

- Script CI `composer licenses` e `npm ls --all --omit=dev` rilevano nuove dipendenze.
- Ogni nuova dipendenza runtime deve essere verificata contro [EUPL-1.2 Annex (compatible licenses)](https://joinup.ec.europa.eu/collection/eupl/matrix-eupl-compatible-open-source-licences) prima del merge.
- Report di audit ricorrenti: vedi [`docs/legal/third-party-licenses-audit.md`](docs/legal/third-party-licenses-audit.md).

---

**Per segnalazioni di licenze mancanti o incorrette in questo NOTICE**, aprire una issue sul repository del progetto.
