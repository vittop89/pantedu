# Contribuire a Pantedu

> 🚧 **Stato repository (2026-05-23)**: il repo è temporaneamente **privato**
> mentre completo l'hardening pre-public-release. Le PR esterne non sono
> ancora aperte. Questa guida è già attiva per quando il repo passerà
> a public (previsto entro fine 2026). Nel frattempo se vuoi segnalare
> bug o discutere feature, scrivi a <vittop89@users.noreply.github.com>.

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

Segnalazioni a <vittop89@users.noreply.github.com>.

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
| 💬 **Domande generiche** | Apri GitHub Discussion (quando repo sarà public) o email |

---

## Setup ambiente di sviluppo

Vedi [`README.md`](README.md) sezione "Quickstart Self-Host". Riassunto:

```bash
git clone https://github.com/vittop89/pantedu.git
cd pantedu
composer install
npm install
cp .env.example .env
# Edita .env con credenziali DB locali
mysql -u root -p < database/schema.sql
php tools/migrate.php
# Avvia php built-in server per dev:
php -S 127.0.0.1:8080 -t public/
# In altro tab, build Vite watch:
npm run dev
```

Requisiti minimi dev:
- PHP 8.3+ con estensioni: `mbstring`, `openssl`, `pdo_mysql`, `json`, `curl`, `gd`, `zip`
- MariaDB 11.x o MySQL 8.x
- Node 20+, npm 10+
- Composer 2.x

---

## Workflow per pull request

1. **Fork** del repo (quando sarà public) o discussione preliminare via email
2. **Branch** dal `main` con nome descrittivo:
   `feat/mappa-export-svg`, `fix/login-csrf-token`, `docs/install-windows`
3. **Conventional Commits** per i messaggi:
   - `feat(scope): aggiungi X`
   - `fix(scope): correggi Y`
   - `docs(scope): aggiorna Z`
   - `refactor(scope): ...` / `test(scope): ...` / `chore: ...`
4. **Test** locali: `composer test && npm test` devono passare
5. **Lint**: `composer stan` (PHPStan level max), `npm run lint`
6. **PR** verso `main` con descrizione: cosa, perché, come testare,
   eventuali screenshot per UI change

### DCO (Developer Certificate of Origin)

Ogni commit deve essere firmato con `git commit -s` (signed-off-by trailer):

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
- PHPStan level max — `composer stan`
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

- **Semver**: `MAJOR.MINOR.PATCH`
- **Tag** firmati con GPG: `git tag -s v0.X.Y`
- **CHANGELOG.md** aggiornato in [Keep a Changelog](https://keepachangelog.com/) format
- **Release notes** su GitHub Releases (quando repo public)

---

## Contatti

- Maintainer: Vittorio Pantaleo
- Email: <vittop89@users.noreply.github.com>
- Security: <{{OPERATORE_EMAIL}}>
- DPO: <{{OPERATORE_EMAIL}}>
