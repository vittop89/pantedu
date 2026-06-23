-- Phase D.2 — SPID + CIE federated identity linking.
-- Spec: docs/plans/d2-spid-cie-integration.md.
--
-- Una row per ogni IdP (SPID provider o CIE) collegato ad un account
-- pantedu. Permette login multi-IdP per lo stesso user (e.g., docente
-- linka sia Aruba SPID che CIE per ridondanza accessi).
--
-- Crypto: attributes_ct/iv/tag/kv usa envelope encryption con
-- KMS_MASTER_KEY (riusa stesso meccanismo body_pt_*/body_html_*).
-- Permette crypto-shredding O(1) (drop row -> attributi unreadable).
--
-- Backward-compat: tabella creata vuota, non rompe nulla.
-- Migration idempotente via CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS spid_cie_identities (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED      NOT NULL,
    provider         ENUM('spid','cie') NOT NULL,
    -- Codice fiscale (univoco in Italia, AT identifier post-SPID).
    -- Per SPID: dall'attributo SAML "fiscalNumber".
    -- Per CIE: dal certificato della carta.
    fiscal_code      VARCHAR(16)       NOT NULL,
    -- SPID IdP entity ID (e.g. "https://identity.aruba.it/saml/sso").
    -- NULL per CIE (unico IdP gov).
    idp_entity_id    VARCHAR(255)      NULL,
    -- Attributi SAML (name, familyName, dateOfBirth, email, ecc.)
    -- cifrati envelope. Riusa il pattern app/Services/Crypto.
    attributes_ct    MEDIUMBLOB        NULL,
    attributes_iv    VARBINARY(12)     NULL,
    attributes_tag   VARBINARY(16)     NULL,
    attributes_kv    SMALLINT UNSIGNED NULL,
    -- Audit trail
    linked_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at    DATETIME          NULL,
    login_count      INT UNSIGNED      NOT NULL DEFAULT 0,
    -- Revoke (soft delete: dato che attributi cifrati con TKEK del
    -- user, post-shred row torna unreadable anche senza DELETE fisica).
    revoked_at       DATETIME          NULL,
    revoked_reason   VARCHAR(255)      NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uk_user_provider_fc (user_id, provider, fiscal_code),
    UNIQUE KEY uk_provider_fc      (provider, fiscal_code),
    KEY idx_fiscal_code           (fiscal_code),
    KEY idx_provider_entity        (provider, idp_entity_id),
    CONSTRAINT fk_spid_cie_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
