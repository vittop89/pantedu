# Pantedu

> Piattaforma educativa italiana self-hosted per docenti — mappe concettuali,
> esercizi, verifiche, gestione classi e materiali didattici.
> Privacy-first con cifratura envelope (AES-256-GCM) e compliance GDPR.

**Status**: Early development. Codice pubblico dal 23 giugno 2026 (EUPL-1.2).
Self-hosting al momento non documentato in modo completo — il quickstart
qui sotto è un placeholder.

---

## Caratteristiche

- **Mappe concettuali** drawio integrate, cifrate per docente
- **Esercizi e verifiche** con compile TeX → PDF lato server
- **Gestione classi** multi-indirizzo (Liceo, Tecnico, Professionale)
- **GDPR ready**: export Art. 15/20, authority cooperation Art. 6(1)(c)
- **AI Act**: import esercizi da PDF via LLM (opt-in, off di default). Sistema
  a rischio limitato — output generati marcati ex art. 50(2) Reg. UE 2024/1689.
  Classificazione motivata in [docs/legal/ai-act-assessment.md](docs/legal/ai-act-assessment.md)
- **Crypto envelope**: AES-256-GCM + KEK per docente + Shamir 3-of-5 KMS
- **WAF applicativo**: challenge Proof-of-Work anti-bot, scoring del
  fingerprint + segnali server-side, geo-filtering non spoofabile,
  threat-intel (Spamhaus/Tor/ASN), CrowdSec, honeypot, ban brute-force NAT-safe
- **Difesa di bordo**: origin lockato al solo CDN/proxy, IP client reale
  anti-spoofing, rate-limiting di bordo
- **Audit logging**: append-only, retention configurabile (7-10 anni)

## Stack Tecnico

- **Backend**: PHP 8.3 o 8.4 (FPM) + MariaDB 11.x
- **Frontend**: Vanilla JS modules (no framework), Vite build
- **Reverse proxy**: nginx (real_ip + rate-limit); ModSecurity/CRS opzionale
- **Bordo (raccomandato)**: CDN/proxy (es. Cloudflare) con origin lockato
  al firewall; il WAF applicativo funziona comunque anche stand-alone
- **TeX compile**: microservizio Python separato (`/opt/tex-compile/`)
- **Deploy**: bare-metal o VPS (testato su Hetzner)

## Quickstart Self-Host

> Guida completa passo-passo: **[docs/INSTALL.md](docs/INSTALL.md)**
> (requisiti, DB, `.env`, nginx+HTTPS, backup chiavi, WAF/firewall, cron).
> Sotto, il riassunto rapido.

```bash
# 1. Clone
git clone https://github.com/<owner>/pantedu.git
cd pantedu

# 2. Dipendenze
composer install --no-dev --optimize-autoloader
npm install && npm run build

# 3. Configurazione
cp .env.example .env
# .env resta la base comune: quello che è della tua installazione va in
# .env.local, che vince (wiki/environment-variables.md, «Quale file vale
# dove»). Crea .env.local con APP_URL, DB_* e i segreti generati:
#   APP_URL=https://tuodominio
#   KMS_MASTER_KEY=$(openssl rand -hex 32)
#   STORAGE_SIGNING_SECRET=$(openssl rand -hex 32)
#   DB_PASS=...
#   WAF_HMAC_SECRET=$(openssl rand -hex 32)

# 4. Database
mysql -u root -p < database/schema.sql
php tools/migrate.php

# 5. Web server
# Configura nginx con root su public/ e fastcgi_pass verso PHP-FPM:
# esempio completo in infra/nginx/pantedu.eu.conf (+ ratelimit-zones.conf)

# 6. Lavori pianificati
# Timer e unit systemd pronti in tools/systemd/ (audit chain, backup cifrato,
# cancellazioni e retention GDPR, threat-intel WAF, pulizia temporanei).
```

**Requisiti minimi VPS**:
- 2 vCPU, 4GB RAM (8GB consigliato), 40GB SSD
- Ubuntu 22.04+ / Debian 12+
- PHP 8.4-fpm, MariaDB 11+, nginx, certbot

## Architettura

Difesa a strati (defence-in-depth): ogni livello filtra prima del successivo.

```
   Browser
     │  HTTPS
     ▼
 ┌───────────────────────────────────────────────┐
 │ BORDO (raccomandato) — CDN/proxy es. Cloudflare│  volumetrico/DDoS, TLS,
 │  geo/bot a scala                                │  header Cf-IPCountry
 └───────────────────────┬─────────────────────────┘
                         │  solo IP del CDN
 ┌───────────────────────▼─────────────────────────┐
 │ ORIGIN FIREWALL (UFW/cloud) — 80/443 SOLO da CDN │  blocca gli hit diretti
 └───────────────────────┬─────────────────────────┘  all'origin (no spoof)
                         │
 ┌───────────────────────▼─────────────────────────┐
 │ nginx — real_ip (IP client reale), limit_req     │  rate-limit di bordo,
 │  (login + anti-flood), CSP/HSTS, ModSecurity opz.│  TLS termination
 └───────────────────────┬─────────────────────────┘
                         │  FastCGI
 ┌───────────────────────▼─────────────────────────┐
 │ PHP 8.4-fpm — WAF applicativo (middleware)       │
 │  EdgeContext anti-spoof · geo-filtering (IT)     │
 │  Proof-of-Work · scoring · threat-intel/CrowdSec │
 │  honeypot · rule engine · ban brute-force        │
 │  cookie HMAC IP+UA · fail-closed                 │
 └───────────────────────┬─────────────────────────┘
                         │  (auth, RBAC, CSRF, audit log)
        ┌────────────────┼────────────────┐
        ▼                ▼                 ▼
   ┌─────────┐   ┌──────────────┐   ┌──────────────────┐
   │ MariaDB │   │ tex-compile  │   │ Envelope crypto  │
   │         │   │ (Python μsvc)│   │ KMS → KEK/docente │
   └─────────┘   └──────────────┘   │ → blob AES-256-GCM│
                                    │ Shamir 3-of-5 KMS │
                                    └──────────────────┘
```

