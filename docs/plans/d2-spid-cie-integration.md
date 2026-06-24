# Fase D.2 — SPID + CIE integration plan

> Status (allineamento 2026-06-18): **D.2.1 scaffolding ✅ COMPLETATO** —
> il resto è **backlog, non iniziato**, in attesa di prerequisiti normativi.
> Created: 2026-05-23 — auditor: {{OPERATORE_NOME}} + Claude Code session.
>
> **Stato reale verificato (2026-06-18)**:
> - ✅ Controller stub presenti: `app/Controllers/Auth/SpidController.php`,
>   `app/Controllers/Auth/CieController.php` (tutti i metodi ritornano 503
>   `service_not_configured` finché non c'è registrazione AgID SP).
> - ✅ Routes registrate in `routes/web.php` (`/auth/spid/*`, `/auth/cie/*`).
> - ✅ DB schema applicato: migration `066_spid_cie_identities.sql`
>   (numero reale; lo `050_…` citato sotto era solo un placeholder di piano).
> - ⬜ **D.2.2–D.2.4 NON iniziati** — la library `italia/spid-cie-php`
>   **non è installata** (assente da `composer.json`); nessun audit
>   library, nessun setup dev/testenv, nessun flow E2E.
> - ⛔ **D.2.5–D.2.6 BLOCCATI** — richiedono la registrazione AgID come
>   Service Provider (decisione partner PA ancora aperta).

## Obiettivo

Permettere ai docenti/admin pantedu di autenticarsi via:

- **SPID** (Sistema Pubblico di Identità Digitale) — IdP italiani
  governativi (hosting legacy, Poste, Lepida, Sielte, Namirial, InfoCert, Register,
  TIM, SpidItalia)
- **CIE** (Carta d'Identità Elettronica) — autenticazione tramite chip
  della carta d'identità + PIN

Ridurre la dipendenza da username/password locali (security + UX),
prerequisito per adozione PA / scuole.

## Stato regolatorio (blocco principale)

Per integrare SPID/CIE in production servono:

1. **Registrazione AgID come Service Provider (SP)**
   - Form AgID + onboarding tecnico
   - X.509 certificati produzione (no self-signed)
   - Privacy policy + Terms specifiche conformi
   - URL endpoints attivi e raggiungibili (metadata XML)
   - **Tempistica**: 4-8 settimane post-submission

2. **Status soggetto erogatore**
   - **Soggetti pubblici** (PA): codice IPA, certificate gov-issued
   - **Soggetti privati**: SPID per servizi privati (Aggregatore SPID
     necessario — tipicamente un partner PA che fa da intermediario)
   - pantedu attualmente è **progetto privato**, deve scegliere:
     - (a) Affiliarsi a scuola PA come tenant → scuola fa SP
     - (b) Diventare Aggregatore SPID privato (paywall ~€1500-3000/anno)
     - (c) Skip SPID, mantenere solo email/password (status quo)

3. **CIE specific**
   - Onboarding separato vs SPID
   - Carta IdP gestita dal Ministero dell'Interno
   - Test environment disponibile via demo.cartaidentita.interno.gov.it

**Conclusione**: D.2 production-ready è BLOCCATO finché pantedu non
diventa SP registrato. Realisticamente: post-affiliazione con una
scuola PA pilota (Q4 2026 obiettivo plan migrazione).

## Cosa facciamo ORA (scaffolding stage)

Pre-implementiamo il **TECH SCAFFOLDING** per essere pronti al
go-live appena ottenuti i certificati AgID:

### A. Library scelta

**italia/spid-cie-php** v3.19.1 (Apache-2.0, ufficiale gov)
- PHP 8.0.28+ richiesto (✓ noi 8.4)
- Wrappa SimpleSAMLphp (well-tested SAML 2.0 implementation)
- Include SPID + CIE entrambi
- Bottoni ufficiali AgID (compliance art. 5 Linee Guida SPID)
- 91⭐, ultimo release Nov 2025, attivamente maintained

Repo: <https://github.com/italia/spid-cie-php>

### B. Audit pre-install (policy pantedu)

> Stesso processo di docs/security/third-party-tools/ per ogni
> nuova library che esegue codice nostro ambiente.

Audit blockers identificati pre-install:
- **SimpleSAMLphp dependency tree** — verificare CVE log
- **Crypto requirements** — XML signing usa openssl (già presente)
- **Sessione conflict** — SimpleSAMLphp ha sua session, dobbiamo
  isolare dal nostro Session handler
- **Path traversal mitigations** — verifica metadata XML parsing

### C. Routes + UI scaffolding

Routes da aggiungere a `routes/web.php`:

```php
// Phase D.2 — SPID/CIE auth (scaffolding, non production until AgID SP).
$router->get ('/auth/spid/login',     [\App\Controllers\Auth\SpidController::class, 'login']);
$router->get ('/auth/spid/callback',  [\App\Controllers\Auth\SpidController::class, 'callback']);
$router->get ('/auth/spid/metadata',  [\App\Controllers\Auth\SpidController::class, 'metadata']);
$router->get ('/auth/spid/logout',    [\App\Controllers\Auth\SpidController::class, 'logout']);

$router->get ('/auth/cie/login',      [\App\Controllers\Auth\CieController::class, 'login']);
$router->get ('/auth/cie/callback',   [\App\Controllers\Auth\CieController::class, 'callback']);
$router->get ('/auth/cie/metadata',   [\App\Controllers\Auth\CieController::class, 'metadata']);
$router->get ('/auth/cie/logout',     [\App\Controllers\Auth\CieController::class, 'logout']);
```

UI pulsanti ufficiali AgID (compliance richiesta) — vedi
`https://docs.italia.it/AgID/documenti-in-consultazione/lg-spid-attribute-authority-docs/it/stabile/regole-tecniche/regole-grafica.html`

Stato pulsanti su login page: **disabled with explanation** (button + label "Disponibile dopo certificazione AgID SP — preview/dev").

### D. DB schema

```sql
-- database/migrations/066_spid_cie_identities.sql  (applicata; numero reale)
-- Phase D.2 — Federated identity linking. Una row per ogni IdP
-- collegato all'utente pantedu. Permette login multi-IdP per
-- lo stesso account.

CREATE TABLE spid_cie_identities (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    provider        ENUM('spid', 'cie') NOT NULL,
    -- Per SPID: codice fiscale dell'IdP (univoco a livello Italia).
    -- Per CIE: codice fiscale dal certificato carta.
    fiscal_code     VARCHAR(16) NOT NULL,
    -- Identity Provider (per SPID): hosting-legacy/poste/lepida/etc.
    idp_entity_id   VARCHAR(255) NULL,
    -- Attributi SPID utili: name, familyName, dateOfBirth, ecc.
    -- Crittografato envelope con KMS (riusa MasterKey).
    attributes_ct   MEDIUMBLOB NULL,
    attributes_iv   VARBINARY(12) NULL,
    attributes_tag  VARBINARY(16) NULL,
    attributes_kv   SMALLINT UNSIGNED NULL,
    -- Audit trail
    linked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME NULL,
    login_count     INT UNSIGNED NOT NULL DEFAULT 0,
    -- Revoke (soft delete)
    revoked_at      DATETIME NULL,
    revoked_reason  VARCHAR(255) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uk_user_provider_fc (user_id, provider, fiscal_code),
    UNIQUE KEY uk_provider_fc (provider, fiscal_code),
    KEY idx_fiscal_code (fiscal_code),
    CONSTRAINT fk_spid_cie_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### E. Config (.env.example)

```env
# Phase D.2 — SPID/CIE auth (disabled by default fino a certificazione AgID).
SPID_ENABLED=0
SPID_SP_ENTITY_ID=https://pantedu.eu/auth/spid/metadata
SPID_SP_CERT_PATH=/etc/pantedu/spid/sp.crt
SPID_SP_KEY_PATH=/etc/pantedu/spid/sp.key
SPID_SP_ORGANIZATION="Pantedu — Piattaforma educativa"
SPID_SP_TECH_CONTACT_EMAIL={{OPERATORE_EMAIL}}
# AgID minimum: name, familyName, fiscalNumber, dateOfBirth
SPID_REQUESTED_ATTRIBUTES=name,familyName,fiscalNumber,dateOfBirth,email
SPID_REQUESTED_AUTH_LEVEL=2

CIE_ENABLED=0
CIE_SP_ENTITY_ID=https://pantedu.eu/auth/cie/metadata
CIE_SP_CERT_PATH=/etc/pantedu/cie/sp.crt
CIE_SP_KEY_PATH=/etc/pantedu/cie/sp.key
CIE_REQUESTED_AUTH_LEVEL=L2
```

## Roadmap implementation

| Step | Effort | Dipendenze | Status |
|---|---|---|---|
| D.2.1 Scaffolding (routes, controller stub, DB schema, env, plan doc) | 3-4h | — | ✅ FATTO (controller 503 stub + routes + mig 066) |
| D.2.2 Audit italia/spid-cie-php library (per pantedu policy) | 1-2h | D.2.1 | TODO — non iniziato (library non installata) |
| D.2.3 Install + config dev env con spid-testenv2 IdP | 1g | D.2.2 | TODO — non iniziato |
| D.2.4 UI buttons compliant + flow E2E test contro testenv2 | 2g | D.2.3 | TODO — non iniziato |
| D.2.5 AgID Service Provider registration | 4-8 settimane | Decision partner PA | BLOCKED |
| D.2.6 Production rollout post-certificazione | 1 settimana | D.2.5 | BLOCKED |

**Total dev effort** post-prerequisiti: 3-5 settimane.
**Total wall-clock** con registrazione AgID: 3-5 mesi.

## Decisione product (richiesta)

Per sbloccare D.2.5, decidere:

1. **(a)** Affiliazione con scuola PA pilota (es. Liceo XYZ) come tenant
   → scuola fa SP, pantedu usa loro infra SPID via SAML proxy
2. **(b)** Pantedu diventa Aggregatore SPID privato (~€1500-3000/anno
   + onboarding 6-12 settimane)
3. **(c)** Skip SPID, mantenere solo email/password — accetta status
   quo, no integrazione PA-ready

Raccomandazione: **(a)** se l'obiettivo è scuole come target.
**(b)** se obiettivo è B2B saas più ampio. **(c)** se progetto
resta personale e SPID non è core value prop.

## Riferimenti normativi

- [Linee Guida SPID v2.7](https://www.agid.gov.it/sites/default/files/repository_files/circolari/spid-regole_tecniche_v1.pdf)
- [Avviso AgID n.18 (errata)](https://www.agid.gov.it/sites/default/files/repository_files/spid-avviso-n_18_v2.pdf)
- [Avviso AgID n.29 (SPID/CIE button graphics)](https://www.agid.gov.it/sites/default/files/repository_files/spid-avviso-n_29_v3.pdf)
- [Lista IdP SPID attivi](https://www.spid.gov.it/serve-aiuto/)
- [CIE technical docs](https://www.cartaidentita.interno.gov.it/identificazione-digitale/cie-id/)
