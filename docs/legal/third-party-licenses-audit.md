# Third-Party Licenses Audit — pantedu

> **Scope**: verifica compatibilità EUPL-1.2 delle dipendenze runtime e dev di pantedu.
> **Ultimo audit**: 2026-05-22 (commit di riferimento: `master_vps`)
> **Autore**: Vittorio
> **Riferimenti normativi**:
> - [EUPL-1.2 Compatible Licenses Matrix](https://joinup.ec.europa.eu/collection/eupl/matrix-eupl-compatible-open-source-licences)
> - [Linee Guida AGID acquisizione e riuso 2020 rev. 2024](https://www.agid.gov.it/it/design-servizi/riuso-open-source)

## Esito sintetico

✅ **GO** — tutte le dipendenze runtime sono compatibili con EUPL-1.2.

Azioni residue per la pubblicazione: vedi sezione [TODO operativo](#todo-operativo).

---

## Metodologia

```powershell
# PHP (Composer)
composer licenses --format=json

# JavaScript (npm) — runtime
npm ls --all --json --omit=dev

# JavaScript (npm) — runtime + dev
npm ls --all --json
```

Per ogni pacchetto trovato si verifica:
1. La licenza è permissiva (MIT/BSD/Apache) o copyleft (GPL/LGPL/AGPL/MPL)?
2. Se copyleft, è elencata nell'[Annex EUPL-1.2 compatible licenses](https://joinup.ec.europa.eu/collection/eupl/matrix-eupl-compatible-open-source-licences)?
3. Esistono clausole non standard (custom EULA, no-commercial, no-modification)?

---

## 1. Dipendenze PHP (Composer)

**Totale pacchetti**: 70 (runtime + dev)

### Distribuzione licenze

| Licenza | # | Esito |
|---------|---|-------|
| MIT | 38 | ✅ Permissiva |
| BSD-3-Clause | 25 | ✅ Permissiva |
| Apache-2.0 | 7 | ✅ Permissiva |
| GPL-2.0-or-later | 1 | ✅ EUPL Annex (compatibile inbound) |
| LGPL-2.1-or-later | 1 | ✅ Lesser copyleft (compatibile) |

### Pacchetti copyleft — analisi

#### `enshrined/svg-sanitize` (GPL-2.0-or-later)
- **Uso**: sanitizzazione SVG durante upload (XSS prevention).
- **Categoria**: dipendenza **runtime obbligatoria**.
- **EUPL compatibility**: ✅ EUPL Annex elenca esplicitamente `GPL-2.0`.
- **Effetto sulla licenza derivata**: nessuno. EUPL può assorbire codice GPLv2+ mantenendo licenza EUPL sul progetto.
- **Obbligo NOTICE**: ✅ già documentato in `NOTICE.md`.

#### `ezyang/htmlpurifier` (LGPL-2.1-or-later)
- **Uso**: sanitizzazione HTML user-generated (consegne, descrizioni).
- **Categoria**: dipendenza **runtime obbligatoria**.
- **EUPL compatibility**: ✅ EUPL Annex elenca `LGPL-2.1`.
- **Particolarità**: LGPL (lesser) consente uso come libreria senza propagazione del copyleft al codice chiamante.
- **Obbligo NOTICE**: ✅ già documentato.

### Conclusione PHP

Tutte le dipendenze sono utilizzabili in un progetto EUPL-1.2 senza vincoli aggiuntivi oltre alla normale attribuzione in `NOTICE.md`.

---

## 2. Dipendenze JavaScript (npm)

### Runtime dependencies

| Pacchetto | Licenza |
|-----------|---------|
| `@codemirror/*` (commands, lang-json, lint, state, view, theme-one-dark) | MIT |
| `@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extension-underline` | MIT |
| `prosemirror-*` (transitive via @tiptap/pm) | MIT |
| `@lezer/*` (transitive) | MIT |
| `codemirror` | MIT |
| `sortablejs` | MIT |

Tutti **MIT** → ✅ EUPL-compatibile.

### Dev dependencies (non shipped)

| Pacchetto | Licenza |
|-----------|---------|
| `vite`, `vitest`, `esbuild`, `eslint`, `prettier`, `happy-dom` | MIT |
| `@playwright/test`, `playwright`, `playwright-core` | Apache-2.0 |
| `@prettier/plugin-php`, `globals`, `adm-zip` | MIT |

Dev deps non vengono distribuite nel pacchetto finale, ma comunque tutte open-source compatibili.

### Conclusione JS

Tutte le dipendenze sono MIT/Apache-2.0 → nessun problema di compatibilità.

---

## 3. Dati esterni runtime

### Stato attuale VPS (verificato 2026-05-22)

Il VPS beta.pantedu.eu **usa già DB-IP Lite** (sia Country che ASN), licenza CC-BY-4.0. File presenti:

```
/var/www/pantedu/storage/geoip/dbip-country-lite.mmdb  (~8 MB)
/var/www/pantedu/storage/geoip/dbip-asn-lite.mmdb      (~9 MB)
```

`.env.local` punta a entrambi:
```
WAF_GEOIP_DB=/var/www/pantedu/storage/geoip/dbip-country-lite.mmdb
WAF_GEOIP_ASN_DB=/var/www/pantedu/storage/geoip/dbip-asn-lite.mmdb
```

**Migrazione DB**: ✅ già completata (file aggiornati 2026-05-18).
**Auto-update mensile**: ❌ cron non ancora installato (installato 2026-05-22, vedi §step 5).

### MaxMind GeoLite2 (storico — sostituito)

- **Licenza**: [MaxMind GeoLite2 EULA](https://www.maxmind.com/en/geolite2/eula) — custom proprietary
- **Restrizioni rilevanti**:
  - Registrazione MaxMind account obbligatoria
  - Aggiornamento DB richiesto (DB stale dopo 30 giorni viola EULA)
  - Non ridistribuibile insieme al software
- **Status**: sostituito da DB-IP in produzione. Codice e docblock allineati.

### ⭐ Provider attuale: DB-IP Lite (Country + ASN)

| Caratteristica | DB-IP Lite (Country + ASN) | MaxMind GeoLite2 |
|----------------|----------------------------|------------------|
| Licenza | **CC-BY-4.0** | Custom proprietary EULA |
| Formato | MMDB (compatibile MaxMind!) | MMDB |
| Coverage country | ~99% | ~99.8% |
| Coverage ASN | ~99% AS coverage | ~99% |
| Update frequency | Mensile (1° di ogni mese) | Settimanale |
| Costo | Gratuito | Gratuito ma con signup |
| Attribuzione | Richiesta (NOTICE) | Richiesta |
| Drop-in replacement SDK | ✅ Sì (geoip2/geoip2 Apache-2.0) | – |
| EUPL-compatible | ✅ Sì | ❌ No |

**Migrazione**: cambio solo URL nel cron `geoipupdate`:

```bash
# Prima (MaxMind, EULA proprietary)
# curl con licenza ID + cron geoipupdate

# Dopo (DB-IP Lite, CC-BY-4.0)
curl -L https://download.db-ip.com/free/dbip-country-lite-$(date +%Y-%m).mmdb.gz \
  -o /tmp/dbip.mmdb.gz
gunzip /tmp/dbip.mmdb.gz
mv /tmp/dbip.mmdb storage/geoip/dbip-country-lite.mmdb
```

Aggiornamento env:
```diff
- WAF_GEOIP_DB=/path/to/GeoLite2-Country.mmdb
+ WAF_GEOIP_DB=/path/to/dbip-country-lite.mmdb
```

Aggiornamento `NOTICE.md`: ✅ già inclusa l'attribution DB-IP (CC-BY-4.0 richiede solo "© DB-IP, db-ip.com").

**Nota architetturale**: il fallback ordinato in [GeoIpService.php](../../app/Services/Waf/GeoIpService.php) è:
1. Header `CF-IPCountry` (Cloudflare) — istantaneo, no DB
2. Header `X-GeoIP-Country` (nginx ngx_http_geoip2_module)
3. PHP SDK lettura MMDB (DB-IP o MaxMind)
4. `null` fallback

In produzione con Cloudflare attivo, il livello 3 (DB locale) viene raramente colpito → impatto della migrazione DB-IP è trascurabile sulla user experience.

### Altre alternative valutate (scartate)

- **IP2Location LITE DB1** (CC-BY-SA-4.0): coverage simile, ma formato BIN proprietario richiede nuovo SDK + clausola "share-alike" più restrittiva del CC-BY puro.
- **RIPE NCC / ARIN GeoFeed (RFC 8805)**: public domain, ma copertura limitata ai RIRs e parsing custom richiesto.
- **API HTTP esterne (ipdata, ipapi)**: latenza per ogni request, dipendenza esterna runtime + privacy (IP leak).

---

## 4. Asset statici

| Asset | Licenza | Stato distribuzione |
|-------|---------|---------------------|
| Material Symbols icons | Apache-2.0 | Servito via Google Fonts CDN (no bundle) |
| KaTeX fonts | SIL OFL 1.1 / MIT | Bundle in `public/build/` |
| Custom logos / immagini pantedu | © Vittorio (proprietary) | Inclusi nel repo, rilasciati sotto EUPL come parte del software |

---

## 5. Componenti deprecati — pre-publish cleanup

### `tikzjax-develop/` (GPL-3.0+)

- **Stato attuale**: cartella sorgenti ancora tracked in git history.
- **Stato funzionale**: deprecato — sostituito da render server-side via VPS (vedi [ADR-013](../../wiki/decisions/ADR-013-tikz-server-render.md) e [tikzjax-develop/.deprecated](../../tikzjax-develop/.deprecated)).
- **Riferimenti dinamici residui**: 4 HTML legacy in `storage/objects/.../eser_sc1s/` contengono `<script src="/tikzjax-develop/output/tikzjax.js">`, ma il path `public/tikzjax-develop/` non esiste più (script 404 → stripped da [content-processor.js](../../js/modules/editor/content-processor.js)).
- **Azione richiesta pre-publish**:
  1. ✅ Aggiunta a `.gitignore` (fatto 2026-05-22 — vedi diff `.gitignore`)
  2. ⏳ Rimozione fisica dal tracking git: `git rm -r --cached tikzjax-develop/` + commit dedicato
  3. ⏳ Verifica che nessun nuovo HTML in `storage/objects/` referenzi più il path
  4. ⏳ Eventuale rewrite history per rimuovere `tikzjax-develop/` da tutto lo storico (BFG / git filter-repo) — solo se pubblicazione richiede storico pulito

Motivazione: pubblicare con `tikzjax-develop/` (GPL-3.0+) richiederebbe NOTICE GPL-3.0 obbligatorio + propagazione delle clausole anti-tivoization a chi forka. Eliminando il codice morto evitiamo l'obbligo.

---

## TODO operativo

Checklist da chiudere **prima del primo release EUPL pubblico**:

- [x] `tikzjax-develop/` aggiunto a `.gitignore` (2026-05-22)
- [x] `NOTICE.md` in root con elenco third-party licenses (2026-05-22)
- [x] Questo audit document (2026-05-22)
- [ ] **`git rm -r --cached tikzjax-develop/`** in commit dedicato (richiede approvazione esplicita user)
- [ ] **Migrazione GeoIP a DB-IP Lite** in produzione VPS:
  - [ ] Cron `geoipupdate` aggiornato a URL DB-IP
  - [ ] `WAF_GEOIP_DB` env var aggiornata
  - [ ] Test funzionale `php tools/waf/test_geoip.php` con nuovo DB
  - [ ] Aggiornamento `app/Services/Waf/GeoIpService.php` docblock (URL setup)
- [ ] Script CI `composer licenses` check (vedi [§Script CI](#script-ci))
- [ ] Script CI `npm` license check (vedi [§Script CI](#script-ci))
- [ ] Verifica licenza repo: cambia `composer.json` "license": "proprietary" → "EUPL-1.2" al momento del primo release
- [ ] Riesegui questo audit prima di ogni release (frequenza: trimestrale o post-aggiornamento dipendenze maggiori)

---

## Script CI

Aggiungere a `composer.json`:

```json
"scripts": {
    "licenses:php": "composer licenses --no-dev --format=json"
}
```

Aggiungere a `package.json`:

```json
"scripts": {
    "licenses:js": "npm ls --all --json --omit=dev"
}
```

Per check automatico in pre-release, valutare l'integrazione di tool dedicati:

- **PHP**: [`composer-license-checker`](https://github.com/dominikb/composer-license-checker) — fallisce CI se trova licenze non in whitelist.
- **JS**: [`license-checker-rseidelsohn`](https://github.com/RSeidelsohn/license-checker-rseidelsohn) — analogo per npm.

Whitelist consigliata per pantedu (EUPL-1.2):

```
MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0,
ISC, 0BSD, Unlicense, CC0-1.0,
LGPL-2.1, LGPL-2.1-or-later, LGPL-3.0, LGPL-3.0-or-later,
GPL-2.0-or-later, GPL-3.0-or-later,
EUPL-1.1, EUPL-1.2,
MPL-2.0
```

---

## Cronologia audit

| Data | Esito | Note |
|------|-------|------|
| 2026-05-22 | ✅ GO | Primo audit completo. Tutte deps runtime EUPL-compatibili. Pending: cleanup `tikzjax-develop/`, migrazione GeoIP. |

---

## Riferimenti

- [`NOTICE.md`](../../NOTICE.md) — attribuzione third-party (file root)
- [`docs/todo/opensource_eupl_publication_plan.md`](../todo/opensource_eupl_publication_plan.md) — piano operativo pubblicazione EUPL
- [`wiki/decisions/ADR-013-tikz-server-render.md`](../../wiki/decisions/ADR-013-tikz-server-render.md) — decisione abbandonare TikZJax client-side
- [EUPL-1.2 official text — IT](https://joinup.ec.europa.eu/sites/default/files/inline-files/EUPL%20v1_2%20IT(1).txt)
- [DB-IP Lite Country](https://db-ip.com/db/download/ip-to-country-lite)
