# Guida pratica DevTools per diagnosticare lentezza

> **Scopo**: diagnosticare lentezza di caricamento pagina/azione tramite browser DevTools.
> **Browser**: Chrome / Firefox / Edge — workflow simile, screenshot/menù possono variare.
> **Target progetto**: `pantedu` (beta.pantedu.eu + locale).

---

## 1. 🌐 Network tab (90% dei casi)

**Apri**: `F12` → tab **Network** → ☑ `Disable cache` → ricarica con `Ctrl+R`.

### Cosa guardare

| Colonna     | Soglia OK            | Significato                                                       |
|-------------|----------------------|-------------------------------------------------------------------|
| `Status`    | `2xx` / `304`        | `4xx`/`5xx` = errori; catene `301`/`302` = redirect lenti         |
| `Size`      | < 100 KB per asset   | Asset enormi = bundle non ottimizzato                             |
| `Time`      | < 300 ms             | Tempo totale richiesta                                            |
| `Waterfall` | barre brevi parallele| Lunghe barre verdi = **TTFB** server lento                        |

### Filtri utili (barra Filter)

- `is:slow` → solo richieste > 250 ms
- `larger-than:50k` → solo file pesanti
- `status-code:200` / `status-code:301`

**Tip**: ordina per colonna `Time` → identifichi subito le 5 richieste più lente.

---

## 2. 🔥 Performance tab (interazioni JS lente)

**Apri**: `F12` → tab **Performance** → click `Record ⏺` → esegui l'azione lenta → `Stop`.

### Cosa guardare

- **FCP / LCP** (Largest Contentful Paint) — visibile in alto. Target: **LCP < 2.5s**
- Riga **Main** (main thread): barre **rosse** = JS sincrono bloccante
- **Long tasks** (> 50 ms): segmenti rossi = bottleneck
- Click su un blocco → tab **Bottom-Up** mostra quale funzione ha mangiato tempo

---

## 3. ⚡ Lighthouse (audit automatico)

**Apri**: `F12` → tab **Lighthouse** → categoria `Performance` → `Analyze`.

Restituisce report **score 0-100** + lista problemi prioritizzati:

- *Eliminate render-blocking resources*
- *Reduce unused JavaScript*
- *Largest Contentful Paint element*

Ogni voce ha link **Learn more** + **savings stimati**.

---

## 4. 🔍 Segnali specifici per `pantedu`

Apri `/admin/waf/blocks` (pagina pesante) con DevTools Network:

| Cosa vedi                                  | Diagnosi                                                                                  |
|--------------------------------------------|-------------------------------------------------------------------------------------------|
| Richiesta HTML 296 byte + `fingerprint.js` | Prima visita: WAF challenge attiva → +1s round-trip. **Normale, una volta sola**.         |
| `Content-Encoding: gzip` su CSS/JS         | ✅ compressione attiva (vedi [reference_vps_nginx_gzip](../../wiki/_llm-primer.md))       |
| HTML waterfall: TTFB > 500 ms              | Server PHP lento → query DB lente                                                         |
| 30+ richieste in serie                     | Bundle non aggregato, ottimizzazione possibile                                            |
| `Cache-Control: no-store` su CSS/JS        | Caching disabilitato (controlla nginx)                                                    |
| Richiesta che dura 5+ s a `/api/...`       | Endpoint API lento, controlla `SecurityAdminController`                                   |

---

## 5. 🧪 Workflow consigliato per diagnosi

1. **Riproduci** la lentezza (es. caricamento pagina X).
2. **Network** → ordina per `Time` → trova top 3 più lente.
3. Click su una richiesta → tab **Timing**:
   - `Queueing` alto → troppe richieste parallele
   - `Waiting (TTFB)` alto → server lento
   - `Content Download` alto → file enorme + no gzip
4. Se Network non spiega → **Performance** record → cerca **Long Tasks** rossi.
5. Se ancora dubbio → **Lighthouse** per audit automatico + raccomandazioni.

---

## 6. 💡 Quick wins frequenti

- **Disable cache** in Network tab durante debug, ma **disattivalo dopo** (altrimenti misuri sempre cold).
- **Network throttling** `Slow 3G` → simula utenti mobili.
- **Coverage tab** (`Cmd/Ctrl+Shift+P` → `show coverage`) → mostra % CSS/JS inutilizzato.
- `console.time('label')` + `console.timeEnd('label')` per misurare blocchi codice.
- **Mobile mode** (`Ctrl+Shift+M`) → testa performance dispositivo.

---

## 7. 📊 Quando aprire issue / chiedere aiuto

Raccogli **prima** questi dati:

- **TTFB** della pagina (Network → click HTML → Timing → `Waiting`)
- **Total page size** (Network → bottom bar)
- **Top 3 slowest requests** (nome + tempo)
- **Long tasks** in Performance (se applicabile)

Con questi numeri si distingue subito se il problema è **server**, **network**, **JS**, o **asset size**.

---

## Riferimenti correlati

- [reference_vps_nginx_gzip](../../wiki/_llm-primer.md) — gzip attivo dal 2026-05-22
- [waf.md](../../wiki/waf.md) — comportamento WAF challenge prima visita
- [architecture.md](../../wiki/architecture.md) — stack e entrypoint
