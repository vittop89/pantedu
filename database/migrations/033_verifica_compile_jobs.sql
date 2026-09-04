-- G22.S5 — Job queue async per la compilazione PDF (tex-compile-vps).
--
-- Sostituisce il flusso sincrono `POST /api/verifica/{id}/compile`
-- (richiesta utente bloccata 4-20s per 8 varianti, con rischio FPM
-- timeout 30s su hosting condiviso) con un'enqueue + worker pattern:
--
--   1. Browser dopo saveBatch chiama POST /api/verifica/{id}/compile-async
--      che enqueua una row pending e ritorna {job_id} in ~10ms.
--   2. Cron hosting legacy (1 min) esegue process_compile_jobs.php che pesca i
--      job pending FIFO, invoca VPS /compile-bundle e salva il PDF.
--   3. Browser polla GET /api/verifica/jobs/{id} fino a status='done'
--      o 'failed'. Polling backoff: 1s -> 2s -> 5s -> 10s.
--
-- Idempotenza:
--   payload_hash = sha256(tex_sha256 + variant) → due enqueue per la
--   stessa verifica/variante con TEX identico vengono dedupli (la 2a
--   ritrova il job esistente in pending/done invece di crearne uno nuovo).
--
-- Retry:
--   Su fallimento (VPS down, pdflatex error) il worker incrementa
--   `attempts` e setta status='retry' con exp backoff `next_attempt_at`
--   (1m, 5m, 15m). Dopo 3 tentativi → status='failed' definitivo.
--
-- Cleanup:
--   process_compile_jobs.php pruna in fondo le row done/failed piu'
--   vecchie di 7 giorni (`completed_at < NOW() - INTERVAL 7 DAY`).
--
-- Rollback safe:
--   DROP TABLE verifica_compile_jobs;

CREATE TABLE IF NOT EXISTS verifica_compile_jobs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id        BIGINT UNSIGNED  NOT NULL,
    teacher_id    INT UNSIGNED     NOT NULL,
    -- Status FSM:
    --   pending: appena enqueued, mai pickato
    --   running: worker sta processando (lock leggero)
    --   done:    PDF compilato + attached con successo
    --   failed:  errore definitivo dopo max attempts
    --   retry:   fallito ma ritentabile (vedi next_attempt_at)
    status        ENUM('pending', 'running', 'done', 'failed', 'retry')
                  NOT NULL DEFAULT 'pending',
    -- Hash del payload per dedup (sha256 hex 64). Composto da
    -- tex_sha256 + variant + (eventuale tex_override) lato Service.
    payload_hash  CHAR(64)         NOT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Quando il prossimo attempt e' lecito (per status=retry).
    next_attempt_at DATETIME       NULL,
    -- Engine + passes copiati dal request, per logging / audit.
    engine        VARCHAR(16)      NOT NULL DEFAULT 'pdflatex',
    passes        TINYINT UNSIGNED NOT NULL DEFAULT 2,
    -- Errore ultimo (max 1024 char). Resta popolato anche su 'done'
    -- se i passes precedenti hanno avuto errori non bloccanti.
    last_error    VARCHAR(1024)    NULL,
    -- Timestamp del lifecycle.
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME         NULL,
    completed_at  DATETIME         NULL,

    -- Owner check sempre via teacher_id (envelope crypto).
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (doc_id)     REFERENCES verifica_documents(id) ON DELETE CASCADE,

    -- Worker pesca jobs FIFO via WHERE status='pending' ORDER BY id.
    INDEX idx_jobs_status_id (status, id),
    -- Status endpoint lookup per teacher (no scan globale).
    INDEX idx_jobs_teacher_status (teacher_id, status, created_at DESC),
    -- Dedup lookup: stesso payload nuovo enqueue ritrova esistente.
    INDEX idx_jobs_dedup (teacher_id, doc_id, payload_hash, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
