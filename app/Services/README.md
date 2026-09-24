# app/Services

Business logic dell'app (no ORM, no framework). I service sono chiamati dai Controller (`app/Controllers/`) e orchestrano repository, crypto, rendering, integrazioni.

**Directory completa con descrizioni → [`docs/SERVICES.md`](../../docs/SERVICES.md)** (mappa "quale service per la feature X", rigenerabile).

## Organizzazione

- **Sottodomini** in sottocartelle namespaced (`App\Services\<Dir>`): `Crypto/`, `Waf/`, `Security/`, `Gdpr/`, `Risdoc/` (+`Pt/`), `TexBuilder/`, `TexCompile/`, `Tex/`, `Tikz/`, `GeoGebra/`, `PdfImport/`, `Verifica/`, `Contract/`, `Maps/`, `Drive/`, `Rendering/`, `Sharing/`, `Shortcuts/`, `GitHub/`, `Audit/`.
- **Service root** (`app/Services/*.php`): cross-cutting o non ancora sotto-dominati (ACL, rate limit, mailer, registration, log, analytics…).

## Convenzioni

- Una classe = una responsabilità; suffisso `*Service`/`*Policy`/`*Repository`/`*Store`/`*Client` secondo il ruolo.
- Docblock in testa con una **riga di summary** (`Phase N — ...` o descrizione): è la fonte di `docs/SERVICES.md`. Tienila aggiornata.
- Sottodomini con README dedicato (security-critical): [`Waf/README.md`](Waf/README.md), [`Crypto/README.md`](Crypto/README.md).

## Vedi anche

- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) — quadro generale e runbook "trova X".
- [`../../docs/ROUTES.md`](../../docs/ROUTES.md) — route → controller (→ service).
