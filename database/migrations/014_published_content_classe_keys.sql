-- Phase 25.D6 — Pubblicazione studenti via classe_keys.
--
-- Modello: il docente "pubblica" un contenuto (esercizio/verifica/mappa)
-- per studenti di una specifica classe. Il body viene cifrato con una
-- chiave SCOPED PER CLASSE (non per teacher), così:
--   - Studenti della classe possono decifrare via cookie auth (loro
--     accesso è gated da indirizzo+classe).
--   - Cancellazione del docente (Art. 17 GDPR shred) NON rompe l'accesso
--     studenti ai contenuti già pubblicati (classe_key sopravvive).
--   - Cambio anno scolastico → rotation classe_key (vecchi published_content
--     ora illeggibili = "archive expiry" naturale).
--
-- Schema design:
--   classe_keys: 1 row per (indirizzo, classe, anno) con wrapped_class_key.
--     KMS_MASTER → HKDF → CKEK → wrap class_key (analogo a teacher KEK).
--   published_content: copia cifrata del body, cifrata con class_key.
--     Decoupled da source_id (teacher_content) — survive teacher delete.
--
-- Rollback safe:
--   DROP TABLE published_content;
--   DROP TABLE classe_keys;

-- ─────── classe_keys ───────
CREATE TABLE IF NOT EXISTS classe_keys (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indirizzo       VARCHAR(16) NOT NULL,
    classe          VARCHAR(16) NOT NULL,
    -- Anno scolastico (ISO format YYYY/YYYY+1, es. "2025/2026") — la
    -- rotation annuale fa cambiare il record (anno avanza, key cambia).
    anno_scolastico VARCHAR(9) NOT NULL,
    -- key_version interna per supportare rotation entro lo stesso anno
    -- (security incident: leak della class_key forza rotation).
    key_version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- wrapped class_key = AES-256-GCM(CKEK, class_key_random_32B)
    -- Layout: iv(12) || ct(32) || tag(16) = 60 bytes
    wrapped_key     VARBINARY(80) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rotated_at      TIMESTAMP NULL,
    archived_at     TIMESTAMP NULL,  -- end-of-year archive timestamp
    UNIQUE KEY uniq_classe_anno (indirizzo, classe, anno_scolastico, key_version),
    INDEX idx_active (indirizzo, classe, anno_scolastico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── published_content ───────
-- Copia cifrata di un teacher_content "pubblicato" verso una classe.
-- Cifrato con class_key (decoupled da teacher KEK).
-- Per servire la pubblicazione a uno studente:
--   1. Auth check: studente ha cookie valido per (indirizzo, classe).
--   2. SELECT published_content WHERE classe_key_id = ? AND id = ?
--   3. Decrypt body_ct con class_key (unwrap CKEK + decrypt).
--   4. Render plaintext nel response.
CREATE TABLE IF NOT EXISTS published_content (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- source: teacher_content da cui è stata pubblicata. NULL se docente
    -- cancellato (Art. 17 shred); il published_content sopravvive ma orphan.
    source_id       INT UNSIGNED NULL,
    teacher_id      INT UNSIGNED NULL,  -- denormalized per audit; nullable post-shred
    -- classe_key che ha cifrato il body. FK obbligatorio (no orphan).
    classe_key_id   INT UNSIGNED NOT NULL,
    content_type    ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica') NOT NULL,
    title           VARCHAR(255) NOT NULL,
    -- topic in chiaro (per indicizzazione + filtro studenti).
    topic           VARCHAR(128) NULL,
    -- subject_code in chiaro (filtro studenti per materia).
    subject_code    VARCHAR(16) NOT NULL,
    -- body cifrato con class_key
    body_ct         MEDIUMBLOB NOT NULL,
    body_iv         VARBINARY(12) NOT NULL,
    body_tag        VARBINARY(16) NOT NULL,
    body_kv         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- metadata in chiaro (no body_pt — quello è dentro body_ct con il body_html).
    -- Permette filtro studenti su difficulty, topic_grouping, etc.
    metadata_json   JSON NULL,
    published_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP NULL,  -- NULL = nessuna scadenza esplicita
    revoked_at      TIMESTAMP NULL,  -- soft-delete (revocata dal docente)

    CONSTRAINT fk_published_classe_key
        FOREIGN KEY (classe_key_id) REFERENCES classe_keys(id) ON DELETE RESTRICT,
    INDEX idx_classe_published (classe_key_id, published_at),
    INDEX idx_subject_topic    (subject_code, topic),
    INDEX idx_active           (revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
