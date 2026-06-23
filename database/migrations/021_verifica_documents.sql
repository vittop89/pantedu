-- Phase G8 — Verifica documents (TEX/PDF) e templates editabili.
--
-- Aggiunge:
--   1. verifica_documents: una row per verifica salvata via 💾 SalvaTEX.
--      tex_blob = TEX content cifrato envelope ADR-006 (storage/verifiche_enc/).
--      pdf_blob = PDF caricato dall'utente via popup (NULLable: il TEX nasce
--      senza PDF, l'utente compila in Overleaf/locale e poi uploada).
--      Layout file blob:
--          [2B kv][12B IV][16B GCM tag][ciphertext]
--      Coerente con MapBlobStore (G8.4 unifica EncryptedBlobStore con namespace).
--
--   2. verifica_templates: 4 sezioni TEX modificabili dal docente
--      (intestazione, griglia voti, criteri valutazione, footer). Allegate
--      automaticamente a tutti i verifica_documents che le referenziano via
--      template_id. Ogni docente puo' avere multipli template (es. "Default
--      MAT", "Compiti in classe FIS"); is_default=1 indica quello di partenza
--      per nuove verifiche del docente.
--
-- Relazione con teacher_content:
--   verifica_documents NON e' un teacher_content: e' un artefatto generato
--   (TEX/PDF dell'esame), non un contenuto editabile in PT. La sua presenza
--   nella sidebar (fm-db-block data-type=verifica) e' un PROIETTOR di link
--   sui verifica_documents del docente filtrati per materia (G8.7).
--
-- Crypto-shredding O(1):
--   Cancellando teacher_keys.kv del docente, tutti i tex/pdf blob diventano
--   indecifrabili (chiave persa). Riusa stesso meccanismo ADR-006 di mappe.
--
-- Rollback safe:
--   DROP TABLE verifica_documents;
--   DROP TABLE verifica_templates;

-- ─────── 1. verifica_templates ───────
-- Template TEX modificabili per un singolo docente. Ogni sezione e' un
-- frammento LaTeX standalone; il TexBuilder concatena prologo + body PT
-- + sezione1 + sezione2 + ... + epilogo al momento del SalvaTEX/GENERA.
CREATE TABLE IF NOT EXISTS verifica_templates (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id    INT UNSIGNED    NOT NULL,
    name          VARCHAR(120)    NOT NULL,
    -- TEX header: \documentclass options, includegraphics{logo}, classe,
    -- anno, intestazione "Verifica di ..." con macro \verTitle.
    intestazione  TEXT            NULL,
    -- TEX tabella punti -> voto (es. tabular o tikz). Stampata in calce.
    griglia_voti  TEXT            NULL,
    -- TEX criteri di valutazione (rubrica testuale).
    criteri       TEXT            NULL,
    -- TEX footer: firma docente, note, \pagestyle.
    footer        TEXT            NULL,
    -- Default = 1 indica il template auto-applicato a nuove verifiche.
    -- UNIQUE parziale: solo 1 default per docente (gestito a livello service).
    is_default    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_vt_teacher (teacher_id, is_default),
    CONSTRAINT fk_vt_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────── 2. verifica_documents ───────
-- Una row per ogni verifica salvata via 💾 SalvaTEX. tex_blob_path e
-- pdf_blob_path sono RELATIVI a storage/verifiche_enc/.
CREATE TABLE IF NOT EXISTS verifica_documents (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id      INT UNSIGNED    NOT NULL,
    -- Materia in formato code (#sel-mater value: MAT, FIS, INF, ...).
    -- Resta string non FK perche' le materie sono in curriculum.json,
    -- non in DB normalizzato (Phase 18 — sel-mater optgroup).
    materia         VARCHAR(32)     NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    -- Sezione fm-db-block in cui mostrare il link (default VERIFICHE,
    -- futura espansione a sotto-sezioni come PROVE_SCRITTE/ORALE).
    fm_db_section   VARCHAR(64)     NOT NULL DEFAULT 'VERIFICHE',
    -- ID degli esercizi inclusi nella verifica (snapshot al SalvaTEX).
    -- JSON array di INT, es. [1234, 1235, 1290]. Permette ri-rendering /
    -- regenerate senza perdere il subset originale.
    exercise_ids    JSON            NULL,
    -- TEX blob: storage/verifiche_enc/{teacher_id}/{ulid}.bin
    tex_blob_path   VARCHAR(255)    NOT NULL,
    tex_blob_kv     SMALLINT UNSIGNED NOT NULL,
    tex_size        INT UNSIGNED    NOT NULL,
    -- PDF blob (opzionale): caricato post-compilazione via popup link click.
    pdf_blob_path   VARCHAR(255)    NULL,
    pdf_blob_kv     SMALLINT UNSIGNED NULL,
    pdf_size        INT UNSIGNED    NULL,
    pdf_filename    VARCHAR(255)    NULL,
    pdf_uploaded_at DATETIME        NULL,
    -- Template TEX applicato (intestazione/griglia/criteri/footer).
    -- NULL = default del docente (cercato a runtime su verifica_templates).
    template_id     BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_vd_teacher_materia (teacher_id, materia),
    KEY idx_vd_teacher_section (teacher_id, fm_db_section),
    KEY idx_vd_template        (template_id),
    CONSTRAINT fk_vd_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_vd_template
        FOREIGN KEY (template_id) REFERENCES verifica_templates(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
