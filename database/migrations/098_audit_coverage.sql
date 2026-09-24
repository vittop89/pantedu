-- 098_audit_coverage.sql
-- Il registro delle operazioni copre davvero chi opera: studente compreso.
--
-- COSA NON ANDAVA (verificato in produzione il 2026-09-02)
--
-- L'unica traccia delle azioni di studenti e docenti era `access_log.json`,
-- un file riscritto per intero a ogni richiesta e troncato a LOG_MAX_ENTRIES
-- (mille voci). Sul VPS conteneva esattamente mille righe, dal 26 agosto al 2
-- settembre: sette giorni. Il file ruotato precedente era del 22 maggio, per
-- cui tre mesi di attivita' erano gia' stati buttati via senza che nessuno
-- potesse accorgersene. Nessuna tabella registrava le azioni di uno studente.
--
-- Due tabelle citate dal pannello di audit inoltre non esistevano affatto:
--
--   · `content_versions` era definita solo in `database/schema.sql`, mai in
--     una migration, e la produzione applica solo le migration. Ogni snapshot
--     di versione finiva in un `catch (\Throwable) {}` vuoto di
--     ContractRepository. Il pannello mostrava il tab "Versioni contenuti"
--     con zero righe, senza dire che la tabella mancava.
--
--   · `teacher_recovery_audit` era stata droppata dalla migration 037 come
--     "scaffold mai utilizzata" — ma gli endpoint recovery-key sono vivi, e
--     ogni generate/revoke scriveva in un catch vuoto. Chi si porta via una
--     chiave di recupero non lasciava traccia.
--
-- COSA SI REGISTRA, E COSA NO
--
-- `audit_activity_log` non e' un access log: registrare ogni GET di ogni
-- utente sarebbe sproporzionato (art. 5(1)(c)) e, con studenti minorenni,
-- anche imprudente. Si registra cio' che e' un'*operazione*:
--
--   · ogni richiesta che non sia GET/HEAD — POST, PUT, PATCH, DELETE;
--   · ogni risposta >= 400 su richiesta autenticata, cioe' ogni tentativo
--     negato o fallito, che e' esattamente cio' che un audit deve vedere e
--     che il vecchio middleware scartava (loggava solo status < 400);
--   · gli eventi di dominio che nessuna rotta descrive da sola: consenso
--     genitoriale, esito di una registrazione, cambio di ruolo.
--
-- Le GET andate a buon fine restano fuori: per le statistiche di navigazione
-- c'e' gia' `access_log.json`, che per quello va bene.
--
-- L'IP si conserva come hash (sha256 grezzo, come consent_audit e
-- password_resets), non in chiaro: qui dentro passano anche i minori. Resta
-- confrontabile con un IP noto, non ricostruibile a partire dal log.
--
-- Rollback:
--   DROP TABLE audit_activity_log;
--   -- content_versions e teacher_recovery_audit: vedi migration 037 e
--   -- schema.sql, ma non si droppano senza esportare prima il contenuto.

CREATE TABLE IF NOT EXISTS audit_activity_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Chi. actor_role e' il ruolo *effettivo* (Auth::actorRole): un
    -- super-admin risulta 'super_admin', non 'teacher' come nel vecchio
    -- access log, dove il ruolo di sessione mascherava chi stava agendo.
    actor_user_id  INT UNSIGNED NULL,
    actor_name     VARCHAR(128) NOT NULL DEFAULT 'anonymous',
    actor_role     VARCHAR(32)  NOT NULL DEFAULT 'guest',

    -- Cosa. `action` e' la voce di dominio quando esiste
    -- (parent_consent_granted, registration_approved, ...), altrimenti
    -- 'http_request'. method+path+status dicono sempre il resto: senza il
    -- metodo, una POST distruttiva e una GET di lettura sulla stessa rotta
    -- erano indistinguibili.
    action         VARCHAR(64)  NOT NULL DEFAULT 'http_request',
    method         VARCHAR(10)  NOT NULL DEFAULT '-',
    path           VARCHAR(512) NOT NULL DEFAULT '',
    status         SMALLINT UNSIGNED NULL,
    outcome        VARCHAR(16)  NOT NULL DEFAULT 'ok',   -- ok|denied|error

    -- Su chi/che cosa, quando l'oggetto non e' il path (es. l'utente
    -- approvato, lo studente di cui si conferma il consenso).
    subject_type   VARCHAR(64)  NULL,
    subject_id     VARCHAR(128) NULL,

    details_json   TEXT         NULL,
    ip_hash        VARBINARY(32) NULL,
    user_agent     VARCHAR(512) NULL,
    request_id     VARCHAR(64)  NULL,

    KEY idx_aal_occurred (occurred_at),
    KEY idx_aal_actor    (actor_user_id, occurred_at),
    KEY idx_aal_role     (actor_role, occurred_at),
    KEY idx_aal_action   (action, occurred_at),
    KEY idx_aal_subject  (subject_type, subject_id),
    KEY idx_aal_outcome  (outcome, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro append-only delle operazioni di admin, docenti e studenti';

-- ─────────────────────────────────────────────────────────────────────────
-- content_versions: la stessa definizione che stava solo in schema.sql.
-- IF NOT EXISTS perche' le installazioni nate da `tools/db_apply_schema.php`
-- ce l'hanno gia'.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS content_versions (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    content_id      BIGINT UNSIGNED NOT NULL,
    version         INT UNSIGNED    NOT NULL,
    snapshot_json   LONGBLOB        NOT NULL,
    actor_user_id   INT UNSIGNED    NULL,
    actor_name      VARCHAR(64)     NULL,
    change_summary  VARCHAR(255)    NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cv_content (content_id, version),
    INDEX idx_cv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- teacher_recovery_audit: ricreata identica alla migration 035. La 037 la
-- tolse perche' vuota, ma vuota lo era perche' la feature non era ancora in
-- uso — non perche' fosse morta. Gli endpoint esistono, il servizio scrive.
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teacher_recovery_audit (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    action       ENUM('generate','download','use','revoke') NOT NULL,
    ip           VARCHAR(45) NULL,
    user_agent   VARCHAR(500) NULL,
    success      TINYINT(1) NOT NULL DEFAULT 1,
    note         TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recovery_audit_user (user_id, created_at),
    CONSTRAINT fk_recovery_audit_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────
-- consent_audit.event: mancava 'requested'. Il consenso genitoriale ha tre
-- momenti (richiesto / concesso / revocato) e finora il registro sapeva
-- descrivere solo l'ultimo. Enum ampliato, valori esistenti intatti.
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE consent_audit
    MODIFY COLUMN event ENUM('requested','granted','revoked','expired',
                             'reconfirmed_after_text_change') NOT NULL;
