# Contribuire a Pantedu

> 🚧 **Stato repository (2026-09-23)**: lo sviluppo vive in un repository
> **privato**. Una copia pubblica ripulita esiste su GitHub dal 23 giugno
> 2026, ma è un'istantanea a un solo commit che una pubblicazione successiva
> riscrive per intero (`tools/publish/sanitize-for-publication.php`): un
> fork o una pull request contro quella copia non ha una storia su cui
> restare aperta. **Le PR esterne non sono ancora aperte.** Per segnalare
> bug o discutere una modifica scrivi a <{{OPERATORE_EMAIL}}>; questa guida
> resta valida per quando il flusso di contribuzione diretta si aprirà.

Grazie per il tuo interesse a contribuire a Pantedu. Questo documento
descrive come segnalare bug, proporre modifiche e inviare pull request
in modo che il processo sia chiaro per tutti.

Pantedu è rilasciato sotto **EUPL-1.2** ([`LICENSE`](LICENSE)). Inviando
una contribution accetti che il tuo lavoro sia distribuito sotto la
stessa licenza e dichiari di averne il diritto (vedi DCO sotto).

---

## Codice di condotta

Aderiamo al [Contributor Covenant 2.1](CODE_OF_CONDUCT.md). In sintesi:
sii rispettoso, pazienzioso e tecnico. Comportamenti tossici, molestie
o discriminazioni non sono tollerati e portano al ban.

Segnalazioni a <{{OPERATORE_EMAIL}}>.

---

## Tipi di contribuzione benvenuti

| Tipo | Come |
|---|---|
| 🐛 **Bug report** | GitHub Issue con tag `bug`. Includi PHP/DB version, browser, stack trace |
| ✨ **Feature request** | Issue con tag `enhancement`. Argomenta il caso d'uso didattico |
| 🔒 **Security vulnerability** | **NON** aprire issue pubblica. Vedi [`SECURITY.md`](SECURITY.md) |
| 📝 **Documentazione** | PR diretta su `docs/` o `wiki/`. Anche typo fix benvenuti |
| 🧪 **Test** | PR che aggiungono test PHPUnit/Vitest/Playwright |
| 🌍 **Traduzioni** | Roadmap: i18n module previsto in v0.2.x. Per ora solo IT |
| 💬 **Domande generiche** | Email — le GitHub Discussion non sono attive sul repository pubblico |

---

## Setup ambiente di sviluppo

**Dal 10 settembre 2026 si sviluppa in WSL** (o su Linux): il repository dentro
il filesystem di Linux, il database in un contenitore, il server integrato di
PHP col router della CI. Non più Windows + XAMPP, e non per gusto — sulla
stessa macchina la suite end-to-end passa da 12,7 a 6,3 minuti, e il confronto
a pixel, che su Windows si saltava sempre, gira.

La guida è una sola e vale anche per un computer nuovo:
**[`docs/dev/sviluppo-in-wsl.md`](docs/dev/sviluppo-in-wsl.md)**. In breve,
dopo averla seguita una volta:

```bash
bash tools/dev/wsl/database.sh    # MariaDB 10.11 in un contenitore, porta 3307
bash tools/dev/wsl/server.sh      # http://127.0.0.1:8765
npm run build                     # la suite rifiuta di girare su un pacchetto vecchio
```

