-- Phase G13 — Schema templates per (indirizzo, materia, tipologia, dsa).
--
-- Sostituisce il modello flat verifica_templates con un sistema a packs:
-- ogni pack contiene 4 sezioni (intestazione/griglia/criteri/footer) per
-- una specifica combinazione di:
--   - indirizzo  (sc, ar, ...)        [NULL = qualsiasi indirizzo]
--   - materia    (MAT, FIS, ITA, ...)  [NULL = qualsiasi materia]
--   - tipologia  ('scritto'|'orale')   [NULL = qualsiasi]
--   - dsa        (0|1)                  [DSA variant flag]
--   - owner_id   (users.id)             [NULL = system default]
--
-- Resolver pickFor(teacher, ind, mat, tip, dsa): cascade
--   1. owner_id = teacher AND match exact (ind, mat, tip, dsa)
--   2. owner_id IS NULL AND match exact (system default)
--   3. owner_id IS NULL AND match parziale con NULL fallback
--   4. fallback hard-coded nel codice (VerificaTemplateStandard)
--
-- Cosi' il super_admin in /admin/templates puo' modificare i system
-- defaults (owner_id=NULL); ogni docente puo' creare overrides
-- personali (owner_id=propio).
--
-- Seed iniziale (separato in 022_seed_packs.sql tramite seedSystemDefaults
-- service): popola i system pack dai file legacy in
-- app/Services/Verifica/legacy_assets/.

CREATE TABLE IF NOT EXISTS verifica_template_packs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- NULL = system default (super_admin only). Altrimenti override docente.
    owner_id     INT UNSIGNED    NULL,
    -- Indirizzo (es. "sc", "ar"). NULL = qualsiasi indirizzo.
    indirizzo    VARCHAR(8)      NULL,
    -- Materia (es. "MAT", "FIS"). NULL = qualsiasi materia.
    materia      VARCHAR(8)      NULL,
    -- Tipologia: scritto, orale, ... NULL = qualsiasi.
    tipologia    ENUM('scritto', 'orale', 'pratica', 'misto') NULL,
    -- DSA flag: 0 (NORMAL) o 1 (DSA). I pack DSA hanno griglia/footer
    -- specifici (font, spacing, prova compensativa).
    dsa          TINYINT(1)      NOT NULL DEFAULT 0,
    -- 4 sezioni del pack — ognuna puo' essere NULL per ereditare dal
    -- fallback piu' generale.
    intestazione TEXT            NULL,
    griglia_voti TEXT            NULL,
    criteri      TEXT            NULL,
    footer       TEXT            NULL,
    -- Etichetta human-readable (es. "Griglia Liceo Scientifico - DSA").
    name         VARCHAR(120)    NOT NULL DEFAULT '',
    -- Pack disabilitato resta in DB ma non viene risolto.
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indice composito per il resolver lookup.
    KEY idx_pack_resolve (owner_id, indirizzo, materia, tipologia, dsa, is_active),

    CONSTRAINT fk_vtpk_owner
        FOREIGN KEY (owner_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note sulla compatibilita' con verifica_templates (G8.3):
-- La tabella verifica_templates resta in piedi: i pack sono additivi.
-- Il resolver pickFor() ritorna sezioni che possono provenire indistintamente
-- da pack-DB, da hard-coded fallback in VerificaTemplateStandard, o
-- (deprecated) dal vecchio verifica_templates per backward-compat.