> Il **WAF applicativo** è self-contained: funziona anche senza CDN/firewall di
> bordo. Bordo + origin-lock sono il deployment **raccomandato** che rende la
> geo-restrizione e l'IP reale non falsificabili (vedi
> [docs/ops/waf-hardening-2026-06.md](docs/ops/waf-hardening-2026-06.md)).

## Documentazione

- [SECURITY.md](SECURITY.md) — Vulnerability disclosure
- [docs/api/](docs/api/) — OpenAPI specs (`api-index.md` entry point)
- [docs/security/operations/shamir-recovery-runbook.md](docs/security/operations/shamir-recovery-runbook.md) — KMS recovery 3-of-5
- [wiki/_llm-primer.md](wiki/_llm-primer.md) — Onboarding tecnico (per dev / LLM assistant)

## Licenza

Rilasciato sotto **[EUPL-1.2](LICENSE)** — European Union Public License
v1.2. Licenza copyleft EU-compatibile, raccomandata da
[AgID](https://www.agid.gov.it/it/design-servizi/riuso-open-source) per
software riusabile dalla Pubblica Amministrazione italiana.

Compatibile con: GPLv2/v3, AGPLv3, CeCILL, OSL, EPL e altre copyleft
EU/internazionali. Vedi [`LICENSE`](LICENSE) per il testo integrale.

Audit licenze dipendenze: [`NOTICE.md`](NOTICE.md) +
[`docs/legal/third-party-licenses-audit.md`](docs/legal/third-party-licenses-audit.md).

## Contribuire

Vedi [`CONTRIBUTING.md`](CONTRIBUTING.md) per workflow, conventions e
Developer Certificate of Origin. Aderiamo al
[Contributor Covenant 2.1](CODE_OF_CONDUCT.md).

Storico modifiche: [`CHANGELOG.md`](CHANGELOG.md) (Keep a Changelog) e
[`wiki/changelog/`](wiki/changelog/) (dettaglio mese-per-mese).

Il codice è pubblico dal 23 giugno 2026 (copia ripulita, aggiornata a ogni
pubblicazione — lo sviluppo resta in un repository privato). Le PR esterne
**non sono ancora aperte**: `CONTRIBUTING.md` descrive il flusso a cui
questa pagina rimanda per quando lo saranno. Per discussioni preliminari:
<{{OPERATORE_EMAIL}}>.

Per security issues: vedi [SECURITY.md](SECURITY.md).

## Conformità open source per PA

Metadati Developers Italia in [`publiccode.yml`](publiccode.yml). La
candidatura al [catalogo developers.italia.it](https://developers.italia.it/it/software)
e' stata depositata il 23 giugno 2026
([italia/catalogo-software#12](https://github.com/italia/catalogo-software/issues/12))
ed e' in attesa di istruttoria. L'integrazione SPID/CIE resta da fare, ma non
e' requisito per l'inserimento in catalogo di software di terze parti.

**Accessibilità**: conforme **WCAG 2.2 livello AA** / EN 301 549 (Legge 4/2004
"Stanca"), con allineamento volontario ai requisiti dello European Accessibility
Act. Dichiarazione AgID Form-A pubblicata in
[`/accessibility`](https://pantedu.eu/accessibility) (sorgente
[`docs/legal/accessibility.md`](docs/legal/accessibility.md)) + audit tecnico per
criterio in [`docs/legal/accessibility-audit.md`](docs/legal/accessibility-audit.md);
verifica axe-core su ogni pull request (workflow `a11y.yml`, non fra i
contesti obbligatori della protezione di `main`: una violazione grave resta
visibile a chi rivede, ma non impedisce da sola l'unione — vedi
[`docs/dev/ci-cd.md`](docs/dev/ci-cd.md)). Le formule matematiche espongono
MathML assistivo (lette dagli screen reader).

## Crediti e autorialità

Ideato e diretto da **Vittorio Pantaleo** — docente di fisica e matematica
(concezione, requisiti, scelte architetturali, revisione, decisioni e
responsabilità del prodotto).

Il **codice sorgente è stato scritto interamente dai modelli di intelligenza
artificiale Claude Opus 4.7 e Claude Opus 4.8 (Anthropic)**, sotto la guida e
la direzione di Vittorio Pantaleo. pantedu è quindi frutto di una
**co-autorialità uomo–AI**; i commit riportano `Co-Authored-By: Claude Opus`.

> **Nota su copyright e licenza.** Ai fini della licenza **EUPL-1.2** il
> **titolare del copyright è Vittorio Pantaleo** (persona fisica): nell'ordinamento
> vigente un sistema di IA non può essere titolare di diritti d'autore, perciò
> l'accreditamento dei modelli come co-autori ha valore di **trasparenza sul
> processo di sviluppo**, non di co-titolarità giuridica.

---

_Last updated: 2026-09-04_
