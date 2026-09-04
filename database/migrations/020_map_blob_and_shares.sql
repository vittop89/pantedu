-- Phase G2 — Mappe: storage locale cifrato + sharing granulare.
--
-- Aggiunge:
--   1. teacher_content: colonne map_* per metadata del blob cifrato locale
--      (drawio XML, PDF, PNG, ecc.) salvato in storage/maps_enc/{teacher}/
--      {ulid}.bin via MapBlobStore. Il blob cifrato e' single source of
--      truth; map_drive_id e' la copia secondaria su Drive del docente.
--      map_version per optimistic concurrency su edit drawio (anti
--      lost-update fra 2 tab dello stesso docente).
--   2. map_shares: regole di condivisione granulare per mappa. Una row
--      per ogni grant (institute/class/student/teacher × view/copy).
--      Default = no row → NESSUN cross-teacher access (diritto autore).
--      Cross-teacher copy genera nuova row in teacher_content con
--      metadata_json.mappa.parent_map_id (audit lineage).
--
-- Relazione con teacher_content.visibility:
--   - visibility='private': solo owner accede (anche con map_shares = solo
--     scope_type=teacher esplicito).
--   - visibility='published'/'classe': accessibile alla classe del owner
--     (via published_content_classe_keys, riuso ADR-006).
--   - map_shares: GRANT ESPLICITI extra per cross-section/cross-teacher.
--
-- Rollback safe:
--   ALTER TABLE teacher_content
--     DROP COLUMN map_blob_path, DROP COLUMN map_mime, ...;
--   DROP TABLE map_shares;

-- ─────── 1. teacher_content: ALTER ADD map_* columns ───────
ALTER TABLE teacher_content
    -- Path relativo a storage/maps_enc/, formato {teacher_id}/{ulid}.bin.
    -- NULL = mappa "link only" legacy (solo metadata.mappa.href, no blob).
    ADD COLUMN map_blob_path  VARCHAR(255) NULL AFTER metadata_json,
    -- MIME type del payload decifrato. Tipici:
    --   application/xml   (drawio native)
    --   application/pdf   (PDF mappa)
    --   image/png         (export raster)
    --   text/html         (mappa HTML statica)
    ADD COLUMN map_mime       VARCHAR(80)  NULL AFTER map_blob_path,
    -- Dimensione plaintext in bytes (NON il ciphertext, che ha overhead
    -- iv+tag = 28B). Display UI + quota check futura.
    ADD COLUMN map_size       INT UNSIGNED NULL AFTER map_mime,
    -- Drive file ID (mirror copia secondaria). NULL = non ancora syncata.
    ADD COLUMN map_drive_id   VARCHAR(64)  NULL AFTER map_size,
    -- Provenienza del blob: drive_legacy = scaricata da Drive in G6 mig;
    -- upload = caricata via UI (G3); drawio_native = creata in-app via
    -- embed.diagrams.net (G3). Drive_orphan e' caso degenere G6 (id Drive
    -- inaccessibile post-migrazione, blob non recuperato).
    ADD COLUMN map_origin     ENUM('drive_legacy','upload','drawio_native','drive_orphan') NULL AFTER map_drive_id,
    -- Public toggle: 1 = signed URL stabile no-scadenza accessibile a
    -- chiunque abbia il link (uso es. embed in articoli wiki). Default 0.
    ADD COLUMN map_is_public  TINYINT(1)   NOT NULL DEFAULT 0 AFTER map_origin,
    -- Optimistic concurrency token: incrementato a ogni save edit drawio.
    -- Client invia versione corrente, server fa UPDATE ... WHERE map_version=?
    -- + 1; mismatch -> 409 conflict, UI prompta reload.
    ADD COLUMN map_version    INT UNSIGNED NOT NULL DEFAULT 0 AFTER map_is_public,
    ADD INDEX idx_tc_map_drive (map_drive_id),
    ADD INDEX idx_tc_map_blob  (map_blob_path);

-- ─────── 2. map_shares ───────
-- Per condividere una mappa con docenti/classi/studenti specifici oltre
-- alla visibility default. Una row = 1 grant. Permission:
--   view: read-only via signed URL view (no edit).
--   copy: aprir embed in modalita' "Modifica copia" → save crea nuova
--         row teacher_content (parent_map_id) intestata al beneficiary.
--         L'originale del owner non viene MAI modificato.
CREATE TABLE IF NOT EXISTS map_shares (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id   BIGINT UNSIGNED NOT NULL,
    -- Scope target del grant:
    --   institute: tutti i docenti dell'istituto (scope_value = institute_id)
    --   class:     una classe-istituto (scope_value = "{institute_id}|{indirizzo}|{classe}")
    --   student:   un singolo studente (scope_value = users.id)
    --   teacher:   un singolo docente (scope_value = users.id)
    scope_type   ENUM('institute','class','student','teacher') NOT NULL,
    scope_value  VARCHAR(128) NOT NULL,
    permission   ENUM('view','copy') NOT NULL DEFAULT 'view',
    granted_by   INT UNSIGNED NOT NULL,
    granted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Audit reason opzionale (solo se super_admin grant per altri).
    reason       VARCHAR(255) NULL,
    -- Revoca = DELETE row (no soft-delete: il diritto e' assenza grant).
    UNIQUE KEY uniq_share (content_id, scope_type, scope_value),
    KEY idx_scope (scope_type, scope_value),
    KEY idx_content (content_id),
    CONSTRAINT fk_ms_content
        FOREIGN KEY (content_id) REFERENCES teacher_content(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ms_granted_by
        FOREIGN KEY (granted_by) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
