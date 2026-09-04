-- Phase PDF-Import — sessioni di estrazione esercizi da PDF via LLM vision.
--
-- Reimplementazione PHP-nativa del tool Python "pdf-scraping-tools" (fismapant),
-- segnalato dal pentest LLM-PY-001 come "hardening / bloccante pre-integrazione".
-- NIENTE Python/daemon: PHP rasterizza il PDF in PNG (pdftoppm/Imagick) e chiama
-- direttamente le API vision dei provider (Anthropic/OpenAI/Ollama) lato server.
--
-- Flusso (mirror della FSM di 033_verifica_compile_jobs.sql, ma unità = sessione
-- con avanzamento per-pagina tramite `pages_done`):
--
--   uploaded  → caricato il PDF, riga creata, prima del rasterize
--   rasterized→ PDF → N PNG in storage (page_count valorizzato)
--   extracting→ worker/inline sta estraendo pagina-per-pagina
--   extracted → tutte le pagine estratte + contract mapping → contracts.json
--   reviewing → il docente sta revisionando/editando in pagina
--   inserting → insert in teacher_content in corso
--   inserted  → esercizi creati come bozze (draft) — terminale OK
--   failed    → errore definitivo dopo max attempts — terminale KO
--   retry     → fallito ma ritentabile (vedi next_attempt_at + backoff)
--
-- Dedup: payload_sha256 = SHA-256 del PDF caricato (MAI MD5, cfr. LLM-PY-001).
-- Un re-upload identico dallo stesso docente ritrova la sessione esistente.
--
-- Ownership: sempre scoped per teacher_id (envelope crypto). I file della
-- sessione (source.pdf, page-{n}.png, raw.json, contracts.json) vivono sotto
-- storage_prefix = institutes/{iid}/private/{tid}/pdf-import/{session_id}/.
--
-- Rollback safe:
--   DROP TABLE pdf_import_sessions;

CREATE TABLE IF NOT EXISTS pdf_import_sessions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id      INT UNSIGNED     NOT NULL,
    institute_id    INT UNSIGNED     NOT NULL,

    status          ENUM('uploaded','rasterized','extracting','extracted',
                         'reviewing','inserting','inserted','failed','retry')
                    NOT NULL DEFAULT 'uploaded',

    -- SHA-256 (hex 64) del PDF sorgente, per dedup. NON MD5 (LLM-PY-001).
    payload_sha256  CHAR(64)         NOT NULL,
    original_filename VARCHAR(255)   NOT NULL DEFAULT 'document.pdf',

    -- Conteggio pagine + avanzamento estrazione (resume/retry granulare).
    page_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    pages_done      SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Provider/model usati (audit + budget). La chiave API NON è mai qui.
    provider        VARCHAR(16)      NOT NULL DEFAULT 'anthropic',
    model           VARCHAR(64)      NOT NULL DEFAULT '',
    tokens_in       INT UNSIGNED     NOT NULL DEFAULT 0,
    tokens_out      INT UNSIGNED     NOT NULL DEFAULT 0,

    -- Prefisso storage della sessione (senza trailing slash).
    storage_prefix  VARCHAR(255)     NOT NULL DEFAULT '',

    -- Contesto di destinazione scelto dal docente per l'insert (nullable:
    -- valorizzato al momento dell'insert, non all'upload).
    indirizzo_id    INT UNSIGNED     NULL,
    classe_id       INT UNSIGNED     NULL,
    subject_id      INT UNSIGNED     NULL,
    section_id      INT UNSIGNED     NULL,

    -- Retry/backoff (mirror compile_jobs).
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME         NULL,
    last_error      VARCHAR(1024)    NULL,

    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    completed_at    DATETIME         NULL,

    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Worker pesca FIFO le sessioni processabili.
    INDEX idx_pdfimp_status_id (status, id),
    -- Status/list endpoint per docente.
    INDEX idx_pdfimp_teacher_status (teacher_id, status, created_at DESC),
    -- Dedup lookup: stesso PDF nuovo upload ritrova sessione esistente.
    INDEX idx_pdfimp_dedup (teacher_id, payload_sha256, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