Requisiti minimi dev (li installa `tools/dev/wsl/pacchetti.sh`):
- PHP 8.3+ con `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `json`, `curl`, `gd`, `zip`, `intl`, `sodium`
  — senza `pdo_sqlite` i test SQLite si saltano **in silenzio** e la suite sembra verde
- MariaDB 10.11 (in sviluppo e in CI; in produzione 11.x)
- Node 22 (la versione sta in `.nvmrc`), npm 10+
- Composer 2.x
- TeX Live, solo per compilare in locale ciò che in produzione fa il microservizio

Il flusso di lavoro quotidiano — cancello su `main`, migrazioni, rilascio — sta
in [`wiki/dev-workflow.md`](wiki/dev-workflow.md); la mappa di che cosa gira in
integrazione continua e cosa fare quando è rosso in
[`docs/dev/ci-cd.md`](docs/dev/ci-cd.md).

---

## Workflow per pull request

Oggi (2026-09-23) non c'è un repository pubblico che accetta fork o pull
request (vedi lo stato in testa a questa guida): il passo 1 è una
discussione preliminare via email. Il resto descrive il flusso a cui questa
guida si applicherà quando si aprirà.

1. **Discussione preliminare via email** (o **fork**, quando il repository
   pubblico accetterà contribuzioni dirette)
2. **Branch** dal `main` con nome descrittivo:
   `feat/mappa-export-svg`, `fix/login-csrf-token`, `docs/install-windows`
3. **Conventional Commits** per i messaggi:
   - `feat(scope): aggiungi X`
   - `fix(scope): correggi Y`
   - `docs(scope): aggiorna Z`
   - `refactor(scope): ...` / `test(scope): ...` / `chore: ...`
4. **Test** locali: `composer test && npm test` devono passare
5. **Lint**: `composer stan` (PHPStan livello 6, con baseline — vedi
   [`wiki/dev-workflow.md`](wiki/dev-workflow.md), «Quality gate»),
   `npm run lint`
6. **PR** verso `main` con descrizione: cosa, perché, come testare,
   eventuali screenshot per UI change

### DCO (Developer Certificate of Origin)

**Non ancora applicato** (misurato il 2026-09-23: nessuno dei 2080 commit
della storia porta un trailer `Signed-off-by`, e nessun controllo lo
richiede). Quando si attiverà, ogni commit dovrà essere firmato con
`git commit -s` (signed-off-by trailer):

```
Signed-off-by: Nome Cognome <email@example.com>
```

Equivale a dichiarare di aver scritto il codice (o avere diritto a
contribuirlo) e di accettare la licenza EUPL-1.2. Vedi
<https://developercertificate.org> per il testo integrale.

---

## Convenzioni codice

### PHP

- PSR-12 enforced via PHP_CodeSniffer
- PHPStan livello 6, con baseline (`phpstan-baseline.neon`, che può solo
  scendere) — `composer stan`
- Namespace `App\` mappato su `app/` (PSR-4)
- Type declarations strict ovunque possibile: `declare(strict_types=1);`
- Crypto: SOLO via `App\Services\Crypto\*` (mai chiamate dirette a
  `openssl_*` nei controller)
- DB: prepared statements obbligatori, MAI string concat di query

### JavaScript

- ESModules, no CommonJS
- Niente jQuery (rimosso in Phase 26)
- ESLint con max-warnings configurato; PR non aumenta il count
- Test Vitest per moduli puri, Playwright per E2E

### SQL

- Migration in `database/migrations/NNN_descrizione.sql`
- Idempotenti dove possibile (CREATE TABLE IF NOT EXISTS, INSERT IGNORE)
- Mai DROP COLUMN senza migration di rollback documentata in `wiki/decisions/`

### Sicurezza

- MAI loggare segreti (KMS_MASTER_KEY, password, token)
- MAI commitare `.env.local`, file `*.pem`, file `*.key`
- Input user-controlled: sempre validato/sanitizzato (`htmlspecialchars`,
  `respect/validation`, `enshrined/svg-sanitize`)
- Per security review profonda: usa `/security-review` su PR rilevanti

---

## Architectural Decision Records (ADR)

Modifiche architetturali significative richiedono un ADR in
`wiki/decisions/ADR-NNN-titolo.md`. Vedi esempi esistenti per format.

---

## Localizzazione

Attualmente l'UI è solo in italiano (target: scuole italiane). Per
contribuire al supporto multilingua (i18n) apri una discussion preliminare
prima di scrivere codice — il refactor coinvolge ~200 stringhe in view.

---

## Hardware/ambiente di produzione

Pantedu è testato in produzione su:
- Hetzner Cloud CPX22 (x86, 80GB SSD, 4GB RAM)
- Debian 13 (Trixie) / Ubuntu 24.04
- nginx 1.24 + PHP-FPM 8.4 + MariaDB 11.8
- Cloudflare DNS + WAF (Free plan)

Test su altri OS/architetture (ARM, FreeBSD, Alpine) sono benvenuti — apri
issue con report compatibilità.

---

## Rilasci

- **Semver**: `MAJOR.MINOR.PATCH` (la versione corrente sta in
  `composer.json`/`package.json`, non in un tag: vedi sotto)
- **Tag Git**: non ancora in uso — misurato il 2026-09-23, nessun tag nella
  storia di questo repository. Quando si introdurranno, saranno firmati con
  GPG: `git tag -s v0.X.Y`
- **CHANGELOG.md** aggiornato in [Keep a Changelog](https://keepachangelog.com/) format
- **Release notes** su GitHub Releases: una bozza (`v0.1.0`, non pubblicata)
  esiste sul repository pubblico, ma senza un tag Git dietro — la copia
  pubblica è un'istantanea a un solo commit, non una storia con tag (vedi lo
  stato in testa a questa guida)

---

## Contatti

- Maintainer: Vittorio Pantaleo
- Email: <{{OPERATORE_EMAIL}}>
- Security: <{{OPERATORE_EMAIL}}>
- DPO: <{{OPERATORE_EMAIL}}>
