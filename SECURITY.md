# Security Policy

Pantedu è una piattaforma educativa italiana con forte focus su privacy/GDPR.
Trattiamo dati personali di docenti e (indirettamente) studenti minorenni: prendiamo
la sicurezza molto sul serio e apprezziamo la responsible disclosure.

## Versioni Supportate

Il progetto è in **active development** — supportiamo solo l'ultima versione del
branch `master`. Non sono previste back-port di security fix su versioni precedenti.

| Versione | Supportata             |
| -------- | ---------------------- |
| master   | :white_check_mark:     |
| < master | :x:                    |

## Segnalare una Vulnerabilità

**NON aprire issue pubbliche per vulnerabilità di sicurezza.** Le issue su GitHub
sono pubbliche e indicizzate: divulgare una vulnerabilità prima della patch
espone gli utenti.

### Canale Preferito

Invia una email a: **security@pantedu.eu**

Includi:
- Descrizione della vulnerabilità (cosa, dove, come)
- Passi per riprodurre (PoC minimo, no exploit completo)
- Impatto stimato (confidentiality / integrity / availability)
- Versione/commit affetti (output di `git rev-parse HEAD`)
- La tua identità o pseudonimo per il credit (opzionale)

**Cifratura email (opzionale ma consigliata per vulnerabilità critiche)**:
PGP public key disponibile su richiesta a security@pantedu.eu (fingerprint
verrà pubblicato in questa sezione dopo apertura repo).

### Cosa Aspettarsi

| Fase                        | SLA target |
| --------------------------- | ---------- |
| Acknowledgement iniziale    | 72 ore     |
| Triage + classificazione    | 7 giorni   |
| Fix per CRITICAL/HIGH       | 30 giorni  |
| Fix per MEDIUM              | 90 giorni  |
| Fix per LOW                 | best-effort|

Manteniamo il reporter informato sullo stato. Pubblichiamo un security advisory
post-fix (con credit se desiderato).

### Classificazione

Usiamo CVSS v3.1 calculator. Esempi indicativi:

- **CRITICAL** (9.0-10.0): RCE non autenticato, leak massivo di dati cifrati
- **HIGH** (7.0-8.9): privilege escalation, decifratura non autorizzata di
  blob cifrati, bypass autenticazione
- **MEDIUM** (4.0-6.9): XSS persistente, IDOR limitati, leak di metadata
- **LOW** (0.1-3.9): XSS reflected limitati, info disclosure non sfruttabile

## Out of Scope

I seguenti non sono considerati vulnerabilità ai fini di questa policy:

- Self-hosted installations con configurazione errata (es. `KMS_MASTER_KEY`
  debole, HTTPS disabilitato, password admin di default lasciate)
- Mancanza di best-practices su servizi di terze parti che self-hoster ha
  configurato (es. nginx senza HSTS lato cliente)
- Email spoofing senza DMARC dell'installazione del self-hoster
- Issue di compatibilità browser/UA legacy non supportati
- **DoS volumetrico / DDoS L3-L4** (perimetro CDN/bordo, non applicativo).
  NB: l'app *include* rate-limiting, ban brute-force e WAF applicativo —
  un loro bypass È in scope; la mitigazione del flood volumetrico no.
- Mancata configurazione del bordo da parte del self-hoster (es. origin
  non lockato al CDN, ModSecurity non attivato) — è una scelta di deployment
- Social engineering, physical attacks
- Vulnerabilità in dipendenze già pubblicate su CVE database

## Scope

In scope:
- Codice in questo repository (PHP app, frontend JS, build tooling)
- Crypto envelope (AES-256-GCM + Shamir Secret Sharing del KMS)
- Logica GDPR (export Art. 15/20, authority cooperation Art. 6(1)(c))
- **WAF applicativo** (`app/Middleware/WafMiddleware.php`,
  `app/Services/Waf/`): bypass del geo-filtering, del Proof-of-Work, dello
  scoring, dell'IP-binding del cookie, della risoluzione IP anti-spoofing
  (`EdgeContext`), o del ban brute-force
- Logica di auth/RBAC/CSRF e ban credenziali (`app/Core/Auth.php`)
- Audit logging append-only (`app/Core/AccessLogger.php`,
  `PrivilegedAccessLogger.php`)
- Endpoint API documentati in `docs/api/`

> Architettura di sicurezza a strati (bordo → origin firewall → nginx →
> WAF applicativo → cifratura): vedi diagramma in [README.md](README.md) e
> [docs/ops/waf-hardening-2026-06.md](docs/ops/waf-hardening-2026-06.md).

Non in scope (separate o non nostre):
- `pdf-scraping-tools/` (helper esterni offline, non production)
- TeX templates `/opt/tex-compile/` (Python service separato)
- Infrastruttura Hetzner del progetto principale (responsabilità deploy team)

## Crypto / Privacy Note

Il sistema usa envelope encryption: ogni docente ha una KEK cifrata da
`KMS_MASTER_KEY`. La perdita di `KMS_MASTER_KEY` rende inaccessibile **tutto**
il contenuto cifrato (questo è by design — confidentiality > availability).

Disponiamo di Shamir Secret Sharing 3-of-5 per recovery del KMS:
vedi [docs/security/operations/shamir-recovery-runbook.md](docs/security/operations/shamir-recovery-runbook.md).

Se trovi una vulnerabilità che permette di **bypassare** l'envelope encryption
(es. recupero plaintext senza KEK valida, oracle di decifratura, side-channel),
trattalo come **CRITICAL** indipendentemente da CVSS.

## Hall of Fame

Reporter che hanno contribuito a Pantedu security (con loro consenso):

_(Sezione vuota al momento — sii il primo!)_

## Linee Guida Etiche

- **No exploitation**: non sfruttare la vulnerabilità oltre quanto necessario a
  dimostrarla. Niente esfiltrazione di dati reali, niente persistence.
- **Privacy first**: se il PoC tocca dati di utenti, anonimizza/cancella copia
  e segnala nel report.
- **Coordinated disclosure**: aspetta il fix prima di pubblicare. Disclosure
  prematura mette a rischio gli utenti delle installazioni self-hosted.
- **Buona fede**: ricerche condotte in buona fede secondo questa policy NON
  saranno oggetto di azioni legali da parte nostra.

---

Ultimo aggiornamento: 2026-05-22
Contatto: security@pantedu.eu
