# tex-compile-vps — PoC server-side LaTeX compile

Microservizio stateless per compilazione LaTeX su VPS dedicato.
Pensato per affiancare l'app principale pantedu in hosting Aruba shared
(che non dispone di TeX Live), delegando la sola fase CPU-bound
`pdflatex → PDF` a un VPS economico (≤7€/mese).

## Architettura hybrid

```
┌─────────────────────────┐         HTTPS + HMAC          ┌─────────────────────┐
│  Aruba shared (PHP app) │  ──────────────────────────►  │  VPS tex-compile    │
│                         │      POST /compile             │                     │
│  TexBuilder.php genera  │      { tex, doc_id, engine }   │  FastAPI + uvicorn  │
│  .tex content           │                                │  pdflatex (TeX Live)│
│                         │  ◄──────────────────────────   │                     │
│  Salva PDF in storage/  │      200 application/pdf       │  Stateless, no DB   │
└─────────────────────────┘      4xx JSON {error,log}      └─────────────────────┘
```

**Vantaggi:**
- Zero migrazione del sito (resta su Aruba)
- VPS minimale (no PHP, no DB, no storage persistente)
- Rollback istantaneo: disattivi VPS → app torna al flow precedente
- Scaling indipendente: scali solo il VPS al crescere dei compile

**Trade-off:**
- 1 round-trip rete extra (200-500ms tipici, accettabile)
- TLS obbligatorio (Let's Encrypt gratis)
- Auth HMAC con segreto condiviso (rotabile)

## Componenti

| Cartella       | Ruolo                                           |
|----------------|-------------------------------------------------|
| `app/`         | Servizio FastAPI Python — endpoint `/compile`   |
| `systemd/`     | Unit file per autostart su Debian/Ubuntu        |
| `nginx/`       | Reverse proxy + TLS termination                 |
| `client/`      | Classe PHP da integrare lato Aruba              |
| `provision.sh` | Script one-shot per setup VPS Debian 13 (trixie) |
| `DEPLOY.md`    | Procedura passo-passo deploy                    |
| `.env.example` | Variabili d'ambiente del servizio               |

## Stack scelto

| Layer        | Tecnologia               | Motivo                                |
|--------------|--------------------------|---------------------------------------|
| OS           | Debian 13 (trixie)       | Standard VPS, TeX Live ufficiale apt  |
| Runtime      | Python 3.11+             | subprocess + async + tipi             |
| Framework    | FastAPI + uvicorn        | Performance, validazione automatica   |
| Reverse proxy| nginx                    | TLS, rate limiting, body size limit   |
| TLS          | certbot + Let's Encrypt  | Gratis, rinnovo automatico            |
| Auth         | HMAC-SHA256 + timestamp  | Niente JWT/OAuth, semplice e sicuro   |
| TeX          | TeX Live `scheme-medium` | ~2GB, copre 99% pacchetti scolastici  |

## Sicurezza minima implementata

1. **TLS obbligatorio** — solo HTTPS, redirect HTTP→HTTPS in nginx
2. **HMAC sul body** — firma SHA256 con segreto condiviso
3. **Anti-replay** — timestamp scartato se > 300s, nonce opzionale
4. **Sandbox compile** — `-no-shell-escape`, tmpdir isolato, cleanup
5. **Resource limits** — timeout 30s/compile, max 4 worker concorrenti
6. **Rate limit nginx** — 20 req/min per IP, body max 5 MB
7. **systemd hardening** — `PrivateTmp=yes`, `NoNewPrivileges=yes`, user dedicato

## Costo runtime stimato

| Voce              | Costo                                       |
|-------------------|---------------------------------------------|
| VPS (4 vCPU 4GB)  | ~5-7 €/mese (IONOS M+ / Hetzner / OVH)      |
| TLS Let's Encrypt | 0 €                                         |
| Sottodominio      | 0 € (riusa DNS esistente)                   |
| **Totale**        | **~5-7 €/mese**                             |

## Prossimi step

1. Provisioning VPS con `provision.sh`
2. Configurazione DNS sottodominio (`tex.tuosito.it → IP_VPS`)
3. Generazione cert TLS via certbot
4. Setup variabili in `.env` con segreto HMAC robusto
5. Integrazione `TexCompileClient.php` lato Aruba (vedi `client/`)
6. Test smoke con un .tex di esempio

Vedi `DEPLOY.md` per procedura completa.
