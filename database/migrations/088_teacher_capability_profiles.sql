-- Migration 088 — ADR-028 Fase 2: profili capabilities per-docente.
--
-- Modello: profili nominati (default + override per-docente). La capability
-- effettiva = profilo default ∪ profilo assegnato ∪ override docente (l'override
-- vince). Valutata da App\Services\TeacherCapabilityPolicy.
--
-- Schema capabilities (JSON):
--   { "sidebar": {"mode":"all|allow|deny","sections":[]},
--     "can_create_section": bool,
--     "doc_types": ["mappa","esercizio","verifica","document","fork","link","custom"],
--     "max_visibility": "class|classes|general" }
--
-- Retrocompat: il profilo seed "Completo" (is_default=1) è permissivo → in
-- INSTITUTE senza configurazione nulla cambia; in SINGLE la policy è comunque
-- full-permissive a prescindere dai profili. Idempotente.

CREATE TABLE IF NOT EXISTS teacher_capability_profiles (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    capabilities LONGTEXT     NOT NULL CHECK (json_valid(capabilities)),
    is_default   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assegnazione profilo per-utente (NULL → usa il profilo default).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS capability_profile_id INT UNSIGNED NULL AFTER role;

-- Override per-docente (delta sulle capabilities del profilo assegnato).
CREATE TABLE IF NOT EXISTS teacher_capability_overrides (
    user_id      INT UNSIGNED NOT NULL PRIMARY KEY,
    capabilities LONGTEXT     NOT NULL CHECK (json_valid(capabilities)),
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed profilo default permissivo (retrocompat). INSERT IGNORE: non sovrascrive.
INSERT IGNORE INTO teacher_capability_profiles (name, capabilities, is_default) VALUES
    ('Completo',
     '{"sidebar":{"mode":"all","sections":[]},"can_create_section":true,"doc_types":["mappa","esercizio","verifica","document","fork","link","custom"],"max_visibility":"general"}',
     1),
    ('Base',
     '{"sidebar":{"mode":"all","sections":[]},"can_create_section":false,"doc_types":["mappa","esercizio","verifica","document"],"max_visibility":"classes"}',
     0),
    ('Collega esterno',
     '{"sidebar":{"mode":"allow","sections":[]},"can_create_section":false,"doc_types":["mappa","document"],"max_visibility":"class"}',
     0);
